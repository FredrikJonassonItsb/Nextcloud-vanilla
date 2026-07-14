<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Skapa handling från mall (tvåstegs-modal). Steg 1: välj mall ur mallbiblioteket
  - (grupperat per mapp). Steg 2: granska/komplettera de förifyllda fälten ur
  - utkastet — varje fält visar sin källa, och skyddsgrindade fält visar en
  - VARNING i stället för värde: användaren SER varför fältet är tomt, och att
  - fylla i det är ett aktivt beslut. Tomma fält blir tomma skrivytor i blanketten.
  - Oersatta fält och skyddsval journalförs i motorn. Föräldern gör API-anropet.
-->
<template>
	<NcModal
		:show="true"
		:name="t('hubs_start', 'Skapa handling från mall')"
		size="normal"
		:can-close="!isRunning"
		@close="$emit('close')">
		<div class="handling-modal">
			<!-- Ladd-/felstate för hämtningarna -->
			<p v-if="laddar" class="handling-modal__muted handling-modal__status">
				<NcLoadingIcon :size="16" /> {{ t('hubs_start', 'Hämtar mallar och förifyllda uppgifter …') }}
			</p>
			<p v-else-if="felVidLaddning" class="handling-modal__muted handling-modal__status">
				{{ t('hubs_start', 'Mallarna kunde inte hämtas just nu. Stäng och försök igen.') }}
			</p>

			<!-- STEG 1: välj mall -->
			<template v-else-if="steg === 1">
				<p class="handling-modal__lead">
					<FileDocumentPlusIcon :size="18" />
					<span>{{ t('hubs_start', 'Handlingen skapas i ärendets dokumentyta med uppgifter förifyllda ur ärendet.') }}</span>
				</p>

				<p v-if="!tillganglig" class="handling-modal__muted handling-modal__status">
					{{ t('hubs_start', 'Mallbiblioteket är inte tillgängligt') }}
				</p>
				<p v-else-if="!mallar.length" class="handling-modal__muted handling-modal__status">
					{{ t('hubs_start', 'Inga mallar finns i mallbiblioteket ännu.') }}
				</p>

				<!-- A10 — mallarna grupperade efter RELEVANS för det aktuella steget, inte
				     platt per mapp: "För detta steg" (mallar vars artefakt-klass matchar
				     stegets delmoment) först, sedan tvärgående stöd ("Används ofta här"),
				     och till sist ALLA övriga i en hopfälld men FULLT nåbar <details> —
				     icke-linjärt arbete är legitimt, inget döljs. -->
				<div class="handling-modal__mallista" role="radiogroup" :aria-label="t('hubs_start', 'Välj mall')">
					<!-- För detta steg -->
					<template v-if="mallForDettaSteg.length">
						<p class="handling-modal__mapp handling-modal__mapp--primar">
							{{ stegLabel ? t('hubs_start', 'För detta steg — {steg}', { steg: stegLabel }) : t('hubs_start', 'För detta steg') }}
						</p>
						<label
							v-for="mall in mallForDettaSteg"
							:key="mall.id"
							class="handling-modal__mall"
							:class="{ 'handling-modal__mall--vald': valdMallId === mall.id }">
							<input
								v-model="valdMallId"
								class="handling-modal__mall-radio"
								type="radio"
								name="handling-mall"
								:value="mall.id">
							<span class="handling-modal__mall-namn">{{ mall.namn }}</span>
						</label>
					</template>

					<!-- Används ofta här (tvärgående stöd) -->
					<template v-if="mallAnvandsOfta.length">
						<p class="handling-modal__mapp">
							{{ t('hubs_start', 'Används ofta här') }}
						</p>
						<label
							v-for="mall in mallAnvandsOfta"
							:key="mall.id"
							class="handling-modal__mall"
							:class="{ 'handling-modal__mall--vald': valdMallId === mall.id }">
							<input
								v-model="valdMallId"
								class="handling-modal__mall-radio"
								type="radio"
								name="handling-mall"
								:value="mall.id">
							<span class="handling-modal__mall-namn">{{ mall.namn }}</span>
						</label>
					</template>

					<!-- Andra steg — hopfälld men FULLT nåbar (aldrig dold). Öppnas
					     automatiskt om inga steg-relevanta mallar kunde härledas, så listan
					     aldrig ser tom ut. -->
					<details v-if="mallAndraSteg.length" class="handling-modal__andra" :open="!mallForDettaSteg.length && !mallAnvandsOfta.length">
						<summary class="handling-modal__andra-sum">
							{{ t('hubs_start', 'Andra steg ({n})', { n: mallAndraSteg.length }) }}
						</summary>
						<label
							v-for="mall in mallAndraSteg"
							:key="mall.id"
							class="handling-modal__mall"
							:class="{ 'handling-modal__mall--vald': valdMallId === mall.id }">
							<input
								v-model="valdMallId"
								class="handling-modal__mall-radio"
								type="radio"
								name="handling-mall"
								:value="mall.id">
							<span class="handling-modal__mall-namn">{{ mall.namn }}</span>
							<span v-if="mall.mapp" class="handling-modal__mall-mapp">{{ mall.mapp }}</span>
						</label>
					</details>
				</div>

				<div class="handling-modal__footer">
					<NcButton :disabled="isRunning" @click="$emit('close')">
						{{ t('hubs_start', 'Avbryt') }}
					</NcButton>
					<NcButton
						type="primary"
						:disabled="!tillganglig || !valdMallId || laddar"
						@click="gaTillGranskning">
						{{ t('hubs_start', 'Nästa') }}
					</NcButton>
				</div>
			</template>

			<!-- STEG 2: granska/komplettera förifyllda fält -->
			<template v-else>
				<div class="handling-modal__vald-mall">
					<span class="handling-modal__muted">
						{{ t('hubs_start', 'Mall: {namn}', { namn: valdMall ? valdMall.namn : '' }) }}
					</span>
					<button
						class="handling-modal__byt-mall"
						type="button"
						:disabled="isRunning"
						@click="steg = 1">
						{{ t('hubs_start', '‹ Byt mall') }}
					</button>
				</div>

				<div class="handling-modal__falt-lista">
					<div v-for="falt in falten" :key="falt.nyckel" class="handling-modal__falt">
						<label class="handling-modal__label" :for="'handling-falt-' + falt.nyckel">
							{{ falt.etikett }}
						</label>
						<!-- AI-utkast uteblev (nekan/onåbar/ej konfigurerat): förklaring
						     utan input, så orsaken SYNS i st.f. att fältet tyst försvinner. -->
						<div v-if="falt.kalla === 'ai_narrativ_info'" class="handling-modal__ai-info">
							<InformationOutlineIcon :size="16" />
							<span>{{ falt.varning }}</span>
						</div>
						<!-- Källförankrat AI-narrativ (flerradigt) redigeras i en textarea;
						     att granska/redigera det HÄR är människa-i-loopen-gränsen innan
						     handlingen skapas. Övriga (metadata)fält är enradiga inputs. -->
						<textarea
							v-else-if="falt.kalla === 'ai_narrativ'"
							:id="'handling-falt-' + falt.nyckel"
							v-model="lokalaVarden[falt.nyckel]"
							class="handling-modal__input handling-modal__narrativ"
							rows="14"
							:disabled="isRunning" />
						<input
							v-else
							:id="'handling-falt-' + falt.nyckel"
							v-model="lokalaVarden[falt.nyckel]"
							class="handling-modal__input"
							type="text"
							:disabled="isRunning">
						<div v-if="falt.kalla !== 'ai_narrativ_info'" class="handling-modal__falt-meta">
							<span v-if="falt.kalla" class="handling-modal__chip">{{ kallaLabel(falt.kalla) }}</span>
							<span v-if="falt.varning" class="handling-modal__varning">
								<ShieldAlertIcon :size="14" />
								{{ falt.varning }}
							</span>
						</div>
					</div>
				</div>

				<p class="handling-modal__muted handling-modal__info">
					{{ t('hubs_start', 'Fält som lämnas tomma blir tomma skrivytor i blanketten — du fyller i resten i dokumentet. Oersatta fält och skyddsval journalförs.') }}
				</p>

				<div class="handling-modal__footer">
					<NcButton :disabled="isRunning" @click="$emit('close')">
						{{ t('hubs_start', 'Avbryt') }}
					</NcButton>
					<NcButton
						type="primary"
						:disabled="isRunning"
						@click="onSkapa">
						<template #icon>
							<NcLoadingIcon v-if="isRunning" :size="18" />
							<FileDocumentPlusIcon v-else :size="18" />
						</template>
						{{ t('hubs_start', 'Skapa handling') }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import FileDocumentPlusIcon from 'vue-material-design-icons/FileDocumentPlus.vue'
import ShieldAlertIcon from 'vue-material-design-icons/ShieldAlert.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'

import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'

import { fetchArendeMallar, fetchHandlingUtkast } from '../../services/api.js'
import { STEG_INNEHALL } from '../../services/arendeFlow.js'

/**
 * A10 — semantisk artefakt-klass → nyckelord (skiftlägesokänslig substring) som
 * identifierar klassen i ett mall-id/namn. SPEGLAR EvidensService.KLASS_NYCKELORD
 * (hubs_arende) så steppern, backend-grinden och den här mall-grupperingen aldrig
 * divergerar. Extraröster (samtycke/kallelse/begäran m.fl.) täcker de tvärgående
 * stödmallar som inte är egna delmoment i STEG_INNEHALL men ändå används ofta.
 *
 * Matchningen sker mot en FOLDAD stam (å→a, ä→a, ö→o) — samma translitterering
 * som HandlingService.mallBasnamn() gör innan mallnamnet journalförs — så de
 * folddade nyckelorden (t.ex. 'skyddsbedom') träffar även råa mallnamn med ö.
 */
const KLASS_NYCKELORD = {
	'mottagen-orosanmalan': ['orosanmalan', 'mottagen'],
	skyddsbedomning: ['skyddsbedom'],
	forhandsbedomning: ['forhandsbedom'],
	utredningsplan: ['utredningsplan'],
	'bbic-utredning': ['bbic', 'barnavardsutredning'],
	barnsamtal: ['barnsamtal', 'barnets-installning', 'barnets-rost', 'delaktighet'],
	kommunicering: ['kommunicer'],
	journalanteckning: ['journal'],
	genomforandeplan: ['genomforande'],
	avslutsanteckning: ['avslut'],
	// Avsiktligt SPECIFIKT ('beslut-om-bistand', inte bara 'beslut') så att t.ex.
	// "Förhandsbedömning och beslut att inleda …" inte felaktigt fångas i beslut-steget.
	beslut: ['beslut-om-bistand', 'beslut-bistand', 'bistand-eller-insats'],
	delgivning: ['underrattelse', 'overklagande', 'delgivning'],
	// Tvärgående stöd (ingen egen delmoment-klass men egna mallar i biblioteket).
	samtycke: ['samtycke'],
	kallelse: ['kallelse'],
	begaran: ['begaran-om-uppgifter', 'begaran'],
	vardplan: ['vardplan'],
	sip: ['sip', 'samordnad-individuell'],
	motesanteckning: ['samtals-och-motesanteckning', 'motesanteckning'],
}

/** Tvärgående stöd-klasser som visas under "Används ofta här" i ALLA steg. */
const STOD_KLASSER = ['journalanteckning', 'samtycke', 'kallelse', 'begaran', 'motesanteckning', 'vardplan', 'sip']

/**
 * Folda + slugga ett mall-id/namn till samma stam som journalen bär (mirror av
 * HandlingService.mallBasnamn): gemener, svenska tecken translittererade, allt
 * utanför [a-z0-9] → '-'. Ger stabil substring-matchning mot nyckelorden.
 * @param {string} s
 * @return {string}
 */
function foldaStam(s) {
	return String(s || '')
		.toLowerCase()
		.replace(/[åä]/g, 'a')
		.replace(/ö/g, 'o')
		.replace(/é/g, 'e')
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
}

export default {
	name: 'HandlingModal',

	components: { NcModal, NcButton, NcLoadingIcon, FileDocumentPlusIcon, ShieldAlertIcon, InformationOutlineIcon },

	props: {
		arende: {
			type: Object,
			required: true,
		},
		isRunning: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			steg: 1,
			laddar: false,
			felVidLaddning: false,
			// Mallbiblioteket (steg 1)
			tillganglig: false,
			mallar: [],
			valdMallId: null,
			// Utkastet (steg 2): fälten ur motorn + användarens redigerbara värden.
			falten: [],
			lokalaVarden: {},
		}
	},

	computed: {
		/** Husets referens-mönster: hubsCaseId ?? dnr ?? triageRef. */
		ref() {
			return this.arende.hubsCaseId || this.arende.dnr || this.arende.triageRef
		},

		/** Ärendets aktuella steg (för A10-relevansgrupperingen). */
		arendeSteg() {
			return (this.arende && this.arende.steg) || null
		},

		/** Svensk etikett för aktuellt steg (ur STEG_INNEHALL) — aldrig rå token. */
		stegLabel() {
			const modell = this.arendeSteg && STEG_INNEHALL[this.arendeSteg]
			return modell ? modell.label : ''
		},

		/**
		 * De artefakt-klasser som aktuellt steg producerar (ur STEG_INNEHALL[steg]
		 * .delmoment[].artefakt). Dessa driver "För detta steg"-matchningen.
		 * @return {string[]}
		 */
		stegKlasser() {
			const modell = this.arendeSteg && STEG_INNEHALL[this.arendeSteg]
			if (!modell) {
				return []
			}
			return modell.delmoment
				.map((d) => d.artefakt)
				.filter((k) => !!k)
		},

		/** Mall + folddad stam (beräknas en gång per mall). */
		mallarMedStam() {
			return this.mallar.map((m) => ({ ...m, _stam: foldaStam(m.id || m.namn) }))
		},

		/**
		 * A10 — "För detta steg": mallar vars folddade stam matchar något nyckelord
		 * för någon av stegets artefakt-klasser. Icke-tvärgående stödmallar hamnar
		 * aldrig här (de bor under "Används ofta här").
		 */
		mallForDettaSteg() {
			const klasser = this.stegKlasser.filter((k) => !STOD_KLASSER.includes(k))
			if (!klasser.length) {
				return []
			}
			const nyckelord = this.nyckelordForKlasser(klasser)
			return this.mallarMedStam.filter((m) => this.stamMatchar(m._stam, nyckelord))
		},

		/**
		 * A10 — "Används ofta här": tvärgående stödmallar (journal, samtycke, kallelse,
		 * m.fl.), minus det som redan visas under "För detta steg".
		 */
		mallAnvandsOfta() {
			const primaraIds = new Set(this.mallForDettaSteg.map((m) => m.id))
			const nyckelord = this.nyckelordForKlasser(STOD_KLASSER)
			return this.mallarMedStam.filter((m) =>
				!primaraIds.has(m.id) && this.stamMatchar(m._stam, nyckelord))
		},

		/**
		 * A10 — "Andra steg": allt som varken är steg-relevant eller tvärgående stöd.
		 * Hopfälld i template men FULLT nåbar — icke-linjärt arbete är legitimt.
		 */
		mallAndraSteg() {
			const visade = new Set([
				...this.mallForDettaSteg.map((m) => m.id),
				...this.mallAnvandsOfta.map((m) => m.id),
			])
			return this.mallarMedStam.filter((m) => !visade.has(m.id))
		},

		valdMall() {
			return this.mallar.find((m) => m.id === this.valdMallId) || null
		},
	},

	mounted() {
		this.ladda()
	},

	methods: {
		t,

		/** Hämta mallbiblioteket och utkastet parallellt. */
		async ladda() {
			if (!this.ref) {
				return
			}
			this.laddar = true
			this.felVidLaddning = false
			try {
				const [mallarSvar, utkast] = await Promise.all([
					fetchArendeMallar(this.ref),
					fetchHandlingUtkast(this.ref),
				])
				this.tillganglig = !!(mallarSvar && mallarSvar.tillganglig)
				this.mallar = (mallarSvar && mallarSvar.mallar) || []
				this.falten = (utkast && utkast.falt) || []
				// Redigerbara kopior — motorns utkast lämnas orört.
				const lokala = {}
				for (const falt of this.falten) {
					lokala[falt.nyckel] = falt.varde || ''
				}
				this.lokalaVarden = lokala
			} catch (e) {
				this.felVidLaddning = true
				showError(this.motorFel(e, t('hubs_start', 'Mallarna kunde inte hämtas. Försök igen.')))
			} finally {
				this.laddar = false
			}
		},

		/**
		 * S4: steg 1 → 2 hämtar OM utkastet filtrerat per vald mall
		 * (malldefinitionen) — dialogen visar bara fält mallen faktiskt har.
		 * Användarens redan ifyllda värden behålls för fält som överlever
		 * filtreringen. Fel ⇒ behåll det ofiltrerade utkastet (ärligt övervisande).
		 */
		async gaTillGranskning() {
			if (!this.valdMallId) {
				return
			}
			this.laddar = true
			try {
				const utkast = await fetchHandlingUtkast(this.ref, this.valdMallId)
				if (utkast && Array.isArray(utkast.falt)) {
					this.falten = utkast.falt
					const lokala = {}
					for (const falt of this.falten) {
						lokala[falt.nyckel] = (this.lokalaVarden[falt.nyckel] !== undefined && this.lokalaVarden[falt.nyckel] !== '')
							? this.lokalaVarden[falt.nyckel]
							: (falt.varde || '')
					}
					this.lokalaVarden = lokala
				}
			} catch (e) {
				// Ofiltrerat utkast från mounted() står kvar — hellre fler fält än stopp.
			} finally {
				this.laddar = false
				this.steg = 2
			}
		},

		onSkapa() {
			if (this.isRunning || !this.valdMall) {
				return
			}
			// Endast fält där användaren har/lämnat ett icke-tomt värde — tomma
			// fält blir tomma skrivytor i blanketten (journalförs som oersatta i motorn).
			const falt = {}
			for (const f of this.falten) {
				const varde = String(this.lokalaVarden[f.nyckel] || '').trim()
				if (varde !== '') {
					falt[f.nyckel] = varde
				}
			}
			this.$emit('skapa', {
				mallId: this.valdMall.id,
				falt,
				mallNamn: this.valdMall.namn,
			})
		},

		kallaLabel(kalla) {
			const map = {
				register: t('hubs_start', 'Register'),
				partsregister: t('hubs_start', 'Partsregistret'),
					ai_narrativ: t('hubs_start', 'AI-utkast — källförankrat'),
				anvandare: t('hubs_start', 'Användare'),
			}
			return map[kalla] || kalla
		},

		/**
		 * A10 — samla nyckelorden (folddade substrings) för en uppsättning
		 * artefakt-klasser. Okända klasser bidrar med klassnamnet självt som
		 * fallback (samma disciplin som EvidensService.harArtefakt).
		 * @param {string[]} klasser
		 * @return {string[]}
		 */
		nyckelordForKlasser(klasser) {
			const ord = []
			for (const k of klasser) {
				const kws = KLASS_NYCKELORD[k] || [k]
				for (const kw of kws) {
					ord.push(foldaStam(kw))
				}
			}
			return ord
		},

		/** Matchar en folddad mall-stam något av nyckelorden (substring)? */
		stamMatchar(stam, nyckelord) {
			return nyckelord.some((kw) => kw && stam.includes(kw))
		},

		/** Motorns felorsak ur ett OCS-fel (husets mönster). */
		motorFel(e, fallback) {
			const d = e && e.response && e.response.data
			const orsak = (d && d.ocs && d.ocs.data && d.ocs.data.error) || (d && d.error) || null
			return orsak || fallback
		},
	},
}
</script>

<style scoped lang="scss">
.handling-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;

	&__lead {
		display: flex;
		gap: 8px;
		align-items: flex-start;
		color: var(--color-text-maxcontrast);
	}

	&__muted {
		color: var(--color-text-maxcontrast);
	}

	&__status {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: 0;
	}

	&__mallista {
		display: flex;
		flex-direction: column;
		gap: 2px;
		max-height: 320px;
		overflow-y: auto;
	}

	&__mapp {
		margin: 8px 0 2px;
		font-size: 0.8rem;
		font-weight: 700;
		color: var(--color-text-maxcontrast);

		&:first-child {
			margin-top: 0;
		}

		// A10 — "För detta steg" lyfts fram (primärfärg) som den rekommenderade ingången.
		&--primar {
			color: var(--color-primary-element);
		}
	}

	// A10 — "Andra steg": hopfälld men fullt nåbar. summary bär samma vikt som en
	// mapp-rubrik; innehållet är identiska mall-rader.
	&__andra {
		margin-top: 8px;

		&-sum {
			cursor: pointer;
			padding: 4px 0;
			font-size: 0.8rem;
			font-weight: 700;
			color: var(--color-text-maxcontrast);
			list-style: revert; // behåll den inbyggda triangeln (fullt nåbar affordance)

			&:hover,
			&:focus-visible {
				color: var(--color-main-text);
			}
		}
	}

	// Mappens namn på en "Andra steg"-rad (kontext utan att stjäla fokus).
	&__mall-mapp {
		margin-left: auto;
		font-size: 0.72rem;
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}

	&__mall {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 6px 8px;
		border-radius: var(--border-radius);
		cursor: pointer;

		&:hover,
		&:focus-within {
			background: var(--color-background-hover);
		}

		&--vald {
			background: var(--color-primary-element-light, var(--color-background-hover));
		}
	}

	&__mall-radio {
		margin: 0;
		flex-shrink: 0;
	}

	&__mall-namn {
		font-weight: 600;
	}

	&__vald-mall {
		display: flex;
		flex-wrap: wrap;
		align-items: baseline;
		gap: 10px;
	}

	&__byt-mall {
		background: none;
		border: none;
		padding: 0;
		font: inherit;
		font-size: 0.85rem;
		font-weight: 600;
		color: var(--color-primary-element);
		cursor: pointer;

		&:hover,
		&:focus-visible {
			text-decoration: underline;
		}

		&:disabled {
			opacity: 0.6;
			cursor: default;
			text-decoration: none;
		}
	}

	&__falt-lista {
		display: flex;
		flex-direction: column;
		gap: 12px;
		max-height: 380px;
		overflow-y: auto;
	}

	&__falt {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__label {
		font-weight: bold;
	}

	&__input {
		width: 100%;
	}

	&__falt-meta {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
	}

	&__chip {
		font-size: 0.72rem;
		font-weight: 600;
		padding: 1px 8px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}

	// Skyddsgrinden: varför fältet är tomt — att fylla i det är ett aktivt beslut.
	&__varning {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-size: 0.8rem;
		font-weight: 600;
		color: var(--hs-status-warning-text, #7f5900);
	}

	&__ai-info {
		display: flex;
		align-items: flex-start;
		gap: 6px;
		padding: 8px 10px;
		border-radius: var(--border-radius, 8px);
		background: var(--color-background-hover);
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
		line-height: 1.4;
	}

	&__info {
		margin: 0;
		font-size: 0.85em;
	}

	&__footer {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: 8px;
	}
}
</style>
