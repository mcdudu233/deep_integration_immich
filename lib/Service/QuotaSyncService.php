<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use OCA\IntegrationImmich\AppInfo\Application;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class QuotaSyncService {
    private mixed $usageProvider;
    private ?string $lastError = null;
    private bool $lastQuotaUnlimited = false;

    public function __construct(
        private IUserManager $userManager,
        private AdminConfigService $adminConfigService,
        private LoggerInterface $logger,
        private ?IRootFolder $rootFolder = null,
        ?callable $usageProvider = null,
    ) {
        $this->usageProvider = $usageProvider;
    }

    public function setNextcloudUsageProvider(callable $usageProvider): void {
        $this->usageProvider = $usageProvider;
    }

	public function computeQuota(string $ncUid, ?int $immichUsage): ?int {
		$this->lastError = null;
		$this->lastQuotaUnlimited = false;

		try {
			if ($immichUsage === null) {
				throw new \RuntimeException('Immich quota usage is unavailable.');
			}

			$user = $this->userManager->get($ncUid);
            if ($user === null) {
                throw new \RuntimeException('Nextcloud user was not found.');
            }

            $nextcloudQuota = $this->parseQuota($user->getQuota());
            if ($nextcloudQuota === null) {
                $this->lastQuotaUnlimited = true;
                return null;
            }

            $nextcloudUsed = $this->getNextcloudTotalUsage($ncUid);
            if ($nextcloudUsed === null || $nextcloudUsed < 0) {
                throw new \RuntimeException('Nextcloud total usage is unavailable.');
            }

			$immichUsage = max(0, $immichUsage);
			$nonImmichUsage = max(0, $nextcloudUsed - $immichUsage);
			$reserveBytes = $this->getReserveBytes();
			$computedQuota = max($immichUsage, $nextcloudQuota - $nonImmichUsage - $reserveBytes);

            if ($computedQuota <= 0) {
                $computedQuota = 1;
            }

            return $computedQuota;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->logger->warning('Immich quota computation failed for Nextcloud user "' . $ncUid . '": ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'ncUid' => $ncUid,
            ]);
            return null;
        }
    }

    /**
	 * @return array{ncQuota: int|null, ncUsed: int|null, ncRemaining: int|null, immichUsage: int|null, immichAvailable: int|null, nonImmichUsed: int|null, reserve: int, computedImmichQuota: int|null, unlimited: bool, error: string|null}
	 */
	public function computeQuotaDetails(string $ncUid, ?int $immichUsage): array {
		$computedQuota = $this->computeQuota($ncUid, $immichUsage);
		$normalizedImmichUsage = $immichUsage === null ? null : max(0, $immichUsage);
		$snapshot = $this->computeNextcloudQuotaSnapshot($ncUid);
		$ncQuota = $snapshot['ncQuota'];
		$ncUsed = $snapshot['ncUsed'];

		$ncRemaining = $ncQuota === null || $ncUsed === null || $normalizedImmichUsage === null ? null : max(0, $ncQuota - max($ncUsed, $normalizedImmichUsage));
		$immichAvailable = $computedQuota === null || $normalizedImmichUsage === null ? null : max(0, $computedQuota - $normalizedImmichUsage);

        return [
            'ncQuota' => $ncQuota,
            'ncUsed' => $ncUsed,
            'ncRemaining' => $ncRemaining,
            'immichUsage' => $normalizedImmichUsage,
            'immichAvailable' => $immichAvailable,
			'nonImmichUsed' => $ncUsed === null || $normalizedImmichUsage === null ? null : max(0, $ncUsed - $normalizedImmichUsage),
            'reserve' => $this->getReserveBytes(),
            'computedImmichQuota' => $computedQuota,
            'unlimited' => $this->wasLastQuotaUnlimited() || ($ncQuota === null && $this->getLastError() === null),
            'error' => $this->getLastError(),
		];
	}

	/**
	 * @return array{ncQuota: int|null, ncUsed: int|null, ncRemaining: int|null, reserve: int, unlimited: bool, error: string|null}
	 */
	public function computeNextcloudQuotaSnapshot(string $ncUid): array {
		$error = null;
		$ncQuota = null;
		$ncUsed = null;

		try {
			$ncQuota = $this->nextcloudQuotaBytes($ncUid);
		} catch (\Throwable $e) {
			$error = $e->getMessage();
		}

		try {
			$ncUsed = $this->nextcloudUsedBytes($ncUid);
		} catch (\Throwable $e) {
			$error ??= $e->getMessage();
		}

		return [
			'ncQuota' => $ncQuota,
			'ncUsed' => $ncUsed,
			'ncRemaining' => $ncQuota === null || $ncUsed === null ? null : max(0, $ncQuota - $ncUsed),
			'reserve' => $this->getReserveBytes(),
			'unlimited' => $ncQuota === null && $error === null,
			'error' => $error,
		];
	}

    public function getLastError(): ?string {
        return $this->lastError;
    }

    public function wasLastQuotaUnlimited(): bool {
        return $this->lastQuotaUnlimited && $this->lastError === null;
    }

    private function parseQuota(mixed $quota): ?int {
        if (is_int($quota)) {
            if ($quota < 0) {
                return null;
            }
            if ($quota === 0) {
                throw new \RuntimeException('Nextcloud quota is zero or invalid.');
            }
            return $quota;
        }

        if (!is_string($quota)) {
            throw new \RuntimeException('Nextcloud quota is unavailable.');
        }

        $quota = trim($quota);
        if ($quota === '') {
            throw new \RuntimeException('Nextcloud quota is unavailable.');
        }

        if (in_array(strtolower($quota), ['none', 'unlimited', '-1'], true)) {
            return null;
        }

        if (strtolower($quota) === 'default') {
            return $this->systemDefaultQuotaBytes();
        }

        if (preg_match('/^\d+$/', $quota) === 1) {
            $bytes = (int)$quota;
            if ($bytes <= 0) {
                throw new \RuntimeException('Nextcloud quota is zero or invalid.');
            }
            return $bytes;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmgtp])i?b?$/i', $quota, $matches) === 1) {
            $bytes = (int)round((float)$matches[1] * $this->quotaUnitMultiplier(strtolower($matches[2])));
            if ($bytes <= 0) {
                throw new \RuntimeException('Nextcloud quota is zero or invalid.');
            }
            return $bytes;
        }

        throw new \RuntimeException('Nextcloud quota must be a finite byte value or unlimited.');
    }

    private function systemDefaultQuotaBytes(): ?int {
        $config = $this->adminConfigService->getAdminConfig();
        $defaultQuota = $config['default_quota'] ?? $config['defaultQuota'] ?? null;
        if ($defaultQuota === null || $defaultQuota === '' || strtolower((string)$defaultQuota) === 'none') {
            return null;
        }

        return $this->parseQuotaValue($defaultQuota);
    }

    private function nextcloudQuotaBytes(string $ncUid): ?int {
        $user = $this->userManager->get($ncUid);
        if ($user === null) {
            throw new \RuntimeException('Nextcloud user was not found.');
        }

        return $this->parseQuota($user->getQuota());
    }

    private function parseQuotaValue(mixed $quota): ?int {
        if (is_int($quota)) {
            if ($quota < 0) {
                return null;
            }
            if ($quota === 0) {
                throw new \RuntimeException('Nextcloud quota is zero or invalid.');
            }
            return $quota;
        }

        if (!is_string($quota)) {
            throw new \RuntimeException('Nextcloud quota is unavailable.');
        }

        $quota = trim($quota);
        if ($quota === '') {
            throw new \RuntimeException('Nextcloud quota is unavailable.');
        }

        if (in_array(strtolower($quota), ['none', 'unlimited', '-1'], true)) {
            return null;
        }

        if (preg_match('/^\d+$/', $quota) === 1) {
            $bytes = (int)$quota;
            if ($bytes <= 0) {
                throw new \RuntimeException('Nextcloud quota is zero or invalid.');
            }
            return $bytes;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmgtp])i?b?$/i', $quota, $matches) === 1) {
            $bytes = (int)round((float)$matches[1] * $this->quotaUnitMultiplier(strtolower($matches[2])));
            if ($bytes <= 0) {
                throw new \RuntimeException('Nextcloud quota is zero or invalid.');
            }
            return $bytes;
        }

        throw new \RuntimeException('Nextcloud quota must be a finite byte value or unlimited.');
    }

    private function nextcloudUsedBytes(string $ncUid): ?int {
        return $this->getNextcloudTotalUsage($ncUid);
    }

    private function quotaUnitMultiplier(string $unit): int {
        return match ($unit) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            't' => 1024 ** 4,
            'p' => 1024 ** 5,
            default => throw new \RuntimeException('Unsupported Nextcloud quota unit.'),
        };
    }

    private function getNextcloudTotalUsage(string $ncUid): ?int {
        if (is_callable($this->usageProvider)) {
            $usage = ($this->usageProvider)($ncUid);
            return is_int($usage) ? $usage : null;
        }

        if ($this->rootFolder !== null) {
            $usage = $this->rootFolder->getUserFolder($ncUid)->getSize();
            return is_numeric($usage) ? (int)$usage : null;
        }

        return null;
    }

    private function getReserveBytes(): int {
        $config = $this->adminConfigService->getAdminConfig();
        $reserve = $config[AdminConfigService::KEY_QUOTA_RESERVE_BYTES] ?? 0;

        if (is_int($reserve)) {
            return max(0, $reserve);
        }

        if (is_string($reserve) && preg_match('/^\d+$/', trim($reserve)) === 1) {
            return (int)trim($reserve);
        }

        return 0;
    }
}
