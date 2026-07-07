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
		createApp(PersonalApp).mount(personal)
	}
})
