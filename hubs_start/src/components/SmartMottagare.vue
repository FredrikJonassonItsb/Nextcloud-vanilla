<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<NcModal
		:name="t('hubs_start', 'Sök motpart')"
		size="normal"
		@close="onClose">
		<div class="smart-mottagare">
			<h2 class="smart-mottagare__title">
				<IconAccountSearch :size="20" />
				{{ t('hubs_start', 'Sök motpart') }}
			</h2>

			<p class="smart-mottagare__intro">
				{{ t('hubs_start', 'Sök på namn, organisation, personnummer, e-post eller telefonnummer. Systemet väljer rätt kanal automatiskt.') }}
			</p>

			<NcTextField
				ref="searchField"
				class="smart-mottagare__search"
				:value.sync="query"
				:label="t('hubs_start', 'Sök motpart')"
				:label-visible="true"
				type="search"
				:show-trailing-button="!!query"
				:trailing-button-label="t('hubs_start', 'Rensa sökning')"
				@input="onQueryInput"
				@trailing-button-click="clearQuery"
				@keydown.down.prevent="focusFirstResult">
				<template #icon>
					<IconAccountSearch :size="20" />
				</template>
			</NcTextField>

			<!-- Free typed value → server classification, shown before choosing -->
			<div
				v-if="freeClassification"
				class="smart-mottagare__free"
				role="group"
				:aria-label="t('hubs_start', 'Fritt angiven motpart')">
				<div class="smart-mottagare__free-info">
					<span class="smart-mottagare__free-address">{{ query }}</span>
					<span class="smart-mottagare__chosen">
						{{ t('hubs_start', 'Systemet väljer kanal:') }}
						<span
							class="smart-mottagare__chip"
							:style="{ '--chip-color': 'var(' + freeChip.colorVar + ')' }">
							<component :is="freeChip.icon" :size="16" />
							{{ freeChip.label }}
						</span>
					</span>
				</div>
				<NcButton
					type="primary"
					:disabled="freeClassification.channel === 'unknown'"
					@click="chooseFree">
					{{ t('hubs_start', 'Välj') }}
				</NcButton>
			</div>

			<!-- Loading / empty / results -->
			<div class="smart-mottagare__results" aria-live="polite">
				<div v-if="loading" class="smart-mottagare__loading">
					<NcLoadingIcon :size="32" />
					<span>{{ t('hubs_start', 'Söker …') }}</span>
				</div>

				<NcEmptyContent
					v-else-if="query && !candidates.length"
					:name="t('hubs_start', 'Inga träffar')"
					:description="t('hubs_start', 'Prova ett annat sökord, eller skriv in en exakt adress för att låta systemet välja kanal.')">
					<template #icon>
						<IconAccountSearch :size="20" />
					</template>
				</NcEmptyContent>

				<ul
					v-else-if="candidates.length"
					class="smart-mottagare__list">
					<template v-for="group in groupedCandidates">
						<li
							:key="'h-' + group.channel"
							class="smart-mottagare__group-header"
							role="presentation">
							<span
								class="smart-mottagare__chip smart-mottagare__chip--small"
								:style="{ '--chip-color': 'var(' + group.meta.colorVar + ')' }">
								<component :is="group.meta.icon" :size="16" />
								{{ group.meta.label }}
							</span>
						</li>
						<li
							v-for="(candidate, idx) in group.items"
							:key="candidate.id"
							class="smart-mottagare__candidate">
							<button
								:ref="'candidate'"
								type="button"
								class="smart-mottagare__candidate-btn"
								@click="choose(candidate)"
								@keydown.down.prevent="focusResult(globalIndex(group, idx) + 1)"
								@keydown.up.prevent="focusResult(globalIndex(group, idx) - 1)">
								<span class="smart-mottagare__candidate-main">
									<span class="smart-mottagare__candidate-name">{{ candidate.displayName }}</span>
									<span class="smart-mottagare__candidate-address">{{ candidate.address }}</span>
								</span>
								<span
									class="smart-mottagare__chip smart-mottagare__chip--small"
									:style="{ '--chip-color': 'var(' + chipFor(candidate).colorVar + ')' }">
									<component :is="chipFor(candidate).icon" :size="16" />
									{{ chipFor(candidate).label }}
								</span>
							</button>
						</li>
					</template>
				</ul>

				<p v-else class="smart-mottagare__hint">
					{{ t('hubs_start', 'Börja skriv för att söka motpart.') }}
				</p>
			</div>

			<p class="smart-mottagare__footer-note">
				{{ t('hubs_start', 'Kanalen bestäms av systemet utifrån mottagaren — du behöver inte välja den själv.') }}
			</p>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

import IconAccountSearch from 'vue-material-design-icons/AccountSearch.vue'

import { translate as t } from '@nextcloud/l10n'

import api from '../services/api.js'
import { channelMeta, CHANNEL_ORDER, CHANNEL_TO_MESSAGE_TYPE } from '../services/channels.js'

const DEBOUNCE_MS = 300

export default {
	name: 'SmartMottagare',

	components: {
		NcModal,
		NcButton,
		NcTextField,
		NcLoadingIcon,
		NcEmptyContent,
		IconAccountSearch,
	},

	data() {
		return {
			query: '',
			candidates: [],
			loading: false,
			/** @type {?import('../services/api.js').ChannelInfo} */
			freeClassification: null,
			debounceTimer: null,
			searchSeq: 0,
		}
	},

	computed: {
		/** Candidates grouped by their server-resolved channel, in canonical order. */
		groupedCandidates() {
			const byChannel = new Map()
			for (const candidate of this.candidates) {
				const channel = candidate.classification?.channel || 'unknown'
				if (!byChannel.has(channel)) {
					byChannel.set(channel, [])
				}
				byChannel.get(channel).push(candidate)
			}
			const order = [...CHANNEL_ORDER, 'unknown']
			const groups = []
			for (const channel of order) {
				const items = byChannel.get(channel)
				if (items && items.length) {
					groups.push({ channel, meta: channelMeta(channel), items })
				}
			}
			return groups
		},

		/** Presentation chip for the free-typed classification. */
		freeChip() {
			return channelMeta(this.freeClassification?.channel)
		},
	},

	mounted() {
		this.$nextTick(() => {
			const field = this.$refs.searchField
			const el = field?.$el?.querySelector('input')
			if (el) {
				el.focus()
			}
		})
	},

	beforeDestroy() {
		if (this.debounceTimer) {
			clearTimeout(this.debounceTimer)
		}
	},

	methods: {
		t,

		/** Presentation chip for a candidate (server-resolved channel only). */
		chipFor(candidate) {
			return channelMeta(candidate.classification?.channel)
		},

		/** Flat index of a candidate across all rendered groups (for arrow nav). */
		globalIndex(group, idxInGroup) {
			let base = 0
			for (const g of this.groupedCandidates) {
				if (g.channel === group.channel) {
					break
				}
				base += g.items.length
			}
			return base + idxInGroup
		},

		onQueryInput() {
			if (this.debounceTimer) {
				clearTimeout(this.debounceTimer)
			}
			const value = this.query
			if (!value || !value.trim()) {
				this.candidates = []
				this.freeClassification = null
				this.loading = false
				return
			}
			this.loading = true
			this.debounceTimer = setTimeout(() => {
				this.runSearch(value)
			}, DEBOUNCE_MS)
		},

		async runSearch(value) {
			const seq = ++this.searchSeq
			try {
				const [results, classification] = await Promise.all([
					api.searchRecipients(value),
					api.classifyRecipient(value).catch(() => null),
				])
				if (seq !== this.searchSeq) {
					return
				}
				this.candidates = Array.isArray(results) ? results : []
				this.freeClassification = classification || null
			} catch (e) {
				if (seq !== this.searchSeq) {
					return
				}
				this.candidates = []
				this.freeClassification = null
			} finally {
				if (seq === this.searchSeq) {
					this.loading = false
				}
			}
		},

		clearQuery() {
			this.query = ''
			this.candidates = []
			this.freeClassification = null
			this.loading = false
			if (this.debounceTimer) {
				clearTimeout(this.debounceTimer)
			}
			this.$nextTick(() => {
				const el = this.$refs.searchField?.$el?.querySelector('input')
				if (el) {
					el.focus()
				}
			})
		},

		/** Move focus to a result button by flat index (arrow-key navigation). */
		focusResult(index) {
			const buttons = this.$refs.candidate
			if (!Array.isArray(buttons) || !buttons.length) {
				return
			}
			const clamped = Math.max(0, Math.min(index, buttons.length - 1))
			buttons[clamped]?.focus()
		},

		focusFirstResult() {
			this.focusResult(0)
		},

		choose(candidate) {
			// Emit exactly the contract shape: { address, classification:{channel,messageType} }
			this.$emit('chosen', {
				address: candidate.address,
				classification: candidate.classification,
			})
		},

		chooseFree() {
			if (!this.freeClassification || this.freeClassification.channel === 'unknown') {
				return
			}
			const channel = this.freeClassification.channel
			const messageType = this.freeClassification.messageType
				|| CHANNEL_TO_MESSAGE_TYPE[channel]
			this.$emit('chosen', {
				address: this.query.trim(),
				classification: { channel, messageType },
			})
		},

		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped lang="scss">
.smart-mottagare {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;

	&__title {
		font-size: 1.15rem;
		font-weight: 600;
		margin: 0;
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__intro {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
	}

	&__search {
		margin-top: 4px;
	}

	&__free {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding: 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--hs-card-radius, 12px);
		background: var(--color-background-hover);
	}

	&__free-info {
		display: flex;
		flex-direction: column;
		gap: 6px;
		min-width: 0;
	}

	&__free-address {
		font-weight: 600;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__chosen {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		font-size: 0.9rem;
		color: var(--color-text-maxcontrast);
	}

	&__chip {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 2px 10px;
		min-height: var(--hs-min-target, 24px);
		border-radius: var(--border-radius-pill, 100px);
		font-size: 0.85rem;
		font-weight: 600;
		line-height: 1.2;
		color: var(--color-main-background);
		background: var(--chip-color, var(--hs-channel-unknown));

		&--small {
			font-size: 0.8rem;
			padding: 2px 8px;
		}
	}

	&__results {
		min-height: 60px;
	}

	&__loading {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 10px;
		padding: 24px 0;
		color: var(--color-text-maxcontrast);
	}

	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__group-header {
		padding: 8px 0 2px;
	}

	&__candidate {
		margin: 0;
	}

	&__candidate-btn {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		width: 100%;
		min-height: 44px;
		padding: 8px 10px;
		border: 1px solid transparent;
		border-radius: var(--border-radius, 8px);
		background: transparent;
		text-align: start;
		cursor: pointer;
		color: var(--color-main-text);

		&:hover {
			background: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: -2px;
		}
	}

	&__candidate-main {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
	}

	&__candidate-name {
		font-weight: 500;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__candidate-address {
		font-size: 0.82rem;
		color: var(--color-text-maxcontrast);
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__hint {
		margin: 0;
		padding: 16px 0;
		text-align: center;
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
	}

	&__footer-note {
		margin: 4px 0 0;
		font-size: 0.82rem;
		color: var(--color-text-maxcontrast);
	}
}
</style>
