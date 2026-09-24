<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div>
		<div class="sad-section-head">
			<h3 class="sad-section-title">{{ t('share_audit_dashboard', 'Orphan shares') }}</h3>
			<span class="sad-section-sep" aria-hidden="true">·</span>
			<p class="sad-section-sub">
				{{ t('share_audit_dashboard', 'Shares whose owner is a disabled or deleted account. These keep granting access after the person is gone.') }}
			</p>
		</div>

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
				<template v-if="notice.details">
					<span class="sad-orphan-notice__title">{{ notice.detailsTitle }}</span>
					<ul class="sad-orphan-notice__list">
						<li v-for="line in notice.details" :key="line">
							{{ line }}
						</li>
					</ul>
				</template>
			</NcNoteCard>

			<div class="sad-orphan-bar">
				<NcCheckboxRadioSwitch v-if="canManage" :model-value="allSelected" @update:model-value="toggleAll">
					{{ t('share_audit_dashboard', 'Select all') }}
				</NcCheckboxRadioSwitch>
				<span v-if="selectedIds.length" class="sad-orphan-bar__count">
					{{ n('share_audit_dashboard', '%n selected', '%n selected', selectedIds.length) }}
				</span>
				<div class="sad-orphan-bar__spacer" />
				<template v-if="selectedIds.length">
					<template v-if="confirming">
						<span class="sad-orphan-bar__confirm">
							{{ n('share_audit_dashboard', 'Revoke %n share?', 'Revoke %n shares?', selectedIds.length) }}
						</span>
						<NcButton variant="error" :disabled="busy" @click="revokeSelected">
							{{ t('share_audit_dashboard', 'Confirm') }}
						</NcButton>
						<NcButton variant="tertiary" :disabled="busy" @click="confirming = false">
							{{ t('share_audit_dashboard', 'Cancel') }}
						</NcButton>
					</template>
					<template v-else-if="pickingOwner && confirmingAccount">
						<div class="sad-orphan-bar__pick">
							<span class="sad-orphan-bar__confirm">
								{{ t('share_audit_dashboard', 'Move all files and shares of {accounts} to {name}?', { accounts: movableOwnerNames, name: newOwner ? newOwner.displayName || newOwner.uid : '' }) }}
							</span>
							<span class="sad-orphan-bar__hint">
								{{ t('share_audit_dashboard', 'This includes shares you have not selected and files no share points to. It runs in the background and cannot be undone from here.') }}
							</span>
							<NcButton variant="primary" :disabled="busy" @click="transferSelected">
								{{ t('share_audit_dashboard', 'Confirm') }}
							</NcButton>
							<NcButton variant="tertiary" :disabled="busy" @click="confirmingAccount = false">
								{{ t('share_audit_dashboard', 'Back') }}
							</NcButton>
						</div>
					</template>
					<template v-else-if="pickingOwner">
						<div class="sad-orphan-bar__pick">
							<span class="sad-orphan-bar__confirm">
								{{ n('share_audit_dashboard', 'Transfer %n share to', 'Transfer %n shares to', transferableSelectedIds.length) }}
							</span>
							<span v-if="nonTransferableSelectedCount > 0" class="sad-orphan-bar__hint">
								{{ n(
									'share_audit_dashboard',
									'%n selected share cannot be transferred (its file no longer exists) and will be skipped.',
									'%n selected shares cannot be transferred (their file no longer exists) and will be skipped.',
									nonTransferableSelectedCount,
								) }}
							</span>
							<NcSelect v-model="newOwner"
								class="sad-orphan-bar__select"
								:options="ownerOptions"
								:loading="ownersLoading"
								:filterable="false"
								:clearable="false"
								:disabled="busy"
								:placeholder="t('share_audit_dashboard', 'Search for a user…')"
								:aria-label-combobox="t('share_audit_dashboard', 'New owner')"
								@search="searchOwners" />
							<NcButton variant="primary" :disabled="!newOwner || busy" @click="startTransfer">
								{{ transferLabel }}
							</NcButton>
							<NcButton variant="tertiary" :disabled="busy" @click="cancelTransfer">
								{{ t('share_audit_dashboard', 'Cancel') }}
							</NcButton>

							<fieldset class="sad-orphan-scope" :disabled="busy">
								<legend class="sad-orphan-scope__legend">
									{{ t('share_audit_dashboard', 'What to move') }}
								</legend>
								<NcCheckboxRadioSwitch v-model="moveScope"
									type="radio"
									name="sad-orphan-move-scope"
									value="shares">
									{{ t('share_audit_dashboard', 'Only the shares') }}
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch v-model="moveScope"
									type="radio"
									name="sad-orphan-move-scope"
									value="files"
									:disabled="!canMoveFiles">
									{{ t('share_audit_dashboard', 'The shares and the files they point to') }}
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch v-model="moveScope"
									type="radio"
									name="sad-orphan-move-scope"
									value="account"
									:disabled="!canMoveAccount">
									{{ t('share_audit_dashboard', 'Everything the account owns') }}
								</NcCheckboxRadioSwitch>
							</fieldset>
							<span v-if="scopeHint" class="sad-orphan-bar__hint sad-orphan-scope__hint">
								{{ scopeHint }}
							</span>
						</div>
					</template>
					<template v-else>
						<NcButton variant="secondary" :disabled="busy" @click="openTransfer">
							{{ t('share_audit_dashboard', 'Transfer selected') }}
						</NcButton>
						<NcButton variant="error" :disabled="busy" @click="confirming = true">
							{{ t('share_audit_dashboard', 'Revoke selected') }}
						</NcButton>
					</template>
				</template>

				<PageSizeSelect v-if="!pickingOwner"
					v-model="pageSize"
					:options="pageSizeOptions"
					:width="120"
					:disabled="busy" />
			</div>

			<div class="sad-table-wrapper">
				<table class="sad-table">
					<caption class="hidden-visually">
						{{ t('share_audit_dashboard', 'Shares owned by a disabled or deleted account, selectable for bulk revoke.') }}
					</caption>
					<thead>
						<tr>
							<th v-if="canManage" class="sad-table__check" />
							<th scope="col">{{ t('share_audit_dashboard', 'Owner') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Path') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Recipient') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Type') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Permissions') }}</th>
							<th scope="col">{{ t('share_audit_dashboard', 'Created') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="share in items" :key="share.id">
							<td v-if="canManage" class="sad-table__check">
								<NcCheckboxRadioSwitch :model-value="selectedIds.includes(share.id)"
									@update:model-value="toggleSelect(share.id, $event)" />
							</td>
							<td>
								<span class="sad-owner">{{ share.ownerDisplayName || share.owner }}</span>
								<span class="sad-owner__status" :class="'sad-owner__status--' + share.ownerStatus">
									{{ statusLabel(share.ownerStatus) }}
								</span>
								<span v-if="share.ownerDisplayName && share.ownerDisplayName !== share.owner"
									class="sad-owner__uid">{{ share.owner }}</span>
							</td>
							<td class="sad-table__path">
								<span v-if="share.sourceExists === false"
									class="sad-source-missing"
									:title="t('share_audit_dashboard', 'The shared file or folder no longer exists.')">
									{{ t('share_audit_dashboard', 'File no longer exists') }}
								</span>
								<span v-else :title="share.path">{{ share.path || '—' }}</span>
							</td>
							<td>
								<RecipientCell v-if="share.recipientInfo" :share="share" />
								<template v-else>
									{{ recipientOf(share) }}
								</template>
							</td>
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
				<PageNavigation v-if="!isAll && total > apiLimit"
					:page="page"
					:total-pages="totalPages"
					:disabled="busy"
					@change="goto" />
			</div>
		</template>

		<!-- Always mounted (never inside the branches above): it polls, and must
		     survive the orphan list reloading each time a move finishes. -->
		<FileMoves ref="fileMoves" @finished="load" />
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
import NcSelect from '@nextcloud/vue/components/NcSelect'
import FileMoves from '../components/FileMoves.vue'
import PageNavigation from '../components/PageNavigation.vue'
import PageSizeSelect from '../components/PageSizeSelect.vue'
import RecipientCell from '../components/RecipientCell.vue'
import { categoryLabel, permissionLabel, formatDate, emptyRecipientLabel } from '../utils/format.js'
import { fetchOrphans, moveOrphanFiles, revokeOrphans, searchTransferTargets, transferOrphans } from '../services/api.js'
import { accountsToMove, idsForFileMove, leftOutOfAccountMove, skippedAfterFileMove } from '../utils/orphanMoves.mjs'

// Must match OrphanShareController::MAX_IDS — larger selections are split
// into sequential requests instead of one huge revoke call.
const BULK_CHUNK_SIZE = 500

// "Display name (uid)", or just the uid when the account has no other name.
const ownerLabel = (user) => (user.displayName && user.displayName !== user.uid
	? `${user.displayName} (${user.uid})`
	: user.uid)

export default {
	name: 'OrphanShares',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcChip,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		FileMoves,
		PageNavigation,
		PageSizeSelect,
		RecipientCell,
	},
	emits: ['orphan-count'],
	inject: {
		canManage: { default: true },
	},
	data() {
		return {
			loading: true,
			error: null,
			revoking: false,
			confirming: false,
				pickingOwner: false,
				// What a transfer takes along: 'shares' (only the shares), 'files' (the
				// files behind them too) or 'account' (everything the owner has).
				moveScope: 'shares',
				confirmingAccount: false,
				newOwner: null,
				ownerOptions: [],
				ownersLoading: false,
				transferring: false,
				// The picker's debounced search: its timer, and a counter so a slow
				// answer to an older query never replaces a newer one.
				searchTimer: null,
				searchSeq: 0,
			items: [],
			total: 0,
			page: 1,
			pageSizeOptions: [
				{ id: 5, label: '5' },
				{ id: 15, label: '15' },
				{ id: 25, label: '25' },
				{ id: 50, label: '50' },
				{ id: 'all', label: t('share_audit_dashboard', 'All') },
			],
			pageSize: { id: 25, label: '25' },
			selectedIds: [],
			notice: null,
		}
	},
	computed: {
		// Either action is in flight: lock the controls that would start another.
		busy() {
			return this.revoking || this.transferring
		},
		isAll() {
			return this.pageSize.id === 'all'
		},
		// Numeric limit sent to the API; 0 means "return every orphan".
		apiLimit() {
			return this.isAll ? 0 : this.pageSize.id
		},
		totalPages() {
			if (this.isAll) {
				return 1
			}
			return Math.max(1, Math.ceil(this.total / this.apiLimit))
		},
		transferableSelectedIds() {
				return this.selectedIds.filter((id) => {
					const share = this.items.find((s) => s.id === id)
					return share ? share.canTransfer !== false : true
				})
			},
			nonTransferableSelectedCount() {
				return this.selectedIds.length - this.transferableSelectedIds.length
			},
			// Files can only be moved out of a disabled account (a deleted one's went
			// with it), and a file move needs the file to still be there. Whether it
			// is in the account's home is for the server to say.
			movableSelected() {
				return this.selectedIds
					.map((id) => this.items.find((s) => s.id === id))
					.filter((share) => share && share.ownerStatus === 'disabled' && share.sourceExists !== false)
			},
			canMoveFiles() {
				return this.movableSelected.length > 0
			},
			// The accounts "everything the account owns" would take. The confirmation
			// names them and the request is built from this same list, so the two can
			// never differ — see utils/orphanMoves.mjs.
			accountMoves() {
				return accountsToMove(this.items, this.selectedIds)
			},
			canMoveAccount() {
				return this.accountMoves.length > 0
			},
			// "Ana Silva, Rui Costa" — who the whole-account confirmation is about.
			movableOwnerNames() {
				return this.accountMoves.map((account) => account.name).join(', ')
			},
			transferLabel() {
				if (this.moveScope === 'files') {
					return t('share_audit_dashboard', 'Transfer and move files')
				}
				if (this.moveScope === 'account') {
					return t('share_audit_dashboard', 'Move files and shares')
				}
				return t('share_audit_dashboard', 'Transfer')
			},
			scopeHint() {
				if (this.moveScope === 'files') {
					return t('share_audit_dashboard', 'Moves the files the selected shares point to, out of the disabled account, and hands them over with every share of those files.')
				}
				if (this.moveScope === 'account') {
					return t('share_audit_dashboard', 'Moves everything the account owns, and all of its shares, to the new owner.')
				}
				if (!this.canMoveAccount) {
					return t('share_audit_dashboard', 'Files can only be moved from a disabled account: the files of a deleted one went with it.')
				}
				return ''
			},
			allSelected() {
			return this.items.length > 0 && this.selectedIds.length === this.items.length
		},
		rangeLabel() {
			if (this.total === 0) {
				return ''
			}
			const from = this.isAll ? 1 : (this.page - 1) * this.apiLimit + 1
			const to = this.isAll ? this.total : Math.min(this.total, this.page * this.apiLimit)
			return t('share_audit_dashboard', '{from}–{to} of {total}', { from, to, total: this.total })
		},
	},
	watch: {
		// Nothing selected, nothing left to confirm or to pick an owner for.
		selectedIds(ids) {
			if (ids.length === 0) {
				this.confirming = false
				this.cancelTransfer()
			}
			// What was agreed to named the accounts of the selection as it was: a
			// different selection has to be confirmed again.
			this.confirmingAccount = false
		},
		// A selection with nothing to move cannot keep a "move the files" choice.
		canMoveFiles(can) {
			if (!can && this.moveScope === 'files') {
				this.moveScope = 'shares'
			}
		},
		canMoveAccount(can) {
			if (!can && this.moveScope === 'account') {
				this.moveScope = 'shares'
				this.confirmingAccount = false
			}
		},
		// A different page size invalidates the current page and selection.
		'pageSize.id'() {
			this.page = 1
			this.selectedIds = []
			this.confirming = false
			this.load()
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
			return emptyRecipientLabel(share.category)
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
			if (page < 1 || page > this.totalPages || page === this.page) {
				return
			}
			this.page = page
			this.selectedIds = []
			this.load()
		},
		openTransfer() {
			this.notice = null
			this.newOwner = null
			this.moveScope = 'shares'
			this.confirmingAccount = false
			this.pickingOwner = true
			this.searchOwners('')
		},
		cancelTransfer() {
			this.pickingOwner = false
			this.newOwner = null
			this.moveScope = 'shares'
			this.confirmingAccount = false
		},
		// Moving a whole account is heavy and takes everything, so it asks first;
		// the other choices go straight through.
		startTransfer() {
			if (this.moveScope === 'account') {
				this.confirmingAccount = true
				return
			}
			this.transferSelected()
		},
		// Candidates come from the server, enabled accounts only, and the search
		// runs as the admin types (after a short pause) rather than loading them all.
		searchOwners(query) {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(async () => {
				const seq = ++this.searchSeq
				this.ownersLoading = true
				try {
					const users = await searchTransferTargets(query)
					if (seq === this.searchSeq) {
						this.ownerOptions = users.map((user) => ({ ...user, label: ownerLabel(user) }))
					}
				} catch (e) {
					if (seq === this.searchSeq) {
						this.ownerOptions = []
					}
				} finally {
					if (seq === this.searchSeq) {
						this.ownersLoading = false
					}
				}
			}, query ? 250 : 0)
		},
		skipReasonLabel(reason) {
			const labels = {
				not_orphan: t('share_audit_dashboard', 'The owner is no longer disabled or deleted'),
				source_missing: t('share_audit_dashboard', 'The shared file or folder no longer exists'),
				unsupported_type: t('share_audit_dashboard', 'This type of share cannot be transferred'),
				recipient_is_new_owner: t('share_audit_dashboard', 'The new owner is who the share is for'),
				no_access: t('share_audit_dashboard', 'The new owner cannot access the file'),
				owner_deleted: t('share_audit_dashboard', 'The account was deleted, so its files are gone'),
				not_in_home: t('share_audit_dashboard', 'The file is not in the account home (a Team Folder or an external storage), so it cannot move with the account'),
				already_queued: t('share_audit_dashboard', 'A move of these files is already queued'),
				not_shareable: t('share_audit_dashboard', 'The new owner may not share the file'),
				insufficient_permissions: t('share_audit_dashboard', 'The share grants more than the new owner may'),
			}
			return labels[reason] ?? reason
		},
		// What happened to a batch: a success, or a warning that names each reason
		// a share stayed put — an admin needs to know which ones and why.
		transferNotice({ transferred, queuedMoves, skipped, failed, scope }, owner) {
			const name = owner.displayName || owner.uid
			const parts = []
			if (transferred > 0) {
				parts.push(n('share_audit_dashboard', 'Transferred %n share to {name}.', 'Transferred %n shares to {name}.', transferred, { name }))
			}
			if (queuedMoves > 0) {
				parts.push(n(
					'share_audit_dashboard',
					'Queued %n file move to {name}. It runs in the background: follow it under File moves.',
					'Queued %n file moves to {name}. They run in the background: follow them under File moves.',
					queuedMoves,
					{ name },
				))
			}
			const message = parts.length > 0 ? parts.join(' ') : t('share_audit_dashboard', 'No share was transferred.')
			const left = skipped.length + failed.length
			if (left === 0) {
				return { type: 'success', message }
			}

			const counts = {}
			for (const { reason } of skipped) {
				counts[reason] = (counts[reason] ?? 0) + 1
			}
			const details = Object.entries(counts)
				.map(([reason, count]) => `${this.skipReasonLabel(reason)} (${count})`)
			if (failed.length > 0) {
				details.push(`${t('share_audit_dashboard', 'Unexpected error, try again')} (${failed.length})`)
			}
			// Pointless advice to somebody who has just chosen to move the files.
			if (counts.no_access && scope === 'shares') {
				details.push(t('share_audit_dashboard', 'To move the files along with the shares, choose "The shares and the files they point to". Or move them first with occ files:transfer-ownership.'))
			}
			return {
				type: transferred === 0 && queuedMoves === 0 && skipped.length === 0 ? 'error' : 'warning',
				message,
				detailsTitle: n('share_audit_dashboard', '%n share was not transferred:', '%n shares were not transferred:', left),
				details,
			}
		},
		// The moves the server queued, in as many requests as the selection needs.
		async queueMoves(ids, newOwner, scope) {
			const total = { queued: [], skipped: [] }
			for (let i = 0; i < ids.length; i += BULK_CHUNK_SIZE) {
				const res = await moveOrphanFiles(ids.slice(i, i + BULK_CHUNK_SIZE), newOwner, scope)
				total.queued.push(...res.queued)
				total.skipped.push(...res.skipped)
			}
			return total
		},
		async transferSelected() {
			if (!this.newOwner) {
				return
			}
			this.transferring = true
			this.notice = null
			try {
				const owner = this.newOwner
				const scope = this.moveScope
				const total = { transferred: 0, queuedMoves: 0, skipped: [], failed: [], scope }

				if (scope === 'account') {
					// Exactly the accounts the confirmation named, one share of each; a
					// share of any other account is not sent at all. The ones left out
					// (a deleted account has no files) are reported, not dropped.
					const ids = this.accountMoves.map((account) => account.shareId)
					total.skipped.push(...leftOutOfAccountMove(this.items, this.selectedIds))
					const moves = await this.queueMoves(ids, owner.uid, 'account')
					total.queuedMoves = moves.queued.length
					total.skipped.push(...moves.skipped)
				} else {
					const ids = this.transferableSelectedIds
					const direct = { transferred: 0, skipped: [], failed: [] }
					for (let i = 0; i < ids.length; i += BULK_CHUNK_SIZE) {
						const chunk = ids.slice(i, i + BULK_CHUNK_SIZE)
						const res = await transferOrphans(chunk, owner.uid)
						direct.transferred += res.transferred
						direct.skipped.push(...res.skipped)
						direct.failed.push(...res.failed)
					}
					total.transferred = direct.transferred
					total.failed = direct.failed
					total.skipped = direct.skipped

					if (scope === 'files') {
						// Whatever the plain transfer could not do goes to the file move,
						// which decides for itself what it can move. Not only the shares
						// refused for lack of access: Talk, mail and federated shares are
						// refused as an unsupported type before their file is even looked at.
						const forward = idsForFileMove(direct.skipped)
						if (forward.length > 0) {
							const moves = await this.queueMoves(forward, owner.uid, 'path')
							total.queuedMoves = moves.queued.length
							total.skipped = skippedAfterFileMove(direct.skipped, moves.skipped)
						}
					}
				}

				this.notice = this.transferNotice(total, owner)
				this.cancelTransfer()
				this.selectedIds = []
				if (this.page > 1 && this.items.length === total.transferred) {
					this.page -= 1
				}
				await this.load()
				if (total.queuedMoves > 0) {
					this.$refs.fileMoves?.refresh()
				}
			} catch (e) {
				this.notice = {
					type: 'error',
					message: e?.response?.status === 400
						? t('share_audit_dashboard', 'That account cannot take over shares. Pick another one.')
						: t('share_audit_dashboard', 'Could not transfer the selected shares.'),
				}
			} finally {
				this.transferring = false
			}
		},
		async load() {
			this.loading = true
			this.error = null
			try {
				const data = await fetchOrphans({ page: this.page, limit: this.apiLimit })
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
				let deleted = 0
				for (let i = 0; i < this.selectedIds.length; i += BULK_CHUNK_SIZE) {
					const chunk = this.selectedIds.slice(i, i + BULK_CHUNK_SIZE)
					const res = await revokeOrphans(chunk)
					deleted += res.deleted
				}
				this.notice = {
					type: 'success',
					message: n('share_audit_dashboard', 'Revoked %n share.', 'Revoked %n shares.', deleted),
				}
				this.confirming = false
				this.selectedIds = []
				if (this.page > 1 && this.items.length === deleted) {
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
// Title and subtitle share a baseline, separated by a middot.
.sad-section-head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 8px;
	margin-bottom: 12px;
}

.sad-section-title {
	margin: 0;
	font-size: 17px;
}

.sad-section-sep,
.sad-section-sub {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.sad-section-sub {
	margin: 0;
	// Nextcloud caps <p> in settings at 900px, which forces a needless wrap.
	max-width: none;
}

.sad-orphan-bar {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
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

.sad-orphan-bar__pick {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
}

.sad-orphan-bar__select {
	min-width: 260px;
}

// The three "what to move" choices sit on a row of their own under the picker.
.sad-orphan-scope {
	flex-basis: 100%;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 16px;
	margin: 0;
	padding: 0;
	border: 0;
}

.sad-orphan-scope__legend {
	// A fieldset needs a legend to be announced as a group; the row has no room for it.
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
}

.sad-orphan-scope__hint {
	flex-basis: 100%;
}

.sad-orphan-notice__title {
	display: block;
	margin-top: 6px;
	font-weight: 600;
}

.sad-orphan-notice__list {
	margin: 4px 0 0;
	padding-left: 20px;
	list-style: disc;
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

.sad-owner__uid {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
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

.sad-source-missing {
	display: inline-block;
	padding: 1px 6px;
	border-radius: var(--border-radius, 6px);
	background-color: var(--sad-critical);
	color: var(--sad-ink-on-solid);
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

.sad-orphan-bar__hint {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.sad-pagination {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-top: 16px;
}

.sad-pagination__info {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
