<template>
	<div>
		<section class="sad-lookup-section">
			<h3 class="sad-section-title">{{ t('share_audit_dashboard', 'Access lookup') }}</h3>
			<RecipientDrilldown />
		</section>

		<section class="sad-orphans-section">
			<h3 class="sad-section-title">{{ t('share_audit_dashboard', 'Orphan shares') }}</h3>
			<OrphanShares @orphan-count="$emit('orphan-count', $event)" />
		</section>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import RecipientDrilldown from './RecipientDrilldown.vue'
import OrphanShares from './OrphanShares.vue'

export default {
	name: 'LookupAndOrphans',
	components: {
		RecipientDrilldown,
		OrphanShares,
	},
	emits: ['orphan-count'],
	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.sad-section-title {
	margin: 0 0 8px;
	font-size: 17px;
}

// Generous separation between the two merged sections.
.sad-orphans-section {
	margin-top: 32px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border);
}
</style>
