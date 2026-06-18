<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="hs-card kvittens">
		<h2 class="hs-card__title">
			{{ t('hubs_start', 'Skickat — kvittenser') }}
		</h2>

		<NcEmptyContent
			v-if="!sortedReceipts.length"
			:name="t('hubs_start', 'Inga skickade meddelanden ännu')"
			:description="t('hubs_start', 'Här visas kvittenser för det du skickar — när det levererats, lästs och besvarats.')">
			<template #icon>
				<IconCheck :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="kvittens__list">
			<li
				v-for="receipt in sortedReceipts"
				:key="receipt.messageId"
				class="kvittens__row"
				:class="{ 'kvittens__row--problem': isProblem(receipt) }">
				<button
					type="button"
					class="kvittens__open hs-target"
					:aria-label="rowLabel(receipt)"
					@click="onOpen(receipt)">
					<span class="kvittens__channel" :style="channelStyle(receipt)">
						<component :is="channelMeta(receipt.channel).icon" :size="20" />
					</span>

					<span class="kvittens__body">
						<span class="kvittens__recipient">{{ receipt.recipient }}</span>
						<span class="kvittens__meta">
							{{ channelMeta(receipt.channel).label }}
							<span v-if="receipt.updatedAt" class="kvittens__time">
								· {{ formatTime(receipt.updatedAt) }}
							</span>
						</span>

						<!-- 4-step status pill: Skickat → Levererat → Läst → Besvarat -->
						<span
							v-if="!isProblem(receipt)"
							class="kvittens__pill"
							role="img"
							:aria-label="pillLabel(receipt)">
							<span
								v-for="step in steps"
								:key="step.key"
								class="kvittens__step"
								:class="{
									'kvittens__step--reached': stepIndex(receipt) >= step.index,
									'kvittens__step--current': stepIndex(receipt) === step.index,
								}">
								<span class="kvittens__dot" aria-hidden="true" />
								<span class="kvittens__step-label">{{ step.label }}</span>
							</span>
						</span>

						<!-- Problem state: error tone, no progress pill -->
						<span v-else class="kvittens__problem-tag">
							{{ t('hubs_start', 'Problem — kunde inte levereras') }}
						</span>
					</span>
				</button>
			</li>
		</ul>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import IconCheck from 'vue-material-design-icons/CheckCircleOutline.vue'

import { translate as t } from '@nextcloud/l10n'
import { channelMeta } from '../services/channels.js'

/**
 * Ordered progress steps for the delivery pill. The server's `state` field maps
 * to a step index; `problem` is handled separately with an error tone.
 */
const STEP_ORDER = ['skickat', 'levererat', 'last', 'besvarat']

export default {
	name: 'KvittensWidget',

	components: {
		NcEmptyContent,
		IconCheck,
	},

	props: {
		/**
		 * @type {Array<{messageId:(string|number), recipient:string,
		 *   channel:string, state:('skickat'|'levererat'|'last'|'besvarat'|'problem'),
		 *   updatedAt:string, deepLink:object}>}
		 */
		receipts: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			steps: [
				{ key: 'skickat', index: 0, label: t('hubs_start', 'Skickat') },
				{ key: 'levererat', index: 1, label: t('hubs_start', 'Levererat') },
				{ key: 'last', index: 2, label: t('hubs_start', 'Läst') },
				{ key: 'besvarat', index: 3, label: t('hubs_start', 'Besvarat') },
			],
		}
	},

	computed: {
		/** Problem rows first; otherwise newest update first. */
		sortedReceipts() {
			return [...this.receipts].sort((a, b) => {
				const ap = this.isProblem(a) ? 0 : 1
				const bp = this.isProblem(b) ? 0 : 1
				if (ap !== bp) {
					return ap - bp
				}
				return String(b.updatedAt || '').localeCompare(String(a.updatedAt || ''))
			})
		},
	},

	methods: {
		t,
		channelMeta,

		isProblem(receipt) {
			return receipt.state === 'problem'
		},

		/** Index (0–3) reached in the 4-step pill for a non-problem state. */
		stepIndex(receipt) {
			const i = STEP_ORDER.indexOf(receipt.state)
			return i === -1 ? 0 : i
		},

		channelStyle(receipt) {
			const meta = channelMeta(receipt.channel)
			return { color: `var(${meta.colorVar})` }
		},

		formatTime(iso) {
			const d = new Date(iso)
			if (isNaN(d.getTime())) {
				return ''
			}
			return d.toLocaleString(undefined, {
				month: 'short',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		pillLabel(receipt) {
			const reached = this.steps[this.stepIndex(receipt)]
			return t('hubs_start', 'Status: {state}', { state: reached.label })
		},

		rowLabel(receipt) {
			if (this.isProblem(receipt)) {
				return t('hubs_start', 'Öppna meddelande till {recipient} — problem med leverans', { recipient: receipt.recipient })
			}
			return t('hubs_start', 'Öppna meddelande till {recipient} — {state}', {
				recipient: receipt.recipient,
				state: this.steps[this.stepIndex(receipt)].label,
			})
		},

		onOpen(receipt) {
			this.$emit('open', { deepLink: receipt.deepLink })
		},
	},
}
</script>

<style scoped lang="scss">
.kvittens {
	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__row {
		border-radius: var(--border-radius-large, 12px);

		&--problem {
			background: var(--color-error, #c7280b);
			background: color-mix(in srgb, var(--hs-status-error) 8%, transparent);
		}
	}

	&__open {
		display: flex;
		align-items: flex-start;
		gap: 12px;
		width: 100%;
		min-height: 44px;
		padding: 8px;
		background: transparent;
		border: 2px solid transparent;
		border-radius: var(--border-radius-large, 12px);
		text-align: start;
		cursor: pointer;
		color: var(--color-main-text);

		&:hover {
			background: var(--color-background-hover);
		}

		&:focus-visible {
			outline: none;
			border-color: var(--color-primary-element);
		}
	}

	&__channel {
		display: flex;
		align-items: center;
		justify-content: center;
		flex: 0 0 auto;
		width: 32px;
		height: 32px;
	}

	&__body {
		display: flex;
		flex-direction: column;
		gap: 4px;
		min-width: 0;
		flex: 1 1 auto;
	}

	&__recipient {
		font-weight: 600;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__meta {
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
	}

	&__time {
		white-space: nowrap;
	}

	/* 4-step progress pill */
	&__pill {
		display: flex;
		align-items: flex-start;
		gap: 0;
		margin-top: 4px;
	}

	&__step {
		display: flex;
		flex-direction: column;
		align-items: center;
		flex: 1 1 0;
		position: relative;
		font-size: 0.72rem;
		color: var(--color-text-maxcontrast);

		// connector line between dots
		&::before {
			content: '';
			position: absolute;
			top: 5px;
			right: 50%;
			left: -50%;
			height: 2px;
			background: var(--color-border-dark, var(--color-border));
		}

		&:first-child::before {
			display: none;
		}

		&--reached {
			color: var(--hs-status-success);

			.kvittens__dot {
				background: var(--hs-status-success);
				border-color: var(--hs-status-success);
			}

			&::before {
				background: var(--hs-status-success);
			}
		}

		&--current {
			font-weight: 600;
		}
	}

	&__dot {
		width: 12px;
		height: 12px;
		border-radius: 50%;
		border: 2px solid var(--color-border-dark, var(--color-border));
		background: var(--color-main-background);
		z-index: 1;
	}

	&__step-label {
		margin-top: 2px;
		text-align: center;
		line-height: 1.1;
	}

	&__problem-tag {
		display: inline-flex;
		align-items: center;
		align-self: flex-start;
		margin-top: 2px;
		padding: 2px 8px;
		border-radius: var(--border-radius-pill, 100px);
		font-size: 0.78rem;
		font-weight: 600;
		color: var(--color-main-background);
		background: var(--hs-status-error);
	}
}
</style>
