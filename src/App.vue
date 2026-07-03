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
				@click="activeTab = tab.id">
				<span class="sad-tab">
					{{ tab.label }}
					<NcCounterBubble v-if="tab.id === 'alerts' && alertsCount > 0"
						:count="alertsCount"
						class="sad-tab__counter" />
				</span>
			</NcButton>
		</nav>

		<div class="sad-view">
			<Dashboard v-if="activeTab === 'dashboard'"
				@navigate="activeTab = $event"
				@alerts-count="alertsCount = $event" />
			<ShareList v-else-if="activeTab === 'shares'" />
			<SecurityAlerts v-else-if="activeTab === 'alerts'"
				@alerts-count="alertsCount = $event" />
			<Settings v-else-if="activeTab === 'settings'" @saved="onSettingsSaved" />
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import Dashboard from './views/Dashboard.vue'
import ShareList from './views/ShareList.vue'
import SecurityAlerts from './views/SecurityAlerts.vue'
import Settings from './views/Settings.vue'

export default {
	name: 'App',
	components: {
		NcButton,
		NcCounterBubble,
		Dashboard,
		ShareList,
		SecurityAlerts,
		Settings,
	},
	data() {
		return {
			activeTab: 'dashboard',
			alertsCount: 0,
		}
	},
	computed: {
		tabs() {
			return [
				{ id: 'dashboard', label: t('share_audit_dashboard', 'Dashboard') },
				{ id: 'shares', label: t('share_audit_dashboard', 'All shares') },
				{ id: 'alerts', label: t('share_audit_dashboard', 'Security alerts') },
				{ id: 'settings', label: t('share_audit_dashboard', 'Settings') },
			]
		},
	},
	methods: {
		t,
		onSettingsSaved() {
			// Rules changed → alert badge may be stale. The Dashboard and alerts
			// views use v-if, so they refetch when reopened; nothing to do here.
		},
	},
}
</script>

<style scoped lang="scss">
.sad-app {
	max-width: 1100px;
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
</style>
