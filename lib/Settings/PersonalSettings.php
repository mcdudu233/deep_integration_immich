<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\Settings;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\FrontendInitialStateService;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {
    public function __construct(
        private ImmichService $immichService,
        private FrontendInitialStateService $frontendInitialStateService,
        private IInitialState $initialState,
        private IUserSession $userSession,
    ) {
    }

    public function getForm(): TemplateResponse {
        $state = $this->frontendInitialStateService->buildUserState($this->userSession->getUser()?->getUID());
        $state['server_url'] = $this->immichService->getServerUrl();
        $state['api_key_set'] = $this->immichService->getApiKey() !== '';

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
