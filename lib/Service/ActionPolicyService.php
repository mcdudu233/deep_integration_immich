<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use OCA\IntegrationImmich\Db\SyncState;

class ActionPolicyService {
    public const KEY_EXPORT_COPY_ENABLED = 'export_copy_enabled';
    public const KEY_IMPORT_TO_IMMICH_ENABLED = 'import_to_immich_enabled';
    public const KEY_IMMICH_DELETE_ENABLED = 'immich_delete_enabled';

    public function __construct(
        private AdminConfigService $adminConfigService,
        private PathTemplateService $pathTemplateService,
        private SyncStateService $syncStateService,
    ) {
    }

    /**
     * @return array{exportCopyEnabled: bool, importToImmichEnabled: bool, immichDeleteEnabled: bool, mirrorMountPaths: string[]}
     */
    public function getCapabilityFlags(?string $ncUid = null): array {
        return [
            'exportCopyEnabled' => $this->isExportCopyEnabled(),
            'importToImmichEnabled' => $this->isImportToImmichEnabled(),
            'immichDeleteEnabled' => $this->isDeleteEnabled(),
            'mirrorMountPaths' => $ncUid === null || $ncUid === '' ? [] : $this->mirrorMountPathCandidates($ncUid, false),
        ];
    }

    public function isExportCopyEnabled(): bool {
        return $this->adminConfigService->isExportCopyEnabled();
    }

    public function isImportToImmichEnabled(): bool {
        return $this->adminConfigService->isImportToImmichEnabled();
    }

    public function isDeleteEnabled(): bool {
        return $this->adminConfigService->isImmichDeleteEnabled();
    }

    public function isPathInsideMirrorMount(string $ncUid, string $path): bool {
        $pathVariants = $this->pathVariants($path, $ncUid);
        if ($pathVariants === []) {
            return false;
        }

        foreach ($this->mirrorMountPathCandidates($ncUid, true) as $candidate) {
            foreach ($this->candidateVariants($candidate) as $candidateVariant) {
                foreach ($pathVariants as $pathVariant) {
                    if ($this->pathIsWithin($pathVariant, $candidateVariant)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function mirrorMountPathCandidates(string $ncUid, bool $includeFilesystemTarget): array {
        $state = $this->findSyncState($ncUid);
        $config = $this->adminConfigService->getAdminConfig();
        try {
            $storageLabel = $this->storageLabelForUid($ncUid, $state, $config);
        } catch (\Throwable) {
            return [];
        }
        $candidates = [];

        $mountTemplate = trim((string)($config[AdminConfigService::KEY_MOUNT_NAME_TEMPLATE] ?? ''));
        if ($mountTemplate !== '') {
            $this->appendExpandedCandidate($candidates, $mountTemplate, $ncUid, $storageLabel, true);
        }

        if ($includeFilesystemTarget) {
            $targetTemplate = trim((string)($config[AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE] ?? ''));
            if ($targetTemplate !== '') {
                $this->appendExpandedCandidate($candidates, $targetTemplate, $ncUid, $storageLabel, false);
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param string[] $candidates
     */
    private function appendExpandedCandidate(array &$candidates, string $template, string $ncUid, string $storageLabel, bool $forceMountPoint): void {
        try {
            $expanded = $this->pathTemplateService->expandPathTemplate($template, $ncUid, $storageLabel);
        } catch (\Throwable) {
            return;
        }

        $normalized = $this->normalizePath($expanded);
        if ($normalized === null || $normalized === '' || $normalized === '/') {
            return;
        }

        $candidates[] = $forceMountPoint ? '/' . ltrim($normalized, '/') : $normalized;
    }

    private function findSyncState(string $ncUid): ?SyncState {
        try {
            return $this->syncStateService->findByUid($ncUid);
        } catch (\Throwable) {
            return null;
        }
    }

    private function storageLabelForUid(string $ncUid, ?SyncState $state, array $config): string {
        $label = trim((string)($state?->getStorageLabel() ?? ''));
        if ($label !== '') {
            return $this->pathTemplateService->sanitizeStorageLabel($label);
        }

        $template = (string)($config[AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE] ?? '{uid}');
        return $this->pathTemplateService->expandStorageLabelTemplate($template, $ncUid);
    }

    /**
     * @return string[]
     */
    private function pathVariants(string $path, string $ncUid): array {
        $normalized = $this->normalizePath($path);
        if ($normalized === null || $normalized === '') {
            return [];
        }

        $variants = [$normalized];
        $userFilesPrefix = '/' . trim($ncUid, '/') . '/files/';
        if (str_starts_with($normalized, $userFilesPrefix)) {
            $variants[] = '/' . substr($normalized, strlen($userFilesPrefix));
        }

        if (str_starts_with($normalized, '/files/')) {
            $variants[] = '/' . substr($normalized, strlen('/files/'));
        }

        if (!str_starts_with($normalized, '/')) {
            $variants[] = '/' . $normalized;
        }

        return array_values(array_unique($variants));
    }

    /**
     * @return string[]
     */
    private function candidateVariants(string $candidate): array {
        $normalized = $this->normalizePath($candidate);
        if ($normalized === null || $normalized === '') {
            return [];
        }

        $variants = [$normalized];
        if (str_starts_with($normalized, '/')) {
            $variants[] = ltrim($normalized, '/');
        } else {
            $variants[] = '/' . $normalized;
        }

        return array_values(array_unique($variants));
    }

    private function pathIsWithin(string $path, string $base): bool {
        $path = rtrim($path, '/');
        $base = rtrim($base, '/');

        if ($path === $base) {
            return true;
        }

        return $base !== '' && str_starts_with($path, $base . '/');
    }

    private function normalizePath(string $path): ?string {
        if (str_contains($path, "\0")) {
            return null;
        }

        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return null;
        }

        $prefix = '';
        $remainder = $path;
        if (preg_match('/\A[A-Za-z]:\//', $path) === 1) {
            $prefix = substr($path, 0, 3);
            $remainder = substr($path, 3);
        } elseif (str_starts_with($path, '/')) {
            $prefix = '/';
            $remainder = ltrim($path, '/');
        }

        $segments = [];
        foreach (explode('/', $remainder) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return null;
            }
            $segments[] = $segment;
        }

        $normalized = match (true) {
            $prefix === '/' => '/' . implode('/', $segments),
            $prefix !== '' => $prefix . implode('/', $segments),
            default => implode('/', $segments),
        };

        return $normalized === '' && $prefix === '/' ? '/' : $normalized;
    }
}
