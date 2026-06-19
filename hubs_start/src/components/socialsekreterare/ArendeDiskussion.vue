<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Fliken "Diskussion" i ärendekortets Quick View — den ärende-interna chatten.
  - Medvetet lättviktig trådvy, INTE ett inbäddat chatt-UI: ingen inkorgskänsla,
  - inga olästa-moln. Bara tråden PÅ ärendet + två broar (gör meddelande till
  - handling → human-in-the-loop, eller lyft avidentifierad referens till enhetschatt).
-->
<template>
	<div class="arende-diskussion" :aria-label="t('hubs_start', 'Ärendechatt')">
		<!-- 1) Vem ser tråden + under vilken sekretess -->
		<SekretessRad
			:sekretess="sekretess"
			:deltagare="deltagare" />

		<!-- 1b) #10 — öppna ärenderummets diskussion i sin helhet. Disabled (ej dold)
		     när rummets token ännu saknas, så ytan är ärlig i stället för att hoppa. -->
		<div class="arende-diskussion__rum">
			<NcButton
				type="tertiary"
				class="hs-target"
				:disabled="!talkToken"
				:title="rumTitle"
				:aria-label="rumTitle"
				@click="openRum">
				<template #icon>
					<ForumOutlineIcon :size="18" />
				</template>
				{{ t('hubs_start', 'Öppna diskussionen') }}
			</NcButton>
		</div>

		<!-- 2) Tråden -->
		<ol
			v-if="meddelanden.length"
			class="arende-diskussion__trad"
			:aria-label="t('hubs_start', 'Meddelanden i ärendechatten')">
			<li
				v-for="m in meddelanden"
				:key="m.id"
				class="arende-diskussion__meddelande">
				<p class="arende-diskussion__rubrik">
					<span class="arende-diskussion__fran">{{ m.fran }}</span>
					<span class="arende-diskussion__tid">{{ kortTid(m.tid) }}</span>
				</p>

				<!-- Text med @-omnämnanden som accent-spans (textnoder, aldrig v-html) -->
				<p class="arende-diskussion__text">
					<template v-for="(del, i) in textDelar(m)">
						<span
							v-if="del.mention"
							:key="i"
							class="arende-diskussion__mention">{{ del.text }}</span>
						<template v-else>{{ del.text }}</template>
					</template>
				</p>

				<!-- 3) Gör meddelandet till en handling (mall-förifylld notering) -->
				<div class="arende-diskussion__radaction">
					<NcButton
						type="tertiary"
						class="hs-target"
						:aria-label="t('hubs_start', 'Gör meddelandet från {fran} till en handling', { fran: m.fran })"
						@click="onGorTillHandling(m)">
						<template #icon>
							<ClipboardPlusIcon :size="18" />
						</template>
						{{ t('hubs_start', 'Gör detta till en handling') }}
					</NcButton>
				</div>
			</li>
		</ol>

		<NcEmptyContent
			v-else
			class="arende-diskussion__empty"
			:name="t('hubs_start', 'Inga meddelanden ännu')"
			:description="t('hubs_start', 'Här syns ärende-intern dialog. Skriv det första meddelandet i ärendechatten.')">
			<template #icon>
				<ForumOutlineIcon :size="40" />
			</template>
		</NcEmptyContent>

		<!-- 5) Enkelt skrivfält (demo) -->
		<form class="arende-diskussion__skriv" @submit.prevent="onSkicka">
			<label class="arende-diskussion__sr-only" :for="utkastId">
				{{ t('hubs_start', 'Skriv i ärendechatten') }}
			</label>
			<textarea
				:id="utkastId"
				v-model="utkast"
				class="arende-diskussion__textarea"
				rows="2"
				:placeholder="t('hubs_start', 'Skriv i ärendechatten…')" />
			<NcButton
				type="primary"
				native-type="submit"
				:disabled="!kanSkicka"
				:aria-label="t('hubs_start', 'Skicka meddelande i ärendechatten')">
				<template #icon>
					<SendIcon :size="18" />
				</template>
				{{ t('hubs_start', 'Skicka') }}
			</NcButton>
		</form>

		<!-- 4) Lyft till enhetschatt (avidentifierad referens) -->
		<div class="arende-diskussion__lyft">
			<NcButton
				type="secondary"
				:aria-label="t('hubs_start', 'Lyft avidentifierad referens till enhetschatt')"
				@click="onLyftEnhetschatt">
				<template #icon>
					<AccountGroupIcon :size="18" />
				</template>
				{{ t('hubs_start', 'Lyft till enhetschatt') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

import ClipboardPlusIcon from 'vue-material-design-icons/ClipboardPlusOutline.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import ForumOutlineIcon from 'vue-material-design-icons/ForumOutline.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'

import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import { spreedRoomLink } from '../../services/deepLinks.js'
import SekretessRad from './SekretessRad.vue'

export default {
	name: 'ArendeDiskussion',

	components: {
		NcButton,
		NcEmptyContent,
		ClipboardPlusIcon,
		AccountGroupIcon,
		ForumOutlineIcon,
		SendIcon,
		SekretessRad,
	},

	props: {
		arende: {
			type: Object,
			required: true,
		},
		/**
		 * {
		 *   olasta:Number, omnamnandeTillMig:Boolean,
		 *   deltagare:[{ uid, namn, roll }],
		 *   sekretess:{ kod, niva },
		 *   meddelanden:[{ id, fran, text, tid (ISO), mention:[uid] }]
		 * }
		 */
		diskussion: {
			type: Object,
			default: () => ({}),
		},
		/** #10 — ärenderummets diskussions-token (ur motorns full.pekare.talkToken).
		 * null ⇒ rummet saknas ännu; knappen visas men inaktiverad (aldrig hårdkodad). */
		talkToken: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			utkast: '',
		}
	},

	computed: {
		deltagare() {
			return (this.diskussion && this.diskussion.deltagare) || []
		},

		sekretess() {
			return (this.diskussion && this.diskussion.sekretess) || {}
		},

		meddelanden() {
			return (this.diskussion && this.diskussion.meddelanden) || []
		},

		/** Uppslag uid → visningsnamn, så @-omnämnanden kan visa "@Namn" inte "@uid". */
		namnPerUid() {
			const map = {}
			for (const d of this.deltagare) {
				if (d && d.uid) {
					map[d.uid] = d.namn || d.uid
				}
			}
			return map
		},

		/** Stabilt men unikt id för label↔textarea-koppling (ärendet identifierar fliken). */
		utkastId() {
			const ref = (this.arende && (this.arende.id || this.arende.ref)) || 'arende'
			return 'arende-diskussion-utkast-' + String(ref)
		},

		kanSkicka() {
			return this.utkast.trim().length > 0
		},

		/** Tooltip för diskussionsrums-knappen — ärlig om rummet ännu saknas. */
		rumTitle() {
			return this.talkToken
				? this.t('hubs_start', 'Öppna ärenderummets diskussion')
				: this.t('hubs_start', 'Diskussionsrum saknas ännu')
		},
	},

	methods: {
		t,
		n,

		/** #10 — navigera till ärenderummets diskussion via dess token (aldrig hårdkodad). */
		openRum() {
			const url = spreedRoomLink(this.talkToken)
			if (url) {
				window.location.href = url
			}
		},

		/** Kort sv-SE-tid: "14 jun · 09:12". Tål trasig ISO genom att falla tillbaka. */
		kortTid(iso) {
			if (!iso) {
				return ''
			}
			const d = new Date(iso)
			if (isNaN(d.getTime())) {
				return String(iso)
			}
			return d.toLocaleString('sv-SE', {
				day: 'numeric',
				month: 'short',
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		/**
		 * Delar upp meddelandetexten i text-/omnämnande-segment UTAN v-html.
		 * Vi matchar "@token" i texten; om token (eller dess visningsnamn) hör till
		 * en deltagare i mention-listan renderas det som accent-span, annars som
		 * vanlig text. Returnerar [{ text, mention:Boolean }] — bara textnoder.
		 * @param {object} m meddelandet
		 * @return {Array<{ text: string, mention: boolean }>}
		 */
		textDelar(m) {
			const text = (m && m.text) || ''
			if (!text) {
				return []
			}
			const mentionUids = (m && m.mention) || []
			// Mängd av tillåtna omnämnanden: både uid och visningsnamn (gemener).
			const tillatna = new Set()
			for (const uid of mentionUids) {
				tillatna.add(String(uid).toLowerCase())
				const namn = this.namnPerUid[uid]
				if (namn) {
					tillatna.add(String(namn).toLowerCase())
				}
			}

			const delar = []
			// Fångar "@" följt av namn-/uid-tecken (inkl. svenska, punkt, bindestreck).
			const re = /@[\wåäöÅÄÖ.-]+/g
			let sista = 0
			let match
			while ((match = re.exec(text)) !== null) {
				const traff = match[0]
				const token = traff.slice(1).toLowerCase()
				const arOmnamnande = tillatna.size === 0 ? true : tillatna.has(token)
				if (match.index > sista) {
					delar.push({ text: text.slice(sista, match.index), mention: false })
				}
				delar.push({ text: traff, mention: arOmnamnande })
				sista = match.index + traff.length
			}
			if (sista < text.length) {
				delar.push({ text: text.slice(sista), mention: false })
			}
			return delar.length ? delar : [{ text, mention: false }]
		},

		onGorTillHandling(meddelande) {
			this.$emit('gor-till-handling', { arende: this.arende, meddelande })
		},

		onLyftEnhetschatt() {
			this.$emit('lyft-enhetschatt', { arende: this.arende })
		},

		onSkicka() {
			const text = this.utkast.trim()
			if (!text) {
				return
			}
			this.$emit('skicka', { arende: this.arende, text })
			this.utkast = ''
		},
	},
}
</script>

<style scoped lang="scss">
.arende-diskussion {
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__trad {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__meddelande {
		padding: 8px 10px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);

		&:hover {
			background: var(--color-background-hover);
		}
	}

	&__rubrik {
		display: flex;
		flex-wrap: wrap;
		align-items: baseline;
		gap: 4px 10px;
		margin: 0 0 2px;
		font-size: 0.82rem;
	}

	&__fran {
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__tid {
		color: var(--color-text-maxcontrast);
	}

	&__text {
		margin: 0;
		font-size: 0.9rem;
		color: var(--color-main-text);
		white-space: pre-wrap;
		overflow-wrap: anywhere;
	}

	// Omnämnande: accent-ton, men "@" + namnet bär signalen — inte bara färgen.
	&__mention {
		padding: 0 4px;
		border-radius: var(--border-radius-pill, 16px);
		background: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background));
		color: var(--color-primary-element);
		font-weight: 600;
	}

	&__radaction {
		margin-top: 4px;
	}

	&__empty {
		margin: 4px 0;
	}

	&__skriv {
		display: flex;
		align-items: flex-end;
		gap: 8px;
		padding-top: 4px;
		border-top: 1px solid var(--color-border);
	}

	&__textarea {
		flex: 1 1 auto;
		min-width: 0;
		min-height: 40px;
		padding: 6px 10px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
		color: var(--color-main-text);
		font: inherit;
		resize: vertical;
	}

	&__lyft {
		display: flex;
		justify-content: flex-start;
	}

	&__sr-only {
		position: absolute;
		width: 1px;
		height: 1px;
		padding: 0;
		margin: -1px;
		overflow: hidden;
		clip: rect(0, 0, 0, 0);
		white-space: nowrap;
		border: 0;
	}

	// Reflow @720px: skrivfältet staplar (textarea över knapp), ingen sidoscroll.
	@media (max-width: 720px) {
		&__skriv {
			flex-wrap: wrap;
		}

		&__skriv .arende-diskussion__textarea {
			width: 100%;
			flex-basis: 100%;
		}
	}
}
</style>
