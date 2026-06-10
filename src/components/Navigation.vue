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
			<div v-if="showAdminManagedStatusPanel"
				class="immich-status-panel"
				data-testid="user-status-panel">
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

				<div class="immich-quota-card" data-testid="sidebar-quota-card">
					<div v-for="row in quotaRows"
						:key="row.testid"
						class="immich-quota-row"
						:data-testid="row.testid">
						<span class="immich-quota-label">{{ row.label }}</span>
						<span class="immich-quota-value">{{ row.value }}</span>
					</div>
					<p v-if="showQuotaCaveat"
						class="immich-quota-caveat"
						data-testid="sidebar-quota-caveat">
						{{ t('deep_integration_immich', 'Quota may lag until external storage is scanned.') }}
					</p>
				</div>

				<!-- Quota stale warning -->
				<NcNoteCard v-if="browsingReadinessWarning"
					type="warning"
					class="immich-warning-note"
					data-testid="browsing-readiness-warning-card">
					{{ browsingReadinessWarning }}
				</NcNoteCard>

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
			<NcNoteCard v-if="showSsoGuidance"
				type="info"
				class="immich-sso-guidance"
				data-testid="open-immich-sso-guidance">
				{{ t('deep_integration_immich', 'Immich does not provide an official API for Nextcloud to silently create a web login session. To open Immich without re-authentication, configure Nextcloud and Immich with the same OIDC/SSO provider.') }}
			</NcNoteCard>
		</template>
	</NcAppNavigation>
</template>

<script setup>
import { computed } from 'vue'
import { NcAppNavigation, NcAppNavigationList, NcAppNavigationItem, NcNoteCard } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'

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

const store = useImmichStore()

// Immich URL (existing — from personal config or initial state)
const immichUrl = computed(() => {
	const rawUrl = store.immichUrl || ''
	return normalizeImmichUrl(rawUrl)
})

// Provisioning / mapping / mount / quota from user initial state
const userMapping = computed(() => store.mapping ?? { status: 'missing' })
const userMount = computed(() => store.mount ?? { status: 'unavailable' })
const userQuota = computed(() => store.quota ?? { stale: false })
const browsingReadiness = computed(() => store.browsingReadiness ?? { status: 'ready' })
const showAdminManagedStatusPanel = computed(() => browsingReadiness.value.adminManaged === true)
const showSsoGuidance = computed(() => browsingReadiness.value.adminManaged === true
	&& browsingReadiness.value.autoLoginMode === 'sso_recommended')
const warnings = computed(() => normalizeWarnings(store.warningDetails, store.warnings))

// Display labels
const mappingLabel = computed(() => {
	switch (userMapping.value.status) {
		case 'mapped': return t('deep_integration_immich', 'Mapped')
		case 'pending': return t('deep_integration_immich', 'Pending')
		case 'missing': return t('deep_integration_immich', 'Missing')
		case 'error': return t('deep_integration_immich', 'Error')
		case 'active': return t('deep_integration_immich', 'Active')
		case 'failed': return t('deep_integration_immich', 'Failed')
		default: return unknownStatusLabel(userMapping.value.status)
	}
})

const mountLabel = computed(() => {
	switch (userMount.value.status) {
		case 'ok': return t('deep_integration_immich', 'OK')
		case 'available': return t('deep_integration_immich', 'Available')
		case 'unavailable': return t('deep_integration_immich', 'Unavailable')
		case 'error': return t('deep_integration_immich', 'Mount error')
		default: return unknownStatusLabel(userMount.value.status)
	}
})

const quotaWarning = computed(() => {
	if (userQuota.value.warningCode) {
		return localizeStatusCode(userQuota.value.warningCode, userQuota.value.warningParams, userQuota.value.warning)
	}
	if (store.quotaStatus && store.quotaStatus !== 'current' && store.quotaStatus !== 'unknown') {
		return localizeStatusCode(`quota_${store.quotaStatus}`, {}, userQuota.value.warning)
	}
	if (userQuota.value.stale) {
		return localizeStatusCode('quota_stale', {}, userQuota.value.warning)
	}
	return ''
})

const quotaRows = computed(() => [
	{
		label: t('deep_integration_immich', 'Immich used'),
		value: formatOptionalBytes(userQuota.value.immichUsage),
		testid: 'sidebar-quota-immich-used',
	},
	{
		label: t('deep_integration_immich', 'Nextcloud remaining'),
		value: nextcloudRemainingLabel.value,
		testid: 'sidebar-quota-nextcloud-remaining',
	},
	{
		label: t('deep_integration_immich', 'Immich quota'),
		value: formatOptionalBytes(userQuota.value.computedImmichQuota),
		testid: 'sidebar-quota-immich-quota',
	},
	{
		label: t('deep_integration_immich', 'Last sync'),
		value: lastQuotaSyncLabel.value,
		testid: 'sidebar-quota-last-sync',
	},
])

const nextcloudRemainingLabel = computed(() => {
	if (userQuota.value.status === 'unlimited') {
		return t('deep_integration_immich', 'Unlimited')
	}

	const rawQuota = userQuota.value.ncQuota
	if (rawQuota === null || rawQuota === undefined || rawQuota === '') {
		return t('deep_integration_immich', 'Unlimited')
	}

	const ncQuota = Number(rawQuota)
	if (!Number.isFinite(ncQuota)) {
		return '—'
	}

	const ncUsed = Number(userQuota.value.ncUsed)
	return formatBytes(Math.max(0, ncQuota - (Number.isFinite(ncUsed) ? ncUsed : 0)))
})

const lastQuotaSyncLabel = computed(() => userQuota.value.lastSyncAt || '—')

const showQuotaCaveat = computed(() => store.quotaStatus !== 'current'
	|| ['disabled', 'unavailable', 'failed'].includes(userQuota.value.status)
	|| userQuota.value.stale === true)

const browsingReadinessWarning = computed(() => {
	const status = browsingReadiness.value.status
	if (browsingReadiness.value.showSidebarCard === false || !status || status === 'ready' || status === 'admin_managed_ready') {
		return ''
	}

	return localizeStatusCode(
		readinessMessageKey(),
		browsingReadiness.value.messageParams,
		readinessLocalizedMessage(),
	)
		|| readinessFallbackLabel(status)
		|| ''
})

function readinessMessageKey() {
	return browsingReadiness.value.messageKey || browsingReadiness.value.messageCode || ''
}

function readinessLocalizedMessage() {
	return browsingReadiness.value.localizedMessage || browsingReadiness.value.message || ''
}

function readinessFallbackLabel(status) {
	switch (status) {
	case 'error': return t('deep_integration_immich', 'Immich browsing status is temporarily unavailable.')
	case 'admin_config_missing': return t('deep_integration_immich', 'Immich admin configuration is incomplete. Ask an administrator to configure Immich browsing.')
	case 'unmapped': return t('deep_integration_immich', 'No Immich mapping exists for this Nextcloud user yet. Ask an administrator to run Immich provisioning.')
	case 'manual_setup_required': return t('deep_integration_immich', 'Immich mirror mount requires manual administrator setup before browsing is ready.')
	case 'mount_pending': return t('deep_integration_immich', 'Immich mirror mount is pending. Upload through Immich first or ask an administrator to reconcile provisioning.')
	case 'personal_unconfigured': return t('deep_integration_immich', 'Immich browsing is not configured for this account.')
	default: return ''
	}

	return ''
}

function normalizeWarnings(details, legacyWarnings) {
	if (Array.isArray(details) && details.length > 0) {
		return details.filter((warning) => !isReadinessWarning(warning?.code) && !isQuotaWarning(warning?.code)).map((warning) => ({
			code: warning?.code,
			text: localizeStatusCode(warning?.code, warning?.params, warning?.message),
		}))
	}

	return Array.isArray(legacyWarnings)
		? legacyWarnings.filter((warning) => typeof warning === 'string' && warning !== readinessLocalizedMessage() && warning !== userQuota.value.warning).map((warning) => ({ text: warning }))
		: []
}

function isReadinessWarning(code) {
	return code === readinessMessageKey()
		|| code === 'no_immich_mapping'
		|| code === 'browsing_status_error'
		|| code === 'browsing_admin_config_missing'
		|| code === 'browsing_setup_not_configured'
		|| code === 'browsing_mount_pending'
		|| code === 'browsing_manual_setup_required'
}

function isQuotaWarning(code) {
	return code === userQuota.value.warningCode
		|| code === 'quota_stale'
		|| code === 'quota_sync_failed'
		|| code === 'quota_external_storage_not_included'
}

function formatBytes(bytes) {
	const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB']
	let value = bytes
	let unitIndex = 0

	while (value >= 1024 && unitIndex < units.length - 1) {
		value /= 1024
		unitIndex += 1
	}

	const precision = unitIndex === 0 || value >= 10 ? 0 : 1
	return `${value.toFixed(precision)} ${units[unitIndex]}`
}

function formatOptionalBytes(bytes) {
	const value = Number(bytes)
	return Number.isFinite(value) && value >= 0 ? formatBytes(value) : '—'
}

function unknownStatusLabel(status) {
	return t('deep_integration_immich', 'Unknown status ({code})', { code: String(status || 'unknown') })
}

function normalizeImmichUrl(rawUrl) {
	if (typeof rawUrl !== 'string') {
		return ''
	}

	try {
		const parsed = new URL(rawUrl)
		if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
			return ''
		}

		const pathname = parsed.pathname === '/' ? '' : parsed.pathname.replace(/\/$/, '')
		return `${parsed.origin}${pathname}`
	} catch {
		return ''
	}
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
	case 'quota_sync_failed':
		return t('deep_integration_immich', 'Quota sync failed. Run quota sync from the admin settings for authoritative status.')
	case 'quota_external_storage_not_included':
		return t('deep_integration_immich', 'Nextcloud external-storage quota inclusion is not enabled; Immich quota values may not enforce the combined quota.')
	case 'action_capabilities_unavailable':
		return t('deep_integration_immich', 'Immich action capabilities are temporarily unavailable.')
	case 'browsing_status_error':
		return t('deep_integration_immich', 'Immich browsing status is temporarily unavailable.')
	case 'browsing_admin_config_missing':
		return t('deep_integration_immich', 'Immich admin configuration is incomplete. Ask an administrator to configure Immich browsing.')
	case 'browsing_setup_not_configured':
		return t('deep_integration_immich', 'Immich browsing is not configured for this account.')
	case 'browsing_mount_pending':
		return t('deep_integration_immich', 'Immich mirror mount is pending. Upload through Immich first or ask an administrator to reconcile provisioning.')
	case 'browsing_manual_setup_required':
		return t('deep_integration_immich', 'Immich mirror mount requires manual administrator setup before browsing is ready.')
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

.immich-quota-card {
	margin: 8px 0;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.immich-quota-row {
	display: flex;
	justify-content: space-between;
	gap: 12px;
	padding: 2px 0;
	font-size: 12px;
}

.immich-quota-label {
	color: var(--color-text-maxcontrast);
}

.immich-quota-value {
	font-weight: 600;
	text-align: end;
	word-break: break-word;
}

.immich-quota-caveat {
	margin: 6px 0 0;
	font-size: 12px;
	line-height: 1.3;
	color: var(--color-warning);
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

.immich-sso-guidance {
	margin: 0 12px 8px;
}
</style>
