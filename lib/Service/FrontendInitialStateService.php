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

        return [
            'immich_url' => (string)($config[AdminConfigService::KEY_IMMICH_BASE_URL] ?? ''),
            'provisioning' => $provisioning,
            'mapping' => $this->mappingState($ncUid, $syncState),
            'mount' => $this->mountState($ncUid, $syncState, $warnings),
            'quota' => $this->quotaState($ncUid, $syncState, $config, $warnings),
            'actions' => $this->actionsFromCapabilities($actionCapabilities),
            'actionCapabilities' => $actionCapabilities,
            'warnings' => array_values(array_unique($warnings)),
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
            'warnings' => array_values(array_unique($warnings)),
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
            $warnings[] = 'Immich mapping status is temporarily unavailable.';
            return null;
        }
    }

    private function mappingState(?string $ncUid, ?SyncState $syncState): array {
        if ($ncUid === null || trim($ncUid) === '') {
            return [
                'status' => 'missing',
                'message' => 'No active Nextcloud user context is available for Immich provisioning.',
            ];
        }

        if ($syncState === null) {
            return [
                'status' => 'missing',
                'message' => 'No Immich mapping exists for this Nextcloud user yet. Ask an administrator to run Immich provisioning.',
            ];
        }

        $immichUserId = trim((string)$syncState->getImmichUserId());
        $mapping = [
            'status' => $this->mappingStatus($syncState),
            'storageLabel' => $syncState->getStorageLabel(),
            'lastSyncAt' => $this->formatDateTime($syncState->getUpdatedAt()),
        ];

        if ($immichUserId !== '') {
            $mapping['immichUserId'] = $immichUserId;
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
        ];

        if ($ncUid === null || trim($ncUid) === '' || $syncState === null) {
            return $summary;
        }

        try {
            $health = $this->externalStorageProvisioner->verifyMount($ncUid);
        } catch (\Throwable) {
            $warnings[] = 'Immich mirror mount health is temporarily unavailable.';
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
            $warnings[] = 'Immich mirror mount health is ' . $summary['status'] . '.';
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
            $summary['warning'] = 'Quota sync needs an Immich user mapping before quota details are available.';
            return $summary;
        }

        $computedQuota = $this->quotaSyncService->computeQuota($ncUid, null);
        $summary['computedImmichQuota'] = $computedQuota;

        if ($computedQuota !== null) {
            $summary['status'] = 'ok';
        }

        if ($this->quotaSyncService->getLastError() !== null) {
            $summary['status'] = 'failed';
            $summary['warning'] = 'Quota details are unavailable. Run quota sync from the admin settings for authoritative status.';
            $warnings[] = $summary['warning'];
            return $summary;
        }

        if ($computedQuota === null && $this->quotaSyncService->wasLastQuotaUnlimited()) {
            $summary['status'] = 'unlimited';
            $summary['warning'] = 'Nextcloud quota is unlimited; Immich quota sync will leave the Immich quota unlimited.';
            return $summary;
        }

        if ($summary['stale'] === true) {
            $summary['warning'] = 'Quota has not been synced yet; values may be stale until the next quota sync job runs.';
        }

        return $summary;
    }

    private function safeActionCapabilities(?string $ncUid, array &$warnings): array {
        try {
            $flags = $this->actionPolicyService->getCapabilityFlags($ncUid);
        } catch (\Throwable) {
            $warnings[] = 'Immich action capabilities are temporarily unavailable.';
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
            $warnings[] = 'Immich sync-state list is temporarily unavailable.';
            return [];
        }

        return array_map(fn(SyncState $state): array => [
            'ncUid' => $state->getNcUid(),
            'immichUserId' => $state->getImmichUserId(),
            'immichEmail' => $state->getImmichEmail(),
            'storageLabel' => $state->getStorageLabel(),
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
            $warnings[] = 'Immich capability detection is temporarily unavailable.';
            return [];
        }
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
        return preg_replace('/(api[_-]?key|token|password|secret|authorization)(\s*[=:]\s*)[^\s,;]+/i', '$1$2[redacted]', $value) ?? $value;
    }

    private function isSecretKey(string $key): bool {
        if (preg_match('/(?:configured|_set)$/i', $key) === 1) {
            return false;
        }

        return preg_match('/(^|[_-])(api[_-]?key|token|password|secret|authorization)($|[_-])/i', $key) === 1;
    }

    private function formatDateTime(?DateTimeInterface $dateTime): ?string {
        return $dateTime?->format(DateTimeInterface::ATOM);
    }
}
