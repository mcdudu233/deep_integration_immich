<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div id="immich-personal-settings">
		<NcSettingsSection :name="t('deep_integration_immich', 'Immich Personal Connection')"
			:description="sectionDescription">
			<div v-if="adminManagedBrowsing" class="immich-personal-settings-form">
				<NcNoteCard type="warning" data-testid="admin-managed-connection-warning">
					<p>{{ t('deep_integration_immich', '请勿随意修改') }}</p>
				</NcNoteCard>

				<NcTextField v-model="adminForm.server_url"
					:label="t('deep_integration_immich', 'Immich server URL')"
					:disabled="true"
					data-testid="admin-managed-immich-server-url" />

				<NcTextField v-model="adminForm.immich_username"
					:label="t('deep_integration_immich', 'Immich account')"
					data-testid="admin-managed-immich-username" />

				<NcPasswordField v-model="adminForm.immich_password"
					:label="t('deep_integration_immich', 'Immich password')"
					data-testid="admin-managed-immich-password" />

				<div class="field">
					<NcPasswordField v-model="adminForm.immich_api_key"
						:label="t('deep_integration_immich', 'Immich API key')"
						:placeholder="adminApiKeySet ? t('deep_integration_immich', 'API key is set') : t('deep_integration_immich', 'Enter Immich API key')"
						data-testid="admin-managed-immich-api-key" />
					<p v-if="adminApiKeySet" class="hint">
						{{ t('deep_integration_immich', 'The provisioned API key is already configured. Leave blank to keep the current key.') }}
					</p>
				</div>

				<div class="actions">
					<NcButton type="primary"
						:disabled="saving || !canSaveAdminManagedConnection"
						data-testid="admin-managed-config-save-button"
						@click="saveAdminManagedConnection">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
						</template>
						{{ t('deep_integration_immich', 'Save and validate') }}
					</NcButton>
				</div>

				<NcNoteCard v-if="message"
					:type="messageType"
					data-testid="personal-config-message">
					<p>{{ message }}</p>
				</NcNoteCard>
			</div>

			<div v-else class="immich-personal-settings-form">
				<NcTextField v-model="form.server_url"
					:label="t('deep_integration_immich', 'Immich server URL')"
					placeholder="https://immich.example.com"
					data-testid="personal-immich-server-url" />

				<div class="field">
					<NcPasswordField v-model="form.api_key"
						:label="t('deep_integration_immich', 'Personal API key')"
						:placeholder="apiKeySet ? t('deep_integration_immich', 'API key is set') : t('deep_integration_immich', 'Enter Immich API key')"
						data-testid="personal-immich-api-key" />
					<p v-if="apiKeySet" class="hint">
						{{ t('deep_integration_immich', 'A personal API key is already configured. Leave blank to keep the current key.') }}
					</p>
				</div>

				<div class="actions">
					<NcButton type="primary"
						:disabled="saving || !form.server_url.trim()"
						data-testid="personal-config-save-button"
						@click="save(false)">
						<template #icon>
							<NcLoadingIcon v-if="saving && !validating" :size="20" />
						</template>
						{{ t('deep_integration_immich', 'Save') }}
					</NcButton>

					<NcButton type="secondary"
						:disabled="saving || !form.server_url.trim()"
						data-testid="personal-config-save-validate-button"
						@click="save(true)">
						<template #icon>
							<NcLoadingIcon v-if="saving && validating" :size="20" />
						</template>
						{{ t('deep_integration_immich', 'Save and validate') }}
					</NcButton>
				</div>

				<NcNoteCard v-if="message"
					:type="messageType"
					data-testid="personal-config-message">
					<p>{{ message }}</p>
				</NcNoteCard>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcPasswordField,
	NcSettingsSection,
	NcTextField,
} from '@nextcloud/vue'
import { getConfig, setConfig } from './services/api.js'

const initialState = loadState('deep_integration_immich', 'personal-config', {})
const initialAdminConnection = initialState.admin_managed_connection || {}

const form = reactive({
	server_url: String(initialState.server_url || initialState.immich_url || ''),
	api_key: '',
})

const adminForm = reactive({
	server_url: String(initialAdminConnection.server_url || initialState.server_url || initialState.immich_url || ''),
	immich_username: String(initialAdminConnection.username || ''),
	immich_password: String(initialAdminConnection.password || ''),
	immich_api_key: String(initialAdminConnection.api_key || ''),
})

const apiKeySet = ref(initialState.api_key_set === true)
const adminApiKeySet = ref(initialAdminConnection.api_key_set === true || adminForm.immich_api_key.trim() !== '')
const saving = ref(false)
const validating = ref(false)
const message = ref('')
const messageType = ref('success')
const adminManagedBrowsing = computed(() => initialState.browsingReadiness?.adminManaged === true)
const sectionDescription = computed(() => adminManagedBrowsing.value
	? t('deep_integration_immich', 'Review the Immich account credentials provisioned by the administrator. Only change them when instructed.')
	: t('deep_integration_immich', 'Configure your personal Immich server URL and API key for browsing when admin proxy browsing is not used.'))
const canSaveAdminManagedConnection = computed(() => adminForm.immich_username.trim() !== ''
	&& adminForm.immich_password.trim() !== ''
	&& (adminApiKeySet.value || adminForm.immich_api_key.trim() !== ''))

onMounted(async () => {
	try {
		const response = await getConfig()
		const config = response.data || {}
		form.server_url = String(config.server_url || form.server_url)
		apiKeySet.value = config.api_key_set === true
		applyAdminManagedConnection(config.admin_managed_connection || {})
	} catch (error) {
		messageType.value = 'warning'
		message.value = t('deep_integration_immich', 'Could not refresh personal Immich settings. Showing saved initial state.')
	}
})

async function save(validate) {
	saving.value = true
	validating.value = validate
	message.value = ''

	try {
		const payload = {
			server_url: form.server_url.trim(),
		}

		if (form.api_key.trim() !== '') {
			payload.api_key = form.api_key.trim()
		}
		if (validate) {
			payload.validate = true
		}

		await setConfig(payload)
		apiKeySet.value = apiKeySet.value || form.api_key.trim() !== ''
		form.api_key = ''
		messageType.value = 'success'
		message.value = validate
			? t('deep_integration_immich', 'Personal Immich settings saved and validated.')
			: t('deep_integration_immich', 'Personal Immich settings saved.')
	} catch (error) {
		messageType.value = 'error'
		message.value = localizeError(error)
	} finally {
		saving.value = false
		validating.value = false
	}
}

async function saveAdminManagedConnection() {
	saving.value = true
	validating.value = true
	message.value = ''

	try {
		const payload = {
			immich_username: adminForm.immich_username.trim(),
			immich_password: adminForm.immich_password,
			validate: true,
		}

		if (adminForm.immich_api_key.trim() !== '') {
			payload.immich_api_key = adminForm.immich_api_key.trim()
		}

		const response = await setConfig(payload)
		applyAdminManagedConnection(response.data?.admin_managed_connection || {})
		adminForm.immich_api_key = ''
		adminApiKeySet.value = true
		messageType.value = 'success'
		message.value = t('deep_integration_immich', 'Immich personal connection saved and validated.')
	} catch (error) {
		messageType.value = 'error'
		message.value = localizeError(error)
	} finally {
		saving.value = false
		validating.value = false
	}
}

function applyAdminManagedConnection(connection) {
	if (connection.enabled !== true) {
		return
	}

	adminForm.server_url = String(connection.server_url || adminForm.server_url)
	adminForm.immich_username = String(connection.username || adminForm.immich_username)
	adminForm.immich_password = String(connection.password || adminForm.immich_password)
	if (typeof connection.api_key === 'string' && connection.api_key !== '') {
		adminForm.immich_api_key = connection.api_key
	}
	adminApiKeySet.value = connection.api_key_set === true || adminForm.immich_api_key.trim() !== ''
}

function localizeError(error) {
	const data = error?.response?.data || {}
	const details = data.error?.details?.detail || data.detail || data.error?.message || data.error
	if (typeof details === 'string' && details !== '') {
		return details
	}

	return error?.message || t('deep_integration_immich', 'Could not save personal Immich settings.')
}
</script>

<style scoped>
.immich-personal-settings-form {
	display: flex;
	max-width: 640px;
	flex-direction: column;
	gap: 16px;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
