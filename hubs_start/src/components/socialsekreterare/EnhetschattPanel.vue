<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Enhetschatt-panelen — en LUGN sidoyta för team-/enhetschatt (mottagningsgruppen,
  - utredningsgrupp, enheten). ALDRIG ett kort i ärendeströmmen, aldrig en rå inkorgs-
  - siffra: bara diskreta olästa (neutral) + omnämnanden (accent, @-ikon). Pull, ej push.
-->
<template>
	<div v-if="open" class="enhetschatt">
		<!-- Backdrop: stänger vid klick, döljs för skärmläsare -->
		<div
			class="enhetschatt__backdrop"
			aria-hidden="true"
			@click="$emit('close')" />

		<aside
			ref="panel"
			class="enhetschatt__panel"
			role="dialog"
			aria-modal="true"
			:aria-label="t('hubs_start', 'Enhetschatt')"
			tabindex="-1"
			@keydown.esc.stop="$emit('close')"
			@keydown.tab="fokusfalla">
			<header class="enhetschatt__head">
				<h2 class="enhetschatt__title">
					<AccountGroupIcon :size="20" />
					{{ t('hubs_start', 'Enhetschatt') }}
				</h2>
				<NcButton
					type="tertiary"
					:aria-label="t('hubs_start', 'Stäng enhetschatt')"
					@click="$emit('close')">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
				</NcButton>
			</header>

			<!-- Team-listan — diskret, aldrig en växande inkorgs-siffra -->
			<ul class="enhetschatt__list" :aria-label="t('hubs_start', 'Team')">
				<li
					v-for="grupp in team"
					:key="grupp.id"
					class="enhetschatt__item">
					<button
						class="enhetschatt__team hs-target"
						type="button"
						:disabled="!grupp.token"
						:title="!grupp.token ? t('hubs_start', 'Chattrum saknas för teamet ännu') : null"
						:aria-label="teamAriaLabel(grupp)"
						@click="grupp.token && $emit('oppna-tradar', grupp)">
						<span class="enhetschatt__team-label">{{ grupp.label }}</span>

						<span class="enhetschatt__indikatorer">
							<!-- Omnämnande till mig: accent, @-ikon bär signalen (ej bara färg) -->
							<span
								v-if="omnamnanden(grupp) > 0"
								class="enhetschatt__pill enhetschatt__pill--mention">
								<AtIcon :size="13" />
								{{ omnamnanden(grupp) }}
							</span>
							<!-- Olästa: neutral, ikon + tal -->
							<span
								v-if="olasta(grupp) > 0"
								class="enhetschatt__pill enhetschatt__pill--olasta">
								<ForumOutlineIcon :size="13" />
								{{ olasta(grupp) }}
							</span>
						</span>
					</button>
				</li>
			</ul>

			<!-- Inga team alls: copy matchar villkoret (inte "inga olästa"). -->
			<NcEmptyContent
				v-if="!team.length"
				class="enhetschatt__empty"
				:name="t('hubs_start', 'Inga team att visa')"
				:description="t('hubs_start', 'När du kopplas till ett team i enheten dyker det upp här.')">
				<template #icon>
					<ForumOutlineIcon :size="40" />
				</template>
			</NcEmptyContent>

			<!-- Team finns men allt är läst: den lugna texten hör hemma här. -->
			<p
				v-else-if="allaLugna"
				class="enhetschatt__lugn">
				{{ t('hubs_start', 'Inga olästa och inga omnämnanden just nu. Det här är en lugn sidoyta — inget måste läsas direkt.') }}
			</p>

			<footer class="enhetschatt__foot">
				<NcButton
					type="primary"
					wide
					@click="$emit('starta-fordelningsmote')">
					<template #icon>
						<AccountMultiplePlusIcon :size="20" />
					</template>
					{{ t('hubs_start', 'Starta fördelningsmöte') }}
				</NcButton>
			</footer>
		</aside>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultiplePlusIcon from 'vue-material-design-icons/AccountMultiplePlus.vue'
import AtIcon from 'vue-material-design-icons/At.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import ForumOutlineIcon from 'vue-material-design-icons/ForumOutline.vue'

import { translate as t, translatePlural as n } from '@nextcloud/l10n'

export default {
	name: 'EnhetschattPanel',

	components: {
		NcButton,
		NcEmptyContent,
		AccountGroupIcon,
		AccountMultiplePlusIcon,
		AtIcon,
		CloseIcon,
		ForumOutlineIcon,
	},

	props: {
		/** Team i enheten: [{ id, label, olasta:Number, omnamnanden:Number }] */
		team: {
			type: Array,
			default: () => [],
		},
		/** Panelen är öppen (kontrollerad av förälder). */
		open: {
			type: Boolean,
			default: false,
		},
	},

	watch: {
		// Flytta fokus in i panelen när den öppnas — Escape fungerar direkt, fokus fastnar inte bakom backdrop.
		// Vid stängning återlämnas fokus till elementet som öppnade panelen.
		open(isOpen) {
			if (isOpen) {
				this.tidigareFokus = document.activeElement
				this.$nextTick(() => {
					if (this.$refs.panel) {
						this.$refs.panel.focus()
					}
				})
			} else if (this.tidigareFokus && typeof this.tidigareFokus.focus === 'function') {
				this.tidigareFokus.focus()
				this.tidigareFokus = null
			}
		},
	},

	computed: {
		/** Team finns men allt är läst — då visas den lugna texten (inte "inga team"). */
		allaLugna() {
			return this.team.length > 0
				&& this.team.every((grupp) => this.olasta(grupp) === 0 && this.omnamnanden(grupp) === 0)
		},
	},

	created() {
		// Icke-reaktiv referens till elementet som hade fokus innan panelen öppnades.
		this.tidigareFokus = null
	},

	methods: {
		t,
		n,

		/**
		 * Enkel fokusfälla: Tab/Shift+Tab cyklar mellan panelens fokuserbara element
		 * så fokus aldrig hamnar bakom backdropen medan panelen är öppen.
		 * @param {KeyboardEvent} event tab-tryck inne i panelen
		 */
		fokusfalla(event) {
			const panel = this.$refs.panel
			if (!panel) {
				return
			}
			const fokuserbara = panel.querySelectorAll(
				'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
			)
			if (!fokuserbara.length) {
				event.preventDefault()
				return
			}
			const forsta = fokuserbara[0]
			const sista = fokuserbara[fokuserbara.length - 1]
			if (event.shiftKey) {
				if (document.activeElement === forsta || document.activeElement === panel) {
					event.preventDefault()
					sista.focus()
				}
			} else if (document.activeElement === sista) {
				event.preventDefault()
				forsta.focus()
			}
		},

		/** Olästa för ett team (neutral indikator). */
		olasta(grupp) {
			return Number((grupp && grupp.olasta) || 0)
		},

		/** Omnämnanden till mig för ett team (accent-indikator). */
		omnamnanden(grupp) {
			return Number((grupp && grupp.omnamnanden) || 0)
		},

		/** Talande aria-label: namn + olästa + omnämnanden i klartext (aldrig bara färg). */
		teamAriaLabel(grupp) {
			const olasta = this.olasta(grupp)
			const omnamnanden = this.omnamnanden(grupp)
			const delar = [grupp.label]
			if (omnamnanden > 0) {
				delar.push(this.n('hubs_start', '%n omnämnande', '%n omnämnanden', omnamnanden))
			}
			if (olasta > 0) {
				delar.push(this.n('hubs_start', '%n oläst', '%n olästa', olasta))
			}
			if (omnamnanden === 0 && olasta === 0) {
				delar.push(this.t('hubs_start', 'inget nytt'))
			}
			// Rad utan chattrum lovar inget den inte kan hålla.
			delar.push(grupp.token
				? this.t('hubs_start', 'öppna trådar')
				: this.t('hubs_start', 'chattrum saknas för teamet ännu'))
			return delar.join(' — ')
		},
	},
}
</script>

<style scoped lang="scss">
.enhetschatt {
	&__backdrop {
		position: fixed;
		inset: 0;
		z-index: 2000;
		background: rgba(0, 0, 0, 0.25);
	}

	&__panel {
		position: fixed;
		inset-block: 0;
		inset-inline-end: 0;
		z-index: 2001;
		display: flex;
		flex-direction: column;
		width: 360px;
		max-width: 100vw;
		background: var(--color-main-background);
		border-inline-start: 1px solid var(--color-border);
		box-shadow: -2px 0 12px rgba(0, 0, 0, 0.12);
		outline: none;
	}

	&__head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		padding: 12px 16px;
		border-bottom: 1px solid var(--color-border);
	}

	&__title {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0;
		font-size: 1.05rem;
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__list {
		flex: 1 1 auto;
		overflow-y: auto;
		margin: 0;
		padding: 8px;
		list-style: none;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__item {
		list-style: none;
	}

	&__team {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		width: 100%;
		padding: 10px 12px;
		border: none;
		border-radius: var(--border-radius-large, 12px);
		background: transparent;
		color: var(--color-main-text);
		text-align: start;
		cursor: pointer;

		&:hover {
			background: var(--color-background-hover);
		}

		// Team utan chattrum ännu: rad utan destination visas dämpad, ej klickbar.
		&:disabled {
			cursor: default;
			color: var(--color-text-maxcontrast);

			&:hover {
				background: transparent;
			}
		}
	}

	&__team-label {
		font-weight: 600;
		font-size: 0.95rem;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__indikatorer {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		flex: 0 0 auto;
	}

	// Chip-grammatik: pill, ikon + tal, color-mix-bakgrund — matchar FristChip/DiskussionChip.
	&__pill {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 2px 9px;
		border-radius: var(--border-radius-pill, 16px);
		font-size: 0.78rem;
		font-weight: 600;
		white-space: nowrap;

		// Olästa: neutral ton.
		&--olasta {
			border: 1px solid var(--color-border);
			background: var(--color-main-background);
			color: var(--color-text-maxcontrast);
		}

		// Omnämnande till mig: accent — men @-ikon + tal bär samma signal, ej bara färg.
		&--mention {
			border: 1px solid var(--color-primary-element);
			background: var(--color-primary-element-light, color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background)));
			color: var(--color-primary-element);
		}
	}

	&__empty {
		margin: 16px 8px;
	}

	// Lugn-läget: diskret rad under team-listan när allt är läst — ingen stor tom-yta.
	&__lugn {
		flex: 0 0 auto;
		margin: 0;
		padding: 8px 16px 12px;
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
	}

	&__foot {
		flex: 0 0 auto;
		padding: 12px 16px;
		border-top: 1px solid var(--color-border);
	}

	// Reflow: full bredd i smal vy (hembesök, portrait, 400 %).
	@media (max-width: 720px) {
		&__panel {
			width: 100vw;
			border-inline-start: none;
		}
	}
}
</style>
