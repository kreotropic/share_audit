/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What "move the files too" sends to the server, and what it tells the admin.
 *
 * Kept free of Vue and Nextcloud imports (hence the .mjs) so it can be tested
 * with plain `node --test` — see tests/js. OrphanShares.vue is the only caller.
 */

/**
 * The accounts an "everything the account owns" move would take, one entry per
 * account, in selection order. Only a *disabled* account has files to move.
 *
 * This one list is what the confirmation names AND what the request is built
 * from, so what the admin agrees to is exactly what is asked for: never build
 * either from anything else.
 *
 * @param {Array<{id: number, owner: string, ownerDisplayName?: string, ownerStatus: string}>} items the listed orphan shares
 * @param {number[]} selectedIds ids of the selected shares
 * @return {Array<{uid: string, name: string, shareId: number}>} shareId is one selected
 *   share of the account, enough for the server to find it by
 */
export function accountsToMove(items, selectedIds) {
	const seen = new Set()
	const accounts = []
	for (const id of selectedIds) {
		const share = items.find((item) => item.id === id)
		if (!share || share.ownerStatus !== 'disabled' || seen.has(share.owner)) {
			continue
		}
		seen.add(share.owner)
		accounts.push({ uid: share.owner, name: share.ownerDisplayName || share.owner, shareId: id })
	}
	return accounts
}

/**
 * The selected shares an account move leaves out because their owner has no
 * files to move (a deleted account) or is not an orphan owner at all. They are
 * not sent; the notice reports them so that they are not silently dropped.
 *
 * @param {Array<{id: number, ownerStatus: string}>} items
 * @param {number[]} selectedIds
 * @return {Array<{id: number, reason: string}>}
 */
export function leftOutOfAccountMove(items, selectedIds) {
	const left = []
	for (const id of selectedIds) {
		const share = items.find((item) => item.id === id)
		if (share && share.ownerStatus !== 'disabled') {
			left.push({ id, reason: share.ownerStatus === 'deleted' ? 'owner_deleted' : 'not_orphan' })
		}
	}
	return left
}

/**
 * The shares to offer the file move after the plain transfer: everything it
 * could not do, whatever the reason it gave. The reasons describe the *new
 * owner's* reach (`no_access`) or the kind of share (`unsupported_type`: Talk,
 * mail, federated — which the plain transfer refuses before it even looks at
 * the file, though moving the file takes them along), and neither says whether
 * the file can move. The server decides that.
 *
 * @param {Array<{id: number, reason: string}>} skipped what the plain transfer skipped
 * @return {number[]}
 */
export function idsForFileMove(skipped) {
	return [...new Set(skipped.map(({ id }) => id))]
}

/**
 * What is still not done once the file move has answered.
 *
 * A share the move did not skip was queued with its file and is done as far as
 * this request goes, so it drops out. One the move skipped keeps a reason: the
 * move's own, except `not_in_home` — "not in this account's home" says less than
 * why the plain transfer refused it (no access, no permission, ...), which is
 * what the admin can act on for a file in a Team Folder.
 *
 * @param {Array<{id: number, reason: string}>} plainSkipped what the plain transfer skipped
 * @param {Array<{id: number, reason: string}>} moveSkipped what the file move skipped
 * @return {Array<{id: number, reason: string}>}
 */
export function skippedAfterFileMove(plainSkipped, moveSkipped) {
	const moveReasons = new Map(moveSkipped.map(({ id, reason }) => [id, reason]))
	return plainSkipped.flatMap(({ id, reason }) => {
		if (!moveReasons.has(id)) {
			return []
		}
		const moveReason = moveReasons.get(id)
		return [{ id, reason: moveReason === 'not_in_home' ? reason : moveReason }]
	})
}
