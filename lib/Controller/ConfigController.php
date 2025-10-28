<?php

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\Controller;

use DateTime;
use OCA\Onedrive\AppInfo\Application;
use OCA\Onedrive\Service\OnedriveAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;

use OCP\Constants;
use OCP\Contacts\IManager as IContactManager;
use OCP\IConfig;
use OCP\IL10N;

use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;

class ConfigController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
		private IInitialState $initialStateService,
		private IContactManager $contactsManager,
		private OnedriveAPIService $onedriveAPIService,
		private ICrypto $crypto,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Set config values
	 *
	 * @param array<string,string> $values key/value pairs to store in user preferences
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function setConfig(array $values): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		foreach ($values as $key => $value) {
			if ($key === 'token' && $value === 'dummyToken') {
				continue;  // Skip writing if 'token' equals 'dummyToken'
			}

			if (in_array($key, ['token', 'refresh_token']) && $value !== '') {
				$value = $this->crypto->encrypt($value);
			}
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
		}
		$result = [];

		if (isset($values['user_name']) && $values['user_name'] === '') {
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'refresh_token');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_id');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_name');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'oauth_state');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'redirect_uri');
			$result['user_name'] = '';
		}
		return new DataResponse($result);
	}

	/**
	 * Set admin config values
	 *
	 * @param array<string,string> $values key/value pairs to store in app config
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if ($key === 'token' && $value === 'dummyToken') {
				continue;  // Skip writing if 'token' equals 'dummyToken'
			}

			if (in_array($key, ['token', 'refresh_token']) && $value !== '') {
				$value = $this->crypto->encrypt($value);
			}
			/** @psalm-suppress DeprecatedMethod */
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}
		return new DataResponse(1);
	}

	#[PasswordConfirmationRequired]
	public function setSensitiveAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if (in_array($key, ['client_secret'], true) && $value !== '') {
				$value = $this->crypto->encrypt($value);
			}
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}
		return new DataResponse([]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function popupSuccessPage(string $username): TemplateResponse {
		$this->initialStateService->provideInitialState('popup-data', ['user_name' => $username]);
		return new TemplateResponse(Application::APP_ID, 'popupSuccess', [], TemplateResponse::RENDER_AS_GUEST);
	}

	/**
	 * Receive oauth code and get oauth access token
	 *
	 * @param string $code request code to use when requesting oauth token
	 * @return RedirectResponse to user settings
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function oauthRedirect(string $code = ''): RedirectResponse {
		/** @psalm-suppress DeprecatedMethod */
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		/** @psalm-suppress DeprecatedMethod */
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$clientSecret = $clientSecret === '' ? '' : $this->crypto->decrypt($clientSecret);

		// anyway, reset state
		$this->config->setUserValue($this->userId, Application::APP_ID, 'oauth_state', '');

		if ($clientID && $clientSecret && $code !== '') {
			$redirectUri = $this->config->getUserValue($this->userId, Application::APP_ID, 'redirect_uri');
			/** @var array{error?: string, access_token?: string, refresh_token?: string, scope?: string, expires_in?: string, user_id?: string} $result */
			$result = $this->onedriveAPIService->requestOAuthAccessToken([
				'client_id' => $clientID,
				'client_secret' => $clientSecret,
				'code' => $code,
				// 'state' => $state,
				'grant_type' => 'authorization_code',
				'redirect_uri' => $redirectUri,
			]);
			if (isset($result['access_token'], $result['refresh_token'])) {
				$accessToken = $result['access_token'];
				$encryptedAccessToken = $accessToken === '' ? '' : $this->crypto->encrypt($accessToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $encryptedAccessToken);
				$refreshToken = $result['refresh_token'];
				$encryptedRefreshToken = $refreshToken === '' ? '' : $this->crypto->encrypt($refreshToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $encryptedRefreshToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'scope', $result['scope'] ?? '');
				if (isset($result['expires_in'])) {
					$nowTs = (new DateTime())->getTimestamp();
					$expiresAt = $nowTs + (int)$result['expires_in'];
					$this->config->setUserValue($this->userId, Application::APP_ID, 'token_expires_at', (string)$expiresAt);
				}

				$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $result['user_id'] ?? '');
				$info = $this->onedriveAPIService->request($this->userId, 'me');
				$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $info['displayName'] ?? '??');
				/** @psalm-suppress DeprecatedMethod */
				$usePopup = $this->config->getAppValue(Application::APP_ID, 'use_popup', '0') === '1';
				if ($usePopup) {
					return new RedirectResponse(
						$this->urlGenerator->linkToRoute(Application::APP_ID . '.config.popupSuccessPage', ['username' => $info['displayName'] ?? '??'])
					);
				} else {
					return new RedirectResponse(
						$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'migration'])
						. '?onedriveToken=success'
					);
				}
			}
			$result = $this->l->t('Error getting OAuth access token') . ' ' . ($result['error'] ?? '');
		} else {
			$result = $this->l->t('Error during OAuth exchanges');
		}
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'migration'])
			. '?onedriveToken=error&message=' . urlencode($result)
		);
	}

	/**
	 * Get local address book list
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getLocalAddressBooks(): DataResponse {
		$addressBooks = $this->contactsManager->getUserAddressBooks();
		$result = [];
		foreach ($addressBooks as $ab) {
			if ($ab->getUri() !== 'system') {
				/** @var int $perms */
				$perms = $ab->getPermissions();
				$result[$ab->getKey()] = [
					'uri' => $ab->getUri(),
					'name' => $ab->getDisplayName(),
					'canEdit' => (bool)(($perms & Constants::PERMISSION_CREATE)),
				];
			}
		}
		return new DataResponse($result);
	}
}
