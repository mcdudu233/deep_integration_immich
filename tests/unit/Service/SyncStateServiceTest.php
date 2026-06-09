<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Service;

use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\IntegrationImmich\Db\SyncState;
use OCA\IntegrationImmich\Db\SyncStateMapper;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Db\DoesNotExistException;
use Test\TestCase;

class SyncStateServiceTest extends TestCase {
    private InMemorySyncStateMapper $mapper;
    private SyncStateService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->mapper = new InMemorySyncStateMapper();
        $this->service = new SyncStateService($this->mapper);
    }

    public function testGetOrCreateCreatesDefaultMapping(): void {
        $state = $this->service->getOrCreateForUid('alice');

        $this->assertSame(1, $state->getId());
        $this->assertSame('alice', $state->getNcUid());
        $this->assertSame('alice', $state->getStorageLabel());
        $this->assertSame(SyncStateService::STATUS_PENDING, $state->getScopeStatus());
        $this->assertSame(SyncStateService::STATUS_PENDING, $state->getLastSyncStatus());
        $this->assertNull($state->getImmichUserId());
        $this->assertNull($state->getNcMountId());
        $this->assertCount(1, $this->mapper->all());
    }

    public function testGetOrCreateIsIdempotentForExistingUid(): void {
        $first = $this->service->getOrCreateForUid('alice');
        $second = $this->service->getOrCreateForUid('alice');

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame('alice', $second->getNcUid());
        $this->assertCount(1, $this->mapper->all());
    }

    public function testGetOrCreateHandlesConcurrentDuplicateInsert(): void {
        $this->mapper->onInsertAttempt(function (SyncState $pending): void {
            $concurrent = new SyncState();
            $concurrent->setNcUid($pending->getNcUid());
            $concurrent->setStorageLabel($pending->getStorageLabel());
            $this->mapper->saveConcurrent($concurrent);
        });

        $state = $this->service->getOrCreateForUid('alice');

        $this->assertSame('alice', $state->getNcUid());
        $this->assertSame(1, $state->getId());
        $this->assertCount(1, $this->mapper->all());
    }

    public function testUpdateStatusPersistsStatusAndError(): void {
        $this->service->getOrCreateForUid('alice');

        $this->service->updateStatus('alice', SyncStateService::STATUS_ACTIVE, SyncStateService::STATUS_MOUNT_PENDING, 'mount is pending');

        $state = $this->service->findByUid('alice');
        $this->assertInstanceOf(SyncState::class, $state);
        $this->assertSame(SyncStateService::STATUS_ACTIVE, $state->getScopeStatus());
        $this->assertSame(SyncStateService::STATUS_MOUNT_PENDING, $state->getLastSyncStatus());
        $this->assertSame('mount is pending', $state->getLastError());
    }

    public function testUpdateMappingPersistsExternalIdentifiers(): void {
        $this->service->getOrCreateForUid('alice');

        $this->service->updateMapping('alice', [
            'immichUserId' => 'immich-alice',
            'immichEmail' => 'alice@example.com',
            'storageLabel' => 'alice.photos',
            'ncMountId' => 42,
        ]);

        $state = $this->service->findByUid('alice');
        $this->assertInstanceOf(SyncState::class, $state);
        $this->assertSame('immich-alice', $state->getImmichUserId());
        $this->assertSame('alice@example.com', $state->getImmichEmail());
        $this->assertSame('alice.photos', $state->getStorageLabel());
        $this->assertSame(42, $state->getNcMountId());
        $this->assertSame($state, $this->service->findByImmichUserId('immich-alice'));
        $this->assertSame($state, $this->service->findByStorageLabel('alice.photos'));
    }

    public function testSyncStateAcceptsImmutableAndHydratedDatetimeValues(): void {
        $state = new SyncState();
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $state->setLastQuotaSyncAt($now);
        $this->assertInstanceOf(DateTime::class, $state->getLastQuotaSyncAt());
        $this->assertSame($now->format(\DateTimeInterface::ATOM), $state->getLastQuotaSyncAt()?->format(\DateTimeInterface::ATOM));

        $state->setCreatedAt('2026-01-02T00:00:00+00:00');
        $this->assertInstanceOf(DateTime::class, $state->getCreatedAt());
        $this->assertSame('2026-01-02T00:00:00+00:00', $state->getCreatedAt()->format(\DateTimeInterface::ATOM));
    }

    /**
     * @dataProvider terminalStatusProvider
     */
    public function testUidReuseRequiresExplicitAdminReconcile(string $terminalStatus): void {
        $this->service->getOrCreateForUid('alice');
        $this->service->updateMapping('alice', ['scopeStatus' => $terminalStatus]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires explicit admin reconcile');

        $this->service->getOrCreateForUid('alice');
    }

    public function testDisabledUidCannotBeReactivatedImplicitly(): void {
        $this->service->getOrCreateForUid('alice');
        $this->service->updateMapping('alice', ['scopeStatus' => SyncStateService::STATUS_DISABLED]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires explicit admin reconcile');

        $this->service->updateStatus('alice', SyncStateService::STATUS_ACTIVE, SyncStateService::STATUS_ACTIVE);
    }

    public function testUnknownStatusIsRejected(): void {
        $this->service->getOrCreateForUid('alice');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported sync state status');

        $this->service->updateStatus('alice', 'unknown', SyncStateService::STATUS_PENDING);
    }

    public function testUpdateMappingRejectsStorageLabelCollision(): void {
        $this->service->getOrCreateForUid('alice');
        $this->service->getOrCreateForUid('bob');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conflicted');

        $this->service->updateMapping('bob', ['storageLabel' => 'alice']);
    }

    public function testEmptyUidIsRejectedBeforeMapperAccess(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nextcloud user id must not be empty');

        $this->service->getOrCreateForUid('');
    }

    public static function terminalStatusProvider(): array {
        return [
            'disabled' => [SyncStateService::STATUS_DISABLED],
            'deleted' => [SyncStateService::STATUS_DELETED],
        ];
    }
}

final class InMemorySyncStateMapper extends SyncStateMapper {
    /** @var array<string, SyncState> */
    private array $byUid = [];
    private int $nextId = 1;
    /** @var callable|null */
    private $onInsertAttempt = null;

    public function __construct() {
    }

    public function findByUid(string $uid): SyncState {
        if (!isset($this->byUid[$uid])) {
            throw new DoesNotExistException('No sync state for uid ' . $uid);
        }

        return $this->byUid[$uid];
    }

    public function findByImmichUserId(string $id): SyncState {
        foreach ($this->byUid as $state) {
            if ($state->getImmichUserId() === $id) {
                return $state;
            }
        }

        throw new DoesNotExistException('No sync state for Immich user id ' . $id);
    }

    public function findByStorageLabel(string $label): SyncState {
        foreach ($this->byUid as $state) {
            if ($state->getStorageLabel() === $label) {
                return $state;
            }
        }

        throw new DoesNotExistException('No sync state for storage label ' . $label);
    }

    public function insertState(SyncState $syncState): SyncState {
        if ($this->onInsertAttempt !== null) {
            ($this->onInsertAttempt)($syncState);
            $this->onInsertAttempt = null;
        }

        $this->assertUnique($syncState);
        $syncState->setId($this->nextId++);
        $this->byUid[$syncState->getNcUid()] = $syncState;

        return $syncState;
    }

    public function updateState(SyncState $syncState): SyncState {
        if (!isset($this->byUid[$syncState->getNcUid()])) {
            throw new DoesNotExistException('No sync state for uid ' . $syncState->getNcUid());
        }

        $this->assertUnique($syncState, $syncState->getNcUid());
        $this->byUid[$syncState->getNcUid()] = $syncState;

        return $syncState;
    }

    public function onInsertAttempt(callable $callback): void {
        $this->onInsertAttempt = $callback;
    }

    public function saveConcurrent(SyncState $syncState): void {
        $this->assertUnique($syncState);
        $syncState->setId($this->nextId++);
        $this->byUid[$syncState->getNcUid()] = $syncState;
    }

    /** @return SyncState[] */
    public function all(): array {
        return array_values($this->byUid);
    }

    private function assertUnique(SyncState $candidate, ?string $sameUid = null): void {
        foreach ($this->byUid as $uid => $state) {
            if ($sameUid !== null && $uid === $sameUid) {
                continue;
            }
            if ($uid === $candidate->getNcUid()
                || $state->getStorageLabel() === $candidate->getStorageLabel()
                || ($candidate->getImmichUserId() !== null && $state->getImmichUserId() === $candidate->getImmichUserId())
                || ($candidate->getNcMountId() !== null && $state->getNcMountId() === $candidate->getNcMountId())
            ) {
                throw new TestUniqueConstraintViolationException();
            }
        }
    }
}

final class TestUniqueConstraintViolationException extends UniqueConstraintViolationException {
    public function __construct() {
    }
}
