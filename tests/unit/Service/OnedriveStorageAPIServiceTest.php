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
	private Folder|MockObject $folder;
	private File|MockObject $file;

	private OnedriveStorageAPIService $service;

	private const STALE_URL = 'https://stale.example.org/download?tempauth=old';
	private const FRESH_URL = 'https://fresh.example.org/download?tempauth=new';

	public function setUp(): void {
		parent::setUp();

		$this->apiService = $this->createMock(OnedriveAPIService::class);
		$this->service = new OnedriveStorageAPIService(
			'integration_onedrive',
			$this->createMock(LoggerInterface::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(IConfig::class),
			$this->createMock(IJobList::class),
			$this->createMock(UserScopeService::class),
			$this->apiService,
		);

		$this->file = $this->createMock(File::class);
		$this->file->method('fopen')->willReturnCallback(static fn () => fopen('php://temp', 'w+'));
		$this->folder = $this->createMock(Folder::class);
		$this->folder->method('nodeExists')->willReturn(false);
		$this->folder->method('newFile')->willReturn($this->file);
	}

	private function getFile(array $fileItem): ?float {
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

		$size = $this->getFile([
			'name' => 'photo.jpg',
			'id' => 'item1',
			'file' => [],
			'@microsoft.graph.downloadUrl' => self::STALE_URL,
		]);

		$this->assertSame(123.0, $size);
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

		$size = $this->getFile([
			'name' => 'photo.jpg',
			'id' => 'item1',
			'file' => [],
			'@microsoft.graph.downloadUrl' => self::STALE_URL,
		]);

		$this->assertSame(123.0, $size);
		$this->assertSame([self::STALE_URL, self::FRESH_URL], $requestedUrls);
	}

	public function testFileIsDroppedWhenTheRetryFailsToo(): void {
		$this->apiService->method('fileRequest')->willReturn(['error' => 'expired']);
		$this->apiService->expects($this->once())
			->method('getDownloadUrl')
			->willReturn(self::FRESH_URL);
		$this->file->method('isDeletable')->willReturn(true);
		$this->file->expects($this->once())->method('delete');

		$this->assertNull($this->getFile([
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

		$this->assertNull($this->getFile([
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

		$size = $this->getFile([
			'name' => 'note.one',
			'id' => 'item1',
			'file' => [],
		]);

		$this->assertSame(42.0, $size);
	}

	public function testExistingFileIsNotDownloadedAgain(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(true);
		$folder->expects($this->never())->method('newFile');
		$this->apiService->expects($this->never())->method('fileRequest');

		$method = new ReflectionMethod(OnedriveStorageAPIService::class, 'getFile');
		$size = $method->invoke($this->service, 'user1', $folder, ['name' => 'photo.jpg']);

		$this->assertSame(0.0, $size);
	}
}
