/**
 * Stub for @nextcloud/vue components (NcButton, NcModal, NcActions,
 * NcCheckboxRadioSwitch, NcLoadingIcon, …).
 *
 * The real package ships ESM that pulls in browser globals jsdom does not provide,
 * so importing it inside a .vue SFC under jest throws. Component tests use
 * shallowMount (which stubs child components by tag anyway), so each Nc* component
 * only needs to resolve to a valid, render-nothing component reference at import
 * time. jest.config.js maps every `@nextcloud/vue/dist/...` and bare `@nextcloud/vue`
 * import here.
 *
 * A Proxy makes BOTH import styles work:
 *   import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'  (default)
 *   import { NcButton } from '@nextcloud/vue'                          (named)
 */
const stub = { name: 'NcStub', render: () => null }

module.exports = new Proxy(stub, {
	get(target, prop) {
		if (prop === '__esModule') return false
		if (prop === 'default') return target
		return prop in target ? target[prop] : stub
	},
})
