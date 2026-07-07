<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Delad ANVÄNDARVÄLJARE — plattformens standard-autocomplete (samma källa
  - som delnings-väljaren) i stället för råa uid-textfält: handläggaren söker
  - på NAMN och väljer i listan (Fredrik 2026-07-07). Emittar uid som sträng
  - via v-model (value/input) så befintliga anropare byter in den rakt av.
  -->
<template>
	<NcSelect
		class="anvandar-valjare"
		:value="valt"
		:options="alternativ"
		:loading="soker"
		label="namn"
		:placeholder="placeholder"
		:input-label="inputLabel"
		:clearable="true"
		:filterable="false"
		:disabled="disabled"
		@search="onSok"
		@input="onValj">
		<template #no-options>
			{{ soktext ? t('hubs_start', 'Inga träffar — sök på namn eller användar-id.') : t('hubs_start', 'Skriv för att söka kollega.') }}
		</template>
	</NcSelect>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import { sokAnvandare } from '../../services/api.js'

export default {
	name: 'AnvandarValjare',

	components: { NcSelect },

	props: {
		/** Valt uid (v-model). */
		value: {
			type: String,
			default: '',
		},
		placeholder: {
			type: String,
			default: '',
		},
		inputLabel: {
			type: String,
			default: '',
		},
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			alternativ: [],
			soker: false,
			soktext: '',
			sokTimer: null,
		}
	},

	computed: {
		/** NcSelect vill ha objektet — bygg det ur uid:t (namn = uid tills träff finns). */
		valt() {
			if (!this.value) {
				return null
			}
			return this.alternativ.find((a) => a.uid === this.value) || { uid: this.value, namn: this.value }
		},
	},

	beforeDestroy() {
		clearTimeout(this.sokTimer)
	},

	methods: {
		t,

		/** Debounce:ad sökning mot plattformens autocomplete. */
		onSok(text) {
			this.soktext = (text || '').trim()
			clearTimeout(this.sokTimer)
			if (this.soktext.length < 2) {
				this.alternativ = []
				return
			}
			this.sokTimer = setTimeout(async () => {
				this.soker = true
				try {
					this.alternativ = await sokAnvandare(this.soktext)
				} catch (e) {
					this.alternativ = []
				} finally {
					this.soker = false
				}
			}, 250)
		},

		onValj(val) {
			this.$emit('input', val ? val.uid : '')
		},
	},
}
</script>

<style scoped lang="scss">
.anvandar-valjare {
	min-width: 220px;
}
</style>
