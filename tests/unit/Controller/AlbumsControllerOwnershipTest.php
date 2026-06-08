<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\AlbumsController;
use OCA\IntegrationImmich\Service\BrowsingAuthService;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class AlbumsControllerOwnershipTest extends TestCase {
    private const ALBUM_ONE = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private const ALBUM_TWO = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    private const ASSET_ONE = '11111111-1111-1111-1111-111111111111';
    private const ASSET_TWO = '22222222-2222-2222-2222-222222222222';

    private BrowsingAuthService&MockObject $browsingAuthService;
    private IClientService&MockObject $clientService;
    private IClient&MockObject $client;
    private IRequest&MockObject $request;
    private AlbumsController $controller;

    protected function setUp(): void {
        parent::setUp();

        $this->browsingAuthService = $this->createMock(BrowsingAuthService::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->client = $this->createMock(IClient::class);
        $this->request = $this->createMock(IRequest::class);

        $this->controller = new AlbumsController(
            $this->request,
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
        $this->request->method('getParam')->with('assetId', '')->willReturn('');
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse([
                ['id' => self::ALBUM_ONE, 'ownerId' => 'immich-bob', 'albumName' => 'Server scoped by personal key'],
            ]));

        $response = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $response->getData());
    }

    public function testAdminProxyAlbumListFiltersForeignOwners(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->request->method('getParam')->with('assetId', '')->willReturn('');
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse([
                ['id' => self::ALBUM_ONE, 'ownerId' => 'immich-alice', 'albumName' => 'Alice'],
                ['id' => self::ALBUM_TWO, 'ownerId' => 'immich-bob', 'albumName' => 'Bob'],
            ]));

        $response = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([['id' => self::ALBUM_ONE, 'ownerId' => 'immich-alice', 'albumName' => 'Alice']], $response->getData());
    }

    public function testAdminProxyShowFiltersForeignAssetsFromOwnedAlbum(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assetBelongsToUser')
            ->willReturnCallback(fn(array $asset, string $immichUserId): bool => ($asset['ownerId'] ?? '') === $immichUserId);
        $this->browsingAuthService->method('assertAssetOwnership')->willReturn(false);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse([
                'id' => self::ALBUM_ONE,
                'ownerId' => 'immich-alice',
                'sharedUsers' => [['id' => 'immich-bob']],
                'assets' => [
                    ['id' => self::ASSET_ONE, 'ownerId' => 'immich-alice'],
                    ['id' => self::ASSET_TWO, 'ownerId' => 'immich-bob'],
                ],
            ]));

        $response = $this->controller->show(self::ALBUM_ONE);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([['id' => self::ASSET_ONE, 'ownerId' => 'immich-alice']], $response->getData()['assets']);
        $this->assertArrayNotHasKey('sharedUsers', $response->getData());
    }

    public function testAdminProxyShowReturnsForbiddenForForeignAlbum(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse(['id' => self::ALBUM_TWO, 'ownerId' => 'immich-bob']));

        $response = $this->controller->show(self::ALBUM_TWO);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testAdminProxyCreateRejectsMixedOwnerAssetIdsBeforeMutating(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->expects($this->exactly(2))
            ->method('assertAssetOwnership')
            ->willReturnMap([
                ['immich-alice', self::ASSET_ONE, true],
                ['immich-alice', self::ASSET_TWO, false],
            ]);
        $this->request->method('getParam')->willReturnMap([
            ['albumName', '', 'Family'],
            ['assetIds', [], [self::ASSET_ONE, self::ASSET_TWO]],
        ]);
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller->create();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testAdminProxyAddRejectsMixedOwnerAssetIdsBeforeMutating(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->expects($this->exactly(2))
            ->method('assertAssetOwnership')
            ->willReturnMap([
                ['immich-alice', self::ASSET_ONE, true],
                ['immich-alice', self::ASSET_TWO, false],
            ]);
        $this->request->method('getParam')->with('assetIds', [])->willReturn([self::ASSET_ONE, self::ASSET_TWO]);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse(['id' => self::ALBUM_ONE, 'ownerId' => 'immich-alice']));
        $this->client->expects($this->never())->method('put');

        $response = $this->controller->addAssets(self::ALBUM_ONE);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testAdminProxyRemoveRejectsMixedOwnerAssetIdsBeforeMutating(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->expects($this->exactly(2))
            ->method('assertAssetOwnership')
            ->willReturnMap([
                ['immich-alice', self::ASSET_ONE, true],
                ['immich-alice', self::ASSET_TWO, false],
            ]);
        $this->request->method('getParam')->with('assetIds', [])->willReturn([self::ASSET_ONE, self::ASSET_TWO]);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse(['id' => self::ALBUM_ONE, 'ownerId' => 'immich-alice']));
        $this->client->expects($this->never())->method('delete');

        $response = $this->controller->removeAssets(self::ALBUM_ONE);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testAdminProxyDeleteReturnsForbiddenForForeignAlbumBeforeMutating(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse(['id' => self::ALBUM_TWO, 'ownerId' => 'immich-bob']));
        $this->client->expects($this->never())->method('delete');

        $response = $this->controller->delete(self::ALBUM_TWO);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testAdminProxyRenameReturnsForbiddenForForeignAlbumBeforeMutating(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->request->method('getParam')->with('albumName', '')->willReturn('Rename');
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse(['id' => self::ALBUM_TWO, 'ownerId' => 'immich-bob']));
        $this->client->expects($this->never())->method('patch');

        $response = $this->controller->rename(self::ALBUM_TWO);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testAdminProxyThumbnailReturnsForbiddenForForeignAlbum(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse(['id' => self::ALBUM_TWO, 'ownerId' => 'immich-bob', 'albumThumbnailAssetId' => self::ASSET_TWO]));

        $response = $this->controller->thumbnail(self::ALBUM_TWO);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
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

    private function jsonResponse(array $body): IResponse&MockObject {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));
        $response->method('getHeader')->willReturn('');
        return $response;
    }
}
