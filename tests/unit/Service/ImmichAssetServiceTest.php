<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Service;

use OCA\IntegrationImmich\Service\ImmichAssetService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ImmichAssetServiceTest extends TestCase {
    private IClientService&MockObject $clientService;
    private IClient&MockObject $client;

    protected function setUp(): void {
        parent::setUp();

        $this->clientService = $this->createMock(IClientService::class);
        $this->client = $this->createMock(IClient::class);
        $this->clientService->method('newClient')->willReturn($this->client);
    }

    public function testGetAssetUsesCredentialResolverForUrlAndApiKey(): void {
        $resolverCalls = 0;
        $service = $this->newService(function () use (&$resolverCalls): array {
            $resolverCalls++;
            return ['url' => 'https://photos.example.com/', 'apiKey' => 'personal-key'];
        });
        $this->client->expects($this->once())
            ->method('get')
            ->with('https://photos.example.com/api/assets/asset-1', $this->callback(function (array $options): bool {
                $this->assertSame('personal-key', $options['headers']['x-api-key']);
                $this->assertSame('application/json', $options['headers']['Accept']);
                $this->assertFalse($options['http_errors']);
                return true;
            }))
            ->willReturn($this->response(200, ['id' => 'asset-1']));

        $asset = $service->getAsset('asset-1');

        $this->assertSame('asset-1', $asset['id']);
        $this->assertGreaterThanOrEqual(1, $resolverCalls);
    }

    public function testTimelineBucketNormalisesShortDateAndTransformsColumnarResponse(): void {
        $service = $this->newService();
        $this->client->expects($this->once())
            ->method('get')
            ->with($this->callback(function (string $url): bool {
                $this->assertStringStartsWith('https://photos.example.com/api/timeline/bucket?', $url);
                parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
                $this->assertSame('2024-01-01T00:00:00.000Z', $query['timeBucket']);
                $this->assertSame('MONTH', $query['size']);
                $this->assertSame('person-1', $query['personId']);
                $this->assertSame('IMAGE', $query['assetType']);
                $this->assertSame('true', $query['isFavorite']);
                return true;
            }), $this->anything())
            ->willReturn($this->response(200, [
                'id' => ['a', 'b'],
                'type' => ['IMAGE', 'VIDEO'],
                'ownerId' => 'owner-1',
            ]));

        $assets = $service->getTimelineBucket('2024-01-01', 'MONTH', 'person-1', 'IMAGE', true);

        $this->assertSame([
            ['id' => 'a', 'type' => 'IMAGE', 'ownerId' => 'owner-1'],
            ['id' => 'b', 'type' => 'VIDEO', 'ownerId' => 'owner-1'],
        ], $assets);
    }

    public function testDownloadArchivePreservesBinaryPostEndpointAndPayload(): void {
        $service = $this->newService();
        $this->client->expects($this->once())
            ->method('post')
            ->with('https://photos.example.com/api/download/archive', $this->callback(function (array $options): bool {
                $this->assertSame('personal-key', $options['headers']['x-api-key']);
                $this->assertSame('application/octet-stream', $options['headers']['Accept']);
                $this->assertSame('application/json', $options['headers']['Content-Type']);
                $this->assertSame(['assetIds' => ['a', 'b']], json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR));
                return true;
            }))
            ->willReturn($this->binaryResponse(200, 'zip-bytes', ['Content-Type' => 'application/zip']));

        $archive = $service->downloadArchive(['a', 'b']);

        $this->assertSame('zip-bytes', $archive['body']);
        $this->assertSame('application/zip', $archive['contentType']);
    }

    public function testVideoStreamPreservesRangeHeaderAndResponseMetadata(): void {
        $service = $this->newService();
        $this->client->expects($this->once())
            ->method('get')
            ->with('https://photos.example.com/api/assets/video-1/video/playback', $this->callback(function (array $options): bool {
                $this->assertSame('personal-key', $options['headers']['x-api-key']);
                $this->assertSame('bytes=1-20', $options['headers']['Range']);
                $this->assertFalse($options['http_errors']);
                return true;
            }))
            ->willReturn($this->binaryResponse(206, 'video-bytes', [
                'Content-Type' => 'video/mp4',
                'Content-Length' => '20',
                'Content-Range' => 'bytes 1-20/100',
                'Accept-Ranges' => 'bytes',
            ]));

        $stream = $service->getVideoStream('video-1', 'bytes=1-20');

        $this->assertSame(206, $stream['statusCode']);
        $this->assertSame('video-bytes', $stream['body']);
        $this->assertSame('bytes 1-20/100', $stream['contentRange']);
    }

    public function testValidateConnectionReportsMissingPermissions(): void {
        $service = $this->newService();
        $this->client->expects($this->once())
            ->method('post')
            ->with('https://photos.example.com/api/auth/validateToken', $this->anything())
            ->willReturn($this->response(200, ['permissions' => ['asset.view']]));

        $result = $service->validateConnection();

        $this->assertTrue($result['success']);
        $this->assertContains('asset.read', $result['missing_permissions']);
        $this->assertContains('album.read', $result['missing_permissions']);
    }

    public function testForbiddenJsonEndpointReturnsEmptyArrayForCompatibility(): void {
        $service = $this->newService();
        $this->client->expects($this->once())
            ->method('get')
            ->with('https://photos.example.com/api/albums', $this->anything())
            ->willReturn($this->response(403, ['message' => 'missing permission']));

        $this->assertSame([], $service->getAlbums());
    }

    public function testPeopleEndpointUnwrapsImmichPeopleEnvelope(): void {
        $service = $this->newService();
        $this->client->expects($this->once())
            ->method('get')
            ->with('https://photos.example.com/api/people', $this->anything())
            ->willReturn($this->response(200, [
                'people' => [
                    ['id' => 'person-1', 'name' => 'Alice'],
                ],
            ]));

        $this->assertSame([['id' => 'person-1', 'name' => 'Alice']], $service->getPeople());
    }

    private function newService(?callable $resolver = null): ImmichAssetService {
        return new ImmichAssetService(
            $this->clientService,
            $this->createMock(LoggerInterface::class),
            $resolver ?? fn (): array => ['url' => 'https://photos.example.com', 'apiKey' => 'personal-key'],
        );
    }

    private function response(int $statusCode, array $body): IResponse&MockObject {
        return $this->binaryResponse($statusCode, json_encode($body, JSON_THROW_ON_ERROR), []);
    }

    private function binaryResponse(int $statusCode, string $body, array $headers): IResponse&MockObject {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($body);
        $response->method('getHeader')->willReturnCallback(
            fn (string $header): string => $headers[$header] ?? ''
        );
        return $response;
    }
}
