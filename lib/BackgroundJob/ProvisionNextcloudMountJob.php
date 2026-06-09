<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\BackgroundJob;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\ExternalStorageProvisioner;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class ProvisionNextcloudMountJob extends QueuedJob {
	public function __construct(
		ITimeFactory $timeFactory,
		private ExternalStorageProvisioner $externalStorageProvisioner,
		private SyncStateService $syncStateService,
		private LoggerInterface $logger,
	) {
		parent::__construct($timeFactory);
	}

	protected function run($argument): void {
		$ncUid = $this->uidFromArgument($argument);
		if ($ncUid === '') {
			$this->logger->warning('Skipping Nextcloud mount provisioning job without a Nextcloud user id.', [
				'app' => Application::APP_ID,
			]);
			return;
		}

		$result = $this->externalStorageProvisioner->provisionMount($ncUid);
		$mountId = $result['mount_id'] ?? null;
		$status = (string)($result['status'] ?? 'unknown');

		try {
			$this->syncStateService->getOrCreateForUid($ncUid);
			$fields = [
				'lastSyncStatus' => $status === 'ok' ? SyncStateService::STATUS_ACTIVE : SyncStateService::STATUS_MOUNT_PENDING,
				'lastError' => $status === 'ok' ? null : $this->summariseError($result),
			];

			if (is_int($mountId)) {
				$fields['ncMountId'] = $mountId;
			}

			$this->syncStateService->updateMapping($ncUid, $fields);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to persist Nextcloud mount provisioning result for user "' . $ncUid . '": ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'ncUid' => $ncUid,
			]);
		}
	}

	private function uidFromArgument(mixed $argument): string {
		if (is_string($argument)) {
			return trim($argument);
		}

		if (is_array($argument)) {
			foreach (['ncUid', 'uid', 'userId'] as $key) {
				if (isset($argument[$key]) && is_string($argument[$key])) {
					return trim($argument[$key]);
				}
			}
		}

		return '';
	}

	private function summariseError(array $result): string {
		$errors = $result['errors'] ?? [];
		if (is_array($errors) && $errors !== []) {
			return implode('; ', array_map('strval', $errors));
		}

		return (string)($result['remediation'] ?? $result['status'] ?? 'Nextcloud mount provisioning did not complete.');
	}
}
