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
						:class="['sad-alert__chip', 'sad-alert__chip--' + issue.code]"
						:text="issueLabel(issue.code)"
						:no-close="true" />
				</div>
				<div class="sad-alert__meta">
					<span class="sad-alert__path" :title="alert.path">{{ alert.path || '—' }}</span>
					<a v-if="alert.fileId"
						:href="filesUrl(alert.fileId)"
						target="_blank"
						rel="noopener noreferrer">
						{{ t('share_audit_dashboard', 'Open in Files') }}
					</a>
					<span>
						{{ t('share_audit_dashboard', 'Owner: {owner}', { owner: alert.ownerDisplayName || alert.owner }) }}
						<span v-if="alert.ownerDisplayName && alert.ownerDisplayName !== alert.owner"
							class="sad-alert__uid">{{ alert.owner }}</span>
					</span>
					<span v-if="alert.recipient">
						{{ t('share_audit_dashboard', 'Group: {group} · {count} members', { group: alert.recipientLabel, count: alert.memberCount }) }}
					</span>
					<span>{{ formatDate(alert.created) }}</span>
				</div>
			</div>
		</div>

		<div class="sad-alert__actions">
			<NcButton v-if="alert.token" type="tertiary" :disabled="busy" @click="copyLink">
				{{ linkCopied ? t('share_audit_dashboard', 'Copied!') : t('share_audit_dashboard', 'Copy link') }}
			</NcButton>

			<NcButton v-if="hasIssue('no_password') || hasIssue('public_upload')"
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
import { generateUrl } from '@nextcloud/router'
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
			linkCopied: false,
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
		filesUrl(fileId) {
			return generateUrl('/f/' + fileId)
		},
		async copyLink() {
			const url = window.location.origin + generateUrl('/s/' + this.alert.token)
			await navigator.clipboard.writeText(url)
			this.linkCopied = true
			setTimeout(() => {
				this.linkCopied = false
			}, 2000)
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
	border-left-color: var(--sad-critical);
}

.sad-alert--warning {
	border-left-color: var(--sad-warning);
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
	color: var(--sad-ink-on-solid);
}

// Severity colours match the dashboard chart palette. Amber is light, so its
// badge uses dark text; the darker colours keep white text.
.sad-alert__badge--critical {
	background-color: var(--sad-critical);
}

.sad-alert__badge--warning {
	background-color: var(--sad-warning);
	color: var(--sad-warning-on);
}

.sad-alert__badge--info {
	background-color: var(--sad-info);
}

// Issue tags, hued per category to match the "Alerts by category" chart.
//
// "Soft" pattern: a tinted surface with saturated ink of the same hue. Solid
// fills with white text failed WCAG AA (white on coral is only 3.1:1). Because
// --color-main-text flips from near-black to near-white with the theme, one
// formula yields a readable chip in both light and dark.
//
// (NcChip forwards our class to its .nc-chip root, whose default background
// needs !important to override.)
.sad-alert__chip {
	--chip: var(--sad-type-other);

	background-color: color-mix(in srgb, var(--chip) 14%, var(--color-main-background)) !important;
	color: color-mix(in srgb, var(--chip) 50%, var(--color-main-text)) !important;
	box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--chip) 32%, var(--color-main-background));
}

.sad-alert__chip--no_password {
	--chip: var(--sad-alert-no-password);
}

.sad-alert__chip--no_expiration {
	--chip: var(--sad-alert-no-expiration);
}

.sad-alert__chip--sensitive_file {
	--chip: var(--sad-alert-sensitive);
}

.sad-alert__chip--expiring_soon {
	--chip: var(--sad-alert-expiring-soon);
}

.sad-alert__chip--already_expired {
	--chip: var(--sad-alert-already-expired);
}

.sad-alert__chip--group_share_editable {
	--chip: var(--sad-alert-group-share-editable);
}

.sad-alert__chip--public_upload {
	--chip: var(--sad-alert-public-upload);
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

.sad-alert__uid {
	opacity: 0.75;
	margin-left: 4px;
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
