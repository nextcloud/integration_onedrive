/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { loadState } from '@nextcloud/initial-state'

const state = loadState('integration_onedrive', 'popup-data')
const username = state.user_name

if (window.opener) {
	window.opener.postMessage({ username })
	window.close()
}
