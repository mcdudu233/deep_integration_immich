<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div v-if="!adminManagedBrowsing" id="immich-personal-settings">
		<NcSettingsSection :name="t('deep_integration_immich', 'Immich Personal Connection')"
			:description="sectionDescription">
			<div class="immich-personal-settings-form">
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

const form = reactive({
	server_url: String(initialState.server_url || initialState.immich_url || ''),
	api_key: '',
})

const apiKeySet = ref(initialState.api_key_set === true)
const saving = ref(false)
const validating = ref(false)
const message = ref('')
const messageType = ref('success')
const adminManagedBrowsing = computed(() => initialState.browsingReadiness?.adminManaged === true
	|| (initialState.provisioning?.enabled === true && initialState.provisioning?.status !== 'personal_unconfigured'))
const sectionDescription = computed(() => t('deep_integration_immich', 'Configure your personal Immich server URL and API key for browsing when admin proxy browsing is not used.'))

onMounted(async () => {
	if (adminManagedBrowsing.value) {
		hidePersonalSettingsNavigationEntry()
		return
	}

	try {
		const response = await getConfig()
		const config = response.data || {}
		form.server_url = String(config.server_url || form.server_url)
		apiKeySet.value = config.api_key_set === true
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

function localizeError(error) {
	const data = error?.response?.data || {}
	const details = data.error?.details?.detail || data.detail || data.error?.message || data.error
	if (typeof details === 'string' && details !== '') {
		return details
	}

	return error?.message || t('deep_integration_immich', 'Could not save personal Immich settings.')
}

function hidePersonalSettingsNavigationEntry() {
	const links = document.querySelectorAll('a[href*="/settings/user/deep_integration_immich-personal"]')
	for (const link of links) {
		const listItem = link.closest('li')
		if (listItem) {
			listItem.hidden = true
		} else {
			link.hidden = true
		}
	}
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
