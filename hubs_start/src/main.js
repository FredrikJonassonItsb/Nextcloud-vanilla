/**
 * Hubs Start — SPA entry point.
 *
 * Mounts the App on #hubs-start (see templates/index.php). Vue 2.7 +
 * @nextcloud/vue 8. The app follows the Nextcloud design system automatically
 * via the component library + CSS variables; the brand rule applies to every
 * visible string (never "Nextcloud"/"Talk" in UI text).
 */

import Vue from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import App from './App.vue'
import store from './store/index.js'

// Global design tokens + the shared .hs-card shell (NOT scoped — every widget
// uses these classes/variables, so they must be bundled once at the top level).
import '../css/variables.scss'

Vue.prototype.t = t
Vue.prototype.n = n
Vue.prototype.$store = store

// eslint-disable-next-line camelcase
__webpack_nonce__ = btoa(OC.requestToken)
// eslint-disable-next-line camelcase
__webpack_public_path__ = OC.linkTo('hubs_start', 'js/')

export default new Vue({
	el: '#hubs-start',
	render: (h) => h(App),
})
