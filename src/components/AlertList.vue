<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="sad-alertlist">
		<div class="sad-alertlist__card">
			<!-- Filters only. This bar never carries the selection count or the
			     bulk actions, so it can't change height when rows are selected
			     (those float in BulkActionBar below). -->
			<div class="sad-alertlist__toolbar">
				<NcCheckboxRadioSwitch class="sad-alertlist__select-all"
					:model-value="allSelected"
					@update:model-value="$emit('toggle-all', $event)">
					{{ t('share_audit_dashboard', 'Select all') }}
				</NcCheckboxRadioSwitch>
				<slot name="leading" />
				<span class="sad-alertlist__spacer" aria-hidden="true" />
				<slot name="trailing" />
				<!-- Last, so that when the line is too full it is the search that drops
				     to a line of its own (the toggles and selects keep theirs), and the
				     tab order is the visual order. -->
				<div v-if="$slots.search" class="sad-alertlist__search">
					<slot name="search" />
				</div>
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
			:default-expiry-days="defaultExpiryDays"
			:max-expiry-days="maxExpiryDays"
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
		// The instance's expiration policy, for the bulk "Set expiry" (see
		// BulkActionBar).
		defaultExpiryDays: {
			type: Number,
			default: 30,
		},
		maxExpiryDays: {
			type: Number,
			default: null,
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
	--sad-toolbar-inset: 20px;

	// What an NcSelect measures: the clickable area plus its two borders.
	--sad-select-height: calc(var(--default-clickable-area) + 2 * var(--border-width-input, 1px));

	display: flex;
	// One line whenever it fits — which never depends on the selection. Only
	// a narrow list wraps it (rather than squeezing the labels).
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 12px;
	box-sizing: border-box;
	min-height: 52px;
	padding: 4px var(--sad-toolbar-inset);
	border-bottom: 1px solid var(--color-border);
	border-radius: calc(var(--border-radius-container, 12px) - 1px) calc(var(--border-radius-container, 12px) - 1px) 0 0;
	background-color: var(--color-background-hover);
}

// The rows start 4px in, where AlertCard's severity stripe ends, while this bar
// is inset 20px: pull "Select all" back so its checkbox sits in the same column
// as the rows' checkboxes. The other controls keep the bar's inset.
.sad-alertlist__select-all {
	margin-inline-start: calc(4px - var(--sad-toolbar-inset));
}

// Slotted controls keep their natural width; the spacer takes what is left.
.sad-alertlist__toolbar > :deep(*) {
	flex: none;
}

// After the rule above, which gives slotted controls their natural width.
// The basis is the floor the box may shrink to, so line-breaking counts only
// that much: it takes the room the other controls leave (up to max-width) and,
// when even the floor doesn't fit, wraps onto a line of its own.
.sad-alertlist__search {
	// Beside the selects, the field is 6px shorter: its box is the clickable area
	// minus the two 2px rings it keeps for focus, theirs the area plus two
	// borders. Asking it for a taller area makes its box the selects' height.
	--default-clickable-area: calc(var(--sad-select-height) + 2 * var(--border-width-input-focused, 2px));

	flex: 1 1 150px;
	min-width: 150px;
	max-width: 320px;
}

.sad-alertlist__spacer {
	flex: 1 1 0;
	min-width: 0;
}

.sad-alertlist__rows {
	margin: 0;
	padding: 0;
	list-style: none;
}
</style>
