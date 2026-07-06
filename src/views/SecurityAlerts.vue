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
				<HBarChart :rows="breakdownRows" />
			</section>

			<BulkActionBar :count="selectedIds.length"
				:all-selected="allSelected"
				:busy="busy"
				@bulk="onBulk"
				@toggle-all="toggleAll"
				@clear="selectedIds = []" />

			<ul class="sad-alerts">
				<AlertCard v-for="alert in items"
					:key="alert.id"
					:alert="alert"
					:busy="busy"
					:selected="selectedIds.includes(alert.id)"
					@update:selected="toggleSelect(alert.id, $event)"
					@action="onCardAction" />
			</ul>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AlertCard from '../components/AlertCard.vue'
import BulkActionBar from '../components/BulkActionBar.vue'
import HBarChart from '../components/HBarChart.vue'
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
	},
	emits: ['alerts-count'],
	data() {
		return {
			loading: true,
			error: null,
			busy: false,
			items: [],
			breakdown: {},
			selectedIds: [],
			generatedPasswords: [],
			notice: null,
		}
	},
	computed: {
		breakdownRows() {
			return Object.entries(this.breakdown).map(([key, count]) => ({
				key,
				label: issueLabel(key),
				count,
			}))
		},
		allSelected() {
			return this.items.length > 0 && this.selectedIds.length === this.items.length
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		async load() {
			try {
				const data = await fetchAlerts()
				this.items = data.items
				this.breakdown = data.breakdown ?? {}
				this.selectedIds = this.selectedIds.filter((id) => this.items.some((a) => a.id === id))
				this.$emit('alerts-count', this.items.length)
			} catch (e) {
				this.error = t('share_audit_dashboard', 'Could not load security alerts.')
			} finally {
				this.loading = false
			}
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
</style>
