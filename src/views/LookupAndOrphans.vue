<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div>
		<!-- Each section renders its own title, so the title can share a row
		     with its search field / subtitle. -->
		<section class="sad-lookup-section">
			<RecipientDrilldown />
		</section>

		<section class="sad-orphans-section">
			<OrphanShares @orphan-count="$emit('orphan-count', $event)" />
		</section>
	</div>
</template>

<script>
import RecipientDrilldown from './RecipientDrilldown.vue'
import OrphanShares from './OrphanShares.vue'

export default {
	name: 'LookupAndOrphans',
	components: {
		RecipientDrilldown,
		OrphanShares,
	},
	emits: ['orphan-count'],
}
</script>

<style scoped lang="scss">
// Generous separation between the two merged sections.
.sad-orphans-section {
	margin-top: 32px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border);
}
</style>
