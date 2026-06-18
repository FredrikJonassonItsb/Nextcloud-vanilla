<template>
	<NcAppContent>
		<div id="sdkmc">
			<h2 class="sdkmc__title">
				{{ t('sdkmc', 'Mailbox Settings') }}
			</h2>
			<MailboxSettings />
		</div>
	</NcAppContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import MailboxSettings from './pages/MailboxSettings.vue'
export default {
	name: 'MailboxSettingsApp',
	components: {
		NcAppContent,
		MailboxSettings,
	},
}
</script>

<style scoped lang="scss">
#sdkmc {
	display: flex;
	justify-content: center;
	margin: 16px;
	flex-direction: column;
}
</style>
