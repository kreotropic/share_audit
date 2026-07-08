import { translate as t } from '@nextcloud/l10n'

const APP = 'share_audit_dashboard'

/**
 * Human-readable label for a share category (as returned by the backend).
 *
 * @param {string} category one of user|group|link|email|federated|talk|other
 * @return {string}
 */
export function categoryLabel(category) {
	const labels = {
		user: t(APP, 'User'),
		group: t(APP, 'Group'),
		link: t(APP, 'Public link'),
		email: t(APP, 'Email'),
		federated: t(APP, 'Federated'),
		talk: t(APP, 'Talk'),
		other: t(APP, 'Other'),
	}
	return labels[category] ?? category
}

/**
 * Filter options for the share-type selector. Each option maps a category to
 * the raw share_type integers the backend understands.
 *
 * 'group' includes both type 1 (Group) and type 7 (Circle): the backend
 * buckets circles with groups (see ShareCollectorService::CATEGORY_BY_TYPE),
 * since both are internal, defined-membership recipients.
 */
export function typeFilterOptions() {
	return [
		{ id: 'user', label: categoryLabel('user'), types: [0] },
		{ id: 'group', label: categoryLabel('group'), types: [1, 7] },
		{ id: 'link', label: categoryLabel('link'), types: [3] },
		{ id: 'email', label: categoryLabel('email'), types: [4] },
		{ id: 'federated', label: categoryLabel('federated'), types: [6, 9] },
		{ id: 'talk', label: categoryLabel('talk'), types: [10] },
	]
}

/**
 * Translate a permission token from the backend into a readable word.
 *
 * @param {string} token read|update|create|delete|share
 * @return {string}
 */
export function permissionLabel(token) {
	const labels = {
		read: t(APP, 'Read'),
		update: t(APP, 'Edit'),
		create: t(APP, 'Create'),
		delete: t(APP, 'Delete'),
		share: t(APP, 'Reshare'),
	}
	return labels[token] ?? token
}

/**
 * Human-readable label for a security-alert issue code.
 *
 * @param {string} code no_password|no_expiration|sensitive_file
 * @return {string}
 */
export function issueLabel(code) {
	const labels = {
		no_password: t(APP, 'No password'),
		no_expiration: t(APP, 'No expiration date'),
		sensitive_file: t(APP, 'Sensitive file type'),
	}
	return labels[code] ?? code
}

/**
 * Format a unix timestamp (seconds) as a localized date, or a dash if empty.
 *
 * @param {number|null} seconds
 * @return {string}
 */
export function formatDate(seconds) {
	if (!seconds) {
		return '—'
	}
	return new Date(seconds * 1000).toLocaleDateString()
}
