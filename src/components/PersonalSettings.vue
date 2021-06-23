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
		<br>
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
			<div v-else>
				<div class="onedrive-grid-form">
					<label class="onedrive-connected">
						<a class="icon icon-checkmark-color" />
						{{ t('integration_onedrive', 'Connected as {user}', { user: state.user_name }) }}
					</label>
					<button id="onedrive-rm-cred" @click="onLogoutClick">
						<span class="icon icon-close" />
						{{ t('integration_onedrive', 'Disconnect from OneDrive') }}
					</button>
				</div>
				<br>
				<div v-if="storageSize > 0" id="import-storage">
					<h3>{{ t('integration_onedrive', 'Onedrive storage') }}</h3>
					<div v-if="!importingOnedrive" class="output-selection">
						<label for="onedrive-output">
							<span class="icon icon-folder" />
							{{ t('integration_onedrive', 'Import directory') }}
						</label>
						<input id="onedrive-output"
							:readonly="true"
							:value="state.onedrive_output_dir">
						<button class="edit-output-dir"
							@click="onOnedriveOutputChange">
							<span class="icon-rename" />
						</button>
						<br><br>
					</div>
					<label>
						<span class="icon icon-folder" />
						{{ t('integration_onedrive', 'Onedrive storage ({formSize})', { formSize: myHumanFileSize(storageSize, true) }) }}
					</label>
					<button v-if="enoughSpaceForOnedrive && !importingOnedrive"
						id="onedrive-import-files"
						@click="onImportOnedrive">
						<span class="icon icon-files-dark" />
						{{ t('integration_onedrive', 'Import Onedrive files') }}
					</button>
					<span v-else-if="!enoughSpaceForOnedrive">
						{{ t('integration_onedrive', 'Your Onedrive storage is bigger than your remaining space left ({formSpace})', { formSpace: myHumanFileSize(state.free_space) }) }}
					</span>
					<div v-else>
						<br>
						{{ n('integration_onedrive', '{amount} file imported ({formImported}) ({progress}%)', '{amount} files imported ({formImported}) ({progress}%)', nbImportedFiles, { amount: nbImportedFiles, formImported: myHumanFileSize(importedSize), progress: onedriveImportProgress }) }}
						<br>
						{{ jobRunningText }}
						<br>
						{{ lastOnedriveImportDate }}
						<br>
						<button @click="onCancelOnedriveImport">
							<span class="icon icon-close" />
							{{ t('integration_onedrive', 'Cancel Onedrive files import') }}
						</button>
					</div>
				</div>
				<div v-if="nbContacts > 0"
					id="onedrive-contacts">
					<h3>{{ t('integration_onedrive', 'Contacts') }}</h3>
					<label>
						<span class="icon icon-menu-sidebar" />
						{{ t('integration_onedrive', '{amount} contacts', { amount: nbContacts }) }}
					</label>
					<button id="onedrive-import-contacts"
						:class="{ loading: importingContacts }"
						@click="onImportContacts">
						<span class="icon icon-contacts-dark" />
						{{ t('integration_onedrive', 'Import Contacts in Nextcloud') }}
					</button>
					<br>
				</div>
				<div v-if="calendars.length > 0"
					id="onedrive-calendars">
					<h3>{{ t('integration_onedrive', 'Calendars') }}</h3>
					<div v-for="cal in calendars" :key="cal.id" class="onedrive-grid-form">
						<label>
							<AppNavigationIconBullet slot="icon" :color="getCalendarColor(cal)" />
							{{ getCalendarLabel(cal) }}
						</label>
						<button
							:class="{ loading: importingCalendar[cal.id] }"
							@click="onCalendarImport(cal)">
							<span class="icon icon-calendar-dark" />
							{{ t('integration_onedrive', 'Import calendar') }}
						</button>
					</div>
					<br>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { humanFileSize } from '../utils'
import { showSuccess, showError } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/styles/toast.scss'
import AppNavigationIconBullet from '@nextcloud/vue/dist/Components/AppNavigationIconBullet'
import moment from '@nextcloud/moment'

export default {
	name: 'PersonalSettings',

	components: {
		AppNavigationIconBullet,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_onedrive', 'user-config'),
			readonly: true,
			redirect_uri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_onedrive/oauth-redirect'),
			// onedrive import stuff
			storageSize: 0,
			importingOnedrive: false,
			jobRunning: false,
			lastOnedriveImportTimestamp: 0,
			importedSize: 0,
			nbImportedFiles: 0,
			onedriveImportLoop: null,
			// calendars
			calendars: [],
			importingCalendar: {},
			// contacts
			nbContacts: 0,
			importingContacts: false,
		}
	},

	computed: {
		showOAuth() {
			return this.state.client_id && this.state.client_secret
		},
		connected() {
			return this.state.user_name && this.state.user_name !== ''
		},
		enoughSpaceForOnedrive() {
			return this.storageSize === 0 || this.state.user_quota === 'none' || this.storageSize < this.state.free_space
		},
		jobRunningText() {
			return this.jobRunning
				? t('integration_onedrive', 'Import job is currently running')
				: t('integration_onedrive', 'Import job is scheduled')
		},
		lastOnedriveImportDate() {
			return this.lastOnedriveImportTimestamp !== 0
				? t('integration_onedrive', 'Last Onedrive import job at {date}', { date: moment.unix(this.lastOnedriveImportTimestamp).format('LLL') })
				: t('integration_onedrive', 'Onedrive import process will begin soon')
		},
		onedriveImportProgress() {
			return this.storageSize > 0 && this.importedSize > 0
				? parseInt(this.importedSize / this.storageSize * 100)
				: 0
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

		if (this.connected) {
			this.getStorageInfo()
			this.getOnedriveImportValues(true)
			this.getCalendarList()
			this.getNbContacts()
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
			const scopes = [
				'Files.Read',
				'User.Read',
				'Calendars.Read',
				'Contacts.Read',
				'MailboxSettings.Read',
				'offline_access',
			]
			const requestUrl = 'https://login.live.com/oauth20_authorize.srf'
				+ '?client_id=' + encodeURIComponent(this.state.client_id)
				+ '&response_type=code'
				+ '&redirect_uri=' + encodeURIComponent(this.redirect_uri)
				// doc mentions onedrive.readwrite, i fought quite some time to find those working scopes
				+ '&scope=' + encodeURIComponent(scopes.join(' '))

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
		getStorageInfo() {
			const url = generateUrl('/apps/integration_onedrive/storage-size')
			axios.get(url)
				.then((response) => {
					if (response.data?.usageInStorage) {
						this.storageSize = response.data.usageInStorage
					}
				})
				.catch((error) => {
					showError(
						t('integration_onedrive', 'Failed to get OneDrive storage information')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
				})
		},
		getOnedriveImportValues(launchLoop = false) {
			const url = generateUrl('/apps/integration_onedrive/import-files-info')
			axios.get(url)
				.then((response) => {
					if (response.data && Object.keys(response.data).length > 0) {
						this.lastOnedriveImportTimestamp = response.data.last_onedrive_import_timestamp
						this.importedSize = response.data.imported_size
						this.nbImportedFiles = response.data.nb_imported_files
						this.importingOnedrive = response.data.importing_onedrive
						this.jobRunning = response.data.onedrive_import_running
						if (!this.importingOnedrive) {
							clearInterval(this.onedriveImportLoop)
						} else if (launchLoop) {
							// launch loop if we are currently importing AND it's the first time we call getOnedriveImportValues
							this.onedriveImportLoop = setInterval(() => this.getOnedriveImportValues(), 10000)
						}
					}
				})
				.catch((error) => {
					console.debug(error)
				})
				.then(() => {
				})
		},
		onImportOnedrive() {
			const req = {
				params: {
				},
			}
			const url = generateUrl('/apps/integration_onedrive/import-files')
			axios.get(url, req)
				.then((response) => {
					const targetPath = response.data.targetPath
					showSuccess(
						t('integration_onedrive', 'Starting importing files in {targetPath} directory', { targetPath })
					)
					this.getOnedriveImportValues(true)
				})
				.catch((error) => {
					showError(
						t('integration_onedrive', 'Failed to start importing Onedrive storage')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
				})
		},
		onCancelOnedriveImport() {
			this.importingOnedrive = false
			clearInterval(this.onedriveImportLoop)
			const req = {
				values: {
					importing_onedrive: '0',
					last_onedrive_import_timestamp: '0',
					imported_size: '0',
				},
			}
			const url = generateUrl('/apps/integration_onedrive/config')
			axios.put(url, req)
				.then((response) => {
				})
				.catch((error) => {
					console.debug(error)
				})
				.then(() => {
				})
		},
		myHumanFileSize(bytes, approx = false, si = false, dp = 1) {
			return humanFileSize(bytes, approx, si, dp)
		},
		// ########## calendars ##########
		getCalendarList() {
			const url = generateUrl('/apps/integration_onedrive/calendars')
			axios.get(url)
				.then((response) => {
					if (response.data && response.data.length && response.data.length > 0) {
						this.calendars = response.data
					}
				})
				.catch((error) => {
					showError(
						t('integration_onedrive', 'Failed to get calendar list')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
				})
		},
		getCalendarLabel(cal) {
			return cal.name
		},
		getCalendarColor(cal) {
			return cal.hexColor
				? cal.hexColor.replace('#', '')
				: '0082c9'
		},
		onCalendarImport(cal) {
			const calId = cal.id
			this.$set(this.importingCalendar, calId, true)
			const req = {
				params: {
					calId,
					calName: this.getCalendarLabel(cal),
					color: cal.hexColor || '#0082c9',
				},
			}
			const url = generateUrl('/apps/integration_onedrive/import-calendar')
			axios.get(url, req)
				.then((response) => {
					const nbAdded = response.data.nbAdded
					const calName = response.data.calName
					showSuccess(
						this.n('integration_onedrive', '{number} event successfully imported in {name}', '{number} events successfully imported in {name}', nbAdded, { number: nbAdded, name: calName })
					)
				})
				.catch((error) => {
					showError(
						t('integration_onedrive', 'Failed to import calendar')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
					this.$set(this.importingCalendar, calId, false)
				})
		},
		// ########## contacts ##########
		getNbContacts() {
			const url = generateUrl('/apps/integration_onedrive/contact-number')
			axios.get(url)
				.then((response) => {
					if (response.data && Object.keys(response.data).length > 0) {
						this.nbContacts = response.data.nbContacts
					}
				})
				.catch((error) => {
					showError(
						t('integration_onedrive', 'Failed to get number of contacts')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
				})
		},
		onImportContacts() {
			this.importingContacts = true
			const url = generateUrl('/apps/integration_onedrive/import-contacts')
			axios.get(url)
				.then((response) => {
					const nbAdded = response.data.nbAdded
					showSuccess(
						this.n('integration_onedrive', '{number} contact successfully imported', '{number} contacts successfully imported', nbAdded, { number: nbAdded })
					)
				})
				.catch((error) => {
					showError(
						t('integration_onedrive', 'Failed to get address book list')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
					this.importingContacts = false
				})
		},
		onOnedriveOutputChange() {
			OC.dialogs.filepicker(
				t('integration_onedrive', 'Choose where to write imported files'),
				(targetPath) => {
					if (targetPath === '') {
						targetPath = '/'
					}
					this.state.onedrive_output_dir = targetPath
					this.saveOptions({ onedrive_output_dir: this.state.onedrive_output_dir })
				},
				false,
				'httpd/unix-directory',
				true
			)
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

#onedrive_prefs .onedrive-grid-form .icon {
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

	h3 {
		font-weight: bold;
	}

	#onedrive-contacts > button,
	#import-storage > button {
		width: 300px;
	}

	#onedrive-contacts > label,
	#import-storage > label {
		width: 300px;
		display: inline-block;

		span {
			margin-bottom: -2px;
		}
	}

	.contact-input {
		width: 200px;
	}

	.output-selection {
		display: flex;

		label,
		input {
			width: 300px;
		}
		button {
			width: 44px !important;
		}

		>label span {
			margin-bottom: -2px;
		}
	}

	.edit-output-dir {
		padding: 6px 6px;
	}
}

#onedrive-search-block .icon {
	width: 22px;
}

#toggle-onedrive-navigation-link {
	margin-left: 40px;
}

::v-deep .app-navigation-entry__icon-bullet {
	display: inline-block;
	padding: 0;
	height: 12px;
	margin: 0 8px 0 10px;
}
</style>
