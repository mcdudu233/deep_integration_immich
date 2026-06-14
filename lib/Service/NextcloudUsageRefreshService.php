<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

class NextcloudUsageRefreshService {
    public function __construct(
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
    ) {
    }

    public function refresh(string $ncUid): array {
        try {
            $folder = $this->rootFolder->getUserFolder($ncUid);
            $listingCount = 0;
            foreach ($folder->getDirectoryListing() as $node) {
                $node->getSize();
                $listingCount++;
            }

            $size = $folder->getSize();

            return [
                'status' => 'ok',
                'warningCode' => null,
                'remediation' => null,
                'error' => null,
                'size' => is_numeric($size) ? (int)$size : null,
                'listingCount' => $listingCount,
            ];
        } catch (\Throwable $e) {
            $message = $this->redactError($e->getMessage());
            $this->logger->warning('Best-effort Nextcloud usage refresh failed for user "' . $ncUid . '": ' . $message, [
                'ncUid' => $ncUid,
            ]);

            return [
                'status' => 'warning',
                'warningCode' => 'usage_refresh_failed',
                'remediation' => 'Run or schedule the appropriate files_external:scan command outside the app if external storage usage appears stale.',
                'error' => $message,
                'size' => null,
                'listingCount' => null,
            ];
        }
    }

    private function redactError(string $message): string {
        return preg_replace('/(api[_-]?key|token|password|secret|authorization)(\s*[=:]\s*)([^\s,&]+)/i', '$1$2[redacted]', $message) ?? $message;
    }
}
