<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div>
		<div class="sad-section-head">
			<h3 class="sad-section-title">{{ t('share_audit_dashboard', 'Orphan shares') }}</h3>
			<span class="sad-section-sep" aria-hidden="true">·</span>
			<p class="sad-section-sub">
				{{ t('share_audit_dashboard', 'Shares whose owner is a disabled or deleted account. These keep granting access after the person is gone.') }}
			</p>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" class="sad-loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent v-else-if="items.length === 0"
			:name="t('share_audit_dashboard', 'No orphan shares')"
			:description="t('share_audit_dashboard', 'Every share is owned by an active account.')">
			<template #icon>
				<span class="icon-checkmark" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<NcNoteCard v-if="notice" :type="notice.type" class="sad-orphan-notice">
				{{ notice.message }}
				<template v-if="notice.details">
					<span class="sad-orphan-notice__title">{{ notice.detailsTitle }}</span>
					<ul class="sad-orphan-notice__list">
						<li v-for="line in notice.details" :key="line">
							{{ line }}
						</li>
					</ul>
				</template>
			</NcNoteCard>

			<div class="sad-orphan-bar">
				<NcCheckboxRadioSwitch :model-value="allSelected" @update:model-value="toggleAll">
					{{ t('share_audit_dashboard', 'Select all') }}
				</NcCheckboxRadioSwitch>
				<span v-if="selectedIds.length" class="sad-orphan-bar__count">
					{{ n('share_audit_dashboard', '%n selected', '%n selected', selectedIds.length) }}
				</span>
				<div class="sad-orphan-bar__spacer" />
				<template v-if="selectedIds.length">
					<template v-if="confirming">
						<span class="sad-orphan-bar__confirm">
							{{ n('share_audit_dashboard', 'Revoke %n share?', 'Revoke %n shares?', selectedIds.length) }}
						</span>
						<NcButton variant="error" :disabled="busy" @click="revokeSelected">
							{{ t('share_audit_dashboard', 'Confirm') }}
						</NcButton>
						<NcButton variant="tertiary" :disabled="busy" @click="confirming = false">
							{{ t('share_audit_dashboard', 'Cancel') }}
						</NcButton>
					</template>
					<template v-else-if="pickingOwner">
						<div class="sad-orphan-bar__pick">
							<span class="sad-orphan-bar__confirm">
								{{ n('share_audit_dashboard', 'Transfer %n share to', 'Transfer %n shares to', selectedIds.length) }}
							</span>
							<NcSelect v-model="newOwner"
								class="sad-orphan-bar__select"
								:options="ownerOptions"
								:loading="ownersLoading"
								:filterable="false"
								:clearable="false"
								:disabled="busy"
								:placeholder="t('share_audit_dashboard', 'Search for a user…')"
								:aria-label-combobox="t('share_audit_dashboard', 'New owner')"
								@search="searchOwners" />
							<NcButton variant="primary" :disabled="!newOwner || busy" @click="transferSelected">
								{{ t('share_audit_dashboard', 'Transfer') }}
							</NcButton>
							<NcButton variant="tertiary" :disabled="busy" @click="cancelTransfer">
								{{ t('share_audit_dashboard', 'Cancel') }}
							</NcButton>
						</div>
					</template>
					<template v-else>
						<NcButton variant="secondary" :disabled="busy" @click="openTransfer">
							{{ t('share_audit_dashboard', 'Transfer selected') }}
						</NcButton>
						<NcButton variant="error" :disabled="busy" @click="confirming = true">
							{{ t('share_audit_dashboard', 'Revoke selected') }}
						</NcButton>
					</template>
				</template>

				<PageSizeSelect v-if="!pickingOwner"
					v-model="pageSize"
					:options="pageSizeOptions"
					:width="120"
					:disabled="busy" />
			</div>

			<div class="sad-table-wrapper">
				<table class="sad-table">
					<caption class="hidden-visually">
						{{ t('share_audit_dashboard', 'Shares owned by a disabled or deleted account, selectable for bulk revoke.') }}
					</caption>
					<thead>
						<tr>
							<th class="sad-table__check" />
							<th scope="col">{{ t('share_audit_dashboard', 'Owner') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Path') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Recipient') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Type') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Permissions') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Created') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="share in items" :key="share.id">
							<td class="sad-table__check">
								<NcCheckboxRadioSwitch :model-value="selectedIds.includes(share.id)"
									@update:model-value="toggleSelect(share.id, $event)" />
							</td>
							<td>
								<span class="sad-owner">{{ share.ownerDisplayName || share.owner }}</span>
								<span class="sad-owner__status" :class="'sad-owner__status--' + share.ownerStatus">
									{{ statusLabel(share.ownerStatus) }}
								</span>
								<span v-if="share.ownerDisplayName && share.ownerDisplayName !== share.owner"
									class="sad-owner__uid">{{ share.owner }}</span>
							</td>
							<td class="sad-table__path" :title="share.path">{{ share.path || '—' }}</td>
							<td>{{ recipientOf(share) }}</td>
							<td><NcChip :text="categoryLabel(share.category)" :no-close="true" /></td>
							<td class="sad-table__perms">
								{{ share.permissionLabels.map(permissionLabel).join(', ') || '—' }}
							</td>
							<td>{{ formatDate(share.created) }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="sad-pagination">
				<span class="sad-pagination__info">{{ rangeLabel }}</span>
				<PageNavigation v-if="!isAll && total > apiLimit"
					:page="page"
					:total-pages="totalPages"
					:disabled="busy"
					@change="goto" />
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
import NcSelect from '@nextcloud/vue/components/NcSelect'
import PageNavigation from '../components/PageNavigation.vue'
import PageSizeSelect from '../components/PageSizeSelect.vue'
import { categoryLabel, permissionLabel, formatDate } from '../utils/format.js'
import { fetchOrphans, revokeOrphans, searchTransferTargets, transferOrphans } from '../services/api.js'

// Must match OrphanShareController::MAX_IDS — larger selections are split
// into sequential requests instead of one huge revoke call.
const BULK_CHUNK_SIZE = 500

// "Display name (uid)", or just the uid when the account has no other name.
const ownerLabel = (user) => (user.displayName && user.displayName !== user.uid
	? `${user.displayName} (${user.uid})`
	: user.uid)

export default {
	name: 'OrphanShares',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcChip,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		PageNavigation,
		PageSizeSelect,
	},
	emits: ['orphan-count'],
	data() {
		return {
			loading: true,
			error: null,
			revoking: false,
			confirming: false,
				pickingOwner: false,
				newOwner: null,
				ownerOptions: [],
				ownersLoading: false,
				transferring: false,
				// The picker's debounced search: its timer, and a counter so a slow
				// answer to an older query never replaces a newer one.
				searchTimer: null,
				searchSeq: 0,
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
		// Either action is in flight: lock the controls that would start another.
		busy() {
			return this.revoking || this.transferring
		},
		isAll() {
			return this.pageSize.id === 'all'
		},
		// Numeric limit sent to the API; 0 means "return every orphan".
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
		// Nothing selected, nothing left to confirm or to pick an owner for.
		selectedIds(ids) {
			if (ids.length === 0) {
				this.confirming = false
				this.cancelTransfer()
			}
		},
		// A different page size invalidates the current page and selection.
		'pageSize.id'() {
			this.page = 1
			this.selectedIds = []
			this.confirming = false
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
		permissionLabel,
		formatDate,
		statusLabel(status) {
			const labels = {
				disabled: t('share_audit_dashboard', 'disabled'),
				deleted: t('share_audit_dashboard', 'deleted'),
			}
			return labels[status] ?? status
		},
		recipientOf(share) {
			if (share.recipient) {
				return share.recipient
			}
			return share.category === 'link' ? t('share_audit_dashboard', '(public)') : '—'
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
		openTransfer() {
			this.notice = null
			this.newOwner = null
			this.pickingOwner = true
			this.searchOwners('')
		},
		cancelTransfer() {
			this.pickingOwner = false
			this.newOwner = null
		},
		// Candidates come from the server, enabled accounts only, and the search
		// runs as the admin types (after a short pause) rather than loading them all.
		searchOwners(query) {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(async () => {
				const seq = ++this.searchSeq
				this.ownersLoading = true
				try {
					const users = await searchTransferTargets(query)
					if (seq === this.searchSeq) {
						this.ownerOptions = users.map((user) => ({ ...user, label: ownerLabel(user) }))
					}
				} catch (e) {
					if (seq === this.searchSeq) {
						this.ownerOptions = []
					}
				} finally {
					if (seq === this.searchSeq) {
						this.ownersLoading = false
					}
				}
			}, query ? 250 : 0)
		},
		skipReasonLabel(reason) {
			const labels = {
				not_orphan: t('share_audit_dashboard', 'The owner is no longer disabled or deleted'),
				unsupported_type: t('share_audit_dashboard', 'This type of share cannot be transferred'),
				recipient_is_new_owner: t('share_audit_dashboard', 'The new owner is who the share is for'),
				no_access: t('share_audit_dashboard', 'The new owner cannot access the file'),
				not_shareable: t('share_audit_dashboard', 'The new owner may not share the file'),
				insufficient_permissions: t('share_audit_dashboard', 'The share grants more than the new owner may'),
			}
			return labels[reason] ?? reason
		},
		// What happened to a batch: a success, or a warning that names each reason
		// a share stayed put — an admin needs to know which ones and why.
		transferNotice({ transferred, skipped, failed }, owner) {
			const name = owner.displayName || owner.uid
			const message = transferred > 0
				? n('share_audit_dashboard', 'Transferred %n share to {name}.', 'Transferred %n shares to {name}.', transferred, { name })
				: t('share_audit_dashboard', 'No share was transferred.')
			const left = skipped.length + failed.length
			if (left === 0) {
				return { type: 'success', message }
			}

			const counts = {}
			for (const { reason } of skipped) {
				counts[reason] = (counts[reason] ?? 0) + 1
			}
			const details = Object.entries(counts)
				.map(([reason, count]) => `${this.skipReasonLabel(reason)} (${count})`)
			if (failed.length > 0) {
				details.push(`${t('share_audit_dashboard', 'Unexpected error, try again')} (${failed.length})`)
			}
			if (counts.no_access) {
				details.push(t('share_audit_dashboard', 'Move the files first with occ files:transfer-ownership, then transfer the shares.'))
			}
			return {
				type: transferred === 0 && skipped.length === 0 ? 'error' : 'warning',
				message,
				detailsTitle: n('share_audit_dashboard', '%n share was not transferred:', '%n shares were not transferred:', left),
				details,
			}
		},
		async transferSelected() {
			if (!this.newOwner) {
				return
			}
			this.transferring = true
			this.notice = null
			try {
				const owner = this.newOwner
				const total = { transferred: 0, skipped: [], failed: [] }
				for (let i = 0; i < this.selectedIds.length; i += BULK_CHUNK_SIZE) {
					const chunk = this.selectedIds.slice(i, i + BULK_CHUNK_SIZE)
					const res = await transferOrphans(chunk, owner.uid)
					total.transferred += res.transferred
					total.skipped.push(...res.skipped)
					total.failed.push(...res.failed)
				}
				this.notice = this.transferNotice(total, owner)
				this.cancelTransfer()
				this.selectedIds = []
				if (this.page > 1 && this.items.length === total.transferred) {
					this.page -= 1
				}
				await this.load()
			} catch (e) {
				this.notice = {
					type: 'error',
					message: e?.response?.status === 400
						? t('share_audit_dashboard', 'That account cannot take over shares. Pick another one.')
						: t('share_audit_dashboard', 'Could not transfer the selected shares.'),
				}
			} finally {
				this.transferring = false
			}
		},
		async load() {
			this.loading = true
			this.error = null
			try {
				const data = await fetchOrphans({ page: this.page, limit: this.apiLimit })
				this.items = data.items
				this.total = data.total
				this.selectedIds = this.selectedIds.filter((id) => this.items.some((s) => s.id === id))
				this.$emit('orphan-count', this.total)
			} catch (e) {
				this.error = t('share_audit_dashboard', 'Could not load orphan shares.')
			} finally {
				this.loading = false
			}
		},
		async revokeSelected() {
			this.revoking = true
			this.notice = null
			try {
				let deleted = 0
				for (let i = 0; i < this.selectedIds.length; i += BULK_CHUNK_SIZE) {
					const chunk = this.selectedIds.slice(i, i + BULK_CHUNK_SIZE)
					const res = await revokeOrphans(chunk)
					deleted += res.deleted
				}
				this.notice = {
					type: 'success',
					message: n('share_audit_dashboard', 'Revoked %n share.', 'Revoked %n shares.', deleted),
				}
				this.confirming = false
				this.selectedIds = []
				if (this.page > 1 && this.items.length === deleted) {
					this.page -= 1
				}
				await this.load()
			} catch (e) {
				this.notice = { type: 'error', message: t('share_audit_dashboard', 'Could not revoke the selected shares.') }
			} finally {
				this.revoking = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
// Title and subtitle share a baseline, separated by a middot.
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
	// Nextcloud caps <p> in settings at 900px, which forces a needless wrap.
	max-width: none;
}

.sad-orphan-bar {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 12px;
	padding: 10px 14px;
	margin-bottom: 12px;
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover);
}

.sad-orphan-bar__spacer {
	flex: 1;
}

.sad-orphan-bar__count,
.sad-orphan-bar__confirm {
	font-weight: 600;
}

.sad-orphan-notice {
	margin-bottom: 12px;
}

.sad-orphan-bar__pick {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
}

.sad-orphan-bar__select {
	min-width: 260px;
}

.sad-orphan-notice__title {
	display: block;
	margin-top: 6px;
	font-weight: 600;
}

.sad-orphan-notice__list {
	margin: 4px 0 0;
	padding-left: 20px;
	list-style: disc;
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
	max-width: 300px;
	overflow: hidden;
	text-overflow: ellipsis;
}

.sad-table__perms {
	white-space: normal;
	min-width: 140px;
}

.sad-owner__uid {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.sad-owner__status {
	font-size: 11px;
	font-weight: 600;
	padding: 1px 6px;
	margin-left: 6px;
	border-radius: var(--border-radius, 6px);
	color: var(--sad-ink-on-solid);
}

.sad-owner__status--disabled {
	background-color: var(--sad-warning);
	color: var(--sad-warning-on);
}

.sad-owner__status--deleted {
	background-color: var(--sad-critical);
}

.sad-pagination {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-top: 16px;
}

.sad-pagination__info {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
