import useItslStore from '../store/itslStore.js'

/**
 * Dynamically adds margin-bottom when content overflows for reading comfort.
 * This preserves the ability to scroll the last message up while avoiding
 * unnecessary scrollbars when content fits on screen.
 *
 * When threadSortNewestFirst is enabled, skip the margin entirely since the
 * newest message is already at the top.
 */
export function initScrollMarginHandler() {
	let isUpdating = false

	const update = () => {
		if (isUpdating) return
		isUpdating = true

		const container = document.querySelector('.splitpanes__pane-details')
		const content = document.querySelector('#mail-message')
		if (!container || !content) {
			isUpdating = false
			return
		}

		// Skip scroll margin when newest-first (newest message already at top)
		const itslStore = useItslStore()
		if (itslStore.threadSortNewestFirst) {
			content.classList.remove('needs-scroll-margin')
			isUpdating = false
			return
		}

		// Temporarily remove margin to measure true content height
		content.classList.remove('needs-scroll-margin')

		// Use requestAnimationFrame to ensure layout is recalculated
		requestAnimationFrame(() => {
			const needsScroll = container.scrollHeight > container.clientHeight
			content.classList.toggle('needs-scroll-margin', needsScroll)
			isUpdating = false
		})
	}

	// Run on DOM changes (message expand/collapse)
	const observer = new MutationObserver(update)

	const startObserving = () => {
		const target = document.querySelector('#mail-message')
		if (target) {
			// Only observe childList and subtree, not attributes (to avoid infinite loop)
			observer.observe(target, { childList: true, subtree: true })
			update()
		} else {
			// Retry if element not found yet
			setTimeout(startObserving, 100)
		}
	}

	// Run on resize
	window.addEventListener('resize', update)

	// Start observing when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', startObserving)
	} else {
		startObserving()
	}
}
