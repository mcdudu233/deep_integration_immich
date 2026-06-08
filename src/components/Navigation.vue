<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppNavigation>
		<NcAppNavigationList>
			<NcAppNavigationItem :name="t('integration_immich', 'All media')"
				:to="{ name: 'timeline' }"
				data-testid="nav-all-media"
				:active="$route.name === 'timeline'">
				<template #icon>
					<ImageIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Photos')"
				:to="{ name: 'photos' }"
				data-testid="nav-photos"
				:active="$route.name === 'photos'">
				<template #icon>
					<PhotosIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Videos')"
				:to="{ name: 'videos' }"
				data-testid="nav-videos"
				:active="$route.name === 'videos'">
				<template #icon>
					<VideoIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Favorites')"
				:to="{ name: 'favorites' }"
				data-testid="nav-favorites"
				:active="$route.name === 'favorites'">
				<template #icon>
					<HeartIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Albums')"
				:to="{ name: 'albums' }"
				data-testid="nav-albums"
				:active="$route.name === 'albums' || $route.name === 'album-detail'">
				<template #icon>
					<FolderIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'People')"
				:to="{ name: 'people' }"
				data-testid="nav-people"
				:active="$route.name === 'people' || $route.name === 'person-detail'">
				<template #icon>
					<AccountGroupIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Map')"
				:to="{ name: 'map' }"
				data-testid="nav-map"
				:active="$route.name === 'map'">
				<template #icon>
					<MapIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Explore')"
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
						<span class="immich-status-label">{{ t('integration_immich', 'Mapping') }}</span>
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
						<span class="immich-status-label">{{ t('integration_immich', 'Mount') }}</span>
						<span class="immich-status-value" :class="'immich-mount-' + userMount.status">{{ mountLabel }}</span>
					</div>
				</div>

				<!-- Quota stale warning -->
				<NcNoteCard v-if="userQuota.stale"
					type="warning"
					class="immich-quota-note"
					data-testid="quota-stale-warning-card">
					{{ t('integration_immich', 'Quota sync is stale — run "Recompute" from the admin settings.') }}
				</NcNoteCard>

				<!-- General warnings from initial state -->
				<NcNoteCard v-for="(msg, i) in warnings"
					:key="i"
					type="warning"
					class="immich-warning-note"
					:data-testid="'general-warning-card-' + i">
					{{ msg }}
				</NcNoteCard>
			</div>

			<NcAppNavigationItem v-if="immichUrl"
				:name="t('integration_immich', 'Open Immich')"
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

const userConfig = loadState('integration_immich', 'user-config', {})

// Immich URL (existing — from personal config or initial state)
const rawUrl = userConfig?.server_url || userConfig?.immich_url || ''
const immichUrl = rawUrl.startsWith('http://') || rawUrl.startsWith('https://') ? rawUrl : ''

// Provisioning / mapping / mount / quota from user initial state
const userMapping = userConfig?.mapping ?? { status: 'missing' }
const userMount = userConfig?.mount ?? { status: 'unavailable' }
const userQuota = userConfig?.quota ?? { stale: false }
const warnings = Array.isArray(userConfig?.warnings) ? userConfig.warnings : []

// Display labels
const mappingLabel = computed(() => {
	switch (userMapping.status) {
		case 'mapped': return t('integration_immich', 'Mapped')
		case 'pending': return t('integration_immich', 'Pending')
		case 'missing': return t('integration_immich', 'Missing')
		case 'error': return t('integration_immich', 'Error')
		default: return userMapping.status.charAt(0).toUpperCase() + userMapping.status.slice(1)
	}
})

const mountLabel = computed(() => {
	switch (userMount.status) {
		case 'available': return t('integration_immich', 'Available')
		case 'unavailable': return t('integration_immich', 'Unavailable')
		case 'error': return t('integration_immich', 'Mount error')
		default: return userMount.status.charAt(0).toUpperCase() + userMount.status.slice(1)
	}
})
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
