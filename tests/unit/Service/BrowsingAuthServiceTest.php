<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Service;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\BrowsingAuthService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class BrowsingAuthServiceTest extends TestCase {
    private IConfig&MockObject $config;
    private ICrypto&MockObject $crypto;
    private SyncStateService&MockObject $syncStateService;
    private AdminConfigService&MockObject $adminConfigService;
    private IClientService&MockObject $clientService;
    private IClient&MockObject $client;
    private LoggerInterface&MockObject $logger;
    private BrowsingAuthService $service;
    private array $userValues = [];

    protected function setUp(): void {
        parent::setUp();

        $this->userValues = [];
        $this->config = $this->createMock(IConfig::class);
        $this->crypto = $this->createMock(ICrypto::class);
        $this->syncStateService = $this->createMock(SyncStateService::class);
        $this->adminConfigService = $this->createMock(AdminConfigService::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->client = $this->createMock(IClient::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('getUserValue')
            ->willReturnCallback(function (string $uid, string $app, string $key, string $default): string {
                $this->assertSame(Application::APP_ID, $app);
                return $this->userValues[$uid][$key] ?? $default;
            });
        $this->crypto->method('decrypt')
            ->willReturnCallback(function (string $value): string {
                if (!str_starts_with($value, 'encrypted:')) {
                    throw new \RuntimeException('not encrypted');
                }

                return substr($value, strlen('encrypted:'));
            });

        $this->service = new BrowsingAuthService(
            $this->config,
            $this->crypto,
            $this->syncStateService,
            $this->adminConfigService,
            $this->clientService,
            $this->logger,
        );
    }

    public function testResolveCredentialsUsesPersonalApiKeyInPersonalMode(): void {
        $this->userValues['alice'] = [
            'server_url' => 'https://personal.example.com/',
            'api_key' => 'encrypted:personal-key',
        ];
        $this->adminConfigService->method('getAdminConfig')->willReturn([
            AdminConfigService::KEY_IMMICH_BROWSING_MODE => AdminConfigService::BROWSING_MODE_PERSONAL,
        ]);
        $this->adminConfigService->expects($this->never())->method('isConfigured');

        $credentials = $this->service->resolveCredentials('alice');

        $this->assertSame(BrowsingAuthService::MODE_PERSONAL, $credentials['mode']);
        $this->assertSame('https://personal.example.com', $credentials['url']);
        $this->assertSame('personal-key', $credentials['apiKey']);
        $this->assertNull($credentials['immichUserId']);
    }

    public function testResolveCredentialsReturnsUnavailableInPersonalModeWhenPersonalKeyIsMissing(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn([
            AdminConfigService::KEY_IMMICH_BROWSING_MODE => AdminConfigService::BROWSING_MODE_PERSONAL,
        ]);
        $this->adminConfigService->expects($this->never())->method('isConfigured');
        $this->syncStateService->expects($this->never())->method('findByUid');

        $credentials = $this->service->resolveCredentials('alice');

        $this->assertSame(BrowsingAuthService::MODE_UNAVAILABLE, $credentials['mode']);
        $this->assertSame('', $credentials['url']);
        $this->assertNull($credentials['apiKey']);
        $this->assertNull($credentials['immichUserId']);
    }

    public function testResolveCredentialsUsesProvisionedUserApiKeyInAdminManagedMode(): void {
        $this->userValues['alice'] = [
            'server_url' => 'https://personal.example.com/',
            'api_key' => 'encrypted:personal-key',
        ];
        $this->adminConfigService->method('getAdminConfig')->willReturn([
            AdminConfigService::KEY_IMMICH_BROWSING_MODE => AdminConfigService::BROWSING_MODE_ADMIN_MANAGED,
        ]);
        $this->adminConfigService->method('isConfigured')->willReturn(true);
        $this->adminConfigService->method('getImmichBaseUrl')->willReturn('https://admin.example.com');
        $state = $this->syncState('alice', 'immich-alice');
        $state->setImmichApiKey('encrypted:user-api-key');
        $this->adminConfigService->expects($this->never())->method('getAdminApiKey');
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($state);

        $credentials = $this->service->resolveCredentials('alice');

        $this->assertSame(BrowsingAuthService::MODE_ADMIN_PROXY, $credentials['mode']);
        $this->assertSame('https://admin.example.com', $credentials['url']);
        $this->assertSame('user-api-key', $credentials['apiKey']);
        $this->assertSame('immich-alice', $credentials['immichUserId']);
        $this->assertTrue($credentials['usesUserApiKey']);
    }

    public function testResolveCredentialsUsesAdminManagedUserApiKeyWhenPersonalKeyIsAbsentAndMappingExists(): void {
        $this->adminConfigService->method('isConfigured')->willReturn(true);
        $this->adminConfigService->method('getImmichBaseUrl')->willReturn('https://admin.example.com');
        $state = $this->syncState('alice', 'immich-alice');
        $state->setImmichApiKey('encrypted:user-api-key');
        $this->adminConfigService->expects($this->never())->method('getAdminApiKey');
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($state);

        $credentials = $this->service->resolveCredentials('alice');

        $this->assertSame(BrowsingAuthService::MODE_ADMIN_PROXY, $credentials['mode']);
        $this->assertSame('https://admin.example.com', $credentials['url']);
        $this->assertSame('user-api-key', $credentials['apiKey']);
        $this->assertSame('immich-alice', $credentials['immichUserId']);
        $this->assertTrue($credentials['usesUserApiKey']);
    }

    public function testResolveCredentialsReturnsUnavailableWhenProvisionedUserApiKeyIsMissing(): void {
        $this->adminConfigService->method('isConfigured')->willReturn(true);
        $state = $this->syncState('alice', 'immich-alice');
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($state);

        $credentials = $this->service->resolveCredentials('alice');

        $this->assertSame(BrowsingAuthService::MODE_UNAVAILABLE, $credentials['mode']);
        $this->assertNull($credentials['apiKey']);
    }

    public function testResolveCredentialsReturnsUnavailableWhenMappingIsMissing(): void {
        $this->adminConfigService->method('isConfigured')->willReturn(true);
        $this->syncStateService->method('findByUid')->with('alice')->willReturn(null);

        $credentials = $this->service->resolveCredentials('alice');

        $this->assertSame(BrowsingAuthService::MODE_UNAVAILABLE, $credentials['mode']);
        $this->assertSame('', $credentials['url']);
        $this->assertNull($credentials['apiKey']);
        $this->assertNull($credentials['immichUserId']);
    }

    public function testResolveCredentialsReturnsUnavailableWhenAdminProxyIsNotConfigured(): void {
        $this->adminConfigService->method('isConfigured')->willReturn(false);
        $this->syncStateService->expects($this->never())->method('findByUid');

        $credentials = $this->service->resolveCredentials('alice');

        $this->assertSame(BrowsingAuthService::MODE_UNAVAILABLE, $credentials['mode']);
    }

    public function testResolveCredentialsReturnsUnavailableForTerminalMapping(): void {
        $this->adminConfigService->method('isConfigured')->willReturn(true);
        $state = $this->syncState('alice', 'immich-alice');
        $state->setScopeStatus(SyncStateService::STATUS_DELETED);
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($state);

        $credentials = $this->service->resolveCredentials('alice');

        $this->assertSame(BrowsingAuthService::MODE_UNAVAILABLE, $credentials['mode']);
        $this->assertNull($credentials['apiKey']);
    }

    public function testResolveAutoLoginHandoffReturnsImmichUrlForMappedAdminManagedUser(): void {
        $state = $this->syncState('alice', 'immich-alice');
        $this->adminConfigService->method('getAdminConfig')->willReturn([
            AdminConfigService::KEY_IMMICH_BROWSING_MODE => AdminConfigService::BROWSING_MODE_ADMIN_MANAGED,
        ]);
        $this->adminConfigService->method('isConfigured')->willReturn(true);
        $this->adminConfigService->method('getImmichBaseUrl')->willReturn('https://admin.example.com');
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($state);

        $handoff = $this->service->resolveAutoLoginHandoff('alice');

        $this->assertSame(BrowsingAuthService::HANDOFF_READY, $handoff['status']);
        $this->assertSame('https://admin.example.com', $handoff['url']);
        $this->assertNull($handoff['username']);
        $this->assertNull($handoff['password']);
        $this->assertSame('immich-alice', $handoff['immichUserId']);
    }

    public function testResolveAutoLoginHandoffDoesNotUsePersonalModeCredentials(): void {
        $this->adminConfigService->method('getAdminConfig')->willReturn([
            AdminConfigService::KEY_IMMICH_BROWSING_MODE => AdminConfigService::BROWSING_MODE_PERSONAL,
        ]);
        $this->adminConfigService->expects($this->never())->method('isConfigured');
        $this->syncStateService->expects($this->never())->method('findByUid');

        $handoff = $this->service->resolveAutoLoginHandoff('alice');

        $this->assertSame(BrowsingAuthService::HANDOFF_PERSONAL_MODE, $handoff['status']);
        $this->assertNull($handoff['username']);
        $this->assertNull($handoff['password']);
    }

    public function testLegacyPasswordLoginHandoffRejectsMappedUsersWithoutStoredPassword(): void {
        $state = $this->syncState('alice', 'immich-alice');
        $state->setImmichUsername('alice@immich.local');
        $this->adminConfigService->method('isConfigured')->willReturn(true);
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($state);

        $handoff = $this->service->resolveLegacyPasswordLoginHandoff('alice');

        $this->assertSame(BrowsingAuthService::HANDOFF_CREDENTIALS_MISSING, $handoff['status']);
        $this->assertNull($handoff['password']);
    }

    public function testCreateImmichLoginSessionPostsCredentialsServerSideAndReturnsCookieOnly(): void {
        $handoff = [
            'status' => BrowsingAuthService::HANDOFF_READY,
            'url' => 'https://admin.example.com/',
            'username' => 'alice@immich.local',
            'password' => 'generated-password',
            'immichUserId' => 'immich-alice',
        ];
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('post')
            ->with('https://admin.example.com/api/auth/login', $this->callback(function (array $options): bool {
                $this->assertSame('application/json', $options['headers']['Content-Type']);
                $this->assertSame('application/json', $options['headers']['Accept']);
                $this->assertStringContainsString('alice@immich.local', $options['body']);
                $this->assertStringContainsString('generated-password', $options['body']);
                return true;
            }))
            ->willReturn($this->jsonResponse([], 'immich_session=session-value; Path=/; HttpOnly'));

        $session = $this->service->createImmichLoginSession($handoff);

        $this->assertTrue($session['success']);
        $this->assertSame('https://admin.example.com', $session['redirectUrl']);
        $this->assertSame('immich_session=session-value; Path=/; HttpOnly', $session['setCookie']);
    }

    public function testCreateImmichLoginSessionRefusesTokenOnlyResponses(): void {
        $handoff = [
            'status' => BrowsingAuthService::HANDOFF_READY,
            'url' => 'https://admin.example.com',
            'username' => 'alice@immich.local',
            'password' => 'generated-password',
            'immichUserId' => 'immich-alice',
        ];
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('post')
            ->willReturn($this->jsonResponse(['accessToken' => 'token-redacted']));

        $session = $this->service->createImmichLoginSession($handoff);

        $this->assertFalse($session['success']);
        $this->assertSame('https://admin.example.com', $session['redirectUrl']);
        $this->assertNull($session['setCookie']);
    }

    public function testAssertAssetOwnershipUsesAdminCredentialsAndAcceptsMatchingOwner(): void {
        $assetId = '11111111-1111-1111-1111-111111111111';
        $this->adminConfigService->method('isConfigured')->willReturn(true);
        $this->adminConfigService->method('getImmichBaseUrl')->willReturn('https://admin.example.com');
        $this->adminConfigService->method('getAdminApiKey')->willReturn('admin-key');
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->with('https://admin.example.com/api/assets/' . $assetId, $this->callback(function (array $options): bool {
                $this->assertSame('admin-key', $options['headers']['x-api-key']);
                return true;
            }))
            ->willReturn($this->jsonResponse(['id' => $assetId, 'ownerId' => 'immich-alice']));

        $this->assertTrue($this->service->assertAssetOwnership('immich-alice', $assetId));
    }

    public function testAssertAssetOwnershipRejectsForeignOwner(): void {
        $assetId = '22222222-2222-2222-2222-222222222222';
        $this->adminConfigService->method('isConfigured')->willReturn(true);
        $this->adminConfigService->method('getImmichBaseUrl')->willReturn('https://admin.example.com');
        $this->adminConfigService->method('getAdminApiKey')->willReturn('admin-key');
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->method('get')
            ->willReturn($this->jsonResponse(['id' => $assetId, 'ownerId' => 'immich-bob']));

        $this->assertFalse($this->service->assertAssetOwnership('immich-alice', $assetId));
    }

    public function testAssetBelongsToUserAcceptsLegacyOwnerShapes(): void {
        $this->assertTrue($this->service->assetBelongsToUser(['userId' => 'immich-alice'], 'immich-alice'));
        $this->assertTrue($this->service->assetBelongsToUser(['owner' => ['id' => 'immich-alice']], 'immich-alice'));
        $this->assertFalse($this->service->assetBelongsToUser(['owner' => ['id' => 'immich-bob']], 'immich-alice'));
    }

    private function syncState(string $ncUid, ?string $immichUserId): SyncState {
        $syncState = new SyncState();
        $syncState->setNcUid($ncUid);
        $syncState->setImmichUserId($immichUserId);
        $syncState->setScopeStatus(SyncStateService::STATUS_ACTIVE);
        return $syncState;
    }

    private function jsonResponse(array $body, string $setCookie = ''): IResponse&MockObject {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));
        $response->method('getHeader')->willReturnCallback(static fn(string $header): string => $header === 'Set-Cookie' ? $setCookie : '');
        return $response;
    }
}
