<template>
	<div>
		<p class="settings-hint">
			{{ t('share_audit_dashboard', 'Shares whose owner is a disabled or deleted account. These keep granting access after the person is gone.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="32" class="sad-loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent v-else-if="items.length === 0"
			:name="t('share_audit_dashboard', 'No orphan shares')"
			:description="t('share_audit_dashboard', 'Every share is owned by an active account.')">
			<template #icon>
				<span class="icon-checkmark" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<NcNoteCard v-if="notice" :type="notice.type" class="sad-orphan-notice">
				{{ notice.message }}
			</NcNoteCard>

			<div class="sad-orphan-bar">
				<NcCheckboxRadioSwitch :model-value="allSelected" @update:model-value="toggleAll">
					{{ t('share_audit_dashboard', 'Select all') }}
				</NcCheckboxRadioSwitch>
				<span v-if="selectedIds.length" class="sad-orphan-bar__count">
					{{ n('share_audit_dashboard', '%n selected', '%n selected', selectedIds.length) }}
				</span>
				<div class="sad-orphan-bar__spacer" />
				<template v-if="selectedIds.length">
					<template v-if="!confirming">
						<NcButton type="error" :disabled="revoking" @click="confirming = true">
							{{ t('share_audit_dashboard', 'Revoke selected') }}
						</NcButton>
					</template>
					<template v-else>
						<span class="sad-orphan-bar__confirm">
							{{ n('share_audit_dashboard', 'Revoke %n share?', 'Revoke %n shares?', selectedIds.length) }}
						</span>
						<NcButton type="error" :disabled="revoking" @click="revokeSelected">
							{{ t('share_audit_dashboard', 'Confirm') }}
						</NcButton>
						<NcButton type="tertiary" :disabled="revoking" @click="confirming = false">
							{{ t('share_audit_dashboard', 'Cancel') }}
						</NcButton>
					</template>
				</template>
			</div>

			<div class="sad-table-wrapper">
				<table class="sad-table">
					<thead>
						<tr>
							<th class="sad-table__check" />
							<th>{{ t('share_audit_dashboard', 'Owner') }}</th>
							<th>{{ t('share_audit_dashboard', 'Path') }}</th>
							<th>{{ t('share_audit_dashboard', 'Recipient') }}</th>
							<th>{{ t('share_audit_dashboard', 'Type') }}</th>
							<th>{{ t('share_audit_dashboard', 'Permissions') }}</th>
							<th>{{ t('share_audit_dashboard', 'Created') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="share in items" :key="share.id">
							<td class="sad-table__check">
								<NcCheckboxRadioSwitch :model-value="selectedIds.includes(share.id)"
									@update:model-value="toggleSelect(share.id, $event)" />
							</td>
							<td>
								<span class="sad-owner">{{ share.owner }}</span>
								<span class="sad-owner__status" :class="'sad-owner__status--' + share.ownerStatus">
									{{ statusLabel(share.ownerStatus) }}
								</span>
							</td>
							<td class="sad-table__path" :title="share.path">{{ share.path || '—' }}</td>
							<td>{{ recipientOf(share) }}</td>
							<td><NcChip :text="categoryLabel(share.category)" :no-close="true" /></td>
							<td class="sad-table__perms">
								{{ share.permissionLabels.map(permissionLabel).join(', ') || '—' }}
							</td>
							<td>{{ formatDate(share.created) }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="sad-pagination">
				<span class="sad-pagination__info">{{ rangeLabel }}</span>
				<div class="sad-pagination__controls">
					<NcButton :disabled="page <= 1" @click="goto(page - 1)">
						{{ t('share_audit_dashboard', 'Previous') }}
					</NcButton>
					<span class="sad-pagination__page">{{ page }} / {{ totalPages }}</span>
					<NcButton :disabled="page >= totalPages" @click="goto(page + 1)">
						{{ t('share_audit_dashboard', 'Next') }}
					</NcButton>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { categoryLabel, permissionLabel, formatDate } from '../utils/format.js'
import { fetchOrphans, revokeOrphans } from '../services/api.js'

export default {
	name: 'OrphanShares',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcChip,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},
	emits: ['orphan-count'],
	data() {
		return {
			loading: true,
			error: null,
			revoking: false,
			confirming: false,
			items: [],
			total: 0,
			page: 1,
			limit: 50,
			selectedIds: [],
			notice: null,
		}
	},
	computed: {
		totalPages() {
			return Math.max(1, Math.ceil(this.total / this.limit))
		},
		allSelected() {
			return this.items.length > 0 && this.selectedIds.length === this.items.length
		},
		rangeLabel() {
			if (this.total === 0) {
				return ''
			}
			const from = (this.page - 1) * this.limit + 1
			const to = Math.min(this.total, this.page * this.limit)
			return t('share_audit_dashboard', '{from}–{to} of {total}', { from, to, total: this.total })
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		n,
		categoryLabel,
		permissionLabel,
		formatDate,
		statusLabel(status) {
			const labels = {
				disabled: t('share_audit_dashboard', 'disabled'),
				deleted: t('share_audit_dashboard', 'deleted'),
			}
			return labels[status] ?? status
		},
		recipientOf(share) {
			if (share.recipient) {
				return share.recipient
			}
			return share.category === 'link' ? t('share_audit_dashboard', '(public)') : '—'
		},
		toggleAll(checked) {
			this.selectedIds = checked ? this.items.map((s) => s.id) : []
		},
		toggleSelect(id, checked) {
			if (checked) {
				if (!this.selectedIds.includes(id)) {
					this.selectedIds.push(id)
				}
			} else {
				this.selectedIds = this.selectedIds.filter((x) => x !== id)
			}
		},
		goto(page) {
			this.page = page
			this.selectedIds = []
			this.load()
		},
		async load() {
			this.loading = true
			this.error = null
			try {
				const data = await fetchOrphans({ page: this.page, limit: this.limit })
				this.items = data.items
				this.total = data.total
				this.selectedIds = this.selectedIds.filter((id) => this.items.some((s) => s.id === id))
				this.$emit('orphan-count', this.total)
			} catch (e) {
				this.error = t('share_audit_dashboard', 'Could not load orphan shares.')
			} finally {
				this.loading = false
			}
		},
		async revokeSelected() {
			this.revoking = true
			this.notice = null
			try {
				const res = await revokeOrphans(this.selectedIds)
				this.notice = {
					type: 'success',
					message: n('share_audit_dashboard', 'Revoked %n share.', 'Revoked %n shares.', res.deleted),
				}
				this.confirming = false
				this.selectedIds = []
				if (this.page > 1 && this.items.length === res.deleted) {
					this.page -= 1
				}
				await this.load()
			} catch (e) {
				this.notice = { type: 'error', message: t('share_audit_dashboard', 'Could not revoke the selected shares.') }
			} finally {
				this.revoking = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.sad-orphan-bar {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 14px;
	margin-bottom: 12px;
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover);
}

.sad-orphan-bar__spacer {
	flex: 1;
}

.sad-orphan-bar__count,
.sad-orphan-bar__confirm {
	font-weight: 600;
}

.sad-orphan-notice {
	margin-bottom: 12px;
}

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

	tbody tr:nth-child(even) {
		background-color: var(--color-background-hover);
	}

	tbody tr:hover {
		background-color: var(--color-background-dark);
	}
}

.sad-table__check {
	width: 44px;
}

.sad-table__path {
	max-width: 300px;
	overflow: hidden;
	text-overflow: ellipsis;
}

.sad-table__perms {
	white-space: normal;
	min-width: 140px;
}

.sad-owner__status {
	font-size: 11px;
	font-weight: 600;
	padding: 1px 6px;
	margin-left: 6px;
	border-radius: var(--border-radius, 6px);
	color: var(--sad-ink-on-solid);
}

.sad-owner__status--disabled {
	background-color: var(--sad-warning);
	color: var(--sad-warning-on);
}

.sad-owner__status--deleted {
	background-color: var(--sad-critical);
}

.sad-pagination {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-top: 16px;
}

.sad-pagination__controls {
	display: flex;
	align-items: center;
	gap: 12px;
}

.sad-pagination__info,
.sad-pagination__page {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
