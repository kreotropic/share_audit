<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<!-- Floats over the bottom of the list instead of being a row in the
	     toolbar, so selecting never changes the height of anything above it.
	     Its root must stay a direct child of the (tall) list container: sticky
	     is bounded by its parent. -->
	<Transition name="sad-bulkbar">
		<div v-if="count > 0" class="sad-bulkbar">
			<div class="sad-bulkbar__pill"
				role="region"
				:aria-label="t('share_audit_dashboard', 'Actions for the selection')">
				<span class="sad-bulkbar__count" aria-live="polite">
					{{ n('share_audit_dashboard', '%n selected', '%n selected', count) }}
				</span>
				<span class="sad-bulkbar__sep" aria-hidden="true" />

				<NcButton v-if="showAcknowledge"
					variant="primary"
					:disabled="busy"
					@click="$emit('bulk', { action: 'acknowledge' })">
					{{ t('share_audit_dashboard', 'Acknowledge all') }}
				</NcButton>
				<NcButton :disabled="busy" @click="$emit('bulk', { action: 'revoke' })">
					{{ t('share_audit_dashboard', 'Revoke all') }}
				</NcButton>

				<template v-if="!overflowMode">
					<NcButton :disabled="busy" @click="$emit('bulk', { action: 'password' })">
						{{ t('share_audit_dashboard', 'Add password') }}
					</NcButton>

					<!-- Split control: the action on the left, its period on the right. -->
					<div class="sad-bulkbar__split">
						<NcButton class="sad-bulkbar__split-main"
							:disabled="busy"
							@click="$emit('bulk', { action: 'expiration', days })">
							{{ t('share_audit_dashboard', 'Set expiry') }}
						</NcButton>
						<NcActions class="sad-bulkbar__split-days"
							:menu-name="daysLabel"
							:aria-label="t('share_audit_dashboard', 'Expiry period')"
							placement="top"
							:disabled="busy">
							<template #icon>
								<NcIconSvgWrapper :path="mdiChevronDown" />
							</template>
							<NcActionRadio v-for="option in dayOptions"
								:key="option.id"
								name="sad-bulk-expiry"
								:model-value="days"
								:value="option.id"
								@update:model-value="days = option.id">
								{{ option.label }}
							</NcActionRadio>
						</NcActions>
					</div>
				</template>

				<!-- Narrow list: password and expiry step aside into "More". -->
				<NcActions v-else
					:menu-name="t('share_audit_dashboard', 'More')"
					placement="top-end"
					:disabled="busy">
					<NcActionButton @click="$emit('bulk', { action: 'password' })">
						<template #icon>
							<NcIconSvgWrapper :path="mdiKeyOutline" />
						</template>
						{{ t('share_audit_dashboard', 'Add password') }}
					</NcActionButton>
					<NcActionButton v-for="option in dayOptions"
						:key="option.id"
						@click="$emit('bulk', { action: 'expiration', days: option.id })">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCalendarClock" />
						</template>
						{{ t('share_audit_dashboard', 'Set expiry: {period}', { period: option.label }) }}
					</NcActionButton>
				</NcActions>

				<NcButton class="sad-bulkbar__clear"
					variant="tertiary"
					:aria-label="t('share_audit_dashboard', 'Clear selection')"
					:title="t('share_audit_dashboard', 'Clear selection')"
					@click="$emit('clear')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClose" />
					</template>
				</NcButton>
			</div>
		</div>
	</Transition>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionRadio from '@nextcloud/vue/components/NcActionRadio'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiCalendarClock, mdiChevronDown, mdiClose, mdiKeyOutline } from '../utils/icons.js'

// Below this list width the pill no longer fits on one line with every
// action inline, so password/expiry move into the "More" menu.
const OVERFLOW_BREAKPOINT = 820

export default {
	name: 'BulkActionBar',
	components: {
		NcActionButton,
		NcActionRadio,
		NcActions,
		NcButton,
		NcIconSvgWrapper,
	},
	props: {
		count: {
			type: Number,
			required: true,
		},
		busy: {
			type: Boolean,
			default: false,
		},
		// Adds an "Acknowledge all" bulk action — only meaningful for the
		// security alerts view (see SecurityAlerts.vue); the personal view has
		// no acknowledge concept, hence a prop rather than always showing it.
		showAcknowledge: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['bulk', 'clear'],
	data() {
		return {
			overflowMode: false,
			// Days for the split "Set expiry" control (default 30).
			days: 30,
			dayOptions: [
				{ id: 7, label: t('share_audit_dashboard', '7 days') },
				{ id: 30, label: t('share_audit_dashboard', '30 days') },
				{ id: 90, label: t('share_audit_dashboard', '90 days') },
			],
			mdiCalendarClock,
			mdiChevronDown,
			mdiClose,
			mdiKeyOutline,
		}
	},
	computed: {
		daysLabel() {
			return this.dayOptions.find((option) => option.id === this.days)?.label ?? ''
		},
	},
	mounted() {
		// Measure the list this bar floats over (its parent), not the window:
		// the admin sidebar takes a variable share of the width.
		const list = this.$el?.parentElement
		if (!list || typeof ResizeObserver === 'undefined') {
			return
		}
		this.observer = new ResizeObserver(([entry]) => {
			this.overflowMode = entry.contentRect.width < OVERFLOW_BREAKPOINT
		})
		this.observer.observe(list)
	},
	beforeUnmount() {
		this.observer?.disconnect()
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
	bottom: 16px;
	z-index: 3;
	display: flex;
	justify-content: center;
	// Bottom margin = the sticky offset: at the end of the list the pill's
	// natural spot is already clear of the viewport edge, so sticky never
	// has to pull it up over the last row.
	margin: 16px 0;
	padding: 0 16px;
	// Only the pill takes clicks; the rows beside and under it stay usable.
	pointer-events: none;
}

.sad-bulkbar__pill {
	display: flex;
	align-items: center;
	gap: 6px;
	max-width: 100%;
	padding: 8px 8px 8px 14px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-rounded, 28px);
	background-color: var(--color-main-background);
	box-shadow: 0 6px 24px rgba(var(--color-box-shadow-rgb, 0, 0, 0), 0.25);
	pointer-events: auto;
	white-space: nowrap;

	// Nothing in the pill may shrink or wrap: the breakpoint decides what
	// is shown instead.
	> * {
		flex: none;
	}
}

.sad-bulkbar__count {
	font-size: 14px;
	font-weight: 600;
}

.sad-bulkbar__sep {
	width: 1px;
	height: 24px;
	margin: 0 4px;
	background-color: var(--color-border-dark);
}

.sad-bulkbar__split {
	display: flex;
	align-items: center;
	gap: 1px;

	// Square the inner corners so the two halves read as one control.
	:deep(.button-vue) {
		border-radius: 0;
	}

	.sad-bulkbar__split-main {
		border-start-start-radius: var(--border-radius-element, 8px);
		border-end-start-radius: var(--border-radius-element, 8px);
	}

	.sad-bulkbar__split-days :deep(.button-vue) {
		border-start-end-radius: var(--border-radius-element, 8px);
		border-end-end-radius: var(--border-radius-element, 8px);
	}

	// Period first, chevron after ("30 days ▾").
	.sad-bulkbar__split-days :deep(.button-vue__wrapper) {
		flex-direction: row-reverse;
	}
}

.sad-bulkbar-enter-active,
.sad-bulkbar-leave-active {
	transition: opacity 150ms ease-out, transform 150ms ease-out;
}

.sad-bulkbar-enter-from,
.sad-bulkbar-leave-to {
	opacity: 0;
	transform: translateY(8px);
}

@media (prefers-reduced-motion: reduce) {
	.sad-bulkbar-enter-active,
	.sad-bulkbar-leave-active {
		transition: none;
	}
}
</style>
