<template>
	<div class="data-table-container">
		<table v-if="items.length > 0" class="table">
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
				<tr
					v-for="(item, index) in items"
					:key="getItemKey(item, index)"
					@click="$emit('row-click', item)">
					<td v-for="column in columns"
						:key="column.key"
						style="cursor: pointer"
						class="cell">
						<slot
							:name="`cell-${column.key}`"
							:class="`cell-${column.key}`"
							:item="item"
							:column="column"
							:index="index"
							:value="getColumnValue(item, column.key)">
							<div :class="`cell-${column.key}`">
								{{ getColumnValue(item, column.key) }}
							</div>
						</slot>
					</td>
					<td v-if="hasActions" class="actions-cell" style="cursor: pointer">
						<slot name="actions" :item="item" :index="index" />
						<button @click.stop="viewReceipt(item)">
							{{ t('sdkmc', 'View SDK receipt') }}
						</button>
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

		<VuePagination
			v-model="currentPage"
			:records="totalRecords"
			:per-page="itemsPerPage"
			:options="paginationOptions"
			@paginate="handlePaginate" />
		<SDKReceiptModal
			:show="showReceiptModal"
			:message-id="selectedMessageId"
			@close="closeReceiptModal" />
	</div>
</template>

<script>
import { ref, computed } from 'vue'
import VuePagination from 'vue-pagination-2'
import SDKReceiptModal from './SDKReceiptModal.vue'
export default {
	name: 'DataTableWithPagination',
	components: {
		VuePagination,
		SDKReceiptModal,
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
		totalRecords: {
			type: Number,
			required: true,
			default: 0,
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
		const showReceiptModal = ref(false)
		const selectedMessageId = ref(null)

		function viewReceipt(item) {
			if (!item.message_id) return
			selectedMessageId.value = item.message_id
			showReceiptModal.value = true
		}

		function closeReceiptModal() {
			showReceiptModal.value = false
			selectedMessageId.value = null
		}

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
				items: props.items,
			})
		}

		function goToPage(page) {
			currentPage.value = page
		}

		function resetPagination() {
			currentPage.value = 1
		}

		return {
			currentPage,
			paginationOptions,
			getItemKey,
			getColumnValue,
			handlePaginate,
			goToPage,
			resetPagination,
			showReceiptModal,
			selectedMessageId,
			viewReceipt,
			closeReceiptModal,
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
	justify-content: flex-end;
}

.cell {
	vertical-align: middle;
}

.cell-message_id {
	text-decoration: underline;
	cursor: pointer;
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
