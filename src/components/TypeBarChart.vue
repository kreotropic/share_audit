<template>
	<HBarChart :rows="rows" />
</template>

<script>
import HBarChart from './HBarChart.vue'
import { categoryLabel } from '../utils/format.js'

const ORDER = ['user', 'group', 'link', 'email', 'federated', 'talk', 'other']

// Distinct colour per share type.
const COLORS = {
	user: '#0082c9', // Nextcloud blue
	group: '#5c7a99', // blue-grey
	link: '#e76f51', // orange (external/risk)
	email: '#2a9d8f', // teal
	federated: '#7d5ba6', // purple
	talk: '#3d9970', // green
	other: '#6b7280', // grey
}

export default {
	name: 'TypeBarChart',
	components: {
		HBarChart,
	},
	props: {
		byType: {
			type: Object,
			required: true,
		},
	},
	computed: {
		rows() {
			return ORDER.map((key) => ({
				key,
				label: categoryLabel(key),
				count: this.byType[key] ?? 0,
				color: COLORS[key],
			}))
		},
	},
}
</script>
