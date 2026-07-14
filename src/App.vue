<template>
	<div class="sad-app">
		<div class="sad-header">
			<h2 class="sad-header__title">{{ t('share_audit_dashboard', 'Share Audit Dashboard') }}</h2>
			<span class="sad-header__sep" aria-hidden="true">·</span>
			<p class="sad-header__sub">
				{{ t('share_audit_dashboard', 'Overview and audit of every share on this instance.') }}
			</p>
		</div>

		<nav class="sad-tabs">
			<div v-for="tab in tabs"
				:key="tab.id"
				class="sad-tab-wrap"
				:class="{ 'sad-tab-wrap--active': activeTab === tab.id }">
				<NcButton :type="activeTab === tab.id ? 'primary' : 'tertiary'"
					@click="selectTab(tab.id)">
					<span class="sad-tab">
						{{ tab.label }}
						<span v-if="counterFor(tab.id) > 0"
							class="sad-badge"
							:class="'sad-badge--' + tab.id">{{ counterFor(tab.id) }}</span>
					</span>
				</NcButton>
			</div>
		</nav>

		<div class="sad-view">
			<Dashboard v-if="activeTab === 'dashboard'"
				@navigate="selectTab($event)"
				@drilldown="onDrilldown"
				@open-shares="onOpenShares"
				@alerts-count="alertsCount = $event"
				@orphan-count="orphanCount = $event"
				@deleted-count="deletedCount = $event" />
			<ShareList v-else-if="activeTab === 'shares'" :preset-types="sharesPreset" />
			<SecurityAlerts v-else-if="activeTab === 'alerts'"
				@alerts-count="alertsCount = $event" />
			<LookupAndOrphans v-else-if="activeTab === 'lookup'"
				@orphan-count="orphanCount = $event" />
			<DeletedShares v-else-if="activeTab === 'deleted'"
				@deleted-count="deletedCount = $event" />
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
import LookupAndOrphans from './views/LookupAndOrphans.vue'
import DeletedShares from './views/DeletedShares.vue'
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
		LookupAndOrphans,
		DeletedShares,
		Settings,
	},
	data() {
		return {
			activeTab: 'dashboard',
			alertsCount: 0,
			orphanCount: 0,
			deletedCount: 0,
			sharesPreset: null,
		}
	},
	computed: {
		tabs() {
			return [
				{ id: 'dashboard', label: t('share_audit_dashboard', 'Dashboard') },
				{ id: 'shares', label: t('share_audit_dashboard', 'All shares') },
				{ id: 'alerts', label: t('share_audit_dashboard', 'Security alerts') },
				{ id: 'lookup', label: t('share_audit_dashboard', 'Lookup & Orphans') },
				{ id: 'deleted', label: t('share_audit_dashboard', 'Deleted shares') },
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
		// A dashboard stat card was clicked: open All shares filtered to its
		// share types (null = the "Total" card → no filter).
		onOpenShares(types) {
			this.sharesPreset = types
			this.activeTab = 'shares'
		},
		counterFor(id) {
			if (id === 'alerts') {
				return this.alertsCount
			}
			if (id === 'lookup') {
				return this.orphanCount
			}
			if (id === 'deleted') {
				return this.deletedCount
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

// Title and subtitle share a baseline, separated by a middot.
.sad-header {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 8px;
}

.sad-header__title {
	margin: 0;
}

.sad-header__sep,
.sad-header__sub {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.sad-header__sub {
	margin: 0;
	// Nextcloud caps <p> in settings at 900px, which forces a needless wrap.
	max-width: none;
}

.sad-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 16px 0;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.sad-tab-wrap {
	position: relative;
	display: inline-flex;
}

// Accent bar under the selected tab, sitting on the tab strip's bottom line.
.sad-tab-wrap--active::after {
	content: '';
	position: absolute;
	left: 6px;
	right: 6px;
	bottom: -9px;
	height: 3px;
	border-radius: 3px 3px 0 0;
	background-color: var(--color-primary-element);
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
	background-color: var(--sad-critical);
}

.sad-badge--lookup,
.sad-badge--deleted {
	background-color: var(--sad-type-other);
}
</style>
