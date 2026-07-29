<template>
	<div>
		<div class="sad-section-head">
			<h3 class="sad-section-title">{{ t('share_audit_dashboard', 'Deleted shares') }}</h3>
			<span class="sad-section-sep" aria-hidden="true">·</span>
			<p class="sad-section-sub">
				{{ t('share_audit_dashboard', 'Shares revoked through this app or unshared natively, kept here for a while so they can be restored.') }}
			</p>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" class="sad-loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent v-else-if="items.length === 0"
			:name="t('share_audit_dashboard', 'Recycle bin is empty')"
			:description="t('share_audit_dashboard', 'Shares you revoke show up here before being permanently removed.')">
			<template #icon>
				<span class="icon-checkmark" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<NcNoteCard v-if="notice" :type="notice.type" class="sad-deleted-notice">
				{{ notice.message }}
			</NcNoteCard>

			<div class="sad-deleted-bar">
				<NcCheckboxRadioSwitch :model-value="allSelected" @update:model-value="toggleAll">
					{{ t('share_audit_dashboard', 'Select all') }}
				</NcCheckboxRadioSwitch>
				<span v-if="selectedIds.length" class="sad-deleted-bar__count">
					{{ n('share_audit_dashboard', '%n selected', '%n selected', selectedIds.length) }}
				</span>
				<div class="sad-deleted-bar__spacer" />
				<template v-if="selectedIds.length">
					<NcButton :disabled="busy" @click="restoreSelected">
						{{ t('share_audit_dashboard', 'Restore selected') }}
					</NcButton>
					<template v-if="!confirmingPurge">
						<NcButton type="error" :disabled="busy" @click="confirmingPurge = true">
							{{ t('share_audit_dashboard', 'Delete permanently') }}
						</NcButton>
					</template>
					<template v-else>
						<span class="sad-deleted-bar__confirm">
							{{ n('share_audit_dashboard', 'Permanently delete %n share?', 'Permanently delete %n shares?', selectedIds.length) }}
						</span>
						<NcButton type="error" :disabled="busy" @click="purgeSelected">
							{{ t('share_audit_dashboard', 'Confirm') }}
						</NcButton>
						<NcButton type="tertiary" :disabled="busy" @click="confirmingPurge = false">
							{{ t('share_audit_dashboard', 'Cancel') }}
						</NcButton>
					</template>
				</template>

				<PageSizeSelect v-model="pageSize"
					:options="pageSizeOptions"
					:width="120"
					:disabled="busy" />
			</div>

			<div class="sad-table-wrapper">
				<table class="sad-table">
					<caption class="hidden-visually">
						{{ t('share_audit_dashboard', 'Revoked shares kept for a limited time, selectable for bulk restore or permanent deletion.') }}
					</caption>
					<thead>
						<tr>
							<th class="sad-table__check" />
							<th scope="col">{{ t('share_audit_dashboard', 'Type') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Path') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Owner') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Recipient') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Deleted') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Purge in') }}</th>
							<th scope="col" />
						</tr>
					</thead>
					<tbody>
						<tr v-for="share in items" :key="share.id">
							<td class="sad-table__check">
								<NcCheckboxRadioSwitch :model-value="selectedIds.includes(share.id)"
									@update:model-value="toggleSelect(share.id, $event)" />
							</td>
							<td><NcChip :text="categoryLabel(share.category)" :no-close="true" /></td>
							<td class="sad-table__path" :title="share.path">{{ share.path || '—' }}</td>
							<td>
								{{ share.ownerDisplayName || share.owner }}
								<span v-if="share.ownerDisplayName && share.ownerDisplayName !== share.owner"
									class="sad-table__uid">{{ share.owner }}</span>
							</td>
							<td>{{ recipientOf(share) }}</td>
							<td>
								{{ formatDate(share.deletedAt) }}
								<span v-if="share.deletedByDisplayName" class="sad-table__uid">
									{{ t('share_audit_dashboard', 'by {name}', { name: share.deletedByDisplayName }) }}
								</span>
							</td>
							<td>
								<span :class="{ 'sad-purge-soon': purgeDaysLeft(share) <= 3 }">
									{{ purgeLabel(share) }}
								</span>
							</td>
							<td class="sad-table__row-actions">
								<NcButton :disabled="busy" @click="restoreOne(share)">
									{{ t('share_audit_dashboard', 'Restore') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="sad-pagination">
				<span class="sad-pagination__info">{{ rangeLabel }}</span>
				<div v-if="!isAll && total > apiLimit" class="sad-pagination__controls">
					<NcButton :disabled="page <= 1" @click="goto(page - 1)">
						{{ t('share_audit_dashboard', 'Previous') }}
					</NcButton>
					<span class="sad-pagination__page">{{ page }} / {{ totalPages }}</span>
					<NcButton :disabled="page >= totalPages" @click="goto(page + 1)">
						{{ t('share_audit_dashboard', 'Next') }}
					</NcButton>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import PageSizeSelect from '../components/PageSizeSelect.vue'
import { categoryLabel, formatDate } from '../utils/format.js'
import { fetchDeletedShares, restoreDeletedShare, purgeDeletedShares } from '../services/api.js'

// Mirrors OrphanShareController::MAX_IDS — larger selections are split into
// sequential requests instead of one huge purge call.
const BULK_CHUNK_SIZE = 500

export default {
	name: 'DeletedShares',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcChip,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		PageSizeSelect,
	},
	emits: ['deleted-count'],
	data() {
		return {
			loading: true,
			error: null,
			busy: false,
			confirmingPurge: false,
			items: [],
			total: 0,
			page: 1,
			pageSizeOptions: [
				{ id: 5, label: '5' },
				{ id: 15, label: '15' },
				{ id: 25, label: '25' },
				{ id: 50, label: '50' },
				{ id: 'all', label: t('share_audit_dashboard', 'All') },
			],
			pageSize: { id: 25, label: '25' },
			selectedIds: [],
			notice: null,
		}
	},
	computed: {
		isAll() {
			return this.pageSize.id === 'all'
		},
		// Numeric limit sent to the API; 0 means "return every entry".
		apiLimit() {
			return this.isAll ? 0 : this.pageSize.id
		},
		totalPages() {
			if (this.isAll) {
				return 1
			}
			return Math.max(1, Math.ceil(this.total / this.apiLimit))
		},
		allSelected() {
			return this.items.length > 0 && this.selectedIds.length === this.items.length
		},
		rangeLabel() {
			if (this.total === 0) {
				return ''
			}
			const from = this.isAll ? 1 : (this.page - 1) * this.apiLimit + 1
			const to = this.isAll ? this.total : Math.min(this.total, this.page * this.apiLimit)
			return t('share_audit_dashboard', '{from}–{to} of {total}', { from, to, total: this.total })
		},
	},
	watch: {
		'pageSize.id'() {
			this.page = 1
			this.selectedIds = []
			this.confirmingPurge = false
			this.load()
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		n,
		categoryLabel,
		formatDate,
		recipientOf(share) {
			if (share.recipient) {
				return share.recipient
			}
			return share.category === 'link' ? t('share_audit_dashboard', '(public)') : '—'
		},
		purgeDaysLeft(share) {
			return Math.ceil((share.purgeAfter * 1000 - Date.now()) / 86400000)
		},
		purgeLabel(share) {
			const days = this.purgeDaysLeft(share)
			if (days <= 0) {
				return t('share_audit_dashboard', 'today')
			}
			return n('share_audit_dashboard', 'in %n day', 'in %n days', days)
		},
		toggleAll(checked) {
			this.selectedIds = checked ? this.items.map((s) => s.id) : []
		},
		toggleSelect(id, checked) {
			if (checked) {
				if (!this.selectedIds.includes(id)) {
					this.selectedIds.push(id)
				}
			} else {
				this.selectedIds = this.selectedIds.filter((x) => x !== id)
			}
		},
		goto(page) {
			if (page < 1 || page > this.totalPages || page === this.page) {
				return
			}
			this.page = page
			this.selectedIds = []
			this.load()
		},
		async load() {
			this.loading = true
			this.error = null
			try {
				const data = await fetchDeletedShares({ page: this.page, limit: this.apiLimit })
				this.items = data.items
				this.total = data.total
				this.selectedIds = this.selectedIds.filter((id) => this.items.some((s) => s.id === id))
				this.$emit('deleted-count', this.total)
			} catch (e) {
				this.error = t('share_audit_dashboard', 'Could not load deleted shares.')
			} finally {
				this.loading = false
			}
		},
		async restoreOne(share) {
			this.busy = true
			this.notice = null
			try {
				const res = await restoreDeletedShare(share.id)
				this.notice = res.tokenChanged
					? { type: 'warning', message: t('share_audit_dashboard', 'Restored, but the original link URL could not be kept — it now has a new one.') }
					: { type: 'success', message: t('share_audit_dashboard', 'Share restored.') }
				this.selectedIds = this.selectedIds.filter((id) => id !== share.id)
				await this.load()
			} catch (e) {
				this.notice = { type: 'error', message: t('share_audit_dashboard', 'Could not restore this share — the original file may no longer exist.') }
			} finally {
				this.busy = false
			}
		},
		async restoreSelected() {
			this.busy = true
			this.notice = null
			try {
				let restored = 0
				let changed = 0
				let failed = 0
				for (const id of [...this.selectedIds]) {
					try {
						const res = await restoreDeletedShare(id)
						restored++
						if (res.tokenChanged) {
							changed++
						}
					} catch (e) {
						failed++
					}
				}
				const parts = [n('share_audit_dashboard', 'Restored %n share.', 'Restored %n shares.', restored)]
				if (changed > 0) {
					parts.push(n('share_audit_dashboard', '%n got a new link URL.', '%n got new link URLs.', changed))
				}
				if (failed > 0) {
					parts.push(n('share_audit_dashboard', '%n could not be restored.', '%n could not be restored.', failed))
				}
				this.notice = { type: failed > 0 ? 'warning' : 'success', message: parts.join(' ') }
				this.selectedIds = []
				await this.load()
			} finally {
				this.busy = false
			}
		},
		async purgeSelected() {
			this.busy = true
			this.notice = null
			try {
				let purged = 0
				for (let i = 0; i < this.selectedIds.length; i += BULK_CHUNK_SIZE) {
					const chunk = this.selectedIds.slice(i, i + BULK_CHUNK_SIZE)
					const res = await purgeDeletedShares(chunk)
					purged += res.purged
				}
				this.notice = {
					type: 'success',
					message: n('share_audit_dashboard', 'Permanently deleted %n share.', 'Permanently deleted %n shares.', purged),
				}
				this.confirmingPurge = false
				this.selectedIds = []
				if (this.page > 1 && this.items.length === purged) {
					this.page -= 1
				}
				await this.load()
			} catch (e) {
				this.notice = { type: 'error', message: t('share_audit_dashboard', 'Could not permanently delete the selected shares.') }
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.sad-section-head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 8px;
	margin-bottom: 12px;
}

.sad-section-title {
	margin: 0;
	font-size: 17px;
}

.sad-section-sep,
.sad-section-sub {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.sad-section-sub {
	margin: 0;
	max-width: none;
}

.sad-deleted-bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	padding: 10px 14px;
	margin-bottom: 12px;
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover);
}

.sad-deleted-bar__spacer {
	flex: 1;
}

.sad-deleted-bar__count,
.sad-deleted-bar__confirm {
	font-weight: 600;
}

.sad-deleted-notice {
	margin-bottom: 12px;
}

.sad-table-wrapper {
	overflow-x: auto;
}

.sad-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;

	th,
	td {
		text-align: left;
		padding: 8px 10px;
		border-bottom: 1px solid var(--color-border);
		white-space: nowrap;
	}

	th {
		color: var(--color-text-maxcontrast);
		font-weight: 600;
	}

	tbody tr:nth-child(even) {
		background-color: var(--color-background-hover);
	}

	tbody tr:hover {
		background-color: var(--color-background-dark);
	}
}

.sad-table__check {
	width: 44px;
}

.sad-table__path {
	max-width: 260px;
	overflow: hidden;
	text-overflow: ellipsis;
}

.sad-table__uid {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.sad-table__row-actions {
	text-align: right;
}

.sad-purge-soon {
	color: var(--color-warning-text, var(--color-warning));
	font-weight: 600;
}

.sad-pagination {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-top: 16px;
}

.sad-pagination__controls {
	display: flex;
	align-items: center;
	gap: 12px;
}

.sad-pagination__info,
.sad-pagination__page {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
