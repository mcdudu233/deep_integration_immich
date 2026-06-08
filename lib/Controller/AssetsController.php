<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\Controller;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\ActionPolicyService;
use OCA\IntegrationImmich\Service\BrowsingAuthService;
use OCA\IntegrationImmich\Service\ImmichAssetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AssetsController extends Controller {
    private const CODE_BROWSING_SETUP_NOT_CONFIGURED = 'browsing_setup_not_configured';
    private const CODE_BROWSING_SETUP_PERSONAL_OR_ADMIN_PROXY = 'browsing_setup_personal_or_admin_proxy';

    public function __construct(
        IRequest $request,
        private IClientService $clientService,
        private BrowsingAuthService $browsingAuthService,
        private IRootFolder $rootFolder,
        private ?string $userId,
        private ActionPolicyService $actionPolicyService,
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
    public function timeline(): JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }

        try {
            $assetService = $this->assetService($credentials);
            $timeBucket = $this->request->getParam('timeBucket');
            $size = $this->request->getParam('size', 'MONTH');
            $personId = $this->request->getParam('personId');
            $assetType = $this->request->getParam('assetType');
            $isFavoriteParam = $this->request->getParam('isFavorite');
            $isFavorite = $isFavoriteParam === 'true';

            if ($timeBucket) {
                $data = $assetService->getTimelineBucket($timeBucket, $size, $personId, null, $isFavorite);
                $data = $this->filterAssetsByType($data, is_string($assetType) ? $assetType : null);
                $data = $this->filterAssetListForBrowsing($data, $credentials);
            } else {
                $buckets = $assetService->getTimelineBuckets($size, $personId, null, $isFavorite);
                $data = $this->filterTimelineBucketsForBrowsing(
                    $assetService,
                    $buckets,
                    $credentials,
                    (string)$size,
                    is_string($personId) ? $personId : null,
                    is_string($assetType) ? $assetType : null,
                    $isFavorite
                );
            }

            return new JSONResponse($data);
        } catch (\Exception $e) {
            return $this->errorResponse('timeline', $e);
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
            return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $data = $this->assetService($credentials)->getAsset($id);
            if (!$this->assetResponseIsAuthorized($credentials, $data, $id)) {
                return $this->ownershipFailureResponse();
            }
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return $this->errorResponse('asset info', $e);
        }
    }

    #[NoAdminRequired]
    public function update(string $id): JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }
        if (!preg_match(ImmichAssetService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
        }
        $allowed = ['isFavorite', 'isArchived', 'description'];
        $data = array_intersect_key($this->request->getParams(), array_flip($allowed));
        if (empty($data)) {
            return new JSONResponse(['error' => 'No valid fields provided'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $ownershipFailure = $this->ensureAssetOwnership($credentials, $id);
            if ($ownershipFailure !== null) {
                return $ownershipFailure;
            }

            $result = $this->assetService($credentials)->updateAsset($id, $data);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse('asset update', $e);
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
            return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $ownershipFailure = $this->ensureAssetOwnership($credentials, $id);
            if ($ownershipFailure !== null) {
                return $ownershipFailure;
            }

            $size = $this->request->getParam('size', 'thumbnail');
            $size = in_array($size, ['thumbnail', 'preview'], true) ? $size : 'thumbnail';
            $result = $this->assetService($credentials)->getAssetThumbnail($id, $size);
            $response = new DataDownloadResponse(
                $result['body'],
                $id . '.jpg',
                $result['contentType'] ?? 'image/jpeg'
            );
            $response->cacheFor(3600);
            return $response;
        } catch (\Exception $e) {
            return $this->errorResponse('thumbnail', $e);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function original(string $id): DataDownloadResponse|JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }
        if (!preg_match(ImmichAssetService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $ownershipFailure = $this->ensureAssetOwnership($credentials, $id);
            if ($ownershipFailure !== null) {
                return $ownershipFailure;
            }

            $result = $this->assetService($credentials)->getAssetOriginal($id);
            $response = new DataDownloadResponse(
                $result['body'],
                $id,
                $result['contentType'] ?? 'application/octet-stream'
            );
            return $response;
        } catch (\Exception $e) {
            return $this->errorResponse('original', $e);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function videoStream(string $id): DataDownloadResponse|JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }
        if (!preg_match(ImmichAssetService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $ownershipFailure = $this->ensureAssetOwnership($credentials, $id);
            if ($ownershipFailure !== null) {
                return $ownershipFailure;
            }

            $rangeHeader = $this->request->getHeader('Range') ?? '';
            if ($rangeHeader !== '' && !preg_match('/^bytes=(\d+-\d*|-\d+)(,\s*(\d+-\d*|-\d+))*$/', $rangeHeader)) {
                $rangeHeader = '';
            }
            $result = $this->assetService($credentials)->getVideoStream($id, $rangeHeader);

            $response = new DataDownloadResponse(
                $result['body'],
                $id,
                $result['contentType']
            );

            // Override Content-Disposition so the browser plays the video inline
            $response->addHeader('Content-Disposition', 'inline');
            $response->addHeader('Accept-Ranges', $result['acceptRanges'] ?: 'bytes');

            if ($result['contentLength'] !== '') {
                $response->addHeader('Content-Length', $result['contentLength']);
            }
            if ($result['contentRange'] !== '') {
                $response->addHeader('Content-Range', $result['contentRange']);
            }

            $response->setStatus((int)($result['statusCode'] ?? Http::STATUS_OK));

            return $response;
        } catch (\Exception $e) {
            return $this->errorResponse('video stream', $e);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function mapMarkers(): JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }

        try {
            $markers = $this->assetService($credentials)->getMapMarkers();
            $markers = $this->filterAssetListForBrowsing($markers, $credentials);
            return new JSONResponse($markers);
        } catch (\Exception $e) {
            return $this->errorResponse('map markers', $e);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function explore(): JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }

        try {
            if (($credentials['mode'] ?? '') === BrowsingAuthService::MODE_ADMIN_PROXY) {
                $markers = $this->assetService($credentials)->getMapMarkers();
                $data = $this->buildExploreFromMarkers($this->filterAssetListForBrowsing($markers, $credentials));
            } else {
                $data = $this->assetService($credentials)->getExplore();
            }
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return $this->errorResponse('explore', $e);
        }
    }

    #[NoAdminRequired]
    public function downloadAssets(): DataDownloadResponse|JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }

        $assetIds = $this->request->getParam('assetIds', []);
        if (!is_array($assetIds)) {
            $assetIds = [$assetIds];
        }

        if (empty($assetIds)) {
            return new JSONResponse(['error' => 'assetIds must be a non-empty array'], Http::STATUS_BAD_REQUEST);
        }

        foreach ($assetIds as $id) {
            if (!preg_match(ImmichAssetService::UUID_PATTERN, (string)$id)) {
                return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
            }
        }

        try {
            $ownershipFailure = $this->ensureAssetIdsOwnership($credentials, array_values(array_map('strval', $assetIds)));
            if ($ownershipFailure !== null) {
                return $ownershipFailure;
            }

            $assetService = $this->assetService($credentials);
            if (count($assetIds) === 1) {
                // Single asset → GET /api/assets/{id}/original
                $assetId = (string) $assetIds[0];
                $asset = $assetService->getAsset($assetId);
                $fileName = $asset['originalFileName'] ?? ($assetId . '.bin');

                $result = $assetService->getAssetOriginal($assetId);
                $response = new DataDownloadResponse(
                    $result['body'],
                    $fileName,
                    $result['contentType'] ?? 'application/octet-stream'
                );
                return $response;
            }

            // Multiple assets → POST /api/download/archive → Immich builds the ZIP
            $zipName = 'immich-download-' . date('Y-m-d') . '.zip';
            $result = $assetService->downloadArchive(array_values(array_map('strval', $assetIds)));
            $response = new DataDownloadResponse(
                $result['body'],
                $zipName,
                'application/zip'
            );
            return $response;
        } catch (\Exception $e) {
            return $this->errorResponse('download', $e);
        }
    }

    #[NoAdminRequired]
    public function deleteAssets(): JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }

        $assetIds = $this->request->getParam('assetIds', []);
        if (!is_array($assetIds)) {
            $assetIds = [$assetIds];
        }

        if (empty($assetIds)) {
            return new JSONResponse(['error' => 'assetIds must be a non-empty array'], Http::STATUS_BAD_REQUEST);
        }

        if (!$this->actionPolicyService->isDeleteEnabled()) {
            return new JSONResponse(['error' => 'Delete from Immich is disabled by the administrator'], Http::STATUS_FORBIDDEN);
        }

        foreach ($assetIds as $id) {
            if (!preg_match(ImmichAssetService::UUID_PATTERN, (string)$id)) {
                return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
            }
        }

        try {
            $assetIds = array_values(array_map('strval', $assetIds));
            $ownershipFailure = $this->ensureAssetIdsOwnership($credentials, $assetIds);
            if ($ownershipFailure !== null) {
                return $ownershipFailure;
            }

            $this->assetService($credentials)->deleteAssets($assetIds);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return $this->errorResponse('delete assets', $e);
        }
    }

    #[NoAdminRequired]
    public function saveToNextcloud(): JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }

        $assetIds = $this->request->getParam('assetIds', []);
        $path = $this->request->getParam('path', '');

        if (empty($assetIds) || !is_array($assetIds)) {
            return new JSONResponse(['error' => 'assetIds must be a non-empty array'], Http::STATUS_BAD_REQUEST);
        }

        if ($path === '' || $path === null) {
            return new JSONResponse(['error' => 'path is required'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->actionPolicyService->isExportCopyEnabled()) {
            return new JSONResponse(['error' => 'Export copy to Nextcloud is disabled by the administrator'], Http::STATUS_FORBIDDEN);
        }

        foreach ($assetIds as $id) {
            if (!preg_match(ImmichAssetService::UUID_PATTERN, (string)$id)) {
                return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
            }
        }

        $normalizedPath = trim((string)$path, '/');

        if (str_contains($normalizedPath, '..') || str_contains($normalizedPath, "\0")) {
            return new JSONResponse(['error' => 'Invalid path'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->actionPolicyService->isPathInsideMirrorMount($this->userId, $normalizedPath)) {
            return new JSONResponse(['error' => 'Exporting into the read-only Immich mirror mount is not allowed'], Http::STATUS_FORBIDDEN);
        }

        $assetIds = array_values(array_map('strval', $assetIds));
        $ownershipFailure = $this->ensureAssetIdsOwnership($credentials, $assetIds);
        if ($ownershipFailure !== null) {
            return $ownershipFailure;
        }

        $userFolder = $this->rootFolder->getUserFolder($this->userId);

        try {
            $targetNode = $userFolder->get($normalizedPath);
            if (!($targetNode instanceof Folder)) {
                return new JSONResponse(['error' => 'Path is not a folder'], Http::STATUS_BAD_REQUEST);
            }
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'Folder not found'], Http::STATUS_NOT_FOUND);
        }

        $saved = 0;
        $failed = 0;
        $errors = [];
        $assetService = $this->assetService($credentials);

        foreach ($assetIds as $assetId) {
            try {
                // Fetch metadata to get the original filename
                $asset = $assetService->getAsset($assetId);
                $fileName = $asset['originalFileName'] ?? ($assetId . '.bin');

                // Ensure unique filename in target folder
                $fileName = $this->getUniqueFileName($targetNode, (string)$fileName);

                // Fetch the original binary (works for images and videos)
                $result = $assetService->getAssetOriginal($assetId);

                // Write to Nextcloud — body is a stream, putContent accepts resource
                $file = $targetNode->newFile($fileName);
                $file->putContent($result['body']);

                $saved++;
            } catch (\Exception $e) {
                $failed++;
                $this->logger->error('Immich save-to-nextcloud failed for asset ' . $assetId . ': ' . $e->getMessage(), [
                    'app' => Application::APP_ID,
                    'exception' => $e,
                ]);
                $errors[] = ['id' => $assetId, 'error' => 'Failed to save asset'];
            }
        }

        return new JSONResponse(['saved' => $saved, 'failed' => $failed, 'errors' => $errors]);
    }

    /**
     * @return array{mode: string, url: string, apiKey: string|null, immichUserId: string|null}|JSONResponse
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
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null} $credentials
     */
    private function assetService(array $credentials): ImmichAssetService {
        return new ImmichAssetService(
            $this->clientService,
            $this->logger,
            static fn (): array => $credentials,
        );
    }

    /**
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null} $credentials
     */
    private function ensureAssetOwnership(array $credentials, string $assetId): ?JSONResponse {
        if (($credentials['mode'] ?? '') !== BrowsingAuthService::MODE_ADMIN_PROXY) {
            return null;
        }

        $immichUserId = (string)($credentials['immichUserId'] ?? '');
        if (!$this->browsingAuthService->assertAssetOwnership($immichUserId, $assetId)) {
            return $this->ownershipFailureResponse();
        }

        return null;
    }

    /**
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null} $credentials
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
        return new JSONResponse(['error' => 'Asset is not available for this user'], Http::STATUS_FORBIDDEN);
    }

    /**
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null} $credentials
     */
    private function assetResponseIsAuthorized(array $credentials, array $asset, string $assetId): bool {
        if (($credentials['mode'] ?? '') !== BrowsingAuthService::MODE_ADMIN_PROXY) {
            return true;
        }

        $immichUserId = (string)($credentials['immichUserId'] ?? '');
        if ($this->browsingAuthService->assetBelongsToUser($asset, $immichUserId)) {
            return true;
        }

        return $this->browsingAuthService->assertAssetOwnership($immichUserId, $assetId);
    }

    /**
     * @param array<int, mixed> $assets
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null} $credentials
     */
    private function filterAssetListForBrowsing(array $assets, array $credentials): array {
        if (($credentials['mode'] ?? '') !== BrowsingAuthService::MODE_ADMIN_PROXY) {
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
     * @param array<int, mixed> $assets
     */
    private function filterAssetsByType(array $assets, ?string $assetType): array {
        if ($assetType === 'IMAGE') {
            return array_values(array_filter(
                $assets,
                static fn(mixed $asset): bool => is_array($asset) && (bool)($asset['isImage'] ?? true)
            ));
        }

        if ($assetType === 'VIDEO') {
            return array_values(array_filter(
                $assets,
                static fn(mixed $asset): bool => is_array($asset) && !($asset['isImage'] ?? true)
            ));
        }

        return $assets;
    }

    /**
     * @param array<int, mixed> $buckets
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null} $credentials
     */
    private function filterTimelineBucketsForBrowsing(
        ImmichAssetService $assetService,
        array $buckets,
        array $credentials,
        string $size,
        ?string $personId,
        ?string $assetType,
        bool $isFavorite,
    ): array {
        if (($credentials['mode'] ?? '') !== BrowsingAuthService::MODE_ADMIN_PROXY) {
            return $buckets;
        }

        $filteredBuckets = [];
        foreach ($buckets as $bucket) {
            if (!is_array($bucket) || !isset($bucket['timeBucket'])) {
                continue;
            }

            $assets = $assetService->getTimelineBucket((string)$bucket['timeBucket'], $size, $personId, null, $isFavorite);
            $assets = $this->filterAssetsByType($assets, $assetType);
            $assets = $this->filterAssetListForBrowsing($assets, $credentials);
            if ($assets === []) {
                continue;
            }

            $bucket['count'] = count($assets);
            $filteredBuckets[] = $bucket;
        }

        return $filteredBuckets;
    }

    /**
     * @param array<int, mixed> $markers
     */
    private function buildExploreFromMarkers(array $markers): array {
        $cities = [];
        $countries = [];
        foreach ($markers as $marker) {
            if (!is_array($marker)) {
                continue;
            }

            $id = $marker['id'] ?? null;
            if (!is_string($id) || $id === '') {
                continue;
            }

            $city = $marker['city'] ?? null;
            $country = $marker['country'] ?? null;
            if (is_string($city) && $city !== '' && !isset($cities[$city])) {
                $cities[$city] = ['value' => $city, 'data' => ['id' => $id]];
            }
            if (is_string($country) && $country !== '' && !isset($countries[$country])) {
                $countries[$country] = ['value' => $country, 'data' => ['id' => $id]];
            }
        }

        $result = [];
        if ($cities !== []) {
            $result[] = ['fieldName' => 'exifInfo.city', 'items' => array_values($cities)];
        }
        if ($countries !== []) {
            $result[] = ['fieldName' => 'exifInfo.country', 'items' => array_values($countries)];
        }

        return $result;
    }

    private function assetIdFromResponse(array $asset): ?string {
        foreach (['id', 'assetId'] as $key) {
            if (isset($asset[$key]) && is_string($asset[$key]) && $asset[$key] !== '') {
                return $asset[$key];
            }
        }

        return null;
    }

    private function getUniqueFileName(Folder $folder, string $fileName): string {
        if (!$folder->nodeExists($fileName)) {
            return $fileName;
        }

        $info = pathinfo($fileName);
        $name = $info['filename'];
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';

        for ($i = 1; $i <= 9999; $i++) {
            $candidate = $name . ' (' . $i . ')' . $ext;
            if (!$folder->nodeExists($candidate)) {
                return $candidate;
            }
        }

        // Fallback: append a unique suffix to guarantee uniqueness
        return $name . ' (' . uniqid('', true) . ')' . $ext;
    }
}
