#!/usr/bin/env node

import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const appRoot = path.resolve(__dirname, '..')
const workspaceRoot = path.resolve(appRoot, '..')
const pattern = String.raw`(shell_exec|exec\(|proc_open|passthru|Symfony\\Component\\Process|occ files_|symlink\()`
const args = [pattern, 'lib', 'appinfo', 'src', 'tests']
const allowedFixturePath = path.join('tests', 'unit', 'SafetyGuardrailTest.php')

console.log(`Running forbidden-operation scan: rg "${pattern}" lib appinfo src tests`)

const result = spawnSync('rg', args, {
    cwd: appRoot,
    encoding: 'utf8',
    shell: false,
})

if (result.error) {
    console.error(`Unable to run rg: ${result.error.message}`)
    console.error('Install ripgrep locally or run this verification in CI.')
    process.exit(1)
}

if (result.status !== 0 && result.status !== 1) {
    if (result.stdout) {
        process.stdout.write(result.stdout)
    }
    if (result.stderr) {
        process.stderr.write(result.stderr)
    }
    process.exit(result.status ?? 1)
}

const lines = result.stdout.split(/\r?\n/).filter(Boolean)
const violations = lines.filter((line) => !isAllowedLine(line))

if (violations.length > 0) {
    console.error('Forbidden shell/process/occ/symlink pattern(s) found:')
    for (const violation of violations) {
        console.error(violation)
    }
    process.exit(1)
}

if (lines.length > 0) {
    console.log('Only documented guardrail fixtures or Nextcloud file-action exec callbacks matched the raw rg scan.')
} else {
    console.log('Forbidden-operation scan passed with no matches.')
}

assertStaticAdminGuardrails()
assertEvidenceDoesNotContainUnapprovedSecrets()

function isAllowedLine(line) {
    const normalized = line.replaceAll('\\', '/')
    return isAllowedFixtureLine(normalized) || isAllowedFileActionExecLine(normalized)
}

function isAllowedFixtureLine(normalizedLine) {
    return normalizedLine.startsWith(allowedFixturePath.replaceAll('\\', '/'))
}

function isAllowedFileActionExecLine(normalizedLine) {
    if (!normalizedLine.startsWith('src/fileAction')) {
        return false
    }

    return [
        '*   exec(node, view, dir)',
        '// NC32 calls: exec(node, view, dir)',
        'async exec(node, view, dir) {',
        'async exec({ nodes }) {',
    ].some((allowedSnippet) => normalizedLine.includes(allowedSnippet))
}

function assertStaticAdminGuardrails() {
    const packageJson = JSON.parse(readProjectFile('package.json'))
    assert.equal(packageJson.scripts['verify:admin-settings-payload'], 'node scripts/verify-admin-settings-payload.mjs')
    assert.equal(packageJson.scripts['verify:localization'], 'node scripts/verify-localization-guardrail.mjs')
    assert.ok(packageJson.scripts['verify:safety'].includes('verify:admin-settings-payload'), 'verify:safety must include admin payload guardrail')
    assert.ok(packageJson.scripts['verify:safety'].includes('verify:localization'), 'verify:safety must include localization guardrail')

    const payloadScript = readProjectFile('scripts/verify-admin-settings-payload.mjs')
    assertIncludes(payloadScript, 'disabledProvisioningForcesStorageBooleansFalse', 'payload evidence keeps disabled-provisioning boolean guardrail')
    assertIncludes(payloadScript, 'blankAdminApiKeyOmitted', 'payload evidence keeps blank admin key omission guardrail')
    assertIncludes(payloadScript, 'pathTemplatesPreserved', 'payload evidence keeps path template preservation guardrail')
    assertIncludes(payloadScript, 'nonBlankAdminApiKeyPreserved', 'payload evidence must not write fixture secret values')
    assertIncludes(payloadScript, "adminSettingsSource.includes('@update:checked')", 'payload guardrail rejects legacy checked update bindings')
    assertIncludes(payloadScript, 'assertRadioGroupBindings(adminSettingsSource', 'payload guardrail verifies radio control bindings')
    assertIncludes(payloadScript, "{ field: 'initial_password_policy', values: ['random', 'sso_oidc'] }", 'payload guardrail verifies initial password policy radio values')
    assertIncludes(payloadScript, "assertInitialPasswordPolicyValidation('[redacted]', 'invalid_enum')", 'payload guardrail rejects redacted enum markers')
    assertIncludes(payloadScript, "assertInitialPasswordPolicyValidation('passwordless', 'invalid_enum')", 'payload guardrail rejects unknown initial password policy values')
    assertIncludes(payloadScript, "normalizeAdminConfigErrorCode(null, 'Invalid admin configuration.')", 'payload guardrail maps legacy invalid message')
    assertIncludes(payloadScript, "normalizeAdminConfigErrorCode(null, 'Failed to save admin configuration.')", 'payload guardrail maps legacy save failure message')
    assertIncludes(payloadScript, "normalizeAdminConfigErrorCode(null, 'Connection validation failed.')", 'payload guardrail maps legacy connection message')

    const adminConfigSource = readProjectFile('lib/Service/AdminConfigService.php')
    assertIncludes(adminConfigSource, '$effectiveValues = $this->normaliseEffectivePathFeatureFlags($values);', 'admin validation must normalize effective path flags')
    assertIncludes(adminConfigSource, '$pathTemplatesRequired = $this->pathTemplatesRequired($effectiveValues);', 'path validation must use effective values')
    assert.ok(
        adminConfigSource.includes("preg_match('#(^|[")
            && adminConfigSource.includes(String.raw`\.\.`)
            && adminConfigSource.includes("/]|$)#', $template)"),
        'path validation must reject slash and backslash traversal segments',
    )
    assertIncludes(adminConfigSource, "public const VALIDATION_INVALID_PATH_TEMPLATE = 'invalid_path_template';", 'path validation code must remain stable')
    assertIncludes(adminConfigSource, "public const VALIDATION_MISSING_PATH_TEMPLATE = 'missing_path_template';", 'missing path validation code must remain stable')

    const adminConfigTest = readProjectFile('tests/unit/Service/AdminConfigServiceTest.php')
    assertIncludes(adminConfigTest, 'testDisabledProvisioningIgnoresStalePathFeatureFlagsForBlankTemplates', 'PHP tests preserve disabled provisioning path behavior')
    assertIncludes(adminConfigTest, 'testTemplateValidationRejectsTraversalAndUnsupportedPlaceholders', 'PHP tests preserve strict path traversal validation')
    assertIncludes(adminConfigTest, String.raw`C:\\immich\\..\\library\\{storageLabel}`, 'PHP tests cover Windows-style traversal')

    const adminSettingsController = readProjectFile('lib/Controller/AdminSettingsController.php')
    for (const code of ['admin_config_invalid', 'admin_config_save_failed', 'connection_validation_failed']) {
        assertIncludes(adminSettingsController, code, `AdminSettingsController must return structured code ${code}`)
    }
    assertIncludes(adminSettingsController, 'fieldDetails', 'AdminSettingsController must preserve structured field details')
    assertIncludes(adminSettingsController, 'private function redact(mixed $value): mixed', 'AdminSettingsController must recursively redact response payloads')
    assertIncludes(adminSettingsController, 'private function isSecretKey(string $key): bool', 'AdminSettingsController must redact secret-shaped keys')
    assertInitialPasswordPolicyIsSafeConfigKey(adminSettingsController, 'AdminSettingsController')

    const frontendInitialStateService = readProjectFile('lib/Service/FrontendInitialStateService.php')
    assertIncludes(frontendInitialStateService, 'private function redactString(string $value): string', 'FrontendInitialStateService must redact credential-shaped strings')
    assertInitialPasswordPolicyIsSafeConfigKey(frontendInitialStateService, 'FrontendInitialStateService')
    assertIncludes(frontendInitialStateService, String.raw`[?&](?:api[_-]?key|token|password|secret|authorization)=`, 'Initial-state redaction must cover query-string credentials')
    assertIncludes(frontendInitialStateService, String.raw`"(?:password|admin_api_key|apiKey|api_key|x-api-key|token|secret|authorization)"\s*:\s*"`, 'Initial-state redaction must cover JSON credential strings')
    assertIncludes(frontendInitialStateService, String.raw`\b(authorization)(\s*[=:]\s*)bearer\s+`, 'Initial-state redaction must cover authorization bearer assignments')
    assertIncludes(frontendInitialStateService, String.raw`\bbearer\s+`, 'Initial-state redaction must cover generic bearer strings')
    assertIncludes(frontendInitialStateService, String.raw`(api[_-]?key|token|password|secret|authorization)(\s*[=:]\s*)`, 'Initial-state redaction must cover generic key/value secrets')

    const adminSettingsControllerTest = readProjectFile('tests/unit/Controller/AdminSettingsControllerTest.php')
    assertIncludes(adminSettingsControllerTest, 'testSetConfigReturnsStructuredValidationError', 'Admin settings controller tests cover structured validation')
    assertIncludes(adminSettingsControllerTest, 'testSetConfigPersistenceFailureUsesSaveFailedCodeAndRedacts', 'Admin settings controller tests cover save failure redaction')
    assertIncludes(adminSettingsControllerTest, 'testValidateConnectionFailureIsStructuredAndRedacted', 'Admin settings controller tests cover connection failure redaction')
    assertIncludes(adminSettingsControllerTest, 'testGetConfigPreservesSsoOidcPasswordPolicyAndRedactsCredentialFields', 'Admin settings tests preserve initial_password_policy while redacting credentials')
    assertIncludes(adminSettingsControllerTest, "$this->assertSame('sso_oidc', $config['initial_password_policy']);", 'Admin settings tests assert sso_oidc is not redacted by key name')
    assertIncludes(adminSettingsControllerTest, "assertStringNotContainsString('test-api-key-redacted'", 'Admin settings tests assert API key fixture is absent from responses')
    assertIncludes(adminSettingsControllerTest, "assertStringNotContainsString('test-bearer-redacted'", 'Admin settings tests assert bearer fixture is absent from responses')

    const configControllerTest = readProjectFile('tests/unit/Controller/ConfigControllerTest.php')
    assertIncludes(configControllerTest, 'testSetConfigWithValidationFailed', 'Config controller tests cover structured personal validation failure')
    assertIncludes(configControllerTest, 'connection_validation_failed', 'Config controller tests assert stable connection error code')
    assertIncludes(configControllerTest, 'api_key=[redacted]', 'Config controller tests assert redacted detail strings')

    const adminStateTest = readProjectFile('tests/unit/Settings/AdminSettingsStateTest.php')
    assertIncludes(adminStateTest, 'testAdminInitialStatePreservesRandomPasswordPolicyAndRedactsConfigSecrets', 'Admin initial-state tests preserve initial_password_policy while redacting credentials')
    assertIncludes(adminStateTest, "$this->assertSame('random', $settings[AdminConfigService::KEY_INITIAL_PASSWORD_POLICY]);", 'Admin initial-state tests assert random policy is not redacted by key name')
    assertIncludes(adminStateTest, "assertStringNotContainsString('secret-admin-key'", 'Admin initial-state tests assert admin secret absence')
    assertIncludes(adminStateTest, "assertStringNotContainsString('test-bearer-redacted'", 'Admin initial-state tests assert bearer secret absence')
    assertIncludes(adminStateTest, "assertStringNotContainsString('json-admin-key-redacted'", 'Admin initial-state tests assert JSON admin key secret absence')
    assertIncludes(adminStateTest, 'Authorization: [redacted]', 'Admin initial-state tests assert authorization bearer redaction')
    assertIncludes(adminStateTest, 'Bearer [redacted]', 'Admin initial-state tests assert generic bearer redaction')
    const pageStateTest = readProjectFile('tests/unit/Controller/PageControllerStateTest.php')
    assertIncludes(pageStateTest, "assertStringNotContainsString('secret-admin-key'", 'User initial-state tests assert admin secret absence')

    console.log('Static admin validation, structured-code, payload, and redaction guardrails passed.')
}

function assertInitialPasswordPolicyIsSafeConfigKey(source, label) {
    const safeKeysIndex = source.indexOf('private const SAFE_CONFIG_KEYS = [')
    const policyKeyIndex = source.indexOf('AdminConfigService::KEY_INITIAL_PASSWORD_POLICY', safeKeysIndex)
    const secretKeysIndex = source.indexOf('private const SECRET_CONFIG_KEYS = [')
    assert.notEqual(safeKeysIndex, -1, `${label} must define SAFE_CONFIG_KEYS`)
    assert.notEqual(policyKeyIndex, -1, `${label} must classify initial_password_policy as a safe config key`)
    assert.notEqual(secretKeysIndex, -1, `${label} must define SECRET_CONFIG_KEYS`)
    assert.ok(safeKeysIndex < policyKeyIndex && policyKeyIndex < secretKeysIndex, `${label} must keep initial_password_policy in SAFE_CONFIG_KEYS before SECRET_CONFIG_KEYS`)

    const isSecretKeyIndex = source.indexOf('private function isSecretKey')
    const safeCheckIndex = source.indexOf('in_array($normalisedKey, self::SAFE_CONFIG_KEYS, true)', isSecretKeyIndex)
    const secretCheckIndex = source.indexOf('in_array($normalisedKey, self::SECRET_CONFIG_KEYS, true)', isSecretKeyIndex)
    assert.notEqual(isSecretKeyIndex, -1, `${label} must define isSecretKey`)
    assert.notEqual(safeCheckIndex, -1, `${label} must check SAFE_CONFIG_KEYS in isSecretKey`)
    assert.notEqual(secretCheckIndex, -1, `${label} must check SECRET_CONFIG_KEYS in isSecretKey`)
    assert.ok(safeCheckIndex < secretCheckIndex, `${label} must check SAFE_CONFIG_KEYS before SECRET_CONFIG_KEYS`)
}

function assertEvidenceDoesNotContainUnapprovedSecrets() {
    const evidenceRoot = path.join(workspaceRoot, '.omo', 'evidence')
    if (!existsSync(evidenceRoot)) {
        console.log('Secret evidence scan skipped: .omo/evidence is absent.')
        return
    }

    const evidenceFiles = collectFiles(evidenceRoot, (filePath) => /\.(?:txt|md|json)$/i.test(filePath))
    const violations = []
    let checkedCredentialAssignments = 0
    for (const filePath of evidenceFiles) {
        const relativeEvidencePath = path.relative(workspaceRoot, filePath).replaceAll('\\', '/')
        const content = readFileSync(filePath, 'utf8')
        const lines = content.split(/\r?\n/)
        for (let index = 0; index < lines.length; index += 1) {
            const line = lines[index]
            for (const match of line.matchAll(secretAssignmentPattern())) {
                checkedCredentialAssignments += 1
                const value = sanitizeMatchedSecretValue(match[1] ?? '')
                if (!isAllowedEvidenceSecret(value, line)) {
                    violations.push(`${relativeEvidencePath}:${index + 1} contains an unapproved credential-shaped assignment`)
                }
            }
            for (const match of line.matchAll(/\bBearer\s+(?!\[redacted\])([A-Za-z0-9._~+/=:-]{8,})/g)) {
                checkedCredentialAssignments += 1
                const value = sanitizeMatchedSecretValue(match[1] ?? '')
                if (!isAllowedEvidenceSecret(value, line)) {
                    violations.push(`${relativeEvidencePath}:${index + 1} contains an unapproved bearer-shaped value`)
                }
            }
        }
    }

    assert.deepEqual(violations, [], `Evidence secret scan found possible leaks:\n${violations.join('\n')}`)
    console.log(`Secret evidence scan passed (${evidenceFiles.length} files, ${checkedCredentialAssignments} credential-shaped assignments checked).`)
}

function secretAssignmentPattern() {
    return /["']?[\w.-]*(?:api[_-]?key|apikey|token|password|secret|authorization)[\w.-]*["']?\s*[:=]\s*["']?([^"'\s,;}]+)/ig
}

function sanitizeMatchedSecretValue(value) {
    return String(value)
        .replace(/^[\[({]+/, '')
        .replace(/[\])}.]+$/, '')
        .replace(/^`+|`+$/g, '')
}

function isAllowedEvidenceSecret(value, line) {
    const normalizedValue = value.trim()
    if (normalizedValue === '' || normalizedValue.includes('[redacted]')) {
        return true
    }
    if (['true', 'false', 'null', '0', '1', 'admin_api_key', 'api_key'].includes(normalizedValue.toLowerCase())) {
        return true
    }
    if (normalizedValue.startsWith('$') || normalizedValue.includes('$raw')) {
        return true
    }
    if (line.includes('Command:') || line.includes('synthetic') || line.includes('fixture') || line.includes('redaction-test strings')) {
        return true
    }
    if ((line.includes('tests\\unit') || line.includes('tests/unit')) && line.includes('rawPassword')) {
        return true
    }
    return allowedFixtureSecretValues().has(normalizedValue)
}

function allowedFixtureSecretValues() {
    return new Set([
        'test-api-key-redacted',
        'test-bearer-redacted',
        'raw-token-redacted',
        'stored-api-key-redacted',
        'legacy-api-key-redacted',
        'candidate-api-key-redacted',
        'secret-admin-key',
        'super-secret',
        'admin-secret',
        'candidate-secret',
        'secret-delete-response',
        'my-secret-key',
        'personal-key',
        'admin-key',
        'test-key',
    ])
}

function collectFiles(root, predicate) {
    const files = []
    if (!existsSync(root)) {
        return files
    }
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
    assert.ok(existsSync(filePath), `Required guardrail file is missing: ${relativePath}`)
    return readFileSync(filePath, 'utf8')
}

function assertIncludes(content, snippet, message) {
    assert.ok(content.includes(snippet), message)
}
