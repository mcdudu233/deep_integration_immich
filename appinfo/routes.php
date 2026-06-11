<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

return [
    'routes' => [
        // Main page (Vue SPA)
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Admin Config
        ['name' => 'config#setConfig', 'url' => '/api/v1/config', 'verb' => 'PUT'],
        ['name' => 'config#getConfig', 'url' => '/api/v1/config', 'verb' => 'GET'],

        // User auth handoff
        ['name' => 'auth_handoff#openImmich', 'url' => '/auth/open-immich', 'verb' => 'GET'],

        // Admin Orchestration
        ['name' => 'admin_settings#getConfig',          'url' => '/api/v1/admin/config',                                  'verb' => 'GET'],
        ['name' => 'admin_settings#setConfig',          'url' => '/api/v1/admin/config',                                  'verb' => 'PUT'],
        ['name' => 'admin_settings#validateConnection', 'url' => '/api/v1/admin/config/validate-connection',              'verb' => 'POST'],
        ['name' => 'admin_provisioning#dryRun',         'url' => '/api/v1/admin/provisioning/dry-run/{ncUid}',             'verb' => 'GET'],
        ['name' => 'admin_provisioning#dryRunAll',      'url' => '/api/v1/admin/provisioning/dry-run',                     'verb' => 'GET'],
        ['name' => 'admin_provisioning#reconcileOne',   'url' => '/api/v1/admin/provisioning/reconcile/{ncUid}',           'verb' => 'POST'],
        ['name' => 'admin_provisioning#reconcileAll',   'url' => '/api/v1/admin/provisioning/reconcile',                   'verb' => 'POST'],
        ['name' => 'admin_provisioning#recomputeQuotaOne', 'url' => '/api/v1/admin/provisioning/quota/{ncUid}',            'verb' => 'POST'],
        ['name' => 'admin_provisioning#recomputeQuotaAll', 'url' => '/api/v1/admin/provisioning/quota',                    'verb' => 'POST'],
        ['name' => 'admin_provisioning#listSyncState',  'url' => '/api/v1/admin/provisioning/sync-state',                 'verb' => 'GET'],
        ['name' => 'admin_provisioning#verifyHealth',   'url' => '/api/v1/admin/provisioning/health/{ncUid}',              'verb' => 'POST'],

        // Assets / Timeline
        ['name' => 'assets#timeline',        'url' => '/api/v1/timeline',                    'verb' => 'GET'],
        ['name' => 'assets#downloadAssets',  'url' => '/api/v1/download',                    'verb' => 'POST'],
        ['name' => 'assets#saveToNextcloud', 'url' => '/api/v1/assets/save',                 'verb' => 'POST'],
        ['name' => 'assets#deleteAssets',    'url' => '/api/v1/assets/delete',               'verb' => 'POST'],
        ['name' => 'assets#update',          'url' => '/api/v1/assets/{id}/update',          'verb' => 'POST'],
        ['name' => 'assets#show',            'url' => '/api/v1/assets/{id}/info',            'verb' => 'GET'],
        ['name' => 'assets#thumbnail',       'url' => '/api/v1/assets/{id}/thumbnail',       'verb' => 'GET'],
        ['name' => 'assets#original',        'url' => '/api/v1/assets/{id}/original',        'verb' => 'GET'],
        ['name' => 'assets#videoStream',     'url' => '/api/v1/assets/{id}/video',           'verb' => 'GET'],
        ['name' => 'assets#mapMarkers',      'url' => '/api/v1/map/markers',                 'verb' => 'GET'],
        ['name' => 'assets#explore',         'url' => '/api/v1/explore',                     'verb' => 'GET'],

        // Albums
        ['name' => 'albums#index',        'url' => '/api/v1/albums',                         'verb' => 'GET'],
        ['name' => 'albums#create',       'url' => '/api/v1/albums/create',                  'verb' => 'POST'],
        ['name' => 'albums#show',         'url' => '/api/v1/albums/{id}/show',               'verb' => 'GET'],
        ['name' => 'albums#delete',       'url' => '/api/v1/albums/{id}/delete',             'verb' => 'POST'],
        ['name' => 'albums#addAssets',    'url' => '/api/v1/albums/{id}/assets/add',         'verb' => 'POST'],
        ['name' => 'albums#removeAssets', 'url' => '/api/v1/albums/{id}/assets/remove',      'verb' => 'POST'],
        ['name' => 'albums#rename',       'url' => '/api/v1/albums/{id}/rename',             'verb' => 'POST'],
        ['name' => 'albums#thumbnail',    'url' => '/api/v1/albums/{id}/thumbnail',          'verb' => 'GET'],

        // People / Personen
        ['name' => 'people#index', 'url' => '/api/v1/people', 'verb' => 'GET'],
        ['name' => 'people#assets', 'url' => '/api/v1/people/{id}/assets', 'verb' => 'GET'],
        ['name' => 'people#thumbnail', 'url' => '/api/v1/people/{id}/thumbnail', 'verb' => 'GET'],

        // Upload NC → Immich
        ['name' => 'upload#upload', 'url' => '/api/v1/upload', 'verb' => 'POST'],
    ],
];
