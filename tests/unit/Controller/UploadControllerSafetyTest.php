<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\UploadController;
use OCA\IntegrationImmich\Service\ActionPolicyService;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class UploadControllerSafetyTest extends TestCase {
    private IRequest&MockObject $request;
    private ImmichService&MockObject $immichService;
    private IRootFolder&MockObject $rootFolder;
    private ActionPolicyService&MockObject $actionPolicyService;
    private UploadController $controller;

    protected function setUp(): void {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->immichService = $this->createMock(ImmichService::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->actionPolicyService = $this->createMock(ActionPolicyService::class);

        $this->controller = new UploadController(
            $this->request,
            $this->immichService,
            $this->rootFolder,
            'alice',
            $this->actionPolicyService,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testImportNormalFileAllowedWhenEnabled(): void {
        $this->actionPolicyService->method('isImportToImmichEnabled')->willReturn(true);
        $this->actionPolicyService->method('isPathInsideMirrorMount')->with('alice', '/alice/files/Photos/photo.jpg')->willReturn(false);
        $this->immichService->method('isConfigured')->willReturn(true);
        $this->request->method('getParam')->with('fileId')->willReturn('42');

        $stream = fopen('php://memory', 'rb+');
        fwrite($stream, 'binary-data');
        rewind($stream);

        $file = $this->createMock(File::class);
        $file->method('getPath')->willReturn('/alice/files/Photos/photo.jpg');
        $file->method('getName')->willReturn('photo.jpg');
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getCreationTime')->willReturn(1700000000);
        $file->method('getMTime')->willReturn(1700000001);
        $file->method('fopen')->with('rb')->willReturn($stream);

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->with(42)->willReturn([$file]);
        $this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
        $this->immichService->expects($this->once())->method('uploadAsset')->willReturn(['id' => 'new-asset']);

        $response = $this->controller->upload();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('new-asset', $response->getData()['id']);
    }

    public function testImportDisabledRejectedBeforeConfigOrFileLookup(): void {
        $this->actionPolicyService->method('isImportToImmichEnabled')->willReturn(false);
        $this->immichService->expects($this->never())->method('isConfigured');
        $this->rootFolder->expects($this->never())->method('getUserFolder');

        $response = $this->controller->upload();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertStringContainsString('Import to Immich is disabled', $response->getData()['error']);
    }

    public function testImportMirrorFileRejectedBeforeImmichUpload(): void {
        $this->actionPolicyService->method('isImportToImmichEnabled')->willReturn(true);
        $this->actionPolicyService->method('isPathInsideMirrorMount')->with('alice', '/alice/files/Immich Photos/photo.jpg')->willReturn(true);
        $this->immichService->method('isConfigured')->willReturn(true);
        $this->request->method('getParam')->with('fileId')->willReturn('42');

        $file = $this->createMock(File::class);
        $file->method('getPath')->willReturn('/alice/files/Immich Photos/photo.jpg');
        $file->expects($this->never())->method('fopen');

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->with(42)->willReturn([$file]);
        $this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
        $this->immichService->expects($this->never())->method('uploadAsset');

        $response = $this->controller->upload();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertStringContainsString('mirror mount', $response->getData()['error']);
    }
}
