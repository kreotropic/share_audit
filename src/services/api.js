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
export async function fetchAlerts() {
	const { data } = await axios.get(base('/api/alerts'))
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
 * Fetch the configurable security-alert rules.
 */
export async function fetchSettings() {
	const { data } = await axios.get(base('/api/settings'))
	return data
}

/**
 * Persist the configurable security-alert rules.
 *
 * @param {object} payload { sensitiveExtensions, ruleNoPassword, ruleNoExpiration, ruleSensitiveFile }
 */
export async function saveSettings(payload) {
	const { data } = await axios.post(base('/api/settings'), payload)
	return data
}
