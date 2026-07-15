/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// Single admin-settings entry point. @nextcloud/webpack-vue-config prefixes the
// app id, so this emits js/share_audit_dashboard-main.js, which templates/admin.php
// loads via script('share_audit_dashboard', 'share_audit_dashboard-main').
webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
}

module.exports = webpackConfig
