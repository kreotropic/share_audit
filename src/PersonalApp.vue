<template>
	<div class="sad-personal">
		<h2>{{ t('share_audit_dashboard', 'My shares audit') }}</h2>
		<p class="settings-hint">
			{{ t('share_audit_dashboard', 'Review the files and folders you share, and fix risky public links.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="32" class="sad-loading" />

		<NcNoteCard v-else-if="error" type="error">{{ error }}</NcNoteCard>

		<template v-else>
			<div class="sad-personal__summary">
				<div class="sad-card">
					<span class="sad-card__count">{{ summary.total }}</span>
					<span class="sad-card__label">{{ t('share_audit_dashboard', 'Shares you created') }}</span>
				</div>
				<div class="sad-card" :class="{ 'sad-card--warn': summary.alertsCount > 0 }">
					<span class="sad-card__count">{{ summary.alertsCount }}</span>
					<span class="sad-card__label">{{ t('share_audit_dashboard', 'Links needing attention') }}</span>
				</div>
			</div>

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
			</NcNoteCard>

			<NcNoteCard v-if="notice" :type="notice.type" class="sad-personal__notice">
				{{ notice.message }}
			</NcNoteCard>

			<!-- My alerts -->
			<section v-if="alerts.length" class="sad-personal__block">
				<h3>{{ t('share_audit_dashboard', 'Your links that need attention') }}</h3>
				<BulkActionBar :count="selectedIds.length"
					:all-selected="allSelected"
					:busy="busy"
					@bulk="onBulk"
					@toggle-all="toggleAll"
					@clear="selectedIds = []" />
				<ul class="sad-alerts">
					<AlertCard v-for="alert in alerts"
						:key="alert.id"
						:alert="alert"
						:busy="busy"
						:selected="selectedIds.includes(alert.id)"
						@update:selected="toggleSelect(alert.id, $event)"
						@action="onAction" />
				</ul>
			</section>

			<!-- My shares -->
			<section class="sad-personal__block">
				<h3>{{ t('share_audit_dashboard', 'All your shares') }}</h3>
				<NcNoteCard v-if="sharesTotal > shares.length"
					type="warning"
					class="sad-personal__truncated">
					{{ t('share_audit_dashboard',
						'Showing the first {shown} of {total} shares.',
						{ shown: shares.length, total: sharesTotal }) }}
				</NcNoteCard>
				<p v-if="shares.length === 0" class="settings-hint">
					{{ t('share_audit_dashboard', 'You have not shared anything.') }}
				</p>
				<div v-else class="sad-table-wrapper">
					<table class="sad-table">
						<thead>
							<tr>
								<th>{{ t('share_audit_dashboard', 'Type') }}</th>
								<th>{{ t('share_audit_dashboard', 'Path') }}</th>
								<th>{{ t('share_audit_dashboard', 'Recipient') }}</th>
								<th>{{ t('share_audit_dashboard', 'Permissions') }}</th>
								<th>{{ t('share_audit_dashboard', 'Created') }}</th>
								<th>{{ t('share_audit_dashboard', 'Expires') }}</th>
								<th>{{ t('share_audit_dashboard', 'Password') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="share in shares" :key="share.id">
								<td><NcChip :text="categoryLabel(share.category)" :no-close="true" /></td>
								<td class="sad-table__path" :title="share.path">{{ share.path || '—' }}</td>
								<td>{{ recipientOf(share) }}</td>
								<td class="sad-table__perms">{{ share.permissionLabels.map(permissionLabel).join(', ') || '—' }}</td>
								<td>{{ formatDate(share.created) }}</td>
								<td>{{ share.expiration || '—' }}</td>
								<td>
									<span :class="share.hasPassword ? 'sad-yes' : 'sad-no'">
										{{ share.hasPassword ? t('share_audit_dashboard', 'Yes') : t('share_audit_dashboard', 'No') }}
									</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		</template>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AlertCard from './components/AlertCard.vue'
import BulkActionBar from './components/BulkActionBar.vue'
import { categoryLabel, permissionLabel, formatDate } from './utils/format.js'
import {
	fetchMySummary, fetchMyShares, fetchMyAlerts,
	setMySharePassword, setMyShareExpiration, revokeMyShare,
} from './services/api.js'

export default {
	name: 'PersonalApp',
	components: {
		NcButton,
		NcChip,
		NcLoadingIcon,
		NcNoteCard,
		AlertCard,
		BulkActionBar,
	},
	data() {
		return {
			loading: true,
			error: null,
			busy: false,
			summary: { total: 0, alertsCount: 0 },
			alerts: [],
			shares: [],
			sharesTotal: 0,
			selectedIds: [],
			generatedPasswords: [],
			notice: null,
		}
	},
	computed: {
		allSelected() {
			return this.alerts.length > 0 && this.selectedIds.length === this.alerts.length
		},
	},
	async mounted() {
		await this.loadAll()
	},
	methods: {
		t,
		n,
		categoryLabel,
		permissionLabel,
		formatDate,
		toggleSelect(id, checked) {
			if (checked) {
				if (!this.selectedIds.includes(id)) {
					this.selectedIds.push(id)
				}
			} else {
				this.selectedIds = this.selectedIds.filter((x) => x !== id)
			}
		},
		toggleAll(checked) {
			this.selectedIds = checked ? this.alerts.map((a) => a.id) : []
		},
		async onBulk({ action, days }) {
			if (!this.selectedIds.length) {
				return
			}
			const idToPath = Object.fromEntries(this.alerts.map((a) => [a.id, a.path]))
			const ids = [...this.selectedIds]
			this.busy = true
			this.notice = null
			let ok = 0
			let failed = 0
			for (const id of ids) {
				try {
					if (action === 'password') {
						const res = await setMySharePassword(id)
						this.generatedPasswords.push({ path: idToPath[id] ?? ('#' + id), password: res.password })
					} else if (action === 'expiration') {
						await setMyShareExpiration(id, days)
					} else if (action === 'revoke') {
						await revokeMyShare(id)
					}
					ok++
				} catch (e) {
					failed++
				}
			}
			this.notice = {
				type: failed ? 'warning' : 'success',
				message: t('share_audit_dashboard', '{ok} of {total} shares updated.', { ok, total: ids.length }),
			}
			this.selectedIds = []
			await this.refresh()
			this.busy = false
		},
		recipientOf(share) {
			if (share.recipient) {
				return share.recipient
			}
			return share.category === 'link' ? t('share_audit_dashboard', '(public)') : '—'
		},
		copy(text) {
			navigator.clipboard?.writeText(text)
		},
		async loadAll() {
			try {
				const [summary, alerts, shares] = await Promise.all([
					fetchMySummary(),
					fetchMyAlerts(),
					fetchMyShares({ limit: 200 }),
				])
				this.summary = summary
				this.alerts = alerts.items
				this.shares = shares.items
				this.sharesTotal = shares.total
			} catch (e) {
				this.error = t('share_audit_dashboard', 'Could not load your shares.')
			} finally {
				this.loading = false
			}
		},
		async refresh() {
			const [summary, alerts, shares] = await Promise.all([
				fetchMySummary(),
				fetchMyAlerts(),
				fetchMyShares({ limit: 200 }),
			])
			this.summary = summary
			this.alerts = alerts.items
			this.shares = shares.items
			this.sharesTotal = shares.total
			this.selectedIds = this.selectedIds.filter((id) => this.alerts.some((a) => a.id === id))
		},
		async onAction({ type, id, days, path }) {
			this.busy = true
			this.notice = null
			try {
				if (type === 'password') {
					const res = await setMySharePassword(id)
					this.generatedPasswords.push({ path, password: res.password })
				} else if (type === 'expiration') {
					const res = await setMyShareExpiration(id, days)
					this.notice = { type: 'success', message: t('share_audit_dashboard', 'Expiration set to {date}', { date: res.expiration }) }
				} else if (type === 'revoke') {
					await revokeMyShare(id)
					this.notice = { type: 'success', message: t('share_audit_dashboard', 'Share revoked.') }
				}
				await this.refresh()
			} catch (e) {
				this.notice = { type: 'error', message: t('share_audit_dashboard', 'The action could not be completed.') }
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.sad-personal {
	max-width: 1000px;
}

.sad-personal__summary {
	display: flex;
	gap: 12px;
	margin: 12px 0 20px;
}

.sad-card {
	display: flex;
	flex-direction: column;
	min-width: 160px;
	padding: 14px 16px;
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover);
}

.sad-card--warn {
	background-color: var(--color-warning);
	color: #fff;
}

.sad-card__count {
	font-size: 26px;
	font-weight: 600;
	line-height: 1.1;
}

.sad-card__label {
	font-size: 13px;
	opacity: 0.9;
}

.sad-personal__block {
	margin-top: 20px;
}

.sad-personal__block h3 {
	font-size: 15px;
	margin: 0 0 12px;
}

.sad-alerts {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.sad-personal__notice,
.sad-personal__truncated {
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
	max-width: 300px;
	overflow: hidden;
	text-overflow: ellipsis;
}

.sad-table__perms {
	white-space: normal;
	min-width: 140px;
}

.sad-no {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}

.sad-yes {
	color: var(--color-success-text, var(--color-success));
}
</style>
