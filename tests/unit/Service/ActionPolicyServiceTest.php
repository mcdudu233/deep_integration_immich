<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Service;

use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\ActionPolicyService;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\PathTemplateService;
use OCA\IntegrationImmich\Service\SyncStateService;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class ActionPolicyServiceTest extends TestCase {
    private AdminConfigService&MockObject $adminConfigService;
    private SyncStateService&MockObject $syncStateService;
    private ActionPolicyService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->adminConfigService = $this->createMock(AdminConfigService::class);
        $this->syncStateService = $this->createMock(SyncStateService::class);
        $this->service = new ActionPolicyService(
            $this->adminConfigService,
            new PathTemplateService(),
            $this->syncStateService,
        );
    }

    public function testCapabilityFlagsDefaultToConfiguredPolicyValues(): void {
        $this->adminConfigService->method('isExportCopyEnabled')->willReturn(true);
        $this->adminConfigService->method('isImportToImmichEnabled')->willReturn(false);
        $this->adminConfigService->method('isImmichDeleteEnabled')->willReturn(true);
        $this->adminConfigService->method('getAdminConfig')->willReturn([
            AdminConfigService::KEY_MOUNT_NAME_TEMPLATE => 'Immich/{uid}',
            AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE => '/mnt/immich-library/{storageLabel}',
        ]);
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'alice'));

        $flags = $this->service->getCapabilityFlags('alice');

        $this->assertTrue($flags['exportCopyEnabled']);
        $this->assertFalse($flags['importToImmichEnabled']);
        $this->assertTrue($flags['immichDeleteEnabled']);
        $this->assertSame(['/Immich/alice'], $flags['mirrorMountPaths']);
    }

    public function testDetectsPathsInsideUserMirrorMount(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn([
            AdminConfigService::KEY_MOUNT_NAME_TEMPLATE => 'Immich Photos',
            AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE => '/mnt/immich-library/{storageLabel}',
        ]);
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($this->state('alice', 'alice'));

        $this->assertTrue($this->service->isPathInsideMirrorMount('alice', '/Immich Photos'));
        $this->assertTrue($this->service->isPathInsideMirrorMount('alice', '/alice/files/Immich Photos/2026/photo.jpg'));
        $this->assertTrue($this->service->isPathInsideMirrorMount('alice', '/mnt/immich-library/alice/2026/photo.jpg'));
        $this->assertFalse($this->service->isPathInsideMirrorMount('alice', '/Photos/2026/photo.jpg'));
        $this->assertFalse($this->service->isPathInsideMirrorMount('alice', '/Immich Photos Backup/photo.jpg'));
    }

    public function testMirrorDetectionFallsBackToSanitizedUidWhenMappingIsMissing(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn([
            AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE => '{uid}',
            AdminConfigService::KEY_MOUNT_NAME_TEMPLATE => 'Immich/{uid}',
            AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE => '/mnt/immich-library/{storageLabel}',
        ]);
        $this->syncStateService->method('findByUid')->with('alice')->willReturn(null);

        $this->assertTrue($this->service->isPathInsideMirrorMount('alice', '/alice/files/Immich/alice/photo.jpg'));
        $this->assertTrue($this->service->isPathInsideMirrorMount('alice', '/mnt/immich-library/alice/photo.jpg'));
    }

    public function testMirrorDetectionUsesStorageLabelTemplateWhenMappingIsMissing(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn([
            AdminConfigService::KEY_STORAGE_LABEL_TEMPLATE => 'library-{uid}',
            AdminConfigService::KEY_MOUNT_NAME_TEMPLATE => 'Immich/{storageLabel}',
            AdminConfigService::KEY_NC_VISIBLE_PATH_TEMPLATE => '/mnt/immich-library/{storageLabel}',
        ]);
        $this->syncStateService->method('findByUid')->with('alice')->willReturn(null);

        $this->assertTrue($this->service->isPathInsideMirrorMount('alice', '/alice/files/Immich/library-alice/photo.jpg'));
        $this->assertTrue($this->service->isPathInsideMirrorMount('alice', '/mnt/immich-library/library-alice/photo.jpg'));
        $this->assertFalse($this->service->isPathInsideMirrorMount('alice', '/mnt/immich-library/alice/photo.jpg'));
    }

    private function state(string $uid, string $storageLabel): SyncState {
        $state = new SyncState();
        $state->setNcUid($uid);
        $state->setStorageLabel($storageLabel);
        return $state;
    }
}
