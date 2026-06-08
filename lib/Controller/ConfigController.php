<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\Controller;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\ActionPolicyService;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ConfigController extends Controller {
    public function __construct(
        IRequest $request,
        private ImmichService $immichService,
        private ActionPolicyService $actionPolicyService,
        private ?string $userId,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getConfig(): JSONResponse {
        return new JSONResponse([
            'server_url' => $this->immichService->getServerUrl(),
            'api_key_set' => $this->immichService->getApiKey() !== '',
            'actionCapabilities' => $this->actionPolicyService->getCapabilityFlags($this->userId),
        ]);
    }

    #[NoAdminRequired]
    public function setConfig(): JSONResponse {
        $serverUrl = $this->request->getParam('server_url');
        $apiKey = $this->request->getParam('api_key');
        $validate = $this->request->getParam('validate', false);

        if ($serverUrl !== null) {
            try {
                $this->immichService->setServerUrl($serverUrl);
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
        }
        if ($apiKey !== null && $apiKey !== '') {
            $this->immichService->setApiKey($apiKey);
        }

        if ($validate === true || $validate === 'true' || $validate === '1') {
            $result = $this->redact($this->immichService->validateConnection());
            if (!$result['success']) {
                $errorMsg = $this->redactString((string)($result['error'] ?? 'unknown'));
                $this->logger->warning('Immich connection validation failed: ' . $errorMsg, [
                    'app' => Application::APP_ID,
                ]);

                $isLocalAccessBlocked = str_contains($errorMsg, 'violates local access rules');

                return $this->errorResponse(
                    'connection_validation_failed',
                    'Connection validation failed.',
                    Http::STATUS_BAD_REQUEST,
                    [
                        'detail' => $errorMsg,
                        'local_access_blocked' => $isLocalAccessBlocked,
                    ],
                    [
                        'detail' => $errorMsg,
                        'local_access_blocked' => $isLocalAccessBlocked,
                    ]
                );
            }

            $missingPermissions = $result['missing_permissions'] ?? [];
            if (!empty($missingPermissions)) {
                $this->logger->warning('Immich API key is missing required permissions: ' . implode(', ', $missingPermissions), [
                    'app' => Application::APP_ID,
                ]);
            }

            return new JSONResponse([
                'success' => true,
                'validation' => $result,
                'missing_permissions' => $missingPermissions,
            ]);
        }

        return new JSONResponse(['success' => true]);
    }

    private function errorResponse(string $code, string $message, int $status, array $details = [], array $legacy = []): JSONResponse {
        return new JSONResponse(array_merge([
            'success' => false,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $this->redact($details),
            ], static fn(mixed $value): bool => $value !== [] && $value !== null),
        ], $this->redact($legacy)), $status);
    }

    private function redact(mixed $value): mixed {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isSecretKey($key)) {
                    $redacted[$key] = '[redacted]';
                    continue;
                }

                $redacted[$key] = $this->redact($item);
            }

            return $redacted;
        }

        if (is_string($value)) {
            return $this->redactString($value);
        }

        return $value;
    }

    private function redactString(string $value): string {
        $value = preg_replace('/([?&](?:api[_-]?key|token|password|secret|authorization)=)[^&\s]+/i', '$1[redacted]', $value) ?? $value;
        $value = preg_replace('/("(?:password|admin_api_key|apiKey|api_key|x-api-key|token|secret|authorization)"\s*:\s*")[^"]+(")/i', '$1[redacted]$2', $value) ?? $value;
        $value = preg_replace('/\b(authorization)(\s*[=:]\s*)bearer\s+[^\s,;}]+/i', '$1$2[redacted]', $value) ?? $value;
        $value = preg_replace('/\bbearer\s+[^\s,;}]+/i', 'Bearer [redacted]', $value) ?? $value;
        return preg_replace('/(api[_-]?key|token|password|secret|authorization)(\s*[=:]\s*)[^\s,;}]+/i', '$1$2[redacted]', $value) ?? $value;
    }

    private function isSecretKey(string $key): bool {
        if (preg_match('/(?:configured|_set)$/i', $key) === 1) {
            return false;
        }

        return preg_match('/(^|[_-])(api[_-]?key|token|password|secret|authorization)($|[_-])/i', $key) === 1;
    }
}
