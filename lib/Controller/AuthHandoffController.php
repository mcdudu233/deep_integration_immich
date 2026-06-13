<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Controller;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Http\SharedCookieRedirectResponse;
use OCA\IntegrationImmich\Service\BrowsingAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;

class AuthHandoffController extends Controller {
    public function __construct(
        IRequest $request,
        private BrowsingAuthService $browsingAuthService,
        private ?string $userId,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function openImmich(): RedirectResponse|JSONResponse {
        if ($this->userId === null || trim($this->userId) === '') {
            return $this->safeError('not_authenticated', 'Sign in to Nextcloud before opening Immich.', Http::STATUS_UNAUTHORIZED);
        }

        $handoff = $this->browsingAuthService->resolveLegacyPasswordLoginHandoff($this->userId);
        if (($handoff['status'] ?? '') !== BrowsingAuthService::HANDOFF_READY) {
            return $this->safeError((string)($handoff['status'] ?? 'unavailable'), $this->messageForStatus((string)($handoff['status'] ?? 'unavailable')), Http::STATUS_PRECONDITION_FAILED);
        }

        $redirectUrl = rtrim((string)($handoff['url'] ?? ''), '/');
        if (!$this->isSafeRedirectUrl($redirectUrl)) {
            return $this->safeError(BrowsingAuthService::HANDOFF_LOGIN_FAILED, 'Immich auto-login is temporarily unavailable. Try again later or ask an administrator to reconcile your Immich account.', Http::STATUS_BAD_GATEWAY);
        }

        $session = $this->browsingAuthService->createImmichLoginSession($handoff);
        if (!($session['success'] ?? false) || !is_string($session['setCookie'] ?? null)) {
            return $this->safeError(BrowsingAuthService::HANDOFF_LOGIN_FAILED, 'Immich auto-login is temporarily unavailable. Try again later or ask an administrator to reconcile your Immich account.', Http::STATUS_BAD_GATEWAY);
        }

        $setCookies = $this->sharedParentDomainCookies((string)$session['setCookie'], $redirectUrl);
        if ($setCookies === null) {
            return $this->safeError(BrowsingAuthService::HANDOFF_LOGIN_FAILED, 'Immich auto-login requires Nextcloud and Immich to share the same parent domain.', Http::STATUS_BAD_GATEWAY);
        }

        return new SharedCookieRedirectResponse($redirectUrl, $setCookies);
    }

    private function safeError(string $code, string $message, int $status): JSONResponse {
        return new JSONResponse([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    private function messageForStatus(string $status): string {
        return match ($status) {
            BrowsingAuthService::HANDOFF_PERSONAL_MODE => 'Immich auto-login is disabled because this account uses personal Immich credentials.',
            BrowsingAuthService::HANDOFF_ADMIN_CONFIG_MISSING => 'Immich admin-managed browsing is not fully configured.',
            BrowsingAuthService::HANDOFF_UNMAPPED => 'No active Immich mapping exists for this Nextcloud user yet.',
            BrowsingAuthService::HANDOFF_CREDENTIALS_MISSING => 'Immich auto-login credentials are not available for this mapped user.',
            default => 'Immich auto-login is unavailable for this account.',
        };
    }

    private function isSafeRedirectUrl(string $url): bool {
        $parsed = parse_url($url);
        return is_array($parsed)
            && in_array(strtolower((string)($parsed['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string)($parsed['host'] ?? '')) !== ''
            && !isset($parsed['user'])
            && !isset($parsed['pass'])
            && !isset($parsed['fragment']);
    }

    /**
     * @return string[]|null
     */
    private function sharedParentDomainCookies(string $setCookie, string $immichUrl): ?array {
        $immichHost = $this->hostFromUrl($immichUrl);
        $nextcloudHost = $this->normalizeHost($this->request->getServerHost());
        if ($immichHost === null || $nextcloudHost === null) {
            return null;
        }

        $parentDomain = $this->sharedParentDomain($nextcloudHost, $immichHost);
        if ($parentDomain === null) {
            return null;
        }

        $parts = array_map('trim', explode(';', $setCookie));
        $nameValue = array_shift($parts);
        if (!is_string($nameValue) || $nameValue === '' || !str_contains($nameValue, '=')) {
            return null;
        }

        $attributes = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $part, 2), 2, null);
            if (strcasecmp($name, 'Domain') === 0) {
                $cookieDomain = $this->normalizeHost((string)$value);
                if ($cookieDomain === null || (!$this->domainMatches($cookieDomain, $immichHost) && $cookieDomain !== $parentDomain)) {
                    return null;
                }
                continue;
            }

            $attributes[] = $part;
        }

        $attributes[] = 'Domain=' . $parentDomain;

        $cookieAttributes = implode('; ', $attributes);

        return [
            $nameValue . '; ' . $cookieAttributes,
            'immich_auth_type=password; ' . $cookieAttributes,
            'immich_is_authenticated=true; ' . $this->nonHttpOnlyAttributes($attributes),
        ];
    }

    /**
     * @param string[] $attributes
     */
    private function nonHttpOnlyAttributes(array $attributes): string {
        return implode('; ', array_values(array_filter($attributes, static fn(string $attribute): bool => strcasecmp($attribute, 'HttpOnly') !== 0)));
    }

    private function hostFromUrl(string $url): ?string {
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return null;
        }

        return $this->normalizeHost((string)($parsed['host'] ?? ''));
    }

    private function normalizeHost(string $host): ?string {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = trim($host, '.');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $host;
    }

    private function sharedParentDomain(string $firstHost, string $secondHost): ?string {
        $firstLabels = array_reverse(explode('.', $firstHost));
        $secondLabels = array_reverse(explode('.', $secondHost));
        $shared = [];

        foreach ($firstLabels as $index => $label) {
            if (($secondLabels[$index] ?? null) !== $label) {
                break;
            }

            $shared[] = $label;
        }

        if (count($shared) < 2 || count($shared) === count($firstLabels) || count($shared) === count($secondLabels)) {
            return null;
        }

        return implode('.', array_reverse($shared));
    }

    private function domainMatches(string $cookieDomain, string $host): bool {
        return $host === $cookieDomain || str_ends_with($host, '.' . $cookieDomain);
    }
}
