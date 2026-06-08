#!/usr/bin/env node

import { spawnSync } from 'node:child_process'
import { existsSync, readdirSync } from 'node:fs'
import path from 'node:path'

const roots = ['appinfo', 'lib', 'tests']

const phpFiles = roots.flatMap((root) => collectPhpFiles(root)).sort()

if (phpFiles.length === 0) {
    console.error('No PHP files found under appinfo/, lib/, or tests/.')
    process.exit(1)
}

const failures = []

for (const file of phpFiles) {
    const result = spawnSync('php', ['-l', file], {
        encoding: 'utf8',
        shell: false,
    })

    if (result.error) {
        console.error(`Unable to run php -l for ${formatPath(file)}: ${result.error.message}`)
        console.error('Install PHP 8.2+ locally or run this verification in CI.')
        process.exit(1)
    }

    if (result.stdout) {
        process.stdout.write(result.stdout)
    }

    if (result.stderr) {
        process.stderr.write(result.stderr)
    }

    if (result.status !== 0) {
        failures.push(file)
    }
}

if (failures.length > 0) {
    console.error(`PHP syntax check failed for ${failures.length} file(s):`)
    for (const file of failures) {
        console.error(`- ${formatPath(file)}`)
    }
    process.exit(1)
}

console.log(`PHP syntax check passed for ${phpFiles.length} file(s).`)

function collectPhpFiles(root) {
    if (!existsSync(root)) {
        return []
    }

    const entries = readdirSync(root, { withFileTypes: true })
    const files = []

    for (const entry of entries) {
        const entryPath = path.join(root, entry.name)

        if (entry.isSymbolicLink()) {
            continue
        }

        if (entry.isDirectory()) {
            files.push(...collectPhpFiles(entryPath))
            continue
        }

        if (entry.isFile() && entry.name.endsWith('.php')) {
            files.push(entryPath)
        }
    }

    return files
}

function formatPath(file) {
    return file.split(path.sep).join('/')
}
