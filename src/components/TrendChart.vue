<template>
	<div ref="root" class="sad-chart">
		<svg ref="svg"
			class="sad-trend"
			:style="{ height: height + 'px' }"
			:viewBox="`0 0 ${W} ${height}`"
			preserveAspectRatio="none"
			role="img"
			:aria-label="t('share_audit_dashboard', 'Shares created per month over the last 12 months')"
			@mousemove="onMove"
			@mouseleave="hoverIndex = null">
			<!-- horizontal gridlines + y labels -->
			<g class="sad-trend__grid">
				<template v-for="line in geo.gridLines" :key="line.v">
					<line :x1="padL" :y1="line.y" :x2="W - padR" :y2="line.y" />
					<text :x="padL - 6" :y="line.y + 3" text-anchor="end">{{ line.v }}</text>
				</template>
			</g>

			<!-- area + line -->
			<path class="sad-trend__area" :d="geo.areaPath" />
			<path class="sad-trend__line" :d="geo.linePath" />

			<!-- x labels -->
			<g class="sad-trend__xlabels">
				<text v-for="p in geo.points"
					:key="p.label"
					:x="p.x"
					:y="height - 8"
					text-anchor="middle">{{ p.month }}</text>
			</g>

			<!-- hover layer -->
			<g v-if="hovered" class="sad-trend__hover">
				<line :x1="hovered.x" :y1="padT" :x2="hovered.x" :y2="geo.baselineY" />
				<circle :cx="hovered.x" :cy="hovered.y" r="4" />
			</g>

			<!-- transparent capture rect -->
			<rect class="sad-trend__capture"
				:x="padL"
				:y="padT"
				:width="W - padL - padR"
				:height="height - padT - padB" />
		</svg>

		<div v-if="hovered" class="sad-chart__tip" :style="tipStyle">
			<strong>{{ hovered.count }}</strong> {{ t('share_audit_dashboard', 'shares') }}
			<span class="sad-chart__tip-sub">{{ hovered.full }}</span>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'TrendChart',
	props: {
		series: {
			type: Array,
			required: true,
		},
		height: {
			type: Number,
			default: 200,
		},
	},
	data() {
		return {
			W: 720,
			padL: 34,
			padR: 12,
			padT: 16,
			padB: 26,
			hoverIndex: null,
			resizeObserver: null,
		}
	},
	computed: {
		yMax() {
			const max = Math.max(1, ...this.series.map((s) => s.count))
			// Round up to a readable ceiling.
			const step = max <= 5 ? 1 : max <= 20 ? 5 : max <= 50 ? 10 : 25
			return Math.ceil(max / step) * step
		},
		geo() {
			const n = this.series.length
			const plotW = this.W - this.padL - this.padR
			const plotH = this.height - this.padT - this.padB
			const baselineY = this.height - this.padB
			const stepX = n > 1 ? plotW / (n - 1) : 0
			const xFor = (i) => this.padL + i * stepX
			const yFor = (v) => baselineY - (v / this.yMax) * plotH

			const points = this.series.map((s, i) => {
				const [year, month] = s.label.split('-')
				const date = new Date(Number(year), Number(month) - 1, 1)
				return {
					x: xFor(i),
					y: yFor(s.count),
					count: s.count,
					label: s.label,
					month: date.toLocaleDateString(undefined, { month: 'short' }),
					full: date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }),
				}
			})

			const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ')
			const areaPath = `M${points[0].x.toFixed(1)},${baselineY} `
				+ points.map((p) => `L${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ')
				+ ` L${points[n - 1].x.toFixed(1)},${baselineY} Z`

			const gridLines = [0, this.yMax / 2, this.yMax].map((v) => ({ v, y: yFor(v) }))

			return { points, linePath, areaPath, gridLines, baselineY }
		},
		hovered() {
			if (this.hoverIndex === null) {
				return null
			}
			return this.geo.points[this.hoverIndex] ?? null
		},
		tipStyle() {
			if (!this.hovered) {
				return {}
			}
			// Position tooltip as a percentage of the chart width/height.
			const left = (this.hovered.x / this.W) * 100
			const top = (this.hovered.y / this.height) * 100
			return {
				left: `${left}%`,
				top: `${top}%`,
			}
		},
	},
	mounted() {
		this.measure()
		this.resizeObserver = new ResizeObserver(() => this.measure())
		this.resizeObserver.observe(this.$refs.root)
	},
	beforeUnmount() {
		this.resizeObserver?.disconnect()
	},
	methods: {
		t,
		measure() {
			const width = this.$refs.root?.clientWidth
			if (width) {
				this.W = width
			}
		},
		onMove(event) {
			const svg = this.$refs.svg
			const rect = svg.getBoundingClientRect()
			const svgX = (event.clientX - rect.left) / rect.width * this.W
			const n = this.series.length
			const plotW = this.W - this.padL - this.padR
			const stepX = n > 1 ? plotW / (n - 1) : 0
			let idx = stepX > 0 ? Math.round((svgX - this.padL) / stepX) : 0
			idx = Math.max(0, Math.min(n - 1, idx))
			this.hoverIndex = idx
		},
	},
}
</script>

<style scoped lang="scss">
.sad-chart {
	position: relative;
	width: 100%;
}

.sad-trend {
	width: 100%;
	overflow: visible;
}

.sad-trend__grid line {
	stroke: var(--color-border);
	stroke-width: 1;
}

.sad-trend__grid text,
.sad-trend__xlabels text {
	fill: var(--color-text-maxcontrast);
	font-size: 11px;
}

.sad-trend__area {
	fill: var(--color-primary-element);
	fill-opacity: 0.15;
	stroke: none;
}

.sad-trend__line {
	fill: none;
	stroke: var(--color-primary-element);
	stroke-width: 2;
	stroke-linejoin: round;
	stroke-linecap: round;
}

.sad-trend__hover line {
	stroke: var(--color-primary-element);
	stroke-width: 1;
	stroke-dasharray: 3 3;
}

.sad-trend__hover circle {
	fill: var(--color-primary-element);
	stroke: var(--color-main-background);
	stroke-width: 2;
}

.sad-trend__capture {
	fill: transparent;
}

.sad-chart__tip {
	position: absolute;
	transform: translate(-50%, -130%);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px);
	box-shadow: 0 2px 8px var(--color-box-shadow, rgba(0, 0, 0, 0.2));
	padding: 4px 8px;
	font-size: 12px;
	white-space: nowrap;
	pointer-events: none;
	z-index: 2;
}

.sad-chart__tip-sub {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 11px;
}
</style>
