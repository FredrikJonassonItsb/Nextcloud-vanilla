<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Mottagar-/favoritväljaren — modalen handläggaren öppnar för att välja mottagare
  - (t.ex. vid "Vidarebefordra" i Ej-kopplat-bandet). En favorit är en RESOLVERAD
  - pekare ur sdkmc-resolverlagret: SDK-adress ur DIGG, Hubs-förvaltad fax, eller
  - intern användare. Varje rad bär kanalikon + namn + identitets-/klass-badge + en
  - diskret proveniens-rad. TOMBSTONE: en borttagen DIGG-post (removed) visas
  - överstruken och är ALDRIG väljbar — en adress som inte längre finns i sanningen
  - får inte användas som mottagare. Fri sökning finns vid sidan av via "Kontakter".
-->
<template>
	<NcModal
		class="favorit-valjare"
		:name="dialogTitel"
		:show="true"
		size="normal"
		@close="onClose">
		<div
			class="favorit-valjare__body"
			@keydown.esc.stop="onClose">
			<!-- Filter-piller: ikon + text (aldrig bara färg), aria-pressed bär valt-tillstånd -->
			<div
				class="favorit-valjare__filter"
				role="group"
				:aria-label="t('hubs_start', 'Filtrera favoriter')">
				<button
					v-for="f in filter"
					:key="f.id"
					class="favorit-valjare__pill hs-target"
					:class="{ 'favorit-valjare__pill--aktiv': aktivtFilter === f.id }"
					type="button"
					:aria-pressed="String(aktivtFilter === f.id)"
					@click="aktivtFilter = f.id">
					<component :is="f.ikon" :size="14" />
					<span class="favorit-valjare__pill-text">{{ f.text }}</span>
				</button>
			</div>

			<!-- Favoritlistan -->
			<ul
				v-if="synliga.length"
				class="favorit-valjare__list"
				:aria-label="t('hubs_start', 'Favoriter')">
				<li
					v-for="fav in synliga"
					:key="fav.id"
					class="favorit-valjare__item hs-card-skal"
					:class="{ 'favorit-valjare__item--removed': fav.removed }">
					<button
						class="favorit-valjare__rad hs-target"
						type="button"
						:disabled="fav.removed"
						:aria-disabled="String(!!fav.removed)"
						:aria-label="radAriaLabel(fav)"
						@click="onValj(fav)">
						<!-- Kanalikon: sdk=ShieldAccount, fax=Fax, internal=Account -->
						<span
							class="favorit-valjare__kanal"
							:class="'favorit-valjare__kanal--' + kanalNyckel(fav)"
							aria-hidden="true">
							<ShieldAccountIcon v-if="fav.kanal === 'sdk'" :size="20" />
							<FaxIcon v-else-if="fav.kanal === 'fax'" :size="20" />
							<AccountIcon v-else :size="20" />
						</span>

						<span class="favorit-valjare__mitt">
							<!-- Namn + ev. org -->
							<span class="favorit-valjare__namn-rad">
								<span class="favorit-valjare__namn">{{ fav.namn }}</span>
								<span v-if="fav.org" class="favorit-valjare__org">{{ fav.org }}</span>
							</span>

							<!-- Klass-/identitets-badge: ikon + text (aldrig bara färg) -->
							<span class="favorit-valjare__badges">
								<span
									class="favorit-valjare__badge"
									:class="'favorit-valjare__badge--' + badge(fav).variant">
									<component :is="badge(fav).ikon" :size="13" />
									<span class="favorit-valjare__badge-text">{{ badge(fav).text }}</span>
								</span>

								<!-- Varning för borttagen post — bär samma signal i text -->
								<span
									v-if="fav.removed"
									class="favorit-valjare__badge favorit-valjare__badge--removed">
									<AlertOctagonOutlineIcon :size="13" />
									<span class="favorit-valjare__badge-text">{{ t('hubs_start', 'Kan inte användas') }}</span>
								</span>
							</span>

							<!-- Diskret proveniens-rad — samma stil som ProvenansChip -->
							<span
								v-if="fav.proveniens"
								class="favorit-valjare__proveniens"
								:class="{ 'favorit-valjare__proveniens--varning': fav.removed }">
								<AlertOctagonOutlineIcon v-if="fav.removed" :size="12" />
								<HistoryIcon v-else :size="12" />
								<span class="favorit-valjare__proveniens-text">{{ fav.proveniens }}</span>
							</span>
						</span>

						<!-- Väljbar-affordans (döljs för borttagen) -->
						<ChevronRightIcon
							v-if="!fav.removed"
							class="favorit-valjare__chevron"
							:size="20"
							aria-hidden="true" />
					</button>
				</li>
			</ul>

			<!-- Tomt resultat för aktivt filter -->
			<NcEmptyContent
				v-else
				class="favorit-valjare__empty"
				:name="t('hubs_start', 'Inga favoriter i den här listan')"
				:description="t('hubs_start', 'Prova en annan lista, eller sök fritt i Kontakter.')">
				<template #icon>
					<AccountStarIcon :size="40" />
				</template>
			</NcEmptyContent>
		</div>

		<div class="favorit-valjare__footer">
			<!-- Fri sökning vid sidan av favoriterna (stub) -->
			<NcButton
				class="favorit-valjare__sok"
				type="tertiary"
				@click="onSok">
				<template #icon>
					<MagnifyIcon :size="20" />
				</template>
				{{ t('hubs_start', 'Sök i Kontakter …') }}
			</NcButton>

			<NcButton type="secondary" @click="onClose">
				{{ t('hubs_start', 'Avbryt') }}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

import AccountIcon from 'vue-material-design-icons/Account.vue'
import AccountStarIcon from 'vue-material-design-icons/AccountStar.vue'
import AlertOctagonOutlineIcon from 'vue-material-design-icons/AlertOctagonOutline.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import FaxIcon from 'vue-material-design-icons/Fax.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import ShieldAccountIcon from 'vue-material-design-icons/ShieldAccount.vue'

import { translate as t, translatePlural as n } from '@nextcloud/l10n'

export default {
	name: 'FavoritValjare',

	components: {
		NcButton,
		NcModal,
		NcEmptyContent,
		AccountIcon,
		AccountStarIcon,
		AlertOctagonOutlineIcon,
		CheckDecagramIcon,
		ChevronRightIcon,
		FaxIcon,
		HistoryIcon,
		MagnifyIcon,
		OfficeBuildingIcon,
		ShieldAccountIcon,
	},

	props: {
		/** Resolverade favorit-DTO:er ur sdkmc-resolverlagret (se services/demo/favoriter.js). */
		favoriter: {
			type: Array,
			default: () => [],
		},
		/** Modalen är öppen (kontrollerad av förälder). */
		open: {
			type: Boolean,
			default: false,
		},
		/** Rubrik i dialoghuvudet. */
		titel: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			// Aktivt listfilter: 'alla' | 'personlig' | 'mottagningen@'.
			aktivtFilter: 'alla',
		}
	},

	computed: {
		dialogTitel() {
			return this.titel || this.t('hubs_start', 'Välj mottagare')
		},

		/** Filterpiller: ikon + text. Mappar mot favoritens listor[]. */
		filter() {
			return [
				{ id: 'alla', text: this.t('hubs_start', 'Alla'), ikon: 'AccountStarIcon' },
				{ id: 'personlig', text: this.t('hubs_start', 'Mina (personlig)'), ikon: 'AccountIcon' },
				{ id: 'mottagningen@', text: this.t('hubs_start', 'mottagningen@'), ikon: 'OfficeBuildingIcon' },
			]
		},

		/** Favoriter efter aktivt filter — tombstones visas kvar (men icke-väljbara). */
		synliga() {
			const lista = Array.isArray(this.favoriter) ? this.favoriter : []
			if (this.aktivtFilter === 'alla') {
				return lista
			}
			return lista.filter((f) => Array.isArray(f.listor) && f.listor.includes(this.aktivtFilter))
		},
	},

	methods: {
		t,
		n,

		/** Stabil nyckel för kanalbaserad styling — faller tillbaka på 'internal'. */
		kanalNyckel(fav) {
			return (fav && fav.kanal) || 'internal'
		},

		/**
		 * Klass-/identitets-badge per regel:
		 *  a) verifierad SDK-adress, b) Hubs-förvaltad fax, c) intern.
		 */
		badge(fav) {
			if (fav.kanal === 'sdk' && fav.identitet && fav.identitet.verifierad) {
				return {
					variant: 'verifierad',
					ikon: 'CheckDecagramIcon',
					text: this.t('hubs_start', '✓ verifierad SDK-adress'),
				}
			}
			if (fav.kanal === 'fax') {
				return {
					variant: 'fax',
					ikon: 'FaxIcon',
					text: this.t('hubs_start', 'Hubs-förvaltad fax'),
				}
			}
			if (fav.kanal === 'internal') {
				return {
					variant: 'intern',
					ikon: 'AccountIcon',
					text: this.t('hubs_start', 'intern'),
				}
			}
			// SDK-pekare utan verifierad identitet (t.ex. borttagen tombstone) — neutral.
			return {
				variant: 'neutral',
				ikon: 'ShieldAccountIcon',
				text: this.t('hubs_start', 'SDK-adress'),
			}
		},

		/** Talande aria-label: namn, org, klass/identitet, väljbarhet — aldrig bara färg. */
		radAriaLabel(fav) {
			const delar = [fav.namn]
			if (fav.org) {
				delar.push(fav.org)
			}
			delar.push(this.badge(fav).text)
			if (fav.removed) {
				delar.push(this.t('hubs_start', 'borttagen — kan inte användas som mottagare'))
			} else {
				delar.push(this.t('hubs_start', 'välj som mottagare'))
			}
			return delar.join(' — ')
		},

		onValj(fav) {
			// Tombstone är aldrig väljbar — en borttagen DIGG-post får ej användas som mottagare.
			if (fav.removed) {
				return
			}
			this.$emit('valj', fav)
		},

		onSok() {
			this.$emit('sok')
		},

		onClose() {
			this.$emit('close')
		},

		/** NcDialog stänger sig självt (backdrop/Escape/×) → re-emit close. */
		onUpdateOpen(open) {
			if (!open) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped lang="scss">
.favorit-valjare {
	&__body {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 4px 0 8px;
	}

	/* Filterrad ------------------------------------------------------------ */
	&__filter {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	&__pill {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		min-height: 24px;
		padding: 3px 11px;
		border-radius: var(--border-radius-pill, 16px);
		border: 1px solid var(--color-border);
		background: var(--color-main-background);
		color: var(--color-text-maxcontrast);
		font-size: 0.82rem;
		font-weight: 600;
		cursor: pointer;
		white-space: nowrap;

		&:hover {
			background: var(--color-background-hover);
			color: var(--color-main-text);
		}

		// Valt filter: accent — men ikon + text + aria-pressed bär signalen, ej bara färg.
		&--aktiv {
			border-color: var(--color-primary-element);
			background: var(--color-primary-element-light, color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background)));
			color: var(--color-primary-element);
		}
	}

	&__pill-text {
		overflow: hidden;
		text-overflow: ellipsis;
	}

	/* Lista ---------------------------------------------------------------- */
	&__list {
		display: flex;
		flex-direction: column;
		gap: 6px;
		margin: 0;
		padding: 0;
		list-style: none;
		max-height: 52vh;
		overflow-y: auto;
	}

	&__item {
		list-style: none;
		border-radius: var(--border-radius-large, 12px);

		// Tombstone: gråtonad ram, ej-väljbar känsla.
		&--removed {
			opacity: 0.72;
		}
	}

	&__rad {
		display: flex;
		align-items: flex-start;
		gap: 12px;
		width: 100%;
		padding: 10px 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
		color: var(--color-main-text);
		text-align: start;
		cursor: pointer;

		&:hover:not(:disabled) {
			background: var(--color-background-hover);
			border-color: var(--color-primary-element);
		}

		&:disabled {
			cursor: not-allowed;
		}
	}

	&__kanal {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		flex: 0 0 auto;
		width: 36px;
		height: 36px;
		border-radius: 50%;
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);

		&--sdk { color: var(--hs-status-success); }
		&--fax { color: var(--hs-status-info, var(--color-primary-element)); }
		&--internal { color: var(--color-primary-element); }
	}

	&__mitt {
		display: flex;
		flex-direction: column;
		gap: 4px;
		min-width: 0;
		flex: 1 1 auto;
	}

	&__namn-rad {
		display: flex;
		align-items: baseline;
		flex-wrap: wrap;
		gap: 4px 8px;
		min-width: 0;
	}

	&__namn {
		font-weight: 600;
		font-size: 0.95rem;
		color: var(--color-main-text);
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__org {
		font-size: 0.82rem;
		color: var(--color-text-maxcontrast);
		overflow: hidden;
		text-overflow: ellipsis;
	}

	/* Badge — chip-grammatik som ProvenansChip/KopplingBadge ---------------- */
	&__badges {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	&__badge {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 1px 8px;
		border-radius: var(--border-radius-pill, 16px);
		border: 1px solid var(--fv-badge-color, var(--color-border));
		background: color-mix(in srgb, var(--fv-badge-color, var(--color-border)) 12%, var(--color-main-background));
		color: var(--fv-badge-color, var(--color-text-maxcontrast));
		font-size: 0.74rem;
		font-weight: 600;
		white-space: nowrap;

		&--verifierad { --fv-badge-color: var(--hs-status-success); }
		&--fax { --fv-badge-color: var(--hs-status-info, var(--color-primary-element)); }
		&--intern { --fv-badge-color: var(--color-primary-element); }
		&--neutral { --fv-badge-color: var(--color-border); }
		&--removed { --fv-badge-color: var(--hs-status-error); }
	}

	&__badge-text {
		overflow: hidden;
		text-overflow: ellipsis;
	}

	/* Proveniens-rad — diskret, samma stil som ProvenansChip ---------------- */
	&__proveniens {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-size: 0.74rem;
		color: var(--color-text-maxcontrast);

		&--varning {
			color: var(--hs-status-error);
			font-weight: 600;
		}
	}

	&__proveniens-text {
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__chevron {
		flex: 0 0 auto;
		align-self: center;
		color: var(--color-text-maxcontrast);
	}

	/* Tombstone: namn + badges överstrukna, gråtonade ---------------------- */
	&__item--removed {
		.favorit-valjare__namn,
		.favorit-valjare__org {
			text-decoration: line-through;
			color: var(--color-text-maxcontrast);
		}

		.favorit-valjare__rad:hover {
			background: var(--color-main-background);
			border-color: var(--color-border);
		}
	}

	&__empty {
		margin: 16px 8px;
	}

	&__footer {
		display: flex;
		align-items: center;
		gap: 8px;
		padding-top: 12px;
	}

	&__sok {
		margin-inline-end: auto;
	}

	/* Reflow: stapla namn/org och låt badges brytas i smal vy. */
	@media (max-width: 720px) {
		&__rad {
			gap: 8px;
		}

		&__chevron {
			display: none;
		}
	}
}
</style>
