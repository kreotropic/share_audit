<template>
	<HBarChart :rows="rows" />
</template>

<script>
import HBarChart from './HBarChart.vue'
import { categoryLabel, otherCategoryHint } from '../utils/format.js'

const ORDER = ['user', 'group', 'link', 'email', 'federated', 'talk', 'other']

// Distinct colour per share type. Values live in css/admin.css so they can
// swap for dark themes; var() resolves fine inside an inline style value.
const COLORS = {
	user: 'var(--sad-type-user)',
	group: 'var(--sad-type-group)',
	link: 'var(--sad-type-link)',
	email: 'var(--sad-type-email)',
	federated: 'var(--sad-type-federated)',
	talk: 'var(--sad-type-talk)',
	other: 'var(--sad-type-other)',
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
				title: key === 'other' ? otherCategoryHint() : undefined,
			}))
		},
	},
}
</script>
