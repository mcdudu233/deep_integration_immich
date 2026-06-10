<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Db\SyncState;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class BrowsingAuthService {
    public const MODE_PERSONAL = 'personal';
    public const MODE_ADMIN_PROXY = 'admin_proxy';
    public const MODE_UNAVAILABLE = 'unavailable';

    private const CONFIG_SERVER_URL = 'server_url';
    private const CONFIG_API_KEY = 'api_key';

    public function __construct(
        private IConfig $config,
        private ICrypto $crypto,
        private SyncStateService $syncStateService,
        private AdminConfigService $adminConfigService,
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{mode: string, url: string, apiKey: string|null, immichUserId: string|null}
     */
    public function resolveCredentials(string $ncUid): array {
        if ($this->browsingMode() === AdminConfigService::BROWSING_MODE_PERSONAL) {
            return $this->resolvePersonalCredentials($ncUid) ?? $this->unavailableCredentials();
        }

        if (!$this->adminConfigService->isConfigured()) {
            return $this->unavailableCredentials();
        }

        $syncState = $this->syncStateService->findByUid($ncUid);
        if (!$this->mappingAllowsBrowsing($syncState)) {
            return $this->unavailableCredentials();
        }

        return [
            'mode' => self::MODE_ADMIN_PROXY,
            'url' => $this->adminConfigService->getImmichBaseUrl(),
            'apiKey' => $this->adminConfigService->getAdminApiKey(),
            'immichUserId' => $syncState->getImmichUserId(),
        ];
    }

    private function browsingMode(): string {
        $config = $this->adminConfigService->getAdminConfig();
        $mode = (string)($config[AdminConfigService::KEY_IMMICH_BROWSING_MODE] ?? AdminConfigService::BROWSING_MODE_ADMIN_MANAGED);

        return $mode === AdminConfigService::BROWSING_MODE_PERSONAL
            ? AdminConfigService::BROWSING_MODE_PERSONAL
            : AdminConfigService::BROWSING_MODE_ADMIN_MANAGED;
    }

    public function assertAssetOwnership(string $immichUserId, string $assetId): bool {
        if (trim($immichUserId) === '' || trim($assetId) === '') {
            return false;
        }

        if (!$this->adminConfigService->isConfigured()) {
            return false;
        }

        try {
            $asset = $this->adminAssetService()->getAsset($assetId);
            return $this->assetBelongsToUser($asset, $immichUserId);
        } catch (\Throwable $e) {
            $this->logger->warning('Immich asset ownership check failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'assetId' => $assetId,
            ]);
            return false;
        }
    }

    public function assetBelongsToUser(array $asset, string $immichUserId): bool {
        $ownerId = $this->extractOwnerId($asset);
        return $ownerId !== null && hash_equals($immichUserId, $ownerId);
    }

    /**
     * @return array{mode: string, url: string, apiKey: string|null, immichUserId: string|null}|null
     */
    private function resolvePersonalCredentials(string $ncUid): ?array {
        $url = rtrim(trim((string)$this->config->getUserValue($ncUid, Application::APP_ID, self::CONFIG_SERVER_URL, '')), '/');
        $storedApiKey = (string)$this->config->getUserValue($ncUid, Application::APP_ID, self::CONFIG_API_KEY, '');
        if ($url === '' || $storedApiKey === '') {
            return null;
        }

        try {
            $apiKey = $this->crypto->decrypt($storedApiKey);
        } catch (\Throwable) {
            $this->logger->warning('ICrypto decrypt failed for personal api_key; assuming legacy plaintext value. Re-save the key in settings to encrypt it properly.', [
                'app' => Application::APP_ID,
            ]);
            $apiKey = $storedApiKey;
        }

        if ($apiKey === '') {
            return null;
        }

        return [
            'mode' => self::MODE_PERSONAL,
            'url' => $url,
            'apiKey' => $apiKey,
            'immichUserId' => null,
        ];
    }

    /**
     * @return array{mode: string, url: string, apiKey: null, immichUserId: null}
     */
    private function unavailableCredentials(): array {
        return [
            'mode' => self::MODE_UNAVAILABLE,
            'url' => '',
            'apiKey' => null,
            'immichUserId' => null,
        ];
    }

    private function mappingAllowsBrowsing(?SyncState $syncState): bool {
        if ($syncState === null || trim((string)$syncState->getImmichUserId()) === '') {
            return false;
        }

        return !in_array($syncState->getScopeStatus(), [
            SyncStateService::STATUS_DISABLED,
            SyncStateService::STATUS_DELETED,
            SyncStateService::STATUS_OUT_OF_SCOPE,
        ], true);
    }

    private function adminAssetService(): ImmichAssetService {
        return new ImmichAssetService(
            $this->clientService,
            $this->logger,
            fn (): array => [
                'url' => $this->adminConfigService->getImmichBaseUrl(),
                'apiKey' => $this->adminConfigService->getAdminApiKey(),
            ],
        );
    }

    private function extractOwnerId(array $asset): ?string {
        foreach (['ownerId', 'ownerID', 'userId'] as $key) {
            if (isset($asset[$key]) && is_string($asset[$key]) && $asset[$key] !== '') {
                return $asset[$key];
            }
        }

        if (isset($asset['owner']) && is_string($asset['owner']) && $asset['owner'] !== '') {
            return $asset['owner'];
        }

        if (isset($asset['owner']) && is_array($asset['owner'])) {
            $owner = $asset['owner'];
            if (isset($owner['id']) && is_string($owner['id']) && $owner['id'] !== '') {
                return $owner['id'];
            }
        }

        return null;
    }
}
