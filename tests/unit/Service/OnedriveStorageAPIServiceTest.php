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
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class OnedriveStorageAPIServiceTest extends TestCase {

	private OnedriveAPIService|MockObject $apiService;
	private LoggerInterface|MockObject $logger;
	private IRootFolder|MockObject $rootFolder;
	private IConfig|MockObject $config;
	private IJobList|MockObject $jobList;
	private Folder|MockObject $folder;
	private File|MockObject $file;

	private OnedriveStorageAPIService $service;

	/** @var array<string, string> */
	private array $configStore = [];

	/** @var string[] names of the files the target folder holds */
	private array $existingFiles = [];

	/** @var string[] the downloads the app asked for, in order */
	private array $downloadedFiles = [];

	private const STALE_URL = 'https://stale.example.org/download?tempauth=old';
	private const FRESH_URL = 'https://fresh.example.org/download?tempauth=new';

	public function setUp(): void {
		parent::setUp();

		$this->apiService = $this->createMock(OnedriveAPIService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->config = $this->createMock(IConfig::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->service = new OnedriveStorageAPIService(
			'integration_onedrive',
			$this->logger,
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

	/**
	 * IRootFolder::getUserFolder() declares an IUserFolder return type since
	 * Nextcloud 36, older versions have no IUserFolder to mock.
	 */
	private function createUserFolderMock(): Folder|MockObject {
		return $this->createMock(interface_exists(IUserFolder::class) ? IUserFolder::class : Folder::class);
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
		$userFolder = $this->createUserFolderMock();
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
		$folder = $this->createUserFolderMock();
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

	public function testStartingAnImportForgetsTheCountersOfThePreviousOne(): void {
		$this->useStatefulConfig([
			'nb_imported_files' => '41',
			'nb_failed_files' => '2',
			'nb_skipped_files' => '3',
			'failed_files' => '["x.jpg"]',
			'imported_size' => '123456',
			'import_tree' => '{"/sub":"todo"}',
		]);
		$folder = $this->createMock(Folder::class);
		$folder->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
		$userFolder = $this->createUserFolderMock();
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($folder);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->jobList->expects($this->once())->method('add');

		$this->service->startImportOnedrive('user1');

		$this->assertSame('1', $this->configStore['importing_onedrive']);
		$this->assertSame('0', $this->configStore['nb_imported_files']);
		$this->assertSame('0', $this->configStore['nb_failed_files']);
		$this->assertSame('0', $this->configStore['nb_skipped_files']);
		$this->assertSame('0', $this->configStore['imported_size']);
		$this->assertArrayNotHasKey('failed_files', $this->configStore);
		$this->assertArrayNotHasKey('import_tree', $this->configStore);
	}

	public function testFileThatCannotBeLookedUpIsLoggedAndCountedAsFailed(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willThrowException(new ForbiddenException('no reading here', false));
		$this->apiService->expects($this->never())->method('fileRequest');
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('photo.jpg'), ['app' => 'integration_onedrive']);

		$method = new ReflectionMethod(OnedriveStorageAPIService::class, 'getFile');
		$result = $method->invoke($this->service, 'user1', $folder, ['name' => 'photo.jpg']);

		$this->assertSame(['status' => 'failed', 'size' => 0.0], $result);
	}
	/**
	 * A drive whose root listing has two pages: a folder on the first one, two files on
	 * the second. $fileSize decides where the batch download size runs out.
	 */
	private function statefulDriveWithTwoRootPages(int $fileSize, array $alreadyThere = []): void {
		$this->useStatefulConfig([]);
		$this->apiService->method('request')->willReturnCallback(
			static function (string $userId, string $endPoint, array $params = []) {
				if ($endPoint === 'me/drive') {
					return ['quota' => ['used' => 1000]];
				}
				$file = static fn (string $name) => [
					'name' => $name,
					'id' => 'id-' . $name,
					'file' => [],
					'@microsoft.graph.downloadUrl' => 'https://dl.example.org/' . $name,
				];
				if ($endPoint === 'me/drive/root/children') {
					if (($params['$skiptoken'] ?? '') === 'page2') {
						return ['value' => [$file('second-page-1.jpg'), $file('second-page-2.jpg')]];
					}
					return [
						'value' => [['name' => 'sub', 'id' => 'id-sub', 'folder' => []]],
						'@odata.nextLink' => 'https://graph.example.org/me/drive/root/children?$skiptoken=page2',
					];
				}
				if ($endPoint === 'me/drive/root:%2Fsub:/children') {
					return ['value' => [$file('nested.jpg')]];
				}
				// the folder itself, asked for to copy its modification time
				return ['lastModifiedDateTime' => '2026-09-01T10:00:00Z'];
			}
		);
		$this->apiService->method('fileRequest')->willReturnCallback(
			function (string $url) {
				$this->downloadedFiles[] = basename($url);
				return ['success' => true];
			}
		);

		$this->existingFiles = $alreadyThere;
		$folders = [];
		$folderFor = function (string $path) use (&$folders, $fileSize) {
			if (!isset($folders[$path])) {
				$folder = $this->createMock(Folder::class);
				$folder->method('nodeExists')->willReturnCallback(
					fn (string $name) => in_array($path . '/' . $name, $this->existingFiles, true)
				);
				$folder->method('newFile')->willReturnCallback(function (string $name) use ($path, $fileSize) {
					$this->existingFiles[] = $path . '/' . $name;
					$file = $this->createMock(File::class);
					$file->method('fopen')->willReturnCallback(static fn () => fopen('php://temp', 'w+'));
					$file->method('stat')->willReturn(['size' => $fileSize]);
					$file->method('isDeletable')->willReturn(true);
					return $file;
				});
				$folders[$path] = $folder;
			}
			return $folders[$path];
		};
		$topFolder = $this->createMock(Folder::class);
		$topFolder->method('nodeExists')->willReturn(true);
		$topFolder->method('get')->willReturnCallback(static fn (string $path) => $folderFor($path));
		$userFolder = $this->createUserFolderMock();
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($topFolder);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
	}

	public function testBatchThatRunsOutInAListingComesBackForTheRestOfIt(): void {
		$this->statefulDriveWithTwoRootPages(200);
		$importTree = [];

		// 200 bytes per file, so the first file of the root's second page ends the batch
		$first = $this->service->importFiles('user1', '/Import', 100, 0, 0, $importTree);

		$this->assertFalse($first['finished']);
		$this->assertSame(['second-page-1.jpg'], $this->downloadedFiles);
		$this->assertSame('todo', $importTree['/sub'] ?? null, 'the folder found on the first page is remembered');
		$this->assertSame('page2', $importTree[''] ?? null, 'the root is remembered at the page it stopped in');

		// the job keeps handing the remembered tree to the next batch until one finishes
		$batches = 1;
		do {
			$result = $this->service->importFiles('user1', '/Import', 100, 0, 0, $importTree);
			$batches++;
		} while (empty($result['finished']) && $batches < 6);

		$this->assertTrue($result['finished']);
		$this->assertSame(
			['second-page-1.jpg', 'second-page-2.jpg', 'nested.jpg'],
			$this->downloadedFiles,
			'every file of the drive was imported'
		);
		$this->assertSame([], $importTree, 'nothing is left unfinished');
	}

	public function testFilesTheImportBroughtItselfAreNotReportedAsAlreadyThere(): void {
		$this->statefulDriveWithTwoRootPages(200);
		$importTree = [];

		// the first batch downloads one file of the second page, the next batch walks that
		// page again and finds it in place
		$this->service->importFiles('user1', '/Import', 100, 0, 0, $importTree);
		$this->assertSame(['second-page-1.jpg'], $this->downloadedFiles);
		$batches = 1;
		do {
			$result = $this->service->importFiles('user1', '/Import', 100, 0, 0, $importTree);
			$batches++;
		} while (empty($result['finished']) && $batches < 6);

		$this->assertSame('0', $this->configStore['nb_skipped_files'] ?? '0', 'nothing was already there');
	}

	public function testFilesThatWereAlreadyThereAreStillCounted(): void {
		// the file of the sub-folder is there before the import starts, and the sub-folder
		// is only reached by a later batch, which is not walking a page again
		$this->statefulDriveWithTwoRootPages(200, ['/sub/nested.jpg']);
		$importTree = [];

		$batches = 0;
		do {
			$result = $this->service->importFiles('user1', '/Import', 100, 0, 0, $importTree);
			$batches++;
		} while (empty($result['finished']) && $batches < 6);

		$this->assertSame('1', $this->configStore['nb_skipped_files'] ?? '0');
	}

	public function testResumingAFolderAsksForThePageItStoppedIn(): void {
		$this->statefulDriveWithTwoRootPages(200);
		$importTree = ['' => 'page2'];

		$this->service->importFiles('user1', '/Import', null, 0, 0, $importTree);

		// the second page holds both files, the first page only the sub-folder: asking for
		// the first page again would have downloaded nothing from the root
		$this->assertSame(
			['second-page-1.jpg', 'second-page-2.jpg'],
			$this->downloadedFiles,
			'the remembered page was asked for, not the first one'
		);
	}

	public function testFolderWhoseListingFailsIsTriedAgainByTheNextBatch(): void {
		$this->useStatefulConfig([]);
		$listings = [];
		$failuresLeft = 1;
		$this->apiService->method('request')->willReturnCallback(
			static function (string $userId, string $endPoint) use (&$listings, &$failuresLeft) {
				if ($endPoint === 'me/drive') {
					return ['quota' => ['used' => 1000]];
				}
				if ($endPoint === 'me/drive/root:%2Fsub:/children') {
					$listings[] = $endPoint;
					if ($failuresLeft > 0) {
						$failuresLeft--;
						return ['error' => 'serviceUnavailable'];
					}
					return ['value' => [[
						'name' => 'nested.jpg',
						'id' => 'id-nested',
						'file' => [],
						'@microsoft.graph.downloadUrl' => 'https://dl.example.org/nested.jpg',
					]]];
				}
				if ($endPoint === 'me/drive/root/children') {
					return ['value' => [['name' => 'sub', 'id' => 'id-sub', 'folder' => []]]];
				}
				return ['lastModifiedDateTime' => '2026-09-01T10:00:00Z'];
			}
		);
		$this->apiService->method('fileRequest')->willReturnCallback(
			function (string $url) {
				$this->downloadedFiles[] = basename($url);
				return ['success' => true];
			}
		);
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturnCallback(function () {
			$file = $this->createMock(File::class);
			$file->method('fopen')->willReturnCallback(static fn () => fopen('php://temp', 'w+'));
			$file->method('stat')->willReturn(['size' => 10]);
			return $file;
		});
		$topFolder = $this->createMock(Folder::class);
		$topFolder->method('nodeExists')->willReturn(true);
		$topFolder->method('get')->willReturn($folder);
		$userFolder = $this->createUserFolderMock();
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($topFolder);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$importTree = [];
		$this->service->importFiles('user1', '/Import', null, 0, 0, $importTree);

		$this->assertSame([], $this->downloadedFiles, 'the folder could not be listed');
		$this->assertSame('todo', $importTree['/sub'] ?? null, 'it is still to do');

		$this->service->importFiles('user1', '/Import', null, 0, 0, $importTree);

		$this->assertSame(['nested.jpg'], $this->downloadedFiles, 'the next batch listed it again');
		$this->assertSame([], $importTree);
	}

	public function testFolderIsStartedOverWhenItsRememberedPageIsGone(): void {
		$this->useStatefulConfig([]);
		$listed = [];
		$this->apiService->method('request')->willReturnCallback(
			static function (string $userId, string $endPoint, array $params = []) use (&$listed) {
				if ($endPoint === 'me/drive') {
					return ['quota' => ['used' => 1000]];
				}
				if ($endPoint === 'me/drive/root/children') {
					$listed[] = $params['$skiptoken'] ?? 'first page';
					if (isset($params['$skiptoken'])) {
						return ['error' => 'invalid skiptoken'];
					}
					return ['value' => [[
						'name' => 'photo.jpg',
						'id' => 'id-photo',
						'file' => [],
						'@microsoft.graph.downloadUrl' => 'https://dl.example.org/photo.jpg',
					]]];
				}
				return ['lastModifiedDateTime' => '2026-09-01T10:00:00Z'];
			}
		);
		$this->apiService->method('fileRequest')->willReturnCallback(
			function (string $url) {
				$this->downloadedFiles[] = basename($url);
				return ['success' => true];
			}
		);
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturnCallback(function () {
			$file = $this->createMock(File::class);
			$file->method('fopen')->willReturnCallback(static fn () => fopen('php://temp', 'w+'));
			$file->method('stat')->willReturn(['size' => 10]);
			return $file;
		});
		$topFolder = $this->createMock(Folder::class);
		$topFolder->method('nodeExists')->willReturn(true);
		$topFolder->method('get')->willReturn($folder);
		$userFolder = $this->createUserFolderMock();
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($topFolder);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		// the previous batch stopped at a page whose token is not accepted any more
		$importTree = ['' => 'stale-token'];
		$result = $this->service->importFiles('user1', '/Import', null, 0, 0, $importTree);

		$this->assertSame(['stale-token', 'first page'], $listed, 'the folder is listed again from its first page');
		$this->assertSame(['photo.jpg'], $this->downloadedFiles);
		$this->assertTrue($result['finished']);
		$this->assertSame([], $importTree);
	}

}
