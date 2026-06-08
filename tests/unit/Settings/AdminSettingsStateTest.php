<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Settings;

use DateTimeImmutable;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\ActionPolicyService;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\CapabilityService;
use OCA\IntegrationImmich\Service\ExternalStorageProvisioner;
use OCA\IntegrationImmich\Service\FrontendInitialStateService;
use OCA\IntegrationImmich\Service\QuotaSyncService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCA\IntegrationImmich\Settings\AdminSettings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class AdminSettingsStateTest extends TestCase {
    private AdminConfigService&MockObject $adminConfigService;
    private SyncStateService&MockObject $syncStateService;
    private CapabilityService&MockObject $capabilityService;
    private ActionPolicyService&MockObject $actionPolicyService;
    private ExternalStorageProvisioner&MockObject $externalStorageProvisioner;
    private QuotaSyncService&MockObject $quotaSyncService;
    private IInitialState&MockObject $initialState;

    protected function setUp(): void {
        parent::setUp();

        $this->adminConfigService = $this->createMock(AdminConfigService::class);
        $this->syncStateService = $this->createMock(SyncStateService::class);
        $this->capabilityService = $this->createMock(CapabilityService::class);
        $this->actionPolicyService = $this->createMock(ActionPolicyService::class);
        $this->externalStorageProvisioner = $this->createMock(ExternalStorageProvisioner::class);
        $this->quotaSyncService = $this->createMock(QuotaSyncService::class);
        $this->initialState = $this->createMock(IInitialState::class);
    }

    public function testAdminInitialStateContainsDashboardStateWithoutSecrets(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn([
            AdminConfigService::KEY_IMMICH_BASE_URL => 'https://photos.example.com',
            AdminConfigService::KEY_ADMIN_API_KEY => 'secret-admin-key',
            'admin_api_key_configured' => true,
            AdminConfigService::KEY_PROVISIONING_ENABLED => true,
            AdminConfigService::KEY_USER_SCOPE_MODE => 'groups',
            AdminConfigService::KEY_USER_SCOPE_GROUPS => ['photos'],
            AdminConfigService::KEY_QUOTA_SYNC_MODE => 'event_scheduled',
            AdminConfigService::KEY_QUOTA_RESERVE_BYTES => 1024,
            AdminConfigService::KEY_EXPORT_COPY_ENABLED => true,
            AdminConfigService::KEY_IMPORT_TO_IMMICH_ENABLED => false,
            AdminConfigService::KEY_IMMICH_DELETE_ENABLED => false,
        ]);
        $this->capabilityService->method('getCapabilities')->willReturn([
            'safeProxyBrowsing' => [
                'supported' => false,
                'reason' => 'probe failed with https://photos.example.com/check?api_key=secret-admin-key&token=query-token-redacted Authorization: Bearer test-bearer-redacted',
                'remediation' => 'configure payload {"authorization":"Bearer test-bearer-redacted","admin_api_key":"json-admin-key-redacted"}',
                'genericBearer' => 'retry with Bearer generic-bearer-redacted and password=generic-password-redacted',
                'nested' => [
                    'authorization' => 'Bearer nested-bearer-redacted',
                    'admin_api_key_configured' => true,
                ],
            ],
        ]);
        $this->syncStateService->expects($this->once())->method('listStates')->with(100, 0)->willReturn([
            $this->syncState(),
        ]);

        $provided = null;
        $this->initialState->expects($this->once())
            ->method('provideInitialState')
            ->willReturnCallback(static function (string $key, array $data) use (&$provided): void {
                self::assertSame('admin-config', $key);
                $provided = $data;
            });

        $response = new AdminSettings($this->stateService(), $this->initialState)->getForm();
        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertIsArray($provided);

        $this->assertSame('https://photos.example.com', $provided['server_url']);
        $this->assertTrue($provided['api_key_set']);
        $this->assertSame('https://photos.example.com', $provided['settings'][AdminConfigService::KEY_IMMICH_BASE_URL]);
        $this->assertTrue($provided['settings']['admin_api_key_configured']);
        $this->assertArrayNotHasKey(AdminConfigService::KEY_ADMIN_API_KEY, $provided['settings']);
        $this->assertSame(['enabled' => true, 'scope' => 'groups', 'scopedGroups' => ['photos']], $provided['status']['provisioning']);
        $this->assertTrue($provided['status']['credentials']['admin_api_key_configured']);
        $this->assertTrue($provided['status']['actions']['exportCopyEnabled']);
        $this->assertSame('alice', $provided['syncStates'][0]['ncUid']);
        $this->assertSame('sync failed with api_key=[redacted] authorization=[redacted];json={"admin_api_key":"[redacted]"}', $provided['syncStates'][0]['lastError']);
        $this->assertSame('probe failed with https://photos.example.com/check?api_key=[redacted]&token=[redacted] Authorization: [redacted]', $provided['capabilities']['safeProxyBrowsing']['reason']);
        $this->assertSame('configure payload {"authorization":"[redacted]","admin_api_key":"[redacted]"}', $provided['capabilities']['safeProxyBrowsing']['remediation']);
        $this->assertSame('retry with Bearer [redacted] and password=[redacted]', $provided['capabilities']['safeProxyBrowsing']['genericBearer']);
        $this->assertSame('[redacted]', $provided['capabilities']['safeProxyBrowsing']['nested']['authorization']);
        $this->assertTrue($provided['capabilities']['safeProxyBrowsing']['nested']['admin_api_key_configured']);
        $this->assertSame([], $provided['warningDetails']);
        $encoded = json_encode($provided, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('[redacted]', $encoded);
        $this->assertStringNotContainsString('secret-admin-key', $encoded);
        $this->assertStringNotContainsString('test-bearer-redacted', $encoded);
        $this->assertStringNotContainsString('json-admin-key-redacted', $encoded);
        $this->assertStringNotContainsString('query-token-redacted', $encoded);
        $this->assertStringNotContainsString('generic-bearer-redacted', $encoded);
        $this->assertStringNotContainsString('generic-password-redacted', $encoded);
        $this->assertStringNotContainsString('nested-bearer-redacted', $encoded);
    }

    private function stateService(): FrontendInitialStateService {
        return new FrontendInitialStateService(
            $this->adminConfigService,
            $this->syncStateService,
            $this->capabilityService,
            $this->actionPolicyService,
            $this->externalStorageProvisioner,
            $this->quotaSyncService,
        );
    }

    private function syncState(): SyncState {
        $state = new SyncState();
        $state->setNcUid('alice');
        $state->setImmichUserId('immich-alice');
        $state->setImmichEmail('alice@example.com');
        $state->setStorageLabel('alice');
        $state->setNcMountId(42);
        $state->setScopeStatus(SyncStateService::STATUS_ACTIVE);
        $state->setLastSyncStatus(SyncStateService::STATUS_QUOTA_FAILED);
        $state->setLastError('sync failed with api_key=secret-admin-key authorization=Bearer test-bearer-redacted;json={"admin_api_key":"json-admin-key-redacted"}');
        $state->setCreatedAt(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $state->setUpdatedAt(new DateTimeImmutable('2026-01-02T00:00:00+00:00'));
        $state->setLastQuotaSyncAt(new DateTimeImmutable('2026-01-03T00:00:00+00:00'));

        return $state;
    }
}
