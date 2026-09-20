<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div>
		<NcLoadingIcon v-if="loading" :size="32" class="sad-loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<!-- Generated passwords and the last action's notice render regardless of
		     whether any insecure links are still left: fixing the very last one
		     must not sweep away the password you just generated for it. -->
		<template v-else>
			<NcNoteCard v-if="generatedPasswords.length" type="success" class="sad-pw-panel">
				<div class="sad-pw-panel__title">
					{{ t('share_audit_dashboard', 'Generated passwords — copy them now, they are not shown again:') }}
				</div>
				<ul>
					<li v-for="(pw, i) in generatedPasswords" :key="i" class="sad-pw-row">
						<span class="sad-pw-row__path">{{ pw.path }}</span>
						<code class="sad-pw-row__code">{{ pw.password }}</code>
						<NcButton variant="tertiary" @click="copy(pw.password)">
							{{ t('share_audit_dashboard', 'Copy') }}
						</NcButton>
					</li>
				</ul>
				<template #actions>
					<NcButton variant="tertiary" @click="generatedPasswords = []">
						{{ t('share_audit_dashboard', 'Dismiss') }}
					</NcButton>
				</template>
			</NcNoteCard>

			<NcNoteCard v-if="notice" :type="notice.type" class="sad-action-notice">
				{{ notice.message }}
			</NcNoteCard>

			<!-- Only shown standalone when AlertList isn't rendered (no
			     active items to select) — otherwise this toggle lives next to
			     "Select all" inside its toolbar, via the #leading slot. -->
			<div v-if="items.length === 0" class="sad-alerts-toolbar">
				<NcCheckboxRadioSwitch :model-value="showAcknowledged" @update:model-value="onToggleShowAcknowledged">
					{{ t('share_audit_dashboard', 'Show acknowledged') }}
				</NcCheckboxRadioSwitch>
			</div>

			<NcEmptyContent v-if="items.length === 0 && !activeIssue"
				:name="t('share_audit_dashboard', 'All clear')"
				:description="t('share_audit_dashboard', 'No insecure public links were found.')">
				<template #icon>
					<span class="icon-checkmark" />
				</template>
			</NcEmptyContent>

			<template v-else>
				<section class="sad-alerts-breakdown">
					<div class="sad-alerts-breakdown__header">
						<h3>{{ t('share_audit_dashboard', 'Alerts by category') }}</h3>
						<NcButton v-if="activeIssue" variant="tertiary" @click="clearIssueFilter">
							{{ t('share_audit_dashboard', 'Showing: {label} — clear filter', { label: issueLabel(activeIssue) }) }}
						</NcButton>
					</div>
					<HBarChart :rows="breakdownRows"
						track-color="var(--sad-track)"
						label-width="180px"
						clickable
						:active-key="activeIssue"
						@select="onIssueSelect" />
				</section>

				<NcEmptyContent v-if="items.length === 0"
					:name="t('share_audit_dashboard', 'No alerts in this category')"
					:description="t('share_audit_dashboard', 'Clear the filter to see the other insecure links.')">
					<template #icon>
						<span class="icon-checkmark" />
					</template>
				</NcEmptyContent>

				<template v-else>
					<AlertList :count="selectedIds.length"
						:all-selected="allSelected"
						:busy="busy"
						show-acknowledge
						@bulk="onBulk"
						@toggle-all="toggleAll"
						@clear="selectedIds = []">
						<template #leading>
							<NcCheckboxRadioSwitch :model-value="showAcknowledged" @update:model-value="onToggleShowAcknowledged">
								{{ t('share_audit_dashboard', 'Show acknowledged') }}
							</NcCheckboxRadioSwitch>
						</template>
						<template #trailing>
							<PageSizeSelect v-model="sortOption"
								:options="sortOptions"
								:label="t('share_audit_dashboard', 'Sort by')"
								:width="250"
								:disabled="busy"
								:aria-label="t('share_audit_dashboard', 'Sort alerts by')" />
							<PageSizeSelect v-model="pageSize"
								:options="pageSizeOptions"
								:width="120"
								:disabled="busy"
								:aria-label="t('share_audit_dashboard', 'Alerts per page')" />
						</template>

						<AlertCard v-for="alert in items"
							:key="alert.id"
							:alert="alert"
							:busy="busy"
							:selected="selectedIds.includes(alert.id)"
							:expanded="expandedId === alert.id"
							@update:selected="toggleSelect(alert.id, $event)"
							@toggle="toggleExpand(alert.id)"
							@action="onCardAction" />
					</AlertList>

					<div v-if="!isAll && total > apiLimit" class="sad-pagination">
						<span class="sad-pagination__range">{{ rangeLabel }}</span>
						<PageNavigation :page="page" :total-pages="totalPages" :disabled="busy" @change="goto" />
					</div>
				</template>
			</template>
		</template>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AlertCard from '../components/AlertCard.vue'
import AlertList from '../components/AlertList.vue'
import HBarChart from '../components/HBarChart.vue'
import PageNavigation from '../components/PageNavigation.vue'
import PageSizeSelect from '../components/PageSizeSelect.vue'
import { issueLabel } from '../utils/format.js'
import {
	fetchAlerts, setSharePassword, setShareExpiration, revokeShare, bulkShareAction,
	acknowledgeAlert, unacknowledgeAlert, bulkAcknowledgeAlerts,
} from '../services/api.js'

// Must match ShareActionController::BULK_MAX_IDS — larger selections ("Select
// all" with limit=0) are split into sequential requests instead of one huge
// bulk call.
const BULK_CHUNK_SIZE = 500

export default {
	name: 'SecurityAlerts',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		AlertCard,
		AlertList,
		HBarChart,
		PageNavigation,
		PageSizeSelect,
	},
	emits: ['alerts-count'],
	data() {
		return {
			loading: true,
			error: null,
			busy: false,
			items: [],
			breakdown: {},
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
			sortOptions: [
				{ id: 'severity', label: t('share_audit_dashboard', 'Severity (default)') },
				{ id: 'created_asc', label: t('share_audit_dashboard', 'Oldest first') },
				{ id: 'created_desc', label: t('share_audit_dashboard', 'Newest first') },
			],
			sortOption: { id: 'severity', label: t('share_audit_dashboard', 'Severity (default)') },
			selectedIds: [],
			// Alert whose details drawer is open — one at a time.
			expandedId: null,
			generatedPasswords: [],
			notice: null,
			// Issue code (e.g. 'no_password') the list is currently restricted
			// to, set by clicking a bar in the "Alerts by category" chart.
			// '' means no filter.
			activeIssue: '',
			// When true, alerts an admin has already acknowledged (see
			// AckService) are included too — each issue annotated with who
			// accepted it and when — instead of being dropped from the
			// active list. See ShareApiController::alerts()'s
			// $includeAcknowledged.
			showAcknowledged: false,
		}
	},
	computed: {
		breakdownRows() {
			// Distinct colour per alert category (consistent across the app).
			const colors = {
				no_password: 'var(--sad-alert-no-password)',
				no_expiration: 'var(--sad-alert-no-expiration)',
				sensitive_file: 'var(--sad-alert-sensitive)',
				expiring_soon: 'var(--sad-alert-expiring-soon)',
				already_expired: 'var(--sad-alert-already-expired)',
				group_share_editable: 'var(--sad-alert-group-share-editable)',
				public_upload: 'var(--sad-alert-public-upload)',
			}
			return Object.entries(this.breakdown).map(([key, count]) => ({
				key,
				label: issueLabel(key),
				count,
				color: colors[key] ?? 'var(--sad-type-other)',
			}))
		},
		allSelected() {
			return this.items.length > 0 && this.selectedIds.length === this.items.length
		},
		isAll() {
			return this.pageSize.id === 'all'
		},
		// Numeric limit sent to the API; 0 means "return every alert".
		apiLimit() {
			return this.isAll ? 0 : this.pageSize.id
		},
		// Maps the selected sort option to the API's sort/sortDir params.
		apiSort() {
			return this.sortOption.id === 'severity' ? 'severity' : 'created'
		},
		apiSortDir() {
			return this.sortOption.id === 'created_asc' ? 'asc' : 'desc'
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
		// A different page size invalidates the current page and selection.
		'pageSize.id'() {
			this.page = 1
			this.selectedIds = []
			this.load()
		},
		'sortOption.id'() {
			this.page = 1
			this.selectedIds = []
			this.load()
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		n,
		async load() {
			try {
				const data = await fetchAlerts({
					page: this.page,
					limit: this.apiLimit,
					issue: this.activeIssue,
					sort: this.apiSort,
					sortDir: this.apiSortDir,
					includeAcknowledged: this.showAcknowledged,
				})
				this.items = data.items
				this.breakdown = data.breakdown ?? {}
				this.total = data.total ?? this.items.length
				// A revoke/expire on the last page can leave it empty — step back.
				if (this.items.length === 0 && this.page > 1) {
					this.page = Math.min(this.page - 1, this.totalPages)
					await this.load()
					return
				}
				this.selectedIds = this.selectedIds.filter((id) => this.items.some((a) => a.id === id))
				if (!this.items.some((a) => a.id === this.expandedId)) {
					this.expandedId = null
				}
				// The tab badge always reflects every insecure link, not just
				// the current category filter.
				this.$emit('alerts-count', data.totalAll ?? this.total)
			} catch (e) {
				this.error = t('share_audit_dashboard', 'Could not load security alerts.')
			} finally {
				this.loading = false
			}
		},
		issueLabel,
		onIssueSelect(key) {
			this.activeIssue = this.activeIssue === key ? '' : key
			this.page = 1
			this.selectedIds = []
			this.load()
		},
		clearIssueFilter() {
			this.activeIssue = ''
			this.page = 1
			this.selectedIds = []
			this.load()
		},
		onToggleShowAcknowledged(value) {
			this.showAcknowledged = value
			this.page = 1
			this.selectedIds = []
			this.load()
		},
		goto(page) {
			if (page < 1 || page > this.totalPages || page === this.page) {
				return
			}
			this.page = page
			this.selectedIds = []
			this.load()
		},
		toggleAll(checked) {
			this.selectedIds = checked ? this.items.map((a) => a.id) : []
		},
		toggleExpand(id) {
			this.expandedId = this.expandedId === id ? null : id
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
		copy(text) {
			navigator.clipboard?.writeText(text)
		},
		async onCardAction({
			type, id, days, path, ruleCodes, note,
		}) {
			this.busy = true
			this.notice = null
			try {
				if (type === 'password') {
					const res = await setSharePassword(id)
					this.generatedPasswords.push({ path, password: res.password })
				} else if (type === 'expiration') {
					const res = await setShareExpiration(id, days)
					this.notice = { type: 'success', message: t('share_audit_dashboard', 'Expiration set to {date}', { date: res.expiration }) }
				} else if (type === 'revoke') {
					await revokeShare(id)
					this.notice = { type: 'success', message: t('share_audit_dashboard', 'Share revoked.') }
				} else if (type === 'acknowledge') {
					await acknowledgeAlert(id, ruleCodes, note)
					this.notice = { type: 'success', message: t('share_audit_dashboard', 'Marked as accepted.') }
				} else if (type === 'unacknowledge') {
					await unacknowledgeAlert(id, ruleCodes)
					this.notice = { type: 'success', message: t('share_audit_dashboard', 'Exception removed — this alert is active again.') }
				}
				await this.load()
			} catch (e) {
				this.notice = { type: 'error', message: t('share_audit_dashboard', 'The action could not be completed.') }
			} finally {
				this.busy = false
			}
		},
		async onBulk({ action, days }) {
			if (!this.selectedIds.length) {
				return
			}
			const idToPath = Object.fromEntries(this.items.map((a) => [a.id, a.path]))
			// Only used for 'acknowledge': each alert keeps its own issue set,
			// unlike revoke/password/expiration which apply uniformly.
			const idToIssues = Object.fromEntries(this.items.map((a) => [a.id, a.issues.map((iss) => iss.code)]))
			this.busy = true
			this.notice = null
			try {
				let succeeded = 0
				let failed = 0
				let total = 0
				for (let i = 0; i < this.selectedIds.length; i += BULK_CHUNK_SIZE) {
					const chunk = this.selectedIds.slice(i, i + BULK_CHUNK_SIZE)
					const data = action === 'acknowledge'
						? await bulkAcknowledgeAlerts(chunk.map((id) => ({ id, ruleCodes: idToIssues[id] || [] })))
						: await bulkShareAction(action, chunk, days ? { days } : {})
					succeeded += data.succeeded
					failed += data.failed
					total += data.total
					if (action === 'password') {
						for (const r of data.results) {
							if (r.success && r.password) {
								this.generatedPasswords.push({ path: idToPath[r.id] ?? ('#' + r.id), password: r.password })
							}
						}
					}
				}
				this.notice = {
					type: failed ? 'warning' : 'success',
					message: t('share_audit_dashboard', '{ok} of {total} shares updated.', { ok: succeeded, total }),
				}
				this.selectedIds = []
				await this.load()
			} catch (e) {
				this.notice = { type: 'error', message: t('share_audit_dashboard', 'The bulk action could not be completed.') }
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.sad-alerts-toolbar {
	display: flex;
	// Left-aligned so it sits above "Select all" (AlertList's own
	// left-aligned checkbox) rather than opposite it — this toggle must stay
	// outside that bar so it's still reachable with zero *active* alerts
	// (the "All clear" empty state), but it should still read as part of the
	// same left-hand control cluster once the bar appears below it.
	justify-content: flex-start;
	margin-bottom: 8px;
}

.sad-alerts-breakdown {
	padding: 16px;
	margin-bottom: 20px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);

	h3 {
		margin: 0;
		font-size: 15px;
	}
}

.sad-alerts-breakdown__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 12px;
}

.sad-pw-panel__title {
	font-weight: 600;
	margin-bottom: 6px;
}

.sad-pw-row {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 2px 0;
}

.sad-pw-row__path {
	color: var(--color-text-maxcontrast);
	max-width: 320px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sad-pw-row__code {
	font-family: monospace;
	background: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: var(--border-radius, 6px);
}

.sad-action-notice {
	margin-bottom: 12px;
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
