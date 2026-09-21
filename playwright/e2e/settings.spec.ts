/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Page } from '@playwright/test'

import { login } from '@nextcloud/e2e-test-server/playwright'
import { test as base, expect } from '@playwright/test'

// the test container always has this admin user
const admin = { userId: 'admin', password: 'admin' }

// Every test also fails on an uncaught exception, or on a failing request to one of the app's own routes.
// Errors of other apps on the instance are ignored on purpose.
const test = base.extend<{ appErrors: void }>({
	appErrors: [async ({ page }, use) => {
		const errors: string[] = []
		page.on('pageerror', (error) => errors.push(`uncaught: ${error.message}`))
		page.on('response', (response) => {
			if (response.status() >= 400 && response.url().includes('/integration_onedrive/')) {
				errors.push(`${response.status()} ${response.request().method()} ${response.url()}`)
			}
		})
		await use()
		expect(errors).toEqual([])
	}, { auto: true }],
})

/**
 * Flip a switch of the OneDrive section, check that the new value survives a reload, then flip it back.
 *
 * @param page the page showing the section
 * @param label the text of the switch
 * @param route the app route that stores the value
 */
async function expectSwitchToBeSaved(page: Page, label: string, route: string) {
	const section = page.locator('#onedrive_prefs')
	const toggle = async () => {
		const saved = page.waitForResponse((response) => response.url().includes(route))
		// the switch hides its input, so click the label
		await section.getByText(label, { exact: true }).click()
		expect((await saved).ok()).toBe(true)
	}

	const before = await section.getByLabel(label, { exact: true }).isChecked()
	await toggle()
	try {
		await page.reload()
		await expect(section.getByLabel(label, { exact: true })).toBeChecked({ checked: !before })
	} finally {
		await toggle()
	}
}

test.beforeEach(async ({ page }) => {
	await login(page.request, admin)
})

test.describe('Admin settings', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('settings/admin/connected-accounts')
	})

	test('show the OneDrive section', async ({ page }) => {
		const section = page.locator('#onedrive_prefs')
		await expect(section.getByRole('heading', { name: /Microsoft OneDrive integration/ })).toBeVisible()
		await expect(section.getByLabel('Client ID', { exact: true })).toBeVisible()
		await expect(section.getByLabel('Client secret', { exact: true })).toBeVisible()
	})

	test('save the popup authentication setting', async ({ page }) => {
		await expectSwitchToBeSaved(page, 'Use a popup to authenticate', '/apps/integration_onedrive/admin-config')
	})
})

test.describe('Personal settings', () => {
	test.beforeEach(async ({ page }) => {
		// the app lists its personal settings under Data migration
		await page.goto('settings/user/migration')
	})

	test('ask for the OneDrive OAuth app to be configured first', async ({ page }) => {
		const section = page.locator('#onedrive_prefs')
		await expect(section.getByRole('heading', { name: /Microsoft OneDrive integration/ })).toBeVisible()
		await expect(section.getByText('Ask your Nextcloud administrator to configure OneDrive OAuth settings in order to use this integration.')).toBeVisible()
	})

	test('save the navigation link setting', async ({ page }) => {
		await expectSwitchToBeSaved(page, 'Enable navigation link', '/apps/integration_onedrive/config')
	})
})
