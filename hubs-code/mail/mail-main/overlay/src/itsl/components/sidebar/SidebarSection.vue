<!--
  - SPDX-FileCopyrightText: 2026 ITSL AB <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="sidebar-section">
		<div class="sidebar-section__header" :class="{ 'sidebar-section__header--reduced-gap': reducedHeaderGap }">
			<span class="sidebar-section__label">{{ title }}</span>
			<span v-if="count !== null" class="sidebar-section__count">{{ count }}</span>
			<NcPopover v-if="addButton"
				class="sidebar-section__add-popover"
				:shown.sync="popoverShown"
				:auto-hide="false"
				container="body"
				placement="left-start"
				:popper-hide-triggers="[]"
				:distance="6"
				:skidding="-12"
				popup-role="dialog">
				<template #trigger>
					<button class="sidebar-section__add"
						:title="t('mail', 'Add')">
						<Plus :size="20" />
					</button>
				</template>
				<slot name="popover" />
			</NcPopover>
			<slot name="header-badge" />
			<button v-if="action"
				class="sidebar-section__action"
				@click.stop="$emit('action')">
				{{ action }}
			</button>
		</div>
		<div class="sidebar-section__content">
			<slot />
		</div>
	</div>
</template>

<script>
import { NcPopover } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'SidebarSection',
	components: {
		NcPopover,
		Plus,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
		action: {
			type: String,
			default: null,
		},
		addButton: {
			type: Boolean,
			default: false,
		},
		count: {
			type: Number,
			default: null,
		},
		reducedHeaderGap: {
			type: Boolean,
			default: false,
		},
		popoverOpen: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['action', 'update:popoverOpen'],
	computed: {
		popoverShown: {
			get() {
				return this.popoverOpen
			},
			set(val) {
				this.$emit('update:popoverOpen', val)
			},
		},
	},
}
</script>

<style lang="scss" scoped>
.sidebar-section {
	padding: 24px 12px 12px 12px;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}

	&:has(.assignee-list),
	&:has(.status-row),
	&:has(.action-buttons) {
		padding-bottom: 4px !important;
	}

	&:has(.action-buttons) .sidebar-section__header {
		margin-bottom: 0 !important;
	}
}

.sidebar-section__header {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 8px;

	&--reduced-gap {
		margin-bottom: 0;
	}
}

.sidebar-section__label {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.sidebar-section__count {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 11px;
	font-weight: 700;
	padding: 0 5px;
	height: 18px;
	line-height: 18px;
	border-radius: 100px;
	min-width: 18px;
}

.sidebar-section__add-popover {
	margin-inline-start: auto !important;
}

.sidebar-section__add {
	width: 18px;
	height: 18px;
	min-height: unset;
	display: flex;
	align-items: center;
	justify-content: center;
	background-color: var(--color-background-hover) !important;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	margin: 0 !important;
	padding: 0;

	&:hover,
	&:focus,
	&:focus-visible,
	&:active {
		background-color: var(--color-background-hover) !important;
	}
}

.sidebar-section__action {
	font-size: 11px;
	color: var(--color-primary-element);
	background: none;
	border: none;
	cursor: pointer;
	padding: 2px 6px;
	border-radius: 4px;
	margin: 0;
	margin-inline-start: auto !important;
	min-height: unset;
	line-height: 1;

	&:hover {
		background: var(--color-background-hover);
	}
}

.sidebar-section__content {
	color: var(--color-main-text);
}
</style>
