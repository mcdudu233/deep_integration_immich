<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Controller;

use OCA\IntegrationImmich\AppInfo\Application;
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

        $handoff = $this->browsingAuthService->resolveAutoLoginHandoff($this->userId);
        if (($handoff['status'] ?? '') !== BrowsingAuthService::HANDOFF_READY) {
            return $this->safeError((string)($handoff['status'] ?? 'unavailable'), $this->messageForStatus((string)($handoff['status'] ?? 'unavailable')), Http::STATUS_PRECONDITION_FAILED);
        }

        $session = $this->browsingAuthService->createImmichLoginSession($handoff);
        if ($session['success'] !== true) {
            return $this->safeError(BrowsingAuthService::HANDOFF_LOGIN_FAILED, 'Immich auto-login is temporarily unavailable. Try again later or ask an administrator to reconcile your Immich account.', Http::STATUS_BAD_GATEWAY);
        }

        $response = new RedirectResponse($session['redirectUrl']);
        if (is_string($session['setCookie']) && trim($session['setCookie']) !== '') {
            $response->addHeader('Set-Cookie', $session['setCookie']);
        }

        return $response;
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
}
