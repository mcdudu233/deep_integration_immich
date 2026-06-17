<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Command;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCredentialsCommand extends Command {
	public function __construct(
		private SyncStateService $syncStateService,
		private ICrypto $crypto,
		private LoggerInterface $logger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('deep_integration_immich:migrate-credentials')
			->setDescription('Re-encrypt any sync_state credentials still stored as plaintext (password, API key).')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report rows that would be re-encrypted without writing.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		$offset = 0;
		$limit = 100;
		$inspected = 0;
		$rewrittenPasswords = 0;
		$rewrittenApiKeys = 0;
		$rewrittenRows = 0;

		do {
			$states = $this->syncStateService->listStates($limit, $offset);
			$offset += $limit;

			foreach ($states as $state) {
				$inspected++;
				$fields = [];

				if ($this->looksLikePlaintext((string)$state->getImmichPassword())) {
					$encrypted = $this->tryEncrypt((string)$state->getImmichPassword(), $state, 'password');
					if ($encrypted !== null) {
						$fields['immichPassword'] = $encrypted;
						$rewrittenPasswords++;
					}
				}

				if ($this->looksLikePlaintext((string)$state->getImmichApiKey())) {
					$encrypted = $this->tryEncrypt((string)$state->getImmichApiKey(), $state, 'api_key');
					if ($encrypted !== null) {
						$fields['immichApiKey'] = $encrypted;
						$rewrittenApiKeys++;
					}
				}

				if ($fields === []) {
					continue;
				}

				$rewrittenRows++;
				$output->writeln(sprintf(
					'%s nc_uid=%s password=%s api_key=%s',
					$dryRun ? '[dry-run]' : '[migrated]',
					$state->getNcUid(),
					isset($fields['immichPassword']) ? 'encrypted' : '-',
					isset($fields['immichApiKey']) ? 'encrypted' : '-',
				));

				if ($dryRun) {
					continue;
				}

				try {
					$this->syncStateService->updateMapping($state->getNcUid(), $fields);
				} catch (\Throwable $e) {
					$output->writeln('<error>Failed to re-encrypt credentials for "' . $state->getNcUid() . '": ' . $e->getMessage() . '</error>');
					$this->logger->warning('Credential migration failed for "' . $state->getNcUid() . '": ' . $e->getMessage(), [
						'app' => Application::APP_ID,
					]);
					return Command::FAILURE;
				}
			}
		} while (count($states) === $limit);

		$output->writeln(sprintf(
			'Inspected %d sync_state rows; %s %d row(s) (passwords=%d, api_keys=%d).',
			$inspected,
			$dryRun ? 'would migrate' : 'migrated',
			$rewrittenRows,
			$rewrittenPasswords,
			$rewrittenApiKeys,
		));

		return Command::SUCCESS;
	}

	/**
	 * Treat a value as plaintext when ICrypto cannot decrypt it. The Nextcloud ICrypto envelope
	 * uses a known "ciphertext|iv|...|version" format, so a value that fails to decrypt almost
	 * certainly never went through encrypt() in the first place.
	 */
	private function looksLikePlaintext(string $value): bool {
		if ($value === '') {
			return false;
		}

		try {
			$this->crypto->decrypt($value);
			return false;
		} catch (\Throwable) {
			return true;
		}
	}

	private function tryEncrypt(string $value, SyncState $state, string $label): ?string {
		try {
			return $this->crypto->encrypt($value);
		} catch (\Throwable $e) {
			$this->logger->warning('Credential migration: encrypt failed for "' . $state->getNcUid() . '" ' . $label . ': ' . $e->getMessage(), [
				'app' => Application::APP_ID,
			]);
			return null;
		}
	}
}
