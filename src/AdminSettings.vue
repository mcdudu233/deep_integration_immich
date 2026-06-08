<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div id="immich-admin-settings">
		<!-- ═══ Section 1: Immich Connection ═══════════════════════════════ -->
		<NcSettingsSection :name="t('integration_immich', 'Immich Connection')"
			:description="t('integration_immich', 'Configure the Immich server URL and admin credentials')">
			<div class="immich-settings-form">
				<div class="field">
					<NcTextField id="immich-server-url"
						v-model="form.immich_base_url"
						:label="t('integration_immich', 'Immich server URL')"
						placeholder="https://immich.example.com"
						data-testid="immich-admin-url" />
				</div>

				<div class="field">
					<NcPasswordField id="immich-admin-api-key"
						v-model="form.admin_api_key"
						:label="t('integration_immich', 'Admin API key')"
						:placeholder="apiKeyConfigured ? t('integration_immich', 'API key is set') : t('integration_immich', 'Enter Immich admin API key')"
						data-testid="immich-admin-api-key" />
					<p v-if="apiKeyConfigured" class="hint">
						{{ t('integration_immich', 'An API key is already configured. Leave blank to keep the current key.') }}
					</p>
				</div>

				<div class="actions">
					<NcButton type="secondary"
						:disabled="testingConnection || !form.immich_base_url"
						data-testid="test-admin-connection"
						@click="testConnection">
						<template #icon>
							<NcLoadingIcon v-if="testingConnection" :size="20" />
						</template>
						{{ t('integration_immich', 'Test connection') }}
					</NcButton>

					<NcButton type="primary"
						:disabled="saving || !form.immich_base_url"
						data-testid="save-admin-settings"
						@click="saveSettings">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
						</template>
						{{ t('integration_immich', 'Save') }}
					</NcButton>
				</div>

				<NcNoteCard v-if="connectionMessage"
					:type="connectionMessageType"
					data-testid="admin-connection-message">
					{{ connectionMessage }}
				</NcNoteCard>

				<NcNoteCard v-if="missingPermissions.length > 0"
					type="warning"
					data-testid="admin-missing-permissions-warning">
					{{ t('integration_immich', 'Connection successful, but the API key is missing required permissions. The following features may not work:') }}
					<ul style="margin: 8px 0 0 16px;">
						<li v-for="perm in missingPermissions" :key="perm">
							<code>{{ perm }}</code>
						</li>
					</ul>
					{{ t('integration_immich', 'Please edit the API key in Immich (Account Settings → API Keys) and enable all required permissions, or create a new key with full permissions.') }}
				</NcNoteCard>

				<NcNoteCard v-if="localAccessBlocked"
					type="error"
					data-testid="admin-local-access-warning">
					{{ t('integration_immich', 'Nextcloud is blocking the connection because the Immich server address is a private/local IP. A Nextcloud administrator can allow this by running:') }}
					<br><br>
					<code>php occ config:system:set allow_local_remote_servers --value=true --type=boolean</code>
					<br><br>
					{{ t('integration_immich', 'Alternatively, use a public hostname for your Immich server instead of a local IP address.') }}
				</NcNoteCard>
			</div>
		</NcSettingsSection>

		<!-- ═══ Section 2: Provisioning ═══════════════════════════════════ -->
		<NcSettingsSection :name="t('integration_immich', 'User Provisioning')"
			:description="t('integration_immich', 'Control how Nextcloud users are mirrored into Immich')">
			<div class="immich-settings-form">
				<div class="field">
					<NcCheckboxRadioSwitch :checked="form.provisioning_enabled"
						data-testid="provisioning-enabled"
						@update:checked="form.provisioning_enabled = $event">
						{{ t('integration_immich', 'Enable user provisioning') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div class="field">
					<label class="field-label">{{ t('integration_immich', 'User scope') }}</label>
					<div class="radio-group" data-testid="provisioning-scope-filter">
						<NcCheckboxRadioSwitch :checked="form.user_scope_mode"
							name="user_scope_mode"
							value="all"
							data-testid="provisioning-scope"
							type="radio"
							@update:checked="form.user_scope_mode = 'all'">
							{{ t('integration_immich', 'All Nextcloud users') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch :checked="form.user_scope_mode"
							name="user_scope_mode"
							value="groups"
							type="radio"
							data-testid="provisioning-scope-groups"
							@update:checked="form.user_scope_mode = 'groups'">
							{{ t('integration_immich', 'Only users in selected groups') }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>

				<div v-if="form.user_scope_mode === 'groups'" class="field">
					<label class="field-label">{{ t('integration_immich', 'Selected groups') }}</label>
					<NcSelect v-model="selectedGroups"
						:options="availableGroups"
						:multiple="true"
						:taggable="true"
						:close-on-select="false"
						:placeholder="t('integration_immich', 'Select or type group IDs')"
						data-testid="provisioning-groups"
						@option:created="onGroupCreated" />
					<p class="hint">
						{{ t('integration_immich', 'Only users belonging to at least one of these groups will be provisioned.') }}
					</p>
				</div>
			</div>
		</NcSettingsSection>

		<!-- ═══ Section 3: Templates ══════════════════════════════════════ -->
		<NcSettingsSection :name="t('integration_immich', 'Templates')"
			:description="t('integration_immich', 'Configure naming and path templates for Immich users and mounts')">
			<div class="immich-settings-form">
				<NcNoteCard type="info">
					{{ t('integration_immich', 'Available placeholders: {uid} (Nextcloud user ID), {storageLabel} (sanitized user ID). Changing the storage label template after assets exist may require a migration.') }}
				</NcNoteCard>

				<div class="field">
					<NcTextField v-model="form.storage_label_template"
						:label="t('integration_immich', 'Storage label template')"
						placeholder="{uid}"
						data-testid="storage-label-template" />
				</div>

				<div class="field">
					<NcTextField v-model="form.email_template"
						:label="t('integration_immich', 'Email fallback template')"
						placeholder="{uid}@immich.local"
						data-testid="email-template" />
					<p class="hint">
						{{ t('integration_immich', 'Used when a Nextcloud user has no email address configured.') }}
					</p>
				</div>

				<div class="field" data-testid="password-policy-selector">
					<label class="field-label">{{ t('integration_immich', 'Initial password policy') }}</label>
					<div class="radio-group">
						<NcCheckboxRadioSwitch :checked="form.initial_password_policy"
							name="initial_password_policy"
							value="random"
							type="radio"
							data-testid="password-policy-random"
							@update:checked="form.initial_password_policy = 'random'">
							{{ t('integration_immich', 'Random generated password') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch :checked="form.initial_password_policy"
							name="initial_password_policy"
							value="sso_oidc"
							type="radio"
							data-testid="password-policy-sso-oidc"
							@update:checked="form.initial_password_policy = 'sso_oidc'">
							{{ t('integration_immich', 'SSO/OIDC mode') }}
						</NcCheckboxRadioSwitch>
					</div>
					<p class="hint">
						{{ t('integration_immich', 'Random mode creates an initial Immich password that is not shown after user creation. Use SSO/OIDC mode only when Immich and Nextcloud share authentication.') }}
					</p>
				</div>

				<div class="field">
					<NcTextField v-model="form.mount_name_template"
						:label="t('integration_immich', 'Mount name template')"
						placeholder="Immich Photos"
						data-testid="mount-name-template" />
					<p class="hint">
						{{ t('integration_immich', 'The name shown in Nextcloud Files for the external storage mount.') }}
					</p>
				</div>

				<div class="field">
					<NcTextField v-model="form.host_path_template"
						:label="t('integration_immich', 'Host path template')"
						placeholder="/srv/immich/originals/library/{storageLabel}"
						data-testid="host-path-template" />
					<p class="hint">
						{{ t('integration_immich', 'The filesystem path on the host where Immich stores each user\'s library folder.') }}
					</p>
				</div>

				<div class="field">
					<NcTextField v-model="form.nc_visible_path_template"
						:label="t('integration_immich', 'Nextcloud-visible path template')"
						placeholder="/mnt/immich-library/{storageLabel}"
						data-testid="nc-visible-path-template" />
					<p class="hint">
						{{ t('integration_immich', 'The path visible inside the Nextcloud container that maps to the host path. Must be outside Nextcloud\'s data directory.') }}
					</p>
				</div>
			</div>
		</NcSettingsSection>

		<!-- ═══ Section 4: Storage ═════════════════════════════════════════ -->
		<NcSettingsSection :name="t('integration_immich', 'Storage Settings')"
			:description="t('integration_immich', 'Control directory creation and external storage mount provisioning')">
			<div class="immich-settings-form">
				<div class="field">
					<NcCheckboxRadioSwitch :checked="form.mkdir_policy_enabled"
						data-testid="mkdir-policy"
						@update:checked="form.mkdir_policy_enabled = $event">
						{{ t('integration_immich', 'Allow creating empty per-user directories') }}
					</NcCheckboxRadioSwitch>
					<p class="hint">
						{{ t('integration_immich', 'If enabled, the app may create empty directories under the configured base path when a user\'s Immich library folder does not exist yet. Only directories are created, never media files.') }}
					</p>
				</div>

				<div class="field">
					<NcCheckboxRadioSwitch :checked="form.external_storage_auto_create"
						data-testid="auto-create-external-storage"
						@update:checked="form.external_storage_auto_create = $event">
						{{ t('integration_immich', 'Auto-create external storage mounts') }}
					</NcCheckboxRadioSwitch>
					<p class="hint">
						{{ t('integration_immich', 'If enabled, the app will attempt to create per-user read-only Local External Storage mounts automatically. If disabled, the app can only verify existing mounts and admins must configure them manually.') }}
					</p>
				</div>

				<NcNoteCard v-if="form.external_storage_auto_create"
					type="warning"
					data-testid="admin-general-warning-external-storage-auto-create">
					{{ t('integration_immich', 'Auto-creating external storage mounts depends on Nextcloud\'s files_external API stability. If the API is unavailable or insufficient, the app will report the required manual steps instead of guessing at private Nextcloud internals.') }}
				</NcNoteCard>
			</div>
		</NcSettingsSection>

		<!-- ═══ Section 5: Quota Sync ══════════════════════════════════════ -->
		<NcSettingsSection :name="t('integration_immich', 'Quota Synchronization')"
			:description="t('integration_immich', 'Control how Immich user quotas are derived from Nextcloud quotas')">
			<div class="immich-settings-form">
				<div class="field">
					<label class="field-label">{{ t('integration_immich', 'Quota sync mode') }}</label>
					<div class="radio-group" data-testid="quota-mode-selector">
						<NcCheckboxRadioSwitch :checked="form.quota_sync_mode"
							name="quota_sync_mode"
							value="disabled"
							type="radio"
							data-testid="quota-sync-mode"
							@update:checked="form.quota_sync_mode = 'disabled'">
							{{ t('integration_immich', 'Disabled') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch :checked="form.quota_sync_mode"
							name="quota_sync_mode"
							value="manual"
							type="radio"
							data-testid="quota-sync-mode-manual"
							@update:checked="form.quota_sync_mode = 'manual'">
							{{ t('integration_immich', 'Manual only') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch :checked="form.quota_sync_mode"
							name="quota_sync_mode"
							value="event_scheduled"
							type="radio"
							data-testid="quota-sync-mode-event-scheduled"
							@update:checked="form.quota_sync_mode = 'event_scheduled'">
							{{ t('integration_immich', 'Event + scheduled job') }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>

				<div v-if="form.quota_sync_mode !== 'disabled'" class="field">
					<NcTextField v-model="quotaReserveDisplay"
						:label="t('integration_immich', 'Safety reserve (MiB)')"
						placeholder="256"
						data-testid="quota-reserve-bytes"
						type="number"
						:min="0" />
					<p class="hint">
						{{ t('integration_immich', 'Bytes reserved before setting the Immich quota. Prevents the combined usage from reaching the exact Nextcloud limit.') }}
					</p>
				</div>

				<NcNoteCard v-if="form.quota_sync_mode !== 'disabled'"
					type="warning"
					data-testid="quota-warning-section">
					{{ t('integration_immich', 'Quota sync is a coordination mechanism, not mathematically perfect real-time accounting. Nextcloud external-storage quota inclusion is experimental and may lag until filecache scans run. Immich thumbnails, encoded videos, and ML data are app overhead not charged through this quota formula.') }}
				</NcNoteCard>

				<NcNoteCard v-if="form.quota_sync_mode !== 'disabled'"
					type="info"
					data-testid="quota-config-info-section">
					{{ t('integration_immich', 'For quota sync to work correctly, enable external storage quota inclusion in config.php:') }}
					<br>
					<code>'quota_include_external_storage' => true</code>
				</NcNoteCard>
			</div>
		</NcSettingsSection>

		<!-- ═══ Section 6: Delete/Disable Policy ═══════════════════════════ -->
		<NcSettingsSection :name="t('integration_immich', 'Delete / Disable Policy')"
			:description="t('integration_immich', 'What happens to the Immich user when a Nextcloud user is deleted or disabled')">
			<div class="immich-settings-form">
				<div class="field">
					<label class="field-label">{{ t('integration_immich', 'Policy when a Nextcloud user is deleted or disabled') }}</label>
					<div class="radio-group" data-testid="delete-disable-policy-selector">
						<NcCheckboxRadioSwitch :checked="form.delete_disable_policy"
							name="delete_disable_policy"
							value="disable_suspend"
							type="radio"
							data-testid="delete-disable-policy"
							@update:checked="onDeletePolicyChange('disable_suspend')">
							{{ t('integration_immich', 'Disable / suspend Immich user (recommended)') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch :checked="form.delete_disable_policy"
							name="delete_disable_policy"
							value="delete_opt_in"
							type="radio"
							data-testid="delete-disable-policy-delete"
							@update:checked="onDeletePolicyChange('delete_opt_in')">
							{{ t('integration_immich', 'Delete Immich user and assets (destructive)') }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>

				<NcNoteCard v-if="form.delete_disable_policy === 'disable_suspend'"
					type="info"
					data-testid="delete-disable-policy-info">
					{{ t('integration_immich', 'When a Nextcloud user is deleted or disabled, the corresponding Immich user will be suspended if supported. Assets are never deleted automatically.') }}
				</NcNoteCard>

				<div v-if="form.delete_disable_policy === 'delete_opt_in'" class="field">
					<NcCheckboxRadioSwitch :checked="deleteOptInConfirmed"
						data-testid="delete-opt-in-confirm"
						@update:checked="deleteOptInConfirmed = $event">
						{{ t('integration_immich', 'I understand this will permanently delete Immich users and their assets when the corresponding Nextcloud user is deleted') }}
					</NcCheckboxRadioSwitch>
				</div>

				<NcNoteCard v-if="form.delete_disable_policy === 'delete_opt_in' && !deleteOptInConfirmed"
					type="error"
					data-testid="delete-disable-policy-warning">
					{{ t('integration_immich', 'You must confirm the destructive policy before it can be saved. This action cannot be undone.') }}
				</NcNoteCard>
			</div>
		</NcSettingsSection>

		<!-- ═══ Section 7: Actions ═════════════════════════════════════════ -->
		<NcSettingsSection :name="t('integration_immich', 'Provisioning Actions')"
			:description="t('integration_immich', 'Dry-run, reconcile, and recompute quota for Immich users')">
			<div class="immich-settings-form">
				<div class="field">
					<label class="field-label">{{ t('integration_immich', 'Single user actions') }}</label>
					<div class="actions-row">
						<NcTextField v-model="actionNcUid"
							:label="t('integration_immich', 'Nextcloud user ID')"
							placeholder="alice"
							data-testid="action-nc-uid"
							class="uid-input" />
						<NcButton type="secondary"
							:disabled="!actionNcUid || store.dryRun.loading"
							data-testid="dry-run-one-user"
							@click="dryRunOneUser">
							<template #icon>
								<NcLoadingIcon v-if="store.dryRun.loading" :size="20" />
							</template>
							{{ t('integration_immich', 'Dry run') }}
						</NcButton>
						<NcButton type="secondary"
							:disabled="!actionNcUid || store.reconcile.loading"
							data-testid="reconcile-one-user"
							@click="reconcileOneUser">
							<template #icon>
								<NcLoadingIcon v-if="store.reconcile.loading" :size="20" />
							</template>
							{{ t('integration_immich', 'Reconcile') }}
						</NcButton>
						<NcButton type="secondary"
							:disabled="!actionNcUid || store.quotaRecompute.loading"
							data-testid="recompute-quota-one"
							@click="recomputeQuotaOneUser">
							<template #icon>
								<NcLoadingIcon v-if="store.quotaRecompute.loading" :size="20" />
							</template>
							{{ t('integration_immich', 'Recompute quota') }}
						</NcButton>
						<NcButton type="secondary"
							:disabled="!actionNcUid || store.mountVerify.loading"
							data-testid="verify-mount-one"
							@click="verifyOneMount">
							<template #icon>
								<NcLoadingIcon v-if="store.mountVerify.loading" :size="20" />
							</template>
							{{ t('integration_immich', 'Verify mount') }}
						</NcButton>
					</div>
				</div>

				<div class="field">
					<label class="field-label">{{ t('integration_immich', 'Bulk actions') }}</label>
					<div class="actions-row">
						<NcButton type="secondary"
							:disabled="store.dryRun.loading"
							data-testid="dry-run-all-users"
							@click="dryRunAllUsers">
							<template #icon>
								<NcLoadingIcon v-if="store.dryRun.loading" :size="20" />
							</template>
							{{ t('integration_immich', 'Dry run all') }}
						</NcButton>
						<NcButton type="secondary"
							:disabled="store.reconcile.loading"
							data-testid="reconcile-all-users"
							@click="reconcileAllUsers">
							<template #icon>
								<NcLoadingIcon v-if="store.reconcile.loading" :size="20" />
							</template>
							{{ t('integration_immich', 'Reconcile all') }}
						</NcButton>
						<NcButton type="secondary"
							:disabled="store.quotaRecompute.loading"
							data-testid="recompute-quota-all"
							@click="recomputeQuotaAllUsers">
							<template #icon>
								<NcLoadingIcon v-if="store.quotaRecompute.loading" :size="20" />
							</template>
							{{ t('integration_immich', 'Recompute all quotas') }}
						</NcButton>
					</div>
				</div>

				<!-- Action results -->
				<NcNoteCard v-if="actionMessage"
					:type="actionMessageType"
					data-testid="admin-action-message">
					{{ actionMessage }}
				</NcNoteCard>

				<div v-if="dryRunResults.length > 0" class="results-block" data-testid="dry-run-results">
					<h4>{{ t('integration_immich', 'Planned changes') }}</h4>
					<ul>
						<li v-for="(item, idx) in dryRunResults" :key="idx">
							<strong>{{ item.ncUid || item.nc_uid || item.uid }}</strong>:
							{{ item.action || item.plan || JSON.stringify(item) }}
						</li>
					</ul>
				</div>

				<div v-if="reconcileStatus && Object.keys(reconcileStatus).length > 0" class="results-block" data-testid="reconcile-results">
					<h4>{{ t('integration_immich', 'Reconcile result') }}</h4>
					<pre class="result-json">{{ JSON.stringify(reconcileStatus, null, 2) }}</pre>
				</div>

				<div v-if="mountHealth && Object.keys(mountHealth).length > 0" class="results-block" data-testid="mount-verify-results">
					<h4>{{ t('integration_immich', 'Mount health') }}</h4>
					<pre class="result-json">{{ JSON.stringify(mountHealth, null, 2) }}</pre>
				</div>
			</div>
		</NcSettingsSection>

		<!-- ═══ Section 8: Status ══════════════════════════════════════════ -->
		<NcSettingsSection :name="t('integration_immich', 'Provisioning Status')"
			:description="t('integration_immich', 'Current sync state, mount health, and quota status for provisioned users')">
			<div class="immich-settings-form">
				<!-- Warnings -->
				<div v-if="warnings.length > 0" data-testid="admin-general-warning-section">
					<NcNoteCard v-for="(warning, idx) in warnings"
						:key="'warn-' + idx"
						type="warning"
						:data-testid="'admin-general-warning-' + idx">
						{{ warning }}
					</NcNoteCard>
				</div>

				<!-- Sync state table -->
				<div class="field">
					<div class="section-header">
						<h4>{{ t('integration_immich', 'User sync state') }}</h4>
						<NcButton type="tertiary"
							:disabled="store.syncStates.loading"
							data-testid="refresh-sync-states"
							@click="refreshSyncStates">
							<template #icon>
								<NcLoadingIcon v-if="store.syncStates.loading" :size="20" />
							</template>
							{{ t('integration_immich', 'Refresh') }}
						</NcButton>
					</div>

					<div v-if="store.syncStates.loading && syncStates.length === 0" class="loading-placeholder">
						<NcLoadingIcon :size="24" />
						<span>{{ t('integration_immich', 'Loading sync states…') }}</span>
					</div>

					<div v-else-if="syncStates.length === 0" class="empty-placeholder">
						{{ t('integration_immich', 'No provisioned users yet. Enable provisioning and reconcile users to see sync state here.') }}
					</div>

					<div v-else class="table-wrapper" data-testid="sync-state-table">
						<table class="sync-table">
							<thead>
								<tr>
									<th>{{ t('integration_immich', 'NC User') }}</th>
									<th>{{ t('integration_immich', 'Immich User ID') }}</th>
									<th>{{ t('integration_immich', 'Storage Label') }}</th>
									<th>{{ t('integration_immich', 'Status') }}</th>
									<th>{{ t('integration_immich', 'Last Sync') }}</th>
									<th>{{ t('integration_immich', 'Last Error') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in syncStates"
									:key="row.ncUid"
									:data-testid="'sync-state-row-' + (row.ncUid || 'unknown')">
									<td>{{ row.ncUid }}</td>
									<td class="mono">{{ row.immichUserId || '—' }}</td>
									<td>{{ row.storageLabel || '—' }}</td>
									<td>
										<span :class="['status-badge', statusClass(row.lastSyncStatus || row.scopeStatus)]">
											{{ statusLabel(row.lastSyncStatus || row.scopeStatus || 'pending') }}
										</span>
									</td>
									<td>{{ formatTimestamp(row.updatedAt) }}</td>
									<td class="error-cell">{{ row.lastError || '—' }}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

				<!-- Mount health cards -->
				<div class="field" data-testid="mount-health-section">
					<h4>{{ t('integration_immich', 'Mount health') }}</h4>
					<div v-if="mountHealthCards.length === 0"
						class="empty-placeholder"
						data-testid="mount-health-empty">
						{{ t('integration_immich', 'No mount health data available. Verify a user\'s mount to see health details.') }}
					</div>
					<div v-else class="health-cards" data-testid="mount-health-card">
						<div v-for="card in mountHealthCards"
							:key="card.ncUid"
							class="health-card"
							:data-testid="'mount-health-row-' + (card.ncUid || 'unknown')">
							<div class="health-card-header">
								<strong>{{ card.ncUid }}</strong>
								<span :class="['status-badge', statusClass(card.status)]">{{ statusLabel(card.status) }}</span>
							</div>
							<div class="health-card-body">
								<div v-if="card.mountId !== null" class="health-row">
									<span class="health-label">{{ t('integration_immich', 'Mount ID') }}</span>
									<span class="mono">{{ card.mountId }}</span>
								</div>
								<div v-if="card.path" class="health-row">
									<span class="health-label">{{ t('integration_immich', 'Mount path') }}</span>
									<span>{{ card.path }}</span>
								</div>
								<div class="health-row">
									<span class="health-label">{{ t('integration_immich', 'Read-only') }}</span>
									<span>{{ card.readOnly === true ? t('integration_immich', 'Yes') : card.readOnly === false ? t('integration_immich', 'No') : '—' }}</span>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Quota status cards -->
				<div class="field" data-testid="quota-status-section">
					<h4>{{ t('integration_immich', 'Quota status') }}</h4>
					<div v-if="quotaStatusCards.length === 0"
						class="empty-placeholder"
						data-testid="quota-status-empty">
						{{ t('integration_immich', 'No quota status data available. Enable quota sync and recompute quotas to see details.') }}
					</div>
					<div v-else class="health-cards" data-testid="quota-status-card">
						<div v-for="card in quotaStatusCards"
							:key="card.ncUid"
							class="health-card"
							:data-testid="'quota-status-row-' + (card.ncUid || 'unknown')">
							<div class="health-card-header">
								<strong>{{ card.ncUid }}</strong>
								<span :class="['status-badge', statusClass(card.status)]">{{ statusLabel(card.status) }}</span>
							</div>
							<div class="health-card-body">
								<div v-if="card.ncQuota !== null" class="health-row">
									<span class="health-label">{{ t('integration_immich', 'NC quota') }}</span>
									<span class="mono">{{ formatBytes(card.ncQuota) }}</span>
								</div>
								<div v-if="card.ncUsed !== null" class="health-row">
									<span class="health-label">{{ t('integration_immich', 'NC used') }}</span>
									<span class="mono">{{ formatBytes(card.ncUsed) }}</span>
								</div>
								<div v-if="card.immichUsage !== null" class="health-row">
									<span class="health-label">{{ t('integration_immich', 'Immich usage') }}</span>
									<span class="mono">{{ formatBytes(card.immichUsage) }}</span>
								</div>
								<div v-if="card.computedImmichQuota !== null" class="health-row">
									<span class="health-label">{{ t('integration_immich', 'Computed Immich quota') }}</span>
									<span class="mono">{{ formatBytes(card.computedImmichQuota) }}</span>
								</div>
								<div v-if="card.lastSyncAt" class="health-row">
									<span class="health-label">{{ t('integration_immich', 'Last sync') }}</span>
									<span>{{ formatTimestamp(card.lastSyncAt) }}</span>
								</div>
								<div v-if="card.warning"
									class="health-row warning-row"
									data-testid="quota-warning-row">
									{{ card.warning }}
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import {
	NcSettingsSection,
	NcTextField,
	NcPasswordField,
	NcButton,
	NcNoteCard,
	NcLoadingIcon,
	NcCheckboxRadioSwitch,
	NcSelect,
} from '@nextcloud/vue'
import { useAdminProvisioningStore } from './store/adminProvisioning.js'

const store = useAdminProvisioningStore()

// ── Form state ────────────────────────────────────────────────────────
const form = reactive({
	immich_base_url: '',
	admin_api_key: '',
	provisioning_enabled: false,
	user_scope_mode: 'all',
	user_scope_groups: [],
	storage_label_template: '{uid}',
	email_template: '{uid}@immich.local',
	initial_password_policy: 'random',
	mount_name_template: 'Immich Photos',
	host_path_template: '',
	nc_visible_path_template: '',
	mkdir_policy_enabled: false,
	external_storage_auto_create: false,
	quota_sync_mode: 'disabled',
	quota_reserve_bytes: 268435456,
	delete_disable_policy: 'disable_suspend',
})

const apiKeyConfigured = ref(false)
const deleteOptInConfirmed = ref(false)
const selectedGroups = ref([])
const availableGroups = ref([])

// ── Connection state ──────────────────────────────────────────────────
const saving = ref(false)
const testingConnection = ref(false)
const connectionMessage = ref('')
const connectionMessageType = ref('success')
const localAccessBlocked = ref(false)
const missingPermissions = ref([])

// ── Action state ──────────────────────────────────────────────────────
const actionNcUid = ref('')
const actionMessage = ref('')
const actionMessageType = ref('success')
const dryRunResults = ref([])
const reconcileStatus = ref(null)
const mountHealth = ref(null)

// ── Status state ──────────────────────────────────────────────────────
const warnings = ref([])
const syncStates = ref([])
const mountHealthCards = ref([])
const quotaStatusCards = ref([])

// ── Computed ──────────────────────────────────────────────────────────
const quotaReserveDisplay = computed({
	get() {
		return Math.round(form.quota_reserve_bytes / (1024 * 1024))
	},
	set(val) {
		const parsed = parseInt(val, 10)
		if (!isNaN(parsed) && parsed >= 0) {
			form.quota_reserve_bytes = parsed * 1024 * 1024
		}
	},
})

// ── Lifecycle ─────────────────────────────────────────────────────────
onMounted(() => {
	try {
		const state = loadState('integration_immich', 'admin-config')
		applyLoadedState(state)
	} catch (e) {
		store.fetchAdminSettings().then(() => {
			const cfg = store.adminSettings.data
			applyConfigToForm(cfg)
			apiKeyConfigured.value = cfg.admin_api_key_configured ?? false
		}).catch(() => {
			connectionMessage.value = t('integration_immich', 'Error loading configuration')
			connectionMessageType.value = 'error'
		})
	}
})

function applyLoadedState(state) {
	if (state.settings) {
		applyConfigToForm(state.settings)
	}
	apiKeyConfigured.value = state.api_key_set ?? (state.settings?.admin_api_key_configured ?? false)

	if (state.capabilities) {
		store.loadCapabilities(state.capabilities)
	}
	if (Array.isArray(state.syncStates)) {
		syncStates.value = state.syncStates
	}
	if (Array.isArray(state.warnings)) {
		warnings.value = state.warnings
	}
	if (state.status) {
		applyAdminStatus(state.status)
	}
}

function applyConfigToForm(cfg) {
	if (cfg.immich_base_url !== undefined) form.immich_base_url = cfg.immich_base_url
	if (cfg.provisioning_enabled !== undefined) form.provisioning_enabled = cfg.provisioning_enabled
	if (cfg.user_scope_mode !== undefined) form.user_scope_mode = cfg.user_scope_mode
	if (cfg.user_scope_groups !== undefined) {
		form.user_scope_groups = Array.isArray(cfg.user_scope_groups) ? cfg.user_scope_groups : []
		selectedGroups.value = form.user_scope_groups.map(g => ({ id: g, label: g }))
		availableGroups.value = selectedGroups.value.slice()
	}
	if (cfg.storage_label_template !== undefined) form.storage_label_template = cfg.storage_label_template
	if (cfg.email_template !== undefined) form.email_template = cfg.email_template
	if (cfg.initial_password_policy !== undefined) form.initial_password_policy = cfg.initial_password_policy
	if (cfg.mount_name_template !== undefined) form.mount_name_template = cfg.mount_name_template
	if (cfg.host_path_template !== undefined) form.host_path_template = cfg.host_path_template
	if (cfg.nc_visible_path_template !== undefined) form.nc_visible_path_template = cfg.nc_visible_path_template
	if (cfg.mkdir_policy_enabled !== undefined) form.mkdir_policy_enabled = cfg.mkdir_policy_enabled
	if (cfg.external_storage_auto_create !== undefined) form.external_storage_auto_create = cfg.external_storage_auto_create
	if (cfg.quota_sync_mode !== undefined) form.quota_sync_mode = cfg.quota_sync_mode
	if (cfg.quota_reserve_bytes !== undefined) form.quota_reserve_bytes = cfg.quota_reserve_bytes
	if (cfg.delete_disable_policy !== undefined) {
		form.delete_disable_policy = cfg.delete_disable_policy
		if (cfg.delete_disable_policy === 'delete_opt_in') {
			deleteOptInConfirmed.value = true
		}
	}
}

function applyAdminStatus(status) {
	if (status.credentials) {
		apiKeyConfigured.value = status.credentials.admin_api_key_configured ?? apiKeyConfigured.value
	}
}

// ── Save ──────────────────────────────────────────────────────────────
async function saveSettings() {
	saving.value = true
	connectionMessage.value = ''
	try {
		const config = { ...form }
		// Map selected groups back to plain array
		config.user_scope_groups = selectedGroups.value.map(g => g.id || g.label || g)
		// Only send API key if user entered a new one
		if (!config.admin_api_key) {
			delete config.admin_api_key
		}
		// Destructive policy confirmation
		if (form.delete_disable_policy === 'delete_opt_in') {
			config.delete_opt_in_confirmed = deleteOptInConfirmed.value
		}
		await store.saveAdminSettings(config)
		form.admin_api_key = ''
		apiKeyConfigured.value = true
		connectionMessage.value = t('integration_immich', 'Settings saved')
		connectionMessageType.value = 'success'
	} catch (e) {
		connectionMessage.value = store.adminSettings.error || t('integration_immich', 'Error saving settings')
		connectionMessageType.value = 'error'
	} finally {
		saving.value = false
	}
}

// ── Test connection ───────────────────────────────────────────────────
async function testConnection() {
	testingConnection.value = true
	connectionMessage.value = ''
	localAccessBlocked.value = false
	missingPermissions.value = []
	try {
		const response = await store.testConnection(form.immich_base_url, form.admin_api_key)
		missingPermissions.value = response?.validation?.missing_permissions ?? []
		if (missingPermissions.value.length === 0) {
			connectionMessage.value = t('integration_immich', 'Connection successful!')
			connectionMessageType.value = 'success'
		} else {
			connectionMessage.value = t('integration_immich', 'Connected, but some permissions are missing (see below).')
			connectionMessageType.value = 'warning'
		}
	} catch (e) {
		localAccessBlocked.value = e.localAccessBlocked === true
		if (localAccessBlocked.value) {
			connectionMessage.value = t('integration_immich', 'Connection failed: local IP blocked by Nextcloud')
		} else {
			connectionMessage.value = e.message || store.adminSettings.error || t('integration_immich', 'Connection failed')
		}
		connectionMessageType.value = 'error'
	} finally {
		testingConnection.value = false
	}
}

// ── Delete policy ─────────────────────────────────────────────────────
function onDeletePolicyChange(value) {
	form.delete_disable_policy = value
	if (value === 'disable_suspend') {
		deleteOptInConfirmed.value = false
	}
}

// ── Group selector ────────────────────────────────────────────────────
function onGroupCreated(newGroup) {
	availableGroups.value.push(newGroup)
}

// ── Actions ───────────────────────────────────────────────────────────
async function dryRunOneUser() {
	actionMessage.value = ''
	dryRunResults.value = []
	reconcileStatus.value = null
	mountHealth.value = null
	try {
		const result = await store.dryRunUser(actionNcUid.value)
		dryRunResults.value = Array.isArray(result?.plan) ? result.plan : [result?.plan ?? result]
		actionMessage.value = t('integration_immich', 'Dry run completed')
		actionMessageType.value = 'success'
	} catch (e) {
		actionMessage.value = store.dryRun.error || t('integration_immich', 'Dry run failed')
		actionMessageType.value = 'error'
	}
}

async function dryRunAllUsers() {
	actionMessage.value = ''
	dryRunResults.value = []
	try {
		const result = await store.dryRunAllUsers()
		const users = result?.plan?.users
		dryRunResults.value = users && typeof users === 'object' ? Object.values(users) : store.dryRun.results
		actionMessage.value = t('integration_immich', 'Dry run completed for all scoped users')
		actionMessageType.value = 'success'
	} catch (e) {
		actionMessage.value = store.dryRun.error || t('integration_immich', 'Dry run failed')
		actionMessageType.value = 'error'
	}
}

async function reconcileOneUser() {
	actionMessage.value = ''
	reconcileStatus.value = null
	try {
		const result = await store.reconcileUser(actionNcUid.value)
		reconcileStatus.value = result
		actionMessage.value = t('integration_immich', 'Reconcile completed')
		actionMessageType.value = 'success'
	} catch (e) {
		actionMessage.value = store.reconcile.error || t('integration_immich', 'Reconcile failed')
		actionMessageType.value = 'error'
	}
}

async function reconcileAllUsers() {
	actionMessage.value = ''
	reconcileStatus.value = null
	try {
		const result = await store.reconcileAllUsers()
		reconcileStatus.value = result
		actionMessage.value = t('integration_immich', 'Reconcile all completed')
		actionMessageType.value = 'success'
	} catch (e) {
		actionMessage.value = store.reconcile.error || t('integration_immich', 'Reconcile all failed')
		actionMessageType.value = 'error'
	}
}

async function recomputeQuotaOneUser() {
	actionMessage.value = ''
	try {
		await store.recomputeQuotaForUser(actionNcUid.value)
		actionMessage.value = t('integration_immich', 'Quota recomputed')
		actionMessageType.value = 'success'
	} catch (e) {
		actionMessage.value = store.quotaRecompute.error || t('integration_immich', 'Quota recompute failed')
		actionMessageType.value = 'error'
	}
}

async function recomputeQuotaAllUsers() {
	actionMessage.value = ''
	try {
		await store.recomputeQuotaForAll()
		actionMessage.value = t('integration_immich', 'All quotas recomputed')
		actionMessageType.value = 'success'
	} catch (e) {
		actionMessage.value = store.quotaRecompute.error || t('integration_immich', 'Quota recompute failed')
		actionMessageType.value = 'error'
	}
}

async function verifyOneMount() {
	actionMessage.value = ''
	mountHealth.value = null
	try {
		const result = await store.verifyUserMount(actionNcUid.value)
		mountHealth.value = result?.health ?? result
		// Also add to mount health cards
		const existing = mountHealthCards.value.findIndex(c => c.ncUid === actionNcUid.value)
		const card = {
			ncUid: actionNcUid.value,
			status: mountHealth.value?.status ?? 'unknown',
			mountId: mountHealth.value?.mount_id ?? null,
			path: mountHealth.value?.mount_name ?? null,
			readOnly: mountHealth.value?.read_only ?? null,
		}
		if (existing >= 0) {
			mountHealthCards.value[existing] = card
		} else {
			mountHealthCards.value.push(card)
		}
		actionMessage.value = t('integration_immich', 'Mount verification completed')
		actionMessageType.value = 'success'
	} catch (e) {
		actionMessage.value = store.mountVerify.error || t('integration_immich', 'Mount verification failed')
		actionMessageType.value = 'error'
	}
}

async function refreshSyncStates() {
	try {
		const result = await store.fetchSyncStates()
		syncStates.value = result?.sync_state ?? store.syncStates.list
	} catch (e) {
		// Error is already in store.syncStates.error
	}
}

// ── Helpers ───────────────────────────────────────────────────────────
function statusClass(status) {
	if (!status) return 'status-unknown'
	const s = String(status).toLowerCase()
	if (s === 'ok' || s === 'active' || s === 'synced') return 'status-ok'
	if (s === 'pending' || s === 'provisioning') return 'status-pending'
	if (s === 'failed' || s === 'error' || s === 'deleted') return 'status-error'
	if (s === 'warning' || s === 'stale') return 'status-warning'
	return 'status-unknown'
}

function statusLabel(status) {
	const s = String(status || 'unknown').toLowerCase()
	switch (s) {
	case 'ok': return t('integration_immich', 'OK')
	case 'active': return t('integration_immich', 'Active')
	case 'synced': return t('integration_immich', 'Synced')
	case 'pending': return t('integration_immich', 'Pending')
	case 'provisioning': return t('integration_immich', 'Provisioning')
	case 'failed': return t('integration_immich', 'Failed')
	case 'error': return t('integration_immich', 'Error')
	case 'deleted': return t('integration_immich', 'Deleted')
	case 'warning': return t('integration_immich', 'Warning')
	case 'stale': return t('integration_immich', 'Stale')
	case 'unknown': return t('integration_immich', 'Unknown')
	default: return String(status)
	}
}

function formatTimestamp(iso) {
	if (!iso) return '—'
	try {
		return new Date(iso).toLocaleString()
	} catch {
		return iso
	}
}

function formatBytes(bytes) {
	if (bytes === null || bytes === undefined) return '—'
	if (bytes === 0) return '0 B'
	const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB']
	const i = Math.floor(Math.log(bytes) / Math.log(1024))
	return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i]
}
</script>

<style scoped>
.immich-settings-form {
	max-width: 700px;
}

.field {
	margin-bottom: 16px;
}

.field-label {
	display: block;
	font-weight: bold;
	margin-bottom: 6px;
}

.hint {
	color: var(--color-text-maxcontrast);
	font-size: var(--default-font-size-small, 13px);
	margin-top: 4px;
}

.actions {
	display: flex;
	gap: 8px;
	margin: 16px 0;
}

.actions-row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-wrap: wrap;
}

.uid-input {
	flex: 0 1 200px;
}

.radio-group {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 4px;
}

.results-block {
	margin-top: 12px;
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large, 12px);
}

.results-block h4 {
	margin: 0 0 8px 0;
}

.results-block ul {
	margin: 0;
	padding-left: 20px;
}

.result-json {
	font-size: var(--default-font-size-small, 13px);
	white-space: pre-wrap;
	word-break: break-all;
	margin: 0;
}

.section-header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 8px;
}

.section-header h4 {
	margin: 0;
}

.loading-placeholder,
.empty-placeholder {
	color: var(--color-text-maxcontrast);
	padding: 16px 0;
	display: flex;
	align-items: center;
	gap: 8px;
}

.table-wrapper {
	overflow-x: auto;
}

.sync-table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--default-font-size-small, 13px);
}

.sync-table th,
.sync-table td {
	padding: 6px 10px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.sync-table th {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.mono {
	font-family: var(--font-family-mono, monospace);
	font-size: var(--default-font-size-small, 13px);
}

.error-cell {
	color: var(--color-error, #e9322d);
	max-width: 200px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius, 4px);
	font-size: var(--default-font-size-small, 13px);
	font-weight: bold;
}

.status-ok {
	background: var(--color-success, #46ba61);
	color: #fff;
}

.status-pending {
	background: var(--color-warning, #eca700);
	color: #000;
}

.status-error {
	background: var(--color-error, #e9322d);
	color: #fff;
}

.status-warning {
	background: var(--color-warning, #eca700);
	color: #000;
}

.status-unknown {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.health-cards {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.health-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 12px;
}

.health-card-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.health-card-body {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.health-row {
	display: flex;
	justify-content: space-between;
}

.health-label {
	color: var(--color-text-maxcontrast);
}

.warning-row {
	color: var(--color-warning, #eca700);
	font-size: var(--default-font-size-small, 13px);
}
</style>
