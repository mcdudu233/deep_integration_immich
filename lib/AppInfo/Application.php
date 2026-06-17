<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\AppInfo;

use OCA\IntegrationImmich\BackgroundJob\ScheduleQuotaSyncJob;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\IntegrationImmich\Listener\AccountUpdatedListener;
use OCA\IntegrationImmich\Listener\CspListener;
use OCA\IntegrationImmich\Listener\GroupMembershipListener;
use OCA\IntegrationImmich\Listener\LoadAdditionalScriptsListener;
use OCA\IntegrationImmich\Listener\UserChangedListener;
use OCA\IntegrationImmich\Listener\UserCreatedListener;
use OCA\IntegrationImmich\Listener\UserDeletedListener;
use OCA\IntegrationImmich\Mount\ImmichLibraryMountProvider;
use OCA\IntegrationImmich\Service\AdminConfigService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Config\IMountProviderCollection;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\Server;

class Application extends App implements IBootstrap {
    public const APP_ID = 'deep_integration_immich';

    private const DAILY_BACKGROUND_JOBS = [
        'OCA\\IntegrationImmich\\BackgroundJob\\ReconcileUsersJob',
        'OCA\\IntegrationImmich\\BackgroundJob\\VerifyProvisioningJob',
    ];

    private const LIFECYCLE_EVENT_LISTENERS = [
        'OCP\\User\\Events\\UserCreatedEvent' => UserCreatedListener::class,
        'OCP\\User\\Events\\UserChangedEvent' => UserChangedListener::class,
        'OCP\\User\\Events\\UserDeletedEvent' => UserDeletedListener::class,
        'OCP\\Accounts\\UserUpdatedEvent' => AccountUpdatedListener::class,
        'OCP\\Group\\Events\\UserAddedEvent' => GroupMembershipListener::class,
        'OCP\\Group\\Events\\UserRemovedEvent' => GroupMembershipListener::class,
        'OCP\\User\\GetQuotaEvent' => UserChangedListener::class,
        'OCP\\User\\Events\\UserIdAssignedEvent' => UserCreatedListener::class,
        'OCP\\User\\Events\\UserIdUnassignedEvent' => UserDeletedListener::class,
    ];

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(
            LoadAdditionalScriptsEvent::class,
            LoadAdditionalScriptsListener::class
        );
        $context->registerEventListener(
            AddContentSecurityPolicyEvent::class,
            CspListener::class
        );

        foreach (self::LIFECYCLE_EVENT_LISTENERS as $eventClass => $listenerClass) {
            if (class_exists($eventClass)) {
                $context->registerEventListener($eventClass, $listenerClass);
            }
        }
    }

    public function boot(IBootContext $context): void {
        $context->injectFn(function (IJobList $jobList, AdminConfigService $adminConfigService): void {
            $this->registerTimedJobs($jobList, $adminConfigService);
        });

        $context->injectFn(function (IMountProviderCollection $mountProviderCollection): void {
            $mountProviderCollection->registerProvider(Server::get(ImmichLibraryMountProvider::class));
        });
    }

    public function registerTimedJobs(IJobList $jobList, AdminConfigService $adminConfigService): void {
        $config = $adminConfigService->getAdminConfig();
        if (($config[AdminConfigService::KEY_PROVISIONING_ENABLED] ?? false) !== true) {
            return;
        }

        foreach (self::DAILY_BACKGROUND_JOBS as $jobClass) {
            $this->addJobIfAvailable($jobList, $jobClass, null);
        }

        $this->addJobIfAvailable($jobList, ScheduleQuotaSyncJob::class, null);
    }

    private function addJobIfAvailable(IJobList $jobList, string $jobClass, mixed $argument): void {
        if (!class_exists($jobClass) || $jobList->has($jobClass, $argument)) {
            return;
        }

        $jobList->add($jobClass, $argument);
    }
}
