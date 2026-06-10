<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use OCA\IntegrationImmich\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class AdminConfigService {
    public const KEY_IMMICH_BASE_URL = 'immich_base_url';
    public const KEY_ADMIN_API_KEY = 'admin_api_key';
    public const KEY_IMMICH_BROWSING_MODE = 'immich_browsing_mode';
    public const KEY_PROVISIONING_ENABLED = 'provisioning_enabled';
    public const KEY_USER_SCOPE_MODE = 'user_scope_mode';
    public const KEY_USER_SCOPE_GROUPS = 'user_scope_groups';
    public const KEY_STORAGE_LABEL_TEMPLATE = 'storage_label_template';
    public const KEY_EMAIL_TEMPLATE = 'email_template';
    public const KEY_INITIAL_PASSWORD_POLICY = 'initial_password_policy';
    public const KEY_HOST_PATH_TEMPLATE = 'host_path_template';
    public const KEY_NC_VISIBLE_PATH_TEMPLATE = 'nc_visible_path_template';
    public const KEY_MOUNT_NAME_TEMPLATE = 'mount_name_template';
    public const KEY_MKDIR_POLICY_ENABLED = 'mkdir_policy_enabled';
    public const KEY_QUOTA_SYNC_MODE = 'quota_sync_mode';
    public const KEY_QUOTA_RESERVE_BYTES = 'quota_reserve_bytes';
    public const KEY_DELETE_DISABLE_POLICY = 'delete_disable_policy';
    public const KEY_EXTERNAL_STORAGE_AUTO_CREATE = 'external_storage_auto_create';
    public const KEY_EXPORT_COPY_ENABLED = ActionPolicyService::KEY_EXPORT_COPY_ENABLED;
    public const KEY_IMPORT_TO_IMMICH_ENABLED = ActionPolicyService::KEY_IMPORT_TO_IMMICH_ENABLED;
    public const KEY_IMMICH_DELETE_ENABLED = ActionPolicyService::KEY_IMMICH_DELETE_ENABLED;

    public const DELETE_OPT_IN_CONFIRMATION_FLAG = 'delete_opt_in_confirmed';

    public const VALIDATION_INVALID_URL = 'invalid_url';
    public const VALIDATION_INVALID_ENUM = 'invalid_enum';
    public const VALIDATION_INVALID_GROUP_LIST = 'invalid_group_list';
    public const VALIDATION_INVALID_BOOLEAN = 'invalid_boolean';
    public const VALIDATION_MISSING_PATH_TEMPLATE = 'missing_path_template';
    public const VALIDATION_INVALID_PATH_TEMPLATE = 'invalid_path_template';
    public const VALIDATION_INVALID_QUOTA_RESERVE = 'invalid_quota_reserve';
    public const VALIDATION_DELETE_OPT_IN_CONFIRMATION_REQUIRED = 'delete_opt_in_confirmation_required';
    public const VALIDATION_INVALID_TEMPLATE = 'invalid_template';
    public const VALIDATION_UNSUPPORTED_TEMPLATE_PLACEHOLDER = 'unsupported_template_placeholder';

    public const BROWSING_MODE_PERSONAL = 'personal';
    public const BROWSING_MODE_ADMIN_MANAGED = 'admin_managed';

    private const DEFAULT_QUOTA_RESERVE_BYTES = 268435456;

    private const DEFAULTS = [
        self::KEY_IMMICH_BASE_URL => '',
        self::KEY_IMMICH_BROWSING_MODE => self::BROWSING_MODE_ADMIN_MANAGED,
        self::KEY_PROVISIONING_ENABLED => false,
        self::KEY_USER_SCOPE_MODE => 'all',
        self::KEY_USER_SCOPE_GROUPS => [],
        self::KEY_STORAGE_LABEL_TEMPLATE => '{uid}',
        self::KEY_EMAIL_TEMPLATE => '{uid}@immich.local',
        self::KEY_INITIAL_PASSWORD_POLICY => 'random',
        self::KEY_HOST_PATH_TEMPLATE => '',
        self::KEY_NC_VISIBLE_PATH_TEMPLATE => '',
        self::KEY_MOUNT_NAME_TEMPLATE => 'Immich Photos',
        self::KEY_MKDIR_POLICY_ENABLED => false,
        self::KEY_QUOTA_SYNC_MODE => 'disabled',
        self::KEY_QUOTA_RESERVE_BYTES => self::DEFAULT_QUOTA_RESERVE_BYTES,
        self::KEY_DELETE_DISABLE_POLICY => 'disable_suspend',
        self::KEY_EXTERNAL_STORAGE_AUTO_CREATE => false,
        self::KEY_EXPORT_COPY_ENABLED => false,
        self::KEY_IMPORT_TO_IMMICH_ENABLED => false,
        self::KEY_IMMICH_DELETE_ENABLED => false,
    ];

    private const BOOLEAN_KEYS = [
        self::KEY_PROVISIONING_ENABLED,
        self::KEY_MKDIR_POLICY_ENABLED,
        self::KEY_EXTERNAL_STORAGE_AUTO_CREATE,
        self::KEY_EXPORT_COPY_ENABLED,
        self::KEY_IMPORT_TO_IMMICH_ENABLED,
        self::KEY_IMMICH_DELETE_ENABLED,
    ];

    private const TEMPLATE_KEYS = [
        self::KEY_STORAGE_LABEL_TEMPLATE,
        self::KEY_EMAIL_TEMPLATE,
        self::KEY_HOST_PATH_TEMPLATE,
        self::KEY_NC_VISIBLE_PATH_TEMPLATE,
        self::KEY_MOUNT_NAME_TEMPLATE,
    ];

    private const PATH_TEMPLATE_KEYS = [
        self::KEY_HOST_PATH_TEMPLATE,
        self::KEY_NC_VISIBLE_PATH_TEMPLATE,
    ];

    private const PATH_DEPENDENT_KEYS = [
        self::KEY_PROVISIONING_ENABLED,
        self::KEY_MKDIR_POLICY_ENABLED,
        self::KEY_EXTERNAL_STORAGE_AUTO_CREATE,
    ];

    private const VALID_USER_SCOPE_MODES = ['all', 'groups'];
    private const VALID_BROWSING_MODES = [self::BROWSING_MODE_PERSONAL, self::BROWSING_MODE_ADMIN_MANAGED];
    private const VALID_QUOTA_SYNC_MODES = ['disabled', 'manual', 'event_scheduled'];
    private const VALID_DELETE_DISABLE_POLICIES = ['disable_suspend', 'delete_opt_in'];
    private const VALID_INITIAL_PASSWORD_POLICIES = ['random', 'sso_oidc'];
    private const VALID_PLACEHOLDERS = ['uid', 'storageLabel'];

    public function __construct(
        private IConfig $config,
        private ICrypto $crypto,
        private LoggerInterface $logger,
    ) {
    }

    public function getAdminConfig(): array {
        $config = $this->readConfigValues();
        $config['admin_api_key_configured'] = $this->readAdminApiKeyState()['value'] !== '';

        return $config;
    }

    public function getImmichBaseUrl(): string {
        return rtrim(trim($this->getAppString(self::KEY_IMMICH_BASE_URL, '')), '/');
    }

    public function getAdminApiKey(): string {
        return $this->readAdminApiKeyState()['value'];
    }

    public function allowsDestructiveUserDelete(): bool {
        return $this->getAppString(self::KEY_DELETE_DISABLE_POLICY, 'disable_suspend') === 'delete_opt_in';
    }

    public function getInitialPasswordPolicy(): string {
        $policy = $this->getAppString(self::KEY_INITIAL_PASSWORD_POLICY, 'random');
        return in_array($policy, self::VALID_INITIAL_PASSWORD_POLICIES, true) ? $policy : 'random';
    }

    public function isExportCopyEnabled(): bool {
        return $this->getAppBool(self::KEY_EXPORT_COPY_ENABLED, false);
    }

    public function isImportToImmichEnabled(): bool {
        return $this->getAppBool(self::KEY_IMPORT_TO_IMMICH_ENABLED, false);
    }

    public function isImmichDeleteEnabled(): bool {
        return $this->getAppBool(self::KEY_IMMICH_DELETE_ENABLED, false);
    }

    public function setAdminConfig(array $values): void {
        $current = $this->readConfigValues();
        $merged = $current;
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $values)) {
                $merged[$key] = $values[$key];
            }
        }
        if (array_key_exists(self::DELETE_OPT_IN_CONFIRMATION_FLAG, $values)) {
            $merged[self::DELETE_OPT_IN_CONFIRMATION_FLAG] = $values[self::DELETE_OPT_IN_CONFIRMATION_FLAG];
        }

        $errors = $this->validateAdminConfig($merged, $current);
        if ($errors !== []) {
            throw new \InvalidArgumentException('Invalid admin configuration: ' . implode('; ', $errors));
        }

        $normalised = $this->normaliseConfigValues($merged);
        foreach ($normalised as $key => $value) {
            $this->config->setAppValue(Application::APP_ID, $key, $this->serialiseValue($key, $value));
        }

        if (array_key_exists(self::KEY_ADMIN_API_KEY, $values) && trim((string)$values[self::KEY_ADMIN_API_KEY]) !== '') {
            $this->storeEncryptedAdminApiKey(trim((string)$values[self::KEY_ADMIN_API_KEY]));
            return;
        }

        $adminApiKey = $this->readAdminApiKeyState();
        if ($adminApiKey['legacyPlaintext'] && $adminApiKey['value'] !== '') {
            $this->storeEncryptedAdminApiKey($adminApiKey['value']);
        }
    }

    public function validateAdminConfig(array $values, ?array $currentValues = null): array {
        $messages = [];
        foreach ($this->validateAdminConfigDetails($values, $currentValues) as $field => $detail) {
            $messages[$field] = (string)$detail['message'];
        }

        return $messages;
    }

    public function validateAdminConfigDetails(array $values, ?array $currentValues = null): array {
        $currentValues = array_merge(self::DEFAULTS, $currentValues ?? $this->readConfigValues());
        $values = array_merge(self::DEFAULTS, $values);
        $effectiveValues = $this->normaliseEffectivePathFeatureFlags($values);
        $errors = [];

        $url = trim((string)$values[self::KEY_IMMICH_BASE_URL]);
        if ($url !== '') {
            $parsedUrl = parse_url($url);
            if ($parsedUrl === false
                || !in_array(strtolower((string)($parsedUrl['scheme'] ?? '')), ['http', 'https'], true)
                || empty($parsedUrl['host'])) {
                $errors[self::KEY_IMMICH_BASE_URL] = $this->validationDetail(
                    self::KEY_IMMICH_BASE_URL,
                    self::VALIDATION_INVALID_URL,
                    'Immich base URL must be a valid http or https URL with a host.',
                    ['allowed_schemes' => ['http', 'https']]
                );
            }
        }

        if (!in_array((string)$values[self::KEY_USER_SCOPE_MODE], self::VALID_USER_SCOPE_MODES, true)) {
            $errors[self::KEY_USER_SCOPE_MODE] = $this->validationDetail(
                self::KEY_USER_SCOPE_MODE,
                self::VALIDATION_INVALID_ENUM,
                'User scope mode must be all or groups.',
                ['allowed_values' => self::VALID_USER_SCOPE_MODES]
            );
        }

        if (!in_array((string)$values[self::KEY_IMMICH_BROWSING_MODE], self::VALID_BROWSING_MODES, true)) {
            $errors[self::KEY_IMMICH_BROWSING_MODE] = $this->validationDetail(
                self::KEY_IMMICH_BROWSING_MODE,
                self::VALIDATION_INVALID_ENUM,
                'Immich browsing mode must be personal or admin_managed.',
                ['allowed_values' => self::VALID_BROWSING_MODES]
            );
        }

        if ($this->parseGroups($values[self::KEY_USER_SCOPE_GROUPS]) === null) {
            $errors[self::KEY_USER_SCOPE_GROUPS] = $this->validationDetail(
                self::KEY_USER_SCOPE_GROUPS,
                self::VALIDATION_INVALID_GROUP_LIST,
                'User scope groups must be a JSON array of non-empty group IDs.'
            );
        }

        $pathTemplatesRequired = $this->pathTemplatesRequired($effectiveValues);
        foreach (self::TEMPLATE_KEYS as $key) {
            $template = (string)$effectiveValues[$key];
            if (!$pathTemplatesRequired && in_array($key, self::PATH_TEMPLATE_KEYS, true) && trim($template) === '') {
                continue;
            }

            $error = $this->validateTemplate($key, $template);
            if ($error !== null) {
                $errors[$key] = $error;
            }
        }

        foreach (self::BOOLEAN_KEYS as $key) {
            if ($this->parseBool($values[$key]) === null) {
                $errors[$key] = $this->validationDetail(
                    $key,
                    self::VALIDATION_INVALID_BOOLEAN,
                    'Value must be boolean.',
                    ['accepted_values' => ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off']]
                );
            }
        }

        if (!in_array((string)$values[self::KEY_QUOTA_SYNC_MODE], self::VALID_QUOTA_SYNC_MODES, true)) {
            $errors[self::KEY_QUOTA_SYNC_MODE] = $this->validationDetail(
                self::KEY_QUOTA_SYNC_MODE,
                self::VALIDATION_INVALID_ENUM,
                'Quota sync mode must be disabled, manual, or event_scheduled.',
                ['allowed_values' => self::VALID_QUOTA_SYNC_MODES]
            );
        }

        if (!in_array((string)$values[self::KEY_INITIAL_PASSWORD_POLICY], self::VALID_INITIAL_PASSWORD_POLICIES, true)) {
            $errors[self::KEY_INITIAL_PASSWORD_POLICY] = $this->validationDetail(
                self::KEY_INITIAL_PASSWORD_POLICY,
                self::VALIDATION_INVALID_ENUM,
                'Initial password policy must be random or sso_oidc.',
                ['allowed_values' => self::VALID_INITIAL_PASSWORD_POLICIES]
            );
        }

        $reserveBytes = $this->parseInt($values[self::KEY_QUOTA_RESERVE_BYTES]);
        if ($reserveBytes === null || $reserveBytes < 0) {
            $errors[self::KEY_QUOTA_RESERVE_BYTES] = $this->validationDetail(
                self::KEY_QUOTA_RESERVE_BYTES,
                self::VALIDATION_INVALID_QUOTA_RESERVE,
                'Quota reserve bytes must be an integer greater than or equal to 0.',
                ['min' => 0]
            );
        }

        $deletePolicy = (string)$values[self::KEY_DELETE_DISABLE_POLICY];
        $previousDeletePolicy = (string)$currentValues[self::KEY_DELETE_DISABLE_POLICY];
        $requiresDeleteOptInConfirmation = $deletePolicy === 'delete_opt_in' && $previousDeletePolicy !== 'delete_opt_in';
        if (!in_array($deletePolicy, self::VALID_DELETE_DISABLE_POLICIES, true)) {
            $errors[self::KEY_DELETE_DISABLE_POLICY] = $this->validationDetail(
                self::KEY_DELETE_DISABLE_POLICY,
                self::VALIDATION_INVALID_ENUM,
                'Delete/disable policy must be disable_suspend or delete_opt_in.',
                ['allowed_values' => self::VALID_DELETE_DISABLE_POLICIES]
            );
        } elseif ($requiresDeleteOptInConfirmation && $this->parseBool($values[self::DELETE_OPT_IN_CONFIRMATION_FLAG] ?? false) !== true) {
            $errors[self::KEY_DELETE_DISABLE_POLICY] = $this->validationDetail(
                self::KEY_DELETE_DISABLE_POLICY,
                self::VALIDATION_DELETE_OPT_IN_CONFIRMATION_REQUIRED,
                'Destructive delete policy requires explicit delete_opt_in confirmation.',
                ['confirmation_flag' => self::DELETE_OPT_IN_CONFIRMATION_FLAG]
            );
        }

        return $errors;
    }

    public function isConfigured(): bool {
        return trim($this->getAppString(self::KEY_IMMICH_BASE_URL, '')) !== ''
            && $this->readAdminApiKeyState()['value'] !== '';
    }

    private function readConfigValues(): array {
        return [
            self::KEY_IMMICH_BASE_URL => rtrim(trim($this->getAppString(self::KEY_IMMICH_BASE_URL, '')), '/'),
            self::KEY_IMMICH_BROWSING_MODE => $this->getAppString(self::KEY_IMMICH_BROWSING_MODE, self::BROWSING_MODE_ADMIN_MANAGED),
            self::KEY_PROVISIONING_ENABLED => $this->getAppBool(self::KEY_PROVISIONING_ENABLED, false),
            self::KEY_USER_SCOPE_MODE => $this->getAppString(self::KEY_USER_SCOPE_MODE, 'all'),
            self::KEY_USER_SCOPE_GROUPS => $this->getAppGroups(),
            self::KEY_STORAGE_LABEL_TEMPLATE => $this->getAppString(self::KEY_STORAGE_LABEL_TEMPLATE, '{uid}'),
            self::KEY_EMAIL_TEMPLATE => $this->getAppString(self::KEY_EMAIL_TEMPLATE, '{uid}@immich.local'),
            self::KEY_INITIAL_PASSWORD_POLICY => $this->getAppString(self::KEY_INITIAL_PASSWORD_POLICY, 'random'),
            self::KEY_HOST_PATH_TEMPLATE => $this->getAppString(self::KEY_HOST_PATH_TEMPLATE, ''),
            self::KEY_NC_VISIBLE_PATH_TEMPLATE => $this->getAppString(self::KEY_NC_VISIBLE_PATH_TEMPLATE, ''),
            self::KEY_MOUNT_NAME_TEMPLATE => $this->getAppString(self::KEY_MOUNT_NAME_TEMPLATE, 'Immich Photos'),
            self::KEY_MKDIR_POLICY_ENABLED => $this->getAppBool(self::KEY_MKDIR_POLICY_ENABLED, false),
            self::KEY_QUOTA_SYNC_MODE => $this->getAppString(self::KEY_QUOTA_SYNC_MODE, 'disabled'),
            self::KEY_QUOTA_RESERVE_BYTES => $this->getAppInt(self::KEY_QUOTA_RESERVE_BYTES, self::DEFAULT_QUOTA_RESERVE_BYTES),
            self::KEY_DELETE_DISABLE_POLICY => $this->getAppString(self::KEY_DELETE_DISABLE_POLICY, 'disable_suspend'),
            self::KEY_EXTERNAL_STORAGE_AUTO_CREATE => $this->getAppBool(self::KEY_EXTERNAL_STORAGE_AUTO_CREATE, false),
            self::KEY_EXPORT_COPY_ENABLED => $this->getAppBool(self::KEY_EXPORT_COPY_ENABLED, false),
            self::KEY_IMPORT_TO_IMMICH_ENABLED => $this->getAppBool(self::KEY_IMPORT_TO_IMMICH_ENABLED, false),
            self::KEY_IMMICH_DELETE_ENABLED => $this->getAppBool(self::KEY_IMMICH_DELETE_ENABLED, false),
        ];
    }

    private function normaliseConfigValues(array $values): array {
        $values = $this->normaliseEffectivePathFeatureFlags($values);

        return [
            self::KEY_IMMICH_BASE_URL => rtrim(trim((string)$values[self::KEY_IMMICH_BASE_URL]), '/'),
            self::KEY_IMMICH_BROWSING_MODE => (string)$values[self::KEY_IMMICH_BROWSING_MODE],
            self::KEY_PROVISIONING_ENABLED => $this->parseBool($values[self::KEY_PROVISIONING_ENABLED]) ?? false,
            self::KEY_USER_SCOPE_MODE => (string)$values[self::KEY_USER_SCOPE_MODE],
            self::KEY_USER_SCOPE_GROUPS => $this->parseGroups($values[self::KEY_USER_SCOPE_GROUPS]) ?? [],
            self::KEY_STORAGE_LABEL_TEMPLATE => trim((string)$values[self::KEY_STORAGE_LABEL_TEMPLATE]),
            self::KEY_EMAIL_TEMPLATE => trim((string)$values[self::KEY_EMAIL_TEMPLATE]),
            self::KEY_INITIAL_PASSWORD_POLICY => (string)$values[self::KEY_INITIAL_PASSWORD_POLICY],
            self::KEY_HOST_PATH_TEMPLATE => trim((string)$values[self::KEY_HOST_PATH_TEMPLATE]),
            self::KEY_NC_VISIBLE_PATH_TEMPLATE => trim((string)$values[self::KEY_NC_VISIBLE_PATH_TEMPLATE]),
            self::KEY_MOUNT_NAME_TEMPLATE => trim((string)$values[self::KEY_MOUNT_NAME_TEMPLATE]),
            self::KEY_MKDIR_POLICY_ENABLED => $this->parseBool($values[self::KEY_MKDIR_POLICY_ENABLED]) ?? false,
            self::KEY_QUOTA_SYNC_MODE => (string)$values[self::KEY_QUOTA_SYNC_MODE],
            self::KEY_QUOTA_RESERVE_BYTES => $this->parseInt($values[self::KEY_QUOTA_RESERVE_BYTES]) ?? self::DEFAULT_QUOTA_RESERVE_BYTES,
            self::KEY_DELETE_DISABLE_POLICY => (string)$values[self::KEY_DELETE_DISABLE_POLICY],
            self::KEY_EXTERNAL_STORAGE_AUTO_CREATE => $this->parseBool($values[self::KEY_EXTERNAL_STORAGE_AUTO_CREATE]) ?? false,
            self::KEY_EXPORT_COPY_ENABLED => $this->parseBool($values[self::KEY_EXPORT_COPY_ENABLED]) ?? false,
            self::KEY_IMPORT_TO_IMMICH_ENABLED => $this->parseBool($values[self::KEY_IMPORT_TO_IMMICH_ENABLED]) ?? false,
            self::KEY_IMMICH_DELETE_ENABLED => $this->parseBool($values[self::KEY_IMMICH_DELETE_ENABLED]) ?? false,
        ];
    }

    private function normaliseEffectivePathFeatureFlags(array $values): array {
        if (($values[self::KEY_IMMICH_BROWSING_MODE] ?? self::BROWSING_MODE_ADMIN_MANAGED) === self::BROWSING_MODE_PERSONAL) {
            $values[self::KEY_PROVISIONING_ENABLED] = false;
            $values[self::KEY_MKDIR_POLICY_ENABLED] = false;
            $values[self::KEY_EXTERNAL_STORAGE_AUTO_CREATE] = false;
            $values[self::KEY_QUOTA_SYNC_MODE] = 'disabled';
        }

        if ($this->parseBool($values[self::KEY_PROVISIONING_ENABLED] ?? false) !== true) {
            $values[self::KEY_MKDIR_POLICY_ENABLED] = false;
            $values[self::KEY_EXTERNAL_STORAGE_AUTO_CREATE] = false;
        }

        return $values;
    }

    private function serialiseValue(string $key, mixed $value): string {
        if (in_array($key, self::BOOLEAN_KEYS, true)) {
            return $value ? '1' : '0';
        }

        if ($key === self::KEY_USER_SCOPE_GROUPS) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        return (string)$value;
    }

    private function readAdminApiKeyState(): array {
        $stored = $this->getAppString(self::KEY_ADMIN_API_KEY, '');
        if ($stored === '') {
            return [
                'value' => '',
                'legacyPlaintext' => false,
            ];
        }

        try {
            return [
                'value' => $this->crypto->decrypt($stored),
                'legacyPlaintext' => false,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('ICrypto decrypt failed for admin_api_key; assuming legacy plaintext value. Re-save admin settings to encrypt it.', [
                'app' => Application::APP_ID,
            ]);

            return [
                'value' => $stored,
                'legacyPlaintext' => true,
            ];
        }
    }

    private function storeEncryptedAdminApiKey(string $apiKey): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_ADMIN_API_KEY, $this->crypto->encrypt($apiKey));
    }

    private function getAppString(string $key, string $default): string {
        return (string)$this->config->getAppValue(Application::APP_ID, $key, $default);
    }

    private function getAppBool(string $key, bool $default): bool {
        return $this->parseBool($this->getAppString($key, $default ? '1' : '0')) ?? $default;
    }

    private function getAppInt(string $key, int $default): int {
        return $this->parseInt($this->getAppString($key, (string)$default)) ?? $default;
    }

    private function getAppGroups(): array {
        return $this->parseGroups($this->getAppString(self::KEY_USER_SCOPE_GROUPS, '[]')) ?? [];
    }

    private function parseBool(mixed $value): ?bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return match ($value) {
                0 => false,
                1 => true,
                default => null,
            };
        }

        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off', '' => false,
            default => null,
        };
    }

    private function parseInt(mixed $value): ?int {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int)trim($value);
        }

        return null;
    }

    private function parseGroups(mixed $value): ?array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if ($decoded === null && trim($value) !== '[]') {
                return null;
            }
            $value = $decoded ?? [];
        }

        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $groups = [];
        foreach ($value as $groupId) {
            if (!is_string($groupId) || trim($groupId) === '' || preg_match('/\0/', $groupId) === 1) {
                return null;
            }
            $groups[] = trim($groupId);
        }

        return $groups;
    }

    private function pathTemplatesRequired(array $values): bool {
        foreach (self::PATH_DEPENDENT_KEYS as $key) {
            if ($this->parseBool($values[$key] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function validateTemplate(string $field, string $template): ?array {
        $template = trim($template);
        $isPathTemplate = in_array($field, self::PATH_TEMPLATE_KEYS, true);
        if ($template === '') {
            return $this->validationDetail(
                $field,
                $isPathTemplate ? self::VALIDATION_MISSING_PATH_TEMPLATE : self::VALIDATION_INVALID_TEMPLATE,
                'Template must not be empty.'
            );
        }

        if (preg_match('/\0/', $template) === 1) {
            return $this->validationDetail(
                $field,
                $isPathTemplate ? self::VALIDATION_INVALID_PATH_TEMPLATE : self::VALIDATION_INVALID_TEMPLATE,
                'Template must not contain NUL bytes.'
            );
        }

        if (preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $template) === 1) {
            return $this->validationDetail(
                $field,
                self::VALIDATION_INVALID_PATH_TEMPLATE,
                'Template must not contain path traversal segments.'
            );
        }

        preg_match_all('/\{([^{}]+)\}/', $template, $matches);
        foreach ($matches[1] as $placeholder) {
            if (!in_array($placeholder, self::VALID_PLACEHOLDERS, true)) {
                return $this->validationDetail(
                    $field,
                    self::VALIDATION_UNSUPPORTED_TEMPLATE_PLACEHOLDER,
                    'Template contains an unsupported placeholder.',
                    ['allowed_placeholders' => self::VALID_PLACEHOLDERS]
                );
            }
        }

        $withoutValidPlaceholders = preg_replace('/\{(?:uid|storageLabel)\}/', '', $template);
        if ($withoutValidPlaceholders !== null && preg_match('/[{}]/', $withoutValidPlaceholders) === 1) {
            return $this->validationDetail(
                $field,
                self::VALIDATION_INVALID_TEMPLATE,
                'Template contains an unsupported placeholder.',
                ['allowed_placeholders' => self::VALID_PLACEHOLDERS]
            );
        }

        return null;
    }

    private function validationDetail(string $field, string $code, string $message, array $params = []): array {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
            'params' => $params,
        ];
    }

}
