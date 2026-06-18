<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Presentational variant: KPI tiles + checklist + plain statements. Used for
  - frister, autentisering/LOA, datasuveränitet, loggretention, compliance.
  - See docs/DEMO-WIDGETS-CONTRACT.md.
-->
<template>
	<section class="hs-card hs-stat" :class="accentClass" :aria-label="title">
		<h3 class="hs-card__title">
			<component :is="iconFor(leadIcon)" :size="20" />
			{{ title }}
		</h3>

		<ul v-if="tiles.length" class="hs-stat__tiles">
			<li v-for="(tile, i) in tiles" :key="'t' + i" class="hs-stat__tile">
				<span class="hs-stat__value" :style="{ color: toneColor(tile.tone) }">{{ tile.value }}</span>
				<span class="hs-stat__label">{{ tile.label }}</span>
			</li>
		</ul>

		<ul v-if="checks.length" class="hs-stat__checks">
			<li v-for="(c, i) in checks" :key="'c' + i" class="hs-stat__check">
				<CheckCircleIcon v-if="c.ok" :size="18" class="hs-stat__check-ok" />
				<AlertCircleIcon v-else :size="18" class="hs-stat__check-no" />
				<span class="hs-stat__check-label">{{ c.label }}</span>
				<span v-if="c.detail" class="hs-stat__check-detail">{{ c.detail }}</span>
			</li>
		</ul>

		<ul v-if="statements.length" class="hs-stat__statements">
			<li v-for="(s, i) in statements" :key="'s' + i" class="hs-stat__statement">
				<ShieldCheckIcon :size="16" />
				{{ s }}
			</li>
		</ul>

		<!-- Optional search affordance (e.g. SDK-log lookup by AS4 id) -->
		<div v-if="searchLine" class="hs-stat__search">
			<label class="hs-stat__search-label">{{ searchLine.label }}</label>
			<div class="hs-stat__search-box">
				<DatabaseSearchIcon :size="16" />
				<input
					type="text"
					class="hs-stat__search-input"
					:value="searchLine.example"
					readonly
					:aria-label="searchLine.label">
			</div>
			<dl v-if="searchLine.result" class="hs-stat__result">
				<div v-for="(val, key) in resultRows" :key="key" class="hs-stat__result-row">
					<dt>{{ resultLabel(key) }}</dt>
					<dd>{{ val }}</dd>
				</div>
			</dl>
		</div>

		<p v-if="descriptor.note" class="hs-stat__note">{{ descriptor.note }}</p>
	</section>
</template>

<script>
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import ShieldCheckIcon from 'vue-material-design-icons/ShieldCheck.vue'
import DatabaseSearchIcon from 'vue-material-design-icons/DatabaseSearch.vue'
import { translate as t } from '@nextcloud/l10n'
import { toneColor } from '../services/tones.js'
import { iconFor } from '../services/icons.js'

const RESULT_LABELS = {
	messageType: 'Meddelandetyp',
	accessPoint: 'Accesspunkt',
	sender: 'Avsändare',
	recipient: 'Mottagare',
	timestamp: 'Tidpunkt',
	conversationId: 'Conversation ID',
	note: 'Notering',
}

export default {
	name: 'WidgetStat',
	components: { CheckCircleIcon, AlertCircleIcon, ShieldCheckIcon, DatabaseSearchIcon },
	props: {
		title: { type: String, default: '' },
		descriptor: { type: Object, required: true },
		leadIcon: { type: String, default: 'ShieldCheck' },
	},
	computed: {
		tiles() {
			return Array.isArray(this.descriptor.tiles) ? this.descriptor.tiles : []
		},
		checks() {
			return Array.isArray(this.descriptor.checks) ? this.descriptor.checks : []
		},
		statements() {
			return Array.isArray(this.descriptor.statements) ? this.descriptor.statements : []
		},
		searchLine() {
			return this.descriptor.searchLine || null
		},
		resultRows() {
			const r = this.searchLine && this.searchLine.result ? { ...this.searchLine.result } : {}
			delete r.tone
			return r
		},
		accentClass() {
			return this.descriptor.overallTone ? 'hs-stat--' + this.descriptor.overallTone : ''
		},
	},
	methods: {
		t,
		toneColor,
		iconFor,
		resultLabel(key) {
			return RESULT_LABELS[key] || key
		},
	},
}
</script>

<style scoped lang="scss">
.hs-stat {
	&--success { border-inline-start: 4px solid var(--hs-status-success); }
	&--warning { border-inline-start: 4px solid var(--hs-status-warning); }
	&--error { border-inline-start: 4px solid var(--hs-status-error); }

	&__tiles {
		list-style: none;
		margin: 0 0 8px;
		padding: 0;
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
		gap: 12px;
	}

	&__tile {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__value {
		font-size: 1.5rem;
		font-weight: 700;
		line-height: 1.05;
	}

	&__label {
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
	}

	&__checks {
		list-style: none;
		margin: 4px 0 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__check {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 0.9rem;
	}

	&__check-ok { color: var(--hs-status-success); flex: 0 0 auto; }
	&__check-no { color: var(--hs-status-warning); flex: 0 0 auto; }
	&__check-label { flex: 1; }
	&__check-detail { color: var(--color-text-maxcontrast); font-size: 0.82rem; }

	&__statements {
		list-style: none;
		margin: 4px 0 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__statement {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 0.9rem;
		color: var(--color-main-text);

		span { color: var(--hs-status-success); }
	}

	&__note {
		margin: 10px 0 0;
		font-size: 0.82rem;
		color: var(--color-text-maxcontrast);
	}

	&__search {
		margin-top: 12px;
	}

	&__search-label {
		display: block;
		font-size: 0.82rem;
		color: var(--color-text-maxcontrast);
		margin-bottom: 4px;
	}

	&__search-box {
		display: flex;
		align-items: center;
		gap: 6px;
		padding: 6px 10px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-background-dark);
	}

	&__search-input {
		flex: 1;
		border: none;
		background: transparent;
		color: var(--color-main-text);
		font-family: var(--font-face-monospace, monospace);
		font-size: 0.8rem;
		min-width: 0;
	}

	&__result {
		margin: 8px 0 0;
		display: flex;
		flex-direction: column;
		gap: 3px;
	}

	&__result-row {
		display: grid;
		grid-template-columns: 130px 1fr;
		gap: 8px;
		font-size: 0.82rem;

		dt { color: var(--color-text-maxcontrast); margin: 0; }
		dd { margin: 0; word-break: break-word; }
	}
}
</style>
