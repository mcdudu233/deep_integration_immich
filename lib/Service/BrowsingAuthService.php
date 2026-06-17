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

    public const HANDOFF_READY = 'ready';
    public const HANDOFF_PERSONAL_MODE = 'personal_mode';
    public const HANDOFF_ADMIN_CONFIG_MISSING = 'admin_config_missing';
    public const HANDOFF_UNMAPPED = 'unmapped';
    public const HANDOFF_CREDENTIALS_MISSING = 'credentials_missing';
    public const HANDOFF_LOGIN_FAILED = 'login_failed';

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
     * @return array{mode: string, url: string, apiKey: string|null, immichUserId: string|null, usesUserApiKey?: bool}
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

        $apiKey = $this->mappedUserApiKey($syncState);
        if ($apiKey === null) {
            return $this->unavailableCredentials();
        }

        return [
            'mode' => self::MODE_ADMIN_PROXY,
            'url' => $this->adminConfigService->getImmichBaseUrl(),
            'apiKey' => $apiKey,
            'immichUserId' => $syncState->getImmichUserId(),
            'usesUserApiKey' => true,
        ];
    }

    /**
     * @return array{status: string, url: string, username: string|null, password: string|null, immichUserId: string|null}
     */
    public function resolveAutoLoginHandoff(string $ncUid): array {
        if ($this->browsingMode() === AdminConfigService::BROWSING_MODE_PERSONAL) {
            $credentials = $this->resolvePersonalCredentials($ncUid);
            if ($credentials === null) {
                return $this->handoffUnavailable(self::HANDOFF_PERSONAL_MODE);
            }

            return $this->handoffReady($credentials['url'], null, null, null);
        }

        if (!$this->adminConfigService->isConfigured()) {
            return $this->handoffUnavailable(self::HANDOFF_ADMIN_CONFIG_MISSING);
        }

        $syncState = $this->syncStateService->findByUid($ncUid);
        if (!$this->mappingAllowsBrowsing($syncState)) {
            return $this->handoffUnavailable(self::HANDOFF_UNMAPPED);
        }

        return $this->handoffReady($this->adminConfigService->getImmichBaseUrl(), null, null, $syncState->getImmichUserId());
    }

    public function resolveLegacyPasswordLoginHandoff(string $ncUid): array {
        if ($this->browsingMode() === AdminConfigService::BROWSING_MODE_PERSONAL) {
            return $this->handoffUnavailable(self::HANDOFF_PERSONAL_MODE);
        }

        if (!$this->adminConfigService->isConfigured()) {
            return $this->handoffUnavailable(self::HANDOFF_ADMIN_CONFIG_MISSING);
        }

        $syncState = $this->syncStateService->findByUid($ncUid);
        if (!$this->mappingAllowsBrowsing($syncState)) {
            return $this->handoffUnavailable(self::HANDOFF_UNMAPPED);
        }

        $username = trim((string)$syncState->getImmichUsername());
        $password = $this->decryptStoredCredential((string)$syncState->getImmichPassword());
        if ($username === '' || $password === '') {
            return $this->handoffUnavailable(self::HANDOFF_CREDENTIALS_MISSING);
        }

        return [
            'status' => self::HANDOFF_READY,
            'url' => $this->adminConfigService->getImmichBaseUrl(),
            'username' => $username,
            'password' => $password,
            'immichUserId' => $syncState->getImmichUserId(),
        ];
    }

    /**
     * @param array{status: string, url: string, username: string|null, password: string|null, immichUserId: string|null} $handoff
     * @return array{success: bool, redirectUrl: string, setCookie: string|null}
     */
    public function createImmichLoginSession(array $handoff): array {
        if (($handoff['status'] ?? '') !== self::HANDOFF_READY) {
            throw new \InvalidArgumentException('Immich auto-login handoff is not ready.');
        }

        $baseUrl = rtrim((string)$handoff['url'], '/');
        $client = $this->clientService->newClient();

        try {
            $response = $client->post($baseUrl . '/api/auth/login', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'email' => (string)$handoff['username'],
                    'password' => (string)$handoff['password'],
                ], JSON_THROW_ON_ERROR),
                'http_errors' => false,
                'timeout' => 30,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Immich auto-login request failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'immichUserId' => (string)($handoff['immichUserId'] ?? ''),
            ]);

            return [
                'success' => false,
                'redirectUrl' => $baseUrl,
                'setCookie' => null,
            ];
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->warning('Immich auto-login returned HTTP ' . $statusCode, [
                'app' => Application::APP_ID,
                'immichUserId' => (string)($handoff['immichUserId'] ?? ''),
            ]);

            return [
                'success' => false,
                'redirectUrl' => $baseUrl,
                'setCookie' => null,
            ];
        }

        $setCookie = $response->getHeader('Set-Cookie') ?: null;
        if (!is_string($setCookie) || trim($setCookie) === '') {
            $this->logger->warning('Immich auto-login succeeded without a browser session cookie; refusing to expose token-based credentials.', [
                'app' => Application::APP_ID,
                'immichUserId' => (string)($handoff['immichUserId'] ?? ''),
            ]);

            return [
                'success' => false,
                'redirectUrl' => $baseUrl,
                'setCookie' => null,
            ];
        }

        return [
            'success' => true,
            'redirectUrl' => $baseUrl,
            'setCookie' => $setCookie,
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
     * @return array{mode: string, url: string, apiKey: string|null, immichUserId: string|null, usesUserApiKey?: bool}|null
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
     * @return array{mode: string, url: string, apiKey: null, immichUserId: null, usesUserApiKey?: bool}
     */
    private function unavailableCredentials(): array {
        return [
            'mode' => self::MODE_UNAVAILABLE,
            'url' => '',
            'apiKey' => null,
            'immichUserId' => null,
        ];
    }

    /**
     * @return array{status: string, url: string, username: null, password: null, immichUserId: null}
     */
    private function handoffUnavailable(string $status): array {
        return [
            'status' => $status,
            'url' => '',
            'username' => null,
            'password' => null,
            'immichUserId' => null,
        ];
    }

    /**
     * @return array{status: string, url: string, username: string|null, password: string|null, immichUserId: string|null}
     */
    private function handoffReady(string $url, ?string $username, ?string $password, ?string $immichUserId): array {
        return [
            'status' => self::HANDOFF_READY,
            'url' => $url,
            'username' => $username,
            'password' => $password,
            'immichUserId' => $immichUserId,
        ];
    }

    private function mappingAllowsBrowsing(?SyncState $syncState): bool {
        if ($syncState === null || trim((string)$syncState->getImmichUserId()) === '') {
            return false;
        }

        return !in_array($syncState->getScopeStatus(), [
            SyncStateService::STATUS_DISABLED,
            SyncStateService::STATUS_DELETED,
        ], true);
    }

    private function mappedUserApiKey(SyncState $syncState): ?string {
        $apiKey = $syncState->getImmichApiKey();
        if (!is_string($apiKey) || $apiKey === '') {
            return null;
        }

        try {
            return $this->crypto->decrypt($apiKey);
        } catch (\Throwable) {
            $this->logger->warning('ICrypto decrypt failed for provisioned Immich api key; assuming legacy plaintext value. Reconcile this user to re-save the key encrypted.', [
                'app' => Application::APP_ID,
                'immichUserId' => (string)$syncState->getImmichUserId(),
            ]);
            return $apiKey;
        }
    }

    private function decryptStoredCredential(string $value): string {
        if ($value === '') {
            return '';
        }

        try {
            return $this->crypto->decrypt($value);
        } catch (\Throwable) {
            // Tolerate legacy plaintext rows; the migrate-credentials command rewrites them.
            return $value;
        }
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
