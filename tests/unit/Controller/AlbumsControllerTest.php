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

class AlbumsControllerTest extends TestCase {

    private AlbumsController $controller;
    private BrowsingAuthService&MockObject $browsingAuthService;
    private IClientService&MockObject $clientService;
    private IClient&MockObject $client;
    private IRequest&MockObject $request;

    private const VALID_UUID = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
    private const INVALID_UUID = 'not-a-valid-uuid';

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
            'testuser',
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testIndexReturns412WhenNotConfigured(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->unavailableCredentials());
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller->index();

        $this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('browsing_setup_not_configured', $data['code']);
        $this->assertSame('browsing_setup_personal_or_admin_proxy', $data['setupCode']);
        $this->assertSame('browsing_setup_not_configured', $data['details']['code']);
    }

    public function testIndexReturns400OnInvalidAssetId(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->request->method('getParam')->with('assetId', '')->willReturn(self::INVALID_UUID);

        $response = $this->controller->index();

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testIndexReturnsAlbums(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->request->method('getParam')->with('assetId', '')->willReturn('');
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse([['id' => self::VALID_UUID, 'albumName' => 'Test']]));

        $response = $this->controller->index();

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $response->getData());
    }

    public function testShowReturns400OnInvalidId(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());

        $response = $this->controller->show(self::INVALID_UUID);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testShowReturnsAlbum(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse(['id' => self::VALID_UUID]));

        $response = $this->controller->show(self::VALID_UUID);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
    }

    public function testCreateReturns400WhenAlbumNameEmpty(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->request->method('getParam')->willReturnMap([
            ['albumName', '', '   '],
            ['assetIds', [], []],
        ]);

        $response = $this->controller->create();

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testCreateReturns400OnInvalidAssetId(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->request->method('getParam')->willReturnMap([
            ['albumName', '', 'My Album'],
            ['assetIds', [], [self::INVALID_UUID]],
        ]);

        $response = $this->controller->create();

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testCreateReturns201OnSuccess(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->request->method('getParam')->willReturnMap([
            ['albumName', '', 'My Album'],
            ['assetIds', [], []],
        ]);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('post')
            ->willReturn($this->jsonResponse(['id' => self::VALID_UUID]));

        $response = $this->controller->create();

        $this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
    }

    public function testDeleteReturns400OnInvalidId(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());

        $response = $this->controller->delete(self::INVALID_UUID);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testDeleteReturnsSuccess(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('delete')
            ->willReturn($this->jsonResponse([]));

        $response = $this->controller->delete(self::VALID_UUID);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
    }

    public function testRenameReturns400WhenNameEmpty(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->request->method('getParam')->with('albumName', '')->willReturn('');

        $response = $this->controller->rename(self::VALID_UUID);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testRenameSucceeds(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->request->method('getParam')->with('albumName', '')->willReturn('New Name');
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('patch')
            ->willReturn($this->jsonResponse(['id' => self::VALID_UUID, 'albumName' => 'New Name']));

        $response = $this->controller->rename(self::VALID_UUID);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
    }

    public function testRemoveAssetsReturns400WhenArrayEmpty(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->request->method('getParam')->with('assetIds', [])->willReturn([]);

        $response = $this->controller->removeAssets(self::VALID_UUID);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testRemoveAssetsReturns400OnInvalidAssetId(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->request->method('getParam')->with('assetIds', [])->willReturn([self::INVALID_UUID]);

        $response = $this->controller->removeAssets(self::VALID_UUID);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testAddAssetsReturns400WhenArrayEmpty(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->request->method('getParam')->with('assetIds', [])->willReturn([]);

        $response = $this->controller->addAssets(self::VALID_UUID);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testAddAssetsSucceeds(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->request->method('getParam')->with('assetIds', [])->willReturn([self::VALID_UUID]);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('put')
            ->willReturn($this->jsonResponse([['success' => true]]));

        $response = $this->controller->addAssets(self::VALID_UUID);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
    }

    private function personalCredentials(): array {
        return [
            'mode' => BrowsingAuthService::MODE_PERSONAL,
            'url' => 'https://photos.example.com',
            'apiKey' => 'personal-key',
            'immichUserId' => null,
        ];
    }

    private function unavailableCredentials(): array {
        return [
            'mode' => BrowsingAuthService::MODE_UNAVAILABLE,
            'url' => '',
            'apiKey' => null,
            'immichUserId' => null,
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
