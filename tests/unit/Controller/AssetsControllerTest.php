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

class AssetsControllerTest extends TestCase {

	private AssetsController $controller;
	private BrowsingAuthService&MockObject $browsingAuthService;
	private IClientService&MockObject $clientService;
	private IClient&MockObject $client;
	private IRequest&MockObject $request;
	private LoggerInterface&MockObject $logger;
	private ActionPolicyService&MockObject $actionPolicyService;

	protected function setUp(): void {
		parent::setUp();

		$this->browsingAuthService = $this->createMock(BrowsingAuthService::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->actionPolicyService = $this->createMock(ActionPolicyService::class);
		$this->clientService->method('newClient')->willReturn($this->client);
		$this->actionPolicyService->method('isExportCopyEnabled')->willReturn(true);
		$this->actionPolicyService->method('isDeleteEnabled')->willReturn(true);
		$this->actionPolicyService->method('isPathInsideMirrorMount')->willReturn(false);

		$this->controller = new AssetsController(
			$this->request,
			$this->clientService,
			$this->browsingAuthService,
			$this->createMock(IRootFolder::class),
			'testuser',
			$this->actionPolicyService,
			$this->logger,
		);
	}

	// --- timeline() ---

	public function testTimelineReturns412WhenNotConfigured(): void {
		$this->browsingAuthService->method('resolveCredentials')->willReturn($this->unavailableCredentials());

		$response = $this->controller->timeline();

		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testTimelineReturnsBuckets(): void {
		$this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
		$this->request->method('getParam')->willReturnMap([
			['timeBucket', null, null],
			['size', 'MONTH', 'MONTH'],
			['personId', null, null],
			['assetType', null, null],
			['isFavorite', null, null],
		]);
		$this->client->expects($this->once())
			->method('get')
			->willReturn($this->jsonResponse([['count' => 5]]));

		$response = $this->controller->timeline();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertIsArray($response->getData());
	}

	public function testTimelineFiltersImageAssets(): void {
		$this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
		$this->request->method('getParam')->willReturnMap([
			['timeBucket', null, '2024-01-01T00:00:00.000Z'],
			['size', 'MONTH', 'MONTH'],
			['personId', null, null],
			['assetType', null, 'IMAGE'],
			['isFavorite', null, null],
		]);

		$assets = [
			['id' => 'uuid-1', 'isImage' => true],
			['id' => 'uuid-2', 'isImage' => false],
			['id' => 'uuid-3', 'isImage' => true],
		];
		$this->client->expects($this->once())
			->method('get')
			->willReturn($this->jsonResponse($assets));

		$response = $this->controller->timeline();
		$data = $response->getData();

		$this->assertCount(2, $data);
		$this->assertTrue($data[0]['isImage']);
		$this->assertTrue($data[1]['isImage']);
	}

	public function testTimelineFiltersVideoAssets(): void {
		$this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
		$this->request->method('getParam')->willReturnMap([
			['timeBucket', null, '2024-01-01T00:00:00.000Z'],
			['size', 'MONTH', 'MONTH'],
			['personId', null, null],
			['assetType', null, 'VIDEO'],
			['isFavorite', null, null],
		]);

		$assets = [
			['id' => 'uuid-1', 'isImage' => true],
			['id' => 'uuid-2', 'isImage' => false],
		];
		$this->client->expects($this->once())
			->method('get')
			->willReturn($this->jsonResponse($assets));

		$response = $this->controller->timeline();
		$data = $response->getData();

		$this->assertCount(1, $data);
		$this->assertFalse($data[0]['isImage']);
	}

	// --- update() ---

	public function testUpdateReturns412WhenNotConfigured(): void {
		$this->browsingAuthService->method('resolveCredentials')->willReturn($this->unavailableCredentials());

		$response = $this->controller->update('some-id');

		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
	}

	public function testUpdateReturns400OnInvalidUuid(): void {
		$this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());

		$response = $this->controller->update('not-a-valid-uuid');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testUpdateReturns400WhenNoValidFields(): void {
		$this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
		$this->request->method('getParams')->willReturn(['unknownField' => 'value']);

		$response = $this->controller->update('a1b2c3d4-e5f6-7890-abcd-ef1234567890');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateSucceedsWithValidFields(): void {
		$this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
		$this->request->method('getParams')->willReturn(['isFavorite' => true]);
		$this->client->expects($this->once())
			->method('put')
			->willReturn($this->jsonResponse(['id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890']));

		$response = $this->controller->update('a1b2c3d4-e5f6-7890-abcd-ef1234567890');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	// --- mapMarkers() ---

	public function testMapMarkersReturns412WhenNotConfigured(): void {
		$this->browsingAuthService->method('resolveCredentials')->willReturn($this->unavailableCredentials());

		$response = $this->controller->mapMarkers();

		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
	}

	public function testMapMarkersReturnsData(): void {
		$this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
		$this->client->expects($this->once())
			->method('get')
			->willReturn($this->jsonResponse([['lat' => 48.0, 'lon' => 11.0]]));

		$response = $this->controller->mapMarkers();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	private function personalCredentials(): array {
		return [
			'mode' => BrowsingAuthService::MODE_PERSONAL,
			'url' => 'https://photos.example.com',
			'apiKey' => 'personal-key',
			'immichUserId' => null,
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
