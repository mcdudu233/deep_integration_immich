<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use InvalidArgumentException;

class PathTemplateService {
	/** @var array<string, string> */
	private array $storageLabelOwners = [];

	/**
	 * @param array<string, string> $storageLabelOwners Temporary label => uid map until SyncStateService persists mappings.
	 */
	public function __construct(array $storageLabelOwners = []) {
		foreach ($storageLabelOwners as $label => $uid) {
			$this->storageLabelOwners[$this->sanitizeStorageLabel((string)$label)] = (string)$uid;
		}
	}

	public function sanitizeStorageLabel(string $input): string {
		if (str_contains($input, "\0")) {
			throw new InvalidArgumentException('Storage label must not contain NUL bytes.');
		}

		$label = trim($input, ' .');
		if ($label === '' || $label === '.' || $label === '..') {
			throw new InvalidArgumentException('Storage label must not be empty, ".", or "..".');
		}

		if (preg_match('/\A[A-Za-z0-9._-]+\z/', $label) !== 1) {
			throw new InvalidArgumentException('Storage label may only contain ASCII letters, digits, dots, underscores, and hyphens.');
		}

		return $label;
	}

	public function expandStorageLabelTemplate(string $template, string $uid): string {
		$this->assertNoNulByte($template, 'Storage label template');
		$baseLabel = $this->sanitizeStorageLabel($uid);
		$expanded = strtr(trim($template), [
			'{uid}' => $baseLabel,
			'{storageLabel}' => $baseLabel,
		]);

		return $this->sanitizeStorageLabel($expanded);
	}

	public function expandPathTemplate(string $template, string $uid, string $storageLabel): string {
		$this->assertNoNulByte($template, 'Path template');
		$this->assertNoNulByte($uid, 'User ID');

		if (str_contains($template, '..')) {
			throw new InvalidArgumentException('Path template must not contain "..".');
		}

		$expanded = strtr($template, [
			'{uid}' => $uid,
			'{storageLabel}' => $this->sanitizeStorageLabel($storageLabel),
		]);

		if ($expanded === '') {
			throw new InvalidArgumentException('Expanded path must not be empty.');
		}

		$this->assertNoNulByte($expanded, 'Expanded path');
		return $this->normalizePath($expanded, false);
	}

	public function validatePathUnderBase(string $expandedPath, string $basePath): void {
		$path = $this->normalizePath($expandedPath, true);
		$base = $this->normalizePath($basePath, true);

		if (!$this->isPathUnderBase($path, $base)) {
			throw new InvalidArgumentException('Expanded path must stay under the configured base path.');
		}
	}

	public function isStorageLabelCollision(string $label, ?string $excludeUid = null): bool {
		$label = $this->sanitizeStorageLabel($label);
		if (!array_key_exists($label, $this->storageLabelOwners)) {
			return false;
		}

		return $excludeUid === null || $this->storageLabelOwners[$label] !== $excludeUid;
	}

	private function assertNoNulByte(string $value, string $name): void {
		if (str_contains($value, "\0")) {
			throw new InvalidArgumentException($name . ' must not contain NUL bytes.');
		}
	}

	private function normalizePath(string $path, bool $requireAbsolute): string {
		$this->assertNoNulByte($path, 'Path');

		$path = str_replace('\\', '/', $path);
		if ($path === '') {
			throw new InvalidArgumentException('Path must not be empty.');
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

		if ($requireAbsolute && $prefix === '') {
			throw new InvalidArgumentException('Path must be absolute.');
		}

		$segments = [];
		foreach (explode('/', $remainder) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			if ($segment === '..') {
				throw new InvalidArgumentException('Path must not contain traversal segments.');
			}

			$segments[] = $segment;
		}

		$normalized = match (true) {
			$prefix === '/' => '/' . implode('/', $segments),
			$prefix !== '' => $prefix . implode('/', $segments),
			default => implode('/', $segments),
		};

		if ($normalized === '') {
			throw new InvalidArgumentException('Path must not normalize to empty.');
		}

		return $normalized;
	}

	private function isPathUnderBase(string $path, string $base): bool {
		if ($path === $base) {
			return true;
		}

		if ($base === '/' || preg_match('/\A[A-Za-z]:\/\z/', $base) === 1) {
			return str_starts_with($path, $base);
		}

		return str_starts_with($path, $base . '/');
	}
}
