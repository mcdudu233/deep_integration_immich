<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\Controller;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\FrontendInitialStateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;

class PageController extends Controller {
    public function __construct(
        IRequest $request,
        private IInitialState $initialState,
        private FrontendInitialStateService $frontendInitialStateService,
        private ?string $userId,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        $state = $this->frontendInitialStateService->buildUserState($this->userId);
        $state['server_url'] = $state['immich_url'];

        $this->initialState->provideInitialState('user-config', $state);
        return new TemplateResponse(Application::APP_ID, 'main');
    }
}
