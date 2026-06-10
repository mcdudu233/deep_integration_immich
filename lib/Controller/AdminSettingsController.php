<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Controller;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AdminSettingsController extends Controller {
    private const CONFIG_KEYS = [
        AdminConfigService::KEY_IMMICH_BASE_URL,
        AdminConfigService::KEY_ADMIN_API_KEY,
        AdminConfigService::KEY_IMMICH_BROWSING_MODE,
        AdminConfigService::KEY_PROVISIONING_ENABLED,
        AdminConfigService::KEY_USER_SCOPE_MODE,
        AdminConfigService::KEY_USER_SCOPE_GROUPS,
        AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE,
        AdminConfigService::KEY_EMAIL_TEMPLATE,
        AdminConfigService::KEY_INITIAL_PASSWORD_POLICY,
        AdminConfigService::KEY_HOST_PATH_TEMPLATE,
        AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE,
        AdminConfigService::KEY_MOUNT_NAME_TEMPLATE,
        AdminConfigService::KEY_MKDIR_POLICY_ENABLED,
        AdminConfigService::KEY_QUOTA_SYNC_MODE,
        AdminConfigService::KEY_QUOTA_RESERVE_BYTES,
        AdminConfigService::KEY_DELETE_DISABLE_POLICY,
        AdminConfigService::KEY_EXTERNAL_STORAGE_AUTO_CREATE,
        AdminConfigService::KEY_EXPORT_COPY_ENABLED,
        AdminConfigService::KEY_IMPORT_TO_IMMICH_ENABLED,
        AdminConfigService::KEY_IMMICH_DELETE_ENABLED,
        AdminConfigService::DELETE_OPT_IN_CONFIRMATION_FLAG,
    ];

    private const SAFE_CONFIG_KEYS = [
        AdminConfigService::KEY_INITIAL_PASSWORD_POLICY,
        AdminConfigService::KEY_DELETE_DISABLE_POLICY,
        AdminConfigService::KEY_QUOTA_SYNC_MODE,
        AdminConfigService::KEY_USER_SCOPE_MODE,
        AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE,
        AdminConfigService::KEY_EMAIL_TEMPLATE,
        AdminConfigService::KEY_MOUNT_NAME_TEMPLATE,
        AdminConfigService::KEY_HOST_PATH_TEMPLATE,
        AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE,
        AdminConfigService::KEY_IMMICH_BROWSING_MODE,
        AdminConfigService::KEY_PROVISIONING_ENABLED,
        AdminConfigService::KEY_MKDIR_POLICY_ENABLED,
        AdminConfigService::KEY_EXTERNAL_STORAGE_AUTO_CREATE,
        AdminConfigService::KEY_QUOTA_RESERVE_BYTES,
        'admin_api_key_configured',
        'api_key_set',
    ];

    private const SECRET_CONFIG_KEYS = [
        AdminConfigService::KEY_ADMIN_API_KEY,
        'immich_admin_api_key',
        'api_key',
        'apikey',
        'x_api_key',
        'token',
        'secret',
        'authorization',
        'password',
    ];

    public function __construct(
        IRequest $request,
        private AdminConfigService $adminConfigService,
        private ImmichUserAdminService $immichUserAdminService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[AdminRequired]
    #[NoCSRFRequired]
    public function getConfig(): JSONResponse {
        return $this->success([
            'config' => $this->safeAdminConfig(),
        ]);
    }

    #[AdminRequired]
    public function setConfig(): JSONResponse {
        $values = $this->requestConfigValues();
        $mergedValues = array_merge($this->safeAdminConfig(), $values);
        $errors = $this->adminConfigService->validateAdminConfigDetails($mergedValues);
        if ($errors !== []) {
            return $this->errorResponse(
                'admin_config_invalid',
                'Invalid admin configuration.',
                Http::STATUS_BAD_REQUEST,
                $this->validationErrorDetails($errors)
            );
        }

        try {
            $this->adminConfigService->setAdminConfig($values);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(
                'admin_config_invalid',
                $this->redactString($e->getMessage()),
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            $message = $this->redactString($e->getMessage());
            $this->logger->error('Failed to save Immich admin configuration: ' . $message, [
                'app' => Application::APP_ID,
            ]);

            return $this->errorResponse(
                'admin_config_save_failed',
                'Failed to save admin configuration.',
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return $this->success([
            'config' => $this->safeAdminConfig(),
        ]);
    }

    #[AdminRequired]
    public function validateConnection(): JSONResponse {
        $validation = $this->redact($this->immichUserAdminService->validateAdminConnection(
            $this->optionalStringParam([AdminConfigService::KEY_IMMICH_BASE_URL, 'server_url']),
            $this->optionalStringParam([AdminConfigService::KEY_ADMIN_API_KEY, 'api_key']),
        ));
        if (($validation['success'] ?? false) !== true) {
            $detail = $this->redactString((string)($validation['error'] ?? 'Unknown Immich admin connection error.'));
            $this->logger->warning('Immich admin connection validation failed: ' . $detail, [
                'app' => Application::APP_ID,
            ]);

            return $this->errorResponse(
                'connection_validation_failed',
                'Connection validation failed.',
                Http::STATUS_BAD_REQUEST,
                [
                    'detail' => $detail,
                    'local_access_blocked' => str_contains($detail, 'violates local access rules'),
                    'validation' => $validation,
                ]
            );
        }

        return $this->success([
            'validation' => $validation,
        ]);
    }

    private function requestConfigValues(): array {
        $values = [];
        foreach (self::CONFIG_KEYS as $key) {
            $value = $this->request->getParam($key, null);
            if ($value !== null) {
                if ($key === AdminConfigService::KEY_ADMIN_API_KEY && trim((string)$value) === '') {
                    continue;
                }

                $values[$key] = $value;
            }
        }

        return $values;
    }

    private function optionalStringParam(array $keys): ?string {
        foreach ($keys as $key) {
            $value = $this->request->getParam($key, null);
            if ($value === null) {
                continue;
            }

            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function safeAdminConfig(): array {
        $config = $this->adminConfigService->getAdminConfig();
        unset($config[AdminConfigService::KEY_ADMIN_API_KEY]);

        return $this->redact($config);
    }

    private function success(array $payload = [], int $status = Http::STATUS_OK): JSONResponse {
        return new JSONResponse(array_merge(['success' => true], $payload), $status);
    }

    private function errorResponse(string $code, string $message, int $status, array $details = []): JSONResponse {
        return new JSONResponse([
            'success' => false,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $this->redact($details),
            ], static fn(mixed $value): bool => $value !== [] && $value !== null),
        ], $status);
    }

    private function validationErrorDetails(array $fieldDetails): array {
        $fields = [];
        $normalisedDetails = [];
        foreach ($fieldDetails as $field => $detail) {
            if (!is_array($detail)) {
                $fieldName = (string)$field;
                $message = (string)$detail;
                $fields[$fieldName] = $message;
                $normalisedDetails[] = [
                    'field' => $fieldName,
                    'code' => 'invalid_value',
                    'message' => $message,
                    'params' => [],
                ];
                continue;
            }

            $fieldName = (string)($detail['field'] ?? $field);
            $message = (string)($detail['message'] ?? 'Invalid value.');
            $fields[$fieldName] = $message;
            $normalisedDetails[] = [
                'field' => $fieldName,
                'code' => (string)($detail['code'] ?? 'invalid_value'),
                'message' => $message,
                'params' => is_array($detail['params'] ?? null) ? $detail['params'] : [],
            ];
        }

        return [
            'fields' => $fields,
            'fieldDetails' => $normalisedDetails,
        ];
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
        $normalisedKey = $this->normaliseConfigKey($key);
        if (in_array($normalisedKey, self::SAFE_CONFIG_KEYS, true)) {
            return false;
        }

        if (in_array($normalisedKey, self::SECRET_CONFIG_KEYS, true)) {
            return true;
        }

        if (str_ends_with($normalisedKey, '_password')) {
            return true;
        }

        return preg_match('/(^|_)(api_key|apikey|token|secret|authorization)($|_)/', $normalisedKey) === 1;
    }

    private function normaliseConfigKey(string $key): string {
        $key = str_replace('-', '_', $key);
        $key = preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $key) ?? $key;
        return strtolower($key);
    }
}
