<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\Tests;

use OCA\Onedrive\Service\OnedriveAPIService;
use OCA\Onedrive\Service\OnedriveStorageAPIService;
use OCA\Onedrive\Service\UserScopeService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class OnedriveStorageAPIServiceTest extends TestCase {

	private OnedriveAPIService|MockObject $apiService;
	private IRootFolder|MockObject $rootFolder;
	private IConfig|MockObject $config;
	private IJobList|MockObject $jobList;
	private Folder|MockObject $folder;
	private File|MockObject $file;

	private OnedriveStorageAPIService $service;

	/** @var array<string, string> */
	private array $configStore = [];

	private const STALE_URL = 'https://stale.example.org/download?tempauth=old';
	private const FRESH_URL = 'https://fresh.example.org/download?tempauth=new';

	public function setUp(): void {
		parent::setUp();

		$this->apiService = $this->createMock(OnedriveAPIService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->config = $this->createMock(IConfig::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->service = new OnedriveStorageAPIService(
			'integration_onedrive',
			$this->createMock(LoggerInterface::class),
			$this->rootFolder,
			$this->config,
			$this->jobList,
			$this->createMock(UserScopeService::class),
			$this->apiService,
		);

		$this->file = $this->createMock(File::class);
		$this->file->method('fopen')->willReturnCallback(static fn () => fopen('php://temp', 'w+'));
		$this->folder = $this->createMock(Folder::class);
		$this->folder->method('nodeExists')->willReturn(false);
		$this->folder->method('newFile')->willReturn($this->file);
	}

	/**
	 * Back the IConfig mock with an array, so code doing read-increment-write
	 * on user settings behaves like it does against the real config.
	 */
	private function useStatefulConfig(array $initial): void {
		$this->configStore = $initial;
		$this->config->method('getUserValue')->willReturnCallback(
			fn (string $userId, string $appName, string $key, $default = '') => $this->configStore[$key] ?? $default
		);
		$this->config->method('setUserValue')->willReturnCallback(
			function (string $userId, string $appName, string $key, $value): void {
				$this->configStore[$key] = (string)$value;
			}
		);
		$this->config->method('deleteUserValue')->willReturnCallback(
			function (string $userId, string $appName, string $key): void {
				unset($this->configStore[$key]);
			}
		);
	}

	private function getFile(array $fileItem): array {
		$method = new ReflectionMethod(OnedriveStorageAPIService::class, 'getFile');
		return $method->invoke($this->service, 'user1', $this->folder, $fileItem);
	}

	public function testDownloadSucceedsOnTheFirstAttempt(): void {
		$this->file->method('stat')->willReturn(['size' => 123]);
		$this->file->expects($this->never())->method('delete');
		$this->apiService->expects($this->once())
			->method('fileRequest')
			->with(self::STALE_URL)
			->willReturn(['success' => true]);
		$this->apiService->expects($this->never())->method('getDownloadUrl');

		$result = $this->getFile([
			'name' => 'photo.jpg',
			'id' => 'item1',
			'file' => [],
			'@microsoft.graph.downloadUrl' => self::STALE_URL,
		]);

		$this->assertSame(['status' => 'downloaded', 'size' => 123.0], $result);
	}

	public function testFailedDownloadIsRetriedWithAFreshUrl(): void {
		$this->file->method('stat')->willReturn(['size' => 123]);
		$this->file->expects($this->never())->method('delete');

		$requestedUrls = [];
		$this->apiService->method('fileRequest')
			->willReturnCallback(static function (string $url) use (&$requestedUrls) {
				$requestedUrls[] = $url;
				return $url === self::FRESH_URL ? ['success' => true] : ['error' => 'expired'];
			});
		$this->apiService->expects($this->once())
			->method('getDownloadUrl')
			->with('user1', 'item1')
			->willReturn(self::FRESH_URL);

		$result = $this->getFile([
			'name' => 'photo.jpg',
			'id' => 'item1',
			'file' => [],
			'@microsoft.graph.downloadUrl' => self::STALE_URL,
		]);

		$this->assertSame(['status' => 'downloaded', 'size' => 123.0], $result);
		$this->assertSame([self::STALE_URL, self::FRESH_URL], $requestedUrls);
	}

	public function testFileIsDroppedWhenTheRetryFailsToo(): void {
		$this->apiService->method('fileRequest')->willReturn(['error' => 'expired']);
		$this->apiService->expects($this->once())
			->method('getDownloadUrl')
			->willReturn(self::FRESH_URL);
		$this->file->method('isDeletable')->willReturn(true);
		$this->file->expects($this->once())->method('delete');

		$this->assertSame(['status' => 'failed', 'size' => 0.0], $this->getFile([
			'name' => 'photo.jpg',
			'id' => 'item1',
			'file' => [],
			'@microsoft.graph.downloadUrl' => self::STALE_URL,
		]));
	}

	public function testFileIsDroppedWhenNoFreshUrlCanBeFetched(): void {
		$this->apiService->method('fileRequest')->willReturn(['error' => 'expired']);
		$this->apiService->method('getDownloadUrl')->willReturn(null);
		$this->file->method('isDeletable')->willReturn(true);
		$this->file->expects($this->once())->method('delete');

		$this->assertSame(['status' => 'failed', 'size' => 0.0], $this->getFile([
			'name' => 'photo.jpg',
			'id' => 'item1',
			'file' => [],
			'@microsoft.graph.downloadUrl' => self::STALE_URL,
		]));
	}

	public function testListingItemWithoutUrlIsDownloadedViaAFreshUrl(): void {
		$this->file->method('stat')->willReturn(['size' => 42]);
		$this->apiService->expects($this->once())
			->method('fileRequest')
			->with(self::FRESH_URL)
			->willReturn(['success' => true]);
		$this->apiService->expects($this->once())
			->method('getDownloadUrl')
			->with('user1', 'item1')
			->willReturn(self::FRESH_URL);

		$result = $this->getFile([
			'name' => 'note.one',
			'id' => 'item1',
			'file' => [],
		]);

		$this->assertSame(['status' => 'downloaded', 'size' => 42.0], $result);
	}

	public function testDownloadedEmptyFileCountsAsDownloaded(): void {
		$this->file->method('stat')->willReturn(['size' => 0]);
		$this->apiService->method('fileRequest')->willReturn(['success' => true]);

		$result = $this->getFile([
			'name' => 'empty.txt',
			'id' => 'item1',
			'file' => [],
			'@microsoft.graph.downloadUrl' => self::STALE_URL,
		]);

		$this->assertSame(['status' => 'downloaded', 'size' => 0.0], $result);
	}

	public function testExistingFileIsNotDownloadedAgain(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(true);
		$folder->expects($this->never())->method('newFile');
		$this->apiService->expects($this->never())->method('fileRequest');

		$method = new ReflectionMethod(OnedriveStorageAPIService::class, 'getFile');
		$result = $method->invoke($this->service, 'user1', $folder, ['name' => 'photo.jpg']);

		$this->assertSame(['status' => 'already there', 'size' => 0.0], $result);
	}

	public function testImportCountsFailedSkippedAndEmptyDownloads(): void {
		$this->useStatefulConfig([]);

		// remote drive root holds four files: a succeeds, b fails on the listing URL
		// and on a fresh one, c is an empty file, d already exists locally
		$this->apiService->method('request')->willReturnCallback(
			static function (string $userId, string $endPoint) {
				if ($endPoint === 'me/drive') {
					return ['quota' => ['used' => 1000]];
				}
				if ($endPoint === 'me/drive/root/children') {
					return ['value' => array_map(static fn (string $name) => [
						'name' => $name . '.jpg',
						'id' => 'id-' . $name,
						'file' => [],
						'@microsoft.graph.downloadUrl' => 'https://listing.example.org/' . $name,
					], ['a', 'b', 'c', 'd'])];
				}
				return [];
			}
		);
		$requestedUrls = [];
		$this->apiService->method('fileRequest')->willReturnCallback(
			static function (string $url) use (&$requestedUrls) {
				$requestedUrls[] = $url;
				return str_ends_with($url, '/b') ? ['error' => 'download error'] : ['success' => true];
			}
		);
		$this->apiService->expects($this->once())
			->method('getDownloadUrl')
			->with('user1', 'id-b')
			->willReturn('https://fresh.example.org/b');

		$dirFolder = $this->createMock(Folder::class);
		$dirFolder->method('nodeExists')->willReturnCallback(
			static fn (string $name) => $name === 'd.jpg'
		);
		$dirFolder->method('newFile')->willReturnCallback(function (string $name) {
			$file = $this->createMock(File::class);
			$file->method('fopen')->willReturnCallback(static fn () => fopen('php://temp', 'w+'));
			$file->method('stat')->willReturn(['size' => $name === 'c.jpg' ? 0 : 10]);
			$file->method('isDeletable')->willReturn(true);
			return $file;
		});
		$topFolder = $this->createMock(Folder::class);
		$topFolder->method('nodeExists')->willReturn(true);
		$topFolder->method('get')->willReturn($dirFolder);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($topFolder);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$result = $this->service->importFiles('user1', '/Import');

		$this->assertTrue($result['finished']);
		$this->assertSame('1', $this->configStore['nb_failed_files'] ?? null);
		$this->assertSame('["b.jpg"]', $this->configStore['failed_files'] ?? null);
		$this->assertSame('1', $this->configStore['nb_skipped_files'] ?? null);
		// a and the empty file c are both imported
		$this->assertSame('2', $this->configStore['nb_imported_files'] ?? null);
		$this->assertSame('10', $this->configStore['imported_size'] ?? null);
		$this->assertSame([
			'https://listing.example.org/a',
			'https://listing.example.org/b',
			'https://fresh.example.org/b',
			'https://listing.example.org/c',
		], $requestedUrls);
	}

	public function testFinishedNotificationReportsImportedAndFailedCounts(): void {
		$this->useStatefulConfig([
			'importing_onedrive' => '1',
			'onedrive_import_running' => '0',
			'nb_imported_files' => '7',
			'nb_failed_files' => '3',
			'nb_skipped_files' => '5',
			'failed_files' => '["x.jpg","y.jpg","z.jpg"]',
		]);

		// nothing left to download, the job finishes right away
		$this->apiService->method('request')->willReturnCallback(
			static function (string $userId, string $endPoint) {
				if ($endPoint === 'me/drive') {
					return ['quota' => ['used' => 1000]];
				}
				if ($endPoint === 'me/drive/root/children') {
					return ['value' => []];
				}
				return [];
			}
		);
		$folder = $this->createMock(Folder::class);
		$folder->method('isShared')->willReturn(false);
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->willReturnSelf();
		$this->rootFolder->method('getUserFolder')->willReturn($folder);

		$this->apiService->expects($this->once())
			->method('sendNCNotification')
			->with('user1', 'import_onedrive_finished', [
				'nbImported' => 7,
				'nbFailed' => 3,
				'nbSkipped' => 5,
				'failedFiles' => ['x.jpg', 'y.jpg', 'z.jpg'],
				'targetPath' => '/OneDrive import',
			]);
		$this->jobList->expects($this->never())->method('add');

		$this->service->importOnedriveJob('user1');

		$this->assertSame('0', $this->configStore['nb_imported_files']);
		$this->assertSame('0', $this->configStore['nb_failed_files']);
		$this->assertSame('0', $this->configStore['nb_skipped_files']);
		$this->assertArrayNotHasKey('failed_files', $this->configStore);
		$this->assertSame('0', $this->configStore['importing_onedrive']);
	}
}
