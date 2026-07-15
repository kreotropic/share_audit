<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<label class="sad-pagesize" :style="{ '--sad-pagesize-width': width + 'px' }">
		<span class="sad-pagesize__label">{{ label }}</span>
		<NcSelect :model-value="modelValue"
			class="sad-pagesize__select"
			:options="options"
			:clearable="false"
			:searchable="false"
			:append-to-body="false"
			:disabled="disabled"
			:aria-label-combobox="ariaLabel"
			@update:model-value="$emit('update:modelValue', $event)" />
	</label>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'

/**
 * A compact "Per page" dropdown for paginated views.
 *
 * NcSelect appends its dropdown to <body> and sizes it to `max-content`, which
 * makes the open menu wider than the compact toggle. `:append-to-body="false"`
 * keeps the menu inline so it inherits the toggle width; the `width` prop then
 * pins both to a single value.
 */
export default {
	name: 'PageSizeSelect',
	components: {
		NcSelect,
	},
	props: {
		/** Selected option, e.g. { id: 25, label: '25' } */
		modelValue: {
			type: Object,
			required: true,
		},
		/** Options as { id, label }; id may be a number or 'all'. */
		options: {
			type: Array,
			required: true,
		},
		/** Leading label shown before the select. */
		label: {
			type: String,
			default: () => t('share_audit_dashboard', 'Per page'),
		},
		/** Accessible name for the combobox. */
		ariaLabel: {
			type: String,
			default: () => t('share_audit_dashboard', 'Items per page'),
		},
		/** Fixed width in px for the toggle and its dropdown. */
		width: {
			type: Number,
			default: 120,
		},
		disabled: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['update:modelValue'],
}
</script>

<style scoped lang="scss">
.sad-pagesize {
	display: flex;
	align-items: center;
	gap: 8px;

	&__label {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		white-space: nowrap;
	}

	&__select {
		// !important beats NcSelect's own ".v-select.select { min-width: 260px }"
		// (equal specificity), which would otherwise leave dead space inside.
		width: var(--sad-pagesize-width, 120px) !important;
		min-width: var(--sad-pagesize-width, 120px) !important;

		:deep(.vs__dropdown-toggle),
		:deep(.vs__dropdown-menu) {
			width: var(--sad-pagesize-width, 120px);
			min-width: var(--sad-pagesize-width, 120px);
		}
	}
}
</style>
