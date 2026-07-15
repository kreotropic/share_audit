/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import App from './App.vue'
import PersonalApp from './PersonalApp.vue'

document.addEventListener('DOMContentLoaded', () => {
	const admin = document.getElementById('share-audit-dashboard')
	if (admin) {
		createApp(App).mount(admin)
	}
	const personal = document.getElementById('share-audit-personal')
	if (personal) {
		const enabled = personal.dataset.enabled !== '0'
		createApp(PersonalApp, { enabled }).mount(personal)
	}
})
