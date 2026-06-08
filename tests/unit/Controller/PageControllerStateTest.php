<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\IntegrationImmich\Controller\PageController;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\ActionPolicyService;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\CapabilityService;
use OCA\IntegrationImmich\Service\ExternalStorageProvisioner;
use OCA\IntegrationImmich\Service\FrontendInitialStateService;
use OCA\IntegrationImmich\Service\QuotaSyncService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class PageControllerStateTest extends TestCase {
    private AdminConfigService&MockObject $adminConfigService;
    private SyncStateService&MockObject $syncStateService;
    private CapabilityService&MockObject $capabilityService;
    private ActionPolicyService&MockObject $actionPolicyService;
    private ExternalStorageProvisioner&MockObject $externalStorageProvisioner;
    private QuotaSyncService&MockObject $quotaSyncService;
    private IInitialState&MockObject $initialState;
    private IRequest&MockObject $request;

    protected function setUp(): void {
        parent::setUp();

        $this->adminConfigService = $this->createMock(AdminConfigService::class);
        $this->syncStateService = $this->createMock(SyncStateService::class);
        $this->capabilityService = $this->createMock(CapabilityService::class);
        $this->actionPolicyService = $this->createMock(ActionPolicyService::class);
        $this->externalStorageProvisioner = $this->createMock(ExternalStorageProvisioner::class);
        $this->quotaSyncService = $this->createMock(QuotaSyncService::class);
        $this->initialState = $this->createMock(IInitialState::class);
        $this->request = $this->createMock(IRequest::class);

        $this->adminConfigService->method('getAdminConfig')->willReturn($this->adminConfig());
        $this->actionPolicyService->method('getCapabilityFlags')->willReturn([
            'exportCopyEnabled' => false,
            'importToImmichEnabled' => false,
            'immichDeleteEnabled' => false,
            'mirrorMountPaths' => [],
        ]);
        $this->quotaSyncService->method('getLastError')->willReturn(null);
        $this->quotaSyncService->method('wasLastQuotaUnlimited')->willReturn(false);
    }

    public function testIndexProvidesSafeMappedUserState(): void {
        $state = $this->syncState('alice', 'immich-alice', 'alice');
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
        $this->externalStorageProvisioner->expects($this->once())->method('verifyMount')->with('alice')->willReturn([
            'status' => 'ok',
            'mount_id' => 42,
            'mount_name' => '/Immich Photos',
            'read_only' => true,
        ]);
        $this->quotaSyncService->expects($this->once())->method('computeQuota')->with('alice', null)->willReturn(9000);
        $this->actionPolicyService->method('getCapabilityFlags')->with('alice')->willReturn([
            'exportCopyEnabled' => true,
            'importToImmichEnabled' => false,
            'immichDeleteEnabled' => true,
            'mirrorMountPaths' => ['/Immich Photos'],
        ]);

        $provided = $this->providedUserConfig();
        $response = $this->controller('alice')->index();
        $data = $provided();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('https://photos.example.com', $data['immich_url']);
        $this->assertSame('https://photos.example.com', $data['server_url']);
        $this->assertSame(['enabled' => true, 'scope' => 'groups', 'scopedGroups' => ['photos'], 'status' => 'active'], $data['provisioning']);
        $this->assertSame('active', $data['mapping']['status']);
        $this->assertSame('immich-alice', $data['mapping']['immichUserId']);
        $this->assertSame('alice', $data['mapping']['storageLabel']);
        $this->assertSame('ok', $data['mount']['status']);
        $this->assertSame(42, $data['mount']['mountId']);
        $this->assertSame('/Immich Photos', $data['mount']['path']);
        $this->assertTrue($data['mount']['readOnly']);
        $this->assertSame(9000, $data['quota']['computedImmichQuota']);
        $this->assertSame(512, $data['quota']['reserve']);
        $this->assertTrue($data['actions']['exportCopyEnabled']);
        $this->assertTrue($data['actions']['immichDeleteEnabled']);
        $this->assertSame(['/Immich Photos'], $data['actionCapabilities']['mirrorMountPaths']);
        $this->assertStringNotContainsString('secret-admin-key', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testMissingMappingProducesSetupMessageWithoutFatalHealthChecks(): void {
        $this->syncStateService->method('findByUid')->with('alice')->willReturn(null);
        $this->externalStorageProvisioner->expects($this->never())->method('verifyMount');
        $this->quotaSyncService->expects($this->never())->method('computeQuota');

        $provided = $this->providedUserConfig();
        $this->controller('alice')->index();
        $data = $provided();

        $this->assertSame('missing', $data['mapping']['status']);
        $this->assertStringContainsString('run Immich provisioning', $data['mapping']['message']);
        $this->assertSame('unavailable', $data['mount']['status']);
        $this->assertSame('unavailable', $data['quota']['status']);
        $this->assertSame('Quota sync needs an Immich user mapping before quota details are available.', $data['quota']['warning']);
        $this->assertArrayHasKey('actionCapabilities', $data);
        $this->assertFalse($data['actions']['exportCopyEnabled']);
        $this->assertStringNotContainsString('secret-admin-key', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function providedUserConfig(): callable {
        $provided = null;
        $this->initialState->expects($this->once())
            ->method('provideInitialState')
            ->willReturnCallback(static function (string $key, array $data) use (&$provided): void {
                self::assertSame('user-config', $key);
                $provided = $data;
            });

        return static function () use (&$provided): array {
            self::assertIsArray($provided);
            return $provided;
        };
    }

    private function controller(string $uid): PageController {
        return new PageController(
            $this->request,
            $this->initialState,
            new FrontendInitialStateService(
                $this->adminConfigService,
                $this->syncStateService,
                $this->capabilityService,
                $this->actionPolicyService,
                $this->externalStorageProvisioner,
                $this->quotaSyncService,
            ),
            $uid,
        );
    }

    private function adminConfig(): array {
        return [
            AdminConfigService::KEY_IMMICH_BASE_URL => 'https://photos.example.com',
            AdminConfigService::KEY_ADMIN_API_KEY => 'secret-admin-key',
            'admin_api_key_configured' => true,
            AdminConfigService::KEY_PROVISIONING_ENABLED => true,
            AdminConfigService::KEY_USER_SCOPE_MODE => 'groups',
            AdminConfigService::KEY_USER_SCOPE_GROUPS => ['photos'],
            AdminConfigService::KEY_QUOTA_SYNC_MODE => 'manual',
            AdminConfigService::KEY_QUOTA_RESERVE_BYTES => 512,
        ];
    }

    private function syncState(string $uid, string $immichUserId, string $storageLabel): SyncState {
        $state = new SyncState();
        $state->setNcUid($uid);
        $state->setImmichUserId($immichUserId);
        $state->setStorageLabel($storageLabel);
        $state->setScopeStatus(SyncStateService::STATUS_ACTIVE);
        $state->setLastSyncStatus(SyncStateService::STATUS_ACTIVE);
        $state->setNcMountId(42);
        $state->setCreatedAt(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $state->setUpdatedAt(new DateTimeImmutable('2026-01-02T00:00:00+00:00'));
        $state->setLastQuotaSyncAt(new DateTimeImmutable('2026-01-03T00:00:00+00:00'));

        return $state;
    }
}
