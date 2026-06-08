/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineStore } from 'pinia'
import {
	getAdminSettings,
	setAdminSettings,
	validateAdminConnection,
	dryRunOne,
	dryRunAll,
	reconcileOne,
	reconcileAll,
	recomputeQuotaOne,
	recomputeQuotaAll,
	listSyncState,
	verifyMountHealth,
} from '../services/api.js'

/**
 * Normalise a backend or network error into a single user-visible string.
 *
 * Backend controllers return:
 *   { success: false, error: { code, message, details: { detail, ... } } }
 * We prefer the structured message, fall back to the detail field, then to
 * the Axios / native error message.
 */
function normaliseError(e) {
	if (e.response?.data?.error?.details?.detail) {
		return e.response.data.error.details.detail
	}
	if (e.response?.data?.error?.message) {
		return e.response.data.error.message
	}
	if (e.response?.data?.error) {
		return String(e.response.data.error)
	}
	if (e.response?.data?.detail) {
		return e.response.data.detail
	}
	return e.message || String(e)
}

export const useAdminProvisioningStore = defineStore('adminProvisioning', {
	state: () => ({
		adminSettings: { loading: false, error: null, data: {} },
		capabilities: { loading: false, error: null, data: {} },
		dryRun: { loading: false, error: null, results: [] },
		reconcile: { loading: false, error: null, status: {} },
		quotaRecompute: { loading: false, error: null, status: {} },
		syncStates: { loading: false, error: null, list: [] },
		mountVerify: { loading: false, error: null, health: {} },
	}),

	actions: {
		// ── Admin Settings ──────────────────────────────────────────

		async fetchAdminSettings() {
			this.adminSettings.loading = true
			this.adminSettings.error = null
			try {
				const response = await getAdminSettings()
				this.adminSettings.data = response.data?.config ?? {}
			} catch (e) {
				this.adminSettings.error = normaliseError(e)
			} finally {
				this.adminSettings.loading = false
			}
		},

		/**
		 * Save admin configuration.
		 *
		 * The `admin_api_key` and `immich_admin_api_key` fields are only
		 * included in the request body when they carry a non-empty value.
		 */
		async saveAdminSettings(config) {
			this.adminSettings.loading = true
			this.adminSettings.error = null
			try {
				const payload = { ...config }
				for (const key of Object.keys(payload)) {
					if ((key === 'admin_api_key' || key === 'immich_admin_api_key') && !payload[key]) {
						delete payload[key]
					}
				}
				const response = await setAdminSettings(payload)
				this.adminSettings.data = response.data?.config ?? {}
				return response.data
			} catch (e) {
				this.adminSettings.error = normaliseError(e)
				throw e
			} finally {
				this.adminSettings.loading = false
			}
		},

		async testConnection(serverUrl, apiKey) {
			this.adminSettings.loading = true
			this.adminSettings.error = null
			try {
				const payload = {}
				if (serverUrl) payload.immich_base_url = serverUrl
				if (apiKey) payload.admin_api_key = apiKey
				const response = await validateAdminConnection(payload)
				return response.data
			} catch (e) {
				const data = e.response?.data
				const errMsg = normaliseError(e)
				this.adminSettings.error = errMsg
				throw Object.assign(new Error(errMsg), {
					responseData: data,
					localAccessBlocked: data?.error?.details?.local_access_blocked === true,
					validationDetails: data?.error?.details?.validation,
				})
			} finally {
				this.adminSettings.loading = false
			}
		},

		// ── Capabilities ────────────────────────────────────────────

		/**
		 * Load capabilities from the initial-state payload injected by
		 * the backend rather than a dedicated API endpoint.
		 *
		 * Callers should pass the value of
		 *   loadState('integration_immich', 'admin-config').capabilities
		 * or call with no argument to load from the page-embedded state.
		 */
		loadCapabilities(capabilitiesData = null) {
			this.capabilities.loading = true
			this.capabilities.error = null
			try {
				if (capabilitiesData) {
					this.capabilities.data = capabilitiesData
				} else {
					// Dynamic import to avoid bundling @nextcloud/initial-state
					// into every code path.  This module is compiled for the
					// admin bundle where the library is already available.
					const { loadState } = require('@nextcloud/initial-state')
					const state = loadState('integration_immich', 'admin-config')
					this.capabilities.data = state?.capabilities ?? {}
				}
			} catch (e) {
				this.capabilities.error = normaliseError(e)
				this.capabilities.data = {}
			} finally {
				this.capabilities.loading = false
			}
		},

		// ── Dry Run ─────────────────────────────────────────────────

		async dryRunUser(ncUid) {
			this.dryRun.loading = true
			this.dryRun.error = null
			try {
				const response = await dryRunOne(ncUid)
				this.dryRun.results = [response.data?.plan ?? response.data]
				return response.data
			} catch (e) {
				this.dryRun.error = normaliseError(e)
				throw e
			} finally {
				this.dryRun.loading = false
			}
		},

		async dryRunAllUsers() {
			this.dryRun.loading = true
			this.dryRun.error = null
			try {
				const response = await dryRunAll()
				const users = response.data?.plan?.users
				this.dryRun.results = users && typeof users === 'object' ? Object.values(users) : [response.data?.plan ?? response.data]
				return response.data
			} catch (e) {
				this.dryRun.error = normaliseError(e)
				throw e
			} finally {
				this.dryRun.loading = false
			}
		},

		// ── Reconcile ───────────────────────────────────────────────

		async reconcileUser(ncUid) {
			this.reconcile.loading = true
			this.reconcile.error = null
			try {
				const response = await reconcileOne(ncUid)
				this.reconcile.status = response.data
				return response.data
			} catch (e) {
				this.reconcile.error = normaliseError(e)
				throw e
			} finally {
				this.reconcile.loading = false
			}
		},

		async reconcileAllUsers() {
			this.reconcile.loading = true
			this.reconcile.error = null
			try {
				const response = await reconcileAll()
				this.reconcile.status = response.data
				return response.data
			} catch (e) {
				this.reconcile.error = normaliseError(e)
				throw e
			} finally {
				this.reconcile.loading = false
			}
		},

		// ── Quota Recompute ─────────────────────────────────────────

		async recomputeQuotaForUser(ncUid) {
			this.quotaRecompute.loading = true
			this.quotaRecompute.error = null
			try {
				const response = await recomputeQuotaOne(ncUid)
				this.quotaRecompute.status = response.data
				return response.data
			} catch (e) {
				this.quotaRecompute.error = normaliseError(e)
				throw e
			} finally {
				this.quotaRecompute.loading = false
			}
		},

		async recomputeQuotaForAll() {
			this.quotaRecompute.loading = true
			this.quotaRecompute.error = null
			try {
				const response = await recomputeQuotaAll()
				this.quotaRecompute.status = response.data
				return response.data
			} catch (e) {
				this.quotaRecompute.error = normaliseError(e)
				throw e
			} finally {
				this.quotaRecompute.loading = false
			}
		},

		// ── Sync States ─────────────────────────────────────────────

		async fetchSyncStates(params = {}) {
			this.syncStates.loading = true
			this.syncStates.error = null
			try {
				const response = await listSyncState(params)
				this.syncStates.list = response.data?.sync_state ?? []
				return response.data
			} catch (e) {
				this.syncStates.error = normaliseError(e)
				throw e
			} finally {
				this.syncStates.loading = false
			}
		},

		// ── Mount Verify ────────────────────────────────────────────

		async verifyUserMount(ncUid) {
			this.mountVerify.loading = true
			this.mountVerify.error = null
			try {
				const response = await verifyMountHealth(ncUid)
				this.mountVerify.health = response.data?.health ?? response.data
				return response.data
			} catch (e) {
				this.mountVerify.error = normaliseError(e)
				throw e
			} finally {
				this.mountVerify.loading = false
			}
		},
	},
})
