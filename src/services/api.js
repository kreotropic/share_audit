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
 * Paginated shares granting access to a recipient. params: { page, limit }.
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
 *   ruleGroupShareEditable, rulePublicUpload, personalViewEnabled, groupShareMinMembers }
 */
export async function saveSettings(payload) {
	const { data } = await axios.post(base('/api/settings'), payload)
	return data
}
