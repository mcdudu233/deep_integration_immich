<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use Closure;
use OCA\IntegrationImmich\AppInfo\Application;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

class ImmichAssetService {
    /** Regex for a canonical UUID string. */
    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Max monthly buckets fetched in a single bulk person-assets request (~2 years). */
    private const MAX_PERSON_BUCKETS = 24;

    /**
     * Permissions the integration requires to work correctly.
     * These map to Immich's permission strings as shown in the API key editor.
     */
    private const REQUIRED_PERMISSIONS = [
        'asset.view',
        'asset.read',
        'asset.update',
        'asset.upload',
        'asset.download',
        'asset.delete',
        'album.read',
        'album.create',
        'album.update',
        'album.delete',
        'albumAsset.create',
        'albumAsset.delete',
        'person.read',
        'map.read',
    ];

    private Closure $credentialResolver;

    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
        callable $credentialResolver,
    ) {
        $this->credentialResolver = Closure::fromCallable($credentialResolver);
    }

    public function validateConnection(): array {
        try {
            $response = $this->request('POST', '/auth/validateToken');

            return [
                'success' => true,
                'data' => $response,
                'missing_permissions' => $this->detectMissingPermissions($response),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getTimelineBuckets(string $size = 'MONTH', ?string $personId = null, ?string $assetType = null, bool $isFavorite = false): array {
        $query = ['size' => $size];
        if ($personId !== null && $personId !== '') {
            $query['personId'] = $personId;
        }
        if ($assetType !== null && $assetType !== '') {
            $query['assetType'] = $assetType;
        }
        if ($isFavorite) {
            $query['isFavorite'] = 'true';
        }

        return $this->request('GET', '/timeline/buckets', ['query' => $query]);
    }

    public function getTimelineBucket(string $timeBucket, string $size = 'MONTH', ?string $personId = null, ?string $assetType = null, bool $isFavorite = false): array {
        if (strlen($timeBucket) === 10) {
            $timeBucket .= 'T00:00:00.000Z';
        }

        $query = ['timeBucket' => $timeBucket, 'size' => $size];
        if ($personId !== null && $personId !== '') {
            $query['personId'] = $personId;
        }
        if ($assetType !== null && $assetType !== '') {
            $query['assetType'] = $assetType;
        }
        if ($isFavorite) {
            $query['isFavorite'] = 'true';
        }

        $raw = $this->request('GET', '/timeline/bucket', ['query' => $query]);
        $assetCount = isset($raw['id']) && is_array($raw['id']) ? count($raw['id']) : count($raw);
        $this->logger->debug('Immich /timeline/bucket returned ' . $assetCount . ' assets', [
            'app' => Application::APP_ID,
            'timeBucket' => $timeBucket,
            'size' => $size,
            'query' => $query,
            'rawKeys' => array_keys($raw),
        ]);

        return $this->transformBucketAssets($raw);
    }

    public function getAsset(string $id): array {
        return $this->request('GET', '/assets/' . $id);
    }

    public function getAssetThumbnail(string $id, string $size = 'thumbnail'): array {
        return $this->requestBinary('/assets/' . $id . '/thumbnail?size=' . urlencode($size));
    }

    public function getAssetOriginal(string $id): array {
        return $this->requestBinary('/assets/' . $id . '/original');
    }

    public function downloadArchive(array $assetIds): array {
        return $this->requestBinaryPost('/download/archive', ['assetIds' => $assetIds]);
    }

    public function getVideoStream(string $id, string $rangeHeader = ''): array {
        $client = $this->clientService->newClient();
        $url = $this->baseUrl() . '/api/assets/' . $id . '/video/playback';

        $headers = ['x-api-key' => $this->apiKey()];
        if ($rangeHeader !== '') {
            $headers['Range'] = $rangeHeader;
        }

        try {
            $response = $client->get($url, [
                'headers' => $headers,
                'http_errors' => false,
            ]);

            return [
                'body' => $response->getBody(),
                'statusCode' => $response->getStatusCode(),
                'contentType' => $response->getHeader('Content-Type') ?: 'video/mp4',
                'contentLength' => $response->getHeader('Content-Length') ?: '',
                'contentRange' => $response->getHeader('Content-Range') ?: '',
                'acceptRanges' => $response->getHeader('Accept-Ranges') ?: 'bytes',
            ];
        } catch (\Exception $e) {
            $this->logger->error('Immich video stream request failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'endpoint' => '/assets/' . $id . '/video/playback',
            ]);
            throw $e;
        }
    }

    public function getAlbums(string $assetId = ''): array {
        $options = $assetId !== '' ? ['query' => ['assetId' => $assetId]] : [];
        return $this->request('GET', '/albums', $options);
    }

    public function getAlbum(string $id): array {
        return $this->request('GET', '/albums/' . $id);
    }

    public function createAlbum(string $albumName, array $assetIds = []): array {
        $body = ['albumName' => $albumName];
        if (!empty($assetIds)) {
            $body['assetIds'] = $assetIds;
        }

        return $this->request('POST', '/albums', ['body' => $body]);
    }

    public function addAssetsToAlbum(string $albumId, array $assetIds): array {
        return $this->request('PUT', '/albums/' . $albumId . '/assets', ['body' => ['ids' => $assetIds]]);
    }

    public function removeAssetsFromAlbum(string $albumId, array $assetIds): array {
        return $this->request('DELETE', '/albums/' . $albumId . '/assets', ['body' => ['ids' => $assetIds]]);
    }

    public function deleteAlbum(string $albumId): void {
        $this->request('DELETE', '/albums/' . $albumId);
    }

    public function renameAlbum(string $albumId, string $albumName): array {
        return $this->request('PATCH', '/albums/' . $albumId, ['body' => ['albumName' => $albumName]]);
    }

    public function updateAsset(string $id, array $data): array {
        return $this->request('PUT', '/assets/' . $id, ['body' => $data]);
    }

    public function deleteAssets(array $assetIds): void {
        $this->request('DELETE', '/assets', ['body' => ['ids' => $assetIds]]);
    }

    public function getPeople(): array {
        $result = $this->request('GET', '/people');
        return $result['people'] ?? (array)$result;
    }

    public function getPersonAssets(string $id): array {
        $buckets = $this->request('GET', '/timeline/buckets', [
            'query' => ['size' => 'MONTH', 'personId' => $id],
        ]);

        if (!is_array($buckets)) {
            return [];
        }

        $assets = [];
        foreach (array_slice($buckets, 0, self::MAX_PERSON_BUCKETS) as $bucket) {
            $timeBucket = $bucket['timeBucket'] ?? null;
            if (!$timeBucket) {
                continue;
            }

            $raw = $this->request('GET', '/timeline/bucket', [
                'query' => [
                    'timeBucket' => strlen((string)$timeBucket) === 10 ? $timeBucket . 'T00:00:00.000Z' : $timeBucket,
                    'size' => 'MONTH',
                    'personId' => $id,
                ],
            ]);
            $assets = array_merge($assets, $this->transformBucketAssets($raw));
        }

        return $assets;
    }

    public function getPersonThumbnail(string $id): array {
        return $this->requestBinary('/people/' . $id . '/thumbnail');
    }

    public function getMapMarkers(): array {
        return $this->request('GET', '/map/markers', [
            'query' => ['isArchived' => 'false'],
        ]);
    }

    public function getExplore(): array {
        try {
            $markers = $this->request('GET', '/map/markers', [
                'query' => ['isArchived' => 'false'],
            ]);

            if (!is_array($markers) || empty($markers)) {
                return [];
            }

            $cities = [];
            $countries = [];
            foreach ($markers as $marker) {
                $id = $marker['id'] ?? null;
                if (!$id) {
                    continue;
                }

                $city = $marker['city'] ?? null;
                $country = $marker['country'] ?? null;
                if ($city !== null && $city !== '' && !isset($cities[$city])) {
                    $cities[$city] = ['value' => $city, 'data' => ['id' => $id]];
                }
                if ($country !== null && $country !== '' && !isset($countries[$country])) {
                    $countries[$country] = ['value' => $country, 'data' => ['id' => $id]];
                }
            }

            $result = [];
            if (!empty($cities)) {
                $result[] = ['fieldName' => 'exifInfo.city', 'items' => array_values($cities)];
            }
            if (!empty($countries)) {
                $result[] = ['fieldName' => 'exifInfo.country', 'items' => array_values($countries)];
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->warning('Explore via map markers failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    public function uploadAsset(
        mixed $fileContent,
        string $fileName,
        string $mimeType,
        string $createdAt,
        string $modifiedAt,
    ): array {
        $deviceAssetId = $fileName . '-' . bin2hex(random_bytes(8));
        $client = $this->clientService->newClient();
        $url = $this->baseUrl() . '/api/assets';

        $response = $client->post($url, [
            'headers' => [
                'x-api-key' => $this->apiKey(),
                'Accept' => 'application/json',
            ],
            'multipart' => [
                ['name' => 'assetData', 'contents' => $fileContent, 'filename' => $fileName, 'headers' => ['Content-Type' => $mimeType]],
                ['name' => 'deviceAssetId', 'contents' => $deviceAssetId],
                ['name' => 'deviceId', 'contents' => 'nextcloud-integration'],
                ['name' => 'fileCreatedAt', 'contents' => $createdAt],
                ['name' => 'fileModifiedAt', 'contents' => $modifiedAt],
            ],
        ]);

        $decoded = json_decode($response->getBody(), true);
        return is_array($decoded) ? $decoded : ['status' => 'unknown', 'raw' => (string)$response->getBody()];
    }

    private function request(string $method, string $endpoint, array $options = []): array {
        $client = $this->clientService->newClient();
        $url = $this->baseUrl() . '/api' . $endpoint;
        if (isset($options['query']) && !empty($options['query'])) {
            $url .= '?' . http_build_query($options['query']);
        }

        $requestOptions = [
            'headers' => [
                'x-api-key' => $this->apiKey(),
                'Accept' => 'application/json',
            ],
            'timeout' => 60,
            'http_errors' => false,
        ];

        if (isset($options['body'])) {
            $requestOptions['body'] = json_encode($options['body']);
            $requestOptions['headers']['Content-Type'] = 'application/json';
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $client->get($url, $requestOptions),
                'POST' => $client->post($url, $requestOptions),
                'PUT' => $client->put($url, $requestOptions),
                'PATCH' => $client->patch($url, $requestOptions),
                'DELETE' => $client->delete($url, $requestOptions),
                default => throw new \InvalidArgumentException('Unsupported HTTP method: ' . $method),
            };

            $statusCode = $response->getStatusCode();
            if ($statusCode === 403) {
                $this->logger->warning('Immich API returned 403 for ' . $endpoint . ' — API key may be missing required permissions.', [
                    'app' => Application::APP_ID,
                    'endpoint' => $endpoint,
                    'method' => $method,
                ]);
                return [];
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->error('Immich API returned HTTP ' . $statusCode . ' for ' . $endpoint, [
                    'app' => Application::APP_ID,
                    'endpoint' => $endpoint,
                    'method' => $method,
                ]);
                throw new \RuntimeException('Immich API error: HTTP ' . $statusCode);
            }

            $decoded = json_decode($response->getBody(), true);
            return $decoded ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Immich API request failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'endpoint' => $endpoint,
                'method' => $method,
                'url' => $url,
            ]);
            throw $e;
        }
    }

    private function requestBinary(string $endpoint): array {
        $client = $this->clientService->newClient();
        $url = $this->baseUrl() . '/api' . $endpoint;

        try {
            $response = $client->get($url, [
                'headers' => ['x-api-key' => $this->apiKey()],
                'timeout' => 60,
            ]);

            return [
                'body' => $response->getBody(),
                'contentType' => $response->getHeader('Content-Type'),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Immich binary request failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'endpoint' => $endpoint,
            ]);
            throw $e;
        }
    }

    private function requestBinaryPost(string $endpoint, array $body): array {
        $client = $this->clientService->newClient();
        $url = $this->baseUrl() . '/api' . $endpoint;

        try {
            $response = $client->post($url, [
                'headers' => [
                    'x-api-key' => $this->apiKey(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/octet-stream',
                ],
                'body' => json_encode($body),
            ]);

            return [
                'body' => $response->getBody(),
                'contentType' => $response->getHeader('Content-Type') ?: 'application/zip',
            ];
        } catch (\Exception $e) {
            $this->logger->error('Immich binary POST request failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'endpoint' => $endpoint,
            ]);
            throw $e;
        }
    }

    private function transformBucketAssets(array $raw): array {
        if (!isset($raw['id']) || !is_array($raw['id'])) {
            return $raw;
        }

        $count = count($raw['id']);
        $keys = array_keys($raw);
        $assets = [];
        for ($i = 0; $i < $count; $i++) {
            $asset = [];
            foreach ($keys as $key) {
                $asset[$key] = is_array($raw[$key]) ? ($raw[$key][$i] ?? null) : $raw[$key];
            }
            $assets[] = $asset;
        }

        return $assets;
    }

    private function detectMissingPermissions(array $tokenResponse): array {
        $granted = $tokenResponse['permissions'] ?? null;
        if ($granted === null || in_array('all', (array)$granted, true)) {
            return [];
        }

        $granted = array_flip((array)$granted);
        $missing = [];
        foreach (self::REQUIRED_PERMISSIONS as $required) {
            if (!isset($granted[$required])) {
                $missing[] = $required;
            }
        }

        return $missing;
    }

    private function baseUrl(): string {
        $credentials = ($this->credentialResolver)();
        return rtrim((string)($credentials['url'] ?? ''), '/');
    }

    private function apiKey(): string {
        $credentials = ($this->credentialResolver)();
        return (string)($credentials['apiKey'] ?? '');
    }
}
