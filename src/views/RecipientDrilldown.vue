<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="sad-recipient">
		<div class="sad-section-head">
			<h3 class="sad-section-title">{{ t('share_audit_dashboard', 'Access lookup') }}</h3>

			<NcTextField v-model="query"
				class="sad-recipient__search"
				:label="t('share_audit_dashboard', 'Search user, group or email')"
				:label-outside="true"
				:placeholder="t('share_audit_dashboard', 'Search user, group or email to see what they can reach…')"
				:disabled="revoking"
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
				<span class="sad-recipient__name">{{ recipientLabel(r) }}</span>
				<span v-if="idHint(r)" class="sad-recipient__id">{{ idHint(r) }}</span>
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
				<NcButton variant="tertiary" :disabled="revoking" @click="clearSelection">
					{{ t('share_audit_dashboard', '← Back') }}
				</NcButton>
				<h3 class="sad-recipient__title">
					<NcChip :text="categoryLabel(selected.category)" :no-close="true" />
					{{ recipientLabel(selected) }}
					<span class="sad-recipient__has">
						{{ n('share_audit_dashboard', 'has %n direct share', 'has %n direct shares', total) }}
					</span>
				</h3>
				<span class="sad-recipient__spacer" />
				<PageSizeSelect v-model="pageSize"
					:options="pageSizeOptions"
					:width="120"
					:disabled="loading || revoking" />
				<template v-if="canManage && total > 0">
					<template v-if="!confirming">
						<NcButton variant="error" :disabled="revoking" @click="confirming = true">
							{{ t('share_audit_dashboard', 'Revoke all direct shares') }}
						</NcButton>
					</template>
					<template v-else>
						<span class="sad-recipient__confirm">
							{{ n('share_audit_dashboard', 'Revoke %n direct share?', 'Revoke %n direct shares?', total) }}
						</span>
						<NcButton variant="error" :disabled="revoking" @click="revokeAll">
							{{ t('share_audit_dashboard', 'Confirm') }}
						</NcButton>
						<NcButton variant="tertiary" :disabled="revoking" @click="confirming = false">
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
						{{ t('share_audit_dashboard', 'Every share made directly to this recipient.') }}
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
				<PageNavigation :page="page" :total-pages="totalPages" :disabled="revoking" @change="goto" />
			</div>

			<!-- Access through groups: shares to a group this user is in. Not
				revoked by "Revoke all direct shares"; each group is looked up
				(and dealt with) on its own. -->
			<div v-if="!loading && viaGroups.length" class="sad-recipient__groups">
				<h4 class="sad-recipient__groups-title">
					{{ t('share_audit_dashboard', 'Also has access through groups') }}
				</h4>
				<p class="settings-hint">
					{{ t('share_audit_dashboard', 'Shares made to a group this account is in. They are not listed above and revoking the direct shares leaves them in place: open a group to see them.') }}
				</p>
				<ul class="sad-recipient__group-list">
					<li v-for="g in viaGroups" :key="g.shareWith">
						<NcButton variant="tertiary" :disabled="revoking" @click="openGroup(g)">
							{{ g.label }}
						</NcButton>
						<span class="sad-recipient__count">
							{{ n('share_audit_dashboard', '%n share', '%n shares', g.count) }}
						</span>
					</li>
				</ul>
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
import PageNavigation from '../components/PageNavigation.vue'
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
		PageNavigation,
		PageSizeSelect,
	},
	inject: {
		canManage: { default: true },
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
			viaGroups: [],
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
			// Bumped by every search / load, so only the answer to the latest
			// one is shown: an earlier, slower request that comes back after it
			// would otherwise overwrite it.
			searchSeq: 0,
			loadSeq: 0,
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
		// A conversation nobody named has an empty label — and, for someone
		// who may not see Talk tokens, nothing else to call it by.
		recipientLabel(recipient) {
			return recipient.label || t('share_audit_dashboard', 'Unnamed conversation')
		},
		// What tells two results apart besides the name: the id itself (a uid,
		// an address, a token an admin may see), or — for a conversation whose
		// token is withheld — the first characters of the opaque handle the
		// server gives it instead.
		idHint(recipient) {
			if (recipient.opaque) {
				return '#' + recipient.shareWith.slice(0, 6)
			}
			return recipient.label !== recipient.shareWith ? recipient.shareWith : ''
		},
		onSearch() {
			clearTimeout(this.searchTimer)
			this.searchSeq++
			this.loadSeq++
			this.selected = null
			if (this.query.trim().length < 2) {
				this.results = []
				this.searched = false
				return
			}
			this.searchTimer = setTimeout(this.runSearch, 350)
		},
		async runSearch() {
			const seq = ++this.searchSeq
			try {
				const results = await searchRecipients(this.query.trim())
				if (seq === this.searchSeq) {
					this.results = results
					this.searched = true
				}
			} catch (e) {
				if (seq === this.searchSeq) {
					this.results = []
				}
			}
		},
		async select(recipient) {
			if (this.revoking) {
				return
			}
			this.selected = recipient
			this.page = 1
			await this.loadShares()
		},
		async loadShares() {
			const seq = ++this.loadSeq
			this.confirming = false
			this.notice = null
			this.loading = true
			try {
				const data = await recipientShares(this.selected.shareWith, this.selected.shareType, {
					page: this.page,
					limit: this.apiLimit,
				})
				if (seq === this.loadSeq) {
					this.items = data.items
					this.total = data.total
					this.viaGroups = data.viaGroups ?? []
				}
			} catch (e) {
				if (seq === this.loadSeq) {
					this.notice = { type: 'error', message: t('share_audit_dashboard', 'Could not load access for this recipient.') }
				}
			} finally {
				if (seq === this.loadSeq) {
					this.loading = false
				}
			}
		},
		goto(page) {
			if (this.revoking || page < 1 || page > this.totalPages || page === this.page) {
				return
			}
			this.page = page
			this.loadShares()
		},
		openGroup(group) {
			this.select({ shareWith: group.shareWith, shareType: 1, category: 'group', label: group.label, count: group.count })
		},
		clearSelection() {
			if (this.revoking) {
				return
			}
			this.loadSeq++
			this.selected = null
			this.items = []
			this.viaGroups = []
			this.total = 0
			this.page = 1
			this.confirming = false
			this.notice = null
		},
		async revokeAll() {
			// The recipient that was confirmed, for every batch: the search and
			// the Back button are disabled meanwhile, but what goes out must not
			// depend on that — `this.selected` is read again after each await.
			const target = { shareWith: this.selected.shareWith, shareType: this.selected.shareType }
			const stillShown = () => this.selected?.shareWith === target.shareWith && this.selected?.shareType === target.shareType
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
				let everFailed = 0
				while (remaining > 0 && batches < MAX_BATCHES) {
					const res = await revokeRecipientAll(target.shareWith, target.shareType)
					deleted += res.deleted
					remaining = res.remaining
					everFailed += (res.failed ?? []).length
					batches += 1
				}
				this.confirming = false
				if (remaining > 0) {
					const parts = [n('share_audit_dashboard', 'Revoked %n share.', 'Revoked %n shares.', deleted)]
					parts.push(n(
						'share_audit_dashboard',
						'%n share still grants this recipient access and could not be revoked.',
						'%n shares still grant this recipient access and could not be revoked.',
						remaining,
					))
					if (stillShown()) {
						this.page = 1
						await this.loadShares()
					}
					// After the reload, which clears the notice when it starts.
					this.notice = { type: 'warning', message: parts.join(' ') }
					return
				}
				this.notice = {
					type: everFailed > 0 ? 'warning' : 'success',
					message: n('share_audit_dashboard', 'Revoked %n share.', 'Revoked %n shares.', deleted),
				}
				this.items = []
				this.total = 0
				this.page = 1
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

.sad-recipient__groups {
	margin-top: 20px;
}

.sad-recipient__groups-title {
	font-weight: bold;
	margin-bottom: 4px;
}

.sad-recipient__group-list li {
	max-width: 480px;
	display: flex;
	align-items: center;
	gap: 8px;
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

.sad-pagination__info {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
