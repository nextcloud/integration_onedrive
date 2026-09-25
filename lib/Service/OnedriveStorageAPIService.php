<?php

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\Service;

use DateTime;
use Exception;
use OCA\Onedrive\AppInfo\Application;
use OCA\Onedrive\BackgroundJob\ImportOnedriveJob;
use OCA\Onedrive\Exceptions\MaxDownloadSizeReachedException;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\PreConditionNotMetException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @psalm-type OneDriveItem = array{
 *   "name": string,
 *   "id"?: string,
 *   "file"?: array<string,mixed>,
 *   "folder"?: array<string,mixed>,
 *   "@microsoft.graph.downloadUrl"?: string,
 *   "@odata.nextLink"?: string,
 *   "lastModifiedDateTime"?: string
 * }
 */

class OnedriveStorageAPIService {

	/** A directory of the import tree that has not been listed at all yet. */
	private const DIR_NOT_STARTED = 'todo';

	/** A directory whose first listing page a batch has begun but not finished. */
	private const DIR_STARTED = 'started';

	private const FILE_DOWNLOADED = 'downloaded';
	private const FILE_ALREADY_THERE = 'already there';
	private const FILE_FAILED = 'failed';

	/**
	 * How many failed file names are kept to be shown in the import finished
	 * notification. The full list is in the server log.
	 */
	private const MAX_REPORTED_FAILED_FILES = 10;

	/**
	 * @var string
	 */
	private $appName;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IRootFolder
	 */
	private $root;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IJobList
	 */
	private $jobList;
	/**
	 * @var OnedriveAPIService
	 */
	private $onedriveApiService;
	/**
	 * @var UserScopeService
	 */
	private $userScopeService;

	/**
	 * Service to make requests to Onedrive API
	 */
	public function __construct(string $appName,
		LoggerInterface $logger,
		IRootFolder $root,
		IConfig $config,
		IJobList $jobList,
		UserScopeService $userScopeService,
		OnedriveAPIService $onedriveApiService) {
		$this->appName = $appName;
		$this->logger = $logger;
		$this->root = $root;
		$this->config = $config;
		$this->jobList = $jobList;
		$this->onedriveApiService = $onedriveApiService;
		$this->userScopeService = $userScopeService;
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function getStorageSize(string $userId): array {
		// onedrive storage size
		//		$onedriveUserId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id');
		$result = $this->onedriveApiService->request($userId, 'me/drive');
		if (isset($result['error']) || !isset($result['quota'], $result['quota']['used'])) {
			return $result;
		}
		//		$driveId = $result['id'] ?? '';
		return [
			'usageInStorage' => $result['quota']['used'],
		];
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function startImportOnedrive(string $userId): array {
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'onedrive_output_dir', '/OneDrive import');
		$targetPath = $targetPath ?: '/OneDrive import';

		$alreadyImporting = $this->config->getUserValue($userId, Application::APP_ID, 'importing_onedrive', '0') === '1';
		if ($alreadyImporting) {
			return ['targetPath' => $targetPath];
		}

		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
				return ['error' => 'Impossible to create Onedrive folder'];
			}
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'importing_onedrive', '1');
		$this->config->setUserValue($userId, Application::APP_ID, 'imported_size', '0');
		$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$this->config->setUserValue($userId, Application::APP_ID, 'nb_failed_files', '0');
		$this->config->setUserValue($userId, Application::APP_ID, 'nb_skipped_files', '0');
		$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', '0');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'failed_files');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'import_tree');

		$this->jobList->add(ImportOnedriveJob::class, ['user_id' => $userId]);
		return ['targetPath' => $targetPath];
	}

	/**
	 * @param string $userId
	 * @return void
	 */
	public function importOnedriveJob(string $userId): void {
		$this->logger->info('Importing onedrive files for ' . $userId);

		// in case SSE is enabled
		$this->userScopeService->setUserScope($userId);
		$this->userScopeService->setFilesystemScope($userId);

		$importingOnedrive = $this->config->getUserValue($userId, Application::APP_ID, 'importing_onedrive', '0') === '1';
		if (!$importingOnedrive) {
			return;
		}
		$jobRunning = $this->config->getUserValue($userId, Application::APP_ID, 'onedrive_import_running', '0') === '1';
		$nowTs = (new DateTime())->getTimestamp();
		if ($jobRunning) {
			$lastJobStart = $this->config->getUserValue($userId, Application::APP_ID, 'onedrive_import_job_last_start');
			if ($lastJobStart !== '' && ($nowTs - intval($lastJobStart) < Application::IMPORT_JOB_TIMEOUT)) {
				// last job has started less than an hour ago => we consider it can still be running
				return;
			}
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'onedrive_import_running', '1');
		$this->config->setUserValue($userId, Application::APP_ID, 'onedrive_import_job_last_start', strval($nowTs));

		// import batch of files
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'onedrive_output_dir', '/OneDrive import');
		$targetPath = $targetPath ?: '/OneDrive import';

		try {
			$targetNode = $this->root->getUserFolder($userId)->get($targetPath);
			if ($targetNode->isShared()) {
				$this->logger->error('Target path ' . $targetPath . 'is shared, resorting to user root folder');
				$targetPath = '/';
			}
		} catch (NotFoundException) {
			// noop, folder doesn't exist
		} catch (NotPermittedException) {
			$this->logger->error('Cannot determine if target path ' . $targetPath . 'is shared, resorting to root folder');
			$targetPath = '/';
		}

		// get previous progress
		$importTreeStr = $this->config->getUserValue($userId, Application::APP_ID, 'import_tree', '[]');
		/** @var array $importTree */
		$importTree = ($importTreeStr === '[]' || $importTreeStr === '') ? [] : json_decode($importTreeStr, true);
		// import by batch of 500 MB
		$alreadyImportedSize = (float)$this->config->getUserValue($userId, Application::APP_ID, 'imported_size', '0');
		$alreadyImportedNumber = (int)$this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		try {
			$result = $this->importFiles($userId, $targetPath, 500000000, $alreadyImportedSize, $alreadyImportedNumber, $importTree);
		} catch (Exception|Throwable $e) {
			$result = [
				'error' => 'Unknow job failure. ' . $e->getMessage(),
			];
		}
		if (isset($result['error']) || (isset($result['finished']) && $result['finished'])) {
			// read the counters accumulated over all batches before resetting them
			$nbImported = (int)$this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
			$nbFailed = (int)$this->config->getUserValue($userId, Application::APP_ID, 'nb_failed_files', '0');
			$nbSkipped = (int)$this->config->getUserValue($userId, Application::APP_ID, 'nb_skipped_files', '0');
			$failedFiles = json_decode($this->config->getUserValue($userId, Application::APP_ID, 'failed_files', '[]'), true) ?: [];
			$this->config->setUserValue($userId, Application::APP_ID, 'importing_onedrive', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'imported_size', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_failed_files', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_skipped_files', '0');
			$this->config->deleteUserValue($userId, Application::APP_ID, 'failed_files');
			$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', '0');
			if (isset($result['finished']) && $result['finished']) {
				$this->config->deleteUserValue($userId, Application::APP_ID, 'import_tree');
				$this->onedriveApiService->sendNCNotification($userId, 'import_onedrive_finished', [
					'nbImported' => $nbImported,
					'nbFailed' => $nbFailed,
					'nbSkipped' => $nbSkipped,
					'failedFiles' => $failedFiles,
					'targetPath' => $targetPath,
				]);
			}
		} else {
			// save progress
			$this->config->setUserValue($userId, Application::APP_ID, 'import_tree', json_encode($importTree));
			$ts = (new DateTime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', (string)$ts);
			$this->jobList->add(ImportOnedriveJob::class, ['user_id' => $userId]);
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'onedrive_import_running', '0');
	}

	/**
	 * @param string $userId
	 * @param string $targetPath
	 * @param ?int $maxDownloadSize
	 * @param float $alreadyImportedSize
	 * @param int $alreadyImportedNumber
	 * @param array $importTree
	 * @return array
	 * @throws \OCP\Files\NotFoundException
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OC\User\NoUserException
	 */
	public function importFiles(string $userId, string $targetPath,
		?int $maxDownloadSize = null, float $alreadyImportedSize = 0, int $alreadyImportedNumber = 0,
		array &$importTree = []): array {
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$topFolder = $userFolder->newFolder($targetPath);
		} else {
			$topFolder = $userFolder->get($targetPath);
			if (!$topFolder instanceof Folder) {
				return ['error' => 'Impossible to create ' . $targetPath . ' folder'];
			}
		}

		$info = $this->getStorageSize($userId);
		if (isset($info['error'])) {
			return $info;
		}
		//		$onedriveStorageSize = $info['usageInStorage'];

		// iterate on unfinished directory list retrieved with getUserValue
		try {
			if (count($importTree) === 0) {
				$downloadResult = $this->downloadDir(
					$userId, $topFolder, $maxDownloadSize, 0, 0, 0, '', $alreadyImportedSize, $alreadyImportedNumber, $importTree
				);
			} else {
				foreach ($importTree as $path => $state) {
					if (!isset($importTree[$path])) {
						// a directory this batch already finished on its way through a parent
						continue;
					}
					// an unfinished directory is remembered as not started at all, as begun
					// on its first page, or with the listing page the last batch stopped in
					$startedBefore = $state !== self::DIR_NOT_STARTED;
					$resumeToken = (!$startedBefore || $state === self::DIR_STARTED || !is_string($state) || $state === '')
						? null
						: $state;
					$downloadResult = $this->downloadDir(
						$userId, $topFolder, $maxDownloadSize, 0, 0, 0, (string)$path, $alreadyImportedSize, $alreadyImportedNumber, $importTree, $resumeToken, $startedBefore
					);
				}
			}
		} catch (MaxDownloadSizeReachedException $e) {
			return [
				'targetPath' => $targetPath,
				'finished' => false,
			];
		}

		return [
			'targetPath' => $targetPath,
			'finished' => true,
			'totalSeenNumber' => $downloadResult['totalSeenNumber'],
		];
	}

	private function downloadDir(
		string $userId,
		Folder $topFolder,
		?int $maxDownloadSize,
		int $downloadedSize,
		int $totalSeenNumber,
		int $nbDownloaded,
		string $path,
		float $alreadyImportedSize,
		int $alreadyImportedNumber,
		array &$importTree,
		?string $resumeToken = null,
		bool $resuming = false,
	): array {
		$newDownloadedSize = (float)$downloadedSize;
		$newTotalSeenNumber = $totalSeenNumber;
		$newNbDownloaded = $nbDownloaded;

		// ensure local folder exists
		if (!$topFolder->nodeExists($path)) {
			$folder = $topFolder->newFolder($path);
		} else {
			$folder = $topFolder->get($path);
		}

		// build Graph endpoint
		$encPath = rawurlencode($path);
		$reqPath = $encPath === '' ? '' : ':' . $encPath . ':';
		$endPoint = 'me/drive/root' . $reqPath . '/children';

		// collect sub-folders for recursive traversal
		/** @var string[] $subDirs */
		$subDirs = [];
		$params = [];
		if ($resumeToken !== null) {
			$params['$skiptoken'] = $resumeToken;
		}
		// Remember this directory as unfinished for as long as its listing is not
		// exhausted. A batch that stops in the middle of it, because it reached its
		// download size, has to come back to it, and to the page it stopped in.
		$importTree[$path] = $resumeToken ?? self::DIR_NOT_STARTED;
		$listingStartedOver = false;
		// the files of a page an earlier batch had already started are not files that
		// were "already there": this import downloaded them itself
		$walkingThePageAgain = $resuming;
		do {
			$result = $this->onedriveApiService->request($userId, $endPoint, $params);
			if (isset($result['error']) || !isset($result['value']) || !is_array($result['value'])) {
				if (isset($params['$skiptoken']) && !$listingStartedOver) {
					// the page cannot be listed any more, its token may simply have expired:
					// start the directory over once, the files it already brought are skipped
					// as existing ones
					$this->logger->info(
						'OneDrive could not list a page of ' . ($path === '' ? 'the import folder' : $path) . ', starting the folder over',
						['app' => Application::APP_ID]
					);
					$listingStartedOver = true;
					unset($params['$skiptoken']);
					$importTree[$path] = self::DIR_NOT_STARTED;
					$subDirs = [];
					continue;
				}
				// the directory stays in the tree: a later batch tries it again, and an
				// import that ends before that at least says in the log what it missed
				$this->logger->warning(
					'OneDrive error listing ' . ($path === '' ? 'the import folder' : $path) . ': ' . ($result['error'] ?? 'no file list in the answer'),
					['app' => Application::APP_ID]
				);
				return [
					'downloadedSize' => $newDownloadedSize,
					'totalSeenNumber' => $newTotalSeenNumber,
					'nbDownloaded' => $newNbDownloaded,
				];
			}

			$pageSkipped = 0;
			if (!isset($params['$skiptoken'])) {
				// the first page has begun: a batch that stops inside it has to come back
				// to it, and must not count its files as files that were already there
				$importTree[$path] = self::DIR_STARTED;
			}
			/** @var OneDriveItem $item */
			foreach ($result['value'] as $item) {
				if (isset($item['file'])) {
					$newTotalSeenNumber++;
					$fileResult = $this->getFile($userId, $folder, $item);
					if ($fileResult['status'] === self::FILE_DOWNLOADED) {
						$newDownloadedSize += $fileResult['size'];
						$newNbDownloaded++;
						$this->config->setUserValue($userId, Application::APP_ID, 'imported_size', (string)($alreadyImportedSize + $newDownloadedSize));
						$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', (string)($alreadyImportedNumber + $newNbDownloaded));
						$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', (string)(new \DateTime())->getTimestamp());
						if ($maxDownloadSize !== null && $newDownloadedSize >= $maxDownloadSize) {
							throw new MaxDownloadSizeReachedException('Download size limit reached');
						}
					} elseif ($fileResult['status'] === self::FILE_ALREADY_THERE) {
						$pageSkipped++;
					} else {
						// count files that could not be downloaded and remember the first
						// few names, to report them in the notification sent when the
						// import finishes
						$nbFailed = (int)$this->config->getUserValue($userId, Application::APP_ID, 'nb_failed_files', '0');
						$this->config->setUserValue($userId, Application::APP_ID, 'nb_failed_files', (string)($nbFailed + 1));
						if ($nbFailed < self::MAX_REPORTED_FAILED_FILES) {
							$failedFiles = json_decode($this->config->getUserValue($userId, Application::APP_ID, 'failed_files', '[]'), true) ?: [];
							$failedFiles[] = $item['name'];
							$this->config->setUserValue($userId, Application::APP_ID, 'failed_files', json_encode($failedFiles));
						}
					}
				}
				// folders: remember for recursion
				if (isset($item['folder'])) {
					$subDirs[] = $item['name'];
					// mark for progress tracking
					$subPath = ltrim($path . '/' . $item['name']);
					$importTree[$subPath] = self::DIR_NOT_STARTED;
				}
			}
			if ($pageSkipped > 0 && !$walkingThePageAgain) {
				// one write per listing page, skipped files are frequent on re-imports
				$nbSkipped = (int)$this->config->getUserValue($userId, Application::APP_ID, 'nb_skipped_files', '0');
				$this->config->setUserValue($userId, Application::APP_ID, 'nb_skipped_files', (string)($nbSkipped + $pageSkipped));
			}
			// only the first page of a resumed directory is one an earlier batch had started
			$walkingThePageAgain = false;

			// prepare next page if any
			if (isset($result['@odata.nextLink'])
				&& $result['@odata.nextLink']
				&& preg_match('/\$skiptoken=/i', $result['@odata.nextLink'])
			) {
				$params['$skiptoken'] = preg_replace('/.*\$skiptoken=/', '', $result['@odata.nextLink']);
				// come back to this page, not to the first one, if the import stops here
				$importTree[$path] = $params['$skiptoken'];
			} else {
				// the whole directory has been listed
				unset($importTree[$path]);
				break;
			}
		} while (true);

		// recurse into each sub-directory
		foreach ($subDirs as $dirName) {
			$subResult = $this->downloadDir(
				$userId,
				$topFolder,
				$maxDownloadSize,
				(int)$newDownloadedSize,
				(int)$newTotalSeenNumber,
				(int)$newNbDownloaded,
				$path . '/' . $dirName,
				$alreadyImportedSize,
				$alreadyImportedNumber,
				$importTree
			);
			// update totals from recursive call
			$newDownloadedSize = $subResult['downloadedSize'];
			$newTotalSeenNumber = $subResult['totalSeenNumber'];
			$newNbDownloaded = $subResult['nbDownloaded'];
		}

		// update directory timestamp to match remote lastModifiedDateTime
		$this->touchFolder($userId, $folder, $path);

		return [
			'downloadedSize' => $newDownloadedSize,
			'totalSeenNumber' => $newTotalSeenNumber,
			'nbDownloaded' => $newNbDownloaded,
		];
	}

	/**
	 * @param string $userId
	 * @param Folder $folder
	 * @param string $onedrivePath
	 * @return void
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws PreConditionNotMetException
	 */
	private function touchFolder(string $userId, Folder $folder, string $onedrivePath): void {
		$encPath = rawurlencode($onedrivePath);
		$reqPath = ($encPath === '')
			? ''
			: ':' . $encPath . ':';
		$endPoint = 'me/drive/root' . $reqPath;
		$remoteFolderInfo = $this->onedriveApiService->request($userId, $endPoint);
		if (isset($remoteFolderInfo['lastModifiedDateTime'])) {
			$d = new DateTime($remoteFolderInfo['lastModifiedDateTime']);
			$ts = $d->getTimestamp();
			$folder->touch($ts);
		}
	}

	/**
	 * @param string $userId
	 * @param Folder $folder
	 * @param array $fileItem
	 * @return array{status: string, size: float} status is one of the FILE_* constants,
	 *                                            size is only meaningful for FILE_DOWNLOADED
	 */
	private function getFile(string $userId, Folder $folder, array $fileItem): array {
		$fileName = $fileItem['name'];
		try {
			$fileExists = $folder->nodeExists($fileName);
		} catch (ForbiddenException $e) {
			// it is counted as a failed file below, so say why: the notification that
			// reports it points at the log
			$this->logger->warning('OneDrive cannot check whether file ' . $fileName . ' is already there: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return ['status' => self::FILE_FAILED, 'size' => 0.0];
		}
		if ($fileExists) {
			return ['status' => self::FILE_ALREADY_THERE, 'size' => 0.0];
		}

		$savedFile = $folder->newFile($fileName);
		$res = $this->downloadFile($savedFile, $fileItem['@microsoft.graph.downloadUrl'] ?? null);
		if (isset($res['error'])) {
			// The URL from the folder listing is only valid for about an hour, so on a long
			// import it may have expired by the time this file is reached. Fetch a fresh URL
			// for the item and try once more; this also covers transient network errors.
			$freshUrl = isset($fileItem['id'])
				? $this->onedriveApiService->getDownloadUrl($userId, $fileItem['id'])
				: null;
			if ($freshUrl !== null) {
				$res = $this->downloadFile($savedFile, $freshUrl);
			}
		}
		if (isset($res['error'])) {
			$this->logger->warning('OneDrive error downloading file ' . $fileName . ' : ' . $res['error'], ['app' => Application::APP_ID]);
			if ($savedFile->isDeletable()) {
				$savedFile->delete();
			}
			return ['status' => self::FILE_FAILED, 'size' => 0.0];
		}

		if (isset($fileItem['lastModifiedDateTime'])) {
			$d = new DateTime($fileItem['lastModifiedDateTime']);
			$ts = $d->getTimestamp();
			$savedFile->touch($ts);
		} else {
			$savedFile->touch();
		}
		$stat = $savedFile->stat();
		return ['status' => self::FILE_DOWNLOADED, 'size' => (float)($stat['size'] ?? 0)];
	}

	/**
	 * Download $url into $file, truncating whatever an earlier attempt may have written.
	 *
	 * @param File $file
	 * @param ?string $url
	 * @return array request result, error under 'error' on failure
	 */
	private function downloadFile(File $file, ?string $url): array {
		if ($url === null) {
			return ['error' => 'no download URL'];
		}
		$resource = $file->fopen('w');
		if ($resource === false) {
			return ['error' => 'could not open local file for writing'];
		}
		$res = $this->onedriveApiService->fileRequest($url, $resource);
		if (is_resource($resource)) {
			fclose($resource);
		}
		return $res;
	}
}
