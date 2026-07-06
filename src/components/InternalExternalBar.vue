<template>
	<div class="sad-split">
		<div v-if="total > 0" class="sad-split__bar">
			<div v-for="seg in segments"
				:key="seg.key"
				class="sad-split__seg"
				:class="'sad-split__seg--' + seg.key"
				:style="{ flexGrow: seg.count }"
				:title="`${seg.label}: ${seg.count} (${seg.pct}%)`">
				<span v-if="seg.pct >= 12" class="sad-split__seg-pct">{{ seg.pct }}%</span>
			</div>
		</div>
		<p v-else class="settings-hint">{{ t('share_audit_dashboard', 'No shares yet.') }}</p>

		<ul class="sad-split__legend">
			<li v-for="seg in segments" :key="seg.key">
				<span class="sad-split__dot" :class="'sad-split__seg--' + seg.key" />
				<span class="sad-split__legend-label">{{ seg.label }}</span>
				<span class="sad-split__legend-value">{{ seg.count }} · {{ seg.pct }}%</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'InternalExternalBar',
	props: {
		byType: {
			type: Object,
			required: true,
		},
	},
	computed: {
		total() {
			return Object.values(this.buckets).reduce((a, b) => a + b, 0)
		},
		buckets() {
			const b = this.byType
			return {
				internal: (b.user ?? 0) + (b.group ?? 0) + (b.talk ?? 0),
				external: (b.link ?? 0) + (b.email ?? 0) + (b.federated ?? 0),
				other: b.other ?? 0,
			}
		},
		segments() {
			const labels = {
				internal: t('share_audit_dashboard', 'Internal'),
				external: t('share_audit_dashboard', 'External'),
				other: t('share_audit_dashboard', 'Other'),
			}
			const total = this.total || 1
			return Object.entries(this.buckets)
				.filter(([, count]) => count > 0)
				.map(([key, count]) => ({
					key,
					label: labels[key],
					count,
					pct: Math.round((count / total) * 100),
				}))
		},
	},
	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.sad-split__bar {
	display: flex;
	gap: 2px;
	height: 28px;
	width: 100%;
}

.sad-split__seg {
	min-width: 3px;
	display: flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;

	&:first-child {
		border-top-left-radius: var(--border-radius, 6px);
		border-bottom-left-radius: var(--border-radius, 6px);
	}

	&:last-child {
		border-top-right-radius: var(--border-radius, 6px);
		border-bottom-right-radius: var(--border-radius, 6px);
	}
}

.sad-split__seg-pct {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-primary-element-text, #fff);
}

.sad-split__seg--internal {
	background: var(--color-primary-element);
}

.sad-split__seg--external {
	background: var(--color-warning, #c28900);
}

.sad-split__seg--other {
	background: var(--color-text-maxcontrast);
}

.sad-split__legend {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin-top: 12px;
}

.sad-split__legend li {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
}

.sad-split__dot {
	width: 10px;
	height: 10px;
	border-radius: 2px;
	flex-shrink: 0;
}

.sad-split__legend-value {
	color: var(--color-text-maxcontrast);
}
</style>
