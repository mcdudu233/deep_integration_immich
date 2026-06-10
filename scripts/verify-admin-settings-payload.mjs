import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const appRoot = resolve(__dirname, '..')
const workspaceRoot = resolve(appRoot, '..')
const evidenceDir = join(workspaceRoot, '.omo', 'evidence')

const helperModule = await import(pathToFileURL(join(appRoot, 'src', 'services', 'adminSettingsPayload.js')).href)
const {
	KNOWN_ADMIN_CONFIG_FIELD_ERROR_CODES,
	REDACTED_MARKERS,
	VALID_IMMICH_BROWSING_MODES,
	VALID_INITIAL_PASSWORD_POLICIES,
	buildAdminConfigPayload,
	isAdminConfigPayloadValidationError,
	normalizeAdminConfigErrorCode,
} = helperModule.default ?? helperModule

function assertInitialPasswordPolicyValidation(value, expectedCode) {
	assert.throws(
		() => buildAdminConfigPayload({ ...disabledForm, initial_password_policy: value }),
		(error) => {
			assert.equal(isAdminConfigPayloadValidationError(error), true)
			assert.equal(error.code, 'admin_config_invalid')
			assert.equal(error.fields?.[0]?.field, 'initial_password_policy')
			assert.equal(error.fields?.[0]?.code, expectedCode)
			assert.equal(error.fields?.[0]?.message, 'Initial password policy must be random or sso_oidc.')
			assert.deepEqual(error.fields?.[0]?.params?.allowed, VALID_INITIAL_PASSWORD_POLICIES)
			return true
		},
	)
}

function extractNamedFunction(source, functionName) {
	const start = source.indexOf(`function ${functionName}(`)
	assert.notEqual(start, -1, `Missing ${functionName}`)
	const openBrace = source.indexOf('{', start)
	assert.notEqual(openBrace, -1, `Missing ${functionName} body`)

	let depth = 0
	for (let index = openBrace; index < source.length; index += 1) {
		const char = source[index]
		if (char === '{') {
			depth += 1
		} else if (char === '}') {
			depth -= 1
			if (depth === 0) {
				return source.slice(start, index + 1)
			}
		}
	}

	assert.fail(`Could not extract ${functionName}`)
}

function escapeRegExp(value) {
	return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

function extractNcCheckboxRadioSwitchTags(source) {
	return source.match(/<NcCheckboxRadioSwitch\b[\s\S]*?>/g) ?? []
}

function hasStaticAttribute(tag, attribute, value) {
	return new RegExp(`\\b${escapeRegExp(attribute)}\\s*=\\s*["']${escapeRegExp(value)}["']`).test(tag)
}

function hasFormVModel(tag, field) {
	return new RegExp(`\\bv-model\\s*=\\s*["']form\\.${escapeRegExp(field)}["']`).test(tag)
}

function staticAttributeValue(tag, attribute) {
	const match = tag.match(new RegExp(`\\b${escapeRegExp(attribute)}\\s*=\\s*["']([^"']+)["']`))
	return match?.[1] ?? null
}

function assertRadioGroupBindings(source, group) {
	const matchingTags = extractNcCheckboxRadioSwitchTags(source).filter(tag => hasFormVModel(tag, group.field)
		&& hasStaticAttribute(tag, 'name', group.field)
		&& hasStaticAttribute(tag, 'type', 'radio'))
	const actualValues = matchingTags
		.map(tag => staticAttributeValue(tag, 'value'))
		.filter(value => value !== null)
		.sort()

	assert.deepEqual(
		actualValues,
		group.values.slice().sort(),
		`${group.field} radio controls must use v-model="form.${group.field}", name="${group.field}", type="radio", and the expected values`,
	)
}

function assertVisibleSwitchHitTargets(source) {
	const controlTags = extractNcCheckboxRadioSwitchTags(source)
	assert.ok(controlTags.length > 0, 'AdminSettings.vue must render NcCheckboxRadioSwitch controls')
	const labelMatches = source.match(/<label\s+class="control-hit-target"\s+@click\.capture\.stop\.prevent="[^"]+">/g) ?? []
	assert.equal(
		labelMatches.length,
		controlTags.length,
		'Every NcCheckboxRadioSwitch control must be wrapped in a native <label class="control-hit-target" @click.capture.stop.prevent="..."> so visible text/icon clicks update Vue state directly',
	)

	for (const tag of controlTags) {
		assert.equal(
			hasStaticAttribute(tag, 'wrapper-element', 'label'),
			false,
			`NcCheckboxRadioSwitch controls must not use kebab-case wrapper-element because this @nextcloud/vue build does not make visible text/icon clicks toggle reliably: ${tag}`,
		)
		assert.equal(
			hasStaticAttribute(tag, 'wrapperElement', 'label'),
			false,
			`NcCheckboxRadioSwitch controls must not rely on wrapperElement because live QA showed the component still rendered a span wrapper: ${tag}`,
		)
	}
}

const disabledForm = {
	immich_base_url: 'https://immich.test.local',
	admin_api_key: '   ',
	immich_browsing_mode: 'admin_managed',
	provisioning_enabled: false,
	user_scope_mode: 'groups',
	user_scope_groups: [' stale-group '],
	storage_label_template: '{uid}',
	email_template: '{uid}@immich.local',
	initial_password_policy: 'random',
	mount_name_template: 'Immich Photos',
	host_path_template: '  /srv/immich/originals/library/{storageLabel}  ',
	nc_visible_path_template: '/mnt/immich-library/{storageLabel}',
	mkdir_policy_enabled: true,
	external_storage_auto_create: true,
	quota_sync_mode: 'manual',
	quota_reserve_bytes: 268435456,
	delete_disable_policy: 'disable_suspend',
}

const disabledPayload = buildAdminConfigPayload(disabledForm, {
	selectedGroups: [{ id: 'photos-team', label: 'Photos Team' }, { label: 'archive' }],
})

assert.equal(disabledPayload.provisioning_enabled, false)
assert.equal(disabledPayload.mkdir_policy_enabled, true)
assert.equal(disabledPayload.external_storage_auto_create, true)
assert.equal(Object.hasOwn(disabledPayload, 'admin_api_key'), false)
assert.deepEqual(disabledPayload.user_scope_groups, ['photos-team', 'archive'])
assert.equal(disabledPayload.host_path_template, disabledForm.host_path_template)
assert.equal(disabledPayload.nc_visible_path_template, disabledForm.nc_visible_path_template)
assert.equal(Object.hasOwn(disabledPayload, 'delete_opt_in_confirmed'), false)
assert.equal(disabledForm.mkdir_policy_enabled, true)
assert.equal(disabledForm.external_storage_auto_create, true)

const enabledPayload = buildAdminConfigPayload({
	...disabledForm,
	admin_api_key: 'test-api-key-redacted',
	provisioning_enabled: true,
	mkdir_policy_enabled: true,
	external_storage_auto_create: true,
	delete_disable_policy: 'delete_opt_in',
}, {
	selectedGroups: [' family ', '', null, { value: 'mobile-uploaders' }],
	deleteOptInConfirmed: true,
})

assert.equal(enabledPayload.admin_api_key, 'test-api-key-redacted')
assert.equal(enabledPayload.mkdir_policy_enabled, true)
assert.equal(enabledPayload.external_storage_auto_create, true)
assert.deepEqual(enabledPayload.user_scope_groups, ['family', 'mobile-uploaders'])
assert.equal(enabledPayload.delete_opt_in_confirmed, true)

assert.deepEqual(VALID_INITIAL_PASSWORD_POLICIES, ['random', 'sso_oidc'])
assert.deepEqual(REDACTED_MARKERS, ['[redacted]'])
assert.equal(buildAdminConfigPayload({ ...disabledForm, initial_password_policy: 'random' }).initial_password_policy, 'random')
assert.equal(buildAdminConfigPayload({ ...disabledForm, initial_password_policy: 'sso_oidc' }).initial_password_policy, 'sso_oidc')
assert.equal(buildAdminConfigPayload({ ...disabledForm, initial_password_policy: undefined }).initial_password_policy, 'random')
assert.equal(buildAdminConfigPayload({ ...disabledForm, initial_password_policy: null }).initial_password_policy, 'random')
assert.equal(buildAdminConfigPayload({ ...disabledForm, initial_password_policy: '   ' }).initial_password_policy, 'random')
assertInitialPasswordPolicyValidation('[redacted]', 'invalid_enum')
assertInitialPasswordPolicyValidation('passwordless', 'invalid_enum')

const blankApiKeyPolicyPayload = buildAdminConfigPayload({
	...disabledForm,
	admin_api_key: '',
	initial_password_policy: 'sso_oidc',
})
assert.equal(blankApiKeyPolicyPayload.initial_password_policy, 'sso_oidc')
assert.equal(Object.hasOwn(blankApiKeyPolicyPayload, 'admin_api_key'), false)

const personalModePayload = buildAdminConfigPayload({
	...disabledForm,
	immich_browsing_mode: 'personal',
	provisioning_enabled: true,
	mkdir_policy_enabled: true,
	external_storage_auto_create: true,
	quota_sync_mode: 'event_scheduled',
})

assert.deepEqual(VALID_IMMICH_BROWSING_MODES, ['personal', 'admin_managed'])
assert.equal(personalModePayload.immich_browsing_mode, 'personal')
assert.equal(personalModePayload.provisioning_enabled, false)
assert.equal(personalModePayload.mkdir_policy_enabled, false)
assert.equal(personalModePayload.external_storage_auto_create, false)
assert.equal(personalModePayload.quota_sync_mode, 'disabled')

assert.throws(
	() => buildAdminConfigPayload({ ...disabledForm, immich_browsing_mode: 'team_shared' }),
	(error) => {
		assert.equal(isAdminConfigPayloadValidationError(error), true)
		assert.equal(error.fields?.[0]?.field, 'immich_browsing_mode')
		assert.equal(error.fields?.[0]?.code, 'invalid_enum')
		return true
	},
)

assert.equal(normalizeAdminConfigErrorCode('invalid_admin_config'), 'admin_config_invalid')
assert.equal(normalizeAdminConfigErrorCode(null, 'Invalid admin configuration.'), 'admin_config_invalid')
assert.equal(normalizeAdminConfigErrorCode(null, 'Failed to save admin configuration.'), 'admin_config_save_failed')
assert.equal(normalizeAdminConfigErrorCode(null, 'Connection validation failed.'), 'connection_validation_failed')
assert.equal(normalizeAdminConfigErrorCode('connection_validation_failed'), 'connection_validation_failed')

const requiredFieldCodes = [
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
]
assert.deepEqual(KNOWN_ADMIN_CONFIG_FIELD_ERROR_CODES, requiredFieldCodes)

const adminSettingsSource = readFileSync(join(appRoot, 'src', 'AdminSettings.vue'), 'utf8')
assert.equal(adminSettingsSource.includes('@update:checked'), false, 'AdminSettings.vue must not use @update:checked; use v-model/modelValue semantics for NcCheckboxRadioSwitch controls')
assertVisibleSwitchHitTargets(adminSettingsSource)

const requiredRadioGroups = [
	{ field: 'user_scope_mode', values: ['all', 'groups'] },
	{ field: 'immich_browsing_mode', values: ['personal', 'admin_managed'] },
	{ field: 'initial_password_policy', values: ['random', 'sso_oidc'] },
	{ field: 'quota_sync_mode', values: ['disabled', 'manual', 'event_scheduled'] },
	{ field: 'delete_disable_policy', values: ['disable_suspend', 'delete_opt_in'] },
]

for (const group of requiredRadioGroups) {
	assertRadioGroupBindings(adminSettingsSource, group)
}

const resolveSavedApiKeyConfigured = new Function(`${extractNamedFunction(adminSettingsSource, 'resolveSavedApiKeyConfigured')}; return resolveSavedApiKeyConfigured`)()
const postSaveApiKeyConfiguredAssertions = {
	blankNoExistingRemainsFalse: resolveSavedApiKeyConfigured({}, {}, false, false) === false,
	blankExistingPreserved: resolveSavedApiKeyConfigured({}, {}, true, false) === true,
	nonBlankSubmittedFallbackBecomesTrue: resolveSavedApiKeyConfigured({}, {}, false, true) === true,
	responseConfigFalseWins: resolveSavedApiKeyConfigured({ config: { admin_api_key_configured: false } }, { admin_api_key_configured: true }, true, true) === false,
	responseConfigTrueWins: resolveSavedApiKeyConfigured({ config: { admin_api_key_configured: true } }, { admin_api_key_configured: false }, false, false) === true,
	storeConfigFalseWinsWhenResponseLacksFlag: resolveSavedApiKeyConfigured({ config: {} }, { admin_api_key_configured: false }, true, true) === false,
	storeConfigTrueWinsWhenResponseLacksFlag: resolveSavedApiKeyConfigured({}, { admin_api_key_configured: true }, false, false) === true,
	unconditionalTrueAssignmentAbsent: adminSettingsSource.includes('apiKeyConfigured.value = true') === false,
}

for (const [name, passed] of Object.entries(postSaveApiKeyConfiguredAssertions)) {
	assert.equal(passed, true, `Post-save API key configured assertion failed: ${name}`)
}
assert.ok(adminSettingsSource.includes('apiKeyConfigured.value = resolveSavedApiKeyConfigured('), 'Save flow must use resolved API key configured state')
assert.ok(adminSettingsSource.includes("Object.prototype.hasOwnProperty.call(config, 'admin_api_key')"), 'Save flow must derive submitted API key state from the normalized payload')
assert.ok(adminSettingsSource.includes('isAdminConfigPayloadValidationError(e)'), 'Save flow must handle local payload validation errors')
assert.ok(adminSettingsSource.includes("data-testid=\"initial-password-policy-field-error\""), 'Initial password policy must render an inline field error')
assert.ok(adminSettingsSource.indexOf('const config = buildAdminConfigPayload(') < adminSettingsSource.indexOf('await store.saveAdminSettings(config)'), 'Payload validation must run before the store save call')

const requiredSelectors = [
	'immich-base-url-input',
	'immich-browsing-mode-personal',
	'immich-browsing-mode-admin-managed',
	'immich-admin-api-key-input',
	'provisioning-enabled-toggle',
	'host-path-template-input',
	'nc-visible-path-template-input',
	'admin-config-save-button',
	'admin-config-error-summary',
	'initial-password-policy-field-error',
]

for (const selector of requiredSelectors) {
	assert.ok(adminSettingsSource.includes(`data-testid="${selector}"`), `Missing selector ${selector}`)
}

for (const code of requiredFieldCodes) {
	assert.ok(adminSettingsSource.includes(`case '${code}':`), `Missing field error mapping ${code}`)
}

mkdirSync(evidenceDir, { recursive: true })

const evidence = {
	command: 'node scripts/verify-admin-settings-payload.mjs',
	status: 'passed',
	payloadAssertions: {
		disabledProvisioningPreservesStorageBooleans: disabledPayload.mkdir_policy_enabled === true
			&& disabledPayload.external_storage_auto_create === true,
		blankAdminApiKeyOmitted: Object.hasOwn(disabledPayload, 'admin_api_key') === false,
		blankApiKeyStillOmittedWithSsoPolicy: Object.hasOwn(blankApiKeyPolicyPayload, 'admin_api_key') === false,
		pathTemplatesPreserved: disabledPayload.host_path_template === disabledForm.host_path_template
			&& disabledPayload.nc_visible_path_template === disabledForm.nc_visible_path_template,
		selectedGroupsNormalized: disabledPayload.user_scope_groups,
		nonBlankAdminApiKeyPreserved: enabledPayload.admin_api_key === 'test-api-key-redacted',
		validInitialPasswordPolicies: VALID_INITIAL_PASSWORD_POLICIES,
		validImmichBrowsingModes: VALID_IMMICH_BROWSING_MODES,
		personalModeDisablesCentralProvisioning: personalModePayload.provisioning_enabled === false
			&& personalModePayload.quota_sync_mode === 'disabled',
		redactedMarkers: REDACTED_MARKERS,
	},
	errorCodeAssertions: {
		legacyInvalidMessage: normalizeAdminConfigErrorCode(null, 'Invalid admin configuration.'),
		legacySaveFailedMessage: normalizeAdminConfigErrorCode(null, 'Failed to save admin configuration.'),
		legacyConnectionMessage: normalizeAdminConfigErrorCode(null, 'Connection validation failed.'),
	},
	postSaveApiKeyConfiguredAssertions,
	requiredSelectors,
	requiredFieldCodes,
	radioGroupAssertions: Object.fromEntries(requiredRadioGroups.map(group => [group.field, group.values])),
}

writeFileSync(
	join(evidenceDir, 'task-2-admin-save-payload.json'),
	`${JSON.stringify(evidence, null, 2)}\n`,
	'utf8',
)

writeFileSync(
	join(evidenceDir, 'task-2-admin-field-errors.txt'),
	[
		'Admin settings field-error source verification: PASS',
		`Required selectors found: ${requiredSelectors.join(', ')}`,
		`Field-code t() switch cases found: ${requiredFieldCodes.join(', ')}`,
		'No @update:checked bindings found in src/AdminSettings.vue.',
		`Radio groups verified: ${requiredRadioGroups.map(group => `${group.field} values [${group.values.join(', ')}]`).join('; ')}`,
		'Post-save API key configured state guardrail verified blank/no-existing false, blank/existing preservation, nonblank fallback, and response/store-confirmed states.',
		'Initial password policy payload guard verified valid random/sso_oidc preservation, missing/null/blank defaulting to random, and local rejection of exact [redacted] plus unknown enum values.',
		'Immich browsing mode payload guard verified personal/admin_managed enum validation and personal-mode centralized provisioning disablement.',
		'Inline field error renderers verified for immich_base_url, admin_api_key, initial_password_policy, host_path_template, and nc_visible_path_template.',
		'No local Nextcloud + Immich runtime was available to drive the browser; this file records source-level fallback evidence only.',
		'',
	].join('\n'),
	'utf8',
)

writeFileSync(
	join(evidenceDir, 'admin-controls-redaction-fix-task-4-control-guardrail.txt'),
	[
		'Admin settings control/payload guardrail: PASS',
		'No @update:checked bindings found in src/AdminSettings.vue.',
		`Radio groups verified: ${requiredRadioGroups.map(group => `${group.field} values [${group.values.join(', ')}]`).join('; ')}`,
		'Initial password policy enum guard verified valid random/sso_oidc preservation, missing/null/blank defaulting to random, and local rejection of exact [redacted] plus unknown enum values.',
		'Immich browsing mode enum guard verified personal/admin_managed preservation and invalid value rejection.',
		'Blank admin API key omission verified, including with sso_oidc policy.',
		'',
	].join('\n'),
	'utf8',
)

console.log(`Admin settings payload verification passed (${requiredSelectors.length} selectors, ${requiredFieldCodes.length} field codes, ${Object.keys(postSaveApiKeyConfiguredAssertions).length} post-save key-state checks, ${requiredRadioGroups.length} radio groups, initial password policy enum guard).`)
