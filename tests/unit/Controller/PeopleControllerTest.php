<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\PeopleController;
use OCA\IntegrationImmich\Service\BrowsingAuthService;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class PeopleControllerTest extends TestCase {

    private PeopleController $controller;
    private BrowsingAuthService&MockObject $browsingAuthService;
    private IClientService&MockObject $clientService;
    private IClient&MockObject $client;

    private const VALID_UUID = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

    protected function setUp(): void {
        parent::setUp();

        $this->browsingAuthService = $this->createMock(BrowsingAuthService::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->client = $this->createMock(IClient::class);

        $this->controller = new PeopleController(
            $this->createMock(IRequest::class),
            $this->clientService,
            $this->browsingAuthService,
            'testuser',
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testIndexReturns412WhenNotConfigured(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->unavailableCredentials());
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller->index();

        $this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('browsing_setup_not_configured', $data['code']);
        $this->assertSame('browsing_setup_personal_or_admin_proxy', $data['setupCode']);
        $this->assertSame('browsing_setup_not_configured', $data['details']['code']);
    }

    public function testIndexReturnsPeople(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse([
                ['id' => '11111111-1111-1111-1111-111111111111', 'name' => 'Alice'],
                ['id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Bob'],
            ]));

        $response = $this->controller->index();

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(2, $response->getData());
    }

    public function testIndexReturns500OnException(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->method('get')->willThrowException(new \Exception('Connection error'));

        $response = $this->controller->index();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }

    public function testAssetsReturns412WhenNotConfigured(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->unavailableCredentials());

        $response = $this->controller->assets(self::VALID_UUID);

        $this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
    }

    public function testAssetsReturnsPersonAssets(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->jsonResponse([['timeBucket' => '2024-01-01T00:00:00.000Z']]),
                $this->jsonResponse([['id' => self::VALID_UUID]]),
            );

        $response = $this->controller->assets(self::VALID_UUID);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $response->getData());
    }

    public function testAssetsReturns500OnException(): void {
        $this->browsingAuthService->method('resolveCredentials')->willReturn($this->personalCredentials());
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->client->method('get')->willThrowException(new \Exception('Timeout'));

        $response = $this->controller->assets(self::VALID_UUID);

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
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
