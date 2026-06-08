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

class PeopleController extends Controller {
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

        try {
            $assetService = $this->assetService($credentials);
            $people = $assetService->getPeople();
            return new JSONResponse($this->filterPeopleForBrowsing($assetService, $people, $credentials));
        } catch (\Exception $e) {
            return $this->errorResponse('people list', $e);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function assets(string $id): JSONResponse {
        $credentials = $this->resolveBrowsingCredentials();
        if ($credentials instanceof JSONResponse) {
            return $credentials;
        }
        if (!preg_match(ImmichAssetService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid person ID format'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $assetService = $this->assetService($credentials);
            $assets = $this->personAssetsForBrowsing($assetService, $credentials, $id);

            return new JSONResponse($assets);
        } catch (\DomainException) {
            return $this->ownershipFailureResponse();
        } catch (\Exception $e) {
            return $this->errorResponse('person assets', $e);
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
            return new JSONResponse(['error' => 'Invalid person ID format'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $assetService = $this->assetService($credentials);
            if (($credentials['mode'] ?? '') !== BrowsingAuthService::MODE_ADMIN_PROXY) {
                $result = $assetService->getPersonThumbnail($id);
            } else {
                $assets = $this->personAssetsForBrowsing($assetService, $credentials, $id);
                $thumbnailAssetId = $this->firstAssetId($assets);
                if ($thumbnailAssetId === null) {
                    return $this->ownershipFailureResponse();
                }

                $result = $assetService->getAssetThumbnail($thumbnailAssetId);
            }

            $response = new DataDownloadResponse(
                $result['body'],
                $id . '.jpg',
                $result['contentType'] ?? 'image/jpeg'
            );
            $response->cacheFor(3600);
            return $response;
        } catch (\DomainException) {
            return $this->ownershipFailureResponse();
        } catch (\Exception $e) {
            return $this->errorResponse('person thumbnail', $e);
        }
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

    private function ownershipFailureResponse(): JSONResponse {
        return new JSONResponse(['error' => 'Person is not available for this user'], Http::STATUS_FORBIDDEN);
    }

    /**
     * @param array<int, mixed> $people
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null} $credentials
     */
    private function filterPeopleForBrowsing(ImmichAssetService $assetService, array $people, array $credentials): array {
        if (($credentials['mode'] ?? '') !== BrowsingAuthService::MODE_ADMIN_PROXY) {
            return $people;
        }

        $filtered = [];
        foreach ($people as $person) {
            if (!is_array($person)) {
                continue;
            }

            $personId = $this->personIdFromResponse($person);
            if ($personId === null) {
                continue;
            }

            try {
                $this->personAssetsForBrowsing($assetService, $credentials, $personId);
                $filtered[] = $person;
            } catch (\DomainException) {
                continue;
            }
        }

        return $filtered;
    }

    /**
     * @param array{mode: string, url: string, apiKey: string|null, immichUserId: string|null} $credentials
     * @return array<int, array<string, mixed>>
     */
    private function personAssetsForBrowsing(ImmichAssetService $assetService, array $credentials, string $personId): array {
        if (($credentials['mode'] ?? '') !== BrowsingAuthService::MODE_ADMIN_PROXY) {
            return $assetService->getPersonAssets($personId);
        }

        $assets = $this->loadAllPersonAssets($assetService, $personId);
        $filteredAssets = $this->filterAssetListForBrowsing($assets, $credentials);
        if ($assets === [] || count($filteredAssets) !== count($assets)) {
            throw new \DomainException('Person is not available for this user');
        }

        return $filteredAssets;
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
     * @return array<int, array<string, mixed>>
     */
    private function loadAllPersonAssets(ImmichAssetService $assetService, string $personId): array {
        $buckets = $assetService->getTimelineBuckets('MONTH', $personId);
        if (!is_array($buckets)) {
            return [];
        }

        $assets = [];
        foreach ($buckets as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }

            $timeBucket = $bucket['timeBucket'] ?? null;
            if (!is_string($timeBucket) || $timeBucket === '') {
                continue;
            }

            foreach ($assetService->getTimelineBucket($timeBucket, 'MONTH', $personId) as $asset) {
                if (is_array($asset)) {
                    $assets[] = $asset;
                }
            }
        }

        return $assets;
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     */
    private function firstAssetId(array $assets): ?string {
        foreach ($assets as $asset) {
            $assetId = $this->assetIdFromResponse($asset);
            if ($assetId !== null) {
                return $assetId;
            }
        }

        return null;
    }

    private function personIdFromResponse(array $person): ?string {
        if (isset($person['id']) && is_string($person['id']) && $person['id'] !== '') {
            return $person['id'];
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
}
