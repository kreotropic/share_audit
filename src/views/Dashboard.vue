<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div>
		<NcLoadingIcon v-if="loading" :size="32" class="sad-loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<!-- Collapsible attention bar, above the fold -->
			<div v-if="warningCount > 0" class="sad-alerts">
				<button type="button" class="sad-alerts__toggle" @click="alertsOpen = !alertsOpen">
					<span class="sad-alerts__chev" :class="{ 'sad-alerts__chev--open': alertsOpen }">▸</span>
					{{ n('share_audit_dashboard', '%n item needs attention', '%n items need attention', warningCount) }}
				</button>
				<div v-show="alertsOpen" class="sad-alerts__rows">
					<div v-if="stats.alertsCount > 0" class="sad-alert-row sad-alert-row--error">
						<span class="sad-alert-row__msg">{{ n('share_audit_dashboard',
							'%n share needs attention', '%n shares need attention', stats.alertsCount) }}</span>
						<NcButton type="tertiary" @click="$emit('navigate', 'alerts')">
							{{ t('share_audit_dashboard', 'Review alerts') }}
						</NcButton>
					</div>
					<div v-if="stats.orphanCount > 0" class="sad-alert-row sad-alert-row--warning">
						<span class="sad-alert-row__msg">{{ n('share_audit_dashboard',
							'%n share owned by a disabled or deleted account',
							'%n shares owned by disabled or deleted accounts', stats.orphanCount) }}</span>
						<NcButton type="tertiary" @click="$emit('navigate', 'lookup')">
							{{ t('share_audit_dashboard', 'Review orphans') }}
						</NcButton>
					</div>
				</div>
			</div>

			<StatsCards :by-type="stats.byType"
				:total="stats.total"
				@select="$emit('open-shares', $event)" />

			<div class="sad-row">
				<section class="sad-panel sad-row__main">
					<header class="sad-panel__head">
						<h3>{{ t('share_audit_dashboard', 'Shares created') }}</h3>
						<span class="sad-panel__sub">
							{{ t('share_audit_dashboard', 'Last 12 months') }}
							· {{ t('share_audit_dashboard', '30d') }}: {{ stats.trend.last30 }}
							· {{ t('share_audit_dashboard', '90d') }}: {{ stats.trend.last90 }}
							· {{ t('share_audit_dashboard', '365d') }}: {{ stats.trend.last365 }}
						</span>
					</header>
					<TrendChart :series="stats.trendSeries" :height="150" />
				</section>

				<section class="sad-panel sad-row__side">
					<h3>{{ t('share_audit_dashboard', 'Internal vs external') }}</h3>
					<ExposureDonut :segments="ieSegments"
						:center-value="externalPct + '%'"
						:center-label="t('share_audit_dashboard', 'external')" />
					<ul class="sad-ie-legend">
						<li v-for="seg in ieSegments" :key="seg.key">
							<span class="sad-ie-dot" :style="{ background: seg.color }" />
							<span>{{ seg.label }}</span>
							<span class="sad-ie-val">{{ seg.value }}</span>
						</li>
					</ul>
				</section>
			</div>

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

			<section class="sad-exposure-section">
				<h3 class="sad-section-title">{{ t('share_audit_dashboard', 'Exposure') }}</h3>
				<ExposureMap @drilldown="$emit('drilldown', $event)" />
			</section>
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
import ExposureDonut from '../components/ExposureDonut.vue'
import ExposureMap from './ExposureMap.vue'
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
		ExposureDonut,
		ExposureMap,
	},
	emits: ['navigate', 'alerts-count', 'orphan-count', 'drilldown', 'open-shares'],
	data() {
		return {
			loading: true,
			error: null,
			stats: null,
			alertsOpen: true,
		}
	},
	computed: {
		warningCount() {
			return (this.stats.alertsCount ?? 0) + (this.stats.orphanCount ?? 0)
		},
		buckets() {
			const b = this.stats.byType
			return {
				internal: (b.user ?? 0) + (b.group ?? 0) + (b.talk ?? 0),
				external: (b.link ?? 0) + (b.email ?? 0) + (b.federated ?? 0),
				other: b.other ?? 0,
			}
		},
		ieSegments() {
			const b = this.buckets
			const labels = {
				internal: t('share_audit_dashboard', 'Internal'),
				external: t('share_audit_dashboard', 'External'),
				other: t('share_audit_dashboard', 'Other'),
			}
			const colors = {
				internal: 'var(--sad-internal)',
				external: 'var(--sad-external)',
				other: 'var(--sad-type-other)',
			}
			return Object.entries(b)
				.filter(([, v]) => v > 0)
				.map(([key, value]) => ({ key, label: labels[key], value, color: colors[key] }))
		},
		externalPct() {
			const total = this.buckets.internal + this.buckets.external + this.buckets.other
			return total ? Math.round((this.buckets.external / total) * 100) : 0
		},
	},
	async mounted() {
		try {
			this.stats = await fetchStats()
			this.$emit('alerts-count', this.stats.alertsCount)
			this.$emit('orphan-count', this.stats.orphanCount ?? 0)
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
.sad-alerts {
	margin-bottom: 12px;
}

.sad-alerts__toggle {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	background: none;
	border: none;
	padding: 4px 0;
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
	cursor: pointer;
}

.sad-alerts__chev {
	display: inline-block;
	transition: transform 0.15s ease;
	color: var(--color-text-maxcontrast);
}

.sad-alerts__chev--open {
	transform: rotate(90deg);
}

.sad-alerts__rows {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 6px;
}

.sad-alert-row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 7px 12px;
	border-radius: var(--border-radius, 6px);
	background: var(--color-background-hover);
	border-left: 3px solid var(--color-border);
}

.sad-alert-row--error {
	border-left-color: var(--color-error);
}

.sad-alert-row--warning {
	border-left-color: var(--color-warning);
}

.sad-alert-row--error .sad-alert-row__msg {
	color: var(--color-error-text, var(--color-error));
}

.sad-alert-row__msg {
	flex: 1;
	font-size: 13px;
}

.sad-panel {
	padding: 12px 14px;
	border-radius: var(--border-radius-large, 12px);
	border: 1px solid var(--color-border);

	h3 {
		margin: 0 0 8px;
		font-size: 15px;
	}
}

.sad-row {
	display: grid;
	grid-template-columns: 3fr 1fr;
	gap: 12px;
	margin: 10px 0;
}

.sad-row__main {
	display: flex;
	flex-direction: column;
}

// Let the trend chart grow to fill the card height (matches the donut column).
.sad-row__main :deep(.sad-chart) {
	flex: 1;
	min-height: 150px;
}

@media (max-width: 820px) {
	.sad-row {
		grid-template-columns: 1fr;
	}
}

.sad-row__side {
	display: flex;
	flex-direction: column;
	align-items: center;

	h3 {
		align-self: flex-start;
	}
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

.sad-ie-legend {
	width: 100%;
	margin-top: 8px;
}

.sad-ie-legend li {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 3px 0;
	font-size: 13px;
}

.sad-ie-dot {
	width: 10px;
	height: 10px;
	border-radius: 3px;
	flex-shrink: 0;
}

.sad-ie-val {
	margin-left: auto;
	font-weight: 600;
}

.sad-panels {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 12px;
	margin-bottom: 10px;
}

.sad-exposure-section {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.sad-section-title {
	margin: 0 0 4px;
	font-size: 17px;
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
</style>
