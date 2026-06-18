<template>
	<NcAppContent>
		<div id="sdkmc">
			<SdkmcLogsPage />
		</div>
	</NcAppContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import SdkmcLogsPage from './pages/SdkmcLogsPage.vue'
export default {
	name: 'SdkmcLogsApp',
	components: {
		NcAppContent,
		SdkmcLogsPage,
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
