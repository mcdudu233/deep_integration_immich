<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\AuthHandoffController;
use OCA\IntegrationImmich\Http\SharedCookieRedirectResponse;
use OCA\IntegrationImmich\Service\BrowsingAuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use Test\TestCase;

class AuthHandoffControllerTest extends TestCase {
    private BrowsingAuthService&MockObject $browsingAuthService;
    private IRequest&MockObject $request;

    protected function setUp(): void {
        parent::setUp();

        $this->browsingAuthService = $this->createMock(BrowsingAuthService::class);
        $this->request = $this->createMock(IRequest::class);
    }

    public function testOpenImmichRedirectsWithSharedParentDomainCookie(): void {
        $handoff = [
            'status' => BrowsingAuthService::HANDOFF_READY,
            'url' => 'https://photos.example.com',
            'username' => 'alice@immich.local',
            'password' => 'generated-password',
            'immichUserId' => 'immich-alice',
        ];
        $this->browsingAuthService->expects($this->once())
            ->method('resolveLegacyPasswordLoginHandoff')
            ->with('alice')
            ->willReturn($handoff);
        $this->browsingAuthService->expects($this->once())
            ->method('createImmichLoginSession')
            ->with($handoff)
            ->willReturn([
                'success' => true,
                'redirectUrl' => 'https://photos.example.com',
                'setCookie' => 'immich_access_token=session-value; Path=/; HttpOnly; SameSite=Lax',
            ]);
        $this->request->method('getServerHost')->willReturn('cloud.example.com');

        $response = $this->controller('alice')->openImmich();

        $this->assertInstanceOf(SharedCookieRedirectResponse::class, $response);
        $this->assertSame('https://photos.example.com', $response->getRedirectURL());
        $this->assertSame([
            'immich_access_token=session-value; Path=/; HttpOnly; SameSite=Lax; Domain=example.com',
            'immich_auth_type=password; Path=/; HttpOnly; SameSite=Lax; Domain=example.com',
            'immich_is_authenticated=true; Path=/; SameSite=Lax; Domain=example.com',
        ], $response->getSetCookies());
        $this->assertStringNotContainsString('generated-password', json_encode($response, JSON_THROW_ON_ERROR));
    }

    public function testOpenImmichRejectsUnrelatedParentDomainCookie(): void {
        $handoff = [
            'status' => BrowsingAuthService::HANDOFF_READY,
            'url' => 'https://photos.example.net/',
            'username' => 'alice@immich.local',
            'password' => 'generated-password',
            'immichUserId' => 'immich-alice',
        ];
        $this->browsingAuthService->method('resolveLegacyPasswordLoginHandoff')->with('alice')->willReturn($handoff);
        $this->browsingAuthService->method('createImmichLoginSession')->willReturn([
            'success' => true,
            'redirectUrl' => 'https://photos.example.net',
            'setCookie' => 'immich_access_token=session-value; Path=/; HttpOnly',
        ]);
        $this->request->method('getServerHost')->willReturn('cloud.example.com');

        $response = $this->controller('alice')->openImmich();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
        $this->assertSame(BrowsingAuthService::HANDOFF_LOGIN_FAILED, $response->getData()['error']['code']);
    }

    public function testOpenImmichReturnsSafeErrorForMissingCredentials(): void {
        $this->browsingAuthService->method('resolveLegacyPasswordLoginHandoff')->with('alice')->willReturn([
            'status' => BrowsingAuthService::HANDOFF_CREDENTIALS_MISSING,
            'url' => '',
            'username' => null,
            'password' => null,
            'immichUserId' => null,
        ]);
        $this->browsingAuthService->expects($this->never())->method('createImmichLoginSession');

        $response = $this->controller('alice')->openImmich();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
        $this->assertSame(BrowsingAuthService::HANDOFF_CREDENTIALS_MISSING, $response->getData()['error']['code']);
        $this->assertStringNotContainsString('password', json_encode($response->getData(), JSON_THROW_ON_ERROR));
    }

    public function testOpenImmichRejectsUnsafeRedirectTargets(): void {
        $handoff = [
            'status' => BrowsingAuthService::HANDOFF_READY,
            'url' => 'javascript:alert(1)',
            'username' => 'alice@immich.local',
            'password' => 'generated-password',
            'immichUserId' => 'immich-alice',
        ];
        $this->browsingAuthService->method('resolveLegacyPasswordLoginHandoff')->with('alice')->willReturn($handoff);
        $this->browsingAuthService->expects($this->never())->method('createImmichLoginSession');

        $response = $this->controller('alice')->openImmich();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
        $this->assertSame(BrowsingAuthService::HANDOFF_LOGIN_FAILED, $response->getData()['error']['code']);
        $this->assertStringNotContainsString('generated-password', json_encode($response->getData(), JSON_THROW_ON_ERROR));
    }

    public function testRouteIsReadOnlyNoAdminRequiredAndNoCsrfRequired(): void {
        $reflection = new ReflectionClass(AuthHandoffController::class);
        $method = $reflection->getMethod('openImmich');

        $this->assertNotEmpty($method->getAttributes(NoAdminRequired::class));
        $this->assertNotEmpty($method->getAttributes(NoCSRFRequired::class));
    }

    private function controller(?string $uid): AuthHandoffController {
        return new AuthHandoffController($this->request, $this->browsingAuthService, $uid);
    }
}
