<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use OCA\IntegrationImmich\AppInfo\Application;
use Psr\Log\LoggerInterface;

class LockService {
    /**
     * @param object|null $lockFactory ILockFactory-compatible dependency when a stable Nextcloud API is available.
     */
    public function __construct(
        private ?object $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function withLock(string $key, int $timeoutSeconds, callable $callback): mixed {
        if ($this->lockFactory !== null && method_exists($this->lockFactory, 'withLock')) {
            return $this->lockFactory->withLock($key, $timeoutSeconds, $callback);
        }

        $lockFile = $this->lockFilePath($key);
        $handle = fopen($lockFile, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open provisioning lock file.');
        }

        $acquired = false;
        $deadline = microtime(true) + $timeoutSeconds;

        try {
            do {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    $acquired = true;
                    break;
                }

                usleep(100000);
            } while (microtime(true) < $deadline);

            if (!$acquired) {
                throw new \RuntimeException('Timed out acquiring provisioning lock "' . $key . '".');
            }

            return $callback();
        } finally {
            if ($acquired) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    private function lockFilePath(string $key): string {
        if ($key === '') {
            throw new \InvalidArgumentException('Lock key must not be empty.');
        }

        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        if (!is_dir($directory) || !is_writable($directory)) {
            $this->logger->warning('System temporary directory is not writable for provisioning locks.', [
                'app' => Application::APP_ID,
            ]);
            throw new \RuntimeException('Provisioning lock directory is not writable.');
        }

        return $directory . DIRECTORY_SEPARATOR . Application::APP_ID . '_' . hash('sha256', $key) . '.lock';
    }
}
