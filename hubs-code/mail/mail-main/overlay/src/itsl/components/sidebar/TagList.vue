<!--
  - SPDX-FileCopyrightText: 2026 ITSL AB <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="tags.length > 0" class="tag-list">
		<div v-for="tag in tags"
			:key="tag.id"
			class="tag-list__item">
			<div class="tag-list__bg"
				:style="{ backgroundColor: tag.color }" />
			<span class="tag-list__label"
				:style="{ color: tag.color }"
				:title="t('mail', 'Search for this tag')"
				@click.stop="$emit('search', tag.imapLabel)">
				{{ tag.displayName }}
			</span>
			<button v-if="editable"
				class="tag-list__remove"
				:style="{ color: tag.color }"
				:title="t('mail', 'Remove tag')"
				@click.stop="$emit('remove', tag.id)">
				<Close :size="14" />
			</button>
		</div>
	</div>
</template>

<script>
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'TagList',
	components: {
		Close,
	},
	props: {
		tags: {
			type: Array,
			default: () => [],
		},
		editable: {
			type: Boolean,
			default: true,
		},
	},
	emits: ['remove', 'search'],
}
</script>

<style lang="scss" scoped>
.tag-list {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.tag-list__item {
	display: inline-flex;
	align-items: center;
	position: relative;
	border-radius: 100px;
	overflow: hidden;
	height: 22px;
}

.tag-list__bg {
	position: absolute;
	width: 100%;
	height: 100%;
	top: 0;
	left: 0;
	opacity: 0.15;
}

.tag-list__label {
	z-index: 2;
	font-size: 12px;
	font-weight: 700;
	padding: 2px 2px 2px 6px;
	cursor: pointer;

	&:hover {
		text-decoration: underline;
	}
}

.tag-list__remove {
	z-index: 2;
	width: 18px;
	height: 18px;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	padding: 0 4px 0 2px;
	border-radius: 50%;
	background: none;
	border: none;
	opacity: 0.7;

	&:hover {
		opacity: 1;
		background: var(--color-background-hover);
	}
}
</style>
