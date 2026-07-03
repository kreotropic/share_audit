<template>
	<div class="sad-cards">
		<div v-for="card in cards" :key="card.key" class="sad-card" :class="'sad-card--' + card.key">
			<span class="sad-card__count">{{ card.count }}</span>
			<span class="sad-card__label">{{ card.label }}</span>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { categoryLabel } from '../utils/format.js'

export default {
	name: 'StatsCards',
	props: {
		byType: {
			type: Object,
			default: () => ({}),
		},
		total: {
			type: Number,
			default: 0,
		},
	},
	computed: {
		cards() {
			const order = ['user', 'group', 'link', 'email', 'federated', 'talk', 'other']
			const cards = [{
				key: 'total',
				label: t('share_audit_dashboard', 'Total shares'),
				count: this.total,
			}]
			for (const key of order) {
				cards.push({
					key,
					label: categoryLabel(key),
					count: this.byType[key] ?? 0,
				})
			}
			return cards
		},
	},
}
</script>

<style scoped lang="scss">
.sad-cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
	gap: 12px;
	margin: 8px 0;
}

.sad-card {
	display: flex;
	flex-direction: column;
	padding: 16px;
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover);
}

.sad-card--total {
	background-color: var(--color-primary-element-light, var(--color-background-dark));
}

.sad-card__count {
	font-size: 28px;
	font-weight: 600;
	line-height: 1.1;
}

.sad-card__label {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin-top: 4px;
}
</style>
