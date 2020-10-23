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
use Psr\Log\LoggerInterface;
use OCP\Http\Client\IClientService;

class OnedriveAPIService {

	private $l10n;
	private $logger;

	/**
	 * Service to make requests to OneDrive v3 (JSON) API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IClientService $clientService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->clientService = $clientService;
		$this->client = $clientService->newClient();
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
			$url = 'https://api.onedrive.com/' . $endPoint;
			$options = [
				'headers' => [
					'Authorization' => 'token ' . $accessToken,
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
			// TODO refresh token
			// client_id={client_id}&redirect_uri={redirect_uri}&client_secret={client_secret}
			// &refresh_token={refresh_token}&grant_type=refresh_token

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
