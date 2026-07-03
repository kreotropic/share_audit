<template>
	<div class="sad-table-wrapper">
		<table class="sad-table">
			<thead>
				<tr>
					<th>{{ t('share_audit_dashboard', 'Type') }}</th>
					<th>{{ t('share_audit_dashboard', 'Path') }}</th>
					<th>{{ t('share_audit_dashboard', 'Owner') }}</th>
					<th>{{ t('share_audit_dashboard', 'Recipient') }}</th>
					<th>{{ t('share_audit_dashboard', 'Permissions') }}</th>
					<th>{{ t('share_audit_dashboard', 'Created') }}</th>
					<th>{{ t('share_audit_dashboard', 'Expires') }}</th>
					<th>{{ t('share_audit_dashboard', 'Password') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="share in shares" :key="share.id">
					<td>
						<NcChip :text="categoryLabel(share.category)" :no-close="true" />
					</td>
					<td class="sad-table__path" :title="share.path">
						{{ share.path || '—' }}
					</td>
					<td>{{ share.owner }}</td>
					<td>{{ recipientOf(share) }}</td>
					<td class="sad-table__perms">
						{{ share.permissionLabels.map(permissionLabel).join(', ') || '—' }}
					</td>
					<td>{{ formatDate(share.created) }}</td>
					<td>{{ share.expiration || '—' }}</td>
					<td>
						<span :class="share.hasPassword ? 'sad-yes' : 'sad-no'">
							{{ share.hasPassword ? t('share_audit_dashboard', 'Yes') : t('share_audit_dashboard', 'No') }}
						</span>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcChip from '@nextcloud/vue/components/NcChip'
import { categoryLabel, permissionLabel, formatDate } from '../utils/format.js'

export default {
	name: 'ShareTable',
	components: {
		NcChip,
	},
	props: {
		shares: {
			type: Array,
			required: true,
		},
	},
	methods: {
		t,
		categoryLabel,
		permissionLabel,
		formatDate,
		recipientOf(share) {
			if (share.recipient) {
				return share.recipient
			}
			return share.category === 'link' ? t('share_audit_dashboard', '(public)') : '—'
		},
	},
}
</script>

<style scoped lang="scss">
.sad-table-wrapper {
	overflow-x: auto;
}

.sad-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;

	th,
	td {
		text-align: left;
		padding: 8px 10px;
		border-bottom: 1px solid var(--color-border);
		white-space: nowrap;
	}

	th {
		color: var(--color-text-maxcontrast);
		font-weight: 600;
	}

	tbody tr:hover {
		background-color: var(--color-background-hover);
	}
}

.sad-table__path {
	max-width: 260px;
	overflow: hidden;
	text-overflow: ellipsis;
}

.sad-table__perms {
	white-space: normal;
	min-width: 140px;
}

.sad-no {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}

.sad-yes {
	color: var(--color-success-text, var(--color-success));
}
</style>
