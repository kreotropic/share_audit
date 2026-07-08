<template>
	<div class="sad-table-wrapper">
		<table class="sad-table">
			<caption class="hidden-visually">
				{{ t('share_audit_dashboard', 'All shares, sortable and filterable by column.') }}
			</caption>
			<thead>
				<tr>
					<th v-for="col in columns"
						:key="col.key"
						scope="col"
						:aria-sort="ariaSort(col)"
						:class="{ 'sad-th--active': sortKey === col.key }">
						<span class="sad-th__inner">
							<button v-if="col.sortable"
								type="button"
								class="sad-th__label sad-th__label--sortable"
								@click="$emit('sort', col.key)">
								{{ col.label }}
								<span v-if="sortKey === col.key" class="sad-th__arrow" aria-hidden="true">{{ arrow(col.key) }}</span>
							</button>
							<span v-else class="sad-th__label">{{ col.label }}</span>

							<NcActions v-if="col.filter"
								class="sad-th__filter"
								:class="{ 'sad-th__filter--on': isActive(col) }"
								force-menu
								:aria-label="t('share_audit_dashboard', 'Filter')">
								<template #icon>
									<span class="sad-funnel" v-html="funnel" />
								</template>

								<!-- Type: multi-select (buttons act as toggles) -->
								<template v-if="col.filter === 'types'">
									<NcActionButton v-for="opt in typeOptions"
										:key="opt.id"
										:model-value="f.types.includes(opt.id)"
										@click="toggleType(opt.id)">
										{{ opt.label }}
									</NcActionButton>
								</template>

								<!-- Text search -->
								<NcActionInput v-else-if="col.filter === 'search'"
									:model-value="f[col.field]"
									:label="col.label"
									@update:model-value="onSearch(col.field, $event)"
									@submit="emitFilter">
									{{ col.label }}
								</NcActionInput>

								<!-- Tri-state (password / expiration) -->
								<template v-else>
									<NcActionButton v-for="opt in col.opts"
										:key="opt.id"
										:model-value="f[col.field] === opt.id"
										@click="setTristate(col.field, opt.id)">
										{{ opt.label }}
									</NcActionButton>
								</template>
							</NcActions>
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
					<td>
						<span v-if="share.expiration">{{ share.expiration }}</span>
						<span v-else-if="share.category === 'link'" class="sad-expire-warn">
							{{ t('share_audit_dashboard', 'None') }}
						</span>
						<span v-else>—</span>
					</td>
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
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionInput from '@nextcloud/vue/components/NcActionInput'
import NcChip from '@nextcloud/vue/components/NcChip'
import { categoryLabel, permissionLabel, formatDate, typeFilterOptions } from '../utils/format.js'

export default {
	name: 'ShareTable',
	components: {
		NcActions,
		NcActionButton,
		NcActionInput,
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
		presetTypes: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['sort', 'filter'],
	data() {
		return {
			// Material Design Icons "filter-variant" (three decreasing lines).
			// eslint-disable-next-line max-len
			funnel: '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M6,13H18V11H6M3,6V8H21V6M10,18H14V16H10V18Z"/></svg>',
			typeOptions: typeFilterOptions(),
			f: {
				types: [],
				pathSearch: '',
				ownerSearch: '',
				recipientSearch: '',
				hasPassword: '',
				hasExpiration: '',
			},
			searchTimer: null,
		}
	},
	computed: {
		columns() {
			const pw = [
				{ id: '', label: t('share_audit_dashboard', 'Any password') },
				{ id: 'true', label: t('share_audit_dashboard', 'With password') },
				{ id: 'false', label: t('share_audit_dashboard', 'Without password') },
			]
			const exp = [
				{ id: '', label: t('share_audit_dashboard', 'Any expiration') },
				{ id: 'true', label: t('share_audit_dashboard', 'With expiration') },
				{ id: 'false', label: t('share_audit_dashboard', 'Without expiration') },
			]
			return [
				{ key: 'type', label: t('share_audit_dashboard', 'Type'), sortable: true, filter: 'types' },
				{ key: 'path', label: t('share_audit_dashboard', 'Path'), sortable: true, filter: 'search', field: 'pathSearch' },
				{ key: 'owner', label: t('share_audit_dashboard', 'Owner'), sortable: true, filter: 'search', field: 'ownerSearch' },
				{ key: 'recipient', label: t('share_audit_dashboard', 'Recipient'), sortable: true, filter: 'search', field: 'recipientSearch' },
				{ key: 'permissions', label: t('share_audit_dashboard', 'Permissions'), sortable: false },
				{ key: 'created', label: t('share_audit_dashboard', 'Created'), sortable: true },
				{ key: 'expires', label: t('share_audit_dashboard', 'Expires'), sortable: true, filter: 'tristate', field: 'hasExpiration', opts: exp },
				{ key: 'password', label: t('share_audit_dashboard', 'Password'), sortable: true, filter: 'tristate', field: 'hasPassword', opts: pw },
			]
		},
	},
	created() {
		if (this.presetTypes.length) {
			this.f.types = this.typeOptions
				.filter((opt) => opt.types.some((type) => this.presetTypes.includes(type)))
				.map((opt) => opt.id)
		}
	},
	methods: {
		t,
		categoryLabel,
		permissionLabel,
		formatDate,
		arrow(key) {
			if (this.sortKey !== key) {
				return ''
			}
			return this.sortDir === 'asc' ? '▲' : '▼'
		},
		ariaSort(col) {
			if (!col.sortable) {
				return null
			}
			if (this.sortKey !== col.key) {
				return 'none'
			}
			return this.sortDir === 'asc' ? 'ascending' : 'descending'
		},
		recipientOf(share) {
			if (share.recipient) {
				return share.recipient
			}
			return share.category === 'link' ? t('share_audit_dashboard', '(public)') : '—'
		},
		isActive(col) {
			if (col.filter === 'types') {
				return this.f.types.length > 0
			}
			return this.f[col.field] !== ''
		},
		toggleType(id) {
			if (this.f.types.includes(id)) {
				this.f.types = this.f.types.filter((x) => x !== id)
			} else {
				this.f.types.push(id)
			}
			this.emitFilter()
		},
		onSearch(field, value) {
			this.f[field] = value
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(this.emitFilter, 400)
		},
		setTristate(field, value) {
			this.f[field] = value
			this.emitFilter()
		},
		emitFilter() {
			const typeMap = Object.fromEntries(this.typeOptions.map((o) => [o.id, o.types]))
			const types = this.f.types.flatMap((id) => typeMap[id] ?? [])

			const out = {}
			if (types.length) {
				out.types = types.join(',')
			}
			for (const key of ['pathSearch', 'ownerSearch', 'recipientSearch', 'hasPassword', 'hasExpiration']) {
				if (this.f[key] !== '' && this.f[key] != null) {
					out[key] = this.f[key]
				}
			}
			this.$emit('filter', out)
		},
	},
}
</script>

<style scoped lang="scss">
.sad-table-wrapper {
	overflow-x: auto;
	// Reserve the scrollbar gutter so the bar toggling as the table width
	// changes (opening filters, filtering rows) no longer nudges the layout.
	scrollbar-gutter: stable;
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

.sad-th__inner {
	display: inline-flex;
	align-items: center;
	gap: 2px;
}

.sad-th__label {
	display: inline-flex;
	align-items: center;
}

.sad-th__label--sortable {
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	font: inherit;
	color: inherit;
	cursor: pointer;
	user-select: none;

	&:hover {
		color: var(--color-main-text);
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
		border-radius: var(--border-radius, 4px);
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

.sad-funnel {
	display: inline-flex;
	color: var(--color-text-maxcontrast);
}

// Shrink the NcActions trigger so headers stay compact.
.sad-th__filter :deep(.action-item__menutoggle),
.sad-th__filter :deep(button) {
	min-width: 28px !important;
	width: 28px;
	height: 28px !important;
	min-height: 28px !important;
}

.sad-th__filter--on .sad-funnel {
	color: var(--color-primary-element);
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

.sad-expire-warn {
	color: var(--sad-warning);
	font-weight: 600;
}

.sad-no {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}

.sad-yes {
	color: var(--color-success-text, var(--color-success));
}
</style>
