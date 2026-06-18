vi.mock('../../store/itslStore.js', () => ({
	default: vi.fn(() => ({
		threadSortNewestFirst: false,
	})),
}))

import { initScrollMarginHandler } from '../../utils/scrollMarginHandler.js'
import useItslStore from '../../store/itslStore.js'

describe('scrollMarginHandler', () => {
	let container, content, rafCallback

	beforeEach(() => {
		// Mock DOM elements
		container = { scrollHeight: 1000, clientHeight: 500 }
		content = {
			classList: {
				remove: vi.fn(),
				toggle: vi.fn(),
			},
		}

		vi.spyOn(document, 'querySelector').mockImplementation((selector) => {
			if (selector === '.splitpanes__pane-details') return container
			if (selector === '#mail-message') return content
			return null
		})

		// Mock MutationObserver
		global.MutationObserver = vi.fn().mockImplementation(() => ({
			observe: vi.fn(),
			disconnect: vi.fn(),
		}))

		// Capture requestAnimationFrame callback
		vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb) => { rafCallback = cb })
		vi.spyOn(window, 'addEventListener').mockImplementation(() => {})

		// Document is already loaded in test env
		Object.defineProperty(document, 'readyState', { value: 'complete', writable: true })
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('adds needs-scroll-margin class when scrollHeight > clientHeight', () => {
		useItslStore.mockReturnValue({ threadSortNewestFirst: false })
		initScrollMarginHandler()

		// Execute the rAF callback
		if (rafCallback) rafCallback()

		expect(content.classList.toggle).toHaveBeenCalledWith('needs-scroll-margin', true)
	})

	it('removes class when threadSortNewestFirst is true', () => {
		useItslStore.mockReturnValue({ threadSortNewestFirst: true })
		initScrollMarginHandler()

		expect(content.classList.remove).toHaveBeenCalledWith('needs-scroll-margin')
	})

	it('handles missing container/content elements without crashing', () => {
		document.querySelector.mockReturnValue(null)
		useItslStore.mockReturnValue({ threadSortNewestFirst: false })

		expect(() => initScrollMarginHandler()).not.toThrow()
	})

	it('sets up MutationObserver on #mail-message', () => {
		useItslStore.mockReturnValue({ threadSortNewestFirst: false })
		initScrollMarginHandler()

		expect(MutationObserver).toHaveBeenCalled()
		const observerInstance = MutationObserver.mock.results[0].value
		expect(observerInstance.observe).toHaveBeenCalledWith(
			content,
			{ childList: true, subtree: true },
		)
	})
})
