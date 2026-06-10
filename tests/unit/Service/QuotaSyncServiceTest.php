<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Service;

use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\QuotaSyncService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class QuotaSyncServiceTest extends TestCase {
    private IUserManager&MockObject $userManager;
    private AdminConfigService&MockObject $adminConfigService;
    private array $adminConfig;

    protected function setUp(): void {
        parent::setUp();

        $this->userManager = $this->createMock(IUserManager::class);
        $this->adminConfigService = $this->createMock(AdminConfigService::class);
        $this->adminConfig = [
            AdminConfigService::KEY_QUOTA_RESERVE_BYTES => 100,
        ];
        $this->adminConfigService->method('getAdminConfig')->willReturnCallback(fn(): array => $this->adminConfig);
    }

    public function testComputeQuotaUsesNextcloudQuotaMinusNonImmichUsageAndReserve(): void {
        $this->userManager->method('get')->with('alice')->willReturn($this->userWithQuota('1000'));
        $service = $this->service(fn(string $uid): int => $uid === 'alice' ? 700 : 0);

        $quota = $service->computeQuota('alice', 200);

        $this->assertSame(400, $quota);
        $this->assertNull($service->getLastError());
        $this->assertFalse($service->wasLastQuotaUnlimited());
    }

    public function testComputeQuotaNeverDropsBelowCurrentImmichUsage(): void {
        $this->userManager->method('get')->with('alice')->willReturn($this->userWithQuota('1000'));
        $service = $this->service(fn(): int => 950);

        $this->assertSame(300, $service->computeQuota('alice', 300));
    }

    public function testComputeQuotaParsesHumanReadableNextcloudQuota(): void {
        $this->userManager->method('get')->with('alice')->willReturn($this->userWithQuota('1 KB'));
        $service = $this->service(fn(): int => 0);

        $this->assertSame(924, $service->computeQuota('alice', 0));
    }

    public function testUnlimitedNextcloudQuotaReturnsNullWithoutError(): void {
        $this->userManager->method('get')->with('alice')->willReturn($this->userWithQuota('none'));
        $service = $this->service(fn(): int => 700);

        $this->assertNull($service->computeQuota('alice', 200));
        $this->assertNull($service->getLastError());
        $this->assertTrue($service->wasLastQuotaUnlimited());
    }

    public function testDefaultQuotaUsesConfiguredSystemDefault(): void {
        $this->adminConfig['default_quota'] = '2 KB';
        $this->userManager->method('get')->with('alice')->willReturn($this->userWithQuota('default'));
        $service = $this->service(fn(): int => 700);

        $this->assertSame(1448, $service->computeQuota('alice', 200));
        $this->assertNull($service->getLastError());
        $this->assertFalse($service->wasLastQuotaUnlimited());
    }

    public function testMissingDefaultQuotaIsTreatedAsUnlimited(): void {
        $this->userManager->method('get')->with('alice')->willReturn($this->userWithQuota('default'));
        $service = $this->service(fn(): int => 700);

        $this->assertNull($service->computeQuota('alice', 200));
        $this->assertNull($service->getLastError());
        $this->assertTrue($service->wasLastQuotaUnlimited());
    }

    public function testSmallRemainingQuotaIsStillAllocatedWhenReserveIsLarger(): void {
        $this->userManager->method('get')->with('alice')->willReturn($this->userWithQuota('100'));
        $service = $this->service(fn(): int => 50);

        $this->assertSame(50, $service->computeQuota('alice', 0));
        $this->assertNull($service->getLastError());
    }

    public function testReserveLargerThanRemainingCapacityUsesRealRemainingCapacity(): void {
        $this->adminConfig[AdminConfigService::KEY_QUOTA_RESERVE_BYTES] = 200;
        $this->userManager->method('get')->with('alice')->willReturn($this->userWithQuota('100'));
        $service = $this->service(fn(): int => 70);

        $this->assertSame(55, $service->computeQuota('alice', 25));
        $this->assertNull($service->getLastError());
    }

    public function testStaleOrUnavailableNextcloudUsageLeavesQuotaUnchanged(): void {
        $this->userManager->method('get')->with('alice')->willReturn($this->userWithQuota('1000'));
        $service = $this->service(fn(): null => null);

        $this->assertNull($service->computeQuota('alice', 200));
        $this->assertSame('Nextcloud total usage is unavailable.', $service->getLastError());
    }

    public function testTotalUsageLowerThanImmichUsageDoesNotSubtractNegativeNonImmichUsage(): void {
        $this->userManager->method('get')->with('alice')->willReturn($this->userWithQuota('1000'));
        $service = $this->service(fn(): int => 100);

        $this->assertSame(900, $service->computeQuota('alice', 500));
        $this->assertNull($service->getLastError());
    }

    private function service(callable $usageProvider): QuotaSyncService {
        return new QuotaSyncService(
            $this->userManager,
            $this->adminConfigService,
            $this->createMock(LoggerInterface::class),
            null,
            $usageProvider,
        );
    }

    private function userWithQuota(int|string $quota): IUser&MockObject {
        $user = $this->createMock(IUser::class);
        $user->method('getQuota')->willReturn($quota);
        return $user;
    }
}
