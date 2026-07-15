<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="sad-settings">
		<NcLoadingIcon v-if="loading" :size="32" class="sad-loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<p class="settings-hint">
				{{ t('share_audit_dashboard', 'Configure which public-link risks are flagged as security alerts.') }}
			</p>

			<section class="sad-settings__block">
				<h3>{{ t('share_audit_dashboard', 'Active alert rules') }}</h3>
				<NcCheckboxRadioSwitch v-model="rules.no_password" type="switch">
					{{ t('share_audit_dashboard', 'Public link without a password') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="rules.no_expiration" type="switch">
					{{ t('share_audit_dashboard', 'Public link without an expiration date') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="rules.sensitive_file" type="switch">
					{{ t('share_audit_dashboard', 'Public link exposing a sensitive file type') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="rules.public_upload" type="switch">
					{{ t('share_audit_dashboard', 'Public link open for anonymous upload without a password') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="rules.group_share_editable" type="switch">
					{{ t('share_audit_dashboard', 'Large group with edit or reshare permission') }}
				</NcCheckboxRadioSwitch>
			</section>

			<section class="sad-settings__block">
				<h3>{{ t('share_audit_dashboard', 'Sensitive file extensions') }}</h3>
				<p class="settings-hint">
					{{ t('share_audit_dashboard', 'Comma-separated list of extensions treated as sensitive.') }}
				</p>
				<NcTextField v-model="sensitiveExtensions"
					class="sad-settings__ext"
					:label="t('share_audit_dashboard', 'Extensions')"
					:disabled="!rules.sensitive_file" />
			</section>

			<section class="sad-settings__block">
				<h3>{{ t('share_audit_dashboard', 'Large group threshold') }}</h3>
				<p class="settings-hint">
					{{ t('share_audit_dashboard', 'Group shares with edit or reshare permission are flagged when the group has at least this many members.') }}
				</p>
				<NcTextField v-model.number="groupShareMinMembers"
					type="number"
					class="sad-settings__ext"
					:label="t('share_audit_dashboard', 'Members')"
					:disabled="!rules.group_share_editable" />
			</section>

			<section class="sad-settings__block">
				<h3>{{ t('share_audit_dashboard', 'Personal view') }}</h3>
				<p class="settings-hint">
					{{ t('share_audit_dashboard',
						'Lets every user audit and fix their own shares under Settings → Personal, and see a dashboard widget for links that need attention.') }}
				</p>
				<NcCheckboxRadioSwitch v-model="personalViewEnabled" type="switch">
					{{ t('share_audit_dashboard', 'Enable the personal audit view for every user') }}
				</NcCheckboxRadioSwitch>
			</section>

			<div class="sad-settings__actions">
				<NcButton type="primary" :disabled="saving" @click="save">
					{{ saving ? t('share_audit_dashboard', 'Saving…') : t('share_audit_dashboard', 'Save') }}
				</NcButton>
				<span v-if="saved" class="sad-settings__saved">
					{{ t('share_audit_dashboard', 'Saved') }}
				</span>
			</div>

			<NcNoteCard v-if="saveError" type="error" class="sad-settings__error">
				{{ saveError }}
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { fetchSettings, saveSettings } from '../services/api.js'

export default {
	name: 'Settings',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},
	emits: ['saved'],
	data() {
		return {
			loading: true,
			error: null,
			saving: false,
			saved: false,
			saveError: null,
			sensitiveExtensions: '',
			groupShareMinMembers: 20,
			rules: {
				no_password: true,
				no_expiration: true,
				sensitive_file: true,
				group_share_editable: true,
				public_upload: true,
			},
			personalViewEnabled: true,
		}
	},
	async mounted() {
		try {
			const data = await fetchSettings()
			this.sensitiveExtensions = data.sensitiveExtensions
			this.groupShareMinMembers = data.groupShareMinMembers ?? this.groupShareMinMembers
			this.rules = { ...this.rules, ...data.rules }
			this.personalViewEnabled = data.personalViewEnabled
		} catch (e) {
			this.error = t('share_audit_dashboard', 'Could not load settings.')
		} finally {
			this.loading = false
		}
	},
	methods: {
		t,
		async save() {
			this.saving = true
			this.saved = false
			this.saveError = null
			try {
				await saveSettings({
					sensitiveExtensions: this.sensitiveExtensions,
					ruleNoPassword: this.rules.no_password,
					ruleNoExpiration: this.rules.no_expiration,
					ruleSensitiveFile: this.rules.sensitive_file,
					ruleGroupShareEditable: this.rules.group_share_editable,
					rulePublicUpload: this.rules.public_upload,
					personalViewEnabled: this.personalViewEnabled,
					groupShareMinMembers: this.groupShareMinMembers,
				})
				this.saved = true
				this.$emit('saved')
				setTimeout(() => { this.saved = false }, 2500)
			} catch (e) {
				this.saveError = t('share_audit_dashboard', 'Could not save settings.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.sad-settings {
	max-width: 640px;
}

.sad-settings__block {
	margin: 20px 0;

	h3 {
		font-size: 15px;
		margin: 0 0 8px;
	}
}

.sad-settings__ext {
	max-width: 480px;
}

.sad-settings__actions {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 20px;
}

.sad-settings__saved {
	color: var(--color-success-text, var(--color-success));
	font-weight: 600;
}

.sad-settings__error {
	margin-top: 12px;
}
</style>
