<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\AssetsController;
use OCA\IntegrationImmich\Service\ActionPolicyService;
use OCA\IntegrationImmich\Service\BrowsingAuthService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class AssetsControllerSafetyTest extends TestCase {
    private const ASSET_ID = '11111111-1111-1111-1111-111111111111';

    private IRequest&MockObject $request;
    private IClientService&MockObject $clientService;
    private IClient&MockObject $client;
    private BrowsingAuthService&MockObject $browsingAuthService;
    private IRootFolder&MockObject $rootFolder;
    private ActionPolicyService&MockObject $actionPolicyService;
    private AssetsController $controller;

    protected function setUp(): void {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->client = $this->createMock(IClient::class);
        $this->browsingAuthService = $this->createMock(BrowsingAuthService::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->actionPolicyService = $this->createMock(ActionPolicyService::class);
        $this->browsingAuthService->method('resolveCredentials')->with('alice')->willReturn($this->personalCredentials());

        $this->controller = new AssetsController(
            $this->request,
            $this->clientService,
            $this->browsingAuthService,
            $this->rootFolder,
            'alice',
            $this->actionPolicyService,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testExportToNormalFolderAllowedWhenEnabled(): void {
        $this->request->method('getParam')->willReturnMap([
            ['assetIds', [], [self::ASSET_ID]],
            ['path', '', '/Exports'],
        ]);
        $this->actionPolicyService->method('isExportCopyEnabled')->willReturn(true);
        $this->actionPolicyService->method('isPathInsideMirrorMount')->with('alice', 'Exports')->willReturn(false);

        $userFolder = $this->createMock(Folder::class);
        $targetFolder = $this->createMock(Folder::class);
        $writtenFile = $this->createMock(File::class);
        $this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
        $userFolder->method('get')->with('Exports')->willReturn($targetFolder);
        $targetFolder->method('nodeExists')->with('photo.jpg')->willReturn(false);
        $targetFolder->expects($this->once())->method('newFile')->with('photo.jpg')->willReturn($writtenFile);
        $writtenFile->expects($this->once())->method('putContent')->with('binary-data');

        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->exactly(2))->method('get')->willReturnOnConsecutiveCalls(
            $this->jsonResponse(['id' => self::ASSET_ID, 'originalFileName' => 'photo.jpg']),
            $this->binaryResponse('binary-data', 'image/jpeg'),
        );

        $response = $this->controller->saveToNextcloud();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(1, $response->getData()['saved']);
        $this->assertSame(0, $response->getData()['failed']);
    }

    public function testExportToMirrorMountRejectedBeforeFolderWrite(): void {
        $this->request->method('getParam')->willReturnMap([
            ['assetIds', [], [self::ASSET_ID]],
            ['path', '', '/Immich Photos'],
        ]);
        $this->actionPolicyService->method('isExportCopyEnabled')->willReturn(true);
        $this->actionPolicyService->method('isPathInsideMirrorMount')->with('alice', 'Immich Photos')->willReturn(true);

        $this->rootFolder->expects($this->never())->method('getUserFolder');
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller->saveToNextcloud();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertStringContainsString('mirror mount', $response->getData()['error']);
    }

    public function testExportDisabledRejectedBeforeFolderLookupOrImmichRequest(): void {
        $this->request->method('getParam')->willReturnMap([
            ['assetIds', [], [self::ASSET_ID]],
            ['path', '', '/Exports'],
        ]);
        $this->actionPolicyService->method('isExportCopyEnabled')->willReturn(false);
        $this->rootFolder->expects($this->never())->method('getUserFolder');
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller->saveToNextcloud();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertStringContainsString('Export copy to Nextcloud is disabled', $response->getData()['error']);
    }

    public function testDeleteDisabledReturnsForbiddenBeforeImmichRequest(): void {
        $this->request->method('getParam')->with('assetIds', [])->willReturn([self::ASSET_ID]);
        $this->actionPolicyService->method('isDeleteEnabled')->willReturn(false);
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller->deleteAssets();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertStringContainsString('Delete from Immich is disabled', $response->getData()['error']);
    }

    public function testDeleteEnabledAllowsImmichDeleteRequest(): void {
        $this->request->method('getParam')->with('assetIds', [])->willReturn([self::ASSET_ID]);
        $this->actionPolicyService->method('isDeleteEnabled')->willReturn(true);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())->method('delete')->willReturn($this->jsonResponse([]));

        $response = $this->controller->deleteAssets();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
    }

    private function personalCredentials(): array {
        return [
            'mode' => BrowsingAuthService::MODE_PERSONAL,
            'url' => 'https://photos.example.com',
            'apiKey' => 'personal-key',
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

    private function binaryResponse(string $body, string $contentType): IResponse&MockObject {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn($body);
        $response->method('getHeader')->with('Content-Type')->willReturn($contentType);
        return $response;
    }
}
