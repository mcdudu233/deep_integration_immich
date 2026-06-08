<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit;

use OCA\IntegrationImmich\Settings\AdminSection;
use OCA\IntegrationImmich\Settings\AdminSettings;
use OCA\IntegrationImmich\Settings\PersonalSection;
use OCA\IntegrationImmich\Settings\PersonalSettings;
use OCA\IntegrationImmich\Service\FrontendInitialStateService;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUser;
use OCP\IUserSession;
use Test\TestCase;

class SettingsRegistrationTest extends TestCase {
    public function testInfoXmlRegistersBothAdminAndPersonalSettings(): void {
        $xml = simplexml_load_file(__DIR__ . '/../../appinfo/info.xml');

        $this->assertNotFalse($xml);
        $this->assertSame('OCA\\IntegrationImmich\\Settings\\AdminSettings', trim((string)$xml->settings->admin));
        $this->assertSame('OCA\\IntegrationImmich\\Settings\\AdminSection', trim((string)$xml->settings->{'admin-section'}));
        $this->assertSame('OCA\\IntegrationImmich\\Settings\\PersonalSettings', trim((string)$xml->settings->personal));
        $this->assertSame('OCA\\IntegrationImmich\\Settings\\PersonalSection', trim((string)$xml->settings->{'personal-section'}));
        $this->assertStringContainsString('admin provisioning', (string)$xml->description);
        $this->assertStringContainsString('Export copy to Nextcloud', (string)$xml->description);
        $this->assertStringContainsString('Import into Immich (opt-in)', (string)$xml->description);
        $this->assertStringNotContainsString('real-time', (string)$xml->description);
        $this->assertStringNotContainsString('guaranteed', (string)$xml->description);
    }

    public function testSettingsClassesUseDistinctSectionsPrioritiesAndTemplates(): void {
        $immichService = $this->createMock(ImmichService::class);
        $immichService->method('getServerUrl')->willReturn('https://photos.example.com');
        $immichService->method('getApiKey')->willReturn('');
        $frontendInitialStateService = $this->createMock(FrontendInitialStateService::class);
        $frontendInitialStateService->method('buildAdminState')->willReturn([
            'server_url' => 'https://photos.example.com',
            'api_key_set' => false,
        ]);
        $frontendInitialStateService->method('buildUserState')->with('alice')->willReturn([
            'immich_url' => 'https://admin-photos.example.com',
            'actionCapabilities' => [],
        ]);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $initialState = $this->createMock(IInitialState::class);
        $calls = [];
        $initialState->expects($this->exactly(2))
            ->method('provideInitialState')
            ->willReturnCallback(static function (string $key, array $data) use (&$calls): void {
                $calls[] = [$key, $data];
            });

        $adminSettings = new AdminSettings($frontendInitialStateService, $initialState);
        $personalSettings = new PersonalSettings($immichService, $frontendInitialStateService, $initialState, $userSession);

        $this->assertSame(AdminSection::SECTION_ID, $adminSettings->getSection());
        $this->assertSame(PersonalSection::SECTION_ID, $personalSettings->getSection());
        $this->assertNotSame($adminSettings->getSection(), $personalSettings->getSection());
        $this->assertSame(10, $adminSettings->getPriority());
        $this->assertSame(20, $personalSettings->getPriority());

        $adminForm = $adminSettings->getForm();
        $personalForm = $personalSettings->getForm();

        $this->assertSame([
            ['admin-config', ['server_url' => 'https://photos.example.com', 'api_key_set' => false]],
            ['personal-config', [
                'immich_url' => 'https://admin-photos.example.com',
                'actionCapabilities' => [],
                'server_url' => 'https://photos.example.com',
                'api_key_set' => false,
            ]],
        ], $calls);
        $this->assertInstanceOf(TemplateResponse::class, $adminForm);
        $this->assertInstanceOf(TemplateResponse::class, $personalForm);
        $this->assertSame('adminSettings', $adminForm->getTemplateName());
        $this->assertSame('personalSettings', $personalForm->getTemplateName());
    }
}
