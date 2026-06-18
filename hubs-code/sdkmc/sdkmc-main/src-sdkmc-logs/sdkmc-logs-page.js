import Vue from 'vue' // Import Vue from core package
import { NextcloudVuePlugin } from '@nextcloud/vue'
import SdkmcLogsApp from './SdkmcLogsApp.vue'
import './css/shared.css'

Vue.use(NextcloudVuePlugin)

Vue.mixin({ methods: { t, n } })

const View = Vue.extend(SdkmcLogsApp)
new View().$mount('#sdkmc-vue-sdkmc-logs-root')
