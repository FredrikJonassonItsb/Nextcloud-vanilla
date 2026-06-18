import Vue from 'vue'
import ServerSettingsApp from './ServerSettingsApp.vue'
Vue.mixin({ methods: { t, n } })

const View = Vue.extend(ServerSettingsApp)
new View().$mount('#sdkmc-vue-server-settings-root')
