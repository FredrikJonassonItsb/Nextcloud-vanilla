<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<NcModal
		:name="t('hubs_start', 'Välkommen till Hubs Start')"
		size="normal"
		:can-close="true"
		@close="onSkip">
		<div class="onboarding">
			<!-- Progress dots -->
			<ol class="onboarding__dots" :aria-label="t('hubs_start', 'Steg i introduktionen')">
				<li
					v-for="n in totalSteps"
					:key="n"
					class="onboarding__dot"
					:class="{ 'onboarding__dot--active': n === step, 'onboarding__dot--done': n < step }"
					:aria-current="n === step ? 'step' : false">
					<span class="hidden-visually">{{ dotLabel(n) }}</span>
				</li>
			</ol>

			<div class="onboarding__body" role="group" :aria-label="currentStepLabel">
				<!-- Step 1: Välkommen + vad Hubs Start är -->
				<section v-if="step === 1" class="onboarding__step">
					<IconRocket :size="40" class="onboarding__hero-icon" />
					<h2 class="onboarding__title">{{ t('hubs_start', 'Välkommen till Hubs Start') }}</h2>
					<p class="onboarding__lead">
						{{ t('hubs_start', 'Hubs Start är din samlade startsida för säker myndighetskommunikation. Här ser du allt som väntar på dig — säkra meddelanden, möten och kvittenser — på ett ställe.') }}
					</p>
					<ul class="onboarding__points">
						<li>{{ t('hubs_start', 'Ett ärendeflöde över alla kanaler — du slipper hoppa mellan system.') }}</li>
						<li>{{ t('hubs_start', 'All data lagras i er egen driftmiljö.') }}</li>
						<li>{{ t('hubs_start', 'Tydliga åtgärder först: det som kräver något av dig ligger överst.') }}</li>
					</ul>
				</section>

				<!-- Step 2: Tillitsnivåer förklarade -->
				<section v-else-if="step === 2" class="onboarding__step">
					<IconShieldCheck :size="40" class="onboarding__hero-icon" />
					<h2 class="onboarding__title">{{ t('hubs_start', 'Tillitsnivåer och BankID') }}</h2>
					<p class="onboarding__lead">
						{{ t('hubs_start', 'Tillitsnivån (LOA) avgör vad du får göra. Ju känsligare uppgifter, desto högre tillitsnivå krävs.') }}
					</p>
					<dl class="onboarding__loa-list">
						<div class="onboarding__loa-row">
							<dt class="onboarding__loa-name">{{ t('hubs_start', 'Tillitsnivå 1 (LOA1)') }}</dt>
							<dd class="onboarding__loa-desc">{{ t('hubs_start', 'Grundläggande inloggning. Räcker för att läsa och orientera dig.') }}</dd>
						</div>
						<div class="onboarding__loa-row">
							<dt class="onboarding__loa-name">{{ t('hubs_start', 'Tillitsnivå 2 (LOA2)') }}</dt>
							<dd class="onboarding__loa-desc">{{ t('hubs_start', 'Stärkt inloggning. Ger åtkomst till mer av det dagliga arbetet.') }}</dd>
						</div>
						<div class="onboarding__loa-row">
							<dt class="onboarding__loa-name">{{ t('hubs_start', 'Tillitsnivå 3 (LOA3)') }}</dt>
							<dd class="onboarding__loa-desc">{{ t('hubs_start', 'Legitimering med BankID. Krävs för känsliga ärenden och för att verifiera motparter med personnummer (grön bock).') }}</dd>
						</div>
					</dl>
					<p class="onboarding__why">
						{{ t('hubs_start', 'BankID knyter handlingen till en verklig person — det ger spårbarhet, kvittens och högsta tillitsnivå.') }}
					</p>

					<div v-if="needsLoa3Hint" class="onboarding__hint" role="note">
						<IconShieldAlert :size="20" class="onboarding__hint-icon" />
						<p class="onboarding__hint-text">
							{{ t('hubs_start', 'Du är inloggad på en lägre tillitsnivå. Legitimera dig med BankID när du behöver hantera känsliga ärenden — du kan göra det när som helst från startsidan.') }}
						</p>
					</div>
				</section>

				<!-- Step 3: Dina funktionsbrevlådor -->
				<section v-else-if="step === 3" class="onboarding__step">
					<IconMailbox :size="40" class="onboarding__hero-icon" />
					<h2 class="onboarding__title">{{ t('hubs_start', 'Dina funktionsbrevlådor') }}</h2>
					<p class="onboarding__lead">
						{{ t('hubs_start', 'En funktionsbrevlåda är en delad brevlåda för en funktion eller grupp. Du och dina kollegor delar på ärendena och ser vad som är ej tilldelat.') }}
					</p>

					<ul v-if="mailboxes.length" class="onboarding__mailboxes">
						<li
							v-for="mailbox in mailboxes"
							:key="mailbox.id"
							class="onboarding__mailbox">
							<IconMailbox :size="20" class="onboarding__mailbox-icon" />
							<span class="onboarding__mailbox-name">{{ mailbox.name }}</span>
						</li>
					</ul>
					<p v-else class="onboarding__muted">
						{{ t('hubs_start', 'Du är ännu inte kopplad till någon funktionsbrevlåda. När du blir det dyker den upp här och på startsidan.') }}
					</p>
				</section>

				<!-- Step 4: Rundtur av de fyra primära åtgärderna -->
				<section v-else-if="step === 4" class="onboarding__step">
					<IconGesture :size="40" class="onboarding__hero-icon" />
					<h2 class="onboarding__title">{{ t('hubs_start', 'De fyra snabbåtgärderna') }}</h2>
					<p class="onboarding__lead">
						{{ t('hubs_start', 'Högst upp på startsidan finns alltid dina viktigaste åtgärder.') }}
					</p>
					<ul class="onboarding__tour">
						<li v-if="apps.mail" class="onboarding__tour-item">
							<IconMessage :size="22" class="onboarding__tour-icon" />
							<div>
								<span class="onboarding__tour-name">{{ t('hubs_start', 'Nytt säkert meddelande') }}</span>
								<span class="onboarding__tour-desc">{{ t('hubs_start', 'Skicka till medborgare eller myndighet — rätt kanal väljs åt dig.') }}</span>
							</div>
						</li>
						<li v-if="apps.spreed" class="onboarding__tour-item">
							<IconCalendarPlus :size="22" class="onboarding__tour-icon" />
							<div>
								<span class="onboarding__tour-name">{{ t('hubs_start', 'Boka säkert möte') }}</span>
								<span class="onboarding__tour-desc">{{ t('hubs_start', 'Boka ett möte med BankID-skydd, SMS-kallelse och kvittens.') }}</span>
							</div>
						</li>
						<li v-if="apps.spreed" class="onboarding__tour-item">
							<IconVideo :size="22" class="onboarding__tour-icon" />
							<div>
								<span class="onboarding__tour-name">{{ t('hubs_start', 'Starta möte nu') }}</span>
								<span class="onboarding__tour-desc">{{ t('hubs_start', 'Öppna ett säkert möte direkt för ett spontant samtal.') }}</span>
							</div>
						</li>
						<li class="onboarding__tour-item">
							<IconSearch :size="22" class="onboarding__tour-icon" />
							<div>
								<span class="onboarding__tour-name">{{ t('hubs_start', 'Sök motpart') }}</span>
								<span class="onboarding__tour-desc">{{ t('hubs_start', 'Hitta en mottagare snabbt — kanalen klassas automatiskt.') }}</span>
							</div>
						</li>
					</ul>
				</section>

				<!-- Step 5: "Kom igång"-checklista -->
				<section v-else-if="step === 5" class="onboarding__step">
					<IconCheckAll :size="40" class="onboarding__hero-icon" />
					<h2 class="onboarding__title">{{ t('hubs_start', 'Kom igång') }}</h2>
					<p class="onboarding__lead">
						{{ t('hubs_start', 'Du är redo. Här är några bra första steg:') }}
					</p>
					<ul class="onboarding__checklist">
						<li v-if="needsLoa3Hint" class="onboarding__check">
							<IconShieldCheck :size="20" class="onboarding__check-icon" />
							{{ t('hubs_start', 'Legitimera dig med BankID för full åtkomst.') }}
						</li>
						<li class="onboarding__check">
							<IconCheck :size="20" class="onboarding__check-icon" />
							{{ t('hubs_start', 'Gå igenom kön "Att hantera" och ta ett ägarlöst ärende.') }}
						</li>
						<li v-if="apps.mail" class="onboarding__check">
							<IconCheck :size="20" class="onboarding__check-icon" />
							{{ t('hubs_start', 'Skicka ditt första säkra meddelande.') }}
						</li>
						<li v-if="apps.spreed" class="onboarding__check">
							<IconCheck :size="20" class="onboarding__check-icon" />
							{{ t('hubs_start', 'Boka ett säkert möte och testa kallelse via SMS.') }}
						</li>
						<li class="onboarding__check">
							<IconCheck :size="20" class="onboarding__check-icon" />
							{{ t('hubs_start', 'Håll koll på dina kvittenser för skickade meddelanden.') }}
						</li>
					</ul>
				</section>
			</div>

			<!-- Navigation -->
			<div class="onboarding__nav">
				<NcButton
					class="onboarding__skip"
					type="tertiary"
					@click="onSkip">
					{{ t('hubs_start', 'Hoppa över') }}
				</NcButton>

				<div class="onboarding__nav-main">
					<NcButton
						v-if="step > 1"
						type="secondary"
						@click="back">
						<template #icon>
							<IconArrowLeft :size="20" />
						</template>
						{{ t('hubs_start', 'Tillbaka') }}
					</NcButton>

					<NcButton
						v-if="step < totalSteps"
						type="primary"
						@click="next">
						<template #icon>
							<IconArrowRight :size="20" />
						</template>
						{{ t('hubs_start', 'Nästa') }}
					</NcButton>

					<NcButton
						v-else
						type="primary"
						@click="finish">
						<template #icon>
							<IconCheck :size="20" />
						</template>
						{{ t('hubs_start', 'Kom igång') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import IconRocket from 'vue-material-design-icons/RocketLaunchOutline.vue'
import IconShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'
import IconShieldAlert from 'vue-material-design-icons/ShieldAlertOutline.vue'
import IconMailbox from 'vue-material-design-icons/EmailMultiple.vue'
import IconGesture from 'vue-material-design-icons/GestureTapButton.vue'
import IconCheckAll from 'vue-material-design-icons/CheckAll.vue'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconMessage from 'vue-material-design-icons/MessageTextLock.vue'
import IconCalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'
import IconVideo from 'vue-material-design-icons/Video.vue'
import IconSearch from 'vue-material-design-icons/Magnify.vue'
import IconArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import IconArrowRight from 'vue-material-design-icons/ArrowRight.vue'

export default {
	name: 'Onboarding',

	components: {
		NcModal,
		NcButton,
		IconRocket,
		IconShieldCheck,
		IconShieldAlert,
		IconMailbox,
		IconGesture,
		IconCheckAll,
		IconCheck,
		IconMessage,
		IconCalendarPlus,
		IconVideo,
		IconSearch,
		IconArrowLeft,
		IconArrowRight,
	},

	props: {
		loa: {
			type: String,
			default: 'LOA1',
		},
		apps: {
			type: Object,
			required: true,
		},
		mailboxes: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			step: 1,
			totalSteps: 5,
		}
	},

	computed: {
		/** Show the gentle BankID legitimation hint when not yet on LOA3. */
		needsLoa3Hint() {
			return this.loa !== 'LOA3'
		},

		/** Accessible label for the current step's content group. */
		currentStepLabel() {
			return t('hubs_start', 'Steg {step} av {total}', {
				step: this.step,
				total: this.totalSteps,
			})
		},
	},

	methods: {
		t,

		/** Accessible label for a single progress dot. */
		dotLabel(n) {
			return t('hubs_start', 'Steg {step} av {total}', {
				step: n,
				total: this.totalSteps,
			})
		},

		next() {
			if (this.step < this.totalSteps) {
				this.step += 1
			}
		},

		back() {
			if (this.step > 1) {
				this.step -= 1
			}
		},

		/** Final-step confirmation. */
		finish() {
			this.$emit('finish')
		},

		/** Esc / skip / close — same outcome as finishing. */
		onSkip() {
			this.$emit('finish')
		},
	},
}
</script>

<style scoped lang="scss">
.onboarding {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 24px;
	min-height: 380px;

	&__dots {
		display: flex;
		justify-content: center;
		gap: 10px;
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__dot {
		width: 10px;
		height: 10px;
		border-radius: 50%;
		background: var(--color-background-dark);
		transition: background-color 0.15s ease, transform 0.15s ease;

		&--done {
			background: var(--hs-status-success);
		}

		&--active {
			background: var(--color-primary-element);
			transform: scale(1.3);
		}
	}

	&__body {
		flex: 1 1 auto;
	}

	&__step {
		display: flex;
		flex-direction: column;
		gap: 12px;
		text-align: left;
	}

	&__hero-icon {
		color: var(--color-primary-element);
	}

	&__title {
		margin: 0;
		font-size: 1.3rem;
		font-weight: 700;
	}

	&__lead {
		margin: 0;
		font-size: 1rem;
		line-height: 1.5;
	}

	&__points,
	&__checklist,
	&__tour,
	&__mailboxes {
		display: flex;
		flex-direction: column;
		gap: 8px;
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__points li {
		position: relative;
		padding-left: 20px;
		line-height: 1.4;

		&::before {
			content: '';
			position: absolute;
			left: 4px;
			top: 0.55em;
			width: 6px;
			height: 6px;
			border-radius: 50%;
			background: var(--color-primary-element);
		}
	}

	&__loa-list {
		display: flex;
		flex-direction: column;
		gap: 10px;
		margin: 0;
	}

	&__loa-row {
		padding: 10px 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius);
	}

	&__loa-name {
		margin: 0;
		font-weight: 600;
	}

	&__loa-desc {
		margin: 2px 0 0;
		color: var(--color-text-maxcontrast);
		line-height: 1.4;
	}

	&__why {
		margin: 0;
		color: var(--color-text-maxcontrast);
		line-height: 1.4;
	}

	&__hint {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		padding: 12px;
		border: 1px solid var(--hs-status-warning);
		border-radius: var(--border-radius);
		background: rgba(199, 135, 11, 0.1);
	}

	&__hint-icon {
		flex: 0 0 auto;
		color: var(--hs-status-warning);
	}

	&__hint-text {
		margin: 0;
		line-height: 1.4;
	}

	&__mailbox,
	&__check,
	&__tour-item {
		display: flex;
		align-items: flex-start;
		gap: 10px;
		line-height: 1.4;
	}

	&__mailbox {
		align-items: center;
		padding: 8px 0;

		& + & {
			border-top: 1px solid var(--color-border);
		}
	}

	&__mailbox-icon,
	&__check-icon,
	&__tour-icon {
		flex: 0 0 auto;
		color: var(--color-primary-element);
	}

	&__mailbox-name {
		font-weight: 500;
	}

	&__muted {
		margin: 0;
		color: var(--color-text-maxcontrast);
		line-height: 1.4;
	}

	&__tour-item div {
		display: flex;
		flex-direction: column;
	}

	&__tour-name {
		font-weight: 600;
	}

	&__tour-desc {
		color: var(--color-text-maxcontrast);
	}

	&__nav {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		flex-wrap: wrap;
		border-top: 1px solid var(--color-border);
		padding-top: 16px;
	}

	&__nav-main {
		display: flex;
		gap: 8px;
		margin-left: auto;
	}
}

.hidden-visually {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	border: 0;
}
</style>
