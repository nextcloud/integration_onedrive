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

use Datetime;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;
use OCP\IConfig;
use OCP\Files\IRootFolder;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\Files\ForbiddenException;
use OCP\BackgroundJob\IJobList;

use OCA\Onedrive\AppInfo\Application;
use OCA\Onedrive\BackgroundJob\ImportOnedriveJob;
use OCA\Onedrive\Exceptions\MaxDownloadSizeReachedException;

class OnedriveStorageAPIService {
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
	 * Service to make requests to Onedrive API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IRootFolder $root,
								IConfig $config,
								IJobList $jobList,
								OnedriveAPIService $onedriveApiService) {
		$this->appName = $appName;
		$this->logger = $logger;
		$this->root = $root;
		$this->config = $config;
		$this->jobList = $jobList;
		$this->onedriveApiService = $onedriveApiService;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getStorageSize(string $accessToken, string $userId): array {
		// onedrive storage size
//		$onedriveUserId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id');
		$result = $this->onedriveApiService->request($accessToken, $userId, 'me/drive');
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
		$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', '0');
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
		$importingOnedrive = $this->config->getUserValue($userId, Application::APP_ID, 'importing_onedrive', '0') === '1';
		$jobRunning = $this->config->getUserValue($userId, Application::APP_ID, 'onedrive_import_running', '0') === '1';
		if (!$importingOnedrive || $jobRunning) {
			return;
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'onedrive_import_running', '1');

		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		// import batch of files
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'onedrive_output_dir', '/OneDrive import');
		$targetPath = $targetPath ?: '/OneDrive import';
		// get previous progress
		$importTreeStr = $this->config->getUserValue($userId, Application::APP_ID, 'import_tree', '[]');
		$importTree = ($importTreeStr === '[]' || $importTreeStr === '') ? [] : json_decode($importTreeStr, true);
		// import by batch of 500 MB
		$alreadyImportedSize = (float) $this->config->getUserValue($userId, Application::APP_ID, 'imported_size', '0');
		$alreadyImportedNumber = (int) $this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$result = $this->importFiles($accessToken, $userId, $targetPath, 500000000, $alreadyImportedSize, $alreadyImportedNumber, $importTree);
		if (isset($result['error']) || (isset($result['finished']) && $result['finished'])) {
			$this->config->setUserValue($userId, Application::APP_ID, 'importing_onedrive', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'imported_size', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', '0');
			if (isset($result['finished']) && $result['finished']) {
				$this->config->deleteUserValue($userId, Application::APP_ID, 'import_tree');
				$this->onedriveApiService->sendNCNotification($userId, 'import_onedrive_finished', [
					'nbImported' => $result['totalSeenNumber'],
					'targetPath' => $targetPath,
				]);
			}
		} else {
			// save progress
			$this->config->setUserValue($userId, Application::APP_ID, 'import_tree', json_encode($importTree));
			$ts = (new Datetime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', $ts);
			$this->jobList->add(ImportOnedriveJob::class, ['user_id' => $userId]);
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'onedrive_import_running', '0');
	}

	/**
	 * @param string $accessToken
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
	public function importFiles(string $accessToken, string $userId, string $targetPath,
								?int $maxDownloadSize = null, float $alreadyImportedSize = 0, int $alreadyImportedNumber = 0,
								array &$importTree = []): array {
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$topFolder = $userFolder->newFolder($targetPath);
		} else {
			$topFolder = $userFolder->get($targetPath);
			if ($topFolder->getType() !== FileInfo::TYPE_FOLDER) {
				return ['error' => 'Impossible to create ' . $targetPath . ' folder'];
			}
		}

		$info = $this->getStorageSize($accessToken, $userId);
		if (isset($info['error'])) {
			return $info;
		}
//		$onedriveStorageSize = $info['usageInStorage'];

		// iterate on unfinished directory list retrieved with getUserValue
		try {
			if (count($importTree) === 0) {
				$downloadResult = $this->downloadDir(
					$accessToken, $userId, $topFolder, $maxDownloadSize, 0, 0, 0, '', $alreadyImportedSize, $alreadyImportedNumber, $importTree
				);
			} else {
				foreach ($importTree as $path => $state) {
					if ($state === 'todo') {
						$downloadResult = $this->downloadDir(
							$accessToken, $userId, $topFolder, $maxDownloadSize, 0, 0, 0, $path, $alreadyImportedSize, $alreadyImportedNumber, $importTree
						);
					}
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

	private function downloadDir(string $accessToken, string $userId, Folder $topFolder,
								?int $maxDownloadSize, int $downloadedSize, int $totalSeenNumber,
								int $nbDownloaded, string $path, float $alreadyImportedSize, int $alreadyImportedNumber,
								array &$importTree): array {
		$newDownloadedSize = $downloadedSize;
		$newTotalSeenNumber = $totalSeenNumber;
		$newNbDownloaded = $nbDownloaded;

		// create dir if needed
		if (!$topFolder->nodeExists($path)) {
			$folder = $topFolder->newFolder($path);
		} else {
			$folder = $topFolder->get($path);
		}

		$reqPath = ($path === '')
			? ''
			: ':' . $path . ':';
		$endPoint = 'me/drive/root' . $reqPath . '/children';
		$params = [
			'filter' => 'file ne null',
		];
		do {
			$result = $this->onedriveApiService->request($accessToken, $userId, $endPoint, $params);
			if (isset($result['error']) || !isset($result['value']) || !is_array($result['value'])) {
				return [
					'downloadedSize' => $newDownloadedSize,
					'totalSeenNumber' => $newTotalSeenNumber,
					'nbDownloaded' => $newNbDownloaded,
				];
			}
			// first get all files
			foreach ($result['value'] as $item) {
				if (isset($item['file'])) {
					$newTotalSeenNumber++;
					$size = $this->getFile($folder, $item);
					$newDownloadedSize += ($size ?? 0);
					if (!is_null($size) && $size > 0) {
						$newNbDownloaded++;
						$this->config->setUserValue($userId, Application::APP_ID, 'imported_size', $alreadyImportedSize + $newDownloadedSize);
						$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', $alreadyImportedNumber + $newNbDownloaded);
						$ts = (new Datetime())->getTimestamp();
						$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', $ts);
					}
					if (!is_null($maxDownloadSize) && $newDownloadedSize >= $maxDownloadSize) {
						throw new MaxDownloadSizeReachedException('Yep');
					}
				}
			}
			// REMOVE this directory from unfinished list
			if (array_key_exists($path, $importTree)) {
				unset($importTree[$path]);
			}
			if (isset($result['@odata.nextLink'])
				&& $result['@odata.nextLink']
				&& preg_match('/\$skiptoken=/i', $result['@odata.nextLink'])) {
				$params['$skiptoken'] = preg_replace('/.*\$skiptoken=/', '', $result['@odata.nextLink']);
			}
		} while (isset($result['@odata.nextLink']) && $result['@odata.nextLink']);

		// DIRECTORIES
		$params = [
			'filter' => 'folder ne null',
		];
		do {
			$result = $this->onedriveApiService->request($accessToken, $userId, $endPoint, $params);
			if (isset($result['error']) || !isset($result['value']) || !is_array($result['value'])) {
				return [
					'downloadedSize' => $newDownloadedSize,
					'totalSeenNumber' => $newTotalSeenNumber,
					'nbDownloaded' => $newNbDownloaded,
				];
			}
			// store directories we have to do
			foreach ($result['value'] as $item) {
				if (isset($item['folder'])) {
					$subDirPath = $path . '/' . $item['name'];
					$importTree[$subDirPath] = 'todo';
				}
			}
			// then explore sub directories
			foreach ($result['value'] as $item) {
				if (isset($item['folder'])) {
					$subDownloadResult = $this->downloadDir(
						$accessToken, $userId, $topFolder, $maxDownloadSize, $newDownloadedSize, $newTotalSeenNumber, $newNbDownloaded,
						$path . '/' . $item['name'], $alreadyImportedSize, $alreadyImportedNumber, $importTree
					);
					$newDownloadedSize = $subDownloadResult['downloadedSize'];
					$newTotalSeenNumber = $subDownloadResult['totalSeenNumber'];
					$newNbDownloaded = $subDownloadResult['nbDownloaded'];
				}
			}
			if (isset($result['@odata.nextLink'])
				&& $result['@odata.nextLink']
				&& preg_match('/\$skiptoken=/i', $result['@odata.nextLink'])) {
				$params['$skiptoken'] = preg_replace('/.*\$skiptoken=/', '', $result['@odata.nextLink']);
			}
		} while (isset($result['@odata.nextLink']) && $result['@odata.nextLink']);
		return [
			'downloadedSize' => $newDownloadedSize,
			'totalSeenNumber' => $newTotalSeenNumber,
			'nbDownloaded' => $newNbDownloaded,
		];
	}

	/**
	 * @param array $fileItem
	 * @param Folder $folder
	 * @return ?float downloaded size, null if already existing or network error
	 */
	private function getFile(Folder $folder, array $fileItem): ?float {
		$fileName = $fileItem['name'];
		try {
            $fileExists = $folder->nodeExists($fileName);
        } catch (ForbiddenException $e) {
            return null;
        }
		if (!$fileExists) {
			$savedFile = $folder->newFile($fileName);
			$resource = $savedFile->fopen('w');
			$res = $this->onedriveApiService->fileRequest($fileItem['@microsoft.graph.downloadUrl'], $resource);
			if (!isset($res['error'])) {
				if (isset($fileItem['lastModifiedDateTime'])) {
					$d = new Datetime($fileItem['lastModifiedDateTime']);
					$ts = $d->getTimestamp();
					$savedFile->touch($ts);
				} else {
					$savedFile->touch();
				}
				$stat = $savedFile->stat();
				return (float) $stat['size'] ?? 0;
			} else {
				// there was an error
				$this->logger->warning('OneDrive error downloading file ' . $fileName . ' : ' . $res['error'], ['app' => $this->appName]);
				if ($savedFile->isDeletable()) {
					$savedFile->delete();
				}
				return null;
			}
		} else {
			// file exists
			return 0;
		}
	}
}
