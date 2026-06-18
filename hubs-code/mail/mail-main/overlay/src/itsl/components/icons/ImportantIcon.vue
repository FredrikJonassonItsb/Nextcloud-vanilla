<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span v-bind="$attrs"
		:aria-hidden="title ? null : 'true'"
		:aria-label="title"
		class="material-design-icon alert-icon"
		role="img"
		@click="$emit('click', $event)">
		<svg :fill="fillColor"
			class="material-design-icon__svg"
			:width="size"
			:height="size"
			viewBox="0 0 24 24">
			<path d="M13 14H11V9H13M13 18H11V16H13M1 21H23L12 2L1 21Z">
				<title v-if="title">{{ title }}</title>
			</path>
		</svg>
	</span>
</template>

<script>
export default {
	name: 'ImportantIcon',
	props: {
		title: {
			type: String,
		},
		fillColor: {
			type: String,
			default: 'currentColor',
		},
		size: {
			type: Number,
			default: 24,
		},
	},
	emits: ['click'],
}
</script>
