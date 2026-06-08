<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppNavigation>
		<NcAppNavigationList>
			<NcAppNavigationItem :name="t('deep_integration_immich', 'All media')"
				:to="{ name: 'timeline' }"
				data-testid="nav-all-media"
				:active="$route.name === 'timeline'">
				<template #icon>
					<ImageIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('deep_integration_immich', 'Photos')"
				:to="{ name: 'photos' }"
				data-testid="nav-photos"
				:active="$route.name === 'photos'">
				<template #icon>
					<PhotosIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('deep_integration_immich', 'Videos')"
				:to="{ name: 'videos' }"
				data-testid="nav-videos"
				:active="$route.name === 'videos'">
				<template #icon>
					<VideoIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('deep_integration_immich', 'Favorites')"
				:to="{ name: 'favorites' }"
				data-testid="nav-favorites"
				:active="$route.name === 'favorites'">
				<template #icon>
					<HeartIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('deep_integration_immich', 'Albums')"
				:to="{ name: 'albums' }"
				data-testid="nav-albums"
				:active="$route.name === 'albums' || $route.name === 'album-detail'">
				<template #icon>
					<FolderIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('deep_integration_immich', 'People')"
				:to="{ name: 'people' }"
				data-testid="nav-people"
				:active="$route.name === 'people' || $route.name === 'person-detail'">
				<template #icon>
					<AccountGroupIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('deep_integration_immich', 'Map')"
				:to="{ name: 'map' }"
				data-testid="nav-map"
				:active="$route.name === 'map'">
				<template #icon>
					<MapIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('deep_integration_immich', 'Explore')"
				:to="{ name: 'explore' }"
				data-testid="nav-explore"
				:active="$route.name === 'explore' || $route.name === 'place-detail'">
				<template #icon>
					<CompassIcon :size="20" />
				</template>
			</NcAppNavigationItem>
		</NcAppNavigationList>

		<!-- Footer: provisioning status panel + link to Immich instance -->
		<template #footer>
			<div class="immich-status-panel" data-testid="user-status-panel">
				<!-- Mapping status badge -->
				<div class="immich-status-item" data-testid="mapping-status-badge">
					<CheckCircleIcon v-if="userMapping.status === 'mapped'"
						:size="18" class="immich-status-icon immich-mapping-mapped" />
					<ClockIcon v-else-if="userMapping.status === 'pending'"
						:size="18" class="immich-status-icon immich-mapping-pending" />
					<AlertCircleIcon v-else
						:size="18" class="immich-status-icon immich-mapping-missing" />
					<div class="immich-status-detail">
						<span class="immich-status-label">{{ t('deep_integration_immich', 'Mapping') }}</span>
						<span class="immich-status-value" :class="'immich-mapping-' + userMapping.status">{{ mappingLabel }}</span>
					</div>
				</div>

				<!-- Mount health indicator -->
				<div v-if="userMount.status"
					class="immich-status-item"
					data-testid="mount-health-badge">
					<CheckIcon v-if="userMount.status === 'available'"
						:size="18" class="immich-status-icon immich-mount-available" />
					<CloseIcon v-else-if="userMount.status === 'unavailable'"
						:size="18" class="immich-status-icon immich-mount-unavailable" />
					<AlertIcon v-else
						:size="18" class="immich-status-icon immich-mount-error" />
					<div class="immich-status-detail">
						<span class="immich-status-label">{{ t('deep_integration_immich', 'Mount') }}</span>
						<span class="immich-status-value" :class="'immich-mount-' + userMount.status">{{ mountLabel }}</span>
					</div>
				</div>

				<!-- Quota stale warning -->
				<NcNoteCard v-if="quotaWarning"
					type="warning"
					class="immich-quota-note"
					data-testid="quota-stale-warning-card">
					{{ quotaWarning }}
				</NcNoteCard>

				<!-- General warnings from initial state -->
				<NcNoteCard v-for="(warning, i) in warnings"
					:key="i"
					type="warning"
					class="immich-warning-note"
					:data-testid="'general-warning-card-' + i">
					{{ warning.text }}
				</NcNoteCard>
			</div>

			<NcAppNavigationItem v-if="immichUrl"
				:name="t('deep_integration_immich', 'Open Immich')"
				:href="immichUrl"
				data-testid="nav-open-immich"
				target="_blank"
				rel="noopener noreferrer">
				<template #icon>
					<OpenInNewIcon :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
	</NcAppNavigation>
</template>

<script setup>
import { computed } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { NcAppNavigation, NcAppNavigationList, NcAppNavigationItem, NcNoteCard } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'

import ImageIcon from 'vue-material-design-icons/ImageFrame.vue'
import PhotosIcon from 'vue-material-design-icons/ImageOutline.vue'
import VideoIcon from 'vue-material-design-icons/PlayCircleOutline.vue'
import HeartIcon from 'vue-material-design-icons/HeartOutline.vue'
import FolderIcon from 'vue-material-design-icons/ViewGalleryOutline.vue'
import AccountGroupIcon from 'vue-material-design-icons/FaceWomanShimmerOutline.vue'
import MapIcon from 'vue-material-design-icons/MapOutline.vue'
import CompassIcon from 'vue-material-design-icons/Telescope.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'
import ClockIcon from 'vue-material-design-icons/ClockOutline.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'

const userConfig = loadState('deep_integration_immich', 'user-config', {})

// Immich URL (existing — from personal config or initial state)
const rawUrl = userConfig?.server_url || userConfig?.immich_url || ''
const immichUrl = rawUrl.startsWith('http://') || rawUrl.startsWith('https://') ? rawUrl : ''

// Provisioning / mapping / mount / quota from user initial state
const userMapping = userConfig?.mapping ?? { status: 'missing' }
const userMount = userConfig?.mount ?? { status: 'unavailable' }
const userQuota = userConfig?.quota ?? { stale: false }
const warnings = normalizeWarnings(userConfig?.warningDetails, userConfig?.warnings)

// Display labels
const mappingLabel = computed(() => {
	switch (userMapping.status) {
		case 'mapped': return t('deep_integration_immich', 'Mapped')
		case 'pending': return t('deep_integration_immich', 'Pending')
		case 'missing': return t('deep_integration_immich', 'Missing')
		case 'error': return t('deep_integration_immich', 'Error')
		case 'active': return t('deep_integration_immich', 'Active')
		case 'failed': return t('deep_integration_immich', 'Failed')
		default: return unknownStatusLabel(userMapping.status)
	}
})

const mountLabel = computed(() => {
	switch (userMount.status) {
		case 'ok': return t('deep_integration_immich', 'OK')
		case 'available': return t('deep_integration_immich', 'Available')
		case 'unavailable': return t('deep_integration_immich', 'Unavailable')
		case 'error': return t('deep_integration_immich', 'Mount error')
		default: return unknownStatusLabel(userMount.status)
	}
})

const quotaWarning = computed(() => {
	if (userQuota.warningCode) {
		return localizeStatusCode(userQuota.warningCode, userQuota.warningParams, userQuota.warning)
	}
	if (userQuota.stale) {
		return localizeStatusCode('quota_stale', {}, userQuota.warning)
	}
	return ''
})

function normalizeWarnings(details, legacyWarnings) {
	if (Array.isArray(details) && details.length > 0) {
		return details.map((warning) => ({
			code: warning?.code,
			text: localizeStatusCode(warning?.code, warning?.params, warning?.message),
		}))
	}

	return Array.isArray(legacyWarnings)
		? legacyWarnings.filter((warning) => typeof warning === 'string').map((warning) => ({ text: warning }))
		: []
}

function unknownStatusLabel(status) {
	return t('deep_integration_immich', 'Unknown status ({code})', { code: String(status || 'unknown') })
}

function localizeStatusCode(code, params = {}, legacyMessage = '') {
	if (!code) {
		return legacyMessage || t('deep_integration_immich', 'Immich status is unavailable.')
	}

	switch (code) {
	case 'mapping_status_unavailable':
		return t('deep_integration_immich', 'Immich mapping status is temporarily unavailable.')
	case 'mount_health_unavailable':
		return t('deep_integration_immich', 'Immich mirror mount health is temporarily unavailable.')
	case 'mount_health_status':
		return t('deep_integration_immich', 'Immich mirror mount health is {status}.', { status: params?.status ?? t('deep_integration_immich', 'unknown') })
	case 'quota_needs_mapping':
		return t('deep_integration_immich', 'Quota sync needs an Immich user mapping before quota details are available.')
	case 'quota_unavailable':
		return t('deep_integration_immich', 'Quota details are unavailable. Run quota sync from the admin settings for authoritative status.')
	case 'quota_unlimited':
		return t('deep_integration_immich', 'Nextcloud quota is unlimited; Immich quota sync will leave the Immich quota unlimited.')
	case 'quota_stale':
		return t('deep_integration_immich', 'Quota has not been synced yet; values may be stale until the next quota sync job runs.')
	case 'action_capabilities_unavailable':
		return t('deep_integration_immich', 'Immich action capabilities are temporarily unavailable.')
	default:
		return t('deep_integration_immich', 'Immich reported status code: {code}', { code: String(code) })
	}
}
</script>

<style scoped>
.immich-status-panel {
	padding: 8px 12px;
	border-top: 1px solid var(--color-border);
	margin-bottom: 4px;
}

.immich-status-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.immich-status-icon {
	flex-shrink: 0;
}

.immich-status-detail {
	display: flex;
	flex-direction: column;
	line-height: 1.3;
}

.immich-status-label {
	font-size: var(--default-font-size);
	color: var(--color-text-maxcontrast);
}

.immich-status-value {
	font-size: 13px;
	font-weight: 600;
}

/* Mapping status colours */
.immich-mapping-mapped { color: var(--color-success); }
.immich-mapping-pending { color: var(--color-warning); }
.immich-mapping-missing,
.immich-mapping-error   { color: var(--color-error); }

/* Mount health colours */
.immich-mount-available   { color: var(--color-success); }
.immich-mount-unavailable { color: var(--color-error); }
.immich-mount-error       { color: var(--color-warning); }

.immich-quota-note,
.immich-warning-note {
	margin: 8px 0;
}
</style>
