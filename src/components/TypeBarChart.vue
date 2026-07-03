<template>
	<div class="sad-bars">
		<div v-for="row in rows" :key="row.key" class="sad-bars__row" :title="`${row.label}: ${row.count}`">
			<span class="sad-bars__label">{{ row.label }}</span>
			<div class="sad-bars__track">
				<div class="sad-bars__fill" :style="{ width: row.width + '%' }" />
			</div>
			<span class="sad-bars__value">{{ row.count }}</span>
		</div>
	</div>
</template>

<script>
import { categoryLabel } from '../utils/format.js'

export default {
	name: 'TypeBarChart',
	props: {
		byType: {
			type: Object,
			required: true,
		},
	},
	computed: {
		rows() {
			const order = ['user', 'group', 'link', 'email', 'federated', 'talk', 'other']
			const entries = order
				.map((key) => ({ key, label: categoryLabel(key), count: this.byType[key] ?? 0 }))
				.filter((r) => r.count > 0)
			const max = Math.max(1, ...entries.map((r) => r.count))
			return entries.map((r) => ({ ...r, width: Math.round((r.count / max) * 100) }))
		},
	},
}
</script>

<style scoped lang="scss">
.sad-bars {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sad-bars__row {
	display: grid;
	grid-template-columns: 90px 1fr 32px;
	align-items: center;
	gap: 8px;
}

.sad-bars__label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sad-bars__track {
	height: 16px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius, 6px);
	overflow: hidden;
}

.sad-bars__fill {
	height: 100%;
	min-width: 4px;
	background: var(--color-primary-element);
	border-radius: var(--border-radius, 6px);
	transition: width 0.3s ease;
}

.sad-bars__row:hover .sad-bars__fill {
	background: var(--color-primary-element-hover, var(--color-primary-element));
	filter: brightness(1.1);
}

.sad-bars__value {
	font-size: 13px;
	font-weight: 600;
	text-align: right;
}
</style>
