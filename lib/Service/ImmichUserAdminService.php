<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Db\SyncState;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

class ImmichUserAdminService {
    public function __construct(
        private IClientService $clientService,
        private AdminConfigService $adminConfigService,
        private CapabilityService $capabilityService,
        private SyncStateService $syncStateService,
        private LoggerInterface $logger,
    ) {
    }

    public function validateAdminConnection(?string $baseUrl = null, ?string $apiKey = null): array {
        try {
            $credentials = $this->resolveAdminCredentials($baseUrl, $apiKey);
            $users = $this->request('GET', '/admin/users', [], $credentials['baseUrl'], $credentials['apiKey']);

            return [
                'success' => true,
                'data' => [
                    'probe' => 'GET /api/admin/users',
                    'admin_users_accessible' => true,
                    'user_count' => count($this->normaliseUsers($users)),
                ],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function listUsers(): array {
        $users = $this->request('GET', '/admin/users');
        return $this->normaliseUsers($users);
    }

    public function findUserForNcUid(string $ncUid, string $email, string $storageLabel): ?array {
        $mappedState = $this->syncStateService->findByUid($ncUid);
        $storageLabelState = $this->syncStateService->findByStorageLabel($storageLabel);
        if ($storageLabelState !== null && $storageLabelState->getNcUid() !== $ncUid) {
            throw new \RuntimeException('Immich storage label conflict for "' . $storageLabel . '".');
        }

        $users = $this->listUsers();
        if ($mappedState !== null && $mappedState->getImmichUserId() !== null && $mappedState->getImmichUserId() !== '') {
            return $this->findMappedImmichUser($mappedState, $users);
        }

        $candidates = [];
        $email = strtolower(trim($email));
        foreach ($users as $index => $user) {
            if (!is_array($user)) {
                continue;
            }

            $matchesEmail = $email !== '' && strtolower((string)($user['email'] ?? '')) === $email;
            $matchesStorageLabel = $storageLabel !== '' && (string)($user['storageLabel'] ?? '') === $storageLabel;
            if (!$matchesEmail && !$matchesStorageLabel) {
                continue;
            }

            $key = (string)($user['id'] ?? $user['userId'] ?? 'candidate-' . $index);
            $candidates[$key] = $user;
        }

        if (count($candidates) > 1) {
            throw new \RuntimeException('Duplicate Immich users match Nextcloud user "' . $ncUid . '" by email/storage label.');
        }

        return array_values($candidates)[0] ?? null;
    }

    public function createUser(array $fields): array {
        $payload = $this->normaliseUserPayload($fields, true);
        $payload['password'] = $this->generateInitialPassword();
        $payload['shouldChangePassword'] = $fields['shouldChangePassword'] ?? $this->shouldChangePasswordAfterCreation();

        $created = $this->request('POST', '/admin/users', ['body' => $payload]);
        return $this->withoutPassword($created);
    }

    public function updateUser(string $immichUserId, array $fields): array {
        $updated = $this->request('PUT', '/admin/users/' . rawurlencode($immichUserId), [
            'body' => $this->normaliseUserPayload($fields, false),
        ]);

        return $this->withoutPassword($updated);
    }

    public function disableUser(string $immichUserId): array {
        throw new \RuntimeException('Immich admin API does not expose a non-destructive disable/suspend field for this version.');
    }

    public function deleteUser(string $immichUserId): array {
        if (!$this->adminConfigService->allowsDestructiveUserDelete()) {
            throw new \RuntimeException('Immich user deletion is disabled by admin policy.');
        }

        return $this->request('DELETE', '/admin/users/' . rawurlencode($immichUserId), ['body' => []]);
    }

	public function getUserQuotaUsage(string $immichUserId): ?int {
		$quotaState = $this->getUserQuotaState($immichUserId);
		return $quotaState['quotaUsageInBytes'];
	}

	/**
	 * @return array{found: bool, quotaUsageInBytes: ?int, quotaSizeInBytes: ?int}
	 */
	public function getUserQuotaState(string $immichUserId): array {
		foreach ($this->listUsers() as $user) {
			if (!is_array($user)) {
				continue;
            }
            $id = (string)($user['id'] ?? $user['userId'] ?? '');
            if ($id !== $immichUserId) {
                continue;
            }

			$usage = $user['quotaUsageInBytes'] ?? null;
			$quota = $user['quotaSizeInBytes'] ?? null;
			return [
				'found' => true,
				'quotaUsageInBytes' => is_numeric($usage) ? (int)$usage : null,
				'quotaSizeInBytes' => is_numeric($quota) ? (int)$quota : null,
			];
		}

		return [
			'found' => false,
			'quotaUsageInBytes' => null,
			'quotaSizeInBytes' => null,
		];
	}

    private function findMappedImmichUser(SyncState $mappedState, array $users): ?array {
        $matches = [];
        foreach ($users as $index => $user) {
            if (!is_array($user)) {
                continue;
            }
            $id = (string)($user['id'] ?? $user['userId'] ?? '');
            if ($id === $mappedState->getImmichUserId()) {
                $matches['mapped-' . $index] = $user;
            }
        }

        if (count($matches) > 1) {
            throw new \RuntimeException('Duplicate Immich users match stored mapping for Nextcloud user "' . $mappedState->getNcUid() . '".');
        }

        if (count($matches) === 1) {
            return array_values($matches)[0];
        }

        throw new \RuntimeException('Stored Immich user mapping for Nextcloud user "' . $mappedState->getNcUid() . '" was not found in Immich.');
    }

    private function normaliseUserPayload(array $fields, bool $creating): array {
        $payload = [];
        foreach (['email', 'name', 'shouldChangePassword', 'storageLabel', 'isEnabled'] as $field) {
            if (array_key_exists($field, $fields)) {
                $payload[$field] = $fields[$field];
            }
        }

        if ($creating && (!array_key_exists('email', $payload) || !array_key_exists('name', $payload))) {
            throw new \InvalidArgumentException('Immich user email and name are required.');
        }

        if (array_key_exists('quotaSizeInBytes', $fields)) {
            if ($fields['quotaSizeInBytes'] === 0) {
                throw new \InvalidArgumentException('quotaSizeInBytes=0 is not allowed; use null or omit the field for unlimited quota.');
            }
            $payload['quotaSizeInBytes'] = $fields['quotaSizeInBytes'];
        }

        return $payload;
    }

    private function request(string $method, string $endpoint, array $options = [], ?string $baseUrl = null, ?string $apiKey = null): array {
        $client = $this->clientService->newClient();
        $url = rtrim($baseUrl ?? $this->adminBaseUrl(), '/') . '/api' . $endpoint;
        if (isset($options['query']) && !empty($options['query'])) {
            $url .= '?' . http_build_query($options['query']);
        }

        $requestOptions = [
            'headers' => [
                'x-api-key' => $apiKey ?? $this->adminApiKey(),
                'Accept' => 'application/json',
            ],
            'timeout' => 60,
            'http_errors' => false,
        ];

        if (array_key_exists('body', $options)) {
            $requestOptions['body'] = json_encode($options['body'], JSON_THROW_ON_ERROR);
            $requestOptions['headers']['Content-Type'] = 'application/json';
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $client->get($url, $requestOptions),
                'POST' => $client->post($url, $requestOptions),
                'PUT' => $client->put($url, $requestOptions),
                'PATCH' => $client->patch($url, $requestOptions),
                'DELETE' => $client->delete($url, $requestOptions),
                default => throw new \InvalidArgumentException('Unsupported HTTP method: ' . $method),
            };

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                $remoteMessage = $this->responseErrorMessage((string)$response->getBody());
                $this->logger->error('Immich admin API returned HTTP ' . $statusCode . ' for ' . $endpoint, [
                    'app' => Application::APP_ID,
                    'endpoint' => $endpoint,
                    'method' => $method,
                ]);
                throw new \RuntimeException('Immich admin API error: HTTP ' . $statusCode . ($remoteMessage !== '' ? ': ' . $remoteMessage : ''));
            }

            $decoded = json_decode($response->getBody(), true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Exception $e) {
            $this->logger->error('Immich admin API request failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'endpoint' => $endpoint,
                'method' => $method,
                'url' => $url,
            ]);
            throw $e;
        }
    }

    private function resolveAdminCredentials(?string $baseUrl, ?string $apiKey): array {
        $baseUrl = rtrim(trim((string)($baseUrl ?? '')), '/');
        if ($baseUrl === '') {
            $baseUrl = $this->adminConfigService->getImmichBaseUrl();
        }

        $apiKey = trim((string)($apiKey ?? ''));
        if ($apiKey === '') {
            $apiKey = $this->adminConfigService->getAdminApiKey();
        }

        if ($baseUrl === '' || $apiKey === '') {
            throw new \RuntimeException('Immich admin URL/API key is not configured.');
        }

        $parsedUrl = parse_url($baseUrl);
        if ($parsedUrl === false
            || !in_array(strtolower((string)($parsedUrl['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parsedUrl['host'])) {
            throw new \RuntimeException('Immich admin base URL must be a valid http or https URL.');
        }

        return [
            'baseUrl' => $baseUrl,
            'apiKey' => $apiKey,
        ];
    }

    private function responseErrorMessage(string $body): string {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }

        foreach (['message', 'error', 'detail'] as $key) {
            $value = $decoded[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_array($value)) {
                $messages = array_values(array_filter($value, static fn(mixed $item): bool => is_string($item) && $item !== ''));
                if ($messages !== []) {
                    return implode('; ', $messages);
                }
            }
        }

        return '';
    }

    private function normaliseUsers(array $users): array {
        if (array_key_exists('users', $users) && is_array($users['users'])) {
            return $users['users'];
        }

        return array_is_list($users) ? $users : [];
    }

    private function generateInitialPassword(): string {
        return bin2hex(random_bytes(16));
    }

    private function shouldChangePasswordAfterCreation(): bool {
        return $this->adminConfigService->getInitialPasswordPolicy() !== 'sso_oidc';
    }

    private function withoutPassword(array $user): array {
        unset($user['password']);
        return $user;
    }

    private function adminBaseUrl(): string {
        return rtrim($this->adminConfigService->getImmichBaseUrl(), '/');
    }

    private function adminApiKey(): string {
        return $this->adminConfigService->getAdminApiKey();
    }
}
