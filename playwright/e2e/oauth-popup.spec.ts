/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { login } from '@nextcloud/e2e-test-server/playwright'
import { test as base, expect } from '@playwright/test'

// the test container always has this admin user
const admin = { userId: 'admin', password: 'admin' }

// Every test also fails on an uncaught exception, or on an unexpected failing request to one of the app's own routes.
// Errors of other apps on the instance are ignored on purpose.
const test = base.extend<{ appErrors: void }>({
	appErrors: [async ({ page }, use) => {
		const errors: string[] = []
		page.on('pageerror', (error) => errors.push(`uncaught: ${error.message}`))
		page.on('response', (response) => {
			if (response.status() >= 400 && response.url().includes('/integration_onedrive/')) {
				errors.push(`${response.status()} ${response.request().method()} ${new URL(response.url()).pathname}`)
			}
		})
		await use()
		expect(errors).toEqual([])
	}, { auto: true }],
})

test.beforeEach(async ({ page }) => {
	await login(page.request, admin)
})

test.describe('OAuth popup', () => {
	test('load the page that closes the authentication popup', async ({ page }) => {
		// the page runs the popupSuccess bundle, which passes the account name to the window that opened it
		const messages: string[] = []
		await page.exposeFunction('reportMessage', (name: string) => messages.push(name))
		await page.addInitScript(() => {
			// pretend the page was opened from the settings, the bundle only posts a message then
			Object.defineProperty(window, 'opener', {
				value: {
					postMessage: (data: { username: string }) => (window as unknown as { reportMessage: (name: string) => void }).reportMessage(data.username),
				},
			})
			// the bundle closes the popup right after, which would end the test
			window.close = () => {}
		})

		await page.goto('apps/integration_onedrive/popup-success?username=jane.doe%40example.com')

		await expect.poll(() => messages).toEqual(['jane.doe@example.com'])
	})
})
