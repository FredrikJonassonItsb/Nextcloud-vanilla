<template>
	<NcAppContent>
		<h2 class="sdkmc__title">
			{{ t('sdkmc', 'Logs V2') }}
		</h2>

		<div class="controls">
			<NcTextField
				v-model="filter"
				:label="t('sdkmc', 'Search logs')"
				type="search"
				:placeholder="t('sdkmc', 'Search...')"
				class="search-input" />
		</div>
		<DataTableWithPagination
			:items="logs"
			:columns="columns"
			:items-per-page="10"
			:total-records="totalLogs"
			:key-field="'id'"
			:has-actions="true"
			:empty-message="t('sdkmc', 'No logs available')"
			@page-change="onPageChange"
			@row-click="openLogDetails" />
		<CustomLogDetailsModal
			v-if="showDetailsModal"
			:log="selectedLog"
			@close="showDetailsModal = false" />
	</NcAppContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import axios from '@nextcloud/axios'
import DataTableWithPagination from '../components/DataTableWithPagination.vue'
import CustomLogDetailsModal from '../components/CustomLogDetailsModal.vue'
import { NcTextField } from '@nextcloud/vue'

import { generateUrl } from '@nextcloud/router'

export default {
	name: 'SdkmcLogsPage',
	components: {
		DataTableWithPagination,
		CustomLogDetailsModal,
		NcAppContent,
		NcTextField,
	},
	data() {
		return {
			logs: [],
			totalLogs: 0,
			columns: [
				{ key: 'id', title: t('sdkmc', 'ID') },
				{ key: 'creation_date_time.date', title: t('sdkmc', 'Creation Time') },
				{ key: 'message_id', title: t('sdkmc', 'Message ID') },
			],
			limit: 10,
			offset: 0,
			filter: '',
			selectedLog: null,
			showDetailsModal: false,
		}
	},
	watch: {
		filter(newVal, oldVal) {
			this.offset = 0
			this.loadLogs()
		},
	},
	created() {
		this.loadLogs()
	},
	methods: {
		async loadLogs() {
			try {
				const response = await axios.get(
					generateUrl('/apps/sdkmc/api/v2/iipax/sdkLog'),
					{
						params: {
							limit: this.limit,
							offset: this.offset,
							search: this.filter,
						},
						headers: {
							Accept: 'application/json',
						},
						withCredentials: true,
					},
				)

				this.logs = response.data?.data || []

				this.totalLogs = parseInt(response.data?.count)

			} catch (error) {
				console.error('Error loading logs:', error)
			}
		},
		onPageChange({ page }) {
			this.offset = (page - 1) * this.limit
			this.loadLogs()
		},
		openLogDetails(item) {
			this.selectedLog = item
			this.showDetailsModal = true
		},
	},
}
</script>

<style scoped>
.sdkmc__title {
	font-weight: bold;
	margin-bottom: 1rem;
}

.controls {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
	align-items: center;
	justify-content: end;
}
</style>
