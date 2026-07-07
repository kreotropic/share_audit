<template>
	<div>
		<NcLoadingIcon v-if="loading" :size="32" class="sad-loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<div class="sad-exposure">
				<section class="sad-panel sad-exposure__score">
					<ExposureDonut :segments="segments"
						:center-value="overview.score"
						:center-label="t('share_audit_dashboard', 'score')" />
					<span class="sad-exposure__badge" :class="'sad-exposure__badge--' + overview.level">
						{{ levelLabel }}
					</span>
					<ul class="sad-exposure__legend">
						<li v-for="seg in segments" :key="seg.key">
							<span class="sad-exposure__dot" :style="{ background: seg.color }" />
							<span>{{ seg.label }}</span>
						</li>
					</ul>
					<p class="settings-hint sad-exposure__hint">
						{{ t('share_audit_dashboard', '0 = everything internal, 100 = everything public.') }}
					</p>
				</section>

				<section class="sad-panel sad-exposure__breakdown">
					<h3>{{ t('share_audit_dashboard', 'Exposure by reach') }}</h3>
					<div v-for="cat in segments" :key="cat.key" class="sad-exposure__row">
						<span class="sad-exposure__dot" :style="{ background: cat.color }" />
						<span class="sad-exposure__name">{{ cat.label }}</span>
						<div class="sad-exposure__track">
							<div class="sad-exposure__fill"
								:style="{ width: pct(cat.value) + '%', background: cat.color }" />
						</div>
						<span class="sad-exposure__val">{{ cat.value }} · {{ pct(cat.value) }}%</span>
						<NcButton type="tertiary"
							:disabled="cat.value === 0"
							@click="$emit('drilldown', cat.key)">
							{{ t('share_audit_dashboard', 'View') }}
						</NcButton>
					</div>
				</section>
			</div>

			<section class="sad-panel sad-exposure__top">
				<h3>{{ t('share_audit_dashboard', 'Most public exposure') }}</h3>
				<ul v-if="overview.topUsers.length" class="sad-top">
					<li v-for="u in overview.topUsers" :key="u.owner">
						<span>{{ u.displayName || u.owner }}</span>
						<span class="sad-top__count">
							{{ n('share_audit_dashboard', '%n public link', '%n public links', u.count) }}
						</span>
					</li>
				</ul>
				<p v-else class="settings-hint">{{ t('share_audit_dashboard', 'No public links.') }}</p>
			</section>
		</template>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ExposureDonut from '../components/ExposureDonut.vue'
import { fetchExposure } from '../services/api.js'

export default {
	name: 'ExposureMap',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		ExposureDonut,
	},
	emits: ['drilldown'],
	data() {
		return {
			loading: true,
			error: null,
			overview: null,
		}
	},
	computed: {
		segments() {
			const c = this.overview.counts
			return [
				{ key: 'internal', label: t('share_audit_dashboard', 'Internal'), value: c.internal, color: '#2a9d8f' },
				{ key: 'external', label: t('share_audit_dashboard', 'External'), value: c.external, color: '#e76f51' },
				{ key: 'public', label: t('share_audit_dashboard', 'Public'), value: c.public, color: '#c1121f' },
			]
		},
		levelLabel() {
			const labels = {
				low: t('share_audit_dashboard', 'Low exposure'),
				medium: t('share_audit_dashboard', 'Medium exposure'),
				high: t('share_audit_dashboard', 'High exposure'),
			}
			return labels[this.overview.level] ?? this.overview.level
		},
	},
	async mounted() {
		try {
			this.overview = await fetchExposure()
		} catch (e) {
			this.error = t('share_audit_dashboard', 'Could not load the exposure map.')
		} finally {
			this.loading = false
		}
	},
	methods: {
		t,
		n,
		pct(value) {
			return this.overview.total ? Math.round((value / this.overview.total) * 100) : 0
		},
	},
}
</script>

<style scoped lang="scss">
.sad-exposure {
	display: grid;
	grid-template-columns: minmax(240px, 320px) 1fr;
	gap: 16px;
	margin: 16px 0;
}

.sad-panel {
	padding: 16px;
	border-radius: var(--border-radius-large, 12px);
	border: 1px solid var(--color-border);

	h3 {
		margin: 0 0 12px;
		font-size: 15px;
	}
}

.sad-exposure__score {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 10px;
}

.sad-exposure__badge {
	display: inline-block;
	font-weight: 600;
	padding: 3px 10px;
	border-radius: var(--border-radius, 6px);
	color: #fff;
	border: 1px solid transparent;
}

.sad-exposure__badge--low {
	background: #2a9d8f;
}

.sad-exposure__badge--medium {
	background: #fef3c7;
	color: #92400e;
	border-color: #fcd34d;
}

.sad-exposure__badge--high {
	background: #c1121f;
}

.sad-exposure__legend {
	display: flex;
	flex-wrap: wrap;
	justify-content: center;
	gap: 6px 16px;
	margin-top: 2px;
}

.sad-exposure__legend li {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 13px;
}

.sad-exposure__hint {
	text-align: center;
	margin: 0;
}

.sad-exposure__row {
	display: grid;
	grid-template-columns: 14px 90px 1fr auto auto;
	align-items: center;
	gap: 10px;
	margin-bottom: 10px;
}

.sad-exposure__dot {
	width: 12px;
	height: 12px;
	border-radius: 3px;
}

.sad-exposure__name {
	font-size: 13px;
}

.sad-exposure__track {
	height: 16px;
	background: #f3f4f6;
	border-radius: var(--border-radius, 6px);
	overflow: hidden;
}

.sad-exposure__fill {
	height: 100%;
	min-width: 3px;
	border-radius: var(--border-radius, 6px);
}

.sad-exposure__val {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.sad-exposure__top {
	margin-top: 16px;
}

.sad-top li {
	display: flex;
	justify-content: space-between;
	padding: 5px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.sad-top li:last-child {
	border-bottom: none;
}

.sad-top__count {
	color: var(--color-text-maxcontrast);
}

@media (max-width: 700px) {
	.sad-exposure {
		grid-template-columns: 1fr;
	}
}
</style>
