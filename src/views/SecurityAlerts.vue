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
			<section class="sad-panel sad-alerts-breakdown">
				<h3>{{ t('share_audit_dashboard', 'Alerts by category') }}</h3>
				<HBarChart :rows="breakdownRows" />
			</section>

			<ul class="sad-alerts">
				<li v-for="alert in items"
				:key="alert.id"
				class="sad-alert"
				:class="'sad-alert--' + alert.severity">
				<div class="sad-alert__head">
					<span class="sad-alert__badge" :class="'sad-alert__badge--' + alert.severity">
						{{ severityLabel(alert.severity) }}
					</span>
					<span class="sad-alert__path" :title="alert.path">{{ alert.path || '—' }}</span>
				</div>
				<div class="sad-alert__meta">
					<span>{{ t('share_audit_dashboard', 'Owner: {owner}', { owner: alert.owner }) }}</span>
					<span>{{ formatDate(alert.created) }}</span>
				</div>
				<div class="sad-alert__issues">
					<NcChip v-for="issue in alert.issues"
						:key="issue.code"
						:text="issueLabel(issue.code)"
						:no-close="true" />
				</div>
			</li>
		</ul>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import HBarChart from '../components/HBarChart.vue'
import { issueLabel, formatDate } from '../utils/format.js'
import { fetchAlerts } from '../services/api.js'

export default {
	name: 'SecurityAlerts',
	components: {
		NcChip,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		HBarChart,
	},
	emits: ['alerts-count'],
	data() {
		return {
			loading: true,
			error: null,
			items: [],
			breakdown: {},
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
	},
	async mounted() {
		try {
			const data = await fetchAlerts()
			this.items = data.items
			this.breakdown = data.breakdown ?? {}
			this.$emit('alerts-count', this.items.length)
		} catch (e) {
			this.error = t('share_audit_dashboard', 'Could not load security alerts.')
		} finally {
			this.loading = false
		}
	},
	methods: {
		t,
		issueLabel,
		formatDate,
		severityLabel(severity) {
			const labels = {
				critical: t('share_audit_dashboard', 'Critical'),
				warning: t('share_audit_dashboard', 'Warning'),
				info: t('share_audit_dashboard', 'Info'),
			}
			return labels[severity] ?? severity
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

.sad-alert {
	padding: 12px 14px;
	border-radius: var(--border-radius-large, 12px);
	border: 1px solid var(--color-border);
	border-left: 4px solid var(--color-border);
}

.sad-alert--critical {
	border-left-color: var(--color-error);
}

.sad-alert--warning {
	border-left-color: var(--color-warning);
}

.sad-alert__head {
	display: flex;
	align-items: center;
	gap: 10px;
}

.sad-alert__badge {
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	padding: 2px 8px;
	border-radius: var(--border-radius, 6px);
	color: var(--color-primary-element-text, #fff);
}

.sad-alert__badge--critical {
	background-color: var(--color-error);
}

.sad-alert__badge--warning {
	background-color: var(--color-warning);
}

.sad-alert__badge--info {
	background-color: var(--color-primary-element);
}

.sad-alert__path {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sad-alert__meta {
	display: flex;
	gap: 16px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	margin: 6px 0;
}

.sad-alert__issues {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 6px;
}
</style>
