<template>
	<div class="sad-app">
		<h2>{{ t('share_audit_dashboard', 'Share Audit Dashboard') }}</h2>
		<p class="settings-hint">
			{{ t('share_audit_dashboard', 'Overview and audit of every share on this instance.') }}
		</p>

		<nav class="sad-tabs">
			<NcButton v-for="tab in tabs"
				:key="tab.id"
				:type="activeTab === tab.id ? 'primary' : 'tertiary'"
				@click="selectTab(tab.id)">
				<span class="sad-tab">
					{{ tab.label }}
					<span v-if="counterFor(tab.id) > 0"
						class="sad-badge"
						:class="'sad-badge--' + tab.id">{{ counterFor(tab.id) }}</span>
				</span>
			</NcButton>
		</nav>

		<div class="sad-view">
			<Dashboard v-if="activeTab === 'dashboard'"
				@navigate="activeTab = $event"
				@alerts-count="alertsCount = $event"
				@orphan-count="orphanCount = $event" />
			<ShareList v-else-if="activeTab === 'shares'" :preset-types="sharesPreset" />
			<SecurityAlerts v-else-if="activeTab === 'alerts'"
				@alerts-count="alertsCount = $event" />
			<OrphanShares v-else-if="activeTab === 'orphans'"
				@orphan-count="orphanCount = $event" />
			<ExposureMap v-else-if="activeTab === 'exposure'" @drilldown="onDrilldown" />
			<RecipientDrilldown v-else-if="activeTab === 'recipients'" />
			<Settings v-else-if="activeTab === 'settings'" @saved="onSettingsSaved" />
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import Dashboard from './views/Dashboard.vue'
import ShareList from './views/ShareList.vue'
import SecurityAlerts from './views/SecurityAlerts.vue'
import OrphanShares from './views/OrphanShares.vue'
import ExposureMap from './views/ExposureMap.vue'
import RecipientDrilldown from './views/RecipientDrilldown.vue'
import Settings from './views/Settings.vue'

const DRILLDOWN_TYPES = {
	internal: [0, 1, 10, 7],
	external: [4, 6, 9],
	public: [3],
}

export default {
	name: 'App',
	components: {
		NcButton,
		Dashboard,
		ShareList,
		SecurityAlerts,
		OrphanShares,
		ExposureMap,
		RecipientDrilldown,
		Settings,
	},
	data() {
		return {
			activeTab: 'dashboard',
			alertsCount: 0,
			orphanCount: 0,
			sharesPreset: null,
		}
	},
	computed: {
		tabs() {
			return [
				{ id: 'dashboard', label: t('share_audit_dashboard', 'Dashboard') },
				{ id: 'shares', label: t('share_audit_dashboard', 'All shares') },
				{ id: 'alerts', label: t('share_audit_dashboard', 'Security alerts') },
				{ id: 'orphans', label: t('share_audit_dashboard', 'Orphan shares') },
				{ id: 'exposure', label: t('share_audit_dashboard', 'Exposure') },
				{ id: 'recipients', label: t('share_audit_dashboard', 'Access lookup') },
				{ id: 'settings', label: t('share_audit_dashboard', 'Settings') },
			]
		},
	},
	methods: {
		t,
		selectTab(id) {
			if (id !== 'shares') {
				this.sharesPreset = null
			}
			this.activeTab = id
		},
		onDrilldown(category) {
			this.sharesPreset = DRILLDOWN_TYPES[category] ?? null
			this.activeTab = 'shares'
		},
		counterFor(id) {
			if (id === 'alerts') {
				return this.alertsCount
			}
			if (id === 'orphans') {
				return this.orphanCount
			}
			return 0
		},
		onSettingsSaved() {
			// Rules changed → alert badge may be stale. The Dashboard and alerts
			// views use v-if, so they refetch when reopened; nothing to do here.
		},
	},
}
</script>

<style scoped lang="scss">
.sad-app {
	width: 100%;
}

.sad-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 16px 0;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.sad-view {
	margin-top: 8px;
}

.sad-tab {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

// Small, discreet severity pills on the tabs.
.sad-badge {
	display: inline-block;
	min-width: 8px;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	line-height: 1.2;
	color: #fff;
	text-align: center;
}

.sad-badge--alerts {
	background-color: #e9322d;
}

.sad-badge--orphans {
	background-color: #f0ad4e;
}
</style>
