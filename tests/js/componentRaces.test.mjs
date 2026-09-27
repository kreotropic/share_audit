/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Run with: npm run test:js  (plain node:test, no dependencies)
//
// The methods of the real components, run with the API replaced by promises
// the test resolves itself, in the order it wants: what happens when the admin
// changes something while a request is still out.

import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'

const root = new URL('../../', import.meta.url)

/**
 * The component's options object, from the <script> of its .vue file, with
 * its imports replaced by `stubs` (and by inert objects for the UI parts).
 */
function component(file, stubs) {
	const source = fs.readFileSync(new URL(file, root), 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1]
		.replace(/^import .*$/gm, '')
		.replace('export default {', 'globalThis.component = {')
	const inert = new Proxy({}, { get: () => ({}) })
	const context = {
		t: (_app, text) => text,
		n: (_app, one, many, count) => (count === 1 ? one : many).replace('%n', count),
		...stubs,
	}
	for (const name of ['NcButton', 'NcChip', 'NcEmptyContent', 'NcCheckboxRadioSwitch', 'NcLoadingIcon', 'NcNoteCard', 'NcTextField',
		'PageNavigation', 'PageSizeSelect', 'ShareTable', 'categoryLabel', 'permissionLabel', 'formatDate']) {
		context[name] ??= inert
	}
	vm.runInNewContext(source, context)
	return context.component
}

/** A component's own data() plus `overrides`, with its methods bound to it, like `this` in Vue. */
function instance(options, overrides = {}) {
	const state = Object.assign(options.data ? options.data.call({}) : {}, overrides)
	for (const [name, method] of Object.entries(options.methods)) {
		state[name] = typeof method === 'function' ? method.bind(state) : method
	}
	return state
}

function deferred() {
	let resolve
	const promise = new Promise((r) => { resolve = r })
	return { promise, resolve }
}

test('every batch of a revoke goes to the recipient that was confirmed, even if another is selected meanwhile', async () => {
	const calls = []
	const first = deferred()
	const options = component('src/views/RecipientDrilldown.vue', {
		revokeRecipientAll: async (shareWith, shareType) => {
			calls.push({ shareWith, shareType })
			return calls.length === 1 ? first.promise : { deleted: 1, remaining: 0, failed: [] }
		},
		searchRecipients: async () => [],
		recipientShares: async () => ({ items: [], total: 0 }),
	})
	const view = instance(options, { selected: { shareWith: 'alice', shareType: 0 }, total: 501 })

	const running = view.revokeAll()
	// Not possible through the UI any more (search and Back are disabled), but
	// what is sent must not depend on that.
	view.selected = { shareWith: 'bob', shareType: 0 }
	first.resolve({ deleted: 500, remaining: 1, failed: [] })
	await running

	assert.deepEqual(calls, [{ shareWith: 'alice', shareType: 0 }, { shareWith: 'alice', shareType: 0 }])
})

test('a recipient cannot be selected, nor the selection cleared, while a revoke runs', async () => {
	const options = component('src/views/RecipientDrilldown.vue', {
		searchRecipients: async () => [],
		recipientShares: async () => ({ items: [], total: 0 }),
	})
	const view = instance(options, { selected: { shareWith: 'alice', shareType: 0 }, revoking: true })

	await view.select({ shareWith: 'bob', shareType: 0 })
	view.clearSelection()

	assert.deepEqual(view.selected, { shareWith: 'alice', shareType: 0 })
})

test('a revoke that leaves shares behind still says so once the list is reloaded', async () => {
	let loads = 0
	const options = component('src/views/RecipientDrilldown.vue', {
		revokeRecipientAll: async () => ({ deleted: 0, remaining: 1, failed: [55] }),
		recipientShares: async () => {
			loads++
			return { items: [{ id: 55 }], total: 1 }
		},
	})
	const view = instance(options, { selected: { shareWith: 'alice', shareType: 0 }, total: 1 })
	view.pageSize = { id: 25 }

	await view.revokeAll()

	assert.equal(loads, 1)
	assert.equal(view.total, 1)
	assert.equal(view.notice?.type, 'warning')
	assert.match(view.notice.message, /still grants this recipient access/)
})

test('a slow answer to an earlier search does not replace the answer to the current one', async () => {
	const answers = {}
	const options = component('src/views/ShareList.vue', {
		fetchShares: ({ pathSearch }) => {
			answers[pathSearch] = deferred()
			return answers[pathSearch].promise
		},
		exportShares: async () => {},
	})
	const view = instance(options, { apiLimit: 50, requestFilters: { pathSearch: 'old' } })

	const old = view.load()
	view.requestFilters = { pathSearch: 'new' }
	const current = view.load()
	answers.new.resolve({ items: [{ id: 2 }], total: 1 })
	await current
	answers.old.resolve({ items: [{ id: 1 }, { id: 3 }], total: 2 })
	await old

	assert.deepEqual(view.items, [{ id: 2 }])
	assert.equal(view.total, 1)
	assert.equal(view.loading, false)
})

test('an earlier request failing late does not show an error over the current answer', async () => {
	const answers = []
	const options = component('src/views/ShareList.vue', {
		fetchShares: () => {
			answers.push(deferred())
			return answers.at(-1).promise.then((r) => (r instanceof Error ? Promise.reject(r) : r))
		},
		exportShares: async () => {},
	})
	const view = instance(options, { apiLimit: 50, requestFilters: {} })

	const old = view.load()
	const current = view.load()
	answers[1].resolve({ items: [], total: 0 })
	await current
	answers[0].resolve(new Error('timeout'))
	await old

	assert.equal(view.error, null)
})

test('a bulk purge sends what was selected when it was confirmed, whatever is unticked meanwhile', async () => {
	const sent = []
	const first = deferred()
	const options = component('src/views/DeletedShares.vue', {
		purgeDeletedShares: async (ids) => {
			sent.push([...ids])
			return sent.length === 1 ? first.promise : { purged: ids.length }
		},
	})
	const selected = Array.from({ length: 501 }, (_, i) => i + 1)
	const view = instance(options, { selectedIds: [...selected] })
	view.load = async () => {}

	const purging = view.purgeSelected()
	view.toggleSelect(1, false)
	first.resolve({ purged: 500 })
	await purging

	assert.deepEqual(sent.flat(), selected)
})
