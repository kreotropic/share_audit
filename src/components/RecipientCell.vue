<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<span class="sad-recipient">
		<span class="sad-recipient__name" :title="title">{{ info.label }}</span>
		<span class="sad-recipient__meta">{{ meta }}</span>
		<span v-if="flag" class="sad-recipient__flag" :title="flag.hint">{{ flag.text }}</span>
	</span>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

/**
 * Who a share made into a Talk conversation or a Deck card is for. The database
 * only holds an opaque key for those (a conversation's token, a card's number),
 * so the backend looks up what it stands for (RecipientDetailsResolver) and this
 * shows it: the name first, then what an auditor needs to weigh who can read the
 * file — how many people are in it, and whether it is open to anyone.
 */
export default {
	name: 'RecipientCell',
	props: {
		/** A share row that carries `recipientInfo`. */
		share: {
			type: Object,
			required: true,
		},
	},
	computed: {
		info() {
			return this.share.recipientInfo
		},
		title() {
			return this.info.kind === 'deck'
				? `${this.info.card} — ${this.info.board}`
				: this.info.label
		},
		// The second line: what it is and how far it reaches, then the key it is
		// stored under, so a row can still be matched to Talk's own link.
		meta() {
			const info = this.info
			const parts = []
			if (info.kind === 'deck') {
				parts.push(info.board)
				if (info.people !== null && info.people !== undefined) {
					parts.push(n('share_audit_dashboard', '%n person', '%n people', info.people))
				}
				return parts.join(' · ')
			}

			if (info.roomType === 'one_to_one') {
				parts.push(t('share_audit_dashboard', 'Private conversation'))
			} else {
				if (info.participants !== null && info.participants !== undefined) {
					parts.push(n('share_audit_dashboard', '%n participant', '%n participants', info.participants))
				}
				if (info.groups > 0) {
					parts.push(n('share_audit_dashboard', '%n group', '%n groups', info.groups))
				}
			}
			// The backend redacts `recipient` down to the same resolved name
			// as info.label for a viewer not allowed to see bare tokens (see
			// ShareCollectorService::redactRoomTokens()) — only append it
			// when it actually adds something (the real token, for an admin).
			if (this.share.recipient && this.share.recipient !== this.info.label) {
				parts.push(this.share.recipient)
			}
			return parts.join(' · ')
		},
		// A conversation that strangers can walk into is the one thing here that
		// changes who has the file, so it is marked rather than left to the count.
		flag() {
			if (this.info.openTo === 'link') {
				return {
					text: t('share_audit_dashboard', 'Public conversation'),
					hint: t('share_audit_dashboard', 'Anyone with the link can join'),
				}
			}
			if (this.info.openTo === 'users') {
				return {
					text: t('share_audit_dashboard', 'Open conversation'),
					hint: t('share_audit_dashboard', 'Any user of this instance can find and join it'),
				}
			}
			return null
		},
	},
}
</script>

<style scoped lang="scss">
// One line each, with the ellipsis on the line itself: the table's cells do not
// wrap, and a long conversation or card name must not widen the column.
.sad-recipient {
	display: block;
}

.sad-recipient__name,
.sad-recipient__meta {
	display: block;
	max-width: 260px;
	overflow: hidden;
	text-overflow: ellipsis;
}

.sad-recipient__meta {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.sad-recipient__flag {
	display: block;
	width: fit-content;
	margin-top: 2px;
	padding: 1px 6px;
	border-radius: var(--border-radius, 6px);
	background-color: var(--sad-warning);
	color: var(--sad-warning-on);
	font-size: 11px;
	font-weight: 600;
}
</style>
