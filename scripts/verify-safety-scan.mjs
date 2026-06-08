#!/usr/bin/env node

import { spawnSync } from 'node:child_process'
import path from 'node:path'

const pattern = String.raw`(shell_exec|exec\(|proc_open|passthru|Symfony\\Component\\Process|occ files_|symlink\()`
const args = [pattern, 'lib', 'appinfo', 'src', 'tests']
const allowedFixturePath = path.join('tests', 'unit', 'SafetyGuardrailTest.php')

console.log(`Running forbidden-operation scan: rg "${pattern}" lib appinfo src tests`)

const result = spawnSync('rg', args, {
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
