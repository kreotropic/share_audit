<template>
	<div class="sad-bars">
		<div v-for="(row, index) in computedRows"
			:key="row.key"
			class="sad-bars__row"
			:title="`${row.label}: ${row.count}`">
			<span class="sad-bars__label">{{ row.label }}</span>
			<div class="sad-bars__track" :style="trackStyle">
				<div class="sad-bars__fill" :style="fillStyle(row, index)" />
			</div>
			<span class="sad-bars__value">{{ row.count }}</span>
		</div>
	</div>
</template>

<script>
export default {
	name: 'HBarChart',
	props: {
		/** @type {Array<{key: string, label: string, count: number}>} */
		rows: {
			type: Array,
			required: true,
		},
		/** Hide rows whose count is zero. */
		hideZero: {
			type: Boolean,
			default: true,
		},
		/** Optional per-bar colours (cycled). Empty = single primary colour. */
		palette: {
			type: Array,
			default: () => [],
		},
		/** Optional track (bar background) colour. Empty = theme default. */
		trackColor: {
			type: String,
			default: '',
		},
	},
	computed: {
		computedRows() {
			const rows = this.hideZero ? this.rows.filter((r) => r.count > 0) : this.rows
			const max = Math.max(1, ...rows.map((r) => r.count))
			return rows.map((r) => ({ ...r, width: Math.round((r.count / max) * 100) }))
		},
		trackStyle() {
			return this.trackColor ? { background: this.trackColor } : null
		},
	},
	methods: {
		fillStyle(row, index) {
			const style = { width: row.width + '%' }
			// A per-row colour wins; otherwise cycle the optional palette.
			if (row.color) {
				style.background = row.color
			} else if (this.palette.length) {
				style.background = this.palette[index % this.palette.length]
			}
			return style
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
	grid-template-columns: 120px 1fr 32px;
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
	filter: brightness(1.1);
}

.sad-bars__value {
	font-size: 13px;
	font-weight: 600;
	text-align: right;
}
</style>
