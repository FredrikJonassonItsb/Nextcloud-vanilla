<template>
	<div class="itsl-direction-icon" :title="directionTitle">
		<InboxArrowUp v-if="isOutgoing" :size="size" />
		<InboxArrowDown v-else :size="size" />
	</div>
</template>

<script>
import InboxArrowDown from 'vue-material-design-icons/InboxArrowDown.vue'
import InboxArrowUp from 'vue-material-design-icons/InboxArrowUp.vue'
import { MESSAGE_DIRECTION } from '../../store/constants.js'

export default {
	name: 'AvatarMessageDirectionItsl',
	components: {
		InboxArrowDown,
		InboxArrowUp,
	},
	props: {
		messageDirection: {
			type: String,
			default: null,
		},
		size: {
			type: Number,
			default: 24,
		},
	},
	computed: {
		isOutgoing() {
			return this.messageDirection === MESSAGE_DIRECTION.OUTGOING
		},
		directionTitle() {
			return this.isOutgoing ? t('mail', 'Outgoing message') : t('mail', 'Incoming message')
		},
	},
}
</script>

<style scoped>
.itsl-direction-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-primary-element);
}
</style>
