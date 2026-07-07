<template>
	<HBarChart :rows="rows" :palette="palette" />
</template>

<script>
import HBarChart from './HBarChart.vue'
import { categoryLabel } from '../utils/format.js'

const ORDER = ['user', 'group', 'link', 'email', 'federated', 'talk', 'other']

// Neutral, consistent palette (Nextcloud blue → blue-greys).
const PALETTE = ['#0082c9', '#5c7a99', '#8a9ba8', '#b0bec5']

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
	data() {
		return { palette: PALETTE }
	},
	computed: {
		rows() {
			return ORDER.map((key) => ({
				key,
				label: categoryLabel(key),
				count: this.byType[key] ?? 0,
			}))
		},
	},
}
</script>
