<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\PeopleController;
use OCA\IntegrationImmich\Service\BrowsingAuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class PeopleControllerOwnershipTest extends TestCase {
    private const PERSON_ONE = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private const PERSON_TWO = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    private const ASSET_ONE = '11111111-1111-1111-1111-111111111111';
    private const ASSET_TWO = '22222222-2222-2222-2222-222222222222';

    private BrowsingAuthService&MockObject $browsingAuthService;
    private IClientService&MockObject $clientService;
    private IClient&MockObject $client;
    private PeopleController $controller;

    protected function setUp(): void {
        parent::setUp();

        $this->browsingAuthService = $this->createMock(BrowsingAuthService::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->client = $this->createMock(IClient::class);

        $this->controller = new PeopleController(
            $this->createMock(IRequest::class),
            $this->clientService,
            $this->browsingAuthService,
            'alice',
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testPersonalModeDoesNotApplyAdminProxyOwnershipFiltering(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->browsingAuthService->expects($this->never())->method('assetBelongsToUser');
        $this->browsingAuthService->expects($this->never())->method('assertAssetOwnership');
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse([
                ['id' => self::PERSON_ONE, 'name' => 'Alice'],
                ['id' => self::PERSON_TWO, 'name' => 'Bob'],
            ]));

        $response = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(2, $response->getData());
    }

    public function testAdminProxyPeopleListFiltersForeignPeopleByOwnedAssets(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assetBelongsToUser')
            ->willReturnCallback(fn(array $asset, string $immichUserId): bool => ($asset['ownerId'] ?? '') === $immichUserId);
        $this->browsingAuthService->method('assertAssetOwnership')->willReturn(false);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->exactly(5))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->jsonResponse([
                    ['id' => self::PERSON_ONE, 'name' => 'Alice'],
                    ['id' => self::PERSON_TWO, 'name' => 'Bob'],
                ]),
                $this->jsonResponse([['timeBucket' => '2024-01-01T00:00:00.000Z']]),
                $this->jsonResponse([['id' => self::ASSET_ONE, 'ownerId' => 'immich-alice']]),
                $this->jsonResponse([['timeBucket' => '2024-02-01T00:00:00.000Z']]),
                $this->jsonResponse([['id' => self::ASSET_TWO, 'ownerId' => 'immich-bob']]),
            );

        $response = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([['id' => self::PERSON_ONE, 'name' => 'Alice']], $response->getData());
    }

    public function testAdminProxyAssetsReturnsForbiddenForForeignPerson(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assetBelongsToUser')
            ->willReturnCallback(fn(array $asset, string $immichUserId): bool => ($asset['ownerId'] ?? '') === $immichUserId);
        $this->browsingAuthService->method('assertAssetOwnership')->willReturn(false);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->jsonResponse([['timeBucket' => '2024-02-01T00:00:00.000Z']]),
                $this->jsonResponse([['id' => self::ASSET_TWO, 'ownerId' => 'immich-bob']]),
            );

        $response = $this->controller->assets(self::PERSON_TWO);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testAdminProxyAssetsReturnsForbiddenForMixedOwnerPerson(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assetBelongsToUser')
            ->willReturnCallback(fn(array $asset, string $immichUserId): bool => ($asset['ownerId'] ?? '') === $immichUserId);
        $this->browsingAuthService->method('assertAssetOwnership')->willReturn(false);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->jsonResponse([['timeBucket' => '2024-01-01T00:00:00.000Z']]),
                $this->jsonResponse([
                    ['id' => self::ASSET_ONE, 'ownerId' => 'immich-alice'],
                    ['id' => self::ASSET_TWO, 'ownerId' => 'immich-bob'],
                ]),
            );

        $response = $this->controller->assets(self::PERSON_ONE);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testAdminProxyAssetsReturnsForbiddenWhenOwnershipMetadataIsMissingAndLookupFails(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assetBelongsToUser')->willReturn(false);
        $this->browsingAuthService->method('assertAssetOwnership')->with('immich-alice', self::ASSET_ONE)->willReturn(false);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->jsonResponse([['timeBucket' => '2024-01-01T00:00:00.000Z']]),
                $this->jsonResponse([['id' => self::ASSET_ONE]]),
            );

        $response = $this->controller->assets(self::PERSON_ONE);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testAdminProxyPeopleListHidesMixedOwnerPerson(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assetBelongsToUser')
            ->willReturnCallback(fn(array $asset, string $immichUserId): bool => ($asset['ownerId'] ?? '') === $immichUserId);
        $this->browsingAuthService->method('assertAssetOwnership')->willReturn(false);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->exactly(3))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->jsonResponse([['id' => self::PERSON_ONE, 'name' => 'Mixed']]),
                $this->jsonResponse([['timeBucket' => '2024-01-01T00:00:00.000Z']]),
                $this->jsonResponse([
                    ['id' => self::ASSET_ONE, 'ownerId' => 'immich-alice'],
                    ['id' => self::ASSET_TWO, 'ownerId' => 'immich-bob'],
                ]),
            );

        $response = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([], $response->getData());
    }

    public function testAdminProxyThumbnailReturnsForbiddenForForeignPersonBeforeStreaming(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assetBelongsToUser')
            ->willReturnCallback(fn(array $asset, string $immichUserId): bool => ($asset['ownerId'] ?? '') === $immichUserId);
        $this->browsingAuthService->method('assertAssetOwnership')->willReturn(false);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->jsonResponse([['timeBucket' => '2024-02-01T00:00:00.000Z']]),
                $this->jsonResponse([['id' => self::ASSET_TWO, 'ownerId' => 'immich-bob']]),
            );

        $response = $this->controller->thumbnail(self::PERSON_TWO);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testAdminProxyThumbnailReturnsForbiddenForMixedOwnerPersonBeforeStreaming(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assetBelongsToUser')
            ->willReturnCallback(fn(array $asset, string $immichUserId): bool => ($asset['ownerId'] ?? '') === $immichUserId);
        $this->browsingAuthService->method('assertAssetOwnership')->willReturn(false);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->jsonResponse([['timeBucket' => '2024-01-01T00:00:00.000Z']]),
                $this->jsonResponse([
                    ['id' => self::ASSET_ONE, 'ownerId' => 'immich-alice'],
                    ['id' => self::ASSET_TWO, 'ownerId' => 'immich-bob'],
                ]),
            );

        $response = $this->controller->thumbnail(self::PERSON_ONE);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testAdminProxyThumbnailStreamsOnlyAfterOwnedPersonAssetIsFound(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assetBelongsToUser')
            ->willReturnCallback(fn(array $asset, string $immichUserId): bool => ($asset['ownerId'] ?? '') === $immichUserId);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->exactly(3))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->jsonResponse([['timeBucket' => '2024-01-01T00:00:00.000Z']]),
                $this->jsonResponse([['id' => self::ASSET_ONE, 'ownerId' => 'immich-alice']]),
                $this->binaryResponse('image-bytes', 'image/jpeg'),
            );

        $response = $this->controller->thumbnail(self::PERSON_ONE);

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
    }

    public function testThumbnailUsesProvisionedUserApiKeyWithoutLegacyOwnershipFiltering(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminManagedUserCredentials());
        $this->browsingAuthService->expects($this->never())->method('assetBelongsToUser');
        $this->browsingAuthService->expects($this->never())->method('assertAssetOwnership');
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->binaryResponse('image-bytes', 'image/jpeg'));

        $response = $this->controller->thumbnail(self::PERSON_ONE);

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
    }

    private function personalCredentials(): array {
        return [
            'mode' => BrowsingAuthService::MODE_PERSONAL,
            'url' => 'https://personal.example.com',
            'apiKey' => 'personal-key',
            'immichUserId' => null,
        ];
    }

    private function adminProxyCredentials(): array {
        return [
            'mode' => BrowsingAuthService::MODE_ADMIN_PROXY,
            'url' => 'https://admin.example.com',
            'apiKey' => 'admin-key',
            'immichUserId' => 'immich-alice',
        ];
    }

    private function adminManagedUserCredentials(): array {
        return [
            'mode' => BrowsingAuthService::MODE_ADMIN_PROXY,
            'url' => 'https://admin.example.com',
            'apiKey' => 'user-key',
            'immichUserId' => 'immich-alice',
            'usesUserApiKey' => true,
        ];
    }

    private function jsonResponse(array $body): IResponse&MockObject {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));
        $response->method('getHeader')->willReturn('');
        return $response;
    }

    private function binaryResponse(string $body, string $contentType): IResponse&MockObject {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn($body);
        $response->method('getHeader')->with('Content-Type')->willReturn($contentType);
        return $response;
    }
}
