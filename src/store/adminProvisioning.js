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

const ADMIN_CONFIG_ERROR_CODE_ALIASES = Object.freeze({
	invalid_admin_config: 'admin_config_invalid',
})

const LEGACY_ADMIN_CONFIG_ERROR_MESSAGES = Object.freeze({
	'Invalid admin configuration.': 'admin_config_invalid',
	'Failed to save admin configuration.': 'admin_config_save_failed',
	'Connection validation failed.': 'connection_validation_failed',
})

function stringifyFieldMessage(value) {
	if (value === null || value === undefined) {
		return ''
	}
	if (typeof value === 'string') {
		return value
	}
	if (typeof value === 'number' || typeof value === 'boolean') {
		return String(value)
	}
	if (typeof value === 'object') {
		return value.message || value.detail || value.error || JSON.stringify(value)
	}
	return String(value)
}

function normaliseParams(params) {
	if (!params || typeof params !== 'object' || Array.isArray(params)) {
		return {}
	}

	return { ...params }
}

function normaliseFieldDetail(entry, index) {
	if (entry && typeof entry === 'object') {
		return {
			field: String(entry.field || entry.name || entry.key || index + 1),
			code: entry.code ? String(entry.code) : null,
			message: stringifyFieldMessage(entry.message || entry.detail || entry.error),
			params: normaliseParams(entry.params),
		}
	}

	return {
		field: String(index + 1),
		code: null,
		message: stringifyFieldMessage(entry),
		params: {},
	}
}

function normaliseLegacyFieldValue(field, value) {
	if (value && typeof value === 'object' && !Array.isArray(value)) {
		return {
			field,
			code: value.code ? String(value.code) : null,
			message: stringifyFieldMessage(value.message || value.detail || value.error || value),
			params: normaliseParams(value.params),
		}
	}

	return {
		field,
		code: null,
		message: stringifyFieldMessage(value),
		params: {},
	}
}

function normaliseLegacyFields(fields) {
	if (!fields || typeof fields !== 'object') {
		return []
	}
	if (Array.isArray(fields)) {
		return fields.map(normaliseFieldDetail).filter(entry => entry.message || entry.field)
	}
	return Object.entries(fields).flatMap(([field, value]) => {
		if (Array.isArray(value)) {
			return value.map(message => normaliseLegacyFieldValue(field, message))
		}
		return [normaliseLegacyFieldValue(field, value)]
	}).filter(entry => entry.message || entry.field)
}

function normaliseFieldErrors(details) {
	if (!details || typeof details !== 'object') {
		return []
	}

	const fieldDetails = Array.isArray(details.fieldDetails)
		? details.fieldDetails.map(normaliseFieldDetail).filter(entry => entry.message || entry.field)
		: []

	return fieldDetails.length > 0 ? fieldDetails : normaliseLegacyFields(details.fields)
}

function normaliseAdminErrorCode(code, message = '') {
	const normalizedCode = String(code || '').trim()
	if (normalizedCode !== '') {
		return ADMIN_CONFIG_ERROR_CODE_ALIASES[normalizedCode] ?? normalizedCode
	}

	const normalizedMessage = String(message || '').trim()
	return LEGACY_ADMIN_CONFIG_ERROR_MESSAGES[normalizedMessage] ?? null
}

/**
 * Normalise a backend or network error into a user-visible string plus
 * machine-readable metadata for validation UIs.
 *
 * Backend controllers return:
 *   { success: false, error: { code, message, details: { detail, fields, ... } } }
 */
function normaliseErrorDetails(e) {
	const data = e.response?.data
	const backendError = data?.error
	const details = backendError?.details && typeof backendError.details === 'object'
		? backendError.details
		: {}
	const backendMessage = backendError?.message ? String(backendError.message) : ''
	let message = ''
	if (details.detail) {
		message = String(details.detail)
	} else if (backendMessage) {
		message = backendMessage
	} else if (backendError) {
		message = String(backendError)
	} else if (data?.detail) {
		message = String(data.detail)
	} else {
		message = e.message || String(e)
	}

	return {
		message,
		code: normaliseAdminErrorCode(backendError?.code, backendMessage || message),
		details,
		fields: normaliseFieldErrors(details),
	}
}

function normaliseError(e) {
	return normaliseErrorDetails(e).message
}

export const useAdminProvisioningStore = defineStore('adminProvisioning', {
	state: () => ({
		adminSettings: { loading: false, error: null, errorDetails: null, data: {} },
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
			this.adminSettings.errorDetails = null
			try {
				const response = await getAdminSettings()
				this.adminSettings.data = response.data?.config ?? {}
				this.adminSettings.errorDetails = null
			} catch (e) {
				const errorDetails = normaliseErrorDetails(e)
				this.adminSettings.error = errorDetails.message
				this.adminSettings.errorDetails = errorDetails
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
			this.adminSettings.errorDetails = null
			try {
				const payload = { ...config }
				for (const key of Object.keys(payload)) {
					if ((key === 'admin_api_key' || key === 'immich_admin_api_key') && String(payload[key] ?? '').trim() === '') {
						delete payload[key]
					}
				}
				const response = await setAdminSettings(payload)
				this.adminSettings.data = response.data?.config ?? {}
				this.adminSettings.errorDetails = null
				return response.data
			} catch (e) {
				const errorDetails = normaliseErrorDetails(e)
				this.adminSettings.error = errorDetails.message
				this.adminSettings.errorDetails = errorDetails
				throw e
			} finally {
				this.adminSettings.loading = false
			}
		},

		async testConnection(serverUrl, apiKey) {
			this.adminSettings.loading = true
			this.adminSettings.error = null
			this.adminSettings.errorDetails = null
			try {
				const payload = {}
				if (serverUrl) payload.immich_base_url = serverUrl
				if (String(apiKey ?? '').trim() !== '') payload.admin_api_key = apiKey
				const response = await validateAdminConnection(payload)
				return response.data
			} catch (e) {
				const data = e.response?.data
				const errorDetails = normaliseErrorDetails(e)
				const errMsg = errorDetails.message
				this.adminSettings.error = errMsg
				this.adminSettings.errorDetails = errorDetails
				throw Object.assign(new Error(errMsg), {
					code: errorDetails.code,
					details: errorDetails.details,
					fields: errorDetails.fields,
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
		 *   loadState('deep_integration_immich', 'admin-config').capabilities
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
					const state = loadState('deep_integration_immich', 'admin-config')
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
