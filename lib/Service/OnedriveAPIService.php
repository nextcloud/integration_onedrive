<?php
/**
 * Nextcloud - onedrive
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Onedrive\Service;

use OCP\IL10N;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use OCP\Http\Client\IClientService;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCP\Notification\IManager as INotificationManager;

use OCA\Onedrive\AppInfo\Application;

class OnedriveAPIService {

	private $l10n;
	private $logger;

	/**
	 * Service to make requests to OneDrive v3 (JSON) API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								INotificationManager $notificationManager,
								IClientService $clientService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->config = $config;
		$this->notificationManager = $notificationManager;
		$this->clientService = $clientService;
		$this->client = $clientService->newClient();
	}

	/**
	 * @param string $userId
	 * @param string $subject
	 * @param string $params
	 * @return void
	 */
	public function sendNCNotification(string $userId, string $subject, array $params): void {
		$manager = $this->notificationManager;
		$notification = $manager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('dum', 'dum')
			->setSubject($subject, $params);

		$manager->notify($notification);
	}

	/**
	 * @param string $url
	 * @return array
	 */
	public function fileRequest(string $url, string $tmpFilePath): array {
		try {
			$options = [
				'save_to' => $tmpFilePath,
				'headers' => [
					'User-Agent' => 'Nextcloud Dropbox integration',
				],
			];

			$response = $this->client->get($url, $options);
			//$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return ['success' => true];
			}
		} catch (ServerException | ClientException $e) {
			$response = $e->getResponse();
			$this->logger->warning('OneDrive API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}

	public function chunkedCopy(string $fromPath, $outResource): int {
		if (!is_resource($outResource)) {
			throw new \InvalidArgumentException(
				sprintf(
					'Argument must be a valid resource type. %s given.',
					gettype($resource)
				)
			);
		}
		// 10 Mo at a time
		$buffer_size = 10000000;
		$ret = 0;
		$fin = fopen($fromPath, 'rb');
		while(!feof($fin)) {
			$ret += fwrite($outResource, fread($fin, $buffer_size));
		}
		fclose($fin);
		fclose($outResource);
		return $ret;
	}

	/**
	 * Make the HTTP request
	 * @param string $accessToken
	 * @param string $endPoint The path to reach in api.onedrive.com
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @return array decoded request result or error
	 */
	public function request(string $accessToken, string $userId, string $endPoint, array $params = [], string $method = 'GET'): array {
		try {
			$url = 'https://graph.microsoft.com/v1.0/' . $endPoint;
			$options = [
				'headers' => [
					'Authorization' => 'bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud OneDrive integration'
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = json_encode($params);
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return json_decode($body, true) ?: [];
			}
		} catch (\Exception $e) {
			$response = $e->getResponse();
			if ($response->getStatusCode() === 401) {
				$this->logger->info('Trying to REFRESH the access token', ['app' => $this->appName]);
				// try to refresh the token
				$clientId = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
				$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');
				$redirectUri = $this->config->getUserValue($userId, Application::APP_ID, 'redirect_uri', '');
				$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token', '');
				$result = $this->requestOAuthAccessToken([
					'client_id' => $clientId,
					'client_secret' => $clientSecret,
					'grant_type' => 'refresh_token',
					'redirect_uri' => $redirectUri,
					'refresh_token' => $refreshToken,
				], 'POST');
				if (isset($result['access_token'])) {
					$this->logger->info('OneDrive access token successfully refreshed', ['app' => $this->appName]);
					$accessToken = $result['access_token'];
					$this->config->setUserValue($userId, Application::APP_ID, 'token', $accessToken);
					// retry the request with new access token
					return $this->request($accessToken, $userId, $endPoint, $params, $method);
				} else {
					// impossible to refresh the token
					return ['error' => $this->l10n->t('Token is not valid anymore. Impossible to refresh it.') . ' ' . $result['error']];
				}
			}
			$this->logger->warning('OneDrive API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Make the request to get an OAuth token
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @return array parsed result or error
	 */
	public function requestOAuthAccessToken(array $params = [], string $method = 'POST'): array {
		try {
			$url = 'https://login.live.com/oauth20_token.srf';
			$options = [
				'headers' => [
					'User-Agent' => 'Nextcloud OneDrive integration'
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('OAuth access token refused')];
			} else {
				return json_decode($body, true);
			}
		} catch (\Exception $e) {
			$this->logger->warning('OneDrive OAuth error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}
}
