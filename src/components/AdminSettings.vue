<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div id="onedrive_prefs" class="section">
		<h2>
			<a class="icon icon-onedrive" />
			{{ t('integration_onedrive', 'Microsoft OneDrive integration') }}
		</h2>
		<p class="settings-hint">
			{{ t('integration_onedrive', 'If you want to allow your Nextcloud users to use OAuth to authenticate to https://onedrive.live.com, create an OAuth application in your Azure settings.') }}
			<a href="https://aka.ms/AppRegistrations/?referrer=https%3A%2F%2Fdev.onedrive.com" class="external" target="_blank">{{ t('integration_onedrive', 'Azure App registrations page') }}</a>
			<br>
			{{ t('integration_onedrive', 'Set "Application name" to a value that will make sense to your Nextcloud users as they will see it when connecting to OneDrive using your OAuth app.') }}
			<br><br>
			<span class="icon icon-details" />
			{{ t('integration_onedrive', 'Make sure you set the "Redirect URI" to') }}
			<b> {{ redirect_uri }} </b>
			<br><br>
			{{ t('integration_onedrive', 'Give the "Contacts.Read", "Calendars.Read", "MailboxSettings.Read", "Files.Read" and "User.Read" API permission to your app.') }}
			<br>
			{{ t('integration_onedrive', 'Create a client secret in "Certificates & secrets".') }}
			{{ t('integration_onedrive', 'Put the OAuth app "Client ID" and "Client secret" below.') }}
			{{ t('integration_onedrive', 'Your Nextcloud users will then see a "Connect to OneDrive" button in their personal settings.') }}
		</p>
		<div class="grid-form">
			<label for="onedrive-client-id">
				<a class="icon icon-category-auth" />
				{{ t('integration_onedrive', 'Client ID') }}
			</label>
			<input id="onedrive-client-id"
				v-model="state.client_id"
				type="password"
				:readonly="readonly"
				:placeholder="t('integration_onedrive', 'Client ID of your OneDrive application')"
				@focus="readonly = false"
				@input="onInput">
			<label for="onedrive-client-secret">
				<a class="icon icon-category-auth" />
				{{ t('integration_onedrive', 'Client secret') }}
			</label>
			<input id="onedrive-client-secret"
				v-model="state.client_secret"
				type="password"
				:readonly="readonly"
				:placeholder="t('integration_onedrive', 'Client secret of your OneDrive application')"
				@input="onInput"
				@focus="readonly = false">
			<NcFormBoxSwitch
				v-model="state.use_popup"
				@update:model-value="onUsePopupChanged">
				{{ t('integration_google', 'Use a popup to authenticate') }}
			</NcFormBoxSwitch>
		</div>
	</div>
</template>

<script>
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'

export default {
	name: 'AdminSettings',

	components: {
		NcFormBoxSwitch,
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
.grid-form label {
	line-height: 38px;
}

.grid-form input {
	width: 100%;
}

.grid-form {
	max-width: 500px;
	display: grid;
	grid-template: 1fr / 1fr 1fr;
	margin-left: 30px;
}

#onedrive_prefs .icon {
	display: inline-block;
	width: 32px;
}

#onedrive_prefs .grid-form .icon {
	margin-bottom: -3px;
}

.icon-onedrive {
	background-image: url('../../img/app-dark.svg');
	background-size: 23px 23px;
	height: 23px;
	margin-bottom: -4px;
	filter: var(--background-invert-if-dark);
}

body.theme--dark .icon-onedrive {
	background-image: url('../../img/app.svg');
}

</style>
