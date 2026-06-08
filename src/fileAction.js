/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { registerFileAction } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'

const immichSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>'
const defaultActionCapabilities = {
	importToImmichEnabled: false,
	mirrorMountPaths: [],
}
let actionCapabilities = { ...defaultActionCapabilities }

async function loadActionCapabilities() {
	try {
		const response = await axios.get(generateUrl('/apps/deep_integration_immich/api/v1/config'))
		const capabilities = response.data?.actionCapabilities ?? {}
		actionCapabilities = {
			importToImmichEnabled: capabilities.importToImmichEnabled === true,
			mirrorMountPaths: Array.isArray(capabilities.mirrorMountPaths) ? capabilities.mirrorMountPaths : [],
		}
	} catch {
		actionCapabilities = { ...defaultActionCapabilities }
	}
}

async function uploadFile(node) {
	const url = generateUrl('/apps/deep_integration_immich/api/v1/upload')
	await axios.post(url, { fileId: node.fileid })
}

function nodePath(node) {
	if (typeof node.path === 'string' && node.path !== '') return node.path
	if (typeof node.dirname === 'string' && typeof node.basename === 'string') return `${node.dirname}/${node.basename}`
	return typeof node.basename === 'string' ? node.basename : ''
}

function normalizePath(path) {
	return String(path || '').replace(/\\/g, '/').replace(/\/+/g, '/').replace(/\/$/, '')
}

function pathVariants(path) {
	const normalized = normalizePath(path)
	const variants = [normalized]
	const filesIndex = normalized.indexOf('/files/')
	if (filesIndex !== -1) {
		variants.push(normalized.slice(filesIndex + '/files'.length))
	}
	if (normalized.startsWith('files/')) {
		variants.push(`/${normalized.slice('files/'.length)}`)
	}
	return [...new Set(variants.filter(Boolean))]
}

function isPathWithin(path, base) {
	const normalizedPath = normalizePath(path)
	const normalizedBase = normalizePath(base)
	return normalizedBase !== '' && (normalizedPath === normalizedBase || normalizedPath.startsWith(`${normalizedBase}/`))
}

function isInMirrorMount(node) {
	const path = nodePath(node)
	return path !== '' && pathVariants(path).some(pathVariant => (
		actionCapabilities.mirrorMountPaths.some(mirrorPath => isPathWithin(pathVariant, mirrorPath))
	))
}

function canImportNode(node) {
	const mime = node.mime || ''
	return actionCapabilities.importToImmichEnabled === true
		&& !isInMirrorMount(node)
		&& (mime.startsWith('image/') || mime.startsWith('video/'))
}

loadActionCapabilities().finally(() => {
	// NC33+ API: context object { nodes, view, folder, contents }
	registerFileAction({
		id: 'send-to-immich',
		displayName: () => t('deep_integration_immich', 'Import copy to Immich'),
		iconSvgInline: () => immichSvg,

		enabled({ nodes, view }) {
			const ignoredViews = ['trashbin', 'public']
			if (view?.id && ignoredViews.includes(view.id)) return false

			return nodes.length > 0 && nodes.every(canImportNode)
		},

		order: 90,

		async exec({ nodes }) {
			const node = nodes[0]
			if (!canImportNode(node)) {
				showError(t('deep_integration_immich', 'Import to Immich is disabled or unavailable for this file'))
				return false
			}
			try {
				await uploadFile(node)
				showSuccess(t('deep_integration_immich', '"{name}" imported to Immich', { name: node.basename }))
				return true
			} catch (e) {
				const errorMsg = e.response?.data?.error || e.message
				showError(t('deep_integration_immich', 'Error importing to Immich: {error}', { error: errorMsg }))
				return false
			}
		},

		async execBatch({ nodes }) {
			const importableNodes = nodes.filter(canImportNode)
			if (importableNodes.length !== nodes.length) {
				showError(t('deep_integration_immich', 'Some files cannot be imported to Immich from this location'))
			}
			const CONCURRENCY = 3
			const results = new Array(nodes.length).fill(false)

			let index = 0
			async function runWorker() {
				while (index < importableNodes.length) {
					const node = importableNodes[index++]
					const resultIndex = nodes.indexOf(node)
					try {
						await uploadFile(node)
						results[resultIndex] = true
					} catch {
						results[resultIndex] = false
					}
				}
			}

			const workers = Array.from({ length: Math.min(CONCURRENCY, importableNodes.length) }, runWorker)
			await Promise.all(workers)

			const successCount = results.filter(Boolean).length
			const failCount = results.length - successCount

			if (successCount > 0) {
				showSuccess(t('deep_integration_immich', '{count} file(s) imported to Immich', { count: successCount }))
			}
			if (failCount > 0) {
				showError(t('deep_integration_immich', '{count} file(s) could not be imported to Immich', { count: failCount }))
			}

			return results
		},
	})
})
