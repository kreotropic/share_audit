<template>
	<div class="sad-filters">
		<NcTextField class="sad-filters__search"
			v-model="search"
			:label="t('share_audit_dashboard', 'Search path or recipient')"
			:show-trailing-button="search !== ''"
			@update:model-value="onSearchInput"
			@trailing-button-click="clearSearch" />

		<NcSelect class="sad-filters__types"
			v-model="selectedTypes"
			:options="typeOptions"
			:multiple="true"
			:close-on-select="false"
			:placeholder="t('share_audit_dashboard', 'All types')"
			:aria-label-combobox="t('share_audit_dashboard', 'Share type')"
			@update:model-value="emitFilters" />

		<NcSelect class="sad-filters__flag"
			v-model="password"
			:options="passwordOptions"
			:clearable="false"
			:aria-label-combobox="t('share_audit_dashboard', 'Password')"
			@update:model-value="emitFilters" />

		<NcSelect class="sad-filters__flag"
			v-model="expiration"
			:options="expirationOptions"
			:clearable="false"
			:aria-label-combobox="t('share_audit_dashboard', 'Expiration')"
			@update:model-value="emitFilters" />

		<NcButton type="tertiary" @click="reset">
			{{ t('share_audit_dashboard', 'Clear') }}
		</NcButton>

		<span class="sad-filters__trailing">
			<slot name="trailing" />
		</span>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { typeFilterOptions } from '../utils/format.js'

export default {
	name: 'ShareFilters',
	components: {
		NcButton,
		NcSelect,
		NcTextField,
	},
	emits: ['update'],
	data() {
		return {
			search: '',
			selectedTypes: [],
			typeOptions: typeFilterOptions(),
			passwordOptions: [
				{ id: '', label: t('share_audit_dashboard', 'Any password') },
				{ id: 'true', label: t('share_audit_dashboard', 'With password') },
				{ id: 'false', label: t('share_audit_dashboard', 'Without password') },
			],
			expirationOptions: [
				{ id: '', label: t('share_audit_dashboard', 'Any expiration') },
				{ id: 'true', label: t('share_audit_dashboard', 'With expiration') },
				{ id: 'false', label: t('share_audit_dashboard', 'Without expiration') },
			],
			password: null,
			expiration: null,
			searchTimer: null,
		}
	},
	created() {
		this.password = this.passwordOptions[0]
		this.expiration = this.expirationOptions[0]
	},
	methods: {
		t,
		onSearchInput() {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(this.emitFilters, 400)
		},
		clearSearch() {
			this.search = ''
			this.emitFilters()
		},
		reset() {
			this.search = ''
			this.selectedTypes = []
			this.password = this.passwordOptions[0]
			this.expiration = this.expirationOptions[0]
			this.emitFilters()
		},
		emitFilters() {
			const types = this.selectedTypes.flatMap((opt) => opt.types)
			this.$emit('update', {
				search: this.search.trim(),
				types: types.join(','),
				hasPassword: this.password?.id ?? '',
				hasExpiration: this.expiration?.id ?? '',
			})
		},
	},
}
</script>

<style scoped lang="scss">
.sad-filters {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.sad-filters__search {
	width: 240px;
}

.sad-filters__types {
	width: 190px;
}

.sad-filters__flag {
	width: 205px;
}

// NcSelect ships with a wide default min-width; override it so the whole
// filter bar (incl. Clear + Export) fits on a single row. Width is set by the
// wrapper classes above.
.sad-filters :deep(.v-select) {
	min-width: 0;
}

.sad-filters__trailing {
	margin-left: auto;
}
</style>
