<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

class NextcloudExternalStorageConfigService {
	private const USER_STORAGE_SERVICE = 'OCA\\Files_External\\Service\\UserStoragesService';
	private const GLOBAL_STORAGE_SERVICE = 'OCA\\Files_External\\Service\\GlobalStoragesService';

	/** @return list<object> */
	public function getUserStorages(string $ncUid): array {
		return array_values(array_filter([
			...$this->storagesFromService(self::USER_STORAGE_SERVICE, [['getStorages', [$ncUid]], ['getAllStorages', [$ncUid]], ['getUserStorages', [$ncUid]]]),
			...$this->storagesFromService(self::GLOBAL_STORAGE_SERVICE, [['getStorages', []], ['getAllStorages', []]]),
		], static fn(mixed $storage): bool => is_object($storage)));
	}

	/**
	 * @param list<array{0: string, 1: list<mixed>}> $calls
	 * @return list<object>
	 */
	private function storagesFromService(string $serviceClass, array $calls): array {
		if (!class_exists($serviceClass) || !class_exists('OC')) {
			return [];
		}

		try {
			$service = \OC::$server->query($serviceClass);
		} catch (\Throwable) {
			return [];
		}

		$storages = [];
		foreach ($calls as [$method, $arguments]) {
			if (!method_exists($service, $method)) {
				continue;
			}

			try {
				$result = $service->{$method}(...$arguments);
			} catch (\Throwable) {
				continue;
			}

			if (is_iterable($result)) {
				foreach ($result as $storage) {
					if (is_object($storage)) {
						$storages[] = $storage;
					}
				}
			}
		}

		return $storages;
	}
}
