<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\AssetsController;
use OCA\IntegrationImmich\Service\ActionPolicyService;
use OCA\IntegrationImmich\Service\BrowsingAuthService;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class AssetsControllerOwnershipTest extends TestCase {
    private const ASSET_ONE = '11111111-1111-1111-1111-111111111111';
    private const ASSET_TWO = '22222222-2222-2222-2222-222222222222';

    private BrowsingAuthService&MockObject $browsingAuthService;
    private IClientService&MockObject $clientService;
    private IClient&MockObject $client;
    private IRootFolder&MockObject $rootFolder;
    private IRequest&MockObject $request;
    private ActionPolicyService&MockObject $actionPolicyService;
    private AssetsController $controller;

    protected function setUp(): void {
        parent::setUp();

        $this->browsingAuthService = $this->createMock(BrowsingAuthService::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->client = $this->createMock(IClient::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->request = $this->createMock(IRequest::class);
        $this->actionPolicyService = $this->createMock(ActionPolicyService::class);
        $this->actionPolicyService->method('isExportCopyEnabled')->willReturn(true);
        $this->actionPolicyService->method('isDeleteEnabled')->willReturn(true);
        $this->actionPolicyService->method('isPathInsideMirrorMount')->willReturn(false);

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

    public function testMissingMappingReturnsPreconditionFailure(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->unavailableCredentials());
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller->show(self::ASSET_ONE);

        $this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
        $this->assertArrayHasKey('setup', $response->getData());
    }

    public function testPersonalModePreservesExistingApiKeyBrowsingWithoutMappingOwnershipCheck(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->browsingAuthService->expects($this->never())->method('assetBelongsToUser');
        $this->browsingAuthService->expects($this->never())->method('assertAssetOwnership');
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse(['id' => self::ASSET_ONE, 'ownerId' => 'not-checked-in-personal-mode']));

        $response = $this->controller->show(self::ASSET_ONE);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(self::ASSET_ONE, $response->getData()['id']);
    }

    public function testShowReturnsForbiddenForForeignAdminProxyAsset(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assetBelongsToUser')->willReturn(false);
        $this->browsingAuthService->method('assertAssetOwnership')->with('immich-alice', self::ASSET_ONE)->willReturn(false);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse(['id' => self::ASSET_ONE, 'ownerId' => 'immich-bob']));

        $response = $this->controller->show(self::ASSET_ONE);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testTimelineBucketFiltersAdminProxyAssetsToMappedOwner(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assetBelongsToUser')
            ->willReturnCallback(fn(array $asset, string $immichUserId): bool => ($asset['ownerId'] ?? '') === $immichUserId);
        $this->browsingAuthService->expects($this->never())->method('assertAssetOwnership');
        $this->request->method('getParam')->willReturnMap([
            ['timeBucket', null, '2024-01-01T00:00:00.000Z'],
            ['size', 'MONTH', 'MONTH'],
            ['personId', null, null],
            ['assetType', null, null],
            ['isFavorite', null, null],
        ]);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse([
                ['id' => self::ASSET_ONE, 'ownerId' => 'immich-alice', 'isImage' => true],
                ['id' => self::ASSET_TWO, 'ownerId' => 'immich-bob', 'isImage' => true],
            ]));

        $response = $this->controller->timeline();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([[ 'id' => self::ASSET_ONE, 'ownerId' => 'immich-alice', 'isImage' => true ]], $response->getData());
    }

    public function testTimelineBucketSummariesAreRecountedAfterAdminProxyOwnershipFiltering(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assetBelongsToUser')
            ->willReturnCallback(fn(array $asset, string $immichUserId): bool => ($asset['ownerId'] ?? '') === $immichUserId);
        $this->request->method('getParam')->willReturnMap([
            ['timeBucket', null, null],
            ['size', 'MONTH', 'MONTH'],
            ['personId', null, null],
            ['assetType', null, null],
            ['isFavorite', null, null],
        ]);
        $this->clientService->expects($this->exactly(3))->method('newClient')->willReturn($this->client);
        $this->client->expects($this->exactly(3))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->jsonResponse([
                    ['timeBucket' => '2024-01-01T00:00:00.000Z', 'count' => 99],
                    ['timeBucket' => '2024-02-01T00:00:00.000Z', 'count' => 99],
                ]),
                $this->jsonResponse([
                    ['id' => self::ASSET_ONE, 'ownerId' => 'immich-alice'],
                    ['id' => self::ASSET_TWO, 'ownerId' => 'immich-bob'],
                ]),
                $this->jsonResponse([
                    ['id' => self::ASSET_TWO, 'ownerId' => 'immich-bob'],
                ]),
            );

        $response = $this->controller->timeline();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([
            ['timeBucket' => '2024-01-01T00:00:00.000Z', 'count' => 1],
        ], $response->getData());
    }

    public function testThumbnailChecksOwnershipBeforeStreamingBinary(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assertAssetOwnership')->with('immich-alice', self::ASSET_ONE)->willReturn(false);
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller->thumbnail(self::ASSET_ONE);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testOriginalChecksOwnershipBeforeStreamingBinary(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assertAssetOwnership')->with('immich-alice', self::ASSET_ONE)->willReturn(false);
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller->original(self::ASSET_ONE);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testVideoStreamChecksOwnershipBeforeStreamingBinary(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assertAssetOwnership')->with('immich-alice', self::ASSET_ONE)->willReturn(false);
        $this->request->expects($this->never())->method('getHeader');
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller->videoStream(self::ASSET_ONE);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testDownloadChecksEveryAssetBeforeArchiveRequest(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->expects($this->exactly(2))
            ->method('assertAssetOwnership')
            ->willReturnMap([
                ['immich-alice', self::ASSET_ONE, true],
                ['immich-alice', self::ASSET_TWO, false],
            ]);
        $this->request->method('getParam')->with('assetIds', [])->willReturn([self::ASSET_ONE, self::ASSET_TWO]);
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller->downloadAssets();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testDeleteChecksEveryAssetBeforeDeleteRequest(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->expects($this->exactly(2))
            ->method('assertAssetOwnership')
            ->willReturnMap([
                ['immich-alice', self::ASSET_ONE, true],
                ['immich-alice', self::ASSET_TWO, false],
            ]);
        $this->request->method('getParam')->with('assetIds', [])->willReturn([self::ASSET_ONE, self::ASSET_TWO]);
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller->deleteAssets();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testUpdateChecksOwnershipBeforeMutating(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assertAssetOwnership')->with('immich-alice', self::ASSET_ONE)->willReturn(false);
        $this->request->method('getParams')->willReturn(['isFavorite' => true]);
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller->update(self::ASSET_ONE);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testSaveToNextcloudChecksAllOwnershipBeforeTargetFolderLookup(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->adminProxyCredentials());
        $this->browsingAuthService->method('assertAssetOwnership')->with('immich-alice', self::ASSET_ONE)->willReturn(false);
        $this->request->method('getParam')->willReturnMap([
            ['assetIds', [], [self::ASSET_ONE]],
            ['path', '', '/Photos'],
        ]);
        $this->rootFolder->expects($this->never())->method('getUserFolder');
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller->saveToNextcloud();

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
