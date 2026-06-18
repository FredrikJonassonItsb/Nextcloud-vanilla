<!--
  - SPDX-FileCopyrightText: 2026 ITSL AB <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="assignees.length > 0" class="assignee-list">
		<div v-for="assignee in assignees"
			:key="assignee.id"
			class="assignee-list__item">
			<div class="assignee-list__avatar"
				:style="{ backgroundColor: assignee.color || 'var(--color-text-maxcontrast)' }">
				{{ getInitials(assignee.displayName) }}
			</div>
			<span class="assignee-list__name"
				:title="t('mail', 'Search for this tag')"
				@click.stop="$emit('search', assignee.imapLabel)">
				{{ assignee.displayName }}
			</span>
			<button v-if="editable"
				class="assignee-list__remove"
				:title="t('mail', 'Remove assignee')"
				@click.stop="$emit('remove', assignee.id)">
				<Close :size="14" />
			</button>
		</div>
	</div>
</template>

<script>
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'AssigneeList',
	components: {
		Close,
	},
	props: {
		assignees: {
			type: Array,
			default: () => [],
		},
		editable: {
			type: Boolean,
			default: true,
		},
	},
	emits: ['remove', 'search'],
	methods: {
		getInitials(name) {
			if (!name) return '?'
			const parts = name.trim().split(/\s+/)
			if (parts.length === 1) {
				return parts[0].substring(0, 2).toUpperCase()
			}
			return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
		},
	},
}
</script>

<style lang="scss" scoped>
.assignee-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.assignee-list__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 0 8px;
	margin: 0 -8px;
	border-radius: 6px;
	cursor: pointer;
	position: relative;
	min-height: 40px;

	&:hover {
		background: var(--color-background-hover);
	}

	&:hover .assignee-list__remove {
		opacity: 1;
		background-color: var(--color-background-hover);
		box-shadow: -3px 0 3px var(--color-background-hover);
	}
}

.assignee-list__avatar {
	width: 24px;
	height: 24px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	color: white;
	font-size: 11px;
	font-weight: 600;
	flex-shrink: 0;
}

.assignee-list__name {
	flex: 1;
	font-size: 13px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	cursor: pointer;
	position: relative;
	z-index: 0;

	&:hover {
		text-decoration: underline;
	}
}

.assignee-list__remove {
	width: 18px;
	height: 18px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	background: none;
	border: none;
	cursor: pointer;
	opacity: 0;
	transition: opacity 0.15s ease;
	flex-shrink: 0;
	position: absolute;
	right: 2px;
	top: 0;
	z-index: 1;

	&:hover,
	&:focus {
		background-color: transparent;
		color: var(--color-text-maxcontrast);
	}

	&:hover .close-icon,
	&:focus .close-icon {
		color: var(--color-text-maxcontrast);
	}
}
</style>
