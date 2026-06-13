<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\Controller;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\BrowsingAuthService;
use OCA\IntegrationImmich\Service\ImmichAssetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AlbumsController extends Controller {
    private const CODE_BROWSING_SETUP_NOT_CONFIGURED = 'browsing_setup_not_configured';
    private const CODE_BROWSING_SETUP_PERSONAL_OR_ADMIN_PROXY = 'browsing_setup_personal_or_admin_proxy';

    public function __construct(
        IRequest $request,
        private IClientService $clientService,
        private BrowsingAuthService $browsingAuthService,
        private ?string $userId,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    private function errorResponse(string $context, \Exception $e): JSONResponse {
        $this->logger->error('Immich ' . $context . ' failed: ' . $e->getMessage(), [
            'app' => Application::APP_ID,
            'exception' => $e,
        ]);
        return new JSONResponse(
            ['error' => 'An internal error occurred'],
            Http::STATUS_INTERNAL_SERVER_ERROR
        );
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }

        $assetId = $this->request->getParam('assetId', '');
        if ($assetId !== '' && !preg_match(ImmichAssetService::UUID_PATTERN, $assetId)) {
            return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
        }

        try {
            if ($assetId !== '') {
                $ownershipFailure = $this->ensureAssetOwnership($credentials, (string)$assetId);
                if ($ownershipFailure !== null) {
                    return $ownershipFailure;
                }
            }

            $assetService = $this->assetService($credentials);
            $albums = $assetService->getAlbums((string)$assetId);
            return new JSONResponse($this->filterAlbumListForBrowsing($assetService, $albums, $credentials));
        } catch (\Exception $e) {
            return $this->errorResponse('albums list', $e);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(string $id): JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }
        if (!preg_match(ImmichAssetService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid album ID format'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $album = $this->assetService($credentials)->getAlbum($id);
            if (!$this->albumResponseIsAuthorized($credentials, $album)) {
                return $this->ownershipFailureResponse();
            }

            return new JSONResponse($this->sanitizeAlbumForBrowsing($album, $credentials));
        } catch (\Exception $e) {
            return $this->errorResponse('album show', $e);
        }
    }

    #[NoAdminRequired]
    public function create(): JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }

        $albumName = $this->request->getParam('albumName', '');
        $assetIds = $this->request->getParam('assetIds', []);

        if (trim($albumName) === '') {
            return new JSONResponse(['error' => 'albumName is required'], Http::STATUS_BAD_REQUEST);
        }

        $assetIds = is_array($assetIds) ? $assetIds : [];
        foreach ($assetIds as $assetId) {
            if (!preg_match(ImmichAssetService::UUID_PATTERN, (string)$assetId)) {
                return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
            }
        }

        $assetIds = array_values(array_map('strval', $assetIds));

        try {
            $ownershipFailure = $this->ensureAssetIdsOwnership($credentials, $assetIds);
            if ($ownershipFailure !== null) {
                return $ownershipFailure;
            }

            $album = $this->assetService($credentials)->createAlbum($albumName, $assetIds);
            return new JSONResponse($this->sanitizeAlbumForBrowsing($album, $credentials), Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->errorResponse('album create', $e);
        }
    }

    #[NoAdminRequired]
    public function delete(string $id): JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }
        if (!preg_match(ImmichAssetService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid album ID format'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $assetService = $this->assetService($credentials);
            $ownershipFailure = $this->ensureAlbumOwnershipById($assetService, $credentials, $id);
            if ($ownershipFailure !== null) {
                return $ownershipFailure;
            }

            $assetService->deleteAlbum($id);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return $this->errorResponse('album delete', $e);
        }
    }

    #[NoAdminRequired]
    public function rename(string $id): JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }
        if (!preg_match(ImmichAssetService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid album ID format'], Http::STATUS_BAD_REQUEST);
        }
        $albumName = trim($this->request->getParam('albumName', ''));
        if ($albumName === '') {
            return new JSONResponse(['error' => 'albumName is required'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $assetService = $this->assetService($credentials);
            $ownershipFailure = $this->ensureAlbumOwnershipById($assetService, $credentials, $id);
            if ($ownershipFailure !== null) {
                return $ownershipFailure;
            }

            $album = $assetService->renameAlbum($id, $albumName);
            return new JSONResponse($this->sanitizeAlbumForBrowsing($album, $credentials));
        } catch (\Exception $e) {
            return $this->errorResponse('album rename', $e);
        }
    }

    #[NoAdminRequired]
    public function removeAssets(string $id): JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }
        if (!preg_match(ImmichAssetService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid album ID format'], Http::STATUS_BAD_REQUEST);
        }
        $assetIds = $this->request->getParam('assetIds', []);
        if (!is_array($assetIds) || empty($assetIds)) {
            return new JSONResponse(['error' => 'assetIds must be a non-empty array'], Http::STATUS_BAD_REQUEST);
        }
        foreach ($assetIds as $assetId) {
            if (!preg_match(ImmichAssetService::UUID_PATTERN, (string)$assetId)) {
                return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
            }
        }

        $assetIds = array_values(array_map('strval', $assetIds));
        try {
            $assetService = $this->assetService($credentials);
            $ownershipFailure = $this->ensureAlbumOwnershipById($assetService, $credentials, $id);
            if ($ownershipFailure !== null) {
                return $ownershipFailure;
            }

            $ownershipFailure = $this->ensureAssetIdsOwnership($credentials, $assetIds);
            if ($ownershipFailure !== null) {
                return $ownershipFailure;
            }

            $result = $assetService->removeAssetsFromAlbum($id, $assetIds);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse('album remove assets', $e);
        }
    }

    #[NoAdminRequired]
    public function addAssets(string $id): JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }

        if (!preg_match(ImmichAssetService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid album ID format'], Http::STATUS_BAD_REQUEST);
        }

        $assetIds = $this->request->getParam('assetIds', []);
        if (!is_array($assetIds) || empty($assetIds)) {
            return new JSONResponse(['error' => 'assetIds must be a non-empty array'], Http::STATUS_BAD_REQUEST);
        }

        foreach ($assetIds as $assetId) {
            if (!preg_match(ImmichAssetService::UUID_PATTERN, (string)$assetId)) {
                return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
            }
        }

        $assetIds = array_values(array_map('strval', $assetIds));

        try {
            $assetService = $this->assetService($credentials);
            $ownershipFailure = $this->ensureAlbumOwnershipById($assetService, $credentials, $id);
            if ($ownershipFailure !== null) {
                return $ownershipFailure;
            }

            $ownershipFailure = $this->ensureAssetIdsOwnership($credentials, $assetIds);
            if ($ownershipFailure !== null) {
                return $ownershipFailure;
            }

            $result = $assetService->addAssetsToAlbum($id, $assetIds);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse('album add assets', $e);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function thumbnail(string $id): DataDownloadResponse|JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }
        if (!preg_match(ImmichAssetService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid album ID format'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $assetService = $this->assetService($credentials);
            $album = $assetService->getAlbum($id);
            if (!$this->albumResponseIsAuthorized($credentials, $album)) {
                return $this->ownershipFailureResponse();
            }

            $album = $this->sanitizeAlbumForBrowsing($album, $credentials);
            $thumbnailAssetId = $this->ownedThumbnailAssetId($album, $credentials);

            if (!$thumbnailAssetId && !empty($album['assets'])) {
                foreach ($album['assets'] as $asset) {
                    if (!is_array($asset)) {
                        continue;
                    }

                    $assetId = $this->assetIdFromResponse($asset);
                    if ($assetId !== null && $this->ensureAssetOwnership($credentials, $assetId) === null) {
                        $thumbnailAssetId = $assetId;
                        break;
                    }
                }
            }

            if (!$thumbnailAssetId) {
                return new JSONResponse(
                    ['error' => 'No thumbnail available'],
                    Http::STATUS_NOT_FOUND
                );
            }

            $result = $assetService->getAssetThumbnail($thumbnailAssetId);
            $response = new DataDownloadResponse(
                $result['body'],
                $id . '.jpg',
                $result['contentType'] ?? 'image/jpeg'
            );
            $response->cacheFor(3600);
            return $response;
        } catch (\Exception $e) {
            return $this->errorResponse('album thumbnail', $e);
        }
    }

    /**
     * @return array{mode: string, url: string, apiKey: string|null, immichUserId: string|null, usesUserApiKey?: bool}|JSONResponse
     */
    private function resolveBrowsingCredentials(): array|JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $credentials = $this->browsingAuthService->resolveCredentials($this->userId);
        if (($credentials['mode'] ?? '') === BrowsingAuthService::MODE_UNAVAILABLE) {
            return $this->browsingSetupNotConfiguredResponse();
        }

        return $credentials;
    }

    private function browsingSetupNotConfiguredResponse(): JSONResponse {
        return new JSONResponse([
            'error' => 'Immich browsing is not configured for this account',
            'code' => self::CODE_BROWSING_SETUP_NOT_CONFIGURED,
            'errorCode' => self::CODE_BROWSING_SETUP_NOT_CONFIGURED,
            'setup' => 'Configure a personal Immich server URL and API key in personal settings, or ask an administrator to enable admin proxy browsing and provision your Immich user mapping.',
            'setupCode' => self::CODE_BROWSING_SETUP_PERSONAL_OR_ADMIN_PROXY,
            'setupParams' => [],
            'details' => [
                'code' => self::CODE_BROWSING_SETUP_NOT_CONFIGURED,
                'setupCode' => self::CODE_BROWSING_SETUP_PERSONAL_OR_ADMIN_PROXY,
                'params' => [],
            ],
        ], Http::STATUS_PRECONDITION_FAILED);
    }

    /**
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null, usesUserApiKey?: bool} $credentials
     */
    private function assetService(array $credentials): ImmichAssetService {
        return new ImmichAssetService(
            $this->clientService,
            $this->logger,
            static fn (): array => $credentials,
        );
    }

    /**
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null, usesUserApiKey?: bool} $credentials
     */
    private function ensureAlbumOwnershipById(ImmichAssetService $assetService, array $credentials, string $albumId): ?JSONResponse {
        if (!$this->requiresServerSideOwnershipChecks($credentials)) {
            return null;
        }

        $album = $assetService->getAlbum($albumId);
        if (!$this->albumResponseIsAuthorized($credentials, $album)) {
            return $this->ownershipFailureResponse();
        }

        return null;
    }

    /**
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null, usesUserApiKey?: bool} $credentials
     */
    private function ensureAssetOwnership(array $credentials, string $assetId): ?JSONResponse {
        if (!$this->requiresServerSideOwnershipChecks($credentials)) {
            return null;
        }

        $immichUserId = (string)($credentials['immichUserId'] ?? '');
        if (!$this->browsingAuthService->assertAssetOwnership($immichUserId, $assetId)) {
            return $this->ownershipFailureResponse();
        }

        return null;
    }

    /**
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null, usesUserApiKey?: bool} $credentials
     * @param string[] $assetIds
     */
    private function ensureAssetIdsOwnership(array $credentials, array $assetIds): ?JSONResponse {
        foreach ($assetIds as $assetId) {
            $failure = $this->ensureAssetOwnership($credentials, $assetId);
            if ($failure !== null) {
                return $failure;
            }
        }

        return null;
    }

    private function ownershipFailureResponse(): JSONResponse {
        return new JSONResponse(['error' => 'Album is not available for this user'], Http::STATUS_FORBIDDEN);
    }

    /**
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null, usesUserApiKey?: bool} $credentials
     */
    private function albumResponseIsAuthorized(array $credentials, array $album): bool {
        if (!$this->requiresServerSideOwnershipChecks($credentials)) {
            return true;
        }

        $immichUserId = (string)($credentials['immichUserId'] ?? '');
        $ownerId = $this->extractOwnerId($album);
        if ($ownerId !== null) {
            return hash_equals($immichUserId, $ownerId);
        }

        $assets = $album['assets'] ?? null;
        if (!is_array($assets) || $assets === []) {
            return false;
        }

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                return false;
            }

            if ($this->browsingAuthService->assetBelongsToUser($asset, $immichUserId)) {
                continue;
            }

            $assetId = $this->assetIdFromResponse($asset);
            if ($assetId === null || !$this->browsingAuthService->assertAssetOwnership($immichUserId, $assetId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, mixed> $albums
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null, usesUserApiKey?: bool} $credentials
     */
    private function filterAlbumListForBrowsing(ImmichAssetService $assetService, array $albums, array $credentials): array {
        if (!$this->requiresServerSideOwnershipChecks($credentials)) {
            return $albums;
        }

        $filtered = [];
        foreach ($albums as $album) {
            if (!is_array($album)) {
                continue;
            }

            if ($this->albumResponseIsAuthorized($credentials, $album)) {
                $filtered[] = $this->sanitizeAlbumForBrowsing($album, $credentials);
                continue;
            }

            if ($this->extractOwnerId($album) !== null) {
                continue;
            }

            $albumId = $this->albumIdFromResponse($album);
            if ($albumId === null) {
                continue;
            }

            try {
                $detailedAlbum = $assetService->getAlbum($albumId);
            } catch (\Throwable $e) {
                $this->logger->warning('Immich album ownership detail check failed: ' . $e->getMessage(), [
                    'app' => Application::APP_ID,
                    'albumId' => $albumId,
                ]);
                continue;
            }

            if ($this->albumResponseIsAuthorized($credentials, $detailedAlbum)) {
                $filtered[] = $this->sanitizeAlbumForBrowsing($album, $credentials);
            }
        }

        return $filtered;
    }

    /**
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null, usesUserApiKey?: bool} $credentials
     */
    private function sanitizeAlbumForBrowsing(array $album, array $credentials): array {
        if (!$this->requiresServerSideOwnershipChecks($credentials)) {
            return $album;
        }

        foreach (['sharedUsers', 'albumUsers', 'users', 'sharedLinks'] as $field) {
            unset($album[$field]);
        }

        if (isset($album['assets']) && is_array($album['assets'])) {
            $album['assets'] = $this->filterAssetListForBrowsing($album['assets'], $credentials);
        }

        return $album;
    }

    /**
     * @param array<int, mixed> $assets
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null, usesUserApiKey?: bool} $credentials
     */
    private function filterAssetListForBrowsing(array $assets, array $credentials): array {
        if (!$this->requiresServerSideOwnershipChecks($credentials)) {
            return $assets;
        }

        $immichUserId = (string)($credentials['immichUserId'] ?? '');
        return array_values(array_filter($assets, function (mixed $asset) use ($immichUserId): bool {
            if (!is_array($asset)) {
                return false;
            }

            if ($this->browsingAuthService->assetBelongsToUser($asset, $immichUserId)) {
                return true;
            }

            $assetId = $this->assetIdFromResponse($asset);
            return $assetId !== null && $this->browsingAuthService->assertAssetOwnership($immichUserId, $assetId);
        }));
    }

    /**
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null, usesUserApiKey?: bool} $credentials
     */
    private function ownedThumbnailAssetId(array $album, array $credentials): ?string {
        $thumbnailAssetId = $album['albumThumbnailAssetId'] ?? null;
        if (!is_string($thumbnailAssetId) || $thumbnailAssetId === '') {
            return null;
        }

        if ($this->ensureAssetOwnership($credentials, $thumbnailAssetId) !== null) {
            return null;
        }

        return $thumbnailAssetId;
    }

    private function extractOwnerId(array $item): ?string {
        foreach (['ownerId', 'ownerID', 'userId'] as $key) {
            if (isset($item[$key]) && is_string($item[$key]) && $item[$key] !== '') {
                return $item[$key];
            }
        }

        if (isset($item['owner']) && is_string($item['owner']) && $item['owner'] !== '') {
            return $item['owner'];
        }

        if (isset($item['owner']) && is_array($item['owner'])) {
            $owner = $item['owner'];
            if (isset($owner['id']) && is_string($owner['id']) && $owner['id'] !== '') {
                return $owner['id'];
            }
        }

        return null;
    }

    private function albumIdFromResponse(array $album): ?string {
        if (isset($album['id']) && is_string($album['id']) && $album['id'] !== '') {
            return $album['id'];
        }

        return null;
    }

    private function assetIdFromResponse(array $asset): ?string {
        foreach (['id', 'assetId'] as $key) {
            if (isset($asset[$key]) && is_string($asset[$key]) && $asset[$key] !== '') {
                return $asset[$key];
            }
        }

        return null;
    }

    /**
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null, usesUserApiKey?: bool} $credentials
     */
    private function requiresServerSideOwnershipChecks(array $credentials): bool {
        return ($credentials['mode'] ?? '') === BrowsingAuthService::MODE_ADMIN_PROXY
            && ($credentials['usesUserApiKey'] ?? false) !== true;
    }
}
