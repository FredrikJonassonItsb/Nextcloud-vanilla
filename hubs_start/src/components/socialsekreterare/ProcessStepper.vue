<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Processindikatorn — gör "var är ärendet?" begripligt på en blick och lär ut
  - ärendelivscykeln utan manual. Fem steg, ikon + text (aldrig bara färg),
  - aria-current på aktuellt steg. Okvitterad plikt spärrar framåt-övergång.
-->
<template>
	<ol class="stepper" :aria-label="t('hubs_start', 'Ärendets processteg')">
		<li
			v-for="(s, i) in steps"
			:key="s.id"
			class="stepper__node"
			:class="{
				'stepper__node--done': i < currentIndex,
				'stepper__node--current': i === currentIndex,
				'stepper__node--future': i > currentIndex,
			}"
			:aria-current="i === currentIndex ? 'step' : null">
			<button
				class="stepper__btn hs-target"
				type="button"
				:disabled="i > currentIndex"
				:title="tooltip(s, i)"
				@click="onClick(s, i)">
				<span class="stepper__marker">
					<CheckIcon v-if="i < currentIndex" :size="14" />
					<span v-else class="stepper__dot" />
				</span>
				<span class="stepper__label">{{ s.label }}</span>
			</button>
			<span v-if="i < steps.length - 1" class="stepper__line" aria-hidden="true" />
		</li>
	</ol>
</template>

<script>
import CheckIcon from 'vue-material-design-icons/Check.vue'
import { translate as t } from '@nextcloud/l10n'
import { PROCESS_STEG } from '../../services/arendeFlow.js'

export default {
	name: 'ProcessStepper',
	components: { CheckIcon },
	props: {
		steg: { type: String, default: 'forhandsbedomning' },
		substeg: { type: String, default: null },
		plikt: { type: Object, default: null },
	},
	data() {
		return { steps: PROCESS_STEG }
	},
	computed: {
		currentIndex() {
			const idx = this.steps.findIndex((s) => s.id === this.steg)
			return idx === -1 ? 0 : idx
		},
		blocked() {
			return !!(this.plikt && !this.plikt.kvitterad)
		},
	},
	methods: {
		t,
		tooltip(s, i) {
			if (this.blocked && i >= this.currentIndex) {
				return this.t('hubs_start', 'Kvittera skyddsbedömningen först')
			}
			if (i < this.currentIndex) {
				return this.t('hubs_start', 'Avklarat: {steg}', { steg: s.label })
			}
			if (i === this.currentIndex) {
				return this.t('hubs_start', 'Aktuellt steg: {steg}', { steg: s.label })
			}
			return this.t('hubs_start', 'Kommande: {steg}', { steg: s.label })
		},
		onClick(s, i) {
			if (i > this.currentIndex || this.blocked) {
				return
			}
			this.$emit('goto-flik', { steg: s.id, historik: i < this.currentIndex })
		},
	},
}
</script>

<style scoped lang="scss">
.stepper {
	display: flex;
	align-items: center;
	list-style: none;
	margin: 0;
	padding: 0;
	flex-wrap: wrap;
	gap: 2px;

	&__node {
		display: flex;
		align-items: center;
		min-width: 0;
	}

	&__btn {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		background: transparent;
		border: none;
		padding: 4px 6px;
		cursor: pointer;
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);

		&:disabled { cursor: default; }
		&:hover:not(:disabled) { color: var(--color-main-text); }
	}

	&__marker {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 18px;
		height: 18px;
		border-radius: 50%;
		border: 2px solid var(--color-border);
		flex: 0 0 auto;
	}

	&__dot { width: 7px; height: 7px; border-radius: 50%; background: var(--color-border); }

	&__line {
		width: 16px;
		height: 2px;
		background: var(--color-border);
		flex: 0 0 auto;
	}

	&__node--done &__marker { border-color: var(--hs-status-success); background: var(--hs-status-success); color: #fff; }
	&__node--done &__label { color: var(--color-main-text); }

	&__node--current &__marker { border-color: var(--color-primary-element); }
	&__node--current &__dot { background: var(--color-primary-element); }
	&__node--current &__label { color: var(--color-main-text); font-weight: 700; }

	@media (max-width: 600px) {
		&__label { display: none; }
		&__node--current &__label { display: inline; }
	}
}
</style>
