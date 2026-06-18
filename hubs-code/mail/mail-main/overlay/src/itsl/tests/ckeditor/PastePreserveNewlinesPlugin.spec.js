vi.mock('@ckeditor/ckeditor5-core/src/plugin.js', () => ({
	default: class MockPlugin {

		constructor(editor) {
			this.editor = editor
		}

	},
}))

vi.mock('@ckeditor/ckeditor5-clipboard/src/utils/normalizeclipboarddata', () => ({ default: vi.fn((html) => html) }))

import PastePreserveNewlinesPlugin from '../../ckeditor/paste/PastePreserveNewlinesPlugin.js'

describe('PastePreserveNewlinesPlugin', () => {
	let plugin

	beforeEach(() => {
		const mockEditor = {
			editing: { view: { document: { on: vi.fn() } } },
			data: { htmlProcessor: { toView: vi.fn((html) => html) } },
		}
		plugin = new PastePreserveNewlinesPlugin(mockEditor)
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	describe('pluginName', () => {
		it('returns PastePreserveNewlines', () => {
			expect(PastePreserveNewlinesPlugin.pluginName).toBe('PastePreserveNewlines')
		})
	})

	describe('_plainTextToHtmlPreserveNewlines', () => {
		it('converts empty lines to <p><br></p>', () => {
			const result = plugin._plainTextToHtmlPreserveNewlines('line1\n\nline3')
			expect(result).toContain('<p>line1</p>')
			expect(result).toContain('<p><br></p>')
			expect(result).toContain('<p>line3</p>')
		})

		it('escapes HTML entities', () => {
			const result = plugin._plainTextToHtmlPreserveNewlines('<b>bold</b> & "quotes"')
			expect(result).toContain('&lt;b&gt;bold&lt;/b&gt;')
			expect(result).toContain('&amp;')
			expect(result).not.toContain('<b>')
		})

		it('converts tabs to nbsp sequences', () => {
			const result = plugin._plainTextToHtmlPreserveNewlines('\tindented')
			expect(result).toContain('&nbsp;&nbsp;&nbsp;&nbsp;indented')
		})

		it('handles \\r\\n line endings', () => {
			const result = plugin._plainTextToHtmlPreserveNewlines('line1\r\nline2')
			expect(result).toBe('<p>line1</p><p>line2</p>')
		})
	})

	describe('_convertPreBlockNewlines', () => {
		it('converts pre block lines to individual paragraphs', () => {
			const html = '<pre>line1\nline2\nline3</pre>'
			const result = plugin._convertPreBlockNewlines(html)
			expect(result).toContain('<p>line1</p>')
			expect(result).toContain('<p>line2</p>')
			expect(result).toContain('<p>line3</p>')
			expect(result).not.toContain('<pre>')
		})

		it('unwraps pre>code correctly', () => {
			const html = '<pre><code>function foo() {\n  return true;\n}</code></pre>'
			const result = plugin._convertPreBlockNewlines(html)
			expect(result).not.toContain('<pre>')
			expect(result).not.toContain('<code>')
			// Should have paragraph elements
			expect(result).toContain('<p>')
		})

		it('removes trailing empty line from pre blocks', () => {
			const html = '<pre>line1\nline2\n</pre>'
			const result = plugin._convertPreBlockNewlines(html)
			// Should have 2 paragraphs, not 3 (trailing empty removed)
			const pCount = (result.match(/<p>/g) || []).length
			expect(pCount).toBe(2)
		})

		it('passes through normal HTML without pre blocks', () => {
			const html = '<p>normal paragraph</p><p>another</p>'
			const result = plugin._convertPreBlockNewlines(html)
			expect(result).toContain('<p>normal paragraph</p>')
			expect(result).toContain('<p>another</p>')
		})
	})
})
