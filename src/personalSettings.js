/* jshint esversion: 6 */

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import Vue from 'vue'
import './bootstrap.js'
import PersonalSettings from './components/PersonalSettings.vue'

// eslint-disable-next-line
'use strict'

// eslint-disable-next-line
new Vue({
	el: '#onedrive_prefs',
	render: h => h(PersonalSettings),
})
