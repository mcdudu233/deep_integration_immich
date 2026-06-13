<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Service;

use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\CapabilityService;
use OCA\IntegrationImmich\Service\ImmichUserAdminService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ImmichUserAdminServiceTest extends TestCase {
    private IClientService&MockObject $clientService;
    private IClient&MockObject $client;
    private AdminConfigService&MockObject $adminConfigService;
    private CapabilityService&MockObject $capabilityService;
    private SyncStateService&MockObject $syncStateService;
    private ImmichUserAdminService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->clientService = $this->createMock(IClientService::class);
        $this->client = $this->createMock(IClient::class);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->adminConfigService = $this->createMock(AdminConfigService::class);
        $this->adminConfigService->method('getImmichBaseUrl')->willReturn('https://photos.example.com');
        $this->adminConfigService->method('getAdminApiKey')->willReturn('admin-secret');
        $this->capabilityService = $this->createMock(CapabilityService::class);
        $this->syncStateService = $this->createMock(SyncStateService::class);

        $this->service = new ImmichUserAdminService(
            $this->clientService,
            $this->adminConfigService,
            $this->capabilityService,
            $this->syncStateService,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testValidateAdminConnectionUsesAdminApiKey(): void {
        $this->client->expects($this->once())
            ->method('get')
            ->with('https://photos.example.com/api/admin/users', $this->callback(function (array $options): bool {
                $this->assertSame('admin-secret', $options['headers']['x-api-key']);
                $this->assertSame('application/json', $options['headers']['Accept']);
                $this->assertFalse($options['http_errors']);
                return true;
            }))
            ->willReturn($this->response(200, [['id' => 'immich-admin']]));

        $result = $this->service->validateAdminConnection();

        $this->assertTrue($result['success']);
        $this->assertSame('GET /api/admin/users', $result['data']['probe']);
        $this->assertTrue($result['data']['admin_users_accessible']);
        $this->assertSame(1, $result['data']['user_count']);
        $this->assertStringNotContainsString('admin-secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testValidateAdminConnectionUsesSubmittedCredentials(): void {
        $this->client->expects($this->once())
            ->method('get')
            ->with('https://candidate.example.com/api/admin/users', $this->callback(function (array $options): bool {
                $this->assertSame('candidate-secret', $options['headers']['x-api-key']);
                return true;
            }))
            ->willReturn($this->response(200, []));

        $result = $this->service->validateAdminConnection('https://candidate.example.com/', 'candidate-secret');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['user_count']);
    }

    public function testCreateUserGeneratesPasswordAndIncludesQuota(): void {
        $this->client->expects($this->once())
            ->method('post')
            ->with('https://photos.example.com/api/admin/users', $this->callback(function (array $options): bool {
                $this->assertSame('admin-secret', $options['headers']['x-api-key']);
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('alice@example.com', $payload['email']);
                $this->assertSame('Alice', $payload['name']);
                $this->assertSame('alice', $payload['storageLabel']);
                $this->assertSame(123456, $payload['quotaSizeInBytes']);
                $this->assertTrue($payload['shouldChangePassword']);
                $this->assertIsString($payload['password']);
                $this->assertGreaterThanOrEqual(16, strlen($payload['password']));
                return true;
            }))
            ->willReturn($this->response(201, ['id' => 'immich-alice', 'email' => 'alice@example.com', 'password' => 'echoed-secret']));

        $created = $this->service->createUser([
            'email' => 'alice@example.com',
            'name' => 'Alice',
            'storageLabel' => 'alice',
            'quotaSizeInBytes' => 123456,
        ]);

        $this->assertSame('immich-alice', $created['id']);
        $this->assertArrayNotHasKey('password', $created);
    }

    public function testCreateUserWithCredentialsReturnsGeneratedPasswordForServerSidePersistenceOnly(): void {
        $this->client->expects($this->once())
            ->method('post')
            ->willReturnCallback(function (string $url, array $options): mixed {
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);

                return $this->response(201, [
                    'id' => 'immich-alice',
                    'email' => 'alice@example.com',
                    'password' => 'remote-echo-must-not-win',
                    'generatedPasswordLength' => strlen((string)$payload['password']),
                ]);
            });

        $created = $this->service->createUserWithCredentials([
            'email' => 'alice@example.com',
            'name' => 'Alice',
        ]);

        $this->assertSame('immich-alice', $created['id']);
        $this->assertIsString($created['password']);
        $this->assertNotSame('remote-echo-must-not-win', $created['password']);
        $this->assertSame(32, strlen($created['password']));
    }

    public function testCreateUserIncludesQuotaWithoutCapabilityGate(): void {
        $this->client->expects($this->once())
            ->method('post')
            ->with('https://photos.example.com/api/admin/users', $this->callback(function (array $options): bool {
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(123456, $payload['quotaSizeInBytes']);
                return true;
            }))
            ->willReturn($this->response(201, ['id' => 'immich-alice']));

        $this->service->createUser([
            'email' => 'alice@example.com',
            'name' => 'Alice',
            'quotaSizeInBytes' => 123456,
        ]);
    }

    public function testCreateUserAlwaysRequiresPasswordChangeForLegacySsoPolicy(): void {
        $this->adminConfigService->method('getInitialPasswordPolicy')->willReturn('sso_oidc');
        $this->client->expects($this->once())
            ->method('post')
            ->with('https://photos.example.com/api/admin/users', $this->callback(function (array $options): bool {
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                $this->assertTrue($payload['shouldChangePassword']);
                $this->assertIsString($payload['password']);
                return true;
            }))
            ->willReturn($this->response(201, ['id' => 'immich-alice']));

        $this->service->createUser([
            'email' => 'alice@example.com',
            'name' => 'Alice',
        ]);
    }

    public function testCreateUserRejectsZeroQuotaBeforeHttpRequest(): void {
        $this->client->expects($this->never())->method('post');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('quotaSizeInBytes=0 is not allowed');

        $this->service->createUser([
            'email' => 'alice@example.com',
            'name' => 'Alice',
            'quotaSizeInBytes' => 0,
        ]);
    }

    public function testCreateUserDuplicateConflictSurfacesHttp409(): void {
        $this->client->expects($this->once())
            ->method('post')
            ->willReturn($this->response(409, ['message' => 'email already exists']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 409');

        $this->service->createUser([
            'email' => 'alice@example.com',
            'name' => 'Alice',
        ]);
    }

    public function testFindUserForNcUidReturnsStoredMappingMatch(): void {
        $state = new SyncState();
        $state->setNcUid('alice');
        $state->setImmichUserId('immich-alice');
        $state->setStorageLabel('alice');
        $this->syncStateService->method('findByUid')->with('alice')->willReturn($state);
        $this->syncStateService->method('findByStorageLabel')->with('alice')->willReturn($state);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->response(200, [
                ['id' => 'immich-alice', 'email' => 'renamed@example.com', 'storageLabel' => 'alice'],
                ['id' => 'immich-bob', 'email' => 'alice@example.com', 'storageLabel' => 'bob'],
            ]));

        $found = $this->service->findUserForNcUid('alice', 'alice@example.com', 'alice');

        $this->assertSame('immich-alice', $found['id']);
        $this->assertSame('renamed@example.com', $found['email']);
    }

    public function testFindUserForNcUidFailsClosedOnDuplicateEmailOrStorageLabelCandidates(): void {
        $this->syncStateService->method('findByUid')->with('alice')->willReturn(null);
        $this->syncStateService->method('findByStorageLabel')->with('alice')->willReturn(null);
        $this->client->expects($this->once())
            ->method('get')
            ->with('https://photos.example.com/api/admin/users', $this->anything())
            ->willReturn($this->response(200, [
                ['id' => 'immich-one', 'email' => 'alice@example.com', 'storageLabel' => 'alice-one'],
                ['id' => 'immich-two', 'email' => 'other@example.com', 'storageLabel' => 'alice'],
            ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate Immich users');

        $this->service->findUserForNcUid('alice', 'alice@example.com', 'alice');
    }

    public function testFindUserForNcUidRejectsStorageLabelOwnedByDifferentMapping(): void {
		$otherState = new SyncState();
		$otherState->setNcUid('bob');
		$otherState->setStorageLabel('alice');
		$this->syncStateService->method('findByUid')->with('alice')->willReturn(null);
		$this->syncStateService->method('findByStorageLabel')->with('alice')->willReturn($otherState);
		$this->client->expects($this->never())->method('get');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('storage label conflict');

		$this->service->findUserForNcUid('alice', 'alice@example.com', 'alice');
	}

    public function testUpdateUserUsesAdminPutAndAllowsNullQuota(): void {
        $this->client->expects($this->once())
            ->method('put')
            ->with('https://photos.example.com/api/admin/users/immich-alice', $this->callback(function (array $options): bool {
                $this->assertSame('admin-secret', $options['headers']['x-api-key']);
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('Alice Updated', $payload['name']);
                $this->assertArrayHasKey('quotaSizeInBytes', $payload);
                $this->assertNull($payload['quotaSizeInBytes']);
                return true;
            }))
            ->willReturn($this->response(200, ['id' => 'immich-alice', 'name' => 'Alice Updated']));

        $updated = $this->service->updateUser('immich-alice', [
            'name' => 'Alice Updated',
            'quotaSizeInBytes' => null,
        ]);

        $this->assertSame('Alice Updated', $updated['name']);
    }

    public function testUpdateUserIncludesQuotaWithoutCapabilityGate(): void {
        $this->client->expects($this->once())
            ->method('put')
            ->with('https://photos.example.com/api/admin/users/immich-alice', $this->callback(function (array $options): bool {
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('Alice Updated', $payload['name']);
                $this->assertSame(123456, $payload['quotaSizeInBytes']);
                return true;
            }))
            ->willReturn($this->response(200, ['id' => 'immich-alice']));

        $this->service->updateUser('immich-alice', [
            'name' => 'Alice Updated',
            'quotaSizeInBytes' => 123456,
        ]);
    }

    public function testCreateUserApiKeyLogsInAndCreatesKeyWithBearerToken(): void {
        $this->client->expects($this->exactly(2))
            ->method('post')
            ->willReturnCallback(function (string $url, array $options): IResponse {
                if ($url === 'https://photos.example.com/api/auth/login') {
                    $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                    $this->assertSame('alice@example.com', $payload['email']);
                    $this->assertSame('generated-password', $payload['password']);
                    $this->assertArrayNotHasKey('x-api-key', $options['headers']);
                    return $this->response(200, ['accessToken' => 'user-session-token']);
                }

                $this->assertSame('https://photos.example.com/api/api-keys', $url);
                $this->assertSame('Bearer user-session-token', $options['headers']['Authorization']);
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('Nextcloud Immich integration', $payload['name']);
                $this->assertSame(['all'], $payload['permissions']);
                return $this->response(201, ['secret' => 'user-api-key']);
            });

        $apiKey = $this->service->createUserApiKey('alice@example.com', 'generated-password');

        $this->assertSame('user-api-key', $apiKey);
    }

    public function testDeleteUserRequiresExplicitAdminPolicy(): void {
        $this->adminConfigService->method('allowsDestructiveUserDelete')->willReturn(false);
        $this->client->expects($this->never())->method('delete');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('deletion is disabled');

        $this->service->deleteUser('immich-alice');
    }

    public function testDisableUserFailsClearlyWhenImmichVersionHasNoDisableField(): void {
        $this->client->expects($this->never())->method('put');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not expose a non-destructive disable');

        $this->service->disableUser('immich-alice');
    }

    public function testGetUserQuotaUsageReadsQuotaUsageInBytes(): void {
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->response(200, [
                ['id' => 'immich-alice', 'quotaUsageInBytes' => 987654],
            ]));

        $this->assertSame(987654, $this->service->getUserQuotaUsage('immich-alice'));
    }

    private function response(int $statusCode, array $body): IResponse&MockObject {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));
        $response->method('getHeader')->willReturn('');
        return $response;
    }
}
