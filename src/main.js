import { createApp } from 'vue'
import App from './App.vue'

document.addEventListener('DOMContentLoaded', () => {
	const el = document.getElementById('share-audit-dashboard')
	if (el) {
		createApp(App).mount(el)
	}
})
