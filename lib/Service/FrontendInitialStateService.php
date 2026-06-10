<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use DateTimeInterface;
use OCA\IntegrationImmich\Db\SyncState;

class FrontendInitialStateService {
    private const CODE_MAPPING_STATUS_UNAVAILABLE = 'mapping_status_unavailable';
    private const CODE_NO_ACTIVE_NC_USER = 'no_active_nc_user';
    private const CODE_NO_IMMICH_MAPPING = 'no_immich_mapping';
    private const CODE_MOUNT_HEALTH_UNAVAILABLE = 'mount_health_unavailable';
    private const CODE_MOUNT_HEALTH_STATUS = 'mount_health_status';
    private const CODE_QUOTA_NEEDS_MAPPING = 'quota_needs_mapping';
    private const CODE_QUOTA_UNAVAILABLE = 'quota_unavailable';
    private const CODE_QUOTA_UNLIMITED = 'quota_unlimited';
    private const CODE_QUOTA_STALE = 'quota_stale';
    private const CODE_ACTION_CAPABILITIES_UNAVAILABLE = 'action_capabilities_unavailable';
    private const CODE_SYNC_STATE_LIST_UNAVAILABLE = 'sync_state_list_unavailable';
    private const CODE_CAPABILITY_DETECTION_UNAVAILABLE = 'capability_detection_unavailable';

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
        private AdminConfigService $adminConfigService,
        private SyncStateService $syncStateService,
        private CapabilityService $capabilityService,
        private ActionPolicyService $actionPolicyService,
        private ExternalStorageProvisioner $externalStorageProvisioner,
        private QuotaSyncService $quotaSyncService,
    ) {
    }

    public function buildUserState(?string $ncUid): array {
        $warnings = [];
        $config = $this->safeAdminConfig();
        $syncState = $this->currentUserSyncState($ncUid, $warnings);
        $actionCapabilities = $this->safeActionCapabilities($ncUid, $warnings);
        $provisioning = $this->provisioningState($config);

        if ($syncState !== null) {
            $provisioning['status'] = $syncState->getScopeStatus();
        }

        $mapping = $this->mappingState($ncUid, $syncState);
        $mount = $this->mountState($ncUid, $syncState, $warnings);
        $quota = $this->quotaState($ncUid, $syncState, $config, $warnings);
        $quotaStatus = $this->quotaReadinessStatus($quota);

        return [
            'immich_url' => (string)($config[AdminConfigService::KEY_IMMICH_BASE_URL] ?? ''),
            'provisioning' => $provisioning,
            'browsingReadiness' => $this->browsingReadinessState($config, $provisioning, $mapping, $mount, $quotaStatus, $warnings),
            'quotaStatus' => $quotaStatus,
            'mapping' => $mapping,
            'mount' => $mount,
            'quota' => $quota,
            'actions' => $this->actionsFromCapabilities($actionCapabilities),
            'actionCapabilities' => $actionCapabilities,
            'warnings' => $this->warningMessages($warnings),
            'warningDetails' => $this->warningDetails($warnings),
        ];
    }

    public function buildAdminState(): array {
        $warnings = [];
        $config = $this->safeAdminConfig();

        return [
            'settings' => $config,
            'status' => $this->adminStatus($config),
            'syncStates' => $this->adminSyncStates($warnings),
            'capabilities' => $this->adminCapabilities($warnings),
            'warnings' => $this->warningMessages($warnings),
            'warningDetails' => $this->warningDetails($warnings),
            // Legacy keys consumed by the current Vue settings form until T19-T21 switch to settings.*.
            'server_url' => (string)($config[AdminConfigService::KEY_IMMICH_BASE_URL] ?? ''),
            'api_key_set' => (bool)($config['admin_api_key_configured'] ?? false),
        ];
    }

    private function currentUserSyncState(?string $ncUid, array &$warnings): ?SyncState {
        if ($ncUid === null || trim($ncUid) === '') {
            return null;
        }

        try {
            return $this->syncStateService->findByUid($ncUid);
        } catch (\Throwable) {
            $this->addWarning($warnings, self::CODE_MAPPING_STATUS_UNAVAILABLE);
            return null;
        }
    }

    private function mappingState(?string $ncUid, ?SyncState $syncState): array {
        if ($ncUid === null || trim($ncUid) === '') {
            $messageCode = self::CODE_NO_ACTIVE_NC_USER;
            return [
                'status' => 'missing',
                'message' => $this->messageForCode($messageCode),
                'messageCode' => $messageCode,
                'messageParams' => [],
            ];
        }

        if ($syncState === null) {
            $messageCode = self::CODE_NO_IMMICH_MAPPING;
            return [
                'status' => 'missing',
                'message' => $this->messageForCode($messageCode),
                'messageCode' => $messageCode,
                'messageParams' => [],
            ];
        }

        $immichUserId = trim((string)$syncState->getImmichUserId());
        $mapping = [
            'status' => $this->mappingStatus($syncState),
            'nc_uid' => $ncUid,
            'storageLabel' => $syncState->getStorageLabel(),
            'storage_label' => $syncState->getStorageLabel(),
            'lastSyncAt' => $this->formatDateTime($syncState->getUpdatedAt()),
        ];

        if ($immichUserId !== '') {
            $mapping['immichUserId'] = $immichUserId;
            $mapping['immich_user_id'] = $immichUserId;
        }

        return $mapping;
    }

    private function mappingStatus(SyncState $syncState): string {
        $lastSyncStatus = trim((string)$syncState->getLastSyncStatus());
        if ($lastSyncStatus !== '') {
            return $lastSyncStatus;
        }

        $scopeStatus = trim((string)$syncState->getScopeStatus());
        return $scopeStatus !== '' ? $scopeStatus : SyncStateService::STATUS_PENDING;
    }

    private function mountState(?string $ncUid, ?SyncState $syncState, array &$warnings): array {
        $summary = [
            'status' => 'unavailable',
            'mountId' => null,
            'path' => null,
            'readOnly' => null,
            'warning' => null,
            'warningCode' => null,
            'warningParams' => [],
        ];

        if ($ncUid === null || trim($ncUid) === '' || $syncState === null) {
            return $summary;
        }

        try {
            $health = $this->externalStorageProvisioner->verifyMount($ncUid);
        } catch (\Throwable) {
            $this->setWarningFields($summary, self::CODE_MOUNT_HEALTH_UNAVAILABLE);
            $this->addWarning($warnings, self::CODE_MOUNT_HEALTH_UNAVAILABLE);
            return $summary;
        }

        $summary['status'] = (string)($health['status'] ?? 'unknown');
        if (is_int($health['mount_id'] ?? null)) {
            $summary['mountId'] = $health['mount_id'];
        }
        if (is_string($health['mount_name'] ?? null) && trim($health['mount_name']) !== '') {
            $summary['path'] = $health['mount_name'];
        }
        if (array_key_exists('read_only', $health)) {
            $summary['readOnly'] = (bool)$health['read_only'];
        }

        if ($summary['status'] !== 'ok') {
            $this->setWarningFields($summary, self::CODE_MOUNT_HEALTH_STATUS, ['status' => $summary['status']]);
            $this->addWarning($warnings, self::CODE_MOUNT_HEALTH_STATUS, ['status' => $summary['status']]);
        }

        return $summary;
    }

    private function quotaState(?string $ncUid, ?SyncState $syncState, array $config, array &$warnings): array {
        $mode = (string)($config[AdminConfigService::KEY_QUOTA_SYNC_MODE] ?? 'disabled');
        $summary = [
            'status' => $mode === 'disabled' ? 'disabled' : 'unavailable',
            'mode' => $mode,
            'ncQuota' => null,
            'ncUsed' => null,
            'immichUsage' => null,
            'computedImmichQuota' => null,
            'reserve' => $this->reserveBytes($config),
            'stale' => true,
            'warning' => null,
            'warningCode' => null,
            'warningParams' => [],
            'lastSyncAt' => null,
        ];

        if (!in_array($mode, ['manual', 'event_scheduled'], true)) {
            $summary['stale'] = false;
            return $summary;
        }

        if ($syncState !== null) {
            $summary['stale'] = $syncState->getLastQuotaSyncAt() === null;
            $summary['lastSyncAt'] = $this->formatDateTime($syncState->getLastQuotaSyncAt());
        }

        if ($ncUid === null || trim($ncUid) === '' || $syncState === null || trim((string)$syncState->getImmichUserId()) === '') {
            $this->setWarningFields($summary, self::CODE_QUOTA_NEEDS_MAPPING);
            return $summary;
        }

        $computedQuota = $this->quotaSyncService->computeQuota($ncUid, null);
        $summary['computedImmichQuota'] = $computedQuota;

        if ($computedQuota !== null) {
            $summary['status'] = 'ok';
        }

        if ($this->quotaSyncService->getLastError() !== null) {
            $summary['status'] = 'failed';
            $this->setWarningFields($summary, self::CODE_QUOTA_UNAVAILABLE);
            $this->addWarning($warnings, self::CODE_QUOTA_UNAVAILABLE);
            return $summary;
        }

        if ($computedQuota === null && $this->quotaSyncService->wasLastQuotaUnlimited()) {
            $summary['status'] = 'unlimited';
            $this->setWarningFields($summary, self::CODE_QUOTA_UNLIMITED);
            return $summary;
        }

        if ($summary['stale'] === true) {
            $this->setWarningFields($summary, self::CODE_QUOTA_STALE);
        }

        return $summary;
    }

    private function safeActionCapabilities(?string $ncUid, array &$warnings): array {
        try {
            $flags = $this->actionPolicyService->getCapabilityFlags($ncUid);
        } catch (\Throwable) {
            $this->addWarning($warnings, self::CODE_ACTION_CAPABILITIES_UNAVAILABLE);
            $flags = [];
        }

        return [
            'exportCopyEnabled' => ($flags['exportCopyEnabled'] ?? false) === true,
            'importToImmichEnabled' => ($flags['importToImmichEnabled'] ?? false) === true,
            'immichDeleteEnabled' => ($flags['immichDeleteEnabled'] ?? false) === true,
            'mirrorMountPaths' => array_values(array_filter(
                array_map('strval', is_array($flags['mirrorMountPaths'] ?? null) ? $flags['mirrorMountPaths'] : []),
                static fn(string $path): bool => trim($path) !== ''
            )),
        ];
    }

    private function actionsFromCapabilities(array $capabilities): array {
        return [
            'exportCopyEnabled' => ($capabilities['exportCopyEnabled'] ?? false) === true,
            'importToImmichEnabled' => ($capabilities['importToImmichEnabled'] ?? false) === true,
            'immichDeleteEnabled' => ($capabilities['immichDeleteEnabled'] ?? false) === true,
        ];
    }

    private function adminStatus(array $config): array {
        $actionCapabilities = [
            'exportCopyEnabled' => ($config[AdminConfigService::KEY_EXPORT_COPY_ENABLED] ?? false) === true,
            'importToImmichEnabled' => ($config[AdminConfigService::KEY_IMPORT_TO_IMMICH_ENABLED] ?? false) === true,
            'immichDeleteEnabled' => ($config[AdminConfigService::KEY_IMMICH_DELETE_ENABLED] ?? false) === true,
        ];

        return [
            'credentials' => [
                'immich_base_url_configured' => trim((string)($config[AdminConfigService::KEY_IMMICH_BASE_URL] ?? '')) !== '',
                'admin_api_key_configured' => ($config['admin_api_key_configured'] ?? false) === true,
            ],
            'provisioning' => $this->provisioningState($config),
            'quota' => [
                'mode' => (string)($config[AdminConfigService::KEY_QUOTA_SYNC_MODE] ?? 'disabled'),
                'reserve' => $this->reserveBytes($config),
            ],
            'actions' => $actionCapabilities,
        ];
    }

    private function adminSyncStates(array &$warnings): array {
        try {
            $states = $this->syncStateService->listStates(100, 0);
        } catch (\Throwable) {
            $this->addWarning($warnings, self::CODE_SYNC_STATE_LIST_UNAVAILABLE);
            return [];
        }

        return array_map(fn(SyncState $state): array => [
            'ncUid' => $state->getNcUid(),
            'nc_uid' => $state->getNcUid(),
            'immichUserId' => $state->getImmichUserId(),
            'immich_user_id' => $state->getImmichUserId(),
            'immichEmail' => $state->getImmichEmail(),
            'storageLabel' => $state->getStorageLabel(),
            'storage_label' => $state->getStorageLabel(),
            'ncMountId' => $state->getNcMountId(),
            'scopeStatus' => $state->getScopeStatus(),
            'lastSyncStatus' => $state->getLastSyncStatus(),
            'lastError' => $this->redactString((string)($state->getLastError() ?? '')) ?: null,
            'lastQuotaSyncAt' => $this->formatDateTime($state->getLastQuotaSyncAt()),
            'updatedAt' => $this->formatDateTime($state->getUpdatedAt()),
        ], $states);
    }

    private function adminCapabilities(array &$warnings): array {
        try {
            return $this->redact($this->capabilityService->getCapabilities());
        } catch (\Throwable) {
            $this->addWarning($warnings, self::CODE_CAPABILITY_DETECTION_UNAVAILABLE);
            return [];
        }
    }

    private function addWarning(array &$warnings, string $code, array $params = []): void {
        $safeParams = $this->safeParams($params);
        $warnings[] = [
            'code' => $code,
            'message' => $this->messageForCode($code, $safeParams),
            'params' => $safeParams,
        ];
    }

    private function setWarningFields(array &$target, string $code, array $params = []): void {
        $safeParams = $this->safeParams($params);
        $target['warning'] = $this->messageForCode($code, $safeParams);
        $target['warningCode'] = $code;
        $target['warningParams'] = $safeParams;
    }

    private function warningMessages(array $warnings): array {
        $messages = [];
        foreach ($warnings as $warning) {
            if (!is_array($warning) || !is_string($warning['message'] ?? null)) {
                continue;
            }
            $messages[] = $warning['message'];
        }

        return array_values(array_unique($messages));
    }

    private function warningDetails(array $warnings): array {
        $details = [];
        foreach ($warnings as $warning) {
            if (!is_array($warning) || !is_string($warning['code'] ?? null)) {
                continue;
            }
            $params = is_array($warning['params'] ?? null) ? $warning['params'] : [];
            $key = $warning['code'] . ':' . json_encode($params, JSON_THROW_ON_ERROR);
            $details[$key] = [
                'code' => $warning['code'],
                'message' => is_string($warning['message'] ?? null) ? $warning['message'] : $this->messageForCode($warning['code'], $params),
                'params' => $params,
            ];
        }

        return array_values($details);
    }

    private function browsingReadinessState(array $config, array $provisioning, array $mapping, array $mount, string $quotaStatus, array $warnings): array {
        $adminManaged = ($config[AdminConfigService::KEY_IMMICH_BROWSING_MODE] ?? AdminConfigService::BROWSING_MODE_ADMIN_MANAGED) === AdminConfigService::BROWSING_MODE_ADMIN_MANAGED;
        $status = 'ready';
        $messageKey = null;
        $messageParams = [];
        $mountStatus = (string)($mount['status'] ?? 'unavailable');

        if (!$adminManaged) {
            $status = 'personal_unconfigured';
            $messageKey = 'browsing_setup_not_configured';
        } elseif ($this->hasErrorWarning($warnings) || ($mapping['status'] ?? '') === 'error') {
            $status = 'error';
            $messageKey = 'browsing_status_error';
        } elseif (!$this->adminConfigComplete($config)) {
            $status = 'admin_config_missing';
            $messageKey = 'browsing_admin_config_missing';
        } elseif (($mapping['status'] ?? '') === 'missing') {
            $status = 'unmapped';
            $messageKey = self::CODE_NO_IMMICH_MAPPING;
        } elseif ($mountStatus === 'template_verification_required') {
            $status = 'manual_setup_required';
            $messageKey = 'browsing_manual_setup_required';
        } elseif ($mountStatus === 'mount_pending') {
            $status = 'mount_pending';
            $messageKey = 'browsing_mount_pending';
        } elseif ($this->mappingHasImmichUser($mapping)) {
            $status = 'admin_managed_ready';
        }

        $localizedMessage = $messageKey !== null ? $this->messageForCode($messageKey, $messageParams) : null;

        return [
            'status' => $status,
            'severity' => $this->browsingReadinessSeverity($status),
            'messageKey' => $messageKey,
            'localizedMessage' => $localizedMessage,
            'autoLoginMode' => 'sso_recommended',
            'showAppBanner' => false,
            'showSidebarCard' => !in_array($status, ['ready', 'admin_managed_ready'], true),
            'showPersonalSettings' => !$adminManaged,
            'messageParams' => $messageParams,
            'message' => $localizedMessage,
            'messageCode' => $messageKey,
            'adminManaged' => $adminManaged,
            'mapped' => $this->mappingHasImmichUser($mapping),
            'mountStatus' => $mountStatus,
            'quotaStatus' => $quotaStatus,
        ];
    }

    private function browsingReadinessSeverity(string $status): string {
        return match ($status) {
            'error', 'admin_config_missing' => 'error',
            'unmapped', 'manual_setup_required', 'mount_pending', 'personal_unconfigured' => 'warning',
            'admin_managed_ready' => 'success',
            default => 'info',
        };
    }

    private function quotaReadinessStatus(array $quota): string {
        if (($quota['warningCode'] ?? null) === self::CODE_QUOTA_UNAVAILABLE || ($quota['status'] ?? '') === 'failed') {
            return 'sync_failed';
        }

        if (($quota['status'] ?? '') === 'disabled') {
            return 'unknown';
        }

        if (($quota['stale'] ?? false) === true || ($quota['warningCode'] ?? null) === self::CODE_QUOTA_STALE) {
            return 'stale';
        }

        if (($quota['status'] ?? '') === 'ok' || ($quota['status'] ?? '') === 'unlimited') {
            return 'current';
        }

        return 'unknown';
    }

    private function hasErrorWarning(array $warnings): bool {
        foreach ($warnings as $warning) {
            if (!is_array($warning)) {
                continue;
            }

            if (in_array($warning['code'] ?? '', [self::CODE_MAPPING_STATUS_UNAVAILABLE], true)) {
                return true;
            }
        }

        return false;
    }

    private function adminConfigComplete(array $config): bool {
        return trim((string)($config[AdminConfigService::KEY_IMMICH_BASE_URL] ?? '')) !== ''
            && ($config['admin_api_key_configured'] ?? false) === true;
    }

    private function mappingHasImmichUser(array $mapping): bool {
        return trim((string)($mapping['immichUserId'] ?? '')) !== '';
    }

    private function messageForCode(string $code, array $params = []): string {
        return match ($code) {
            self::CODE_MAPPING_STATUS_UNAVAILABLE => 'Immich mapping status is temporarily unavailable.',
            self::CODE_NO_ACTIVE_NC_USER => 'No active Nextcloud user context is available for Immich provisioning.',
            self::CODE_NO_IMMICH_MAPPING => 'No Immich mapping exists for this Nextcloud user yet. Ask an administrator to run Immich provisioning.',
            self::CODE_MOUNT_HEALTH_UNAVAILABLE => 'Immich mirror mount health is temporarily unavailable.',
            self::CODE_MOUNT_HEALTH_STATUS => 'Immich mirror mount health is ' . (string)($params['status'] ?? 'unknown') . '.',
            self::CODE_QUOTA_NEEDS_MAPPING => 'Quota sync needs an Immich user mapping before quota details are available.',
            self::CODE_QUOTA_UNAVAILABLE => 'Quota details are unavailable. Run quota sync from the admin settings for authoritative status.',
            self::CODE_QUOTA_UNLIMITED => 'Nextcloud quota is unlimited; Immich quota sync will leave the Immich quota unlimited.',
            self::CODE_QUOTA_STALE => 'Quota has not been synced yet; values may be stale until the next quota sync job runs.',
            self::CODE_ACTION_CAPABILITIES_UNAVAILABLE => 'Immich action capabilities are temporarily unavailable.',
            self::CODE_SYNC_STATE_LIST_UNAVAILABLE => 'Immich sync-state list is temporarily unavailable.',
            self::CODE_CAPABILITY_DETECTION_UNAVAILABLE => 'Immich capability detection is temporarily unavailable.',
            'browsing_status_error' => 'Immich browsing status is temporarily unavailable.',
            'browsing_admin_config_missing' => 'Immich admin configuration is incomplete. Ask an administrator to configure Immich browsing.',
            'browsing_setup_not_configured' => 'Immich browsing is not configured for this account.',
            'browsing_mount_pending' => 'Immich mirror mount is pending. Upload through Immich first or ask an administrator to reconcile provisioning.',
            'browsing_manual_setup_required' => 'Immich mirror mount requires manual administrator setup before browsing is ready.',
            default => 'Immich status code: ' . $code,
        };
    }

    private function provisioningState(array $config): array {
        $scope = (string)($config[AdminConfigService::KEY_USER_SCOPE_MODE] ?? 'all');
        $state = [
            'enabled' => ($config[AdminConfigService::KEY_PROVISIONING_ENABLED] ?? false) === true,
            'scope' => $scope !== '' ? $scope : 'all',
        ];

        $groups = $config[AdminConfigService::KEY_USER_SCOPE_GROUPS] ?? [];
        if (is_array($groups) && $groups !== []) {
            $state['scopedGroups'] = array_values(array_map('strval', $groups));
        }

        return $state;
    }

    private function safeAdminConfig(): array {
        try {
            $config = $this->adminConfigService->getAdminConfig();
        } catch (\Throwable) {
            $config = [];
        }

        unset($config[AdminConfigService::KEY_ADMIN_API_KEY]);
        $config['admin_api_key_configured'] = ($config['admin_api_key_configured'] ?? false) === true;

        return $this->redact($config);
    }

    private function reserveBytes(array $config): int {
        $reserve = $config[AdminConfigService::KEY_QUOTA_RESERVE_BYTES] ?? 0;
        if (is_int($reserve)) {
            return max(0, $reserve);
        }

        if (is_string($reserve) && preg_match('/^\d+$/', trim($reserve)) === 1) {
            return (int)trim($reserve);
        }

        return 0;
    }

    private function safeParams(array $params): array {
        $safe = [];
        foreach ($params as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (is_string($value)) {
                $safe[$key] = $this->redactString($value);
                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
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
        return preg_replace('/(api[_-]?key|token|password|secret|authorization)(\s*[=:]\s*)[^\s,;}&]+/i', '$1$2[redacted]', $value) ?? $value;
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

    private function formatDateTime(?DateTimeInterface $dateTime): ?string {
        return $dateTime?->format(DateTimeInterface::ATOM);
    }
}
