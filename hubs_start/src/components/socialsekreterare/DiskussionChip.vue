<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Surfar ärende-chatten PÅ ärendet, minimalt. Pull, ingen push utom omnämnande.
  - 💬 3 (olästa, neutral) eller starkare 💬 @1 (omnämnande till mig, accent).
  - Aldrig röda badge-moln — chatten kan aldrig bli en växande inkorgs-siffra.
-->
<template>
	<button
		v-if="visa"
		class="diskussion-chip hs-target"
		:class="{ 'diskussion-chip--mention': mention }"
		type="button"
		:aria-label="ariaLabel"
		@click.stop="$emit('open-diskussion')">
		<ForumIcon :size="14" />
		<span class="diskussion-chip__text">{{ mention ? ('@' + olasta) : olasta }}</span>
	</button>
</template>

<script>
import ForumIcon from 'vue-material-design-icons/Forum.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'DiskussionChip',
	components: { ForumIcon },
	props: {
		/** { olasta:Number, omnamnandeTillMig:Boolean } */
		diskussion: { type: Object, default: () => ({ olasta: 0, omnamnandeTillMig: false }) },
	},
	computed: {
		olasta() {
			return Number((this.diskussion && this.diskussion.olasta) || 0)
		},
		mention() {
			return !!(this.diskussion && this.diskussion.omnamnandeTillMig)
		},
		visa() {
			return this.olasta > 0 || this.mention
		},
		ariaLabel() {
			return this.mention
				? this.t('hubs_start', 'Du är omnämnd i ärendechatten — öppna diskussionen')
				: this.t('hubs_start', '{n} olästa i ärendechatten — öppna diskussionen', { n: this.olasta })
		},
	},
	methods: { t },
}
</script>

<style scoped lang="scss">
.diskussion-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 9px;
	border-radius: var(--border-radius-pill, 16px);
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	font-size: 0.78rem;
	font-weight: 600;
	cursor: pointer;
	white-space: nowrap;

	&:hover { background: var(--color-background-hover); }

	// Omnämnande till mig: accent-ton (men ikon + @-tecken bär samma signal, ej bara färg).
	&--mention {
		border-color: var(--color-primary-element);
		background: var(--color-primary-element-light, var(--color-background-hover));
		color: var(--color-primary-element);
	}
}
</style>
