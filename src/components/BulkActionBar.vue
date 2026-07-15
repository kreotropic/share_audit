<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="sad-bulkbar">
		<div class="sad-bulkbar__left">
			<NcCheckboxRadioSwitch :model-value="allSelected"
				@update:model-value="$emit('toggle-all', $event)">
				{{ t('share_audit_dashboard', 'Select all') }}
			</NcCheckboxRadioSwitch>
			<span v-if="count" class="sad-bulkbar__count">
				{{ n('share_audit_dashboard', '%n selected', '%n selected', count) }}
			</span>
		</div>

		<div class="sad-bulkbar__right">
			<div v-if="count" class="sad-bulkbar__actions">
				<NcButton :disabled="busy" @click="$emit('bulk', { action: 'password' })">
					{{ t('share_audit_dashboard', 'Add password') }}
				</NcButton>

				<div class="sad-bulkbar__expiry">
					<NcSelect v-model="days"
						class="sad-bulkbar__days"
						:options="dayOptions"
						:clearable="false"
						:append-to-body="false"
						:aria-label-combobox="t('share_audit_dashboard', 'Days')" />
					<NcButton :disabled="busy" @click="$emit('bulk', { action: 'expiration', days: days.id })">
						{{ t('share_audit_dashboard', 'Set expiry') }}
					</NcButton>
				</div>

				<NcButton type="error" :disabled="busy" @click="$emit('bulk', { action: 'revoke' })">
					{{ t('share_audit_dashboard', 'Revoke all') }}
				</NcButton>

				<NcButton type="tertiary" :disabled="busy" @click="$emit('clear')">
					{{ t('share_audit_dashboard', 'Clear selection') }}
				</NcButton>
			</div>

			<!-- Optional trailing controls (e.g. a page-size selector), flush right -->
			<slot name="trailing" />
		</div>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'

export default {
	name: 'BulkActionBar',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
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
	},
	emits: ['bulk', 'clear', 'toggle-all'],
	data() {
		return {
			dayOptions: [
				{ id: 7, label: t('share_audit_dashboard', '7 days') },
				{ id: 30, label: t('share_audit_dashboard', '30 days') },
				{ id: 90, label: t('share_audit_dashboard', '90 days') },
			],
			days: null,
		}
	},
	created() {
		this.days = this.dayOptions[1]
	},
	methods: {
		t,
		n,
	},
}
</script>

<style scoped lang="scss">
.sad-bulkbar {
	position: sticky;
	top: 0;
	z-index: 3;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 14px;
	margin-bottom: 12px;
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover);
}

.sad-bulkbar__left {
	display: flex;
	align-items: center;
	gap: 12px;
}

.sad-bulkbar__count {
	font-weight: 600;
}

.sad-bulkbar__right {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	margin-left: auto;
}

.sad-bulkbar__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.sad-bulkbar__expiry {
	display: flex;
	align-items: center;
	gap: 6px;
}

.sad-bulkbar__days {
	width: 140px;
	min-width: 140px;

	// NcSelect's dropdown defaults to a ~260px min-width, which makes the open
	// menu far wider than the toggle. Pin both to the same width.
	:deep(.vs__dropdown-toggle),
	:deep(.vs__dropdown-menu) {
		width: 140px;
		min-width: 140px;
	}
}

.sad-bulkbar :deep(.v-select) {
	min-width: 0;
}
</style>
