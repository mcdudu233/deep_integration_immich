<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit;

use OCA\IntegrationImmich\Settings\AdminSection;
use OCA\IntegrationImmich\Settings\AdminSettings;
use OCA\IntegrationImmich\Settings\PersonalSection;
use OCA\IntegrationImmich\Settings\PersonalSettings;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\FrontendInitialStateService;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IURLGenerator;
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
        $adminConfigService = $this->createMock(AdminConfigService::class);
        $adminConfigService->method('getAdminConfig')->willReturn([
            AdminConfigService::KEY_IMMICH_BROWSING_MODE => AdminConfigService::BROWSING_MODE_PERSONAL,
            AdminConfigService::KEY_PROVISIONING_ENABLED => false,
        ]);
        $frontendInitialStateService = $this->createMock(FrontendInitialStateService::class);
        $frontendInitialStateService->method('buildAdminState')->willReturn([
            'server_url' => 'https://photos.example.com',
            'api_key_set' => false,
        ]);
        $frontendInitialStateService->method('buildUserState')->with('alice')->willReturn([
            'immich_url' => 'https://admin-photos.example.com',
            'browsingReadiness' => [
                'adminManaged' => true,
                'showPersonalSettings' => false,
            ],
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
        $personalSettings = new PersonalSettings($immichService, $adminConfigService, $frontendInitialStateService, $initialState, $userSession);

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
                'browsingReadiness' => [
                    'adminManaged' => true,
                    'showPersonalSettings' => false,
                ],
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

    public function testPersonalSettingsSectionIsHiddenWhenAdminManagedModeIsEnabled(): void {
        $immichService = $this->createMock(ImmichService::class);
        $adminConfigService = $this->createMock(AdminConfigService::class);
        $adminConfigService->method('getAdminConfig')->willReturn([
            AdminConfigService::KEY_IMMICH_BROWSING_MODE => AdminConfigService::BROWSING_MODE_ADMIN_MANAGED,
            AdminConfigService::KEY_PROVISIONING_ENABLED => true,
        ]);
        $frontendInitialStateService = $this->createMock(FrontendInitialStateService::class);
        $initialState = $this->createMock(IInitialState::class);
        $userSession = $this->createMock(IUserSession::class);

        $personalSettings = new PersonalSettings($immichService, $adminConfigService, $frontendInitialStateService, $initialState, $userSession);

        $this->assertNotSame(PersonalSection::SECTION_ID, $personalSettings->getSection());
    }

    public function testPersonalSettingsSectionIsVisibleInPersonalModeEvenWhenProvisioningIsEnabled(): void {
        $immichService = $this->createMock(ImmichService::class);
        $adminConfigService = $this->createMock(AdminConfigService::class);
        $adminConfigService->method('getAdminConfig')->willReturn([
            AdminConfigService::KEY_IMMICH_BROWSING_MODE => AdminConfigService::BROWSING_MODE_PERSONAL,
            AdminConfigService::KEY_PROVISIONING_ENABLED => true,
        ]);
        $frontendInitialStateService = $this->createMock(FrontendInitialStateService::class);
        $initialState = $this->createMock(IInitialState::class);
        $userSession = $this->createMock(IUserSession::class);

        $personalSettings = new PersonalSettings($immichService, $adminConfigService, $frontendInitialStateService, $initialState, $userSession);

        $this->assertSame(PersonalSection::SECTION_ID, $personalSettings->getSection());
    }

    public function testSettingsSectionsUseLocalizedNames(): void {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('imagePath')->willReturn('/apps/deep_integration_immich/img/app-dark.svg');

        $personalSection = new PersonalSection($l10n, $urlGenerator);
        $adminSection = new AdminSection($l10n, $urlGenerator);

        $this->assertSame('Immich Personal Connection', $personalSection->getName());
        $this->assertSame('Immich Administration', $adminSection->getName());
    }
}
