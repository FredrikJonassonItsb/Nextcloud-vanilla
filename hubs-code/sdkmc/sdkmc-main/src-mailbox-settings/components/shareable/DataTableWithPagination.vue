<template>
	<div class="data-table-container">
		<table v-if="paginatedItems.length > 0" class="table">
			<thead>
				<tr class="table-header">
					<th v-for="column in columns" :key="column.key">
						{{ column.title }}
					</th>
					<th v-if="hasActions">
						{{ actionsTitle }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(item, index) in paginatedItems" :key="getItemKey(item, index)">
					<td v-for="column in columns" :key="column.key" :data-label="column.title">
						<slot
							:name="`cell-${column.key}`"
							:item="item"
							:column="column"
							:index="index"
							:value="getColumnValue(item, column.key)">
							{{ getColumnValue(item, column.key) }}
						</slot>
					</td>
					<td v-if="hasActions" class="actions-cell" :data-label="actionsTitle">
						<slot name="actions" :item="item" :index="index" />
					</td>
				</tr>
			</tbody>
		</table>

		<div v-else class="empty-state">
			<slot name="empty-state">
				<div class="empty-message">
					{{ emptyMessage }}
				</div>
			</slot>
		</div>

		<div v-if="totalRecords > itemsPerPage" class="pagination-container">
			<VuePagination
				v-model="currentPage"
				:records="totalRecords"
				:per-page="itemsPerPage"
				:options="paginationOptions"
				@paginate="handlePaginate" />
		</div>
	</div>
</template>

<script>
import { ref, computed, watch } from 'vue'
import VuePagination from 'vue-pagination-2'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'DataTableWithPagination',
	components: {
		VuePagination,
	},
	props: {
		items: {
			type: Array,
			required: true,
			default: () => [],
		},
		columns: {
			type: Array,
			required: true,
			default: () => [],
		},
		itemsPerPage: {
			type: Number,
			default: 10,
		},
		keyField: {
			type: String,
			default: 'id',
		},
		customKeyFunction: {
			type: Function,
			default: null,
		},
		hasActions: {
			type: Boolean,
			default: false,
		},
		actionsTitle: {
			type: String,
			default: t('sdkmc', 'Actions'),
		},
		emptyMessage: {
			type: String,
			default: t('sdkmc', 'No data available'),
		},
		paginationTexts: {
			type: Object,
			default: () => ({
				count: t('sdkmc', 'Showing {from} to {to} of {count} items|{count} items|One item'),
			}),
		},
	},
	setup(props, { emit }) {
		const currentPage = ref(1)

		const totalRecords = computed(() => props.items.length)

		const paginatedItems = computed(() => {
			const start = (currentPage.value - 1) * props.itemsPerPage
			const end = start + props.itemsPerPage
			return props.items.slice(start, end)
		})

		const paginationOptions = computed(() => ({
			texts: props.paginationTexts,
		}))

		function getItemKey(item, index) {
			if (props.customKeyFunction) {
				return props.customKeyFunction(item, index)
			}
			return item[props.keyField] || index
		}

		function getColumnValue(item, key) {
			return key
				.split('.')
				.reduce((obj, prop) => (obj && obj[prop] !== undefined ? obj[prop] : undefined), item) || ''
		}

		function handlePaginate(page) {
			currentPage.value = page

			emit('page-change', {
				page,
				items: paginatedItems.value,
			})
		}

		function goToPage(page) {
			currentPage.value = page
		}

		function resetPagination() {
			currentPage.value = 1
		}

		watch(
			() => props.items,
			() => {
				if (currentPage.value > 1 && paginatedItems.value.length === 0) {
					resetPagination()
				}
			},
			{ deep: true },
		)

		return {
			currentPage,
			totalRecords,
			paginatedItems,
			paginationOptions,
			getItemKey,
			getColumnValue,
			handlePaginate,
			goToPage,
			resetPagination,
		}
	},
}
</script>
<style scoped>
.data-table-container {
	width: 100%;
}

.table {
	width: 100%;
	border-collapse: collapse;
	border: 1px solid #ccc;
}

.table-header {
	background-color: var(--color-primary, #0082c9);
	color: var(--color-primary-text, white);
}

th,
td {
	padding: 8px;
	border: 1px solid #ccc;
	text-align: left;
	vertical-align: top;
	border-left: none;
	border-right: none;
}

.actions-cell {
	white-space: nowrap;
}

.empty-state {
	padding: 40px 20px;
	text-align: center;
	color: var(--color-text-lighter, #666);
}

.empty-message {
	font-size: 16px;
	color: var(--color-text-lighter, #666);
}

.pagination-container {
	margin-top: 16px;
	display: flex;
	justify-content: flex-start;
}

@media (max-width: 768px) {
	.table {
		font-size: 14px;
	}

	th,
	td {
		padding: 6px;
	}
}
</style>
