<template>
	<div class="sad-table-wrapper">
		<table class="sad-table">
			<thead>
				<tr>
					<th v-for="col in columns"
						:key="col.key"
						:class="{ 'sad-th--sortable': col.sortable, 'sad-th--active': sortKey === col.key }"
						@click="col.sortable && $emit('sort', col.key)">
						{{ col.label }}
						<span v-if="col.sortable" class="sad-th__arrow">
							{{ sortKey === col.key ? (sortDir === 'asc' ? '▲' : '▼') : '⇅' }}
						</span>
					</th>
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
		sortKey: {
			type: String,
			default: 'created',
		},
		sortDir: {
			type: String,
			default: 'desc',
		},
	},
	emits: ['sort'],
	computed: {
		columns() {
			return [
				{ key: 'type', label: t('share_audit_dashboard', 'Type'), sortable: true },
				{ key: 'path', label: t('share_audit_dashboard', 'Path'), sortable: true },
				{ key: 'owner', label: t('share_audit_dashboard', 'Owner'), sortable: true },
				{ key: 'recipient', label: t('share_audit_dashboard', 'Recipient'), sortable: true },
				{ key: 'permissions', label: t('share_audit_dashboard', 'Permissions'), sortable: false },
				{ key: 'created', label: t('share_audit_dashboard', 'Created'), sortable: true },
				{ key: 'expires', label: t('share_audit_dashboard', 'Expires'), sortable: true },
				{ key: 'password', label: t('share_audit_dashboard', 'Password'), sortable: true },
			]
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

.sad-th--sortable {
	cursor: pointer;
	user-select: none;

	&:hover {
		color: var(--color-main-text);
	}
}

.sad-th--active {
	color: var(--color-main-text);
}

.sad-th__arrow {
	font-size: 10px;
	opacity: 0.6;
	margin-left: 2px;
}

.sad-th--active .sad-th__arrow {
	opacity: 1;
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
