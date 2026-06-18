<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="hs-tour">
		<!-- Steg 0: dismissbar ingångsbanner (icke-blockerande) -->
		<div
			v-if="phase === 'banner'"
			class="hs-tour__banner hs-card"
			role="region"
			:aria-label="t('hubs_start', 'Introduktion till vyn')">
			<MapMarkerPathIcon class="hs-tour__banner-icon" :size="22" />
			<p class="hs-tour__banner-text">
				{{ t('hubs_start', 'Ny här? Så här hänger vyn ihop') }}
			</p>
			<div class="hs-tour__banner-actions">
				<NcButton type="primary" class="hs-target" @click="startTour">
					{{ t('hubs_start', 'Visa mig') }}
					<template #icon>
						<ArrowRightIcon :size="18" />
					</template>
				</NcButton>
				<NcButton type="tertiary" class="hs-target" @click="skip">
					{{ t('hubs_start', 'Hoppa över') }}
				</NcButton>
			</div>
		</div>

		<!-- Coach-stegen: lättviktig egen overlay, icke-blockerande -->
		<template v-else-if="phase === 'coach'">
			<!-- Hålet runt målet (highlight). Stänger inte vid klick → icke-blockerande. -->
			<div
				class="hs-tour__spotlight"
				:style="spotlightStyle"
				aria-hidden="true" />

			<!-- Coach-bubblan -->
			<div
				ref="bubble"
				class="hs-tour__bubble hs-card"
				:class="'hs-tour__bubble--' + bubblePlacement"
				:style="bubbleStyle"
				role="dialog"
				aria-modal="false"
				:aria-label="t('hubs_start', 'Rundtur, steg {n} av {total}', { n: step + 1, total: steps.length })">
				<span class="hs-tour__arrow" :class="'hs-tour__arrow--' + bubblePlacement" aria-hidden="true" />

				<p class="hs-tour__count">
					{{ t('hubs_start', 'Steg {n} av {total}', { n: step + 1, total: steps.length }) }}
				</p>
				<h3 class="hs-tour__title">
					<component :is="current.icon" :size="20" class="hs-tour__title-icon" />
					{{ current.title }}
				</h3>
				<p class="hs-tour__body">
					{{ current.body }}
				</p>

				<div class="hs-tour__nav">
					<NcButton type="tertiary" class="hs-target" @click="skip">
						{{ t('hubs_start', 'Hoppa över') }}
					</NcButton>
					<NcButton type="primary" class="hs-target" @click="next">
						{{ isLast ? t('hubs_start', 'Klart') : t('hubs_start', 'Nästa') }}
						<template #icon>
							<CheckIcon v-if="isLast" :size="18" />
							<ArrowRightIcon v-else :size="18" />
						</template>
					</NcButton>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import MapMarkerPathIcon from 'vue-material-design-icons/MapMarkerPath.vue'
import PulseIcon from 'vue-material-design-icons/Pulse.vue'
import StairsIcon from 'vue-material-design-icons/Stairs.vue'
import GestureTapButtonIcon from 'vue-material-design-icons/GestureTapButton.vue'
import { translate as t } from '@nextcloud/l10n'

// Fallbacks så bubblan har en vettig plats även om ett mål ännu inte målats.
const FALLBACK_RECT = { top: 120, left: 24, width: 320, height: 64 }

export default {
	name: 'OnboardingTour',

	components: {
		NcButton,
		ArrowRightIcon,
		CheckIcon,
		MapMarkerPathIcon,
	},

	props: {
		/** Om guiden redan setts — då visas inget. */
		seen: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			// 'banner' → ingångsraden; 'coach' → de tre stegen.
			phase: 'banner',
			step: 0,
			targetRect: { ...FALLBACK_RECT },
			bubblePlacement: 'bottom',
			bubbleStyle: {},
			// De tre coach-stegen. selector pekar ut målet i DOM (best effort).
			steps: [
				{
					selector: '[data-tour="dagspulsen"]',
					icon: PulseIcon,
					title: t('hubs_start', 'Dagspulsen'),
					body: t('hubs_start', 'Dagens läge i fyra tal: frister som brinner, möten, sådant att signera och nya inkommande. Klicka på ett tal för att filtrera listan.'),
				},
				{
					selector: '[data-tour="stepper"]',
					icon: StairsIcon,
					title: t('hubs_start', 'Ärendets väg'),
					body: t('hubs_start', 'Så här går ett barnärende — uppifrån och ned: Förhandsbedömning, Utredning, Beslut, Uppföljning, Avslutat. Steppern visar var ärendet står just nu.'),
				},
				{
					selector: '[data-tour="nasta-atgard"]',
					icon: GestureTapButtonIcon,
					title: t('hubs_start', 'Nästa åtgärd'),
					body: t('hubs_start', 'Du behöver aldrig räkna ut nästa steg själv — tryck på knappen som lyser, så tar vyn dig dit.'),
				},
			],
		}
	},

	computed: {
		current() {
			return this.steps[this.step]
		},
		isLast() {
			return this.step === this.steps.length - 1
		},
		/** Highlight-ramen kring det aktuella målet. */
		spotlightStyle() {
			const r = this.targetRect
			const pad = 6
			return {
				top: (r.top - pad) + 'px',
				left: (r.left - pad) + 'px',
				width: (r.width + pad * 2) + 'px',
				height: (r.height + pad * 2) + 'px',
			}
		},
	},

	watch: {
		// Vid scroll/omflöde följer overlay-positionerna med.
		step() {
			this.$nextTick(this.positionToTarget)
		},
	},

	mounted() {
		window.addEventListener('resize', this.onReflow, { passive: true })
		window.addEventListener('scroll', this.onReflow, { passive: true, capture: true })
	},

	beforeDestroy() {
		window.removeEventListener('resize', this.onReflow)
		window.removeEventListener('scroll', this.onReflow, true)
	},

	methods: {
		t,

		startTour() {
			this.phase = 'coach'
			this.step = 0
			this.$nextTick(this.positionToTarget)
		},

		next() {
			if (this.isLast) {
				this.finish()
				return
			}
			this.step += 1
		},

		/** "Hoppa över" → samma utgång som att slutföra (markeras som sedd). */
		skip() {
			this.finish()
		},

		finish() {
			this.phase = 'done'
			this.$emit('finish')
		},

		onReflow() {
			if (this.phase === 'coach') {
				this.positionToTarget()
			}
		},

		/** Mät målet i DOM och placera spotlight + bubbla. Faller tillbaka mjukt. */
		positionToTarget() {
			const el = document.querySelector(this.current.selector)
			const rect = el ? el.getBoundingClientRect() : null
			if (rect && rect.width > 0 && rect.height > 0) {
				this.targetRect = { top: rect.top, left: rect.left, width: rect.width, height: rect.height }
				if (el.scrollIntoView) {
					el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' })
				}
			} else {
				// Inget mål målat ännu — centrera bubblan utan spotlight.
				this.targetRect = {
					top: window.innerHeight / 2 - 32,
					left: Math.max(24, window.innerWidth / 2 - 160),
					width: 320,
					height: 64,
				}
			}
			this.placeBubble()
		},

		/** Lägg bubblan under målet om plats finns, annars över. */
		placeBubble() {
			const r = this.targetRect
			const bubbleH = 200
			const gap = 16
			const below = r.top + r.height + gap
			const placeBelow = (below + bubbleH) < window.innerHeight
			this.bubblePlacement = placeBelow ? 'bottom' : 'top'

			const maxLeft = window.innerWidth - 340
			const left = Math.min(Math.max(16, r.left), Math.max(16, maxLeft))
			const top = placeBelow ? below : Math.max(16, r.top - gap - bubbleH)
			this.bubbleStyle = { top: top + 'px', left: left + 'px' }
		},
	},
}
</script>

<style scoped lang="scss">
.hs-tour {
	// Ingen heltäckande, klickfångande backdrop → icke-blockerande.

	&__banner {
		display: flex;
		align-items: center;
		gap: 12px;
		flex-wrap: wrap;
		border-left: 4px solid var(--color-primary-element);
		background: var(--color-primary-element-light, var(--color-background-hover));
	}

	&__banner-icon {
		color: var(--color-primary-element);
		flex: 0 0 auto;
	}

	&__banner-text {
		margin: 0;
		font-weight: 600;
		flex: 1 1 auto;
		min-width: 180px;
	}

	&__banner-actions {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
	}

	// --- Coach-stegen -----------------------------------------------------
	&__spotlight {
		position: fixed;
		z-index: 2000;
		border: 2px solid var(--color-primary-element);
		border-radius: var(--hs-card-radius);
		box-shadow: 0 0 0 4px var(--color-primary-element-light, rgba(0, 0, 0, 0.08)),
			0 0 0 9999px rgba(0, 0, 0, 0.32);
		pointer-events: none; // släpper igenom klick → icke-blockerande
		transition: top 0.25s ease, left 0.25s ease, width 0.25s ease, height 0.25s ease;
	}

	&__bubble {
		position: fixed;
		z-index: 2001;
		width: 320px;
		max-width: calc(100vw - 32px);
		box-shadow: 0 8px 24px rgba(0, 0, 0, 0.24);
		transition: top 0.2s ease, left 0.2s ease;
	}

	&__arrow {
		position: absolute;
		left: 24px;
		width: 14px;
		height: 14px;
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		transform: rotate(45deg);

		&--bottom {
			top: -8px;
			border-right: none;
			border-bottom: none;
		}

		&--top {
			bottom: -8px;
			border-left: none;
			border-top: none;
		}
	}

	&__count {
		margin: 0 0 4px;
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
	}

	&__title {
		margin: 0 0 8px;
		font-size: 1.05rem;
		font-weight: 600;
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__title-icon {
		color: var(--color-primary-element);
		flex: 0 0 auto;
	}

	&__body {
		margin: 0 0 16px;
		font-size: 0.92rem;
		line-height: 1.45;
		color: var(--color-main-text);
	}

	&__nav {
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 8px;
	}
}
</style>
