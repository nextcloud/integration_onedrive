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

use DateTime;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use OCA\Onedrive\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Notification\IManager as INotificationManager;

use Psr\Log\LoggerInterface;
use Throwable;

class OnedriveAPIService {

	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var string
	 */
	private $appName;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var INotificationManager
	 */
	private $notificationManager;
	/**
	 * @var IL10N
	 */
	private $l10n;
	/**
	 * @var \OCP\Http\Client\IClient
	 */
	private $client;

	/**
	 * Service to make requests to OneDrive v3 (JSON) API
	 */
	public function __construct(string $appName,
		LoggerInterface $logger,
		IL10N $l10n,
		IConfig $config,
		INotificationManager $notificationManager,
		IClientService $clientService) {
		$this->appName = $appName;
		$this->logger = $logger;
		$this->config = $config;
		$this->notificationManager = $notificationManager;
		$this->client = $clientService->newClient();
		$this->l10n = $l10n;
	}

	/**
	 * @param string $userId
	 * @param string $subject
	 * @param array $params
	 * @return void
	 */
	public function sendNCNotification(string $userId, string $subject, array $params): void {
		$manager = $this->notificationManager;
		$notification = $manager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject('dum', 'dum')
			->setSubject($subject, $params);

		$manager->notify($notification);
	}

	/**
	 * @param string $url
	 * @param resource $resource
	 * @return array
	 */
	public function fileRequest(string $url, $resource): array {
		try {
			$options = [
				'stream' => true,
				'timeout' => 0,
				'headers' => [
					'User-Agent' => 'Nextcloud Dropbox integration',
				],
			];

			$response = $this->client->get($url, $options);
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			}

			$body = $response->getBody();
			if (is_string($body)) {
				fwrite($resource, $body);
			} else {
				while (!feof($body)) {
					// write ~5 MB chunks
					$chunk = fread($body, 5000000);
					fwrite($resource, $chunk);
				}
				fclose($body);
			}

			return ['success' => true];
		} catch (ServerException | ClientException $e) {
			// $response = $e->getResponse();
			$this->logger->warning('OneDrive API error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		} catch (ConnectException $e) {
			$this->logger->error('OneDrive API request connection error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		} catch (Exception | Throwable $e) {
			$this->logger->error('OneDrive API request connection error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Make the HTTP request
	 * @param string $userId
	 * @param string $endPoint The path to reach in api.onedrive.com
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @return array{body?: resource|string, headers?: array, error?: string} decoded request result or error
	 * @throws \OCP\PreConditionNotMetException
	 */
	public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET',
		bool $jsonResponse = true): array {
		$this->checkTokenExpiration($userId);
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		try {
			$url = 'https://graph.microsoft.com/v1.0/' . $endPoint;
			$options = [
				'headers' => [
					'Authorization' => 'bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud OneDrive integration'
				],
			];
			if ($method === 'POST') {
				$options['headers']['Content-Type'] = 'application/json';
			}

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
			} elseif ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} elseif ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} elseif ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				if ($jsonResponse) {
					if (is_resource($body)) {
						$stream_body = stream_get_contents($body);
						fclose($body);
						$body = $stream_body;
					}
					return json_decode($body, true) ?: [];
				} else {
					return [
						'body' => $body,
						'headers' => $response->getHeaders(),
					];
				}
			}
		} catch (ServerException | ClientException $e) {
			$this->logger->warning('OneDrive API error : '.$e->getResponse()->getBody(), ['app' => Application::APP_ID]);
			return ['error' => $e->getResponse()->getBody()];
		} catch (ConnectException $e) {
			$this->logger->warning('OneDrive API connection error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Make the request to get an OAuth token
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @return array{error?: string} parsed result or error
	 * @throws Exception
	 */
	public function requestOAuthAccessToken(array $params = [], string $method = 'POST'): array {
		try {
			$url = 'https://login.live.com/oauth20_token.srf';
			$options = [
				'headers' => [
					'User-Agent' => 'Nextcloud OneDrive integration',
					'Content-Type' => 'application/x-www-form-urlencoded',
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
			} elseif ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} elseif ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} elseif ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('OAuth access token refused')];
			} else {
				if (is_resource($body)) {
					$body = stream_get_contents($body);
				}
				return json_decode($body, true);
			}
		} catch (ConnectException | ServerException | ClientException $e) {
			$this->logger->warning('OneDrive OAuth error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}

	private function checkTokenExpiration(string $userId): void {
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		$expireAt = $this->config->getUserValue($userId, Application::APP_ID, 'token_expires_at');
		if ($refreshToken !== '' && $expireAt !== '') {
			$nowTs = (new DateTime())->getTimestamp();
			$expireAt = (int) $expireAt;
			// if token expires in less than 2 minutes or has already expired
			if ($nowTs > $expireAt - 120) {
				$this->refreshToken($userId);
			}
		}
	}

	public function refreshToken(string $userId): array {
		$this->logger->debug('Trying to REFRESH the access token', ['app' => Application::APP_ID]);
		/** @psalm-suppress DeprecatedMethod */
		$clientId = $this->config->getAppValue(Application::APP_ID, 'client_id');
		/** @psalm-suppress DeprecatedMethod */
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$redirectUri = $this->config->getUserValue($userId, Application::APP_ID, 'redirect_uri');
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		/** @var array{access_token?: string, expires_in?: string} $result */
		$result = $this->requestOAuthAccessToken([
			'client_id' => $clientId,
			'client_secret' => $clientSecret,
			'grant_type' => 'refresh_token',
			'redirect_uri' => $redirectUri,
			'refresh_token' => $refreshToken,
		], 'POST');

		if (isset($result['access_token'])) {
			$this->logger->debug('OneDrive access token successfully refreshed', ['app' => Application::APP_ID]);
			$this->config->setUserValue($userId, Application::APP_ID, 'token', $result['access_token']);
			if (isset($result['expires_in'])) {
				$nowTs = (new DateTime())->getTimestamp();
				$expiresAt = $nowTs + (int) $result['expires_in'];
				$this->config->setUserValue($userId, Application::APP_ID, 'token_expires_at', (string)$expiresAt);
			}
		} else {
			$responseTxt = json_encode($result);
			$this->logger->warning('OneDrive API error, impossible to refresh the token. Response: ' . $responseTxt, ['app' => Application::APP_ID]);
		}

		return $result;
	}
}
