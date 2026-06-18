/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { Plugin } from 'ckeditor5'

/**
 * Plugin that preserves empty paragraphs when pasting text, and converts
 * <pre> block newlines to paragraphs (CKEditor has no CodeBlock plugin).
 *
 * Without this plugin, CKEditor strips empty <p> elements during upcast,
 * collapsing blank lines. The <br> filler keeps them alive.
 */
export default class PastePreserveNewlinesPlugin extends Plugin {

	static get pluginName() {
		return 'PastePreserveNewlines'
	}

	init() {
		const editor = this.editor
		const view = editor.editing.view
		const viewDocument = view.document

		viewDocument.on('clipboardInput', (evt, data) => {
			const htmlContent = data.dataTransfer.getData('text/html')

			if (htmlContent) {
				if (/<pre[\s>]/i.test(htmlContent)) {
					// Strip BOM and normalize whitespace (replaces the old
					// normalizeClipboardData import removed in CKEditor 45)
					const normalized = htmlContent
						.replace(/^\uFEFF/, '')
						.replace(/\r\n/g, '\n')
						.replace(/\r/g, '\n')
					const fixedHtml = this._convertPreBlockNewlines(normalized)
					data.content = editor.data.htmlProcessor.toView(fixedHtml)
				}
				return
			}

			const plainText = data.dataTransfer.getData('text/plain')
			if (!plainText) {
				return
			}

			const html = this._plainTextToHtmlPreserveNewlines(plainText)
			data.content = editor.data.htmlProcessor.toView(html)
		}, { priority: 'high' })
	}

	/**
	 * Each line becomes a <p>. Empty lines get a <br> filler to prevent
	 * CKEditor from stripping them during upcast.
	 * @param text
	 */
	_plainTextToHtmlPreserveNewlines(text) {
		return text
			.replace(/\r\n/g, '\n')
			.replace(/\r/g, '\n')
			.split('\n')
			.map(line => {
				if (line === '') {
					return '<p><br></p>'
				}
				line = line
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/\t/g, '&nbsp;&nbsp;&nbsp;&nbsp;')
				return `<p>${line}</p>`
			})
			.join('')
	}

	/**
	 * Replaces <pre> blocks with paragraphs (one per line) so newlines
	 * are preserved. Handles <pre><code>...</code></pre> too.
	 * @param html
	 */
	_convertPreBlockNewlines(html) {
		const parser = new DOMParser()
		const doc = parser.parseFromString(html, 'text/html')

		doc.querySelectorAll('pre').forEach(pre => {
			const text = pre.textContent
			const lines = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n')

			// Remove trailing empty line that <pre> blocks often have
			if (lines.length > 0 && lines[lines.length - 1] === '') {
				lines.pop()
			}

			const fragment = doc.createDocumentFragment()
			for (const line of lines) {
				const p = doc.createElement('p')
				if (line === '') {
					p.appendChild(doc.createElement('br'))
				} else {
					const escaped = line.replace(/\t/g, '\u00a0\u00a0\u00a0\u00a0')
					p.textContent = escaped
				}
				fragment.appendChild(p)
			}

			pre.replaceWith(fragment)
		})

		return doc.body.innerHTML
	}

}
