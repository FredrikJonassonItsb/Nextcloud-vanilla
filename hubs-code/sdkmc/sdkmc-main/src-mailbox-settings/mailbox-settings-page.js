import Vue from 'vue'
import MailboxSettingsApp from './MailboxSettingsApp.vue'
import './css/shared.css'

Vue.mixin({ methods: { t, n } })

const View = Vue.extend(MailboxSettingsApp)
new View().$mount('#sdkmc-vue-mailbox-settings-root')
