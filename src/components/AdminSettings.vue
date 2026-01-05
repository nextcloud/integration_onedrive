<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div id="onedrive_prefs" class="section">
		<h2>
			<OnedriveIcon />
			{{ t('integration_onedrive', 'Microsoft OneDrive integration') }}
		</h2>
		<div class="onedrive-content">
			<NcNoteCard type="info">
				{{ t('integration_onedrive', 'If you want to allow your Nextcloud users to use OAuth to authenticate to https://onedrive.live.com, create an OAuth application in your Azure settings.') }}
				<br>
				<a href="https://aka.ms/AppRegistrations/?referrer=https%3A%2F%2Fdev.onedrive.com" class="external" target="_blank">{{ t('integration_onedrive', 'Azure App registrations page') }}</a>
				<br><br>
				{{ t('integration_onedrive', 'Set "Application name" to a value that will make sense to your Nextcloud users as they will see it when connecting to OneDrive using your OAuth app.') }}
				<br><br>
				{{ t('integration_onedrive', 'Make sure you set the "Redirect URI" to') }}
				<br>
				<strong>{{ redirect_uri }}</strong>
				<br><br>
				{{ t('integration_onedrive', 'Give the "Contacts.Read", "Calendars.Read", "MailboxSettings.Read", "Files.Read" and "User.Read" API permission to your app.') }}
				<br>
				{{ t('integration_onedrive', 'Create a client secret in "Certificates & secrets".') }}
				{{ t('integration_onedrive', 'Put the OAuth app "Client ID" and "Client secret" below.') }}
				{{ t('integration_onedrive', 'Your Nextcloud users will then see a "Connect to OneDrive" button in their personal settings.') }}
			</NcNoteCard>
			<NcTextField
				v-model="state.client_id"
				type="password"
				:label="t('integration_onedrive', 'Client ID')"
				:placeholder="t('integration_onedrive', 'Client ID of your OneDrive application')"
				:readonly="readonly"
				:show-trailing-button="!!state.client_id"
				@trailing-button-click="state.client_id = ''; onInput()"
				@focus="readonly = false"
				@update:model-value="onInput">
				<template #icon>
					<KeyOutlineIcon :size="20" />
				</template>
			</NcTextField>
			<NcTextField
				v-model="state.client_secret"
				type="password"
				:label="t('integration_onedrive', 'Client secret')"
				:placeholder="t('integration_onedrive', 'Client secret of your OneDrive application')"
				:readonly="readonly"
				:show-trailing-button="!!state.client_secret"
				@trailing-button-click="state.client_secret = ''; onInput()"
				@focus="readonly = false"
				@update:model-value="onInput">
				<template #icon>
					<KeyOutlineIcon :size="20" />
				</template>
			</NcTextField>
			<NcFormBoxSwitch
				v-model="state.use_popup"
				@update:model-value="onUsePopupChanged">
				{{ t('integration_onedrive', 'Use a popup to authenticate') }}
			</NcFormBoxSwitch>
		</div>
	</div>
</template>

<script>
import KeyOutlineIcon from 'vue-material-design-icons/KeyOutline.vue'

import OnedriveIcon from './icons/OnedriveIcon.vue'

import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'

export default {
	name: 'AdminSettings',

	components: {
		OnedriveIcon,
		NcFormBoxSwitch,
		NcNoteCard,
		NcTextField,
		KeyOutlineIcon,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_onedrive', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
			redirect_uri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_onedrive/oauth-redirect'),
		}
	},

	watch: {
	},

	mounted() {
	},

	methods: {
		onUsePopupChanged(newValue) {
			this.saveOptions({ use_popup: newValue ? '1' : '0' })
		},
		onInput() {
			delay(() => {
				const values = {
					client_id: this.state.client_id,
				}
				if (this.state.client_secret !== 'dummySecret') {
					values.client_secret = this.state.client_secret
				}
				this.saveOptions(values, true)

			}, 2000)()
		},
		async saveOptions(values, sensitive = false) {
			if (sensitive) {
				await confirmPassword()
			}
			const req = {
				values,
			}
			const url = sensitive
				? generateUrl('/apps/integration_onedrive/sensitive-admin-config')
				: generateUrl('/apps/integration_onedrive/admin-config')

			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_onedrive', 'OneDrive admin options saved'))
				})
				.catch((error) => {
					showError(
						t('integration_onedrive', 'Failed to save OneDrive admin options')
						+ ': ' + error.response?.request?.responseText,
					)
				})
				.then(() => {
				})
		},
	},
}
</script>

<style scoped lang="scss">
#onedrive_prefs {
	h2 {
		display: flex;
		align-items: center;
		justify-content: start;
		gap: 8px;
	}
	.onedrive-content {
		max-width: 800px;
		margin-left: 40px;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}
}
</style>
