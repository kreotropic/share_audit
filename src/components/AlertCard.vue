<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<li class="sad-alert" :class="{ 'sad-alert--open': expanded }">
		<!-- One 44px line: severity, file, reasons, actions. Owner, token and
		     date are audit data rather than triage data, so they live in the
		     details drawer below. Enter on the focused row toggles it too. -->
		<div class="sad-alert__row"
			:class="'sad-alert__row--' + alert.severity"
			role="group"
			tabindex="0"
			:aria-label="fileName"
			@keydown.enter.self.prevent="$emit('toggle')">
			<NcCheckboxRadioSwitch v-if="canManage"
				class="sad-alert__check"
				:model-value="selected"
				:aria-label="t('share_audit_dashboard', 'Select {name}', { name: fileName })"
				@update:model-value="$emit('update:selected', $event)" />

			<span class="sad-alert__badge" :class="'sad-alert__badge--' + alert.severity">
				{{ severityLabel(alert.severity) }}
			</span>
			<span class="sad-alert__name" :title="alert.label ? alert.path + ' — ' + alert.label : alert.path">{{ fileName }}</span>
			<span class="sad-alert__path" :title="alert.path">{{ alert.path || '—' }}</span>

			<span class="sad-alert__chips">
				<NcChip v-for="issue in alert.issues"
					:key="issue.code"
					:class="['sad-alert__chip', 'sad-alert__chip--' + issue.code, { 'sad-alert__chip--acknowledged': issue.acknowledged }]"
					:title="issue.acknowledged ? acknowledgedTitle(issue) : issueLabel(issue.code)"
					:text="issueLabel(issue.code)"
					:no-close="true" />
			</span>

			<span class="sad-alert__spacer" aria-hidden="true" />

			<!-- Fixed order. An action that doesn't apply to this alert keeps
			     its (empty) slot, so every column lines up from row to row. -->
			<div class="sad-alert__actions">
				<NcButton v-if="alert.token"
					variant="tertiary"
					:aria-label="linkCopied ? t('share_audit_dashboard', 'Copied!') : t('share_audit_dashboard', 'Copy link')"
					:title="linkCopied ? t('share_audit_dashboard', 'Copied!') : t('share_audit_dashboard', 'Copy link')"
					:disabled="busy"
					@click="copyLink">
					<template #icon>
						<NcIconSvgWrapper :path="linkCopied ? mdiCheck : mdiLinkVariant" />
					</template>
				</NcButton>
				<span v-else class="sad-alert__slot" aria-hidden="true" />

				<!-- Opens the public link itself, so an alert whose file name
				     means nothing without context can be judged by what it
				     actually shows. A plain link, not a mutation: no `busy`. -->
				<NcButton v-if="alert.token"
					variant="tertiary"
					:href="publicUrl"
					target="_blank"
					rel="noopener noreferrer"
					:aria-label="t('share_audit_dashboard', 'Open link in a new tab')"
					:title="t('share_audit_dashboard', 'Open link in a new tab')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiOpenInNew" />
					</template>
				</NcButton>
				<span v-else class="sad-alert__slot" aria-hidden="true" />

				<NcButton v-if="canManage && (hasIssue('no_password') || hasIssue('public_upload'))"
					variant="tertiary"
					:aria-label="t('share_audit_dashboard', 'Add password')"
					:title="t('share_audit_dashboard', 'Add password')"
					:disabled="busy"
					@click="$emit('action', { type: 'password', id: alert.id, path: alert.path })">
					<template #icon>
						<NcIconSvgWrapper :path="mdiKeyOutline" />
					</template>
				</NcButton>
				<span v-else class="sad-alert__slot" aria-hidden="true" />

				<NcButton v-if="canManage && hasIssue('no_expiration')"
					variant="tertiary"
					:aria-label="t('share_audit_dashboard', 'Set expiry ({days}d)', { days: expiryDays })"
					:title="t('share_audit_dashboard', 'Set expiry ({days}d)', { days: expiryDays })"
					:disabled="busy"
					@click="$emit('action', { type: 'expiration', id: alert.id, days: expiryDays })">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCalendarClock" />
					</template>
				</NcButton>
				<span v-else class="sad-alert__slot" aria-hidden="true" />

				<template v-if="canManage && allowAcknowledge">
					<NcButton v-if="alert.acknowledged"
						class="sad-alert__btn"
						variant="tertiary"
						:aria-label="t('share_audit_dashboard', 'Remove exception')"
						:title="t('share_audit_dashboard', 'Remove exception')"
						:disabled="busy"
						@click="$emit('action', { type: 'unacknowledge', id: alert.id, ruleCodes: issueCodes })">
						<template #icon>
							<NcIconSvgWrapper :path="mdiUndo" />
						</template>
					</NcButton>
					<NcButton v-else
						ref="ackTrigger"
						class="sad-alert__btn sad-alert__btn--accept"
						variant="tertiary"
						:aria-label="t('share_audit_dashboard', 'Acknowledge')"
						:title="t('share_audit_dashboard', 'Acknowledge')"
						:aria-expanded="panel === 'acknowledge'"
						:disabled="busy"
						@click="openPanel('acknowledge')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCheck" />
						</template>
					</NcButton>
				</template>

				<NcButton v-if="canManage"
					ref="revokeTrigger"
					class="sad-alert__btn sad-alert__btn--revoke"
					variant="tertiary"
					:aria-label="t('share_audit_dashboard', 'Revoke')"
					:title="t('share_audit_dashboard', 'Revoke')"
					:aria-expanded="panel === 'revoke'"
					:disabled="busy"
					@click="openPanel('revoke')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClose" />
					</template>
				</NcButton>
				<span v-else class="sad-alert__slot" aria-hidden="true" />

				<NcButton variant="tertiary"
					:aria-label="expanded ? t('share_audit_dashboard', 'Hide details') : t('share_audit_dashboard', 'Details')"
					:title="expanded ? t('share_audit_dashboard', 'Hide details') : t('share_audit_dashboard', 'Details')"
					:aria-expanded="expanded"
					:aria-controls="drawerId"
					@click="$emit('toggle')">
					<template #icon>
						<NcIconSvgWrapper class="sad-alert__chevron" :path="mdiChevronDown" />
					</template>
				</NcButton>
			</div>

			<!-- Announces the copy result: the button's own label only changes. -->
			<span class="hidden-visually" aria-live="polite">{{ linkCopied ? t('share_audit_dashboard', 'Copied!') : '' }}</span>
		</div>

		<!-- Acknowledge (with an optional note) and revoke both need a second
		     step. It opens under the row so the row itself never changes size. -->
		<div v-if="panel === 'acknowledge'"
			class="sad-alert__panel"
			role="group"
			:aria-label="t('share_audit_dashboard', 'Acknowledge')"
			@keydown.esc.stop.prevent="closePanel">
			<input ref="ackNote"
				v-model="ackNote"
				type="text"
				class="sad-alert__ack-note"
				:placeholder="t('share_audit_dashboard', 'Optional note (why this is accepted)')"
				:aria-label="t('share_audit_dashboard', 'Optional note (why this is accepted)')"
				:disabled="busy"
				@keydown.enter.prevent="acknowledge">
			<NcButton variant="primary" :disabled="busy" @click="acknowledge">
				{{ t('share_audit_dashboard', 'Confirm') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="busy" @click="closePanel">
				{{ t('share_audit_dashboard', 'Cancel') }}
			</NcButton>
		</div>
		<div v-else-if="panel === 'revoke'"
			class="sad-alert__panel"
			role="group"
			:aria-label="t('share_audit_dashboard', 'Revoke')"
			@keydown.esc.stop.prevent="closePanel">
			<span class="sad-alert__panel-text">{{ t('share_audit_dashboard', 'Revoke this share?') }}</span>
			<NcButton variant="error" :disabled="busy" @click="revoke">
				{{ t('share_audit_dashboard', 'Confirm') }}
			</NcButton>
			<NcButton ref="revokeCancel" variant="tertiary" :disabled="busy" @click="closePanel">
				{{ t('share_audit_dashboard', 'Cancel') }}
			</NcButton>
		</div>

		<div v-if="expanded" :id="drawerId" class="sad-alert__drawer">
			<span v-if="alert.path" class="sad-alert__drawer-path">
				{{ t('share_audit_dashboard', 'Path: {path}', { path: alert.path }) }}
			</span>
			<!-- The name given to the share itself; the search box matches it. -->
			<span v-if="alert.label">
				{{ t('share_audit_dashboard', 'Share name: {name}', { name: alert.label }) }}
			</span>
			<span>
				{{ t('share_audit_dashboard', 'Owner: {owner}', { owner: alert.ownerDisplayName || alert.owner }) }}
				<span v-if="alert.ownerDisplayName && alert.ownerDisplayName !== alert.owner"
					class="sad-alert__uid">{{ alert.owner }}</span>
			</span>
			<span v-if="alert.recipient">
				{{ t('share_audit_dashboard', 'Group: {group} · {count} members', { group: alert.recipientLabel, count: alert.memberCount }) }}
			</span>
			<span>{{ t('share_audit_dashboard', 'Created: {date}', { date: formatDate(alert.created) }) }}</span>
			<code v-if="alert.token" class="sad-alert__token" :title="t('share_audit_dashboard', 'Share token')">{{ alert.token }}</code>
			<a v-if="alert.fileId"
				:href="filesUrl(alert.fileId)"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('share_audit_dashboard', 'Open in Files') }}
			</a>
			<!-- Same text as the chip tooltip, which keyboard and touch users can't reach. -->
			<span v-for="issue in acknowledgedIssues" :key="issue.code" class="sad-alert__drawer-ack">
				{{ issueLabel(issue.code) }} — {{ acknowledgedTitle(issue) }}
			</span>
		</div>
	</li>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { issueLabel, formatDate } from '../utils/format.js'
import {
	mdiCalendarClock, mdiCheck, mdiChevronDown, mdiClose, mdiKeyOutline, mdiLinkVariant, mdiOpenInNew, mdiUndo,
} from '../utils/icons.js'

export default {
	name: 'AlertCard',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcChip,
		NcIconSvgWrapper,
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
		// Whether the details drawer is open. The parent owns this so that
		// only one alert is expanded at a time.
		expanded: {
			type: Boolean,
			default: false,
		},
		// Acknowledging is an admin concept (see AckService); the personal
		// view has no endpoint for it, so it hides the button.
		allowAcknowledge: {
			type: Boolean,
			default: true,
		},
		// Days the "Set expiry" action applies — the instance's own default
		// (Administration settings → Sharing), see ExpiryDefaultsService.
		expiryDays: {
			type: Number,
			default: 30,
		},
	},
	emits: ['update:selected', 'action', 'toggle'],
	// Provided by src/main.js (admin dashboard / viewer page) from the mount
	// element's data-role; defaults to true so the personal view — a
	// separate Vue app that never provides it, see PersonalApp.vue — and any
	// standalone use of this component keep every action.
	inject: {
		canManage: { default: true },
	},
	data() {
		return {
			// Second step under the row: null | 'acknowledge' | 'revoke'
			panel: null,
			linkCopied: false,
			ackNote: '',
			mdiCalendarClock,
			mdiCheck,
			mdiChevronDown,
			mdiClose,
			mdiKeyOutline,
			mdiLinkVariant,
			mdiOpenInNew,
			mdiUndo,
		}
	},
	computed: {
		fileName() {
			const parts = (this.alert.path || '').split('/').filter(Boolean)
			return parts.length ? parts[parts.length - 1] : '—'
		},
		// The public URL of this link — what "Copy link" copies and "Open
		// link" opens.
		publicUrl() {
			return window.location.origin + generateUrl('/s/' + this.alert.token)
		},
		drawerId() {
			return 'sad-alert-details-' + this.alert.id
		},
		// Every issue code currently shown on this row — acknowledging (or
		// removing the exception on) the whole alert acts on all of them.
		issueCodes() {
			return this.alert.issues.map((i) => i.code)
		},
		acknowledgedIssues() {
			return this.alert.issues.filter((i) => i.acknowledged)
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
			await navigator.clipboard.writeText(this.publicUrl)
			this.linkCopied = true
			setTimeout(() => {
				this.linkCopied = false
			}, 2000)
		},
		openPanel(kind) {
			this.panel = this.panel === kind ? null : kind
			if (!this.panel) {
				return
			}
			// Move focus into the step just opened. For revoke that is
			// "Cancel", so a stray Enter can't confirm a destructive action.
			this.$nextTick(() => {
				if (kind === 'acknowledge') {
					this.$refs.ackNote?.focus()
				} else {
					this.$refs.revokeCancel?.$el?.focus()
				}
			})
		},
		closePanel() {
			const kind = this.panel
			this.panel = null
			this.ackNote = ''
			this.$nextTick(() => {
				const trigger = kind === 'acknowledge' ? this.$refs.ackTrigger : this.$refs.revokeTrigger
				trigger?.$el?.focus()
			})
		},
		revoke() {
			this.panel = null
			this.$emit('action', { type: 'revoke', id: this.alert.id, path: this.alert.path })
		},
		acknowledge() {
			this.panel = null
			this.$emit('action', {
				type: 'acknowledge', id: this.alert.id, ruleCodes: this.issueCodes, note: this.ackNote, path: this.alert.path,
			})
			this.ackNote = ''
		},
		acknowledgedTitle(issue) {
			const date = formatDate(issue.acknowledgedAt)
			const base = t('share_audit_dashboard', 'Accepted by {by} · {date}', { by: issue.acknowledgedBy, date })
			return issue.note ? base + ' — ' + issue.note : base
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
	border-bottom: 1px solid var(--color-border);

	// The list card draws the outer border and radius; the last row only has
	// to follow the inner curve so its hover fill doesn't poke out of it.
	&:last-child {
		border-bottom: 0;
		border-radius: 0 0 calc(var(--border-radius-container, 12px) - 1px) calc(var(--border-radius-container, 12px) - 1px);
	}
}

.sad-alert__row {
	// Severity stripe. A pseudo-element rather than a flex item so it still
	// spans the full height when the row wraps onto two lines.
	--sad-stripe: var(--color-border);

	position: relative;
	display: flex;
	align-items: center;
	gap: 10px;
	min-height: 44px;
	padding: 0 14px 0 4px;

	&::before {
		content: '';
		position: absolute;
		inset: 0 auto 0 0;
		width: 4px;
		background-color: var(--sad-stripe);
	}

	&:hover {
		background-color: var(--color-background-hover);
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: -2px;
	}
}

.sad-alert__row--critical {
	--sad-stripe: var(--sad-critical);
}

.sad-alert__row--warning {
	--sad-stripe: var(--sad-warning);
}

.sad-alert__row--info {
	--sad-stripe: var(--sad-info);
}

.sad-alert__check {
	flex: none;

	// The checkbox's icon carries a top margin meant to line it up with the
	// first line of a label; with no label it would sit above the row's
	// centre. NcCheckboxRadioSwitch's own rule is more specific, hence the
	// !important.
	:deep(.checkbox-radio-switch__icon) {
		margin-block: auto !important;
	}
}

.sad-alert__badge {
	flex: none;
	padding: 2px 7px;
	border-radius: var(--border-radius-small, 4px);
	color: var(--sad-ink-on-solid);
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.05em;
	text-transform: uppercase;
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

// Squeeze order when the row runs out of room: the path goes first (it is
// also in the drawer and the tooltip), then the reason chips (ellipsised,
// with a tooltip), and the file name last — it is what identifies the row,
// so it keeps a floor. The actions never shrink.
.sad-alert__name {
	flex: 0 1 auto;
	min-width: 9ch;
	max-width: 40%;
	overflow: hidden;
	font-size: 14px;
	font-weight: 600;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sad-alert__path {
	flex: 0 100 auto;
	min-width: 0;
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sad-alert__chips {
	flex: 0 4 auto;
	min-width: 0;
	display: flex;
	gap: 6px;
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

	flex: 0 1 auto;
	min-width: 0;

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

// An acknowledged issue (only ever visible in "show acknowledged" mode —
// the default view drops it entirely) stays legible but visually recedes,
// so the still-active chips on a partially-acknowledged alert keep drawing
// the eye. Hover/focus shows who accepted it and why (title attribute).
.sad-alert__chip--acknowledged {
	opacity: 0.55;
	text-decoration: line-through;
	cursor: help;
}

.sad-alert__spacer {
	flex: 1 1 6px;
	min-width: 6px;
}

.sad-alert__actions {
	flex: none;
	display: flex;
	align-items: center;
	gap: 2px;
	// Keeps the actions at the right edge when the row wraps (the spacer
	// only does that while they share a line with everything else).
	margin-inline-start: auto;
}

// Empty stand-in with the size of an icon button.
.sad-alert__slot {
	flex: none;
	width: var(--default-clickable-area, 34px);
	height: var(--default-clickable-area, 34px);
}

// Accept / revoke read green / red at rest; --color-success and --color-error
// are the theme's *soft* fills, so they double as the hover background.
.sad-alert__actions .sad-alert__btn--accept {
	color: var(--color-success-text);

	&:hover:not(:disabled) {
		background-color: var(--color-success);
	}
}

.sad-alert__actions .sad-alert__btn--revoke {
	color: var(--color-error-text);

	&:hover:not(:disabled) {
		background-color: var(--color-error);
	}
}

.sad-alert__chevron {
	transition: transform 150ms ease-out;

	.sad-alert--open & {
		transform: rotate(180deg);
	}
}

.sad-alert__drawer,
.sad-alert__panel {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 28px;
	padding: 10px 20px 14px 44px;
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.sad-alert__drawer-path {
	flex-basis: 100%;
	overflow-wrap: anywhere;
}

.sad-alert__uid {
	margin-left: 4px;
	opacity: 0.75;
}

.sad-alert__token {
	font-family: ui-monospace, monospace;
	font-size: 12px;
}

.sad-alert__drawer-ack {
	flex-basis: 100%;
}

.sad-alert__panel {
	gap: 8px;
	padding: 8px 14px 8px 44px;
	border-top: 1px solid var(--color-border);
	color: var(--color-main-text);
}

.sad-alert__panel-text {
	font-weight: 600;
}

.sad-alert__ack-note {
	width: 280px;
	max-width: 100%;
	padding: 6px 10px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius, 6px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
}

// Narrow list (not narrow window — the admin sidebar eats width too): below
// this the reason chips would be ellipsised to a letter or two, so let the
// row wrap onto a second line instead, and drop the inline path, which the
// details drawer repeats in full.
@container sad-alerts (max-width: 860px) {
	.sad-alert__row {
		flex-wrap: wrap;
		row-gap: 2px;
		padding-block: 4px;
	}

	.sad-alert__path {
		display: none;
	}
}

@media (prefers-reduced-motion: reduce) {
	.sad-alert__chevron {
		transition: none;
	}
}
</style>
