/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import App from './App.vue'
import PersonalApp from './PersonalApp.vue'

document.addEventListener('DOMContentLoaded', () => {
	const admin = document.getElementById('share-audit-dashboard')
	if (admin) {
		// data-role comes from templates/admin.php ('admin') or
		// templates/viewer.php ('auditor', and later 'manager' — see
		// AccessScope). Defaults to 'admin' so an older cached template
		// without the attribute still gets the full app. canManage() is
		// injected for every component that shows a write action; see
		// App.vue and the components that read it.
		const role = admin.dataset.role || 'admin'
		const app = createApp(App)
		app.provide('viewerRole', role)
		app.provide('canManage', role === 'admin')
		app.mount(admin)
	}
	const personal = document.getElementById('share-audit-personal')
	if (personal) {
		createApp(PersonalApp).mount(personal)
	}
})
