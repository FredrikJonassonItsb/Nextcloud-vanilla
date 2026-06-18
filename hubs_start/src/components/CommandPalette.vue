<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<NcModal
		size="normal"
		:name="t('hubs_start', 'Snabbkommandon')"
		@close="onClose">
		<div class="hs-command-palette" @keydown="onKeydown">
			<!-- Search field (autofocused on open) -->
			<NcTextField
				ref="field"
				class="hs-command-palette__field"
				:value.sync="query"
				type="text"
				:label="t('hubs_start', 'Sök kommando eller motpart')"
				:label-visible="true"
				:show-trailing-button="false"
				role="combobox"
				aria-controls="hs-command-palette-list"
				aria-autocomplete="list"
				:aria-expanded="String(results.length > 0)"
				:aria-activedescendant="activeId"
				@update:value="onInput">
				<template #icon>
					<IconSearch :size="18" />
				</template>
			</NcTextField>

			<!-- Combobox results -->
			<ul
				id="hs-command-palette-list"
				class="hs-command-palette__list"
				role="listbox"
				:aria-label="t('hubs_start', 'Resultat')">
				<li
					v-for="(entry, index) in results"
					:id="optionId(index)"
					:key="entry.key"
					class="hs-command-palette__option"
					:class="{ 'hs-command-palette__option--active': index === activeIndex }"
					role="option"
					:aria-selected="String(index === activeIndex)"
					@click="choose(entry)"
					@mousemove="activeIndex = index">
					<span class="hs-command-palette__icon" aria-hidden="true">
						<component :is="entry.icon" v-if="entry.icon" :size="20" />
					</span>
					<span class="hs-command-palette__text">
						<span class="hs-command-palette__label">{{ entry.label }}</span>
						<span v-if="entry.sublabel" class="hs-command-palette__sublabel">{{ entry.sublabel }}</span>
					</span>
				</li>
			</ul>

			<!-- Loading / empty feedback -->
			<p
				v-if="searching"
				class="hs-command-palette__hint"
				aria-live="polite">
				<NcLoadingIcon :size="20" />
				{{ t('hubs_start', 'Söker motparter …') }}
			</p>
			<p
				v-else-if="query && !recipients.length"
				class="hs-command-palette__hint"
				aria-live="polite">
				{{ t('hubs_start', 'Inga motparter hittades') }}
			</p>

			<p class="hs-command-palette__footer">
				{{ t('hubs_start', 'Använd piltangenterna för att välja, Enter för att öppna, Esc för att stänga.') }}
			</p>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import IconSearch from 'vue-material-design-icons/Magnify.vue'
import IconMessage from 'vue-material-design-icons/MessageTextLock.vue'
import IconCalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'
import IconVideo from 'vue-material-design-icons/Video.vue'

import { translate as t } from '@nextcloud/l10n'
import api from '../services/api.js'
import { channelMeta } from '../services/channels.js'

const SEARCH_DEBOUNCE_MS = 250

export default {
	name: 'CommandPalette',

	components: {
		NcModal,
		NcTextField,
		NcLoadingIcon,
		IconSearch,
		IconMessage,
		IconCalendarPlus,
		IconVideo,
	},

	data() {
		return {
			query: '',
			recipients: [],
			searching: false,
			activeIndex: 0,
			searchTimer: null,
			searchSeq: 0,
		}
	},

	computed: {
		/** Static actions, filtered by the current query. */
		actions() {
			const all = [
				{
					key: 'action:new-message',
					event: 'new-message',
					label: t('hubs_start', 'Nytt säkert meddelande'),
					icon: IconMessage,
				},
				{
					key: 'action:book-meeting',
					event: 'book-meeting',
					label: t('hubs_start', 'Boka säkert möte'),
					icon: IconCalendarPlus,
				},
				{
					key: 'action:start-meeting',
					event: 'start-meeting',
					label: t('hubs_start', 'Starta möte nu'),
					icon: IconVideo,
				},
			]
			const q = this.query.trim().toLowerCase()
			if (!q) {
				return all
			}
			return all.filter((a) => a.label.toLowerCase().includes(q))
		},

		/** Recipient hits mapped to combobox entries (server-classified channel). */
		recipientEntries() {
			return this.recipients.map((r) => {
				const meta = channelMeta(r.classification?.channel)
				return {
					key: 'recipient:' + r.id,
					event: 'new-message',
					recipient: r,
					label: r.displayName || r.address,
					sublabel: meta.label,
					icon: meta.icon,
				}
			})
		},

		/** Combined, ordered combobox list: actions first, then live recipients. */
		results() {
			return [...this.actions, ...this.recipientEntries]
		},

		/** id of the currently active option, for aria-activedescendant. */
		activeId() {
			return this.results.length ? this.optionId(this.activeIndex) : null
		},
	},

	watch: {
		results() {
			// Keep the active index in range as the list changes.
			if (this.activeIndex >= this.results.length) {
				this.activeIndex = Math.max(0, this.results.length - 1)
			}
		},
	},

	mounted() {
		this.$nextTick(() => this.focusField())
	},

	beforeDestroy() {
		if (this.searchTimer) {
			clearTimeout(this.searchTimer)
		}
	},

	methods: {
		t,

		optionId(index) {
			return 'hs-command-palette-option-' + index
		},

		focusField() {
			const field = this.$refs.field
			const el = field?.$el?.querySelector('input')
			if (el) {
				el.focus()
			}
		},

		onInput() {
			this.activeIndex = 0
			if (this.searchTimer) {
				clearTimeout(this.searchTimer)
			}
			const q = this.query.trim()
			if (!q) {
				this.recipients = []
				this.searching = false
				return
			}
			this.searchTimer = setTimeout(() => this.runSearch(q), SEARCH_DEBOUNCE_MS)
		},

		async runSearch(q) {
			const seq = ++this.searchSeq
			this.searching = true
			try {
				const hits = await api.searchRecipients(q)
				// Ignore stale responses (out-of-order resolution).
				if (seq !== this.searchSeq) {
					return
				}
				this.recipients = Array.isArray(hits) ? hits : []
			} catch (e) {
				if (seq === this.searchSeq) {
					this.recipients = []
				}
			} finally {
				if (seq === this.searchSeq) {
					this.searching = false
				}
			}
		},

		onKeydown(e) {
			switch (e.key) {
			case 'ArrowDown':
				e.preventDefault()
				this.move(1)
				break
			case 'ArrowUp':
				e.preventDefault()
				this.move(-1)
				break
			case 'Enter':
				e.preventDefault()
				if (this.results.length) {
					this.choose(this.results[this.activeIndex])
				}
				break
			case 'Escape':
				e.preventDefault()
				this.onClose()
				break
			}
		},

		move(delta) {
			const n = this.results.length
			if (!n) {
				return
			}
			this.activeIndex = (this.activeIndex + delta + n) % n
		},

		choose(entry) {
			if (!entry) {
				return
			}
			if (entry.recipient) {
				// Choosing a recipient opens the composer flow via Smart mottagare.
				this.$emit('new-message')
			} else {
				this.$emit(entry.event)
			}
			this.onClose()
		},

		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped lang="scss">
.hs-command-palette {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;

	&__field {
		width: 100%;
	}

	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
		max-height: 50vh;
		overflow-y: auto;
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__option {
		display: flex;
		align-items: center;
		gap: 12px;
		min-height: 44px;
		padding: 6px 10px;
		border-radius: var(--border-radius-large, 12px);
		cursor: pointer;

		&--active,
		&:hover {
			background: var(--color-background-hover);
		}

		// Focus is driven by aria-activedescendant; make the active row visible.
		&--active {
			outline: 2px solid var(--color-primary-element);
			outline-offset: -2px;
		}
	}

	&__icon {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		min-width: var(--hs-min-target);
		min-height: var(--hs-min-target);
		color: var(--color-text-maxcontrast);
	}

	&__text {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__label {
		font-weight: 600;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__sublabel {
		font-size: 0.82rem;
		color: var(--color-text-maxcontrast);
	}

	&__hint {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
	}

	&__footer {
		margin: 4px 0 0;
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
	}
}
</style>
