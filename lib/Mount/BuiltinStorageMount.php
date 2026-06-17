<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Mount;

/**
 * Value object that mirrors the shape ExternalStorageProvisioner expects from the
 * files_external storage objects so verifyMount can work without that app installed.
 */
class BuiltinStorageMount {
	public function __construct(
		private int $id,
		private string $mountPoint,
		private string $targetPath,
		private string $applicableUid,
		private bool $readOnly,
	) {
	}

	public function getId(): int {
		return $this->id;
	}

	public function getMountPoint(): string {
		return $this->mountPoint;
	}

	public function getBackendOptions(): array {
		return ['datadir' => $this->targetPath];
	}

	public function getMountOptions(): array {
		return ['readonly' => $this->readOnly];
	}

	public function getApplicableUsers(): array {
		return $this->applicableUid === '' ? [] : [$this->applicableUid];
	}

	public function getApplicableGroups(): array {
		return [];
	}
}
