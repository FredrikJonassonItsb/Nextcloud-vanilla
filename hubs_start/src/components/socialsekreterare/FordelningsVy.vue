<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Gruppledarens lättviktiga fördelningsläge (roll-läge 'fordelning', ingen egen app).
  - Tre zoner: A "Att fördela" (otilldelade utredningar med mottagningens förslag +
  - primäråtgärd fördela), B "Utredarnas belastning" (aggregat, aldrig ärendeinnehåll),
  - C "Mottagningens pågående" (read-only). Compliance-ankare: räknaren "Att fördela: N"
  - får aldrig nå noll med kort kvar — antalet visas alltid. Fördelning bekräftas i
  - dialog (ansvar + skrivåtkomst + Mina ärenden + fristpåminnelser) innan emit.
-->
<template>
	<div class="fordelnings-vy">
		<NcLoadingIcon v-if="loading" :size="40" class="fordelnings-vy__laddar" :name="t('hubs_start', 'Laddar fördelningsläget …')" />

		<template v-else>
			<!-- ZON A — Att fördela -->
			<section class="hs-card fordelnings-vy__zon" :aria-label="t('hubs_start', 'Att fördela')">
				<h2 class="hs-card__title">
					<InboxArrowDownIcon :size="20" />
					{{ t('hubs_start', 'Att fördela') }}
					<!-- Compliance-ankare: antalet visas alltid, även 0, så raden aldrig "försvinner". -->
					<NcCounterBubble class="fordelnings-vy__count">{{ attFordela.length }}</NcCounterBubble>
				</h2>

				<ul
					v-if="attFordela.length"
					class="fordelnings-vy__lista"
					aria-live="polite"
					:aria-label="t('hubs_start', 'Otilldelade utredningar')">
					<li
						v-for="a in attFordela"
						:key="refFor(a)"
						class="hs-card fordelnings-vy__arende">
						<div class="fordelnings-vy__arende-huvud">
							<span class="fordelnings-vy__barn">{{ a.barnRef }}</span>

							<span class="fordelnings-vy__badges">
								<span
									v-if="a.sekretess && a.sekretess.kod"
									class="fordelnings-vy__chip fordelnings-vy__chip--sekretess">
									<ShieldLockIcon :size="13" />
									{{ a.sekretess.kod }}
								</span>
								<span
									v-if="a.sekretess && a.sekretess.skyddadeUppgifter"
									class="fordelnings-vy__chip fordelnings-vy__chip--skydd">
									<ShieldAccountIcon :size="13" />
									{{ t('hubs_start', 'skyddade uppgifter') }}
								</span>
								<span
									v-if="a.loa"
									class="fordelnings-vy__chip fordelnings-vy__chip--loa">
									<CheckDecagramIcon :size="13" />
									{{ a.loa }}
								</span>
								<FristChip v-if="a.frist" :frist="a.frist" />
							</span>
						</div>

						<!-- Mottagningens förslag (rådgivande, chefen beslutar) -->
						<p v-if="a.forslag" class="fordelnings-vy__forslag">
							<LightbulbOutlineIcon :size="15" />
							<span>
								<span class="fordelnings-vy__forslag-utfall">{{ t('hubs_start', 'Mottagningens förslag: {utfall}', { utfall: a.forslag.utfall }) }}</span>
								<span v-if="a.forslag.motiv" class="fordelnings-vy__forslag-motiv"> — {{ a.forslag.motiv }}</span>
							</span>
						</p>

						<!-- Compliance: ofördelad-tid + att utredningsfristen redan löper -->
						<p class="fordelnings-vy__ofordelad">
							<ClockAlertOutlineIcon :size="15" />
							{{ ofordeladText(a) }}
						</p>

						<div class="fordelnings-vy__atgard">
							<FordelaTill
								:arende="a"
								:utredare="utredare"
								@fordela="onFordela" />
						</div>
					</li>
				</ul>

				<NcEmptyContent
					v-else
					class="fordelnings-vy__empty"
					:name="t('hubs_start', 'Inget att fördela just nu')"
					:description="t('hubs_start', 'Alla utredningar har en ansvarig utredare. När mottagningen lämnar över ett nytt ärende dyker det upp här — räknaren visar alltid hur många som väntar.')">
					<template #icon>
						<CheckCircleOutlineIcon :size="40" />
					</template>
				</NcEmptyContent>
			</section>

			<!-- ZON B — Utredarnas belastning (aggregat, aldrig ärendeinnehåll) -->
			<section class="hs-card fordelnings-vy__zon" :aria-label="t('hubs_start', 'Utredarnas belastning')">
				<h2 class="hs-card__title">
					<AccountGroupIcon :size="20" />
					{{ t('hubs_start', 'Utredarnas belastning') }}
				</h2>
				<UtredarLast
					:utredare="utredare"
					@valj-utredare="onValjUtredare" />
			</section>

			<!-- ZON C — Mottagningens pågående (read-only, ingen åtgärd) -->
			<section class="hs-card fordelnings-vy__zon fordelnings-vy__zon--read" :aria-label="t('hubs_start', 'Mottagningens pågående')">
				<h2 class="hs-card__title">
					<ClipboardTextClockOutlineIcon :size="20" />
					{{ t('hubs_start', 'Mottagningens pågående') }}
				</h2>
				<p class="fordelnings-vy__mottagning">
					{{ n('hubs_start', '%n pågående förhandsbedömning i mottagningen', '%n pågående förhandsbedömningar i mottagningen', mottagningPagaende) }}
				</p>
			</section>
		</template>

		<!-- Bekräfta fördelning: konsekvenserna stavas ut innan beslutet verkställs -->
		<NcModal
			v-if="bekraftar"
			:name="t('hubs_start', 'Fördela ärendet?')"
			size="normal"
			:show="true"
			@close="avbryt">
			<p class="fordelnings-vy__bekrafta-text">{{ bekraftaText }}</p>
			<div class="fordelnings-vy__bekrafta-footer">
				<NcButton @click="avbryt">
					{{ t('hubs_start', 'Avbryt') }}
				</NcButton>
				<NcButton
					type="primary"
					class="fordelnings-vy__bekrafta-ja"
					@click="bekraftaFordela">
					<template #icon>
						<AccountArrowRightIcon :size="20" />
					</template>
					{{ t('hubs_start', 'Fördela till {namn}', { namn: bekraftaNamn }) }}
				</NcButton>
			</div>
		</NcModal>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import InboxArrowDownIcon from 'vue-material-design-icons/InboxArrowDown.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import AccountArrowRightIcon from 'vue-material-design-icons/AccountArrowRight.vue'
import ClipboardTextClockOutlineIcon from 'vue-material-design-icons/ClipboardTextClockOutline.vue'
import ClockAlertOutlineIcon from 'vue-material-design-icons/ClockAlertOutline.vue'
import ShieldLockIcon from 'vue-material-design-icons/ShieldLock.vue'
import ShieldAccountIcon from 'vue-material-design-icons/ShieldAccount.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import LightbulbOutlineIcon from 'vue-material-design-icons/LightbulbOutline.vue'
import CheckCircleOutlineIcon from 'vue-material-design-icons/CheckCircleOutline.vue'

import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import FristChip from './FristChip.vue'
import FordelaTill from './FordelaTill.vue'
import UtredarLast from './UtredarLast.vue'

export default {
	name: 'FordelningsVy',

	components: {
		NcButton,
		NcModal,
		NcCounterBubble,
		NcEmptyContent,
		NcLoadingIcon,
		InboxArrowDownIcon,
		AccountGroupIcon,
		AccountArrowRightIcon,
		ClipboardTextClockOutlineIcon,
		ClockAlertOutlineIcon,
		ShieldLockIcon,
		ShieldAccountIcon,
		CheckDecagramIcon,
		LightbulbOutlineIcon,
		CheckCircleOutlineIcon,
		FristChip,
		FordelaTill,
		UtredarLast,
	},

	props: {
		/**
		 * {
		 *   attFordela: Array<Arende>,
		 *   utredare: Array<{ namn, aktiva:Number, roda:Number, naraTak:Boolean }>,
		 *   mottagningPagaende: Number,
		 * }
		 */
		summary: {
			type: Object,
			default: () => ({ attFordela: [], utredare: [], mottagningPagaende: 0 }),
		},
		loading: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			// Det pågående fördelnings-valet i väntan på bekräftelse: { arende, utredareUid }.
			bekraftar: null,
		}
	},

	computed: {
		attFordela() {
			return (this.summary && this.summary.attFordela) || []
		},
		utredare() {
			return (this.summary && this.summary.utredare) || []
		},
		mottagningPagaende() {
			return Number((this.summary && this.summary.mottagningPagaende) || 0)
		},
		bekraftaNamn() {
			return this.bekraftar ? this.bekraftar.utredareUid : ''
		},
		bekraftaText() {
			return this.t('hubs_start', '{namn} blir ansvarig utredare, får skrivåtkomst till ärenderummet, ärendet visas i hens Mina ärenden, fristpåminnelser går till hen.', { namn: this.bekraftaNamn })
		},
	},

	methods: {
		t,
		n,

		/** Stabil referens: dnr om det finns, annars triageRef. */
		refFor(a) {
			return a.dnr || a.triageRef
		},

		ofordeladText(a) {
			const dagar = Number(a.ofordeladDagar || 0)
			return this.t('hubs_start', 'ofördelad i {dagar} dagar · utredningsfrist löper', { dagar })
		},

		/** Öppnar bekräftelsedialogen i stället för att fördela direkt. */
		onFordela({ arende, utredareUid }) {
			this.bekraftar = { arende, utredareUid }
		},

		avbryt() {
			this.bekraftar = null
		},

		bekraftaFordela() {
			if (!this.bekraftar) {
				return
			}
			const { arende, utredareUid } = this.bekraftar
			this.$emit('fordela', { ref: this.refFor(arende), utredareUid })
			this.bekraftar = null
		},

		/** Omfördelning (byte av ansvarig) går via samma kanal — exponeras för föräldern. */
		omfordela(payload) {
			this.$emit('omfordela', payload)
		},

		onValjUtredare(utredare) {
			this.$emit('valj-utredare', utredare)
		},
	},
}
</script>

<style scoped lang="scss">
.fordelnings-vy {
	display: flex;
	flex-direction: column;
	gap: var(--hs-gap, 16px);

	&__laddar {
		margin: 32px auto;
	}

	&__count {
		margin-inline-start: 2px;
	}

	&__lista {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__arende {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding: 12px;

		&:hover {
			background: var(--color-background-hover);
		}
	}

	&__arende-huvud {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px 12px;
	}

	&__barn {
		font-weight: 600;
		font-size: 0.98rem;
		color: var(--color-main-text);
	}

	&__badges {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
	}

	// Chip-grammatik matchar FristChip: pill, ikon + text, ljus färgton.
	&__chip {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 2px 9px;
		border-radius: var(--border-radius-pill, 16px);
		border: 1px solid var(--chip-color, var(--color-border));
		background: color-mix(in srgb, var(--chip-color, var(--color-border)) 12%, var(--color-main-background));
		color: var(--color-main-text);
		font-size: 0.76rem;
		font-weight: 600;
		white-space: nowrap;

		&--sekretess { --chip-color: var(--hs-status-info); }
		&--skydd { --chip-color: var(--hs-status-error); }
		&--loa { --chip-color: var(--hs-status-success); }
	}

	&__forslag {
		display: flex;
		align-items: flex-start;
		gap: 6px;
		margin: 0;
		font-size: 0.85rem;
		color: var(--color-main-text);

		.material-design-icon {
			color: var(--hs-status-warning);
		}
	}

	&__forslag-utfall {
		font-weight: 600;
	}

	&__forslag-motiv {
		color: var(--color-text-maxcontrast);
	}

	&__ofordelad {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: 0;
		font-size: 0.82rem;
		color: var(--color-text-maxcontrast);

		.material-design-icon {
			color: var(--hs-status-warning);
		}
	}

	&__atgard {
		display: flex;
		justify-content: flex-end;
	}

	&__empty {
		margin: 8px 0 0;
	}

	&__mottagning {
		margin: 0;
		font-size: 0.9rem;
		color: var(--color-text-maxcontrast);
	}

	&__zon--read {
		background: var(--color-background-dark);
	}

	&__bekrafta-text {
		margin: 0;
		padding: 8px 0;
		color: var(--color-main-text);
		line-height: 1.5;
	}

	&__bekrafta-footer {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		padding-top: 12px;
	}

	// Reflow @720px: åtgärden under huvudet, inga horisontella scrollytor.
	@media (max-width: 720px) {
		&__atgard {
			justify-content: flex-start;
		}
	}
}
</style>
