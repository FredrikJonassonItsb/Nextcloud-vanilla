<template>
	<NcAppContent>
		<div id="sdkmc">
			<h2 class="sdkmc__title">
				{{ t('sdkmc', 'Server Settings') }}
			</h2>
			<ServerSettings />
		</div>
	</NcAppContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import ServerSettings from './pages/ServerSettings.vue'
export default {
	name: 'ServerSettingsApp',
	components: {
		NcAppContent,
		ServerSettings,
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
