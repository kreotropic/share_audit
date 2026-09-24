<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div>
		<div class="sad-list-toolbar">
			<NcChip v-if="exposure" :text="exposureLabel" @close="clearExposure" />

			<NcButton v-if="filtersActive" variant="tertiary" @click="clearFilters">
				{{ t('share_audit_dashboard', 'Clear filters') }}
			</NcButton>

			<div class="sad-list-toolbar__right">
				<!-- An auditor never gets tokens back regardless of this switch
				     (ShareApiController::export() strips them), so it would just
				     mislead — not offered at all. -->
				<NcCheckboxRadioSwitch v-if="canManage" v-model="includeTokens" type="checkbox">
					{{ t('share_audit_dashboard', 'Include link tokens') }}
				</NcCheckboxRadioSwitch>

				<NcButton :disabled="exporting || total === 0" @click="exportCsv">
					{{ exporting
						? t('share_audit_dashboard', 'Exporting…')
						: t('share_audit_dashboard', 'Export CSV') }}
				</NcButton>

				<PageSizeSelect v-model="pageSize"
					:options="pageSizeOptions"
					:width="110"
					:disabled="loading"
					:aria-label="t('share_audit_dashboard', 'Shares per page')" />
			</div>
		</div>

		<NcNoteCard v-if="includeTokens" type="warning" class="sad-export-warn">
			{{ t('share_audit_dashboard',
				'This file will contain public link tokens — bare credentials that let anyone open the linked file without logging in. Handle it accordingly.') }}
		</NcNoteCard>

		<NcNoteCard v-if="exportError" type="error" class="sad-export-error">
			{{ exportError }}
		</NcNoteCard>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<!-- ShareTable stays mounted across loads so the header-filter state
		     (selected types, searches) is never reset by the loading toggle. -->
		<ShareTable :key="tableKey"
			:shares="items"
			:sort-key="sortKey"
			:sort-dir="sortDir"
			:preset-types="activePreset"
			@sort="onSort"
			@filter="onFilter" />

		<NcLoadingIcon v-if="loading" :size="32" class="sad-loading" />

		<p v-else-if="items.length === 0" class="sad-empty settings-hint">
			{{ t('share_audit_dashboard', 'No shares match the current filters.') }}
		</p>

		<div v-else class="sad-pagination">
			<span class="sad-pagination__range">{{ rangeLabel }}</span>
			<PageNavigation :page="page" :total-pages="totalPages" @change="goto" />
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import PageNavigation from '../components/PageNavigation.vue'
import PageSizeSelect from '../components/PageSizeSelect.vue'
import ShareTable from '../components/ShareTable.vue'
import { fetchShares, exportShares } from '../services/api.js'

export default {
	name: 'ShareList',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcChip,
		NcLoadingIcon,
		NcNoteCard,
		PageNavigation,
		PageSizeSelect,
		ShareTable,
	},
	props: {
		presetTypes: {
			type: Array,
			default: null,
		},
		// An exposure category (internal | external | public) to open filtered
		// to — see App.vue. Sent to the backend as it is.
		presetExposure: {
			type: String,
			default: '',
		},
	},
	inject: {
		canManage: { default: true },
	},
	data() {
		return {
			loading: true,
			error: null,
			items: [],
			total: 0,
			page: 1,
			pageSizeOptions: [
				{ id: 25, label: '25' },
				{ id: 50, label: '50' },
				{ id: 100, label: '100' },
			],
			pageSize: { id: 50, label: '50' },
			filters: this.presetTypes?.length ? { types: this.presetTypes.join(',') } : {},
			exposure: this.presetExposure,
			activePreset: this.presetTypes ? [...this.presetTypes] : [],
			tableKey: 0,
			sortKey: 'created',
			sortDir: 'desc',
			exporting: false,
			exportError: null,
			includeTokens: false,
		}
	},
	computed: {
		apiLimit() {
			return this.pageSize.id
		},
		totalPages() {
			return Math.max(1, Math.ceil(this.total / this.apiLimit))
		},
		filtersActive() {
			return Object.keys(this.filters).length > 0 || this.exposure !== ''
		},
		// The table's own filters plus the exposure category, which the table
		// knows nothing about and so never puts in what it emits.
		requestFilters() {
			return this.exposure ? { ...this.filters, exposure: this.exposure } : this.filters
		},
		exposureLabel() {
			const levels = {
				internal: t('share_audit_dashboard', 'Internal'),
				external: t('share_audit_dashboard', 'External'),
				public: t('share_audit_dashboard', 'Public'),
			}
			return t('share_audit_dashboard', 'Exposure: {level}', { level: levels[this.exposure] ?? this.exposure })
		},
		rangeLabel() {
			if (this.total === 0) {
				return ''
			}
			const from = (this.page - 1) * this.apiLimit + 1
			const to = Math.min(this.total, this.page * this.apiLimit)
			return t('share_audit_dashboard', '{from}–{to} of {total}', {
				from,
				to,
				total: this.total,
			})
		},
	},
	watch: {
		// A different page size invalidates the current page.
		'pageSize.id'() {
			this.page = 1
			this.load()
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		onFilter(filters) {
			this.filters = filters
			this.page = 1
			this.load()
		},
		clearExposure() {
			this.exposure = ''
			this.page = 1
			this.load()
		},
		clearFilters() {
			this.filters = {}
			this.exposure = ''
			this.activePreset = []
			this.page = 1
			this.tableKey++
			this.load()
		},
		goto(page) {
			if (page < 1 || page > this.totalPages || page === this.page) {
				return
			}
			this.page = page
			this.load()
		},
		onSort(key) {
			if (this.sortKey === key) {
				this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'
			} else {
				this.sortKey = key
				this.sortDir = 'asc'
			}
			this.page = 1
			this.load()
		},
		async exportCsv() {
			this.exporting = true
			this.exportError = null
			try {
				const res = await exportShares({
					...this.requestFilters,
					sort: this.sortKey,
					sortDir: this.sortDir,
					includeTokens: this.includeTokens,
				})
				const blob = new Blob([res.data], { type: 'text/csv;charset=utf-8' })
				const disposition = res.headers['content-disposition'] || ''
				const match = disposition.match(/filename="?([^";]+)"?/)
				const filename = match ? match[1] : 'share-audit.csv'

				const url = URL.createObjectURL(blob)
				const link = document.createElement('a')
				link.href = url
				link.download = filename
				document.body.appendChild(link)
				link.click()
				link.remove()
				URL.revokeObjectURL(url)
			} catch (e) {
				this.exportError = t('share_audit_dashboard', 'Could not export the share list.')
			} finally {
				this.exporting = false
			}
		},
		async load() {
			this.loading = true
			this.error = null
			try {
				const data = await fetchShares({
					page: this.page,
					limit: this.apiLimit,
					sort: this.sortKey,
					sortDir: this.sortDir,
					...this.requestFilters,
				})
				this.items = data.items
				this.total = data.total
			} catch (e) {
				this.error = t('share_audit_dashboard', 'Could not load shares.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.sad-list-toolbar {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 12px;
	min-height: 34px;
}

.sad-list-toolbar__right {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-left: auto;
}

.sad-export-warn,
.sad-export-error {
	margin-bottom: 12px;
}

.sad-empty {
	padding: 24px 0;
	text-align: center;
}

.sad-pagination {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-top: 16px;
}

.sad-pagination__range {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
