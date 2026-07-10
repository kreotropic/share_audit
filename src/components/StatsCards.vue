<template>
	<div class="sad-cards">
		<div v-for="card in cards"
			:key="card.key"
			class="sad-card"
			:class="['sad-card--' + card.key, { 'sad-card--clickable': card.clickable }]"
			:role="card.clickable ? 'button' : null"
			:tabindex="card.clickable ? 0 : null"
			@click="card.clickable && onSelect(card)"
			@keydown.enter="card.clickable && onSelect(card)"
			@keydown.space.prevent="card.clickable && onSelect(card)"
			:title="card.title">
			<span class="sad-card__icon" v-html="card.icon" />
			<span class="sad-card__body">
				<span class="sad-card__count">{{ card.count }}</span>
				<span class="sad-card__label">{{ card.label }}</span>
			</span>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { categoryLabel, otherCategoryHint, typeFilterOptions } from '../utils/format.js'

// category id -> raw share_type integers the ShareList filter understands.
const TYPE_MAP = Object.fromEntries(typeFilterOptions().map((o) => [o.id, o.types]))

// Small Material Design Icons paths, keyed by card.
const ICONS = {
	total: 'M18,16.08C17.24,16.08 16.56,16.38 16.04,16.85L8.91,12.7C8.96,12.47 9,12.24 9,12C9,11.76 8.96,11.53 8.91,11.3L15.96,7.19C16.5,7.69 17.21,8 18,8A3,3 0 0,0 21,5A3,3 0 0,0 18,2A3,3 0 0,0 15,5C15,5.24 15.04,5.47 15.09,5.7L8.04,9.81C7.5,9.31 6.79,9 6,9A3,3 0 0,0 3,12A3,3 0 0,0 6,15C6.79,15 7.5,14.69 8.04,14.19L15.16,18.34C15.11,18.55 15.08,18.77 15.08,19C15.08,20.61 16.39,21.91 18,21.91C19.61,21.91 20.92,20.61 20.92,19A2.92,2.92 0 0,0 18,16.08Z',
	user: 'M12,4A4,4 0 0,1 16,8A4,4 0 0,1 12,12A4,4 0 0,1 8,8A4,4 0 0,1 12,4M12,14C16.42,14 20,15.79 20,18V20H4V18C4,15.79 7.58,14 12,14Z',
	group: 'M16,13C15.71,13 15.38,13 15.03,13.05C16.19,13.89 17,15 17,16.5V19H23V16.5C23,14.17 18.33,13 16,13M8,13C5.67,13 1,14.17 1,16.5V19H15V16.5C15,14.17 10.33,13 8,13M8,11A3,3 0 0,0 11,8A3,3 0 0,0 8,5A3,3 0 0,0 5,8A3,3 0 0,0 8,11M16,11A3,3 0 0,0 19,8A3,3 0 0,0 16,5A3,3 0 0,0 13,8A3,3 0 0,0 16,11Z',
	link: 'M3.9,12C3.9,10.29 5.29,8.9 7,8.9H11V7H7A5,5 0 0,0 2,12A5,5 0 0,0 7,17H11V15.1H7C5.29,15.1 3.9,13.71 3.9,12M8,13H16V11H8V13M17,7H13V8.9H17C18.71,8.9 20.1,10.29 20.1,12C20.1,13.71 18.71,15.1 17,15.1H13V17H17A5,5 0 0,0 22,12A5,5 0 0,0 17,7Z',
	email: 'M20,8L12,13L4,8V6L12,11L20,6M20,4H4C2.89,4 2,4.89 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V6C22,4.89 21.1,4 20,4Z',
	federated: 'M16.36,14C16.44,13.34 16.5,12.68 16.5,12C16.5,11.32 16.44,10.66 16.36,10H19.74C19.9,10.64 20,11.31 20,12C20,12.69 19.9,13.36 19.74,14M14.59,19.56C15.19,18.45 15.65,17.25 15.97,16H18.92C17.96,17.65 16.43,18.93 14.59,19.56M14.34,14H9.66C9.56,13.34 9.5,12.68 9.5,12C9.5,11.32 9.56,10.65 9.66,10H14.34C14.43,10.65 14.5,11.32 14.5,12C14.5,12.68 14.43,13.34 14.34,14M12,19.96C11.17,18.76 10.5,17.43 10.09,16H13.91C13.5,17.43 12.83,18.76 12,19.96M8,8H5.08C6.03,6.34 7.57,5.06 9.4,4.44C8.8,5.55 8.35,6.75 8,8M5.08,16H8C8.35,17.25 8.8,18.45 9.4,19.56C7.57,18.93 6.03,17.65 5.08,16M4.26,14C4.1,13.36 4,12.69 4,12C4,11.31 4.1,10.64 4.26,10H7.64C7.56,10.66 7.5,11.32 7.5,12C7.5,12.68 7.56,13.34 7.64,14M12,4.03C12.83,5.23 13.5,6.57 13.91,8H10.09C10.5,6.57 11.17,5.23 12,4.03M18.92,8H15.97C15.65,6.75 15.19,5.55 14.59,4.44C16.43,5.07 17.96,6.34 18.92,8M12,2C6.47,2 2,6.5 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2Z',
	talk: 'M12,3C6.5,3 2,6.58 2,11C2.05,13.15 3.06,15.17 4.75,16.5C4.75,17.1 4.33,18.67 2,21C4.37,20.89 6.64,20 8.47,18.5C9.61,18.83 10.81,19 12,19C17.5,19 22,15.42 22,11C22,6.58 17.5,3 12,3Z',
	other: 'M16,12A2,2 0 0,1 18,10A2,2 0 0,1 20,12A2,2 0 0,1 18,14A2,2 0 0,1 16,12M10,12A2,2 0 0,1 12,10A2,2 0 0,1 14,12A2,2 0 0,1 12,14A2,2 0 0,1 10,12M4,12A2,2 0 0,1 6,10A2,2 0 0,1 8,12A2,2 0 0,1 6,14A2,2 0 0,1 4,12Z',
}

const svg = (path) => `<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="${path}"/></svg>`

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
	emits: ['select'],
	computed: {
		cards() {
			const order = ['user', 'group', 'link', 'email', 'federated', 'talk', 'other']
			// "Total" opens the full list (no type filter).
			const cards = [{
				key: 'total',
				label: t('share_audit_dashboard', 'Total shares'),
				count: this.total,
				icon: svg(ICONS.total),
				types: null,
				clickable: true,
			}]
			for (const key of order) {
				const count = this.byType[key] ?? 0
				// Hide empty categories to reduce noise.
				if (count === 0) {
					continue
				}
				// Only categories with a known type mapping can be filtered.
				const types = TYPE_MAP[key] ?? null
				cards.push({
					key,
					label: categoryLabel(key),
					count,
					icon: svg(ICONS[key]),
					types,
					clickable: types !== null,
					title: key === 'other' ? otherCategoryHint() : undefined,
				})
			}
			return cards
		},
	},
	methods: {
		onSelect(card) {
			this.$emit('select', card.types)
		},
	},
}
</script>

<style scoped lang="scss">
.sad-cards {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin: 8px 0;
}

.sad-card {
	display: flex;
	align-items: center;
	gap: 12px;
	flex: 1 1 150px;
	min-width: 140px;
	max-width: 220px;
	padding: 12px 16px;
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover);
}

.sad-card--total {
	background-color: var(--color-primary-element-light, var(--color-background-dark));
}

.sad-card--clickable {
	cursor: pointer;
	transition: background-color 0.1s ease, box-shadow 0.1s ease;

	&:hover,
	&:focus-visible {
		background-color: var(--color-background-dark);
		box-shadow: 0 0 0 2px var(--color-primary-element);
		outline: none;
	}
}

.sad-card__icon {
	display: inline-flex;
	color: var(--color-primary-element);
	opacity: 0.85;
}

.sad-card__body {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.sad-card__count {
	font-size: 24px;
	font-weight: 600;
	line-height: 1.1;
}

.sad-card__label {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
