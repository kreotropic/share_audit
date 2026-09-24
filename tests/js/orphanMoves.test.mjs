/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Run with: npm run test:js  (plain node:test, no dependencies)

import assert from 'node:assert/strict'
import test from 'node:test'
import {
	accountsToMove,
	idsForFileMove,
	leftOutOfAccountMove,
	skippedAfterFileMove,
} from '../../src/utils/orphanMoves.mjs'

const share = (id, owner, ownerStatus = 'disabled', extra = {}) => ({
	id,
	owner,
	ownerDisplayName: owner.toUpperCase(),
	ownerStatus,
	sourceExists: true,
	...extra,
})

// ---------------------------------------------------------------------------
// The confirmation and the request must name the same accounts
// ---------------------------------------------------------------------------

test('a share whose file is gone still puts its (disabled) account in the confirmation and the request alike', () => {
	// The reported case: a valid share of A and one with a missing file of B.
	const items = [share(1, 'a'), share(2, 'b', 'disabled', { sourceExists: false })]

	const accounts = accountsToMove(items, [1, 2])

	assert.deepEqual(accounts.map((a) => a.uid), ['a', 'b'])
	// What is sent is built from the very same list, one share per account.
	const sent = accounts.map((a) => a.shareId)
	assert.deepEqual(sent, [1, 2])
	assert.deepEqual(
		sent.map((id) => items.find((i) => i.id === id).owner),
		accounts.map((a) => a.uid),
		'the accounts the request reaches are exactly the accounts confirmed',
	)
})

test('no selected share can reach an account that is not in the confirmation', () => {
	const items = [
		share(1, 'a'), share(2, 'a'), share(3, 'b'),
		share(4, 'gone', 'deleted'),
		share(5, 'odd', 'unknown'),
	]
	const selected = [1, 2, 3, 4, 5]

	const accounts = accountsToMove(items, selected)
	const sent = accounts.map((a) => a.shareId)

	const reached = new Set(sent.map((id) => items.find((i) => i.id === id).owner))
	assert.deepEqual([...reached].sort(), accounts.map((a) => a.uid).sort())
	assert.ok(!sent.includes(4) && !sent.includes(5), 'a deleted or unknown owner is never sent')
})

test('an account appears once however many of its shares are selected', () => {
	const items = [share(1, 'a'), share(2, 'a'), share(3, 'a')]

	const accounts = accountsToMove(items, [1, 2, 3])

	assert.equal(accounts.length, 1)
	assert.equal(accounts[0].shareId, 1)
})

test('accounts keep the order they were selected in, and use the display name', () => {
	const items = [share(1, 'a'), share(2, 'b')]

	assert.deepEqual(accountsToMove(items, [2, 1]).map((a) => a.name), ['B', 'A'])
})

test('a selection that is not on the page any more contributes nothing', () => {
	assert.deepEqual(accountsToMove([share(1, 'a')], [99]), [])
})

test('the shares left out of an account move are reported, not dropped in silence', () => {
	const items = [share(1, 'a'), share(2, 'gone', 'deleted'), share(3, 'odd', 'unknown')]

	assert.deepEqual(leftOutOfAccountMove(items, [1, 2, 3]), [
		{ id: 2, reason: 'owner_deleted' },
		{ id: 3, reason: 'not_orphan' },
	])
})

// ---------------------------------------------------------------------------
// The file move is offered what the plain transfer could not do
// ---------------------------------------------------------------------------

test('Talk, mail and federated shares are offered to the file move too, not only no_access ones', () => {
	// The plain transfer refuses these as unsupported_type before it looks at the file.
	const skipped = [
		{ id: 10, reason: 'no_access' },
		{ id: 11, reason: 'unsupported_type' },
		{ id: 12, reason: 'unsupported_type' },
	]

	assert.deepEqual(idsForFileMove(skipped), [10, 11, 12])
})

test('a share is offered once', () => {
	assert.deepEqual(idsForFileMove([{ id: 1, reason: 'no_access' }, { id: 1, reason: 'no_access' }]), [1])
})

test('a share the file move accepted is done, so it drops out of what was skipped', () => {
	const plain = [{ id: 10, reason: 'no_access' }, { id: 11, reason: 'unsupported_type' }]

	assert.deepEqual(skippedAfterFileMove(plain, []), [])
})

test('what the file move refuses keeps a reason, and its own reason wins when it says more', () => {
	const plain = [
		{ id: 10, reason: 'no_access' },
		{ id: 11, reason: 'unsupported_type' },
		{ id: 12, reason: 'no_access' },
	]
	const move = [
		{ id: 10, reason: 'owner_deleted' },
		{ id: 11, reason: 'already_queued' },
		{ id: 12, reason: 'source_missing' },
	]

	assert.deepEqual(skippedAfterFileMove(plain, move), move)
})

test('not_in_home says less than why the plain transfer refused, so that reason is kept', () => {
	// A Team Folder file: the move cannot help, but "the new owner may not share it"
	// is what the admin can act on.
	const plain = [{ id: 10, reason: 'insufficient_permissions' }, { id: 11, reason: 'no_access' }]
	const move = [{ id: 10, reason: 'not_in_home' }, { id: 11, reason: 'not_in_home' }]

	assert.deepEqual(skippedAfterFileMove(plain, move), plain)
})
