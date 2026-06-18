<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\Settings;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Controller\ConfigController;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCA\IntegrationImmich\Service\FrontendInitialStateService;
use OCA\IntegrationImmich\Service\ImmichService;
use OCA\IntegrationImmich\Service\SyncStateService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {
    public function __construct(
        private ImmichService $immichService,
        private FrontendInitialStateService $frontendInitialStateService,
        private IInitialState $initialState,
        private IUserSession $userSession,
        private AdminConfigService $adminConfigService,
        private SyncStateService $syncStateService,
        private ICrypto $crypto,
    ) {
    }

    public function getForm(): TemplateResponse {
        $uid = $this->userSession->getUser()?->getUID();
        $state = $this->frontendInitialStateService->buildUserState($uid);
        $state['server_url'] = $this->immichService->getServerUrl();
        $state['api_key_set'] = $this->immichService->getApiKey() !== '';
        // Pre-fill the admin-managed connection block so the Vue form renders the provisioned
        // username/password/API key on first paint instead of waiting for the async getConfig()
        // round-trip (which leaves the fields blank on initial render and entirely if it fails).
        $state['admin_managed_connection'] = ConfigController::buildAdminManagedConnectionState(
            $uid,
            $this->adminConfigService,
            $this->syncStateService,
            $this->crypto,
        );

        $this->initialState->provideInitialState('personal-config', $state);

        return new TemplateResponse(Application::APP_ID, 'personalSettings');
    }

    public function getSection(): string {
        return PersonalSection::SECTION_ID;
    }

    public function getPriority(): int {
        return 20;
    }
}
