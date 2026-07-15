<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="sad-donut">
		<svg viewBox="0 0 120 120" role="img" :aria-label="ariaLabel">
			<g transform="rotate(-90 60 60)">
				<circle class="sad-donut__track" cx="60" cy="60" :r="radius" />
				<circle v-for="arc in arcs"
					:key="arc.key"
					cx="60"
					cy="60"
					:r="radius"
					:style="{ stroke: arc.color }"
					:stroke-dasharray="arc.dash"
					:stroke-dashoffset="arc.offset" />
			</g>
			<text class="sad-donut__value" x="60" y="58">{{ centerValue }}</text>
			<text class="sad-donut__label" x="60" y="74">{{ centerLabel }}</text>
		</svg>
	</div>
</template>

<script>
export default {
	name: 'ExposureDonut',
	props: {
		/** @type {Array<{key:string,label:string,value:number,color:string}>} */
		segments: {
			type: Array,
			required: true,
		},
		centerValue: {
			type: [String, Number],
			default: '',
		},
		centerLabel: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			radius: 50,
			stroke: 14,
			gap: 3, // surface gap between segments, in circumference units
		}
	},
	computed: {
		circ() {
			return 2 * Math.PI * this.radius
		},
		total() {
			return this.segments.reduce((a, s) => a + s.value, 0)
		},
		arcs() {
			if (this.total === 0) {
				return []
			}
			let acc = 0
			return this.segments
				.filter((s) => s.value > 0)
				.map((s) => {
					const len = (s.value / this.total) * this.circ
					const visible = Math.max(0, len - this.gap)
					const arc = {
						key: s.key,
						color: s.color,
						dash: `${visible} ${this.circ - visible}`,
						offset: -acc,
					}
					acc += len
					return arc
				})
		},
		ariaLabel() {
			return this.segments.map((s) => `${s.label}: ${s.value}`).join(', ')
		},
	},
}
</script>

<style scoped lang="scss">
.sad-donut svg {
	width: 160px;
	height: 160px;
}

.sad-donut circle {
	fill: none;
	stroke-width: 14;
}

.sad-donut__track {
	stroke: var(--sad-track);
}

.sad-donut__value {
	text-anchor: middle;
	font-size: 26px;
	font-weight: 700;
	fill: var(--color-main-text);
}

.sad-donut__label {
	text-anchor: middle;
	font-size: 9px;
	fill: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}
</style>
