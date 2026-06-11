/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineStore } from 'pinia'
import { translate as t } from '@nextcloud/l10n'
import { getTimeline, getAlbums, getAlbum, getPeople, getMapMarkers, getExplore } from '../services/api.js'

export const useImmichStore = defineStore('immich', {
	state: () => ({
		// Timeline
		timelineBuckets: [],
		timelineAssets: {},
		// Filtered timelines (keyed by assetType: 'IMAGE' | 'VIDEO')
		filteredBuckets: { IMAGE: [], VIDEO: [] },
		filteredAssets: { IMAGE: {}, VIDEO: {} },
		// Favorites
		favoriteBuckets: [],
		favoriteAssets: {},
		// Albums
		albums: [],
		currentAlbum: null,
		// People list
		people: [],
		// Person detail — lazy-loaded buckets (same pattern as timeline)
		currentPersonId: null,
		personBuckets: [],
		personBucketAssets: {},
		// Map
		mapMarkers: [],
		// Explore
		exploreData: [],
		// UI
		loading: false,
		error: null,
		// Lightbox
		lightbox: {
			visible: false,
			assets: [],
			currentIndex: 0,
		},
		// Selection
		selectedAssetIds: new Set(),
		isSelectionMode: false,
		actionCapabilities: {
			exportCopyEnabled: false,
			importToImmichEnabled: false,
			immichDeleteEnabled: false,
			mirrorMountPaths: [],
		},
		// User-facing provisioning status (from FrontendInitialStateService)
		immichUrl: '',
		provisioning: {
			enabled: false,
			scope: 'all',
			scopedGroups: [],
			status: '',
		},
		mapping: {
			status: 'missing',
			immichUserId: null,
			storageLabel: null,
			lastSyncAt: null,
			message: '',
		},
		mount: {
			status: 'unavailable',
			mountId: null,
			path: null,
			readOnly: null,
		},
		quota: {
			status: 'disabled',
			mode: 'disabled',
			ncQuota: null,
			ncUsed: null,
			immichUsage: null,
			computedImmichQuota: null,
			immichAvailable: null,
			ncRemaining: null,
			reserve: 0,
			stale: false,
			warning: null,
			lastSyncAt: null,
		},
        browsingReadiness: {
            status: 'ready',
            severity: 'info',
            autoLoginMode: 'server_handoff',
            messageKey: null,
            localizedMessage: null,
            showAppBanner: false,
            showSidebarCard: false,
            showPersonalSettings: false,
			message: null,
			messageCode: null,
			messageParams: {},
			adminManaged: false,
			mapped: false,
			mountStatus: 'unavailable',
			quotaStatus: 'unknown',
		},
		quotaStatus: 'unknown',
		warnings: [],
		warningDetails: [],
	}),

	actions: {
		setActionCapabilities(capabilities = {}) {
			this.actionCapabilities = {
				exportCopyEnabled: capabilities.exportCopyEnabled === true,
				importToImmichEnabled: capabilities.importToImmichEnabled === true,
				immichDeleteEnabled: capabilities.immichDeleteEnabled === true,
				mirrorMountPaths: Array.isArray(capabilities.mirrorMountPaths) ? capabilities.mirrorMountPaths : [],
			}
		},

		setUserState(state = {}) {
			// Immich URL for "Open in Immich" link
			if (typeof state.immich_url === 'string') {
				this.immichUrl = state.immich_url
			}

			// Provisioning status
			if (state.provisioning && typeof state.provisioning === 'object') {
				this.provisioning = {
					enabled: state.provisioning.enabled === true,
					scope: typeof state.provisioning.scope === 'string' ? state.provisioning.scope : 'all',
					scopedGroups: Array.isArray(state.provisioning.scopedGroups) ? state.provisioning.scopedGroups : [],
					status: typeof state.provisioning.status === 'string' ? state.provisioning.status : '',
				}
			}

			// Mapping status
			if (state.mapping && typeof state.mapping === 'object') {
				this.mapping = {
					status: typeof state.mapping.status === 'string' ? state.mapping.status : 'missing',
					immichUserId: state.mapping.immichUserId ?? null,
					storageLabel: state.mapping.storageLabel ?? null,
					lastSyncAt: state.mapping.lastSyncAt ?? null,
					message: typeof state.mapping.message === 'string' ? state.mapping.message : '',
				}
			}

			// Mount health
			if (state.mount && typeof state.mount === 'object') {
				this.mount = {
					status: typeof state.mount.status === 'string' ? state.mount.status : 'unavailable',
					mountId: state.mount.mountId ?? null,
					path: state.mount.path ?? null,
					readOnly: state.mount.readOnly ?? null,
				}
			}

			// Quota sync status
			if (state.quota && typeof state.quota === 'object') {
				this.quota = {
					status: typeof state.quota.status === 'string' ? state.quota.status : 'disabled',
					mode: typeof state.quota.mode === 'string' ? state.quota.mode : 'disabled',
					ncQuota: state.quota.ncQuota ?? null,
					ncUsed: state.quota.ncUsed ?? null,
					ncRemaining: state.quota.ncRemaining ?? null,
					immichUsage: state.quota.immichUsage ?? null,
					immichAvailable: state.quota.immichAvailable ?? null,
					computedImmichQuota: state.quota.computedImmichQuota ?? null,
					reserve: typeof state.quota.reserve === 'number' ? state.quota.reserve : 0,
					stale: state.quota.stale === true,
					warning: state.quota.warning ?? null,
					lastSyncAt: state.quota.lastSyncAt ?? null,
				}
			}

			if (state.browsingReadiness && typeof state.browsingReadiness === 'object') {
                this.browsingReadiness = {
                    status: typeof state.browsingReadiness.status === 'string' ? state.browsingReadiness.status : 'ready',
                    severity: typeof state.browsingReadiness.severity === 'string' ? state.browsingReadiness.severity : 'info',
                    autoLoginMode: typeof state.browsingReadiness.autoLoginMode === 'string' ? state.browsingReadiness.autoLoginMode : 'server_handoff',
                    messageKey: typeof state.browsingReadiness.messageKey === 'string' ? state.browsingReadiness.messageKey : null,
                    localizedMessage: typeof state.browsingReadiness.localizedMessage === 'string' ? state.browsingReadiness.localizedMessage : null,
                    showAppBanner: state.browsingReadiness.showAppBanner === true,
                    showSidebarCard: state.browsingReadiness.showSidebarCard === true,
                    showPersonalSettings: state.browsingReadiness.showPersonalSettings === true,
					message: typeof state.browsingReadiness.message === 'string' ? state.browsingReadiness.message : null,
					messageCode: typeof state.browsingReadiness.messageCode === 'string' ? state.browsingReadiness.messageCode : null,
					messageParams: state.browsingReadiness.messageParams && typeof state.browsingReadiness.messageParams === 'object'
						? state.browsingReadiness.messageParams
						: {},
					adminManaged: state.browsingReadiness.adminManaged === true,
					mapped: state.browsingReadiness.mapped === true,
					mountStatus: typeof state.browsingReadiness.mountStatus === 'string' ? state.browsingReadiness.mountStatus : 'unavailable',
					quotaStatus: typeof state.browsingReadiness.quotaStatus === 'string' ? state.browsingReadiness.quotaStatus : 'unknown',
				}
			}

			if (typeof state.quotaStatus === 'string') {
				this.quotaStatus = state.quotaStatus
			}

			// Action capabilities (preferred source)
			if (state.actionCapabilities && typeof state.actionCapabilities === 'object') {
				this.setActionCapabilities(state.actionCapabilities)
			}

			// Warnings
			if (Array.isArray(state.warnings)) {
				this.warnings = state.warnings.filter(w => typeof w === 'string')
			}
			if (Array.isArray(state.warningDetails)) {
				this.warningDetails = state.warningDetails.filter(w => w && typeof w === 'object')
			}
		},

		// ---- Timeline ----

		async fetchTimelineBuckets() {
			this.loading = true
			this.error = null
			try {
				const response = await getTimeline()
				this.timelineBuckets = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				const isTimeout = e.code === 'ECONNABORTED' || e.message?.includes('timeout')
				this.error = isTimeout
					? t('deep_integration_immich', 'Request timed out — Immich may be slow or unreachable. Check your server connection.')
					: (e.response?.data?.error || e.message)
			} finally {
				this.loading = false
			}
		},

		async fetchTimelineBucket(timeBucket, signal = null) {
			if (this.timelineAssets[timeBucket]) {
				return
			}
			try {
				const response = await getTimeline({ timeBucket }, signal)
				this.timelineAssets[timeBucket] = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				if (e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return
				this.error = e.response?.data?.error || e.message
			}
		},

		unloadTimelineBucket(timeBucket) {
			delete this.timelineAssets[timeBucket]
		},

		// ---- Filtered timelines (Fotos / Videos) ----

		async fetchFilteredBuckets(assetType) {
			this.loading = true
			this.error = null
			try {
				// Immich's timeline/buckets endpoint does not support assetType filtering.
				// Reuse the main timeline bucket structure; PHP will filter the asset content.
				if (this.timelineBuckets.length === 0) {
					const response = await getTimeline()
					this.timelineBuckets = Array.isArray(response.data) ? response.data : []
				}
				this.filteredBuckets[assetType] = this.timelineBuckets.map(b => {
					const copy = { ...b }
					const loaded = this.filteredAssets[assetType][b.timeBucket]
					if (loaded) copy.count = loaded.length
					return copy
				})
			} catch (e) {
				const isTimeout = e.code === 'ECONNABORTED' || e.message?.includes('timeout')
				this.error = isTimeout
					? t('deep_integration_immich', 'Request timed out — Immich may be slow or unreachable. Check your server connection.')
					: (e.response?.data?.error || e.message)
			} finally {
				this.loading = false
			}
		},

		async fetchFilteredBucket(assetType, timeBucket, signal = null) {
			if (this.filteredAssets[assetType][timeBucket]) return
			try {
				// Pass assetType so PHP backend can filter the returned assets by type
				const response = await getTimeline({ assetType, timeBucket }, signal)
				const assets = Array.isArray(response.data) ? response.data : []
				this.filteredAssets[assetType][timeBucket] = assets
				// Update bucket count to match actual filtered asset count for correct height estimation
				const bucket = this.filteredBuckets[assetType].find(b => b.timeBucket === timeBucket)
				if (bucket) {
					bucket.count = assets.length
				}
			} catch (e) {
				if (e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return
				this.error = e.response?.data?.error || e.message
			}
		},

		unloadFilteredBucket(assetType, timeBucket) {
			delete this.filteredAssets[assetType][timeBucket]
		},

		// ---- Favorites ----

		async fetchFavoriteBuckets() {
			this.loading = true
			this.error = null
			try {
				const response = await getTimeline({ isFavorite: true })
				this.favoriteBuckets = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				const isTimeout = e.code === 'ECONNABORTED' || e.message?.includes('timeout')
				this.error = isTimeout
					? t('deep_integration_immich', 'Request timed out — Immich may be slow or unreachable. Check your server connection.')
					: (e.response?.data?.error || e.message)
			} finally {
				this.loading = false
			}
		},

		async fetchFavoriteBucket(timeBucket, signal = null) {
			if (this.favoriteAssets[timeBucket]) return
			try {
				const response = await getTimeline({ isFavorite: true, timeBucket }, signal)
				this.favoriteAssets[timeBucket] = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				if (e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return
				this.error = e.response?.data?.error || e.message
			}
		},

		unloadFavoriteBucket(timeBucket) {
			delete this.favoriteAssets[timeBucket]
		},

		// ---- Albums ----

		async fetchAlbums() {
			this.loading = true
			this.error = null
			try {
				const response = await getAlbums()
				this.albums = response.data
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		async fetchAlbum(id) {
			this.loading = true
			this.error = null
			try {
				const response = await getAlbum(id)
				this.currentAlbum = response.data
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		// ---- People list ----

		async fetchPeople() {
			this.loading = true
			this.error = null
			try {
				const response = await getPeople()
				this.people = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		// ---- Person detail (lazy buckets) ----

		async fetchPersonBuckets(id) {
			this.loading = true
			this.error = null
			this.currentPersonId = id
			this.personBuckets = []
			this.personBucketAssets = {}
			try {
				const response = await getTimeline({ personId: id })
				this.personBuckets = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				const isTimeout = e.code === 'ECONNABORTED' || e.message?.includes('timeout')
				this.error = isTimeout
					? t('deep_integration_immich', 'Request timed out — Immich may be slow or unreachable. Check your server connection.')
					: (e.response?.data?.error || e.message)
			} finally {
				this.loading = false
			}
		},

		async fetchPersonBucketAsset(personId, timeBucket) {
			if (this.personBucketAssets[timeBucket]) {
				return
			}
			try {
				const response = await getTimeline({ personId, timeBucket })
				this.personBucketAssets[timeBucket] = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			}
		},

		unloadPersonBucketAsset(timeBucket) {
			delete this.personBucketAssets[timeBucket]
		},

		// ---- Map ----

		async fetchMapMarkers() {
			this.loading = true
			this.error = null
			try {
				const response = await getMapMarkers()
				this.mapMarkers = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		// ---- Explore ----

		async fetchExplore() {
			this.loading = true
			this.error = null
			try {
				const response = await getExplore()
				this.exploreData = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		// ---- Lightbox ----

		openLightbox(assets, index = 0) {
			this.lightbox.assets = assets
			this.lightbox.currentIndex = index
			this.lightbox.visible = true
		},

		closeLightbox() {
			this.lightbox.visible = false
			this.lightbox.assets = []
			this.lightbox.currentIndex = 0
		},

		lightboxNext() {
			if (this.lightbox.currentIndex < this.lightbox.assets.length - 1) {
				this.lightbox.currentIndex++
			}
		},

		lightboxPrev() {
			if (this.lightbox.currentIndex > 0) {
				this.lightbox.currentIndex--
			}
		},

		patchLightboxAsset(index, assetData) {
			if (this.lightbox.assets && index >= 0 && index < this.lightbox.assets.length) {
				this.lightbox.assets[index] = assetData
			}
		},

		removeAssetFromLightbox(assetId) {
			const index = this.lightbox.assets.findIndex(a => a.id === assetId)
			if (index === -1) return

			// Remove from lightbox assets array
			this.lightbox.assets.splice(index, 1)

			// Also remove from all cached timeline/filtered/favorite data
			this.removeAssetFromAllCaches(assetId)

			// Adjust current index if needed
			if (this.lightbox.currentIndex >= this.lightbox.assets.length) {
				this.lightbox.currentIndex = Math.max(0, this.lightbox.assets.length - 1)
			}
		},

		removeAssetFromAllCaches(assetId) {
			// Remove from timeline assets
			for (const bucket in this.timelineAssets) {
				this.timelineAssets[bucket] = this.timelineAssets[bucket].filter(a => a.id !== assetId)
			}

			// Remove from filtered assets (photos/videos)
			for (const type in this.filteredAssets) {
				for (const bucket in this.filteredAssets[type]) {
					this.filteredAssets[type][bucket] = this.filteredAssets[type][bucket].filter(a => a.id !== assetId)
				}
			}

			// Remove from favorite assets
			for (const bucket in this.favoriteAssets) {
				this.favoriteAssets[bucket] = this.favoriteAssets[bucket].filter(a => a.id !== assetId)
			}

			// Remove from person bucket assets
			for (const bucket in this.personBucketAssets) {
				this.personBucketAssets[bucket] = this.personBucketAssets[bucket].filter(a => a.id !== assetId)
			}

			// Remove from album assets
			if (this.currentAlbum && this.currentAlbum.assets) {
				this.currentAlbum.assets = this.currentAlbum.assets.filter(a => a.id !== assetId)
			}

			// Remove from explore data
			this.exploreData.forEach(group => {
				if (group.assets) {
					group.assets = group.assets.filter(a => a.id !== assetId)
				}
			})
		},

		// ---- Selection ----

		enterSelectionMode() {
			this.isSelectionMode = true
		},

		toggleAssetSelection(id) {
			const updated = new Set(this.selectedAssetIds)
			if (updated.has(id)) {
				updated.delete(id)
			} else {
				updated.add(id)
			}
			this.selectedAssetIds = updated
		},

		clearSelection() {
			this.selectedAssetIds = new Set()
			this.isSelectionMode = false
		},

		// ---- Asset patching ----

		// Update isFavorite in-place across ALL loaded caches so the UI reflects
		// the change immediately without a full reload.
		patchAssetFavorite(ids, isFavorite) {
			const idSet = new Set(ids)

			const patchList = (list) => {
				for (let i = 0; i < list.length; i++) {
					if (idSet.has(list[i].id)) {
						list[i] = { ...list[i], isFavorite }
					}
				}
			}

			// Timeline
			for (const key of Object.keys(this.timelineAssets)) {
				patchList(this.timelineAssets[key])
			}
			// Filtered (Fotos / Videos)
			for (const cache of Object.values(this.filteredAssets)) {
				for (const key of Object.keys(cache)) {
					patchList(cache[key])
				}
			}
			// Favorites
			for (const key of Object.keys(this.favoriteAssets)) {
				patchList(this.favoriteAssets[key])
			}
			// Person detail
			for (const key of Object.keys(this.personBucketAssets)) {
				patchList(this.personBucketAssets[key])
			}
			// Album detail
			if (this.currentAlbum?.assets) {
				patchList(this.currentAlbum.assets)
			}
			// Lightbox assets
			patchList(this.lightbox.assets)
		},
	},

	getters: {
		// Flat map of all currently loaded assets (id → asset object) across all caches.
		// Used to check isFavorite status of selected assets without extra API calls.
		allLoadedAssetsMap(state) {
			const map = {}
			// Timeline
			for (const assets of Object.values(state.timelineAssets)) {
				for (const a of assets) map[a.id] = a
			}
			// Filtered (photos / videos)
			for (const cache of Object.values(state.filteredAssets)) {
				for (const assets of Object.values(cache)) {
					for (const a of assets) map[a.id] = a
				}
			}
			// Favorites
			for (const assets of Object.values(state.favoriteAssets)) {
				for (const a of assets) map[a.id] = a
			}
			// Person detail
			for (const assets of Object.values(state.personBucketAssets)) {
				for (const a of assets) map[a.id] = a
			}
			// Album detail
			if (state.currentAlbum?.assets) {
				for (const a of state.currentAlbum.assets) map[a.id] = a
			}
			return map
		},
	},
})
