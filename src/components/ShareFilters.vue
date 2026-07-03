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
			:input-label="t('share_audit_dashboard', 'Share type')"
			@update:model-value="emitFilters" />

		<NcSelect class="sad-filters__flag"
			v-model="password"
			:options="triStateOptions"
			:clearable="false"
			:input-label="t('share_audit_dashboard', 'Password')"
			@update:model-value="emitFilters" />

		<NcSelect class="sad-filters__flag"
			v-model="expiration"
			:options="triStateOptions"
			:clearable="false"
			:input-label="t('share_audit_dashboard', 'Expiration')"
			@update:model-value="emitFilters" />

		<NcButton type="tertiary" @click="reset">
			{{ t('share_audit_dashboard', 'Clear') }}
		</NcButton>
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
			triStateOptions: [
				{ id: '', label: t('share_audit_dashboard', 'Any') },
				{ id: 'true', label: t('share_audit_dashboard', 'Yes') },
				{ id: 'false', label: t('share_audit_dashboard', 'No') },
			],
			password: null,
			expiration: null,
			searchTimer: null,
		}
	},
	created() {
		this.password = this.triStateOptions[0]
		this.expiration = this.triStateOptions[0]
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
			this.password = this.triStateOptions[0]
			this.expiration = this.triStateOptions[0]
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
	align-items: flex-end;
	gap: 12px;
	margin-bottom: 16px;
}

.sad-filters__search {
	min-width: 220px;
	max-width: 300px;
}

.sad-filters__types {
	min-width: 200px;
}

.sad-filters__flag {
	min-width: 130px;
}
</style>
