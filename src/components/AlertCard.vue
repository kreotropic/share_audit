<template>
	<li class="sad-alert" :class="'sad-alert--' + alert.severity">
		<div class="sad-alert__main">
			<NcCheckboxRadioSwitch class="sad-alert__check"
				:model-value="selected"
				@update:model-value="$emit('update:selected', $event)" />

			<div class="sad-alert__body">
				<div class="sad-alert__head">
					<span class="sad-alert__badge" :class="'sad-alert__badge--' + alert.severity">
						{{ severityLabel(alert.severity) }}
					</span>
					<span class="sad-alert__name" :title="alert.path">{{ fileName }}</span>
					<NcChip v-for="issue in alert.issues"
						:key="issue.code"
						class="sad-alert__chip"
						:text="issueLabel(issue.code)"
						:no-close="true" />
				</div>
				<div class="sad-alert__meta">
					<span class="sad-alert__path" :title="alert.path">{{ alert.path || '—' }}</span>
					<span>{{ t('share_audit_dashboard', 'Owner: {owner}', { owner: alert.owner }) }}</span>
					<span>{{ formatDate(alert.created) }}</span>
				</div>
			</div>
		</div>

		<div class="sad-alert__actions">
			<NcButton v-if="hasIssue('no_password')"
				:disabled="busy"
				@click="$emit('action', { type: 'password', id: alert.id, path: alert.path })">
				{{ t('share_audit_dashboard', 'Add password') }}
			</NcButton>

			<NcButton v-if="hasIssue('no_expiration')"
				:disabled="busy"
				@click="$emit('action', { type: 'expiration', id: alert.id, days: 30 })">
				{{ t('share_audit_dashboard', 'Set expiry (30d)') }}
			</NcButton>

			<template v-if="!confirming">
				<NcButton type="tertiary"
					:disabled="busy"
					@click="confirming = true">
					{{ t('share_audit_dashboard', 'Revoke') }}
				</NcButton>
			</template>
			<template v-else>
				<NcButton type="error"
					:disabled="busy"
					@click="revoke">
					{{ t('share_audit_dashboard', 'Confirm') }}
				</NcButton>
				<NcButton type="tertiary" :disabled="busy" @click="confirming = false">
					{{ t('share_audit_dashboard', 'Cancel') }}
				</NcButton>
			</template>
		</div>
	</li>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcChip from '@nextcloud/vue/components/NcChip'
import { issueLabel, formatDate } from '../utils/format.js'

export default {
	name: 'AlertCard',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcChip,
	},
	props: {
		alert: {
			type: Object,
			required: true,
		},
		selected: {
			type: Boolean,
			default: false,
		},
		busy: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['update:selected', 'action'],
	data() {
		return {
			confirming: false,
		}
	},
	computed: {
		fileName() {
			const parts = (this.alert.path || '').split('/').filter(Boolean)
			return parts.length ? parts[parts.length - 1] : '—'
		},
	},
	methods: {
		t,
		issueLabel,
		formatDate,
		hasIssue(code) {
			return this.alert.issues.some((i) => i.code === code)
		},
		revoke() {
			this.confirming = false
			this.$emit('action', { type: 'revoke', id: this.alert.id, path: this.alert.path })
		},
		severityLabel(severity) {
			const labels = {
				critical: t('share_audit_dashboard', 'Critical'),
				warning: t('share_audit_dashboard', 'Warning'),
				info: t('share_audit_dashboard', 'Info'),
			}
			return labels[severity] ?? severity
		},
	},
}
</script>

<style scoped lang="scss">
.sad-alert {
	padding: 12px 14px;
	border-radius: var(--border-radius-large, 12px);
	border: 1px solid var(--color-border);
	border-left: 4px solid var(--color-border);
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.sad-alert--critical {
	border-left-color: var(--color-error);
}

.sad-alert--warning {
	border-left-color: var(--color-warning);
}

.sad-alert__main {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	min-width: 280px;
	flex: 1;
}

.sad-alert__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.sad-alert__badge {
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	padding: 2px 8px;
	border-radius: var(--border-radius, 6px);
	color: var(--color-primary-element-text, #fff);
}

.sad-alert__badge--critical {
	background-color: var(--color-error);
}

.sad-alert__badge--warning {
	background-color: var(--color-warning);
}

.sad-alert__badge--info {
	background-color: var(--color-primary-element);
}

.sad-alert__name {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 360px;
}

.sad-alert__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	margin: 6px 0;
}

.sad-alert__path {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 420px;
}

.sad-alert__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
}
</style>
