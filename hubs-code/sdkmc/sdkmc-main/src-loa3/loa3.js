/**
 * SPDX-FileCopyrightText: 2024 Pondersource <michiel@pondersource.com>
 * SPDX-FileCopyrightText: 2024 Micke Nordin <kano@sunet.se>
 * SPDX-FileCopyrightText: 2025 ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import Vue from 'vue'
import Loa3 from './views/Loa3.vue'
import Loa3Modal from './components/Loa3Modal.vue'
import { loadState } from '@nextcloud/initial-state'
import {
	DefaultType,
	FileAction,
	registerFileAction,
} from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'

if ((typeof window.OCA !== 'undefined') && typeof window.OCA.WorkflowEngine !== 'undefined') {
	window.OCA.WorkflowEngine.registerCheck({
		class: 'OCA\\SdkMc\\Check\\Loa3',
		name: t('sdkmc', 'Login type'),
		operators: [
			{ operator: 'is', name: t('sdkmc', 'LOA-2 or lower') },
			{ operator: '!is', name: t('sdkmc', 'LOA-3 or higher') },
		],
		component: Loa3,
	})
}

let settings = null
try {
	settings = loadState('sdkmc', 'loaSettings')
} catch (error) {
	console.error('[LOA3] Failed to load settings from initial state:', error)
}

function hasLoa3Tag(node, loa3Tag) {
	const systemTags = node.attributes?.['system-tags']

	if (!systemTags || typeof systemTags !== 'object') {
		return false
	}

	const raw = systemTags['system-tag']

	if (!raw) {
		return false
	}

	const tags = Array.isArray(raw) ? raw : [raw]

	const hasTag = tags.some(tag => {
		if (!tag) {
			return false
		}

		const expectedTag = loa3Tag.trim()
		let tagText = ''
		if (typeof tag === 'object') {
			tagText = tag.text.trim()
		} else if (typeof tag === 'string') {
			tagText = tag
		} else {
			return false
		}
		return tagText === expectedTag
	})

	return hasTag
}

function showLoa3Modal() {
	return new Promise((resolve) => {
		const modalContainer = document.createElement('div')
		document.body.appendChild(modalContainer)

		const ModalComponent = Vue.extend({
			components: {
				Loa3Modal,
			},
			methods: {
				t,
				handleModalClose(confirmed) {
					resolve(confirmed)
					cleanup()
				},
			},
			render(h) {
				return h(Loa3Modal, {
					on: {
						close: this.handleModalClose,
					},
				})
			},
		})

		const modalInstance = new ModalComponent().$mount(modalContainer)

		const cleanup = () => {
			modalInstance.$destroy()
			if (document.body.contains(modalContainer)) {
				document.body.removeChild(modalContainer)
			}
		}
	})
}

registerFileAction(new FileAction({
	id: 'loa3_inline',
	title: () => t('sdkmc', 'Requires LOA-3'),
	inline: () => true,
	displayName: () => '',
	iconSvgInline: () => {
		return `<svg width="16.898" height="20" version="1.1" viewBox="0 0 4.4709 5.2916" xmlns="http://www.w3.org/2000/svg">
          <g transform="translate(-47.692 -117.23)">
          <rect x="47.688" y="119.33" width="4.4709" height="3.1957" ry="1.0867" fill="#666" stroke-width=".030235"/>
          <ellipse cx="49.954" cy="119.11" rx="1.6826" ry="1.6549" fill="none" stroke="#666" stroke-width=".44167"/>
          <ellipse cx="49.921" cy="119.29" rx="1.0299" ry="1.027" fill="none" stroke-width=".030235"/>
          <text x="48.722206" y="121.35229" fill="#ffffff" fill-opacity=".0011489" font-size="1.2px" stroke="#ffffff" stroke-width=".034282" xml:space="preserve"><tspan x="48.722206" y="121.35229" fill="#ffffff" fill-opacity=".99993" font-family="'Liberation Mono'" font-size="1.2px" stroke="#ffffff" stroke-width=".034282">LOA3</tspan></text>
          <text transform="matrix(.030235 0 0 .030235 42.238 103.84)" fill="#000000" fill-opacity=".0011489" stroke="#000000" stroke-width="1.1339" style="shape-inside:url(#rect2692);white-space:pre" xml:space="preserve"/>
          </g>
          </svg>`
	},
	exec: async (node) => {

		if (settings.loginSecurity === 'LOA-3') {
			return
		}

		if (!hasLoa3Tag(node, settings.loa3Tag)) {
			return
		}

		const confirmed = await showLoa3Modal()
		if (confirmed) {
			const fileId = node.fileid || node.id
			const isFolder = node.type === 'folder' || node.type === 'dir'
			const nodePath = node.path || '/'

			let targetUrl
			let dirPath

			if (isFolder) {
				dirPath = nodePath
				targetUrl = `/apps/files/files/${fileId}?dir=${dirPath}`
			} else {
				const pathSegments = nodePath.split('/')
				pathSegments.pop()
				dirPath = pathSegments.join('/') || '/'

				targetUrl = `/apps/files/files/${fileId}?dir=${dirPath}&openfile=true`
			}

			const redirectUrl = `/apps/sdkmc/upgradeToLoa3?returnUrl=${encodeURIComponent(targetUrl)}`

			window.location.href = redirectUrl
		}
	},
	order: -10,
	default: DefaultType.DEFAULT,
	enabled: (nodes) => {
		if (!settings) {
			return false
		}

		if (settings.loginSecurity === 'LOA-3') {
			return false
		}

		const node = nodes[0]
		const result = hasLoa3Tag(node, settings.loa3Tag)
		return result
	},
}))
