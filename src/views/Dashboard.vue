<template>
	<div>
		<NcLoadingIcon v-if="loading" :size="32" class="sad-loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<StatsCards :by-type="stats.byType" :total="stats.total" />

			<section class="sad-panel sad-panel--wide">
				<header class="sad-panel__head">
					<h3>{{ t('share_audit_dashboard', 'Internal vs external sharing') }}</h3>
					<span class="sad-panel__sub">{{ t('share_audit_dashboard', 'Where your shared data is exposed') }}</span>
				</header>
				<InternalExternalBar :by-type="stats.byType" />
			</section>

			<section class="sad-panel sad-panel--wide">
				<header class="sad-panel__head">
					<h3>{{ t('share_audit_dashboard', 'Shares created') }}</h3>
					<span class="sad-panel__sub">
						{{ t('share_audit_dashboard', 'Last 12 months') }}
						· {{ t('share_audit_dashboard', '30d') }}: {{ stats.trend.last30 }}
						· {{ t('share_audit_dashboard', '90d') }}: {{ stats.trend.last90 }}
						· {{ t('share_audit_dashboard', '365d') }}: {{ stats.trend.last365 }}
					</span>
				</header>
				<TrendChart :series="stats.trendSeries" />
			</section>

			<div class="sad-panels">
				<section class="sad-panel">
					<h3>{{ t('share_audit_dashboard', 'Shares by type') }}</h3>
					<TypeBarChart :by-type="stats.byType" />
				</section>

				<section class="sad-panel">
					<h3>{{ t('share_audit_dashboard', 'Top sharers') }}</h3>
					<ul v-if="stats.topOwners.length" class="sad-top">
						<li v-for="owner in stats.topOwners" :key="owner.owner">
							<span class="sad-top__name">
								{{ owner.displayName || owner.owner }}
								<span v-if="owner.displayName && owner.displayName !== owner.owner"
									class="sad-top__uid">{{ owner.owner }}</span>
							</span>
							<span class="sad-top__count">{{ owner.count }}</span>
						</li>
					</ul>
					<p v-else class="settings-hint">
						{{ t('share_audit_dashboard', 'No shares yet.') }}
					</p>
				</section>
			</div>

			<NcNoteCard v-if="stats.alertsCount > 0"
				type="warning"
				class="sad-alerts-note">
				<span>{{ n('share_audit_dashboard',
					'%n share needs attention.', '%n shares need attention.', stats.alertsCount) }}</span>
				<NcButton type="secondary" @click="$emit('navigate', 'alerts')">
					{{ t('share_audit_dashboard', 'Review alerts') }}
				</NcButton>
			</NcNoteCard>
			<NcNoteCard v-else type="success">
				{{ t('share_audit_dashboard', 'No insecure public links detected.') }}
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import StatsCards from '../components/StatsCards.vue'
import TrendChart from '../components/TrendChart.vue'
import TypeBarChart from '../components/TypeBarChart.vue'
import InternalExternalBar from '../components/InternalExternalBar.vue'
import { fetchStats } from '../services/api.js'

export default {
	name: 'Dashboard',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		StatsCards,
		TrendChart,
		TypeBarChart,
		InternalExternalBar,
	},
	emits: ['navigate', 'alerts-count'],
	data() {
		return {
			loading: true,
			error: null,
			stats: null,
		}
	},
	async mounted() {
		try {
			this.stats = await fetchStats()
			this.$emit('alerts-count', this.stats.alertsCount)
		} catch (e) {
			this.error = t('share_audit_dashboard', 'Could not load share statistics.')
		} finally {
			this.loading = false
		}
	},
	methods: {
		t,
		n,
	},
}
</script>

<style scoped lang="scss">
.sad-panel {
	padding: 16px;
	border-radius: var(--border-radius-large, 12px);
	border: 1px solid var(--color-border);

	h3 {
		margin: 0 0 12px;
		font-size: 15px;
	}
}

.sad-panel--wide {
	margin: 20px 0;
}

.sad-panel__head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 8px;
	margin-bottom: 8px;

	h3 {
		margin: 0;
	}
}

.sad-panel__sub {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.sad-panels {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 16px;
	margin-bottom: 20px;
}

.sad-top li {
	display: flex;
	justify-content: space-between;
	padding: 5px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.sad-top li:last-child {
	border-bottom: none;
}

.sad-top__count {
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.sad-top__uid {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-weight: normal;
	margin-left: 6px;
}

.sad-alerts-note {
	display: flex;
	align-items: center;
	gap: 12px;
	justify-content: space-between;
}
</style>
