<template>
	<HBarChart :rows="rows" />
</template>

<script>
import HBarChart from './HBarChart.vue'
import { categoryLabel } from '../utils/format.js'

const ORDER = ['user', 'group', 'link', 'email', 'federated', 'talk', 'other']

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
			}))
		},
	},
}
</script>
