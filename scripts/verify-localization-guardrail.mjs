#!/usr/bin/env node

import assert from 'node:assert/strict'
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const appRoot = path.resolve(__dirname, '..')
const workspaceRoot = path.resolve(appRoot, '..')
const auditPath = path.join(workspaceRoot, '.omo', 'evidence', 'task-4-i18n-audit.md')
const zhCnPath = path.join(appRoot, 'l10n', 'zh_CN.json')

const knownRawEnglishExamples = [
	'Invalid admin configuration.',
	'Failed to save admin configuration.',
	'Connection validation failed.',
	'Immich mapping status is temporarily unavailable.',
	'No active Nextcloud user context is available for Immich provisioning.',
	'No Immich mapping exists for this Nextcloud user yet. Ask an administrator to run Immich provisioning.',
	'Quota details are unavailable. Run quota sync from the admin settings for authoritative status.',
	'Quota has not been synced yet; values may be stale until the next quota sync job runs.',
	'Immich browsing is not configured for this account',
]

const guardrails = [
	{
		rawText: 'Invalid admin configuration.',
		code: 'admin_config_invalid',
		codeSources: ['lib/Controller/AdminSettingsController.php', 'src/services/adminSettingsPayload.js', 'src/store/adminProvisioning.js', 'src/AdminSettings.vue'],
		localizedTexts: ['Admin configuration is invalid. Please check the fields below.'],
	},
	{
		rawText: 'Failed to save admin configuration.',
		code: 'admin_config_save_failed',
		codeSources: ['lib/Controller/AdminSettingsController.php', 'src/services/adminSettingsPayload.js', 'src/store/adminProvisioning.js', 'src/AdminSettings.vue'],
		localizedTexts: ['Error saving settings'],
	},
	{
		rawText: 'Connection validation failed.',
		code: 'connection_validation_failed',
		codeSources: ['lib/Controller/AdminSettingsController.php', 'lib/Controller/ConfigController.php', 'src/services/adminSettingsPayload.js', 'src/store/adminProvisioning.js', 'src/AdminSettings.vue'],
		localizedTexts: ['Connection validation failed.'],
	},
	...statusGuardrails(),
]

assert.ok(existsSync(auditPath), 'Task 4 i18n audit evidence must exist')
assert.ok(existsSync(zhCnPath), 'zh_CN localization catalog must exist')

const audit = readFileSync(auditPath, 'utf8')
const auditRows = parseAuditRows(audit)
const translations = JSON.parse(readFileSync(zhCnPath, 'utf8')).translations ?? {}

assertAuditRowsAreClassified(auditRows)
assertKnownRawExamplesAreGuarded()
assertGuardrailSourcesAndCatalogs()
assertRawUiOccurrencesAreLocalizedOrLegacyMapped()

console.log(`Localization guardrail passed (${auditRows.length} audit rows, ${knownRawEnglishExamples.length} required raw examples, ${guardrails.length} code guardrails).`)

function statusGuardrails() {
	return [
		['Immich mapping status is temporarily unavailable.', 'mapping_status_unavailable', ['lib/Service/FrontendInitialStateService.php', 'src/components/Navigation.vue', 'src/AdminSettings.vue']],
		['No active Nextcloud user context is available for Immich provisioning.', 'no_active_nc_user', ['lib/Service/FrontendInitialStateService.php', 'src/App.vue']],
		['No Immich mapping exists for this Nextcloud user yet. Ask an administrator to run Immich provisioning.', 'no_immich_mapping', ['lib/Service/FrontendInitialStateService.php', 'src/App.vue']],
		['Immich mirror mount health is temporarily unavailable.', 'mount_health_unavailable', ['lib/Service/FrontendInitialStateService.php', 'src/components/Navigation.vue', 'src/AdminSettings.vue']],
		['Immich mirror mount health is {status}.', 'mount_health_status', ['lib/Service/FrontendInitialStateService.php', 'src/components/Navigation.vue', 'src/AdminSettings.vue']],
		['Quota sync needs an Immich user mapping before quota details are available.', 'quota_needs_mapping', ['lib/Service/FrontendInitialStateService.php', 'src/components/Navigation.vue', 'src/AdminSettings.vue']],
		['Quota details are unavailable. Run quota sync from the admin settings for authoritative status.', 'quota_unavailable', ['lib/Service/FrontendInitialStateService.php', 'src/components/Navigation.vue', 'src/AdminSettings.vue']],
		['Nextcloud quota is unlimited; Immich quota sync will leave the Immich quota unlimited.', 'quota_unlimited', ['lib/Service/FrontendInitialStateService.php', 'src/components/Navigation.vue', 'src/AdminSettings.vue']],
		['Quota has not been synced yet; values may be stale until the next quota sync job runs.', 'quota_stale', ['lib/Service/FrontendInitialStateService.php', 'src/components/Navigation.vue', 'src/AdminSettings.vue']],
		['Immich action capabilities are temporarily unavailable.', 'action_capabilities_unavailable', ['lib/Service/FrontendInitialStateService.php', 'src/components/Navigation.vue', 'src/AdminSettings.vue']],
		['Immich sync-state list is temporarily unavailable.', 'sync_state_list_unavailable', ['lib/Service/FrontendInitialStateService.php', 'src/AdminSettings.vue']],
		['Immich capability detection is temporarily unavailable.', 'capability_detection_unavailable', ['lib/Service/FrontendInitialStateService.php', 'src/AdminSettings.vue']],
		['Immich browsing is not configured for this account', 'browsing_setup_not_configured', ['lib/Controller/AssetsController.php', 'lib/Controller/AlbumsController.php', 'lib/Controller/PeopleController.php', 'src/App.vue'], 'Immich browsing is not configured for this account.'],
	].map(([rawText, code, codeSources, localizedText = rawText]) => ({
		rawText,
		code,
		codeSources,
		localizedTexts: [localizedText],
		auditRequired: true,
	}))
}

function assertAuditRowsAreClassified(rows) {
	assert.ok(rows.length >= 20, 'Task 4 i18n audit must contain classified findings')
	const allowedClassifications = new Set(['fixed', 'already-localized', 'non-user-visible'])
	const violations = []
	for (const row of rows) {
		if (!allowedClassifications.has(row.classification)) {
			violations.push(`line ${row.line}: unsupported classification ${row.classification || '(blank)'}`)
		}
		if (row.action.trim() === '') {
			violations.push(`line ${row.line}: missing action`)
		}
	}
	assert.deepEqual(violations, [], `Task 4 i18n audit has unclassified findings:\n${violations.join('\n')}`)

	for (const guardrail of guardrails.filter((entry) => entry.auditRequired)) {
		assert.ok(audit.includes(guardrail.rawText), `Task 4 audit must account for "${guardrail.rawText}"`)
	}
}

function assertKnownRawExamplesAreGuarded() {
	for (const rawExample of knownRawEnglishExamples) {
		assert.ok(
			guardrails.some((guardrail) => guardrail.rawText === rawExample),
			`Known raw-English example is missing from guardrail table: ${rawExample}`,
		)
	}
}

function assertGuardrailSourcesAndCatalogs() {
	for (const guardrail of guardrails) {
		for (const relativePath of guardrail.codeSources) {
			const content = readProjectFile(relativePath)
			assert.ok(content.includes(guardrail.code), `${relativePath} must include structured code ${guardrail.code}`)
		}

		for (const localizedText of guardrail.localizedTexts) {
			assert.ok(Object.hasOwn(translations, localizedText), `zh_CN catalog is missing ${localizedText}`)
			assert.notEqual(translations[localizedText], localizedText, `zh_CN catalog leaves raw English for ${localizedText}`)
			assert.ok(frontendHasLocalizedSource(localizedText), `Frontend source must render ${localizedText} through t()`)
		}
	}
}

function assertRawUiOccurrencesAreLocalizedOrLegacyMapped() {
	const violations = []
	for (const filePath of collectFiles(path.join(appRoot, 'src'), (entry) => /\.(?:vue|js)$/i.test(entry))) {
		const relativePath = path.relative(appRoot, filePath).replaceAll('\\', '/')
		const content = readFileSync(filePath, 'utf8')
		const lines = content.split(/\r?\n/)
		for (const rawExample of knownRawEnglishExamples) {
			for (let index = 0; index < lines.length; index += 1) {
				const line = lines[index]
				if (!line.includes(rawExample)) {
					continue
				}
				if (isLocalizedLine(line) || isLegacyMappingLine(relativePath, content, line)) {
					continue
				}
				violations.push(`${relativePath}:${index + 1} contains unlocalized raw English: ${rawExample}`)
			}
		}
	}

	assert.deepEqual(violations, [], `Raw UI string guardrail failed:\n${violations.join('\n')}`)
}

function frontendHasLocalizedSource(localizedText) {
	return collectFiles(path.join(appRoot, 'src'), (entry) => /\.(?:vue|js)$/i.test(entry))
		.some((filePath) => readFileSync(filePath, 'utf8')
			.split(/\r?\n/)
			.some((line) => line.includes(localizedText) && isLocalizedLine(line)))
}

function isLocalizedLine(line) {
	return line.includes("t('deep_integration_immich'") || line.includes('t("deep_integration_immich"')
}

function isLegacyMappingLine(relativePath, content, line) {
	if (!['src/services/adminSettingsPayload.js', 'src/store/adminProvisioning.js'].includes(relativePath)) {
		return false
	}
	return content.includes('LEGACY_ADMIN_CONFIG_ERROR_MESSAGES') && line.includes(':')
}

function parseAuditRows(markdown) {
	const rows = []
	const lines = markdown.split(/\r?\n/)
	for (let index = 0; index < lines.length; index += 1) {
		const line = lines[index]
		if (!line.startsWith('| `')) {
			continue
		}
		const cells = line.slice(1, -1).split('|').map((cell) => cell.trim())
		if (cells.length < 5) {
			continue
		}
		rows.push({
			line: index + 1,
			classification: cells[3],
			action: cells[4],
		})
	}
	return rows
}

function collectFiles(root, predicate) {
	const files = []
	for (const entry of readdirSync(root)) {
		const filePath = path.join(root, entry)
		const stat = statSync(filePath)
		if (stat.isDirectory()) {
			files.push(...collectFiles(filePath, predicate))
		} else if (stat.isFile() && predicate(filePath)) {
			files.push(filePath)
		}
	}
	return files.sort()
}

function readProjectFile(relativePath) {
	const filePath = path.join(appRoot, relativePath)
	assert.ok(existsSync(filePath), `Required source file is missing: ${relativePath}`)
	return readFileSync(filePath, 'utf8')
}
