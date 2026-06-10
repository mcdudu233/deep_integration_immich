<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\IntegrationImmich\Service\PathTemplateService;
use Test\TestCase;

class PathTemplateServiceTest extends TestCase {
	private PathTemplateService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->service = new PathTemplateService();
	}

	/**
	 * @dataProvider validStorageLabelProvider
	 */
	public function testSanitizeStorageLabelAcceptsSafeLabels(string $input, string $expected): void {
		$this->assertSame($expected, $this->service->sanitizeStorageLabel($input));
	}

	public static function validStorageLabelProvider(): array {
		return [
			['alice', 'alice'],
			['admin', 'admin'],
			['immich-e2e-test', 'immich-e2e-test'],
			['john.doe', 'john.doe'],
			['john-doe_1', 'john-doe_1'],
			[' alice ', 'alice'],
			['.alice.', 'alice'],
			[' ...team.photos... ', 'team.photos'],
		];
	}

	/**
	 * @dataProvider invalidStorageLabelProvider
	 */
	public function testSanitizeStorageLabelRejectsUnsafeLabels(string $input): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->sanitizeStorageLabel($input);
	}

	public static function invalidStorageLabelProvider(): array {
		return [
			['../alice'],
			['alice..bob'],
			['alice/../bob'],
			['.'],
			['..'],
			['...'],
			[''],
			['   '],
			["alice\0bob"],
			['alice/bob'],
			['alice\\bob'],
			['john doe'],
			['álîçé'],
		];
	}

	public function testExpandStorageLabelTemplateUsesSanitizedUid(): void {
		$this->assertSame('nc-alice', $this->service->expandStorageLabelTemplate('nc-{uid}', 'alice'));
		$this->assertSame('library-alice', $this->service->expandStorageLabelTemplate('library-{storageLabel}', 'alice'));
		$this->assertSame('admin', $this->service->expandStorageLabelTemplate('{uid}', 'admin'));
		$this->assertSame('immich-e2e-test', $this->service->expandStorageLabelTemplate('{uid}', 'immich-e2e-test'));
	}

	public function testDetectsUuidLikeStorageLabels(): void {
		$this->assertTrue($this->service->isUuidLikeStorageLabel('550e8400-e29b-41d4-a716-446655440000'));
		$this->assertFalse($this->service->isUuidLikeStorageLabel('immich-e2e-test'));
		$this->assertFalse($this->service->isUuidLikeStorageLabel('alice'));
	}

	public function testExpandStorageLabelTemplateRejectsUnsafeOutput(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->expandStorageLabelTemplate('../{uid}', 'alice');
	}

	public function testExpandPathTemplateReplacesKnownPlaceholders(): void {
		$this->assertSame(
			'/srv/immich/originals/library/alice',
			$this->service->expandPathTemplate('/srv/immich/originals/library/{storageLabel}', 'alice', 'alice')
		);
		$this->assertSame(
			'Immich/alice',
			$this->service->expandPathTemplate('Immich/{uid}', 'alice', 'alice')
		);
	}

	public function testExpandPathTemplateNormalizesBackslashesWithoutFilesystemAccess(): void {
		$this->assertSame(
			'C:/mnt/immich-library/john-doe_1',
			$this->service->expandPathTemplate('C:\\mnt\\immich-library\\{storageLabel}', 'john', 'john-doe_1')
		);
	}

	/**
	 * @dataProvider invalidTemplateProvider
	 */
	public function testExpandPathTemplateRejectsUnsafeTemplates(string $template): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->expandPathTemplate($template, 'alice', 'alice');
	}

	public static function invalidTemplateProvider(): array {
		return [
			['/srv/immich/../library/{storageLabel}'],
			["/srv/immich/\0/{storageLabel}"],
			['..'],
			['.'],
		];
	}

	public function testExpandPathTemplateRejectsTraversalInjectedByUid(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->expandPathTemplate('/srv/immich/library/{uid}', '../alice', 'alice');
	}

	public function testExpandPathTemplateRejectsInvalidStorageLabelPlaceholderValue(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->expandPathTemplate('/srv/immich/library/{storageLabel}', 'alice', 'alice/bob');
	}

	public function testValidatePathUnderBaseAcceptsPathInsideBase(): void {
		$this->service->validatePathUnderBase('/srv/immich/originals/library/alice', '/srv/immich/originals/library');

		$this->addToAssertionCount(1);
	}

	public function testValidatePathUnderBaseAcceptsBasePathItself(): void {
		$this->service->validatePathUnderBase('/srv/immich/originals/library', '/srv/immich/originals/library/');

		$this->addToAssertionCount(1);
	}

	/**
	 * @dataProvider escapingPathProvider
	 */
	public function testValidatePathUnderBaseRejectsEscapingPaths(string $expandedPath, string $basePath): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->validatePathUnderBase($expandedPath, $basePath);
	}

	public static function escapingPathProvider(): array {
		return [
			['/srv/immich/originals/library2/alice', '/srv/immich/originals/library'],
			['/srv/immich/originals/library/../alice', '/srv/immich/originals/library'],
			['relative/alice', '/srv/immich/originals/library'],
			["/srv/immich/originals/library/alice\0evil", '/srv/immich/originals/library'],
		];
	}

	public function testValidatePathUnderBaseHandlesWindowsStyleAbsolutePaths(): void {
		$this->service->validatePathUnderBase('C:\\mnt\\immich-library\\alice', 'C:/mnt/immich-library');

		$this->addToAssertionCount(1);
	}

	public function testValidatePathUnderBaseRejectsWindowsSiblingWithSharedPrefix(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->validatePathUnderBase('C:/mnt/immich-library2/alice', 'C:/mnt/immich-library');
	}

	public function testValidatePathUnderBaseAcceptsRootBasePaths(): void {
		$this->service->validatePathUnderBase('/srv/immich/originals/library/alice', '/');
		$this->service->validatePathUnderBase('C:/mnt/immich-library/alice', 'C:/');

		$this->addToAssertionCount(2);
	}

	public function testStorageLabelCollisionUsesTemporaryOwnerMap(): void {
		$service = new PathTemplateService(['alice' => 'alice-uid']);

		$this->assertTrue($service->isStorageLabelCollision('alice'));
		$this->assertFalse($service->isStorageLabelCollision('alice', 'alice-uid'));
		$this->assertFalse($service->isStorageLabelCollision('bob'));
	}
}
