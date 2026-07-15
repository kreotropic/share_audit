<template>
	<div class="sad-recipient">
		<div class="sad-section-head">
			<h3 class="sad-section-title">{{ t('share_audit_dashboard', 'Access lookup') }}</h3>

			<NcTextField v-model="query"
				class="sad-recipient__search"
				:label="t('share_audit_dashboard', 'Search user, group or email')"
				:label-outside="true"
				:placeholder="t('share_audit_dashboard', 'Search user, group or email to see what they can reach…')"
				@update:model-value="onSearch">
				<template #icon>
					<span class="sad-recipient__search-icon" v-html="magnify" />
				</template>
			</NcTextField>
		</div>

		<!-- Autocomplete results -->
		<ul v-if="!selected && results.length" class="sad-recipient__results">
			<li v-for="r in results"
				:key="r.shareType + ':' + r.shareWith"
				class="sad-recipient__result"
				@click="select(r)">
				<NcChip :text="categoryLabel(r.category)" :no-close="true" />
				<span class="sad-recipient__name">{{ r.label }}</span>
				<span v-if="r.label !== r.shareWith" class="sad-recipient__id">{{ r.shareWith }}</span>
				<span class="sad-recipient__count">
					{{ n('share_audit_dashboard', '%n share', '%n shares', r.count) }}
				</span>
			</li>
		</ul>

		<p v-else-if="!selected && searched && query.length >= 2 && !loading" class="settings-hint sad-recipient__empty">
			{{ t('share_audit_dashboard', 'No recipient matches “{query}”.', { query }) }}
		</p>

		<!-- Selected recipient detail -->
		<template v-if="selected">
			<div class="sad-recipient__head">
				<NcButton type="tertiary" @click="clearSelection">
					{{ t('share_audit_dashboard', '← Back') }}
				</NcButton>
				<h3 class="sad-recipient__title">
					<NcChip :text="categoryLabel(selected.category)" :no-close="true" />
					{{ selected.label }}
					<span class="sad-recipient__has">
						{{ n('share_audit_dashboard', 'has access to %n item', 'has access to %n items', total) }}
					</span>
				</h3>
				<span class="sad-recipient__spacer" />
				<PageSizeSelect v-model="pageSize"
					:options="pageSizeOptions"
					:width="120"
					:disabled="loading || revoking" />
				<template v-if="total > 0">
					<template v-if="!confirming">
						<NcButton type="error" :disabled="revoking" @click="confirming = true">
							{{ t('share_audit_dashboard', 'Revoke all access') }}
						</NcButton>
					</template>
					<template v-else>
						<span class="sad-recipient__confirm">
							{{ n('share_audit_dashboard', 'Revoke %n share?', 'Revoke %n shares?', total) }}
						</span>
						<NcButton type="error" :disabled="revoking" @click="revokeAll">
							{{ t('share_audit_dashboard', 'Confirm') }}
						</NcButton>
						<NcButton type="tertiary" :disabled="revoking" @click="confirming = false">
							{{ t('share_audit_dashboard', 'Cancel') }}
						</NcButton>
					</template>
				</template>
			</div>

			<NcNoteCard v-if="notice" :type="notice.type" class="sad-recipient__notice">
				{{ notice.message }}
			</NcNoteCard>

			<NcLoadingIcon v-if="loading" :size="32" class="sad-loading" />

			<div v-if="!loading && items.length" class="sad-table-wrapper">
				<table class="sad-table">
					<caption class="hidden-visually">
						{{ t('share_audit_dashboard', 'Every share granting this recipient access.') }}
					</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('share_audit_dashboard', 'Path') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Shared by') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Permissions') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Created') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Expires') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="share in items" :key="share.id">
							<td class="sad-table__path" :title="share.path">{{ share.path || '—' }}</td>
							<td>
								{{ share.ownerDisplayName || share.owner }}
								<span v-if="share.ownerDisplayName && share.ownerDisplayName !== share.owner"
									class="sad-table__uid">{{ share.owner }}</span>
							</td>
							<td class="sad-table__perms">
								{{ share.permissionLabels.map(permissionLabel).join(', ') || '—' }}
							</td>
							<td>{{ formatDate(share.created) }}</td>
							<td>
								<span v-if="share.expiration">{{ share.expiration }}</span>
								<span v-else class="sad-recipient__warn">{{ t('share_audit_dashboard', 'never') }}</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div v-if="!loading && total > apiLimit" class="sad-pagination">
				<span class="sad-pagination__info">{{ rangeLabel }}</span>
				<div class="sad-pagination__controls">
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
import NcChip from '@nextcloud/vue/components/NcChip'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import PageSizeSelect from '../components/PageSizeSelect.vue'
import { categoryLabel, permissionLabel, formatDate } from '../utils/format.js'
import { searchRecipients, recipientShares, revokeRecipientAll } from '../services/api.js'

// Matches RecipientLookupService::BATCH_SIZE server-side batches; caps how
// many rounds revokeAll() will loop for, e.g. if shares keep coming back as
// failed (a persistently locked file) instead of looping forever.
const MAX_BATCHES = 50

export default {
	name: 'RecipientDrilldown',
	components: {
		NcButton,
		NcChip,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		PageSizeSelect,
	},
	data() {
		return {
			// Material Design Icons "magnify".
			// eslint-disable-next-line max-len
			magnify: '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M9.5,3A6.5,6.5 0 0,1 16,9.5C16,11.11 15.41,12.59 14.44,13.73L14.71,14H15.5L20.5,19L19,20.5L14,15.5V14.71L13.73,14.44C12.59,15.41 11.11,16 9.5,16A6.5,6.5 0 0,1 3,9.5A6.5,6.5 0 0,1 9.5,3M9.5,5C7,5 5,7 5,9.5C5,12 7,14 9.5,14C12,14 14,12 14,9.5C14,7 12,5 9.5,5Z"/></svg>',
			query: '',
			results: [],
			searched: false,
			selected: null,
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
			loading: false,
			revoking: false,
			confirming: false,
			notice: null,
			searchTimer: null,
		}
	},
	computed: {
		isAll() {
			return this.pageSize.id === 'all'
		},
		// Numeric limit sent to the API; 0 means "return every share".
		apiLimit() {
			return this.isAll ? 0 : this.pageSize.id
		},
		totalPages() {
			if (this.isAll) {
				return 1
			}
			return Math.max(1, Math.ceil(this.total / this.apiLimit))
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
		// A different page size invalidates the current page.
		'pageSize.id'() {
			if (!this.selected) {
				return
			}
			this.page = 1
			this.loadShares()
		},
	},
	methods: {
		t,
		n,
		categoryLabel,
		permissionLabel,
		formatDate,
		onSearch() {
			clearTimeout(this.searchTimer)
			this.selected = null
			if (this.query.trim().length < 2) {
				this.results = []
				this.searched = false
				return
			}
			this.searchTimer = setTimeout(this.runSearch, 350)
		},
		async runSearch() {
			try {
				this.results = await searchRecipients(this.query.trim())
				this.searched = true
			} catch (e) {
				this.results = []
			}
		},
		async select(recipient) {
			this.selected = recipient
			this.page = 1
			await this.loadShares()
		},
		async loadShares() {
			this.confirming = false
			this.notice = null
			this.loading = true
			try {
				const data = await recipientShares(this.selected.shareWith, this.selected.shareType, {
					page: this.page,
					limit: this.apiLimit,
				})
				this.items = data.items
				this.total = data.total
			} catch (e) {
				this.notice = { type: 'error', message: t('share_audit_dashboard', 'Could not load access for this recipient.') }
			} finally {
				this.loading = false
			}
		},
		goto(page) {
			if (page < 1 || page > this.totalPages || page === this.page) {
				return
			}
			this.page = page
			this.loadShares()
		},
		clearSelection() {
			this.selected = null
			this.items = []
			this.total = 0
			this.page = 1
			this.confirming = false
			this.notice = null
		},
		async revokeAll() {
			this.revoking = true
			try {
				// The server resolves and deletes one batch (500) per request
				// and reports how many are left; repeat until it reports none,
				// so a recipient with thousands of shares doesn't time out a
				// single HTTP request. MAX_BATCHES is just a safety net against
				// looping forever if shares keep failing (e.g. a stuck lock).
				let deleted = 0
				let remaining = Infinity
				let batches = 0
				while (remaining > 0 && batches < MAX_BATCHES) {
					const res = await revokeRecipientAll(this.selected.shareWith, this.selected.shareType)
					deleted += res.deleted
					remaining = res.remaining
					batches += 1
				}
				this.notice = {
					type: 'success',
					message: n('share_audit_dashboard', 'Revoked %n share.', 'Revoked %n shares.', deleted),
				}
				this.items = []
				this.total = 0
				this.page = 1
				this.confirming = false
				// Refresh the autocomplete so the recipient disappears if empty.
				if (this.query.trim().length >= 2) {
					this.runSearch()
				}
			} catch (e) {
				this.notice = { type: 'error', message: t('share_audit_dashboard', 'Could not revoke access.') }
			} finally {
				this.revoking = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
// Title and search field share one row.
.sad-section-head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 12px;
}

.sad-section-title {
	margin: 0;
	font-size: 17px;
}

.sad-recipient__search {
	width: 350px;
	max-width: 100%;
	margin: 0;
}

.sad-recipient__search-icon {
	display: inline-flex;
	color: var(--color-text-maxcontrast);
}

.sad-recipient__truncated {
	margin-bottom: 12px;
}

.sad-recipient__search {
	max-width: 420px;
	margin-bottom: 16px;
}

.sad-recipient__results {
	max-width: 620px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	overflow: hidden;
}

.sad-recipient__result {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 12px;
	cursor: pointer;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}

	&:hover {
		background: var(--color-background-hover);
	}
}

.sad-recipient__name {
	font-weight: 500;
}

.sad-recipient__id,
.sad-recipient__count {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.sad-recipient__count {
	margin-left: auto;
}

.sad-recipient__empty {
	margin-top: 12px;
}

.sad-recipient__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	margin: 8px 0 16px;
}

.sad-recipient__title {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	font-size: 16px;
}

.sad-recipient__has {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	font-size: 14px;
}

.sad-recipient__spacer {
	flex: 1;
}

.sad-recipient__confirm {
	font-weight: 600;
}

.sad-recipient__notice {
	margin-bottom: 12px;
}

.sad-recipient__warn {
	color: var(--color-warning-text, var(--color-warning));
	font-weight: 600;
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

.sad-table__path {
	max-width: 320px;
	overflow: hidden;
	text-overflow: ellipsis;
}

.sad-table__uid {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.sad-table__perms {
	white-space: normal;
	min-width: 140px;
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
