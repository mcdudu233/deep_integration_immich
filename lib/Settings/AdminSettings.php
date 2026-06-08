<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\Settings;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\FrontendInitialStateService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    public function __construct(
        private FrontendInitialStateService $frontendInitialStateService,
        private IInitialState $initialState,
    ) {
    }

    public function getForm(): TemplateResponse {
        $this->initialState->provideInitialState('admin-config', $this->frontendInitialStateService->buildAdminState());

        return new TemplateResponse(Application::APP_ID, 'adminSettings');
    }

    public function getSection(): string {
        return AdminSection::SECTION_ID;
    }

    public function getPriority(): int {
        return 10;
    }
}
