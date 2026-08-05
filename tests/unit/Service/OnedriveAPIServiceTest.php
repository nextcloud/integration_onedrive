<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use OCA\Onedrive\Service\OnedriveAPIService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Notification\IManager as INotificationManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OnedriveAPIServiceTest extends TestCase {

	private LoggerInterface|MockObject $logger;
	private IConfig|MockObject $config;
	private IClient|MockObject $client;

	private OnedriveAPIService $apiService;

	public function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->config = $this->createMock(IConfig::class);
		// IConfig::getUserValue has no native return type, so the mock would return
		// null instead of the empty-string default the code relies on
		$this->config->method('getUserValue')->willReturnArgument(3);
		$this->client = $this->createMock(IClient::class);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);

		$this->apiService = new OnedriveAPIService(
			$this->logger,
			$this->createMock(IL10N::class),
			$this->config,
			$this->createMock(ICrypto::class),
			$this->createMock(INotificationManager::class),
			$this->createMock(IAppManager::class),
			$clientService,
		);
	}

	public function testGetDownloadUrlFetchesTheItem(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode([
			'id' => 'item1',
			'@microsoft.graph.downloadUrl' => 'https://fresh.example.org/download',
		]));
		$this->client->expects($this->once())
			->method('get')
			->with($this->stringContains('me/drive/items/item1'))
			->willReturn($response);

		$this->assertSame(
			'https://fresh.example.org/download',
			$this->apiService->getDownloadUrl('user1', 'item1')
		);
	}

	public function testGetDownloadUrlReturnsNullWhenTheItemHasNoUrl(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode(['id' => 'item1']));
		$this->client->method('get')->willReturn($response);

		$this->assertNull($this->apiService->getDownloadUrl('user1', 'item1'));
	}

	public function testGetDownloadUrlReturnsNullOnRequestError(): void {
		$this->client->method('get')->willReturnCallback(static function (string $url) {
			throw new ConnectException('Connection refused', new Request('GET', $url));
		});

		$this->assertNull($this->apiService->getDownloadUrl('user1', 'item1'));
	}

	public function testFileRequestKeepsUrlsOutOfErrorsAndLogs(): void {
		$url = 'https://my.example.org/download.aspx?tempauth=SECRETTOKEN&ApiVersion=2.0';
		$this->client->method('get')->willReturnCallback(static function () use ($url) {
			throw new ConnectException(
				'cURL error 28: Operation too slow for ' . $url,
				new Request('GET', $url)
			);
		});
		$this->logger->expects($this->once())
			->method('error')
			->with($this->callback(static function (string $message): bool {
				return !str_contains($message, 'SECRETTOKEN') && str_contains($message, '<url removed>');
			}));

		$sink = fopen('php://temp', 'w+');
		$result = $this->apiService->fileRequest($url, $sink);
		fclose($sink);

		$this->assertArrayHasKey('error', $result);
		$this->assertStringNotContainsString('SECRETTOKEN', $result['error']);
		$this->assertStringContainsString('<url removed>', $result['error']);
	}
}
