<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use OCP\Http\Client\IClientService;

class CapabilityService {
	private const REQUIRED_EVENT_CLASSES = [
        'OCP\\User\\Events\\UserCreatedEvent',
        'OCP\\User\\Events\\UserChangedEvent',
        'OCP\\User\\Events\\UserDeletedEvent',
        'OCP\\Accounts\\UserUpdatedEvent',
    ];

    private const OPTIONAL_EVENT_CLASSES = [
        'OCP\\Group\\Events\\UserAddedEvent',
        'OCP\\Group\\Events\\UserRemovedEvent',
    ];

    private const EXTERNAL_STORAGE_APIS = [
        'OCP\\Files\\External\\IExternalMountProvider' => 'interface',
        'OC\\Files\\External\\Service\\DBConfigService' => 'class',
	];

	public function __construct(
		private AdminConfigService $adminConfigService,
		private IClientService $clientService,
		private ?string $infoXmlPath = null,
		private ?array $symbolAvailability = null,
    ) {
        $this->infoXmlPath ??= dirname(__DIR__, 2) . '/appinfo/info.xml';
    }

    public function getCapabilities(): array {
        $appInfo = $this->readAppInfo();
        $adminConfig = $this->readAdminConfig();
        $immichProbe = $this->probeImmichAdminApi($adminConfig['url'], $adminConfig['apiKey']);

        return [
            'nextcloudDependencyRange' => $this->detectNextcloudDependencyRange($appInfo),
            'phpRuntime' => $this->detectPhpRuntime($appInfo),
            'immichAdminUsers' => $this->immichAdminCapability($immichProbe, 'Immich admin user provisioning API appears reachable.'),
            'immichQuota' => $this->immichAdminCapability($immichProbe, 'Immich quota update API appears reachable.'),
            'nextcloudExternalStorageAutoCreate' => $this->detectExternalStorageAutoCreate(),
            'nextcloudEvents' => $this->detectNextcloudEvents(),
            'adminSettings' => $this->detectAdminSettings($appInfo),
            'safeProxyBrowsing' => $this->detectSafeProxyBrowsing($adminConfig),
        ];
    }

    private function readAppInfo(): array {
        if ($this->infoXmlPath === null || !is_file($this->infoXmlPath)) {
            return ['error' => 'appinfo/info.xml not found'];
        }

		$contents = @file_get_contents($this->infoXmlPath);
		if ($contents === false) {
			return ['error' => 'appinfo/info.xml could not be read'];
		}

		$xml = @simplexml_load_string($contents, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false) {
            return ['error' => 'appinfo/info.xml could not be parsed'];
        }

        $nextcloud = $xml->dependencies->nextcloud ?? null;
        $php = $xml->dependencies->php ?? null;

        return [
            'nextcloudMinVersion' => $nextcloud !== null ? (string)($nextcloud['min-version'] ?? '') : '',
            'nextcloudMaxVersion' => $nextcloud !== null ? (string)($nextcloud['max-version'] ?? '') : '',
            'phpMinVersion' => $php !== null ? (string)($php['min-version'] ?? '') : '',
            'adminSettingsClass' => isset($xml->settings->admin) ? trim((string)$xml->settings->admin) : '',
        ];
    }

    private function detectNextcloudDependencyRange(array $appInfo): array {
        if (isset($appInfo['error'])) {
            return $this->unsupported('blocking', $appInfo['error'], 'Restore a readable appinfo/info.xml with dependency declarations.');
        }

        $min = $appInfo['nextcloudMinVersion'] ?? '';
        $max = $appInfo['nextcloudMaxVersion'] ?? '';
        if ($min === '' || $max === '') {
            return $this->unsupported('blocking', 'Nextcloud dependency range is incomplete.', 'Declare both min-version and max-version on dependencies/nextcloud in appinfo/info.xml.');
        }

        $capability = $this->supported('Nextcloud dependency range is declared.');
        $capability['minVersion'] = $min;
        $capability['maxVersion'] = $max;
        return $capability;
    }

    private function detectPhpRuntime(array $appInfo): array {
        if (isset($appInfo['error'])) {
            return $this->unsupported('blocking', $appInfo['error'], 'Restore a readable appinfo/info.xml with PHP dependency declarations.');
        }

        $min = $appInfo['phpMinVersion'] ?? '';
        if ($min === '') {
            return $this->unsupported('blocking', 'PHP minimum version is not declared.', 'Declare dependencies/php min-version in appinfo/info.xml.');
        }

        if (version_compare(PHP_VERSION, $min, '<')) {
            $capability = $this->unsupported('blocking', 'PHP runtime is older than the app dependency requires.', 'Upgrade PHP to ' . $min . ' or newer.');
        } else {
            $capability = $this->supported('PHP runtime satisfies the app dependency.');
        }

        $capability['minVersion'] = $min;
        $capability['runtimeVersion'] = PHP_VERSION;
        return $capability;
    }

    private function detectNextcloudEvents(): array {
        $missingRequired = [];
        foreach (self::REQUIRED_EVENT_CLASSES as $className) {
            if (!$this->symbolExists($className, 'class')) {
                $missingRequired[] = $className;
            }
        }

        $missingOptional = [];
        foreach (self::OPTIONAL_EVENT_CLASSES as $className) {
            if (!$this->symbolExists($className, 'class')) {
                $missingOptional[] = $className;
            }
        }

        if ($missingRequired !== []) {
            $capability = $this->unsupported('blocking', 'Required Nextcloud user/account event classes are missing.', 'Run this app on a supported Nextcloud release or disable provisioning event listeners until the missing classes exist.');
        } elseif ($missingOptional !== []) {
            $capability = $this->unsupported('warning', 'Optional Nextcloud group event classes are missing.', 'Group-scoped automatic provisioning will be limited; use all-user scope or scheduled reconciliation on this Nextcloud release.');
        } else {
            $capability = $this->supported('Required Nextcloud user/account/group event classes are available.');
        }

        $capability['missingRequired'] = $missingRequired;
        $capability['missingOptional'] = $missingOptional;
        return $capability;
    }

    private function detectAdminSettings(array $appInfo): array {
        if (isset($appInfo['error'])) {
            return $this->unsupported('blocking', $appInfo['error'], 'Restore a readable appinfo/info.xml and register the admin settings class.');
        }

        $settingsClass = $appInfo['adminSettingsClass'] ?? '';
        if ($settingsClass === '') {
            return $this->unsupported('blocking', 'Admin settings are not registered in appinfo/info.xml.', 'Add a settings/admin element pointing to OCA\\IntegrationImmich\\Settings\\AdminSettings.');
        }

        $capability = $this->supported('Admin settings are registered in appinfo/info.xml.');
        $capability['settingsClass'] = $settingsClass;
        return $capability;
    }

    private function detectExternalStorageAutoCreate(): array {
        $available = [];
        foreach (self::EXTERNAL_STORAGE_APIS as $symbol => $type) {
            if ($this->symbolExists($symbol, $type)) {
                $available[] = $symbol;
            }
        }

        if ($available === []) {
            return $this->unsupported('blocking', 'No supported Nextcloud external-storage provisioning API was detected.', 'Install/enable the external storage app or configure mounts manually and let this app verify them.');
        }

        $capability = $this->supported('A known Nextcloud external-storage provisioning API is available.');
        $capability['availableApis'] = $available;
        return $capability;
    }

    private function detectSafeProxyBrowsing(array $adminConfig): array {
        $hasMappingService = $this->symbolExists('OCA\\IntegrationImmich\\Service\\SyncStateService', 'class');
        if (!$adminConfig['configured']) {
            return $this->unsupported('blocking', 'Admin credentials not configured', 'Configure the Immich base URL and admin API key before enabling admin-key proxy browsing.');
        }
        if (!$hasMappingService) {
            return $this->unsupported('blocking', 'No mapping service was detected for ownership filtering.', 'Implement SyncStateService so nc_uid to immich_user_id mappings are enforced for every proxied request.');
        }

        return $this->supported('Admin config and ownership mapping support are available for filtered proxy browsing.');
    }

    private function immichAdminCapability(array $probe, string $successReason): array {
        if ($probe['supported']) {
            $capability = $this->supported($successReason);
            $capability['probe'] = $probe['probe'];
            return $capability;
        }

        return $this->unsupported($probe['severity'], $probe['reason'], $probe['remediation']);
    }

    private function probeImmichAdminApi(string $baseUrl, string $apiKey): array {
        if ($baseUrl === '' || $apiKey === '') {
            return [
                'supported' => false,
                'severity' => 'blocking',
                'reason' => 'Admin credentials not configured',
                'remediation' => 'Configure the Immich base URL and admin API key in the admin settings.',
            ];
        }

        $client = $this->clientService->newClient();
        $baseUrl = rtrim($baseUrl, '/');
        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'x-api-key' => $apiKey,
            ],
            'timeout' => 10,
            'http_errors' => false,
        ];

        try {
            $response = $client->get($baseUrl . '/api/admin/users', $options);
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return [
                    'supported' => true,
                    'probe' => 'GET /api/admin/users',
                ];
            }
        } catch (\Throwable $e) {
            return [
                'supported' => false,
                'severity' => 'blocking',
                'reason' => 'Immich admin API probe failed: ' . $e->getMessage(),
                'remediation' => 'Check the configured Immich base URL, network reachability, and SSRF allow-list settings.',
            ];
        }

        return [
            'supported' => false,
            'severity' => 'blocking',
            'reason' => 'Immich admin API probe failed against GET /api/admin/users (HTTP status ' . $status . ')',
            'remediation' => 'Verify the Immich URL/API version and configure a scoped admin API key owned by an Immich admin user.',
        ];
	}

	private function readAdminConfig(): array {
		$url = $this->adminConfigService->getImmichBaseUrl();
		$apiKey = $this->adminConfigService->getAdminApiKey();

		return [
			'url' => rtrim($url, '/'),
            'apiKey' => $apiKey,
            'configured' => $url !== '' && $apiKey !== '',
		];
	}

	private function symbolExists(string $symbol, string $type): bool {
        if ($this->symbolAvailability !== null && array_key_exists($symbol, $this->symbolAvailability)) {
            return (bool)$this->symbolAvailability[$symbol];
        }

        return $type === 'interface' ? interface_exists($symbol) : class_exists($symbol);
    }

    private function supported(string $reason): array {
        return [
            'supported' => true,
            'reason' => $reason,
            'remediation' => '',
        ];
    }

    private function unsupported(string $severity, string $reason, string $remediation): array {
        return [
            'supported' => false,
            'severity' => $severity,
            'reason' => $reason,
            'remediation' => $remediation,
        ];
    }
}
