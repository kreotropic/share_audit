<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<nav class="sad-page-nav" :aria-label="t('share_audit_dashboard', 'Pagination')">
		<NcButton :disabled="disabled || page <= 1" @click="go(page - 1)">
			{{ t('share_audit_dashboard', 'Previous') }}
		</NcButton>

		<div class="sad-page-nav__pages">
			<NcButton v-for="number in directPages"
				:key="number"
				:disabled="disabled || number === page"
				:variant="number === page ? 'primary' : 'tertiary'"
				:aria-current="number === page ? 'page' : null"
				:aria-label="n('share_audit_dashboard', 'Page %n', 'Page %n', number)"
				@click="go(number)">
				{{ number }}
			</NcButton>

			<form v-if="showJump" class="sad-page-nav__jump" @submit.prevent="submitJump">
				<input v-model="jumpValue"
					class="sad-page-nav__input"
					type="number"
					inputmode="numeric"
					:min="1"
					:max="totalPages"
					:disabled="disabled"
					:aria-label="t('share_audit_dashboard', 'Go to page')"
					@blur="resetJump">
			</form>

			<NcButton v-for="number in trailingPages"
				:key="number"
				:disabled="disabled || number === page"
				:variant="number === page ? 'primary' : 'tertiary'"
				:aria-current="number === page ? 'page' : null"
				:aria-label="n('share_audit_dashboard', 'Page %n', 'Page %n', number)"
				@click="go(number)">
				{{ number }}
			</NcButton>
		</div>

		<NcButton :disabled="disabled || page >= totalPages" @click="go(page + 1)">
			{{ t('share_audit_dashboard', 'Next') }}
		</NcButton>
	</nav>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

export default {
	name: 'PageNavigation',
	components: {
		NcButton,
	},
	props: {
		page: {
			type: Number,
			required: true,
		},
		totalPages: {
			type: Number,
			required: true,
		},
		disabled: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['change'],
	data() {
		return {
			jumpValue: String(this.page),
		}
	},
	computed: {
		showJump() {
			return this.totalPages > 5
		},
		directPages() {
			if (!this.showJump) {
				return Array.from({ length: this.totalPages }, (_, i) => i + 1)
			}
			return [1, 2]
		},
		trailingPages() {
			if (!this.showJump) {
				return []
			}
			return [this.totalPages - 1, this.totalPages]
		},
	},
	watch: {
		page() {
			this.resetJump()
		},
		totalPages() {
			this.resetJump()
		},
	},
	methods: {
		t,
		n,
		go(page) {
			const target = Number(page)
			if (!Number.isInteger(target) || target < 1 || target > this.totalPages || target === this.page) {
				this.resetJump()
				return
			}
			this.$emit('change', target)
		},
		submitJump() {
			this.go(Number(this.jumpValue))
		},
		resetJump() {
			this.jumpValue = String(this.page)
		},
	},
}
</script>

<style scoped lang="scss">
.sad-page-nav,
.sad-page-nav__pages {
	display: flex;
	align-items: center;
	gap: 8px;
}

.sad-page-nav__jump {
	margin: 0;
}

.sad-page-nav__input {
	width: 72px;
	height: 34px;
	padding: 0 8px;
	text-align: center;
}

@media (max-width: 600px) {
	.sad-page-nav,
	.sad-page-nav__pages {
		flex-wrap: wrap;
	}
}
</style>
