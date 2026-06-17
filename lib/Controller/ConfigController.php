<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\Controller;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\ActionPolicyService;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\ImmichService;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class ConfigController extends Controller {
    public function __construct(
        IRequest $request,
        private ImmichService $immichService,
        private ActionPolicyService $actionPolicyService,
        private AdminConfigService $adminConfigService,
        private SyncStateService $syncStateService,
        private ImmichUserAdminService $immichUserAdminService,
        private ICrypto $crypto,
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
            'admin_managed_connection' => $this->adminManagedConnectionState(),
        ]);
    }

    #[NoAdminRequired]
    public function setConfig(): JSONResponse {
        if ($this->isAdminManagedConnectionUpdate()) {
            return $this->setAdminManagedConnection();
        }

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

    private function setAdminManagedConnection(): JSONResponse {
        if ($this->userId === null || trim($this->userId) === '') {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $username = trim((string)$this->request->getParam('immich_username', ''));
        $password = (string)$this->request->getParam('immich_password', '');
        $apiKey = trim((string)$this->request->getParam('immich_api_key', ''));

        $state = $this->syncStateService->findByUid($this->userId);
        $storedApiKey = $state === null ? '' : $this->decryptNullable((string)($state->getImmichApiKey() ?? ''));
        $effectiveApiKey = $apiKey !== '' ? $apiKey : $storedApiKey;
        $hasExistingMapping = $state !== null && trim((string)$state->getImmichUserId()) !== '';

        if ($username === '' || $password === '' || $effectiveApiKey === '') {
            return $this->errorResponse('connection_fields_required', 'Immich username, password, and API key are required.', Http::STATUS_BAD_REQUEST);
        }

        $loginValidation = $this->immichUserAdminService->validateUserLogin($username, $password);
        if (($loginValidation['success'] ?? false) !== true) {
            return $this->errorResponse('immich_login_validation_failed', 'Immich username/password validation failed.', Http::STATUS_BAD_REQUEST, [
                'detail' => $this->redactString((string)($loginValidation['error'] ?? 'unknown')),
            ]);
        }

        $apiKeyValidation = $this->immichUserAdminService->validateUserApiKey($effectiveApiKey);
        if (($apiKeyValidation['success'] ?? false) !== true) {
            return $this->errorResponse('immich_api_key_validation_failed', 'Immich API key validation failed.', Http::STATUS_BAD_REQUEST, [
                'detail' => $this->redactString((string)($apiKeyValidation['error'] ?? 'unknown')),
            ]);
        }

        if (!$hasExistingMapping) {
            $bindResult = $this->bindExistingImmichUser($effectiveApiKey, $username);
            if ($bindResult instanceof JSONResponse) {
                return $bindResult;
            }
            $state = $bindResult;
        }

        $fields = [
            'immichUsername' => $username,
            'immichPassword' => $password,
        ];
        if ($apiKey !== '') {
            $fields['immichApiKey'] = $this->crypto->encrypt($apiKey);
        }

        $this->syncStateService->updateMapping($this->userId, $fields);

        return new JSONResponse([
            'success' => true,
            'admin_managed_connection' => [
                'enabled' => true,
                'server_url' => $this->adminConfigService->getImmichBaseUrl(),
                'username' => $username,
                'password' => $password,
                'api_key_set' => true,
            ],
        ]);
    }

    private function bindExistingImmichUser(string $apiKey, string $loginEmail): JSONResponse|SyncState {
        $lookup = $this->immichUserAdminService->findUserByApiKey($apiKey);
        if (($lookup['success'] ?? false) !== true) {
            return $this->errorResponse(
                'immich_user_lookup_failed',
                'Cannot bind Immich account: ' . (string)($lookup['error'] ?? 'unknown'),
                Http::STATUS_BAD_REQUEST,
                ['detail' => $this->redactString((string)($lookup['error'] ?? 'unknown'))],
            );
        }

        $immichUser = $lookup['user'];
        $immichUserId = trim((string)$immichUser['id']);
        if ($immichUserId === '') {
            return $this->errorResponse(
                'immich_user_lookup_failed',
                'Immich API key validated but no user id was resolved.',
                Http::STATUS_BAD_REQUEST,
            );
        }

        // Refuse to hijack another Nextcloud user's mapping.
        $existing = $this->syncStateService->findByImmichUserId($immichUserId);
        if ($existing !== null && $existing->getNcUid() !== $this->userId) {
            return $this->errorResponse(
                'immich_user_already_mapped',
                'This Immich account is already bound to a different Nextcloud user.',
                Http::STATUS_CONFLICT,
            );
        }

        $email = trim((string)$immichUser['email']) !== '' ? (string)$immichUser['email'] : $loginEmail;
        $storageLabel = trim((string)$immichUser['storageLabel']);

        $state = $this->syncStateService->getOrCreateForUid($this->userId);
        $mapping = [
            'immichUserId' => $immichUserId,
            'immichEmail' => $email,
            'scopeStatus' => SyncStateService::STATUS_ACTIVE,
            'lastSyncStatus' => SyncStateService::STATUS_ACTIVE,
            'lastError' => null,
        ];
        if ($storageLabel !== '') {
            $mapping['storageLabel'] = $storageLabel;
        }

        try {
            $this->syncStateService->updateMapping($this->userId, $mapping);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to persist user-initiated Immich mapping for "' . $this->userId . '": ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'ncUid' => $this->userId,
            ]);
            return $this->errorResponse(
                'immich_mapping_persist_failed',
                'Failed to persist the Immich mapping.',
                Http::STATUS_INTERNAL_SERVER_ERROR,
                ['detail' => $this->redactString($e->getMessage())],
            );
        }

        return $this->syncStateService->findByUid($this->userId) ?? $state;
    }

    private function isAdminManagedConnectionUpdate(): bool {
        foreach (['immich_username', 'immich_password', 'immich_api_key'] as $key) {
            if ($this->request->getParam($key, null) !== null) {
                return true;
            }
        }

        return false;
    }

    private function adminManagedConnectionState(): array {
        $config = $this->adminConfigService->getAdminConfig();
        $adminManaged = ($config[AdminConfigService::KEY_IMMICH_BROWSING_MODE] ?? AdminConfigService::BROWSING_MODE_ADMIN_MANAGED) === AdminConfigService::BROWSING_MODE_ADMIN_MANAGED;
        if (!$adminManaged || $this->userId === null) {
            return ['enabled' => false];
        }

        $state = $this->syncStateService->findByUid($this->userId);
        if ($state === null) {
            return [
                'enabled' => true,
                'mapped' => false,
                'server_url' => $this->adminConfigService->getImmichBaseUrl(),
                'username' => '',
                'password' => '',
                'api_key' => '',
                'api_key_set' => false,
            ];
        }

        $apiKey = $this->decryptNullable((string)($state->getImmichApiKey() ?? ''));

        return [
            'enabled' => true,
            'mapped' => trim((string)$state->getImmichUserId()) !== '',
            'server_url' => $this->adminConfigService->getImmichBaseUrl(),
            'username' => (string)($state->getImmichUsername() ?? ''),
            'password' => (string)($state->getImmichPassword() ?? ''),
            'api_key' => $apiKey,
            'api_key_set' => $apiKey !== '',
        ];
    }

    private function decryptNullable(string $value): string {
        if ($value === '') {
            return '';
        }

        try {
            return $this->crypto->decrypt($value);
        } catch (\Throwable) {
            return $value;
        }
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
