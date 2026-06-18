<template>
	<section class="hs-card system-halsa">
		<h2 class="hs-card__title">
			<HeartPulseIcon :size="20" />
			{{ t('hubs_start', 'Systemhälsa') }}
		</h2>

		<div class="system-halsa__grid">
			<!-- SDK-loggstatus -->
			<div class="hs-subcard" :class="{ 'hs-subcard--error': sdkLog.error }">
				<h3 class="hs-subcard__title">{{ t('hubs_start', 'SDK-loggstatus') }}</h3>
				<NcLoadingIcon v-if="sdkLog.loading" :size="20" />
				<p v-else-if="sdkLog.error" class="hs-subcard__muted">
					{{ t('hubs_start', 'Kunde inte läsa SDK-loggen') }}
				</p>
				<template v-else>
					<p class="hs-subcard__value">
						<span class="hs-dot" :class="sdkLog.ok ? 'hs-dot--ok' : 'hs-dot--warn'" aria-hidden="true" />
						{{ sdkLog.ok ? t('hubs_start', 'Loggen är aktiv') : t('hubs_start', 'Loggen rapporterar fel') }}
					</p>
					<p v-if="sdkLog.detail" class="hs-subcard__muted">{{ sdkLog.detail }}</p>
				</template>
			</div>

			<!-- Adressbokssynk age -->
			<div class="hs-subcard">
				<h3 class="hs-subcard__title">{{ t('hubs_start', 'Adressbokssynk') }}</h3>
				<NcLoadingIcon v-if="health.loading" :size="20" />
				<p v-else-if="health.error" class="hs-subcard__muted">
					{{ t('hubs_start', 'Status ej tillgänglig') }}
				</p>
				<p v-else class="hs-subcard__value">{{ addressbookAgeLabel }}</p>
			</div>

			<!-- Bakgrundsjobb health -->
			<div class="hs-subcard">
				<h3 class="hs-subcard__title">{{ t('hubs_start', 'Bakgrundsjobb') }}</h3>
				<NcLoadingIcon v-if="health.loading" :size="20" />
				<p v-else-if="health.error" class="hs-subcard__muted">
					{{ t('hubs_start', 'Status ej tillgänglig') }}
				</p>
				<p v-else class="hs-subcard__value">
					<span class="hs-dot" :class="health.jobsOk ? 'hs-dot--ok' : 'hs-dot--warn'" aria-hidden="true" />
					{{ health.jobsOk ? t('hubs_start', 'Körs som väntat') : t('hubs_start', 'Försenade jobb') }}
				</p>
			</div>

			<!-- Notifieringsstatus -->
			<div class="hs-subcard">
				<h3 class="hs-subcard__title">{{ t('hubs_start', 'Notifieringar') }}</h3>
				<NcLoadingIcon v-if="notif.loading" :size="20" />
				<p v-else-if="notif.error" class="hs-subcard__muted">
					{{ t('hubs_start', 'Status ej tillgänglig') }}
				</p>
				<p v-else class="hs-subcard__value">
					<span class="hs-dot" :class="notif.enabled ? 'hs-dot--ok' : 'hs-dot--warn'" aria-hidden="true" />
					{{ notif.enabled ? t('hubs_start', 'Aktiverade') : t('hubs_start', 'Avstängda') }}
				</p>
			</div>

			<!-- Komponentversioner -->
			<div class="hs-subcard hs-subcard--wide">
				<h3 class="hs-subcard__title">{{ t('hubs_start', 'Komponentversioner') }}</h3>
				<NcLoadingIcon v-if="health.loading" :size="20" />
				<p v-else-if="health.error || !versions.length" class="hs-subcard__muted">
					{{ t('hubs_start', 'Versioner ej tillgängliga') }}
				</p>
				<ul v-else class="hs-versions">
					<li v-for="v in versions" :key="v.name" class="hs-versions__row">
						<span class="hs-versions__name">{{ v.name }}</span>
						<span class="hs-versions__ver">{{ v.version }}</span>
					</li>
				</ul>
			</div>

			<!-- Gallring (destructive, type-to-confirm guarded) -->
			<div class="hs-subcard hs-subcard--wide hs-subcard--danger">
				<h3 class="hs-subcard__title">{{ t('hubs_start', 'Gallring') }}</h3>
				<p class="hs-subcard__muted">
					{{ t('hubs_start', 'Kör gallring nu raderar utgångna ärenden permanent. Detta kan inte ångras.') }}
				</p>
				<NcTextField
					class="system-halsa__confirm"
					:value.sync="confirmInput"
					:label="confirmFieldLabel"
					:disabled="expunge.running"
					autocomplete="off"
					@keydown.enter.prevent="runExpunge" />
				<p class="hs-subcard__hint" aria-live="polite">{{ confirmFieldLabel }}</p>
				<NcButton
					type="error"
					:disabled="!confirmReady || expunge.running"
					@click="runExpunge">
					<template #icon>
						<NcLoadingIcon v-if="expunge.running" :size="20" />
						<DeleteSweepIcon v-else :size="20" />
					</template>
					{{ t('hubs_start', 'Kör nu') }}
				</NcButton>
			</div>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import HeartPulseIcon from 'vue-material-design-icons/HeartPulse.vue'
import DeleteSweepIcon from 'vue-material-design-icons/DeleteSweep.vue'

/** The word the förvaltare must type to arm the destructive gallring action. */
const CONFIRM_WORD = 'RADERA'

export default {
	name: 'SystemHalsa',

	components: {
		NcButton,
		NcTextField,
		NcLoadingIcon,
		HeartPulseIcon,
		DeleteSweepIcon,
	},

	data() {
		return {
			confirmWord: CONFIRM_WORD,
			confirmInput: '',
			sdkLog: { loading: true, error: false, ok: false, detail: '' },
			notif: { loading: true, error: false, enabled: false },
			health: { loading: true, error: false, jobsOk: false, addressbookSyncedAt: null },
			versions: [],
			expunge: { running: false },
		}
	},

	computed: {
		confirmReady() {
			return this.confirmInput.trim().toUpperCase() === this.confirmWord
		},
		confirmFieldLabel() {
			return t('hubs_start', 'Skriv {word} för att aktivera', { word: this.confirmWord })
		},
		addressbookAgeLabel() {
			const ts = this.health.addressbookSyncedAt
			if (!ts) {
				return t('hubs_start', 'Okänd')
			}
			const ageMin = Math.max(0, Math.round((Date.now() - new Date(ts).getTime()) / 60000))
			if (ageMin < 60) {
				return n('hubs_start', 'Senast synkad för {n} minut sedan', 'Senast synkad för {n} minuter sedan', ageMin, { n: ageMin })
			}
			const ageH = Math.round(ageMin / 60)
			return n('hubs_start', 'Senast synkad för {n} timme sedan', 'Senast synkad för {n} timmar sedan', ageH, { n: ageH })
		},
	},

	created() {
		this.loadSdkLog()
		this.loadNotificationStatus()
		this.loadHealth()
	},

	methods: {
		t,

		async loadSdkLog() {
			this.sdkLog.loading = true
			try {
				const res = await axios.get(generateUrl('/apps/sdkmc/api/v2/iipax/sdkLog'))
				const data = res.data ?? {}
				this.sdkLog.ok = data.ok ?? data.healthy ?? (data.status === 'ok')
				this.sdkLog.detail = data.message || data.lastEntry || ''
				this.sdkLog.error = false
			} catch (e) {
				this.sdkLog.error = true
			} finally {
				this.sdkLog.loading = false
			}
		},

		async loadNotificationStatus() {
			this.notif.loading = true
			try {
				const res = await axios.get(generateUrl('/apps/sdkmc/api/v2/admin/activityNotificationStatus'))
				const data = res.data ?? {}
				this.notif.enabled = data.enabled ?? (data.status === 'enabled') ?? false
				this.notif.error = false
			} catch (e) {
				this.notif.error = true
			} finally {
				this.notif.loading = false
			}
		},

		async loadHealth() {
			this.health.loading = true
			try {
				const res = await axios.get(generateUrl('/apps/sdkmc/api/v2/admin/activityNotificationStatus'))
				const data = res.data ?? {}
				this.health.addressbookSyncedAt = data.addressbookSyncedAt ?? data.addressbookSync ?? null
				this.health.jobsOk = data.backgroundJobsOk ?? data.jobsHealthy ?? true
				this.versions = Array.isArray(data.versions)
					? data.versions.map((v) => ({ name: v.name ?? v.app ?? '', version: v.version ?? v.ver ?? '' }))
					: []
				this.health.error = false
			} catch (e) {
				this.health.error = true
			} finally {
				this.health.loading = false
			}
		},

		async runExpunge() {
			if (!this.confirmReady || this.expunge.running) {
				return
			}
			this.expunge.running = true
			try {
				await axios.get(generateUrl('/apps/sdkmc/api/v2/admin/runExpungeNow'))
				showSuccess(t('hubs_start', 'Gallring startad'))
				this.confirmInput = ''
			} catch (e) {
				showError(t('hubs_start', 'Kunde inte starta gallring'))
			} finally {
				this.expunge.running = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.system-halsa {
	&__grid {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: var(--hs-gap);
	}

	&__confirm {
		margin: 8px 0 4px;
		max-width: 320px;
	}
}

.hs-subcard {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 4px;

	&--wide {
		grid-column: 1 / -1;
	}

	&--error {
		border-color: var(--hs-status-error);
	}

	&--danger {
		border-color: var(--hs-status-error);
	}

	&__title {
		font-size: 0.95rem;
		font-weight: 600;
		margin: 0;
	}

	&__value {
		margin: 0;
		display: flex;
		align-items: center;
		gap: 6px;
		font-weight: 500;
	}

	&__muted {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
	}

	&__hint {
		margin: 0 0 8px;
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
	}
}

.hs-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	flex: 0 0 auto;
	background: var(--hs-status-neutral);

	&--ok {
		background: var(--hs-status-success);
	}

	&--warn {
		background: var(--hs-status-warning);
	}
}

.hs-versions {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;

	&__row {
		display: flex;
		justify-content: space-between;
		gap: 12px;
		padding: 2px 0;
	}

	&__ver {
		color: var(--color-text-maxcontrast);
		font-variant-numeric: tabular-nums;
	}
}

@media (max-width: 680px) {
	.system-halsa__grid {
		grid-template-columns: 1fr;
	}
}
</style>
