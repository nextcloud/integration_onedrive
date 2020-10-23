<template>
	<div id="onedrive_prefs" class="section">
		<h2>
			<a class="icon icon-onedrive-settings" />
			{{ t('integration_onedrive', 'Microsoft OneDrive integration') }}
		</h2>
		<div id="toggle-onedrive-navigation-link">
			<input
				id="onedrive-link"
				type="checkbox"
				class="checkbox"
				:checked="state.navigation_enabled"
				@input="onNavigationChange">
			<label for="onedrive-link">{{ t('integration_onedrive', 'Enable navigation link') }}</label>
		</div>
		<br><br>
		<p v-if="!showOAuth && !connected" class="settings-hint">
			{{ t('integration_onedrive', 'Ask your Nextcloud administrator to configure OneDrive OAuth settings in order to use this integration.') }}
		</p>
		<div v-if="showOAuth" id="onedrive-content">
			<button v-if="!connected"
				id="onedrive-oauth"
				@click="onOAuthClick">
				<span class="icon icon-external" />
				{{ t('integration_onedrive', 'Connect to OneDrive') }}
			</button>
			<div v-else
				class="onedrive-grid-form">
				<label class="onedrive-connected">
					<a class="icon icon-checkmark-color" />
					{{ t('integration_onedrive', 'Connected as {user}', { user: state.user_name }) }}
				</label>
				<button id="onedrive-rm-cred" @click="onLogoutClick">
					<span class="icon icon-close" />
					{{ t('integration_onedrive', 'Disconnect from OneDrive') }}
				</button>
				<span />
			</div>
			<br>
			<div v-if="connected" id="onedrive-import-block">
				plop connected
			</div>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'PersonalSettings',

	components: {
	},

	props: [],

	data() {
		return {
			state: loadState('integration_onedrive', 'user-config'),
			readonly: true,
			redirect_uri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_onedrive/oauth-redirect'),
		}
	},

	computed: {
		showOAuth() {
			return this.state.client_id && this.state.client_secret
		},
		connected() {
			return this.state.user_name && this.state.user_name !== ''
		},
	},

	watch: {
	},

	mounted() {
		const paramString = window.location.search.substr(1)
		// eslint-disable-next-line
		const urlParams = new URLSearchParams(paramString)
		const ghToken = urlParams.get('onedriveToken')
		if (ghToken === 'success') {
			showSuccess(t('integration_onedrive', 'Connected to OneDrive!'))
		} else if (ghToken === 'error') {
			showError(t('integration_onedrive', 'OneDrive OAuth error:') + ' ' + urlParams.get('message'))
		}
	},

	methods: {
		onLogoutClick() {
			this.state.user_name = ''
			this.saveOptions({ user_name: this.state.user_name })
		},
		onNavigationChange(e) {
			this.state.navigation_enabled = e.target.checked
			this.saveOptions({ navigation_enabled: this.state.navigation_enabled ? '1' : '0' })
		},
		saveOptions(values) {
			const req = {
				values,
			}
			const url = generateUrl('/apps/integration_onedrive/config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_onedrive', 'OneDrive options saved'))
					if (response.data.user_name !== undefined) {
						this.state.user_name = response.data.user_name
					}
				})
				.catch((error) => {
					showError(
						t('integration_onedrive', 'Failed to save OneDrive options')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
				})
		},
		onOAuthClick() {
			// const oauthState = Math.random().toString(36).substring(3)
			const requestUrl = 'https://login.live.com/oauth20_authorize.srf'
				+ '?client_id=' + encodeURIComponent(this.state.client_id)
				+ '&response_type=code'
				+ '&redirect_uri=' + encodeURIComponent(this.redirect_uri)
				// + '&state=' + encodeURIComponent(oauthState)
				+ '&scope=' + encodeURIComponent('onedrive.readwrite offline_access')

			const req = {
				values: {
					// oauth_state: oauthState,
					redirect_uri: this.redirect_uri,
				},
			}
			const url = generateUrl('/apps/integration_onedrive/config')
			axios.put(url, req)
				.then((response) => {
					window.location.replace(requestUrl)
				})
				.catch((error) => {
					showError(
						t('integration_onedrive', 'Failed to save OneDrive OAuth state')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
				})
		},
	},
}
</script>

<style scoped lang="scss">
.onedrive-grid-form label {
	line-height: 38px;
}

.onedrive-grid-form input {
	width: 100%;
}

.onedrive-grid-form {
	max-width: 600px;
	display: grid;
	grid-template: 1fr / 1fr 1fr;
	button .icon {
		margin-bottom: -1px;
	}
}

#onedrive_prefs .icon {
	display: inline-block;
	width: 32px;
}

#onedrive_prefs .grid-form .icon {
	margin-bottom: -3px;
}

.icon-onedrive-settings {
	background-image: url('./../../img/app-dark.svg');
	background-size: 23px 23px;
	height: 23px;
	margin-bottom: -4px;
}

body.theme--dark .icon-onedrive-settings {
	background-image: url('./../../img/app.svg');
}

#onedrive-content {
	margin-left: 40px;
}

#onedrive-search-block .icon {
	width: 22px;
}

#toggle-onedrive-navigation-link {
	margin-left: 40px;
}

</style>
