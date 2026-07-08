<template>
	<div>
		<NcLoadingIcon v-if="loading" :size="32" class="sad-loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent v-else-if="items.length === 0"
			:name="t('share_audit_dashboard', 'All clear')"
			:description="t('share_audit_dashboard', 'No insecure public links were found.')">
			<template #icon>
				<span class="icon-checkmark" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Generated passwords (must be copied before they are lost) -->
			<NcNoteCard v-if="generatedPasswords.length" type="success" class="sad-pw-panel">
				<div class="sad-pw-panel__title">
					{{ t('share_audit_dashboard', 'Generated passwords — copy them now, they are not shown again:') }}
				</div>
				<ul>
					<li v-for="(pw, i) in generatedPasswords" :key="i" class="sad-pw-row">
						<span class="sad-pw-row__path">{{ pw.path }}</span>
						<code class="sad-pw-row__code">{{ pw.password }}</code>
						<NcButton type="tertiary" @click="copy(pw.password)">
							{{ t('share_audit_dashboard', 'Copy') }}
						</NcButton>
					</li>
				</ul>
				<template #actions>
					<NcButton type="tertiary" @click="generatedPasswords = []">
						{{ t('share_audit_dashboard', 'Dismiss') }}
					</NcButton>
				</template>
			</NcNoteCard>

			<NcNoteCard v-if="notice" :type="notice.type" class="sad-action-notice">
				{{ notice.message }}
			</NcNoteCard>

			<section class="sad-alerts-breakdown">
				<h3>{{ t('share_audit_dashboard', 'Alerts by category') }}</h3>
				<HBarChart :rows="breakdownRows" track-color="var(--sad-track)" label-width="180px" />
			</section>

			<BulkActionBar :count="selectedIds.length"
				:all-selected="allSelected"
				:busy="busy"
				@bulk="onBulk"
				@toggle-all="toggleAll"
				@clear="selectedIds = []">
				<template #trailing>
					<PageSizeSelect v-model="pageSize"
						:options="pageSizeOptions"
						:width="120"
						:disabled="busy"
						:aria-label="t('share_audit_dashboard', 'Alerts per page')" />
				</template>
			</BulkActionBar>

			<ul class="sad-alerts">
				<AlertCard v-for="alert in items"
					:key="alert.id"
					:alert="alert"
					:busy="busy"
					:selected="selectedIds.includes(alert.id)"
					@update:selected="toggleSelect(alert.id, $event)"
					@action="onCardAction" />
			</ul>

			<div v-if="!isAll && total > apiLimit" class="sad-pagination">
				<span class="sad-pagination__range">{{ rangeLabel }}</span>
				<div class="sad-pagination__controls">
					<NcButton :disabled="busy || page <= 1" @click="goto(page - 1)">
						{{ t('share_audit_dashboard', 'Previous') }}
					</NcButton>
					<span class="sad-pagination__page">
						{{ n('share_audit_dashboard', 'Page %n', 'Page %n', page) }} / {{ totalPages }}
					</span>
					<NcButton :disabled="busy || page >= totalPages" @click="goto(page + 1)">
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
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AlertCard from '../components/AlertCard.vue'
import BulkActionBar from '../components/BulkActionBar.vue'
import HBarChart from '../components/HBarChart.vue'
import PageSizeSelect from '../components/PageSizeSelect.vue'
import { issueLabel } from '../utils/format.js'
import {
	fetchAlerts, setSharePassword, setShareExpiration, revokeShare, bulkShareAction,
} from '../services/api.js'

export default {
	name: 'SecurityAlerts',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		AlertCard,
		BulkActionBar,
		HBarChart,
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
			selectedIds: [],
			generatedPasswords: [],
			notice: null,
		}
	},
	computed: {
		breakdownRows() {
			// Distinct colour per alert category (consistent across the app).
			const colors = {
				no_password: 'var(--sad-alert-no-password)',
				no_expiration: 'var(--sad-alert-no-expiration)',
				sensitive_file: 'var(--sad-alert-sensitive)',
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
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		n,
		async load() {
			try {
				const data = await fetchAlerts({ page: this.page, limit: this.apiLimit })
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
				this.$emit('alerts-count', this.total)
			} catch (e) {
				this.error = t('share_audit_dashboard', 'Could not load security alerts.')
			} finally {
				this.loading = false
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
		toggleAll(checked) {
			this.selectedIds = checked ? this.items.map((a) => a.id) : []
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
		async onCardAction({ type, id, days, path }) {
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
			this.busy = true
			this.notice = null
			try {
				const data = await bulkShareAction(action, this.selectedIds, days ? { days } : {})
				if (action === 'password') {
					for (const r of data.results) {
						if (r.success && r.password) {
							this.generatedPasswords.push({ path: idToPath[r.id] ?? ('#' + r.id), password: r.password })
						}
					}
				}
				this.notice = {
					type: data.failed ? 'warning' : 'success',
					message: t('share_audit_dashboard', '{ok} of {total} shares updated.', { ok: data.succeeded, total: data.total }),
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
.sad-alerts-breakdown {
	padding: 16px;
	margin-bottom: 20px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);

	h3 {
		margin: 0 0 12px;
		font-size: 15px;
	}
}

.sad-alerts {
	display: flex;
	flex-direction: column;
	gap: 10px;
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

.sad-pagination__controls {
	display: flex;
	align-items: center;
	gap: 12px;
}

.sad-pagination__page,
.sad-pagination__range {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
