const ADMIN_CONFIG_ERROR_CODE_ALIASES = Object.freeze({
	invalid_admin_config: 'admin_config_invalid',
})

const LEGACY_ADMIN_CONFIG_ERROR_MESSAGES = Object.freeze({
	'Invalid admin configuration.': 'admin_config_invalid',
	'Failed to save admin configuration.': 'admin_config_save_failed',
	'Connection validation failed.': 'connection_validation_failed',
})

const KNOWN_ADMIN_CONFIG_FIELD_ERROR_CODES = Object.freeze([
	'invalid_url',
	'invalid_enum',
	'invalid_group_list',
	'invalid_template',
	'unsupported_template_placeholder',
	'invalid_path_template',
	'missing_path_template',
	'delete_opt_in_confirmation_required',
	'invalid_quota_reserve',
	'invalid_boolean',
])

function clonePlainValue(value) {
	if (Array.isArray(value)) {
		return value.map(clonePlainValue)
	}

	if (value && typeof value === 'object') {
		return Object.fromEntries(Object.entries(value).map(([key, entry]) => [key, clonePlainValue(entry)]))
	}

	return value
}

function groupOptionToId(group) {
	if (group && typeof group === 'object') {
		return group.id ?? group.label ?? group.value ?? group.name ?? ''
	}

	return group
}

function normalizeGroupIds(groups) {
	if (!Array.isArray(groups)) {
		return []
	}

	return groups
		.map(groupOptionToId)
		.map(group => String(group ?? '').trim())
		.filter(group => group !== '')
}

function hasNonBlankValue(value) {
	return value !== null && value !== undefined && String(value).trim() !== ''
}

function buildAdminConfigPayload(formState, options = {}) {
	const payload = clonePlainValue(formState || {})

	const groups = Object.prototype.hasOwnProperty.call(options, 'selectedGroups')
		? options.selectedGroups
		: payload.user_scope_groups
	payload.user_scope_groups = normalizeGroupIds(groups)

	if (!hasNonBlankValue(payload.admin_api_key)) {
		delete payload.admin_api_key
	}

	if (payload.provisioning_enabled !== true) {
		payload.provisioning_enabled = false
		payload.mkdir_policy_enabled = false
		payload.external_storage_auto_create = false
	}

	if (payload.delete_disable_policy === 'delete_opt_in') {
		payload.delete_opt_in_confirmed = Object.prototype.hasOwnProperty.call(options, 'deleteOptInConfirmed')
			? options.deleteOptInConfirmed === true
			: payload.delete_opt_in_confirmed === true
	} else {
		delete payload.delete_opt_in_confirmed
	}

	return payload
}

function normalizeAdminConfigErrorCode(code, message = '') {
	const normalizedCode = String(code || '').trim()
	if (normalizedCode !== '') {
		return ADMIN_CONFIG_ERROR_CODE_ALIASES[normalizedCode] ?? normalizedCode
	}

	const normalizedMessage = String(message || '').trim()
	return LEGACY_ADMIN_CONFIG_ERROR_MESSAGES[normalizedMessage] ?? null
}

module.exports = {
	ADMIN_CONFIG_ERROR_CODE_ALIASES,
	KNOWN_ADMIN_CONFIG_FIELD_ERROR_CODES,
	LEGACY_ADMIN_CONFIG_ERROR_MESSAGES,
	buildAdminConfigPayload,
	normalizeAdminConfigErrorCode,
}
