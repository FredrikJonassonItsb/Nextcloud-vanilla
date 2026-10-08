<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Vyns kärnkomponent: ETT ärende i alla processteg. Stepper + frist + provenans
  - + EN lysande Nästa-åtgärd. Expanderar till Quick View (flikar) utan sidbyte.
  - Pliktmarkör (röd) spärrar stepper-framsteg tills plikten committas.
-->
<template>
	<article
		:id="'arende-' + (arende.triageRef || arende.hubsCaseId)"
		class="arende-kort hs-card"
		:class="{ 'arende-kort--het': pinned, 'arende-kort--plikt': pliktAktiv, 'arende-kort--markerad': markerad }">
		<!-- Pliktmarkör: röd när skyddsbedömningen saknas … -->
		<p v-if="pliktAktiv" class="arende-kort__plikt">
			<AlertOctagonIcon :size="16" />
			{{ arende.plikt.label }}
		</p>
		<!-- … och en dämpad GRÖN BEKRÄFTELSE när den är dokumenterad (T4/F5):
		     markören försvinner inte längre tyst, den bekräftar "klar". -->
		<p v-else-if="pliktBekraftad" class="arende-kort__plikt-ok">
			<CheckCircleIcon :size="15" />
			{{ t('hubs_start', 'Skyddsbedömning dokumenterad') }}
		</p>

		<!-- Titel + sekretess/LOA -->
		<header class="arende-kort__head">
			<button class="arende-kort__titel" type="button" @click="toggleExpand">
				<ChevronRightIcon :size="18" class="arende-kort__chev" :class="{ 'arende-kort__chev--open': isExpanded }" />
				<!-- Rubriken har ALLTID en referens: 'Ärende {kort6}' — ett oregistrerat
				     ärende utan barnRef/dnr fick tidigare en TOM rubrik. -->
				<span>{{ kortTitel }}<span v-if="arende.dnr" class="arende-kort__dnr"> · dnr {{ arende.dnr }}</span></span>
			</button>
			<span class="arende-kort__badges">
				<!-- Ägarskap synligt + självbetjäning: en behörig handläggare kan ta ett
				     otilldelat ärende direkt från kortet (motorns tilldela-API + handoff). -->
				<span v-if="arOtilldelat" class="arende-kort__otilldelad">{{ t('hubs_start', 'Otilldelad') }}</span>
				<NcButton v-if="arOtilldelat" type="secondary" :disabled="tarArendet" @click="taArendet">
					{{ t('hubs_start', 'Ta ärendet') }}
				</NcButton>
				<span v-if="arende.sekretess && arende.sekretess.kod" class="arende-kort__sekretess">{{ arende.sekretess.kod }}</span>
				<span v-if="arende.loa" class="arende-kort__loa">{{ arende.loa }}</span>
			</span>
		</header>

		<!-- Processteg. Hela ärendet skickas (A1/A5) så steppern kan härleda
		     nod-states + rik hover ur STEG_INNEHALL/stegNodState + evidensen. -->
		<ProcessStepper
			:arende="arende"
			:steg="arende.steg"
			:substeg="arende.substeg"
			:plikt="arende.plikt"
			@goto-flik="onStepperGoto" />

		<!-- Frist + provenans + ärendechatt-chip -->
		<div class="arende-kort__meta">
			<FristChip :frist="arende.frist" />
			<ProvenansChip :provenance="arende.provenance" @commit="emitCommit" />
			<DiskussionChip
				v-if="arende.diskussion"
				:diskussion="arende.diskussion"
				@open-diskussion="openDiskussion" />
		</div>

		<!-- Tilldelning + ursprung (diskret rad, aldrig ett eget kort) -->
		<TilldelningBand
			v-if="arende.tilldelning"
			:tilldelning="arende.tilldelning"
			@omfordela-begaran="$emit('omfordela-begaran', arende)" />

		<!-- Provenanskedja (utökar provenans-raden till ett band) -->
		<p v-if="full.provenansKedja && full.provenansKedja.length" class="arende-kort__kedja">
			<span v-for="(led, i) in full.provenansKedja" :key="i" class="arende-kort__kedja-led">
				<ChevronRightIcon v-if="i" :size="13" class="arende-kort__kedja-pil" />{{ led }}
			</span>
		</p>

		<!-- Nästa åtgärd -->
		<div class="arende-kort__nasta">
			<span class="arende-kort__nasta-label">{{ t('hubs_start', 'Nästa åtgärd:') }}</span>
			<NastaAtgardKnapp
				:arende="arende"
				@nasta-atgard="$emit('nasta-atgard', arende)"
				@action="onMenuAction" />
		</div>

		<!-- Modulrad: ÖPPNA ärendets moduler (Team först — den samlade vyn).
		     Åtgärder (skicka/boka/bevaka) bor i Nästa åtgärd-menyn, inte här. -->
		<div class="arende-kort__quick">
			<button v-for="q in moduler" :key="q.key" class="arende-kort__q hs-target" type="button" :title="q.label" @click="openModul(q.key)">
				<component :is="iconFor(q.icon)" :size="18" />
				<span class="arende-kort__q-txt">{{ q.short }}</span>
			</button>
		</div>

		<!-- Flikar: ärendets innehåll — ALLTID synliga (med räknare); innehållet
		     lazy-laddas när fliken öppnas. Ingen expand-klick för att se ATT något finns. -->
		<section class="arende-kort__expand">
			<div class="arende-kort__tabs" role="tablist">
				<button
					v-for="f in flikar"
					:key="f.id"
					class="arende-kort__tab hs-target"
					:class="{ 'arende-kort__tab--active': isExpanded && activeFlik === f.id }"
					role="tab"
					:aria-selected="String(isExpanded && activeFlik === f.id)"
					@click="openFlik(f.id)">
					{{ f.label }}<span v-if="f.antal !== null" class="arende-kort__tab-antal">{{ f.antal }}</span>
				</button>
			</div>

			<div v-if="isExpanded" class="arende-kort__flik">
				<!-- Akten (ärenderummets dokument) — filerna är KLICKBARA.
				     Meddelandereferenser (msg-*.url) visas INTE här: meddelanden hör
				     hemma under fliken Meddelanden (aktenDokument filtrerar bort dem). -->
				<ul v-if="activeFlik === 'dokument'" class="arende-kort__list">
					<li v-for="(d, i) in aktenDokument" :key="'f' + i" :title="dokTitle(d)">
						<a class="arende-kort__fillank" :href="fileHref(d)"><FileDocumentIcon :size="16" /> {{ dokNamn(d) }}</a>
					</li>
					<li v-if="!aktenDokument.length" class="arende-kort__muted">{{ t('hubs_start', 'Inga dokument än.') }}</li>
				</ul>

				<!-- Meddelanden: ALLA kopplade meddelanden, alla kanaler (case:-taggen) -->
				<ul v-else-if="activeFlik === 'meddelanden'" class="arende-kort__list">
					<li v-if="tabLaddar.meddelanden" class="arende-kort__muted">{{ t('hubs_start', 'Hämtar meddelanden…') }}</li>
					<li v-for="(m, i) in kopplladeMeddelanden" :key="'cm' + i">
						<a class="arende-kort__fillank" :href="meddelandeHref(m)">
							<MessageTextLockIcon :size="16" /> {{ m.amne || m.titel }}
						</a>
						<span class="arende-kort__muted"> · {{ m.kanal && m.kanal.label ? m.kanal.label : (m.status || '') }}<template v-if="m.inkom"> · {{ fmtTime(m.inkom) }}</template></span>
						<span v-if="m.olast" class="arende-kort__olast">{{ t('hubs_start', 'oläst') }}</span>
					</li>
					<li v-if="!tabLaddar.meddelanden && !kopplladeMeddelanden.length" class="arende-kort__muted">{{ t('hubs_start', 'Inga kopplade meddelanden än.') }}</li>
				</ul>

				<!-- Rum: ALLA ärendets chattrum (som inte är bokade möten) + Ny chatt -->
				<div v-else-if="activeFlik === 'rum'">
					<ul class="arende-kort__list">
						<li v-for="(r, i) in talkRooms" :key="r.token">
							<a class="arende-kort__fillank" :href="rumHref(r)">
								<ForumIcon :size="16" /> {{ rumLabel(r, i) }}
							</a>
						</li>
						<li v-if="!talkRooms.length" class="arende-kort__muted">{{ t('hubs_start', 'Ärendet saknar chattrum.') }}</li>
					</ul>
					<NcButton type="secondary" @click="$emit('ny-chatt', arende)">
						<template #icon><ForumIcon :size="18" /></template>
						{{ t('hubs_start', 'Ny chatt i ärendet') }}
					</NcButton>
				</div>

				<!-- Möten: kommande + genomförda (dnr-märkta bokningar) + boka nytt -->
				<div v-else-if="activeFlik === 'moten'">
					<p v-if="tabLaddar.moten" class="arende-kort__muted">{{ t('hubs_start', 'Hämtar möten…') }}</p>
					<template v-if="moten.kommande.length">
						<p class="arende-kort__flikrubrik">{{ t('hubs_start', 'Kommande') }}</p>
						<ul class="arende-kort__list">
							<li v-for="(mo, i) in moten.kommande" :key="'k' + i">
								<VideoIcon :size="16" /> {{ mo.titel || t('hubs_start', 'Säkert möte') }}
								<span class="arende-kort__muted"> · {{ fmtTime(mo.start) }}</span>
								<a v-if="mo.callUrl" class="arende-kort__fillank" :href="mo.callUrl"> {{ t('hubs_start', 'Öppna mötesrummet') }}</a>
							</li>
						</ul>
					</template>
					<template v-if="moten.genomforda.length">
						<p class="arende-kort__flikrubrik">{{ t('hubs_start', 'Genomförda') }}</p>
						<ul class="arende-kort__list">
							<li v-for="(mo, i) in moten.genomforda" :key="'g' + i">
								<VideoIcon :size="16" /> {{ mo.titel || t('hubs_start', 'Säkert möte') }}
								<span class="arende-kort__muted"> · {{ fmtTime(mo.start) }}</span>
								<a v-if="mo.callUrl" class="arende-kort__fillank" :href="mo.callUrl"> {{ t('hubs_start', 'Öppna mötets chatt') }}</a>
							</li>
						</ul>
					</template>
					<!-- Demo-fixture-flödet (transkript/godkänn) behålls när det finns. -->
					<div v-for="(mo, i) in full.moten || []" :key="'fx' + i" class="arende-kort__mote">
						<p class="arende-kort__mote-titel"><VideoIcon :size="16" /> {{ mo.titel }} <span class="arende-kort__muted">· {{ fmtTime(mo.start) }}</span></p>
						<div v-if="mo.transkript && !mo.godkand" class="arende-kort__godkann">
							<div class="arende-kort__sbs">
								<div><strong>{{ t('hubs_start', 'Transkript (lokalt)') }}</strong><p>{{ mo.transkript }}</p></div>
								<div><strong>{{ t('hubs_start', 'AI-utkast (lokalt)') }}</strong><p>{{ mo.aiUtkast }}</p></div>
							</div>
							<NcButton type="primary" @click="$emit('godkann', arende, mo)">
								<template #icon><CheckCircleIcon :size="18" /></template>
								{{ t('hubs_start', 'Granska & godkänn → för till Treserva') }}
							</NcButton>
						</div>
						<p v-else-if="mo.godkand" class="arende-kort__muted"><CheckCircleIcon :size="14" /> {{ t('hubs_start', 'Anteckning godkänd och sparad.') }}</p>
					</div>
					<p v-if="!tabLaddar.moten && !moten.kommande.length && !moten.genomforda.length && !(full.moten && full.moten.length)" class="arende-kort__muted">{{ t('hubs_start', 'Inga möten kopplade.') }}</p>
					<NcButton type="secondary" @click="$emit('boka-mote', arende)">
						<template #icon><VideoIcon :size="18" /></template>
						{{ t('hubs_start', 'Boka säkert möte') }}
					</NcButton>
				</div>

				<!-- Bevakningar: förstaklassiga register-rader (motorns bevaknings-register).
				     Varje rad har egen livscykel (aktiv→uppnadd|passerad|avbruten), villkor,
				     frist och taggar. Lagstadgade/passerade läses som brådskande (rött). -->
				<div v-else-if="activeFlik === 'bevakningar'" class="arende-kort__flik-body">
					<p v-if="tabLaddar.bevakningar" class="arende-kort__muted">{{ t('hubs_start', 'Hämtar bevakningar…') }}</p>
					<div
						v-for="b in bevakningar"
						:key="'bv' + b.id"
						class="arende-kort__bevakning"
						:class="{ 'arende-kort__bevakning--bradskande': arBradskande(b) }">
						<p class="arende-kort__bevakning-titel">
							<BellRingIcon :size="16" /> <strong>{{ b.titel }}</strong>
							<span class="arende-kort__chip" :class="statusKlass(b.status)">{{ statusLabel(b.status) }}</span>
							<span v-if="b.lagstadgad" class="arende-kort__chip arende-kort__chip--rod">{{ t('hubs_start', 'Lagstadgad') }}</span>
							<span v-if="b.recurringDagar" class="arende-kort__chip arende-kort__chip--neutral">{{ t('hubs_start', 'Återkommande') }}</span>
							<span v-if="b.forsenad" class="arende-kort__chip arende-kort__chip--rod">{{ t('hubs_start', 'Försenad') }}</span>
						</p>
						<p class="arende-kort__bevakning-meta arende-kort__muted">
							<span>{{ villkorLabel(b.villkorTyp) }}</span>
							<template v-if="b.fristDue"><span> · {{ t('hubs_start', 'frist') }} {{ fmtDatum(b.fristDue) }} ({{ relativFrist(b.fristDue) }})</span></template>
						</p>
						<div v-if="b.kanKvittera || b.status === 'aktiv'" class="arende-kort__bevakning-actions">
							<NcButton
								v-if="b.kanKvittera"
								type="secondary"
								:disabled="bevakningPending.includes(b.id)"
								@click="kvitteraBevak(b)">
								<template #icon><CheckCircleIcon :size="16" /></template>
								{{ t('hubs_start', 'Kvittera klar') }}
							</NcButton>
							<NcButton
								v-if="b.status === 'aktiv'"
								type="tertiary"
								:disabled="bevakningPending.includes(b.id)"
								@click="avbrytBevak(b)">
								{{ t('hubs_start', 'Ta bort') }}
							</NcButton>
						</div>
					</div>
					<p v-if="!tabLaddar.bevakningar && !bevakningar.length" class="arende-kort__muted">{{ t('hubs_start', 'Inga bevakningar.') }}</p>

					<!-- Ny bevakning: titel (ingen PII i rubriken) + valfri frist + valfri cykel. -->
					<div class="arende-kort__bevakning-ny">
						<p class="arende-kort__flikrubrik">{{ t('hubs_start', 'Ny bevakning') }}</p>
						<label class="arende-kort__falt">
							<span class="arende-kort__falt-label">{{ t('hubs_start', 'Rubrik') }} *</span>
							<input
								v-model="nyBevakning.titel"
								type="text"
								class="arende-kort__input"
								:placeholder="t('hubs_start', 'Kort rubrik — ingen känslig information')"
								:disabled="nyBevakningSparar"
								aria-required="true"
								@keyup.enter="skapaBevak">
						</label>
						<p class="arende-kort__muted arende-kort__falt-hint">{{ t('hubs_start', 'Ingen känslig information i rubriken.') }}</p>
						<div class="arende-kort__falt-rad">
							<label class="arende-kort__falt">
								<span class="arende-kort__falt-label">{{ t('hubs_start', 'Frist (valfri)') }}</span>
								<input
									v-model="nyBevakning.fristDue"
									type="date"
									class="arende-kort__input"
									:disabled="nyBevakningSparar">
							</label>
							<label class="arende-kort__falt">
								<span class="arende-kort__falt-label">{{ t('hubs_start', 'Återkommande var (dagar, valfri)') }}</span>
								<input
									v-model.number="nyBevakning.recurringDagar"
									type="number"
									min="1"
									class="arende-kort__input arende-kort__input--kort"
									:disabled="nyBevakningSparar">
							</label>
						</div>
						<!-- Disabled ENDAST under sparande: rubrik-kravet valideras i
						     handlern med synligt fel. En villkors-disabled knapp blir en
						     oförklarat död knapp om komponentens render fryser efter ett
						     JS-fel (bindningen omvärderas aldrig, klicket avfyras aldrig). -->
						<NcButton
							type="primary"
							:disabled="nyBevakningSparar"
							@click="skapaBevak">
							<template #icon><BellRingIcon :size="16" /></template>
							{{ t('hubs_start', 'Skapa bevakning') }}
						</NcButton>
						<!-- Förklara den nedtonade knappen — annars ser formuläret "trasigt"
						     ut när frist/cykel är ifyllda men rubriken (kravet) är tom. -->
						<p
							v-if="!nyBevakning.titel.trim() && (nyBevakning.fristDue || nyBevakning.recurringDagar)"
							class="arende-kort__muted arende-kort__falt-hint"
							role="status">
							{{ t('hubs_start', 'Ange en rubrik för att kunna skapa bevakningen.') }}
						</p>
					</div>

					<!-- Delgivningsdatum: sätter FL 33 §-datumet → föder överklagandebevakningen
					     (3 veckor → laga kraft). Visar ärendets nuvarande datum om satt. -->
					<div class="arende-kort__delgivning">
						<p class="arende-kort__flikrubrik">{{ t('hubs_start', 'Sätt delgivningsdatum') }}</p>
						<p class="arende-kort__muted arende-kort__falt-hint">
							{{ t('hubs_start', 'Skapar överklagandefrist-bevakningen (3 veckor → laga kraft).') }}
							<template v-if="full.delgivningsdatum"> · {{ t('hubs_start', 'Nuvarande: {datum}', { datum: fmtDatum(full.delgivningsdatum) }) }}</template>
						</p>
						<div class="arende-kort__falt-rad">
							<input
								v-model="delgivningsdatum"
								type="date"
								class="arende-kort__input"
								:disabled="delgivningSparar">
							<!-- Samma buggklass som Skapa bevakning: disabled endast under
							     sparande — datum-kravet valideras i handlern med synligt fel. -->
							<NcButton
								type="secondary"
								:disabled="delgivningSparar"
								@click="sattDelgivning">
								{{ t('hubs_start', 'Spara delgivningsdatum') }}
							</NcButton>
						</div>
					</div>
				</div>

				<!-- Historik & beslut: motorns händelsejournal som tidslinje.
				     NEVER-SoR: besluten i sak bor i facksystemet — detta är spegeln. -->
				<div v-else-if="activeFlik === 'historik'">
					<!-- K-SIGN-5/6/7 — signeringsstatus (statuskedja + per-part + åtgärder).
					     Självförsörjande panel; auto-pollar medan en begäran pågår och
					     fliken är öppen. signed → verklig status driver nästa-åtgärden. -->
					<SigneringPanel :arende="arende" @signed="onSigneringSigned" />
					<template v-if="full.beslut">
						<!-- Motorns A11-beslut ({ dnr, destination, provenans, signatur,
						     signerat }) saknar demo-fixturens kravniva/signStatus/bevarande —
						     ovillkorlig dereferens gav TypeError i rendern och fliken såg
						     död ut på registrerade live-ärenden. Guarda per form. -->
						<p v-if="full.beslut.kravniva">{{ t('hubs_start', 'Kravnivå:') }} <strong>{{ full.beslut.kravniva }}</strong> · {{ full.beslut.signStatus }}</p>
						<p v-else>{{ t('hubs_start', 'Registrerad i facksystemet — dnr {dnr}', { dnr: full.beslut.dnr || '' }) }}<template v-if="full.beslut.signerat"> · {{ t('hubs_start', 'signerad') }}</template></p>
						<p v-if="beslutBevarande" class="arende-kort__bevarande">
							<span :class="bevClass(beslutBevarande.pades)">PAdES</span>
							<span :class="bevClass(beslutBevarande.pdfa)">PDF/A-1</span>
							<span :class="bevClass(beslutBevarande.ltv)">LTV</span>
							<span class="arende-kort__muted">{{ t('hubs_start', 'Giltig nu / Giltig då') }}</span>
						</p>
					</template>
					<p v-if="tabLaddar.historik" class="arende-kort__muted">{{ t('hubs_start', 'Hämtar historik…') }}</p>
					<ol class="arende-kort__tidslinje">
						<li v-for="(h, i) in historik" :key="'h' + i">
							<span class="arende-kort__tid">{{ fmtTime(h.tid) }}</span>
							<span class="arende-kort__handelse">
								{{ historikLabel(h) }}
								<span v-if="h.aktorUid" class="arende-kort__muted"> · {{ h.aktorUid }}</span>
								<a
									v-if="historikLank(h)"
									class="arende-kort__fillank arende-kort__handelse-lank"
									:href="historikLank(h)"><FileDocumentIcon :size="14" /> {{ t('hubs_start', 'öppna') }}</a>
							</span>
						</li>
					</ol>
					<p v-if="!tabLaddar.historik && !historik.length" class="arende-kort__muted">{{ t('hubs_start', 'Ingen historik än.') }}</p>
				</div>

				<!-- Parter: ärendets partsregister (motorns enda PII-tabell) —
				     självförsörjande panel som hämtar/muterar via egna OCS-anrop.
				     PII-visning för behörig handläggare är AVSEDD (authz i motorn). -->
				<PartsPanel
					v-else-if="activeFlik === 'parter'"
					:arende="arende"
					@antal="tabParterAntal = $event" />
			</div>

			<!-- Medlemspanel: vem är ansluten (ur motorns ledger) + lägg till kollega -->
			<div v-if="isExpanded" class="arende-kort__medlemmar">
				<span class="arende-kort__muted">{{ t('hubs_start', 'Anslutna:') }}</span>
				<span v-for="(m, i) in medlemmar" :key="'md' + i" class="arende-kort__medlem">
					{{ m.displayName || m.uid }} <span class="arende-kort__muted">({{ rollLabel(m.roll) }})</span>
				</span>
				<span v-if="!medlemmar.length" class="arende-kort__muted">{{ t('hubs_start', 'Inga registrerade medlemmar.') }}</span>
				<span class="arende-kort__kollega">
					<!-- Plattformens standard-användarsök (namn räcker — inget exakt uid). -->
					<AnvandarValjare
						v-model="kollegaUid"
						:placeholder="t('hubs_start', 'Sök kollega (namn)')"
						:disabled="laggerTillKollega" />
					<NcButton type="secondary" :disabled="!kollegaUid.trim() || laggerTillKollega" @click="laggTillKollega">
						{{ t('hubs_start', 'Lägg till kollega') }}
					</NcButton>
				</span>
			</div>
		</section>
	</article>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import AlertOctagonIcon from 'vue-material-design-icons/AlertOctagon.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import MessageTextLockIcon from 'vue-material-design-icons/MessageTextLock.vue'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import BellRingIcon from 'vue-material-design-icons/BellRing.vue'
import ForumIcon from 'vue-material-design-icons/Forum.vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import { showSuccess, showError } from '@nextcloud/dialogs'

import store from '../../store/index.js'
import { iconFor } from '../../services/icons.js'
import deepLinks from '../../services/deepLinks.js'
import { fetchCaseMessages, fetchArendeMeetings, fetchArendeHistorik, fetchArendeBevakningar, fetchArendeMedlemmar, tilldela, laggTillMedlem, skapaBevakning, kvitteraBevakning, avbrytBevakning, setDelgivningsdatum } from '../../services/api.js'
import ProcessStepper from './ProcessStepper.vue'
import FristChip from './FristChip.vue'
import ProvenansChip from './ProvenansChip.vue'
import NastaAtgardKnapp from './NastaAtgardKnapp.vue'
import DiskussionChip from './DiskussionChip.vue'
import TilldelningBand from './TilldelningBand.vue'
import PartsPanel from './PartsPanel.vue'
import SigneringPanel from './SigneringPanel.vue'
import AnvandarValjare from './AnvandarValjare.vue'

/** Svenska etiketter för bevakningens villkorstyp (aldrig rå maskintoken i UI). */
const VILLKOR_LABEL = {
	steg_uppnatt: t('hubs_start', 'Släcks när ärendet når ett målsteg'),
	komplettering_kopplad: t('hubs_start', 'Släcks när en komplettering kopplas'),
	commit_registrerad: t('hubs_start', 'Släcks vid registrering i facksystemet'),
	signering_kvitterad: t('hubs_start', 'Släcks vid signeringskvittens'),
	datum_passerat: t('hubs_start', 'Datumbevakning — släcks när fristdagen nås'),
	manuell_kvittering: t('hubs_start', 'Kvitteras manuellt när klar'),
}

/** Svenska etiketter för bevakningens status. */
const STATUS_LABEL = {
	aktiv: t('hubs_start', 'Aktiv'),
	uppnadd: t('hubs_start', 'Uppnådd'),
	passerad: t('hubs_start', 'Passerad'),
	avbruten: t('hubs_start', 'Avbruten'),
}

/** A2 — grind-namn (TYP_GRINDVAL.grind) → läsbar svensk etikett (aldrig rå token). */
const GRIND_LABEL = {
	skyddsbedomning: t('hubs_start', 'skyddsbedömning'),
	inte_inleda: t('hubs_start', 'inte inleda utredning'),
	kommunicering: t('hubs_start', 'kommunicering'),
	avslut: t('hubs_start', 'avslut'),
}

/** A2 — grindval (TYP_GRINDVAL.val) → läsbar etikett. */
const GRINDVAL_LABEL = {
	godkand: t('hubs_start', 'godkänt'),
	override: t('hubs_start', 'åsidosatt (dokumenterat skäl)'),
	vald: t('hubs_start', 'valt'),
}

/** A2 — kvittens-/grind-moment (TYP_KVITTENS.moment) → läsbar etikett. */
const MOMENT_LABEL = {
	skyddsbedomning: t('hubs_start', 'skyddsbedömning'),
	kommunicering: t('hubs_start', 'kommunicering'),
	beslut: t('hubs_start', 'beslut'),
}

export default {
	name: 'ArendeKort',
	components: {
		NcButton, AlertOctagonIcon, ChevronRightIcon, FileDocumentIcon, MessageTextLockIcon,
		VideoIcon, CheckCircleIcon, BellRingIcon, ForumIcon,
		ProcessStepper, FristChip, ProvenansChip, NastaAtgardKnapp,
		DiskussionChip, TilldelningBand, PartsPanel, SigneringPanel, AnvandarValjare,
	},
	props: {
		arende: { type: Object, required: true },
		expanded: { type: Boolean, default: false },
		pinned: { type: Boolean, default: false },
		keyboardMode: { type: Boolean, default: false },
		/** Varsel-länkat: kortet markeras (puls-ram) + auto-expanderar. */
		markerad: { type: Boolean, default: false },
	},
	data() {
		return {
			localExpanded: this.expanded,
			activeFlik: 'dokument',
			// Flik-innehåll (lazy-laddat per kort; null = ej hämtat än).
			tabMeddelanden: null,
			tabMoten: null,
			tabBevakningar: null,
			tabHistorik: null,
			// Anslutna (medlemsledger) — hämtas om vid varje expand/mutation; den
			// tunga full-cachen är frusen efter första laddningen.
			tabMedlemmar: null,
			// Parter-flikens räknare — sätts av PartsPanel (@antal) efter varje hämtning.
			tabParterAntal: null,
			tabLaddar: { meddelanden: false, moten: false, bevakningar: false, historik: false },
			kollegaUid: '',
			laggerTillKollega: false,
			tarArendet: false,
			togsNyss: false,
			// Bevaknings-flikens formulär + åtgärdsstatus.
			nyBevakning: { titel: '', fristDue: '', recurringDagar: null },
			nyBevakningSparar: false,
			delgivningsdatum: '',
			delgivningSparar: false,
			bevakningPending: [],
		}
	},
	computed: {
		isExpanded() {
			return this.localExpanded
		},
		pliktAktiv() {
			return !!(this.arende.plikt && !this.arende.plikt.kvitterad)
		},
		/** T4/F5 — skyddsbedömningen finns dokumenterad (plikt kvitterad): visa
		 * en bekräftelse i stället för att markören tyst försvinner. */
		pliktBekraftad() {
			return !!(this.arende.plikt && this.arende.plikt.kvitterad)
		},
		/** Stabil cache-nyckel: triageRef (= dnr ?? hubsCaseId, ALLTID satt) — aldrig
		 * den ibland-null:a dnr. Annars cachas ett oregistrerat ärende under en nyckel
		 * medan kortet läser en annan (full[undefined]) → flikarna blir evigt tomma och
		 * full.pekare.talkToken syns aldrig. */
		cacheKey() {
			return this.arende.triageRef || this.arende.dnr
		},
		/** Lazily-loaded full ärende (flik content) merged over the collapsed one. */
		full() {
			const f = store.state.arende.full[this.cacheKey]
			return f ? { ...this.arende, ...f } : this.arende
		},
		/** #10 — ärenderummets diskussions-token ur motorns full.pekare (kollapsad
		 * fallback). null när rummet ännu saknas → knappen visas inaktiverad. */
		diskussionsToken() {
			return (this.full.pekare && this.full.pekare.talkToken) || this.full.talkToken || null
		},
		/** Ärenderummets team (circle singleId) ur motorns pekare — presentations-
		 * lagret som knyter ihop rummet. null ⇒ team-pillret visas inte (ärlig
		 * frånvaro, aldrig ett påhittat team). */
		teamId() {
			return (this.full.pekare && this.full.pekare.teamId) || this.full.teamId || null
		},
		/** Ärendets ALLA chattrum (1:n) ur motorns pekare — Rum-flikens lista. */
		talkRooms() {
			const rooms = (this.full.pekare && this.full.pekare.talkRooms) || []
			if (rooms.length) {
				return rooms
			}
			// Kollapsad fallback: bara diskussions-token känd → visa den ändå.
			return this.diskussionsToken ? [{ token: this.diskussionsToken, namn: null }] : []
		},
		/** Ärenderummets medlemmar (ur motorns ledger) — medlemspanelen. Färsk
		 * ledger-hämtning (tabMedlemmar) vinner över den frusna full-cachen. */
		medlemmar() {
			return this.tabMedlemmar || this.full.medlemmar || []
		},
		arOtilldelat() {
			// Efter ett lyckat "Ta ärendet" göms knappen DIREKT (togsNyss) — utan
			// att vänta på summary-omladdningen (annars kan man "ta" ett ärende
			// man redan äger).
			return !this.togsNyss
				&& (this.full.status || this.arende.status) === 'otilldelat'
				&& !(this.full.agareUid || this.arende.agareUid)
		},
		/** Rubrik: kort mänsklig referens + ev. objekt-pseudonym. Aldrig tom. */
		kortTitel() {
			const kort = this.arende.kortRef
				|| String(this.arende.hubsCaseId || '').replace(/-/g, '').slice(0, 6)
			const bas = this.t('hubs_start', 'Ärende {ref}', { ref: kort })
			return this.arende.barnRef ? bas + ' · ' + this.arende.barnRef : bas
		},
		/** Aktens dokument. Meddelandepekare (msg-*.url) filtreras BORT — meddelanden
		 * visas enbart under fliken Meddelanden, aldrig som referensrad i akten. */
		aktenDokument() {
			const docs = (this.full.rum && this.full.rum.dokument) || []
			return docs
				.map((d) => {
					const namn = this.dokNamn(d)
					return {
						...(d && typeof d === 'object' ? d : { namn: String(d) }),
						namn,
					}
				})
				.filter((d) => !/^msg-[0-9a-f]+\.url$/i.test(d.namn))
		},
		/** Kopplade meddelanden: hämtade (live) med fixture-fallback (demo). */
		kopplladeMeddelanden() {
			if (this.tabMeddelanden && this.tabMeddelanden.length) {
				return this.tabMeddelanden
			}
			return (this.full.meddelanden && this.full.meddelanden.length) ? this.full.meddelanden : []
		},
		moten() {
			return this.tabMoten || { kommande: [], genomforda: [] }
		},
		bevakningar() {
			return this.tabBevakningar || []
		},
		historik() {
			return this.tabHistorik || []
		},
		/** B5 — bevarande-status för historik-fliken: demo-fixturens beslut.bevarande
		 * ({pades,pdfa,ltv}) när den finns, annars härledd ur motorns A11-signatur
		 * ({format,pdfa,ltv} per signaturDetaljFromKvitto). null = inget att visa
		 * (oregistrerat/osignerat) — chippen renderas då inte alls i stället för att
		 * en ovillkorlig dereferens kraschar hela flikens render. */
		beslutBevarande() {
			const b = this.full.beslut
			if (!b) {
				return null
			}
			if (b.bevarande) {
				return b.bevarande
			}
			const sig = b.signatur
			if (sig && typeof sig === 'object') {
				return { pades: /pades/i.test(String(sig.format || '')), pdfa: !!sig.pdfa, ltv: !!sig.ltv }
			}
			return null
		},
		flikar() {
			// Flikraden är ALLTID synlig; räknaren visas när innehållet är känt
			// (kollapsat kort saknar full-datat → null = ingen siffra, ärlig okänd).
			const dok = this.full.rum && this.full.rum.dokument ? this.aktenDokument.length : null
			return [
				{ id: 'dokument', label: t('hubs_start', 'Akten'), antal: dok },
				{ id: 'parter', label: t('hubs_start', 'Parter'), antal: this.tabParterAntal },
				{ id: 'meddelanden', label: t('hubs_start', 'Meddelanden'), antal: this.tabMeddelanden !== null ? this.kopplladeMeddelanden.length : null },
				{ id: 'rum', label: t('hubs_start', 'Rum'), antal: this.talkRooms.length || null },
				{ id: 'moten', label: t('hubs_start', 'Möten'), antal: this.tabMoten !== null ? (this.moten.kommande.length + this.moten.genomforda.length) : null },
				{ id: 'bevakningar', label: t('hubs_start', 'Bevakningar'), antal: this.tabBevakningar !== null ? this.bevakningar.length : null },
				{ id: 'historik', label: t('hubs_start', 'Historik & beslut'), antal: this.tabHistorik !== null ? this.historik.length : null },
			]
		},
		/** Modulraden ÖPPNAR moduler (Team först — vanligast, den samlade vyn).
		 * Åtgärderna (skicka/boka/bevaka) bor i Nästa åtgärd-menyn. */
		moduler() {
			const rad = []
			if (this.teamId) {
				rad.push({ key: 'team', icon: 'AccountGroup', short: t('hubs_start', 'Team'), label: t('hubs_start', 'Öppna teamet — ärendets samlade vy') })
			}
			rad.push(
				{ key: 'akten', icon: 'FolderLock', short: t('hubs_start', 'Akten'), label: t('hubs_start', 'Öppna akten i Filer') },
				{ key: 'meddelanden', icon: 'EmailFast', short: t('hubs_start', 'Meddelanden'), label: t('hubs_start', 'Öppna meddelandemodulen') },
				{ key: 'kalender', icon: 'CalendarCheck', short: t('hubs_start', 'Kalender'), label: t('hubs_start', 'Öppna kalendern') },
				{ key: 'signering', icon: 'FileSign', short: t('hubs_start', 'Signering'), label: t('hubs_start', 'Skicka för underskrift') },
			)
			return rad
		},
	},
	watch: {
		expanded(v) {
			this.localExpanded = v
		},
		markerad(v) {
			// Varsel-länken landar här: öppna kortet så åtgärden kan utföras direkt.
			if (v && !this.localExpanded) {
				this.localExpanded = true
				if (this.cacheKey) {
					store.loadArende(this.cacheKey)
				}
				this.loadMedlemmar()
			}
		},
	},
	methods: {
		t,
		n,
		iconFor,
		toggleExpand() {
			this.localExpanded = !this.localExpanded
			if (this.localExpanded && this.cacheKey) {
				store.loadArende(this.cacheKey)
				// A6 — ge processteppern evidens (historik/bevakningar/parter) så dess
				// nod-states blir exakta (klar/lucka/överhoppat) i det expanderade läget.
				store.loadStegEvidens(this.cacheKey)
				this.loadFlikData(this.activeFlik)
				this.loadMedlemmar()
			}
			this.$emit('expand', this.arende)
		},
		/** Flik-klick: expandera vid behov + lazy-ladda flikens innehåll. */
		openFlik(id) {
			this.activeFlik = id
			if (!this.localExpanded) {
				this.localExpanded = true
				if (this.cacheKey) {
					store.loadArende(this.cacheKey)
				}
				this.loadMedlemmar()
				this.$emit('expand', this.arende)
			}
			this.loadFlikData(id)
		},
		/** Lazy-ladda flikens läsyta (en gång per kort; ärligt tom vid fel). */
		async loadFlikData(id) {
			const ref = this.arende.hubsCaseId || this.cacheKey
			if (!ref) {
				return
			}
			try {
				if (id === 'meddelanden' && this.tabMeddelanden === null && !this.tabLaddar.meddelanden) {
					this.tabLaddar.meddelanden = true
					this.tabMeddelanden = await fetchCaseMessages(this.arende.hubsCaseId || ref)
					this.tabLaddar.meddelanden = false
				} else if (id === 'moten' && this.tabMoten === null && !this.tabLaddar.moten) {
					this.tabLaddar.moten = true
					this.tabMoten = await fetchArendeMeetings([this.arende.dnr, this.arende.hubsCaseId])
					this.tabLaddar.moten = false
				} else if (id === 'bevakningar' && this.tabBevakningar === null && !this.tabLaddar.bevakningar) {
					this.tabLaddar.bevakningar = true
					this.tabBevakningar = await fetchArendeBevakningar(ref)
					this.tabLaddar.bevakningar = false
					// A6 — spegla in i evidens-cachen så steppern återanvänder datat.
					store.setStegEvidensDel(this.cacheKey, 'bevakningar', this.tabBevakningar)
				} else if (id === 'historik' && this.tabHistorik === null && !this.tabLaddar.historik) {
					this.tabLaddar.historik = true
					this.tabHistorik = await fetchArendeHistorik(ref)
					this.tabLaddar.historik = false
					store.setStegEvidensDel(this.cacheKey, 'historik', this.tabHistorik)
				}
			} catch (e) {
				// Ärligt tom flik i stället för evig spinner.
				this.tabLaddar = { meddelanden: false, moten: false, bevakningar: false, historik: false }
			}
		},
		/** Hämta om medlemsledgern (Anslutna). Den tunga full-cachen är frusen efter
		 * första laddningen → panelen skulle annars visa en föråldrad lista tills en
		 * mutation forcerar reload. Egen billig ledger-läsning vid varje expand/mutation. */
		async loadMedlemmar() {
			const ref = this.arende.hubsCaseId || this.cacheKey
			if (!ref) {
				return
			}
			try {
				this.tabMedlemmar = await fetchArendeMedlemmar(ref)
			} catch (e) {
				// Behåll ev. tidigare lista i stället för att tömma vid tillfälligt fel.
			}
		},
		onStepperGoto({ steg }) {
			const map = { beslut: 'historik', utredning: 'dokument', uppfoljning: 'bevakningar', avslutat: 'historik', forhandsbedomning: 'dokument' }
			this.openFlik(map[steg] || 'dokument')
		},
		onMenuAction(key) {
			this.$emit(key, this.arende)
		},
		/**
		 * K-SIGN-8 — panelen såg en begäran gå till signed: låt VERKLIG status driva
		 * nastaAtgard-kedjan (overriden 'signaturkvittens' → "Delge beslut").
		 * TODO[signering-fas2]: motorn ska sätta vantar='signaturkvittens' i
		 * arende-summaryn utifrån signeringsstatus (SigneringService släcker även
		 * bevakningsvillkoret signering_kvitterad); tills dess patchas kortet lokalt
		 * här — samma objekt som summaryn bär, så knappen flippar direkt.
		 */
		onSigneringSigned() {
			this.$set(this.arende, 'vantar', 'signaturkvittens')
		},
		/** Öppna ärendechatten: expandera kortet och hoppa till Rum-fliken. */
		openDiskussion() {
			this.openFlik('rum')
		},
		/** Modulraden: öppna respektive modul (Team/Akten/Meddelanden/Kalender/Signering). */
		openModul(key) {
			switch (key) {
			case 'team':
				this.$emit('open-team', this.arende)
				break
			case 'akten':
				this.$emit('open-rum', this.arende)
				break
			case 'meddelanden':
				window.location.href = deepLinks.mailModuleLink()
				break
			case 'kalender':
				window.location.href = deepLinks.calendarModuleLink()
				break
			case 'signering':
				this.$emit('signera', this.arende)
				break
			}
		},
		/** Klickbar fil i akten: mount_point = hubsCaseId, filen ligger i roten. */
		fileHref(d) {
			const namn = this.dokNamn(d)
			return deepLinks.fileLink('/' + (this.arende.hubsCaseId || '') + '/' + namn)
		},
		/** Meddelanderad → tråden (server-levererad deepLink, annars inert '#'). */
		meddelandeHref(m) {
			if (m && m.deepLink) {
				return deepLinks.resolve(m.deepLink)
			}
			return '#'
		},
		rumHref(r) {
			return deepLinks.spreedRoomLink(r.token) || '#'
		},
		/** Rums-etikett: eget namn när det finns; saga-originalet = ärendets diskussion. */
		rumLabel(r, i) {
			if (r.namn) {
				return r.namn
			}
			return i === 0 ? t('hubs_start', 'Ärendets diskussion') : t('hubs_start', 'Chatt {n}', { n: i + 1 })
		},
		/**
		 * Tidslinjens radtext ur journalens typ + detalj (koordination, aldrig PII).
		 * Beviset finns i detaljen — A2 lägger till case för handling/part/bevakning/
		 * grindval/kvittens så raden aldrig faller till det råa typnamnet. Aktör + datum
		 * renderas separat av raden (h.aktorUid + fmtTime(h.tid)); ev. artefakt-länk
		 * via historikLank().
		 */
		historikLabel(h) {
			const d = this.historikDetalj(h)
			switch (h.typ) {
			case 'skapad':
				return t('hubs_start', 'Ärendet skapades ({typ})', { typ: d.arendeTyp || '' })
			case 'steg':
				return t('hubs_start', 'Steg: {fran} → {till}', { fran: d.fran || '', till: d.till || '' })
			case 'tilldelad':
				return t('hubs_start', 'Tilldelat {uid}', { uid: d.uid || '' })
			case 'medlem':
				return d.riktning === 'ut'
					? t('hubs_start', 'Medlem borttagen: {uid}', { uid: d.uid || '' })
					: t('hubs_start', 'Medlem tillagd: {uid} ({roll})', { uid: d.uid || '', roll: this.rollLabel(d.roll) })
			case 'registrerad':
				return t('hubs_start', 'Registrerad i facksystemet — dnr {dnr}', { dnr: d.dnr || '' })
			case 'rum':
				return t('hubs_start', 'Ny chatt skapades i ärendet')
			case 'kopplad':
				return n('hubs_start', '%n meddelande kopplades', '%n meddelanden kopplades', d.antal || 1)
			case 'handling': {
				// Handling skapad ur mall (mall-nyckeln, aldrig innehåll). Filnamnet
				// visas när det finns; annars bara mallen.
				const mall = d.mall || d.mallNamn || d.mallId || ''
				const fil = this.handelseFil(h)
				if (mall && fil) {
					return t('hubs_start', 'Handling skapad ur mall: {mall} → {fil}', { mall, fil })
				}
				if (mall) {
					return t('hubs_start', 'Handling skapad ur mall: {mall}', { mall })
				}
				return fil
					? t('hubs_start', 'Handling skapad: {fil}', { fil })
					: t('hubs_start', 'Handling skapad')
			}
			case 'part': {
				// Partsregister-händelse: roll + riktning (aldrig namn/pnr).
				const roll = this.rollLabel(d.roll) || t('hubs_start', 'part')
				if (d.riktning === 'ut' || d.borttagen) {
					return t('hubs_start', 'Part borttagen: {roll}', { roll })
				}
				if (d.kalla === 'navet' || d.uppdaterad) {
					return t('hubs_start', 'Part uppdaterad mot folkbokföringen: {roll}', { roll })
				}
				return t('hubs_start', 'Part tillagd: {roll}', { roll })
			}
			case 'bevakning': {
				// Bevaknings-livscykel: typ/titel + status (register-koordination).
				const titel = d.titel || this.bevakningTypLabel(d.typ) || t('hubs_start', 'bevakning')
				const status = d.status ? this.statusLabel(d.status) : ''
				return status
					? t('hubs_start', 'Bevakning: {titel} ({status})', { titel, status })
					: t('hubs_start', 'Bevakning: {titel}', { titel })
			}
			case 'grindval': {
				// A2 — grind-beslut: vilken grind + vilket val (+ ev. skäl-enum).
				const grind = GRIND_LABEL[d.grind] || d.grind || ''
				const val = GRINDVAL_LABEL[d.val] || d.val || ''
				const skal = this.grindvalSkal(d)
				const bas = t('hubs_start', 'Grindval: {grind} — {val}', { grind, val })
				return skal ? bas + ' · ' + skal : bas
			}
			case 'kvittens': {
				// A2 — kvittens av ett moment (t.ex. skyddsbedömning).
				const moment = MOMENT_LABEL[d.moment] || d.moment || ''
				return t('hubs_start', 'Kvitterat: {moment}', { moment })
			}
			default:
				return h.typ
			}
		},
		/** Normalisera journal-detaljen (objekt eller JSON-sträng) → objekt. */
		historikDetalj(h) {
			if (h && typeof h.detalj === 'object' && h.detalj) {
				return h.detalj
			}
			if (h && typeof h.detalj === 'string') {
				try {
					return JSON.parse(h.detalj)
				} catch (e) {
					return {}
				}
			}
			return {}
		},
		/** Filnamnet för en journal-händelse om detaljen bär en (handling/artefakt). */
		handelseFil(h) {
			const d = this.historikDetalj(h)
			return d.fil || d.filnamn || d.artefakt || d.artefaktRef || ''
		},
		/**
		 * A2 — artefakt-länk för en journalrad (återanvänder fileHref-mönstret): en
		 * skapad handling/artefakt i akten öppnas i Filer. null när raden saknar fil.
		 */
		historikLank(h) {
			const fil = this.handelseFil(h)
			const caseId = this.arende.hubsCaseId
			if (fil && caseId) {
				return deepLinks.fileLink('/' + caseId + '/' + fil)
			}
			return null
		},
		/** Grindvalets skäl-enum → läsbar text (orsak/skal/utfall + beslutsfattare). */
		grindvalSkal(d) {
			const delar = []
			const koder = {
				// A7 override-skäl
				gjord_i_facksystem: t('hubs_start', 'skyddsbedömning gjord i facksystemet'),
				gjord_utanfor_hubs: t('hubs_start', 'skyddsbedömning gjord utanför Hubs'),
				bradskande: t('hubs_start', 'brådskande'),
				// A9a inte-inleda-orsak
				ingen_grund: t('hubs_start', 'ingen grund för utredning'),
				annan_huvudman: t('hubs_start', 'annan huvudman'),
				redan_aktuell: t('hubs_start', 'redan aktuell'),
				avskrivs: t('hubs_start', 'avskrivs'),
				// A9b kommunicerings-skäl
				sker_i_beslut: t('hubs_start', 'kommunicering sker i beslutet'),
				ej_relevant: t('hubs_start', 'ej relevant'),
				// A9c avslutsutfall
				behov_tillgodosett: t('hubs_start', 'behov tillgodosett'),
				flyttat: t('hubs_start', 'flyttat'),
				avbojt: t('hubs_start', 'tackat nej'),
				annan_insats: t('hubs_start', 'annan insats'),
				overford_facksystem: t('hubs_start', 'överförd till facksystemet'),
			}
			const kod = d.orsak || d.skal || d.utfall
			if (kod && koder[kod]) {
				delar.push(koder[kod])
			}
			if (d.kvarstaende) {
				delar.push(t('hubs_start', 'kvarstående behov'))
			}
			return delar.join(', ')
		},
		/** Bevaknings-typens läsbara etikett (fallback när titel saknas). */
		bevakningTypLabel(typ) {
			if (!typ) {
				return ''
			}
			const s = String(typ).toLowerCase()
			if (s.includes('overvagande') || s.includes('omprovning')) {
				return t('hubs_start', 'övervägande/omprövning')
			}
			if (s.includes('overklagande')) {
				return t('hubs_start', 'överklagandefrist')
			}
			return typ
		},
		rollLabel(roll) {
			const map = {
				mottagningskrets: t('hubs_start', 'mottagningskrets'),
				handlaggare: t('hubs_start', 'handläggare'),
				co_handlaggare: t('hubs_start', 'medhandläggare'),
				observator: t('hubs_start', 'observatör'),
			}
			return map[roll] || roll
		},
		/** Självbetjäning: ta ett otilldelat ärende (motorns tilldela + handoff). */
		async taArendet() {
			const uid = getCurrentUser() && getCurrentUser().uid
			const ref = this.arende.hubsCaseId || this.cacheKey
			if (!uid || !ref) {
				return
			}
			this.tarArendet = true
			try {
				const r = await tilldela(ref, uid)
				if (r && r.ok !== false) {
					this.togsNyss = true
					showSuccess(t('hubs_start', 'Ärendet är nu ditt.'))
					store.loadArendeSummary()
					store.loadArende(this.cacheKey, true)
					this.loadMedlemmar()
				} else {
					showError(t('hubs_start', 'Kunde inte ta ärendet: {orsak}', { orsak: (r && r.error) || t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				showError(this.motorFel(e, t('hubs_start', 'Kunde inte ta ärendet. Försök igen.')))
			} finally {
				this.tarArendet = false
			}
		},
		/** Motorns felorsak ur ett OCS-fel (t.ex. 'Användaren finns inte i Hubs: x'). */
		motorFel(e, fallback) {
			const d = e && e.response && e.response.data
			const orsak = (d && d.ocs && d.ocs.data && d.ocs.data.error) || (d && d.error) || null
			return orsak || fallback
		},
		/** Lägg till en kollega i rummet (ledger + grupp + chatt + notis via motorn). */
		async laggTillKollega() {
			const uid = this.kollegaUid.trim()
			const ref = this.arende.hubsCaseId || this.cacheKey
			if (!uid || !ref || this.laggerTillKollega) {
				return
			}
			this.laggerTillKollega = true
			try {
				const r = await laggTillMedlem(ref, uid)
				if (r && r.ok !== false) {
					showSuccess(t('hubs_start', 'Kollegan är tillagd i ärendet.'))
					this.kollegaUid = ''
					store.loadArende(this.cacheKey, true)
					this.loadMedlemmar()
				} else {
					showError(t('hubs_start', 'Kunde inte lägga till kollegan: {orsak}', { orsak: (r && r.error) || t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				// Motorn validerar att kollegan är en RIKTIG användare (400 annars).
				showError(this.motorFel(e, t('hubs_start', 'Kunde inte lägga till kollegan. Kontrollera användar-id:t.')))
			} finally {
				this.laggerTillKollega = false
			}
		},
		/** "Gör detta till en handling" → human-in-the-loop → commit-grinden (förälder). */
		onGorTillHandling(payload) {
			this.emitCommit()
		},
		/** #5 — emit:a commit MED ärenderummets dokumentlista så CommitGrind kan visa
		 * den granskbara (alla förvalda) urvalslistan. */
		emitCommit() {
			this.$emit('commit', this.arende, { dokument: this.normaliseraDok() })
		},
		/** Normalisera ärenderummets dokument till {fileid,namn} (stöd strängar + objekt).
		 * Meddelandepekare (msg-*.url) utesluts — de är referenser, inte handlingar,
		 * och ska aldrig erbjudas i commit-grindens dokumenturval. */
		normaliseraDok() {
			const docs = (this.full.rum && this.full.rum.dokument) || []
			return docs
				.filter((d) => !/^msg-[0-9a-f]+\.url$/i.test(this.dokNamn(d)))
				.map((d) => (d && typeof d === 'object')
					? { fileid: (d.fileid !== undefined && d.fileid !== null) ? d.fileid : null, namn: this.dokNamn(d) }
					: { fileid: null, namn: String(d) })
		},
		/** Dokumentets visningsnamn. arenderumDokument() ger {namn,fileid}-objekt på
		 * riktig data; demo-data ger strängar. Stöd båda (annars '[object Object]'). */
		dokNamn(d) {
			return (d && typeof d === 'object') ? (d.namn || d.name || '') : d
		},
		/** Tooltip: filnamn + ev. fil-id (övrig info hålls i title, inte i listraden). */
		dokTitle(d) {
			if (d && typeof d === 'object') {
				return d.fileid ? `${d.namn} · fil-id ${d.fileid}` : (d.namn || '')
			}
			return String(d)
		},
		bevClass(ok) {
			return ok ? 'arende-kort__bev arende-kort__bev--ok' : 'arende-kort__bev'
		},
		fmtTime(s) {
			try {
				return new Date(s).toLocaleString('sv-SE', { hour: '2-digit', minute: '2-digit', day: 'numeric', month: 'short' })
			} catch (e) {
				return s
			}
		},
		/** Datum utan klockslag (bevakningens fristDue/delgivningsdatum är YYYY-MM-DD). */
		fmtDatum(s) {
			try {
				return new Date(s).toLocaleDateString('sv-SE', { day: 'numeric', month: 'short', year: 'numeric' })
			} catch (e) {
				return s
			}
		},
		/** Svensk villkors-/status-etikett (aldrig rå maskintoken). */
		villkorLabel(v) {
			return VILLKOR_LABEL[v] || v
		},
		statusLabel(s) {
			return STATUS_LABEL[s] || s
		},
		statusKlass(s) {
			const map = {
				aktiv: 'arende-kort__chip--neutral',
				uppnadd: 'arende-kort__chip--gron',
				passerad: 'arende-kort__chip--rod',
				avbruten: 'arende-kort__chip--gra',
			}
			return map[s] || 'arende-kort__chip--neutral'
		},
		/** Brådskande läsning: en passerad ELLER lagstadgad+aktiv bevakning är röd. */
		arBradskande(b) {
			return b.status === 'passerad' || (b.lagstadgad && b.status === 'aktiv')
		},
		/** Relativ frist-hint: "om N dagar" / "N dagar sen" / "idag" (YYYY-MM-DD). */
		relativFrist(due) {
			if (!due) {
				return ''
			}
			const idag = new Date()
			idag.setHours(0, 0, 0, 0)
			const d = new Date(due + 'T00:00:00')
			const dagar = Math.round((d - idag) / 86400000)
			if (dagar === 0) {
				return this.t('hubs_start', 'idag')
			}
			if (dagar > 0) {
				return this.n('hubs_start', 'om %n dag', 'om %n dagar', dagar)
			}
			return this.n('hubs_start', '%n dag sen', '%n dagar sen', Math.abs(dagar))
		},
		/** Kvittera en manuell bevakning (klarmarkering) → ladda om fliken. */
		async kvitteraBevak(b) {
			const ref = this.arende.hubsCaseId || this.cacheKey
			if (!ref || !b || this.bevakningPending.includes(b.id)) {
				return
			}
			this.bevakningPending = this.bevakningPending.concat([b.id])
			try {
				const r = await kvitteraBevakning(ref, b.id)
				if (r && r.ok !== false) {
					showSuccess(t('hubs_start', 'Bevakningen är klarmarkerad.'))
					await this.reloadBevakningar()
				} else {
					showError(t('hubs_start', 'Kunde inte kvittera bevakningen: {orsak}', { orsak: (r && r.error) || t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				showError(this.motorFel(e, t('hubs_start', 'Kunde inte kvittera bevakningen. Försök igen.')))
			} finally {
				this.bevakningPending = this.bevakningPending.filter((id) => id !== b.id)
			}
		},
		/** Avbryt (ta bort) en aktiv bevakning efter bekräftelse → ladda om fliken. */
		async avbrytBevak(b) {
			const ref = this.arende.hubsCaseId || this.cacheKey
			if (!ref || !b || this.bevakningPending.includes(b.id)) {
				return
			}
			// eslint-disable-next-line no-alert
			if (!window.confirm(t('hubs_start', 'Ta bort bevakningen "{titel}"?', { titel: b.titel }))) {
				return
			}
			this.bevakningPending = this.bevakningPending.concat([b.id])
			try {
				const r = await avbrytBevakning(ref, b.id)
				if (r && r.ok !== false) {
					showSuccess(t('hubs_start', 'Bevakningen är borttagen.'))
					await this.reloadBevakningar()
				} else {
					showError(t('hubs_start', 'Kunde inte ta bort bevakningen: {orsak}', { orsak: (r && r.error) || t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				showError(this.motorFel(e, t('hubs_start', 'Kunde inte ta bort bevakningen. Försök igen.')))
			} finally {
				this.bevakningPending = this.bevakningPending.filter((id) => id !== b.id)
			}
		},
		/** Skapa en ny ad hoc-bevakning (titel obligatorisk; ingen PII) → ladda om. */
		async skapaBevak() {
			const ref = this.arende.hubsCaseId || this.cacheKey
			const titel = this.nyBevakning.titel.trim()
			if (!ref || this.nyBevakningSparar) {
				return
			}
			// Rubrik-kravet valideras HÄR (inte i :disabled): handlern läser alltid
			// aktuellt komponent-state och ger ett synligt fel — en tyst return
			// lämnar användaren gissande om varför inget händer.
			if (!titel) {
				showError(t('hubs_start', 'Ange en rubrik för bevakningen.'))
				return
			}
			this.nyBevakningSparar = true
			try {
				const r = await skapaBevakning(ref, {
					titel,
					fristDue: this.nyBevakning.fristDue || null,
					recurringDagar: this.nyBevakning.recurringDagar || null,
				})
				if (r && r.ok !== false) {
					showSuccess(t('hubs_start', 'Bevakningen är skapad.'))
					this.nyBevakning = { titel: '', fristDue: '', recurringDagar: null }
					await this.reloadBevakningar()
				} else {
					showError(t('hubs_start', 'Kunde inte skapa bevakningen: {orsak}', { orsak: (r && r.error) || t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				showError(this.motorFel(e, t('hubs_start', 'Kunde inte skapa bevakningen. Försök igen.')))
			} finally {
				this.nyBevakningSparar = false
			}
		},
		/** Sätt delgivningsdatum → föder överklagandebevakningen; ladda om fliken. */
		async sattDelgivning() {
			const ref = this.arende.hubsCaseId || this.cacheKey
			if (!ref || this.delgivningSparar) {
				return
			}
			// Datum-kravet valideras här (inte i :disabled) — synligt fel i stället
			// för en oförklarat grå knapp, robust även mot fryst re-render.
			if (!this.delgivningsdatum) {
				showError(t('hubs_start', 'Välj ett delgivningsdatum.'))
				return
			}
			this.delgivningSparar = true
			try {
				const r = await setDelgivningsdatum(ref, this.delgivningsdatum)
				if (r && r.ok !== false) {
					showSuccess(t('hubs_start', 'Delgivningsdatum satt — överklagandebevakningen är skapad.'))
					this.delgivningsdatum = ''
					// Datumet lever på ärendet (arende.delgivningsdatum) — läs om kortet.
					if (this.cacheKey) {
						store.loadArende(this.cacheKey, true)
					}
					await this.reloadBevakningar()
				} else {
					showError(t('hubs_start', 'Kunde inte sätta delgivningsdatum: {orsak}', { orsak: (r && r.error) || t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				showError(this.motorFel(e, t('hubs_start', 'Kunde inte sätta delgivningsdatum. Försök igen.')))
			} finally {
				this.delgivningSparar = false
			}
		},
		/** Tvinga en omhämtning av bevaknings-fliken (efter en mutation). */
		async reloadBevakningar() {
			const ref = this.arende.hubsCaseId || this.cacheKey
			if (!ref) {
				return
			}
			this.tabLaddar.bevakningar = true
			try {
				this.tabBevakningar = await fetchArendeBevakningar(ref)
				// A6 — håll evidens-cachen (och därmed steppern) i synk efter mutationen.
				store.setStegEvidensDel(this.cacheKey, 'bevakningar', this.tabBevakningar)
			} catch (e) {
				// Ärligt tom flik i stället för evig spinner.
			} finally {
				this.tabLaddar.bevakningar = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.arende-kort {
	display: flex;
	flex-direction: column;
	gap: 10px;

	&--het { border: 1px solid var(--color-primary-element); box-shadow: 0 2px 10px var(--color-box-shadow, rgba(0, 0, 0, 0.1)); }
	&--plikt { border-color: var(--hs-status-error); }

	&__plikt {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: 0;
		padding: 4px 10px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--hs-status-error);
		color: #fff;
		font-size: 0.82rem;
		font-weight: 600;
		align-self: flex-start;
	}

	// Dämpad grön bekräftelse (T4/F5): lugnare än den röda plikt-pillen — den
	// SKA inte tävla om uppmärksamhet, bara bekräfta att momentet är gjort.
	&__plikt-ok {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: 0;
		padding: 3px 10px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--hs-status-success-subtle, rgba(70, 186, 97, 0.12));
		color: var(--hs-status-success, #2d7d3f);
		font-size: 0.78rem;
		font-weight: 600;
		align-self: flex-start;
	}

	&__head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }

	&__titel {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		background: transparent;
		border: none;
		cursor: pointer;
		font-size: 1.05rem;
		font-weight: 700;
		color: var(--color-main-text);
		text-align: start;
		min-width: 0;
	}

	&__chev { flex: 0 0 auto; transition: transform 0.15s ease; color: var(--color-text-maxcontrast); }
	&__chev--open { transform: rotate(90deg); }
	&__dnr { font-weight: 400; font-size: 0.85rem; color: var(--color-text-maxcontrast); }

	&__badges { display: inline-flex; gap: 6px; flex: 0 0 auto; }
	&__sekretess, &__loa {
		font-size: 0.7rem;
		font-weight: 600;
		padding: 1px 7px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}

	&__meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }

	&__kedja {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 2px 4px;
		margin: 0;
		font-size: 0.74rem;
		color: var(--color-text-maxcontrast);
	}
	&__kedja-led { display: inline-flex; align-items: center; gap: 2px; }
	&__kedja-pil { color: var(--color-border); }

	&__nasta { display: flex; align-items: center; gap: 10px; }
	&__nasta-label { font-size: 0.85rem; color: var(--color-text-maxcontrast); white-space: nowrap; flex: 0 0 auto; }

	&__quick { display: flex; flex-wrap: wrap; gap: 6px; }
	&__q {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 4px 10px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
		color: var(--color-main-text);
		font-size: 0.8rem;
		cursor: pointer;
		&:hover { background: var(--color-background-hover); }
	}

	&__expand { border-top: 1px solid var(--color-border); padding-top: 10px; }
	&__tabs { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px; }
	&__tab {
		padding: 4px 12px;
		border: none;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark);
		color: var(--color-main-text);
		font-size: 0.82rem;
		cursor: pointer;
		&--active { background: var(--color-primary-element); color: var(--color-primary-element-text); }
	}

	&__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 5px; font-size: 0.88rem;
		li { display: flex; align-items: center; gap: 6px; } }
	&__muted { color: var(--color-text-maxcontrast); font-size: 0.82rem; }

	&__mote { margin-bottom: 10px; }
	&__mote-titel { display: flex; align-items: center; gap: 6px; margin: 0 0 6px; font-weight: 600; }
	&__sbs { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 8px; font-size: 0.82rem;
		p { margin: 4px 0 0; color: var(--color-text-maxcontrast); }
		@media (max-width: 600px) { grid-template-columns: 1fr; } }

	&__bevarande { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
	&__bev { font-size: 0.72rem; padding: 1px 8px; border-radius: var(--border-radius-pill, 16px); border: 1px solid var(--color-border); color: var(--color-text-maxcontrast); }
	&__bev--ok { border-color: var(--hs-status-success); color: var(--hs-status-success); }

	&__otilldelad {
		font-size: 0.78rem; font-weight: 600; padding: 1px 10px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--hs-status-warning-bg, #fdf3e3);
		color: var(--hs-status-warning-text, #7f5900);
	}
	&__tab-antal {
		margin-left: 6px; padding: 0 7px; font-size: 0.75rem;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark); font-variant-numeric: tabular-nums;
	}
	&__fillank {
		display: inline-flex; align-items: center; gap: 6px;
		color: var(--color-primary-element); font-weight: 600; text-decoration: none;
		&:hover, &:focus-visible { text-decoration: underline; }
	}
	&__radknapp {
		display: inline-flex; align-items: center; gap: 6px;
		background: none; border: none; padding: 0; font: inherit; cursor: pointer;
		color: var(--color-primary-element); font-weight: 600;
		&:hover, &:focus-visible { text-decoration: underline; }
	}
	&__olast {
		margin-left: 6px; font-size: 0.72rem; font-weight: 700; padding: 0 7px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-primary-element-light, #e7f1f7); color: var(--color-primary-element);
	}
	&__flikrubrik { margin: 8px 0 4px; font-weight: 700; font-size: 0.82rem; }
	&__bevakning {
		margin-bottom: 10px;
		padding: 8px 10px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
		&--bradskande { border-color: var(--hs-status-error); background: color-mix(in srgb, var(--hs-status-error) 6%, var(--color-main-background)); }
	}
	&__bevakning-titel { display: flex; align-items: center; gap: 6px; margin: 0; flex-wrap: wrap; }
	&__bevakning-meta { margin: 4px 0 0 22px; display: flex; flex-wrap: wrap; gap: 4px; }
	&__bevakning-actions { display: flex; gap: 6px; margin: 6px 0 0 22px; }
	&__bevakning-besk { margin: 2px 0 0 22px; }

	&__chip {
		font-size: 0.7rem; font-weight: 600; padding: 1px 8px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark); color: var(--color-text-maxcontrast);
		white-space: nowrap;
		&--neutral { background: var(--color-primary-element-light, #e7f1f7); color: var(--color-primary-element); }
		&--gron { background: color-mix(in srgb, var(--hs-status-success) 16%, var(--color-main-background)); color: var(--hs-status-success); }
		&--rod { background: var(--hs-status-error); color: #fff; }
		&--gra { background: var(--color-background-dark); color: var(--color-text-maxcontrast); }
	}

	&__bevakning-ny, &__delgivning {
		border-top: 1px solid var(--color-border);
		margin-top: 10px;
		padding-top: 10px;
	}
	&__falt { display: flex; flex-direction: column; gap: 3px; margin-bottom: 6px; }
	&__falt-label { font-size: 0.8rem; color: var(--color-text-maxcontrast); }
	&__falt-hint { margin: 0 0 6px; }
	&__falt-rad { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 8px; margin-bottom: 8px; }
	&__input {
		padding: 5px 8px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius, 8px);
		background: var(--color-main-background);
		color: var(--color-main-text);
		font: inherit;
		&--kort { max-width: 120px; }
	}
	&__tidslinje {
		list-style: none; margin: 6px 0 0; padding: 0;
		display: flex; flex-direction: column; gap: 5px; font-size: 0.88rem;
		li { display: flex; gap: 10px; align-items: baseline; }
	}
	&__tid { color: var(--color-text-maxcontrast); font-size: 0.78rem; min-width: 96px; font-variant-numeric: tabular-nums; }
	&__handelse { min-width: 0; }
	&__handelse-lank { margin-left: 6px; font-size: 0.82rem; font-weight: 600; }
	&__medlemmar {
		display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
		border-top: 1px solid var(--color-border); padding-top: 10px; margin-top: 10px;
		font-size: 0.85rem;
	}
	&__medlem { font-weight: 600; }
	&__kollega { display: inline-flex; align-items: center; gap: 6px; margin-left: auto; }
	&__kollega-input { min-width: 170px; }

	&--markerad {
		outline: 3px solid var(--color-primary-element);
		outline-offset: 2px;
		@media (prefers-reduced-motion: no-preference) {
			transition: outline-color 0.4s ease;
		}
	}
}
</style>
