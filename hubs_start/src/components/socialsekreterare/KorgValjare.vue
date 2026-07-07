<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Korg-/typ-filter överst i Zon 1. En handläggare med 4–6 korgar fokuserar eller
  - avfokuserar per korg + per meddelandetyp utan navigeringskostnad. Default "Alla
  - korgar". Korg ∩ typ kombineras (båda kan vara aktiva). Samma piller-grammatik som
  - Dagspulsen: ikon + text + tal, aldrig bara färg; knapp-/tangentbordsstyrt.
-->
<template>
	<div class="korg-valjare" role="group" :aria-label="t('hubs_start', 'Korg- och typfilter')">
		<!-- Korg-raden: ett "Alla"-piller + ett per korg -->
		<div
			class="korg-valjare__korgar"
			role="group"
			:aria-label="t('hubs_start', 'Korgar')">
			<!-- AGGREGAT-pillren (Alla korgar / Alla grupper) har egen gråton
			     (--aggregat) — de är SUMMOR av de andra korgarna, inte egna val. -->
			<button
				type="button"
				class="korg-valjare__korg korg-valjare__korg--aggregat hs-target"
				:class="{ 'korg-valjare__korg--aktiv': aktivKorg === null }"
				:aria-pressed="aktivKorg === null ? 'true' : 'false'"
				:aria-label="t('hubs_start', 'Alla korgar (summan av alla) — {n} otriagerat', { n: totaltOtriagerat })"
				@click="valjKorg(null)">
				<span class="korg-valjare__ikon" aria-hidden="true">
					<InboxMultipleIcon :size="18" />
				</span>
				<span class="korg-valjare__etikett">{{ t('hubs_start', 'Alla korgar') }}</span>
				<NcCounterBubble v-if="totaltOtriagerat > 0" class="korg-valjare__bubbla">{{ totaltOtriagerat }}</NcCounterBubble>
			</button>

			<button
				v-if="harGrupper"
				type="button"
				class="korg-valjare__korg korg-valjare__korg--aggregat hs-target"
				:class="{ 'korg-valjare__korg--aktiv': aktivKorg === GRUPPER }"
				:aria-pressed="aktivKorg === GRUPPER ? 'true' : 'false'"
				:aria-label="t('hubs_start', 'Alla grupper (summan av funktionsbrevlådorna) — {n} otriagerat', { n: gruppOtriagerat })"
				@click="valjKorg(GRUPPER)">
				<span class="korg-valjare__ikon" aria-hidden="true">
					<AccountGroupIcon :size="18" />
				</span>
				<span class="korg-valjare__etikett">{{ t('hubs_start', 'Alla grupper') }}</span>
				<NcCounterBubble v-if="gruppOtriagerat > 0" class="korg-valjare__bubbla">{{ gruppOtriagerat }}</NcCounterBubble>
			</button>

			<button
				v-for="korg in korgar"
				:key="korg.addr"
				type="button"
				class="korg-valjare__korg hs-target"
				:class="{ 'korg-valjare__korg--aktiv': aktivKorg === korg.addr }"
				:aria-pressed="aktivKorg === korg.addr ? 'true' : 'false'"
				:aria-label="korgAriaLabel(korg)"
				@click="valjKorg(korg.addr)">
				<span class="korg-valjare__ikon" aria-hidden="true">
					<component :is="scopeIkon(korg.scope)" :size="18" />
				</span>
				<span class="korg-valjare__etikett">{{ korg.label }}</span>
				<NcCounterBubble v-if="Number(korg.otriagerat) > 0" class="korg-valjare__bubbla">{{ Number(korg.otriagerat) }}</NcCounterBubble>
			</button>
		</div>

		<!-- Typ-raden: små piller, en per meddelandetyp -->
		<div
			class="korg-valjare__typer"
			role="group"
			:aria-label="t('hubs_start', 'Meddelandetyper')">
			<button
				v-for="typ in typer"
				:key="typ.key"
				type="button"
				class="korg-valjare__typ hs-target"
				:class="{ 'korg-valjare__typ--aktiv': aktivTyp === typ.key }"
				:aria-pressed="aktivTyp === typ.key ? 'true' : 'false'"
				:aria-label="t('hubs_start', 'Typ: {typ}', { typ: typ.label })"
				@click="valjTyp(typ.key)">
				<component :is="typ.icon" :size="14" aria-hidden="true" />
				<span class="korg-valjare__typ-text">{{ typ.label }}</span>
			</button>
		</div>
	</div>
</template>

<script>
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'

import AccountIcon from 'vue-material-design-icons/Account.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import FaxIcon from 'vue-material-design-icons/Fax.vue'
import ShieldAccountIcon from 'vue-material-design-icons/ShieldAccount.vue'
import InboxMultipleIcon from 'vue-material-design-icons/InboxMultiple.vue'

import AlertOctagonIcon from 'vue-material-design-icons/AlertOctagon.vue'
import FileDocumentPlusIcon from 'vue-material-design-icons/FileDocumentPlus.vue'
import HelpCircleOutlineIcon from 'vue-material-design-icons/HelpCircleOutline.vue'
import FileSendIcon from 'vue-material-design-icons/FileSend.vue'
import ForumIcon from 'vue-material-design-icons/Forum.vue'
import BankIcon from 'vue-material-design-icons/Bank.vue'
import TrashCanOutlineIcon from 'vue-material-design-icons/TrashCanOutline.vue'

import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'KorgValjare',

	components: {
		NcCounterBubble,
		AccountIcon,
		AccountGroupIcon,
		FaxIcon,
		ShieldAccountIcon,
		InboxMultipleIcon,
		AlertOctagonIcon,
		FileDocumentPlusIcon,
		HelpCircleOutlineIcon,
		FileSendIcon,
		ForumIcon,
		BankIcon,
		TrashCanOutlineIcon,
	},

	props: {
		/** [{ addr, label, scope:('personlig'|'grupp'|'fax'|'sdk'), otriagerat:Number }] */
		korgar: {
			type: Array,
			default: () => [],
		},
		/** Aktiv korg-adress, eller null = "Alla korgar". */
		aktivKorg: {
			type: String,
			default: null,
		},
		/** Aktiv meddelandetyp, eller null = alla typer. */
		aktivTyp: {
			type: String,
			default: null,
		},
	},

	computed: {
		/**
		 * Sentinel-värdet för aggregatet "Alla grupper" (= alla korgar utom den
		 * personliga). '@'-prefixet kan aldrig kollidera med en korg-adress.
		 * MinaArendens filteredInflode tolkar samma sentinel.
		 */
		GRUPPER() {
			return '@alla-grupper'
		},

		/** Summan av otriagerat över alla korgar — visas på "Alla"-pillret. */
		totaltOtriagerat() {
			return this.korgar.reduce((sum, korg) => sum + Number(korg.otriagerat || 0), 0)
		},

		/** Finns funktionsbrevlådor (grupp/fax/sdk)? Annars är aggregatet meningslöst. */
		harGrupper() {
			return this.korgar.some((korg) => korg.scope !== 'personlig')
		},

		/** Summan av otriagerat över funktionsbrevlådorna — "Alla grupper"-pillret. */
		gruppOtriagerat() {
			return this.korgar
				.filter((korg) => korg.scope !== 'personlig')
				.reduce((sum, korg) => sum + Number(korg.otriagerat || 0), 0)
		},

		/** De åtta meddelandetyperna med svensk etikett + ikon, i fast visningsordning. */
		typer() {
			return [
				{ key: 'orosanmalan', label: this.t('hubs_start', 'Orosanmälan'), icon: 'AlertOctagonIcon' },
				{ key: 'komplettering', label: this.t('hubs_start', 'Komplettering'), icon: 'FileDocumentPlusIcon' },
				{ key: 'fraga', label: this.t('hubs_start', 'Fråga'), icon: 'HelpCircleOutlineIcon' },
				{ key: 'remiss', label: this.t('hubs_start', 'Remiss'), icon: 'FileSendIcon' },
				{ key: 'internpost', label: this.t('hubs_start', 'Internpost'), icon: 'ForumIcon' },
				{ key: 'fax', label: this.t('hubs_start', 'Fax'), icon: 'FaxIcon' },
				{ key: 'sdk_myndighet', label: this.t('hubs_start', 'SDK-myndighet'), icon: 'BankIcon' },
				{ key: 'skrap', label: this.t('hubs_start', 'Skräp'), icon: 'TrashCanOutlineIcon' },
			]
		},
	},

	methods: {
		t,

		/** Ikon per korg-scope (personlig/grupp/fax/sdk). */
		scopeIkon(scope) {
			switch (scope) {
			case 'personlig':
				return 'AccountIcon'
			case 'grupp':
				return 'AccountGroupIcon'
			case 'fax':
				return 'FaxIcon'
			case 'sdk':
				return 'ShieldAccountIcon'
			default:
				return 'AccountIcon'
			}
		},

		korgAriaLabel(korg) {
			return this.t('hubs_start', '{korg} — {n} otriagerat', {
				korg: korg.label,
				n: Number(korg.otriagerat || 0),
			})
		},

		/** Klick på aktiv korg avmarkerar (emit null = "Alla"). */
		valjKorg(addr) {
			const nytt = this.aktivKorg === addr ? null : addr
			this.$emit('valj-korg', nytt)
		},

		/** Klick på aktivt typ-piller avmarkerar (emit null). */
		valjTyp(typ) {
			const nytt = this.aktivTyp === typ ? null : typ
			this.$emit('valj-typ', nytt)
		},
	},
}
</script>

<style scoped lang="scss">
.korg-valjare {
	display: flex;
	flex-direction: column;
	gap: 8px;

	&__korgar,
	&__typer {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
	}

	// Korg-piller: ikon + text + tal (NcCounterBubble). Aldrig bara färg.
	&__korg {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		min-height: 36px;
		padding: 4px 12px;
		border: 2px solid var(--color-border);
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-main-background);
		color: var(--color-main-text);
		font-size: 0.86rem;
		font-weight: 600;
		cursor: pointer;
		white-space: nowrap;
		transition: background 0.1s ease, border-color 0.1s ease;

		&:hover {
			background: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}

		// Aktiv korg = fylld accent + ring (ikon + text + tal bär informationen).
		&--aktiv {
			border-color: var(--color-primary-element);
			background: var(--color-primary-element-light, var(--color-background-hover));
			color: var(--color-main-text);
			font-weight: 700;
		}

		// AGGREGAT-pillren (Alla korgar / Alla grupper): egen gråton + streckad
		// ram som signalerar "summan av de andra", inte ett eget korg-val.
		&--aggregat:not(&--aktiv) {
			background: var(--color-background-dark);
			border-style: dashed;
			font-weight: 500;

			&:hover {
				background: var(--color-background-hover);
			}
		}

		&--aggregat#{&}--aktiv {
			border-style: dashed;
		}
	}

	&__ikon {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		color: var(--color-text-maxcontrast);
	}

	&__korg--aktiv &__ikon {
		color: var(--color-primary-element);
	}

	&__etikett {
		overflow: hidden;
		text-overflow: ellipsis;
		max-width: 18ch;
	}

	&__bubbla {
		margin-inline-start: 2px;
	}

	// Typ-piller: mindre, samma grammatik (ikon + text), toggle.
	&__typ {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		min-height: 24px;
		padding: 2px 9px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-main-background);
		color: var(--color-text-maxcontrast);
		font-size: 0.78rem;
		font-weight: 600;
		cursor: pointer;
		white-space: nowrap;
		transition: background 0.1s ease, border-color 0.1s ease;

		&:hover {
			background: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}

		// Aktiv typ = accent-ton; ikon + text + ramen bär samma signal, ej bara färg.
		&--aktiv {
			border-color: var(--color-primary-element);
			background: var(--color-primary-element-light, var(--color-background-hover));
			color: var(--color-primary-element);
		}
	}
}

// Reflow: pillerraderna wrappar redan; ingen horisontell scroll i smal vy.
@media (max-width: 720px) {
	.korg-valjare__etikett {
		max-width: 12ch;
	}
}
</style>
