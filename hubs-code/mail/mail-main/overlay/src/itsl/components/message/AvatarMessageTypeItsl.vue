<template>
	<div class="itsl-direction-icon" :title="iconTitle">
		<component :is="icon" :size="size" />
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { MESSAGE_TYPES } from '../../store/constants.js'
import { messageTypeToIcon } from '../../utils/messageTypeUtils.js'
import Message from 'vue-material-design-icons/Message.vue'

const props = defineProps({
	messageType: {
		type: String,
		default: '',
	},
	size: {
		type: Number,
		default: 40,
	},
})

const icon = computed(() => {
	const typesWithIcons = [
		MESSAGE_TYPES.FAX.id,
		MESSAGE_TYPES.SMS.id,
		MESSAGE_TYPES.INTERNAL.id,
		MESSAGE_TYPES.SDK.id,
		MESSAGE_TYPES.SECURE.id,
	]

	console.warn(messageTypeToIcon(props.messageType) || Message)
	if (typesWithIcons.includes(props.messageType)) {
		return messageTypeToIcon(props.messageType) || Message
	}

	return Message
})

const iconTitle = computed(() => {
	return MESSAGE_TYPES[props.messageType]
		? t('mail', MESSAGE_TYPES[props.messageType].labelKey)
		: t('mail', 'Message')
})
</script>

<style scoped>
.itsl-direction-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-primary-element);
}
</style>
