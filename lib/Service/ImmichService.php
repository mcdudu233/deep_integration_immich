<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use OCA\IntegrationImmich\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * @deprecated Use ImmichAssetService for user-facing Immich browsing and ImmichUserAdminService for admin-key provisioning.
 */
class ImmichService {
    private const CONFIG_SERVER_URL = 'server_url';
    private const CONFIG_API_KEY = 'api_key';

    /** Regex for a canonical UUID string. */
    public const UUID_PATTERN = ImmichAssetService::UUID_PATTERN;

    private ImmichAssetService $assetService;

    public function __construct(
        IClientService $clientService,
        private IConfig $config,
        private IUserSession $userSession,
        private LoggerInterface $logger,
        private ICrypto $crypto,
    ) {
        $this->assetService = new ImmichAssetService(
            $clientService,
            $logger,
            fn (): array => [
                'url' => $this->getServerUrl(),
                'apiKey' => $this->getApiKey(),
            ],
        );
    }

    public function getServerUrl(): string {
        return rtrim(
            $this->config->getUserValue($this->getUserId(), Application::APP_ID, self::CONFIG_SERVER_URL, ''),
            '/'
        );
    }

    public function getApiKey(): string {
        $stored = $this->config->getUserValue($this->getUserId(), Application::APP_ID, self::CONFIG_API_KEY, '');
        if ($stored === '') {
            return '';
        }

        try {
            return $this->crypto->decrypt($stored);
        } catch (\Exception) {
            $this->logger->warning('ICrypto decrypt failed for api_key — assuming legacy plaintext value. Re-save the key in settings to encrypt it properly.', [
                'app' => Application::APP_ID,
            ]);
            return $stored;
        }
    }

    public function setServerUrl(string $url): void {
        $url = rtrim($url, '/');
        $parsed = parse_url($url);
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Invalid server URL: must use http or https scheme');
        }
        if (empty($parsed['host'])) {
            throw new \InvalidArgumentException('Invalid server URL: missing host');
        }

        $this->config->setUserValue($this->getUserId(), Application::APP_ID, self::CONFIG_SERVER_URL, $url);
    }

    public function setApiKey(string $key): void {
        $this->config->setUserValue($this->getUserId(), Application::APP_ID, self::CONFIG_API_KEY, $this->crypto->encrypt($key));
    }

    public function isConfigured(): bool {
        return $this->getServerUrl() !== '' && $this->getApiKey() !== '';
    }

    public function validateConnection(): array {
        return $this->assetService->validateConnection();
    }

    public function getTimelineBuckets(string $size = 'MONTH', ?string $personId = null, ?string $assetType = null, bool $isFavorite = false): array {
        return $this->assetService->getTimelineBuckets($size, $personId, $assetType, $isFavorite);
    }

    public function getTimelineBucket(string $timeBucket, string $size = 'MONTH', ?string $personId = null, ?string $assetType = null, bool $isFavorite = false): array {
        return $this->assetService->getTimelineBucket($timeBucket, $size, $personId, $assetType, $isFavorite);
    }

    public function getAsset(string $id): array {
        return $this->assetService->getAsset($id);
    }

    public function getAssetThumbnail(string $id, string $size = 'thumbnail'): array {
        return $this->assetService->getAssetThumbnail($id, $size);
    }

    public function getAssetOriginal(string $id): array {
        return $this->assetService->getAssetOriginal($id);
    }

    public function downloadArchive(array $assetIds): array {
        return $this->assetService->downloadArchive($assetIds);
    }

    public function getVideoStream(string $id, string $rangeHeader = ''): array {
        return $this->assetService->getVideoStream($id, $rangeHeader);
    }

    public function getAlbums(string $assetId = ''): array {
        return $this->assetService->getAlbums($assetId);
    }

    public function getAlbum(string $id): array {
        return $this->assetService->getAlbum($id);
    }

    public function createAlbum(string $albumName, array $assetIds = []): array {
        return $this->assetService->createAlbum($albumName, $assetIds);
    }

    public function addAssetsToAlbum(string $albumId, array $assetIds): array {
        return $this->assetService->addAssetsToAlbum($albumId, $assetIds);
    }

    public function removeAssetsFromAlbum(string $albumId, array $assetIds): array {
        return $this->assetService->removeAssetsFromAlbum($albumId, $assetIds);
    }

    public function deleteAlbum(string $albumId): void {
        $this->assetService->deleteAlbum($albumId);
    }

    public function renameAlbum(string $albumId, string $albumName): array {
        return $this->assetService->renameAlbum($albumId, $albumName);
    }

    public function updateAsset(string $id, array $data): array {
        return $this->assetService->updateAsset($id, $data);
    }

    public function deleteAssets(array $assetIds): void {
        $this->assetService->deleteAssets($assetIds);
    }

    public function getPeople(): array {
        return $this->assetService->getPeople();
    }

    public function getPersonAssets(string $id): array {
        return $this->assetService->getPersonAssets($id);
    }

    public function getPersonThumbnail(string $id): array {
        return $this->assetService->getPersonThumbnail($id);
    }

    public function getMapMarkers(): array {
        return $this->assetService->getMapMarkers();
    }

    public function getExplore(): array {
        return $this->assetService->getExplore();
    }

    public function uploadAsset(
        mixed $fileContent,
        string $fileName,
        string $mimeType,
        string $createdAt,
        string $modifiedAt,
    ): array {
        return $this->assetService->uploadAsset($fileContent, $fileName, $mimeType, $createdAt, $modifiedAt);
    }

    private function getUserId(): string {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            throw new \RuntimeException('No authenticated user session available');
        }

        return $uid;
    }
}
