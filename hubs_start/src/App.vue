<template>
	<NcContent app-name="hubs_start">
		<NcAppContent>
			<StartView />
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcContent from '@nextcloud/vue/dist/Components/NcContent.js'
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import StartView from './views/Start.vue'

export default {
	name: 'App',
	components: { NcContent, NcAppContent, StartView },
}
</script>
