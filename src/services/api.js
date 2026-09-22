/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/share_audit_dashboard' + path)

/**
 * Fetch dashboard counters (totals per share type, trend, top users).
 */
export async function fetchStats() {
	const { data } = await axios.get(base('/api/stats'))
	return data
}

/**
 * Fetch a paginated, filterable list of shares.
 *
 * @param {object} params query params (page, limit, shareType, search, ...)
 */
export async function fetchShares(params = {}) {
	const { data } = await axios.get(base('/api/shares'), { params })
	return data
}

/**
 * Fetch security alerts (links without password/expiration, oversharing, ...).
 */
export async function fetchAlerts(params = {}) {
	const { data } = await axios.get(base('/api/alerts'), { params })
	return data
}

/**
 * Download the filtered share list as CSV. Returns the raw axios response so
 * the caller can read the blob body and the Content-Disposition filename.
 *
 * @param {object} params same filter params as fetchShares
 */
export async function exportShares(params = {}) {
	return axios.get(base('/api/export'), { params, responseType: 'blob' })
}

/**
 * Set (or auto-generate) a password on a share. Returns { password } with the
 * plain password when auto-generated.
 */
export async function setSharePassword(id, password = '') {
	const { data } = await axios.post(base('/api/shares/' + id + '/password'),
		password ? { password } : {})
	return data
}

/**
 * Set a share's expiration to N days from today.
 */
export async function setShareExpiration(id, days) {
	const { data } = await axios.post(base('/api/shares/' + id + '/expiration'), { days })
	return data
}

/**
 * Revoke (delete) a share.
 */
export async function revokeShare(id) {
	const { data } = await axios.delete(base('/api/shares/' + id))
	return data
}

/**
 * Apply one action to many shares at once.
 *
 * @param {string} action password | expiration | revoke
 * @param {number[]} ids share ids
 * @param {object} params extra params (e.g. { days })
 */
export async function bulkShareAction(action, ids, params = {}) {
	const { data } = await axios.post(base('/api/shares/bulk'), { action, ids, ...params })
	return data
}

/**
 * Autocomplete recipients (users / groups / emails) matching a query.
 */
export async function searchRecipients(q) {
	const { data } = await axios.get(base('/api/recipients/search'), { params: { q } })
	return data.items
}

/**
 * Shares granting access to a recipient, paginated.
 *
 * @param {object} params { page, limit } — limit 0 returns every share on one page
 */
export async function recipientShares(shareWith, shareType, params = {}) {
	const { data } = await axios.get(base('/api/recipients/shares'), { params: { shareWith, shareType, ...params } })
	return data
}

/**
 * Revoke every share to a recipient.
 */
export async function revokeRecipientAll(shareWith, shareType) {
	const { data } = await axios.post(base('/api/recipients/revoke-all'), { shareWith, shareType })
	return data
}

// --- Personal (per-user) view ---

export async function fetchMySummary() {
	const { data } = await axios.get(base('/api/my/summary'))
	return data
}

export async function fetchMyShares(params = {}) {
	const { data } = await axios.get(base('/api/my/shares'), { params })
	return data
}

export async function fetchMyAlerts() {
	const { data } = await axios.get(base('/api/my/alerts'))
	return data
}

export async function setMySharePassword(id, password = '') {
	const { data } = await axios.post(base('/api/my/shares/' + id + '/password'), password ? { password } : {})
	return data
}

export async function setMyShareExpiration(id, days) {
	const { data } = await axios.post(base('/api/my/shares/' + id + '/expiration'), { days })
	return data
}

export async function revokeMyShare(id) {
	const { data } = await axios.delete(base('/api/my/shares/' + id))
	return data
}

/**
 * Fetch the exposure overview (counts per category, score, top users).
 */
export async function fetchExposure() {
	const { data } = await axios.get(base('/api/exposure'))
	return data
}

/**
 * Fetch the paginated list of orphan shares (owner disabled/deleted).
 */
export async function fetchOrphans(params = {}) {
	const { data } = await axios.get(base('/api/orphans'), { params })
	return data
}

/**
 * Revoke selected orphan shares.
 *
 * @param {number[]} ids
 */
export async function revokeOrphans(ids) {
	const { data } = await axios.post(base('/api/orphans/revoke'), { ids })
	return data
}

/**
 * Enabled accounts that can take orphan shares over, for the new-owner picker.
 *
 * @param {string} search matched against user id and display name; empty lists
 *   the first few accounts
 * @return {Promise<Array<{uid: string, displayName: string}>>}
 */
export async function searchTransferTargets(search = '') {
	const { data } = await axios.get(base('/api/orphans/transfer-targets'), { params: { search } })
	return data.items
}

/**
 * Hand orphan shares to another account instead of revoking them. Each share
 * that could not move comes back with the reason, see
 * OrphanTransferService::transfer().
 *
 * @param {number[]} ids
 * @param {string} newOwner user id of the account taking over
 * @return {Promise<{transferred: number, skipped: Array<{id: number, reason: string}>, failed: number[]}>}
 */
export async function transferOrphans(ids, newOwner) {
	const { data } = await axios.post(base('/api/orphans/transfer'), { ids, newOwner })
	return data
}

/**
 * Fetch the paginated list of recycled (soft-deleted) shares.
 */
export async function fetchDeletedShares(params = {}) {
	const { data } = await axios.get(base('/api/deleted'), { params })
	return data
}

/**
 * Restore a recycled share. Response may include `tokenChanged: true` for a
 * link share whose original token could not be preserved (see
 * SoftDeleteService::restore()) — the caller should surface that.
 */
export async function restoreDeletedShare(id) {
	const { data } = await axios.post(base('/api/deleted/' + id + '/restore'))
	return data
}

/**
 * Permanently delete one recycled entry.
 */
export async function purgeDeletedShare(id) {
	const { data } = await axios.delete(base('/api/deleted/' + id))
	return data
}

/**
 * Permanently delete several recycled entries at once.
 *
 * @param {number[]} ids
 */
export async function purgeDeletedShares(ids) {
	const { data } = await axios.post(base('/api/deleted/purge'), { ids })
	return data
}

/**
 * Accept $ruleCodes (every issue code currently shown on that alert row) as
 * an exception on share $id, with an optional free-text note.
 *
 * @param {number} id
 * @param {string[]} ruleCodes
 * @param {string} note
 */
export async function acknowledgeAlert(id, ruleCodes, note = '') {
	const { data } = await axios.post(base('/api/alerts/' + id + '/ack'), { ruleCodes, note })
	return data
}

/**
 * Undo a previously accepted exception, restoring $ruleCodes to the active
 * alert list for share $id.
 *
 * @param {number} id
 * @param {string[]} ruleCodes
 */
export async function unacknowledgeAlert(id, ruleCodes) {
	const { data } = await axios.delete(base('/api/alerts/' + id + '/ack'), { data: { ruleCodes } })
	return data
}

/**
 * Acknowledge many alerts at once. Each item names its own ruleCodes — an
 * alert's issue set isn't uniform across a selection the way a shared
 * action (revoke, set expiration) is.
 *
 * @param {Array<{id: number, ruleCodes: string[]}>} items
 * @param {string} note applies to every item in the batch
 */
export async function bulkAcknowledgeAlerts(items, note = '') {
	const { data } = await axios.post(base('/api/alerts/bulk-ack'), { items, note })
	return data
}

/**
 * Fetch the configurable security-alert rules.
 */
export async function fetchSettings() {
	const { data } = await axios.get(base('/api/settings'))
	return data
}

/**
 * Persist the configurable security-alert rules.
 *
 * @param {object} payload { sensitiveExtensions, ruleNoPassword, ruleNoExpiration, ruleSensitiveFile,
 *   ruleGroupShareEditable, rulePublicUpload, personalViewEnabled, groupShareMinMembers, auditorGroups }
 */
export async function saveSettings(payload) {
	const { data } = await axios.post(base('/api/settings'), payload)
	return data
}

/**
 * Search instance groups, for the "Auditor groups" picker in Settings.
 *
 * @param {string} search matched against the group id and display name;
 *   empty lists the first few groups
 * @return {Promise<Array<{id: string, displayName: string}>>}
 */
export async function searchAuditorGroups(search = '') {
	const { data } = await axios.get(base('/api/settings/groups'), { params: { search } })
	return data.items
}
