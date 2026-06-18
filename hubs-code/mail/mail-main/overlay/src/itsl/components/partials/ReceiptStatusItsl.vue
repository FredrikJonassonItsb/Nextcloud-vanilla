<template>
	<NcPopover>
		<template #trigger>
			<NcButton aria-label="Show status" class="status-icon-button" unstyled>
				<component :is="statusIcon"
					:size="size"
					:class="['status-icon', statusClass]" />
			</NcButton>
		</template>

		<template #default>
			<div class="popover-content" tabindex="0">
				<div><strong>{{ t('mail', 'Status') }}:</strong> {{ displayStatus }}</div>
				<div><strong>{{ t('mail', 'Sent at') }}:</strong> {{ formattedSentAt }}</div>
			</div>
		</template>
	</NcPopover>
</template>

<script>
import { defineComponent, computed } from 'vue'

import { NcPopover, NcButton } from '@nextcloud/vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import TimerSand from 'vue-material-design-icons/TimerSand.vue'

import moment from '@nextcloud/moment'

export default defineComponent({
	name: 'ReceiptStatusItsl',
	components: {
		NcPopover,
		NcButton,
		CheckCircle,
		CloseCircle,
		TimerSand,
	},
	props: {
		status: {
			type: String,
			default: null,
		},
		sentAt: {
			type: String,
			required: true,
		},
		size: {
			type: Number,
			default: 20,
		},
		hasFailure: {
			type: Boolean,
			default: false,
		},
	},
	setup(props) {
		const formattedSentAt = computed(() =>
			moment(props.sentAt).format('YYYY-MM-DD HH:mm:ss'),
		)

		const messageOlderThanTenMinutes = computed(() => {
			return moment().diff(moment(props.sentAt), 'minutes') > 10
		})

		const effectiveStatus = computed(() => {
			if (props.hasFailure) return 'FAILURE'
			if (props.status === 'ACCEPTED') return 'ACCEPTED'
			if (props.status === 'REJECTED') return 'REJECTED'
			return messageOlderThanTenMinutes.value ? 'REJECTED' : 'PENDING'
		})

		const statusIcon = computed(() => {
			switch (effectiveStatus.value) {
			case 'ACCEPTED': return CheckCircle
			case 'REJECTED':
			case 'FAILURE': return CloseCircle
			default: return TimerSand
			}
		})

		const statusClass = computed(() => {
			switch (effectiveStatus.value) {
			case 'ACCEPTED': return 'success'
			case 'REJECTED': return 'failure'
			case 'FAILURE': return 'failure'
			default: return 'pending'
			}
		})

		const displayStatus = computed(() => {
			switch (effectiveStatus.value) {
			case 'ACCEPTED': return t('mail', 'Accepted')
			case 'REJECTED': return t('mail', 'Rejected')
			case 'FAILURE': return t('mail', 'Failure')
			default: return t('mail', 'Pending')
			}
		})

		return {
			formattedSentAt,
			statusIcon,
			statusClass,
			displayStatus,
		}
	},
})
</script>

<style scoped>
.status-icon-button {
	all: unset;
	display: inline-flex;
	align-items: center;
	cursor: pointer;
}
.status-icon-button:hover,
.status-icon-button:focus,
.status-icon-button:focus-visible {
	background-color: transparent !important;
	box-shadow: none;
	outline: none;
}
.popover-content {
	padding: 10px;
}
.status-icon.success {
	color: var(--color-success);
}
.status-icon.pending {
	color: var(--color-warning);
}
.status-icon.failure {
	color: var(--color-error);
}
</style>
