<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="sad-alertlist">
		<div class="sad-alertlist__card">
			<!-- Filters only. This bar is always one line and never carries the
			     selection count or the bulk actions, so it can't change height
			     when rows are selected (those float in BulkActionBar below). -->
			<div class="sad-alertlist__toolbar">
				<NcCheckboxRadioSwitch :model-value="allSelected"
					@update:model-value="$emit('toggle-all', $event)">
					{{ t('share_audit_dashboard', 'Select all') }}
				</NcCheckboxRadioSwitch>
				<slot name="leading" />
				<span class="sad-alertlist__spacer" aria-hidden="true" />
				<slot name="trailing" />
			</div>

			<ul class="sad-alertlist__rows">
				<slot />
			</ul>
		</div>

		<!-- A sibling of the card, not inside it: the card must not clip (the
		     toolbar's dropdowns hang below it) and sticky needs the tall
		     parent. -->
		<BulkActionBar :count="count"
			:busy="busy"
			:show-acknowledge="showAcknowledge"
			@bulk="$emit('bulk', $event)"
			@clear="$emit('clear')" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import BulkActionBar from './BulkActionBar.vue'

/**
 * The alerts list: one bordered card holding a filter toolbar and the rows
 * (AlertCard, via the default slot), with the floating bulk-action bar under
 * it. Shared by the admin "Security alerts" tab and the personal view.
 */
export default {
	name: 'AlertList',
	components: {
		BulkActionBar,
		NcCheckboxRadioSwitch,
	},
	props: {
		count: {
			type: Number,
			required: true,
		},
		allSelected: {
			type: Boolean,
			default: false,
		},
		busy: {
			type: Boolean,
			default: false,
		},
		showAcknowledge: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['bulk', 'clear', 'toggle-all'],
	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.sad-alertlist__card {
	// The rows' narrow-layout rules query this container (see AlertCard.vue).
	container: sad-alerts / inline-size;

	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container, 12px);
	background-color: var(--color-main-background);
}

.sad-alertlist__toolbar {
	display: flex;
	// One line whenever it fits — which never depends on the selection. Only
	// a narrow list wraps it (rather than squeezing the labels).
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 16px;
	box-sizing: border-box;
	min-height: 52px;
	padding: 4px 20px;
	border-bottom: 1px solid var(--color-border);
	border-radius: calc(var(--border-radius-container, 12px) - 1px) calc(var(--border-radius-container, 12px) - 1px) 0 0;
	background-color: var(--color-background-hover);
}

// Slotted controls keep their natural width; the spacer takes what is left.
.sad-alertlist__toolbar > :deep(*) {
	flex: none;
}

.sad-alertlist__spacer {
	flex: 1 1 8px;
	min-width: 8px;
}

.sad-alertlist__rows {
	margin: 0;
	padding: 0;
	list-style: none;
}
</style>
