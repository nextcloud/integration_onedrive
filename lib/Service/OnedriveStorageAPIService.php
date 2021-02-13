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
use OCP\IConfig;
use OCP\ITempManager;
use OCP\Files\IRootFolder;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\BackgroundJob\IJobList;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

use OCA\Onedrive\AppInfo\Application;
use OCA\Onedrive\BackgroundJob\ImportOnedriveJob;
use OCA\Onedrive\Exceptions\MaxDownloadSizeReachedException;

class OnedriveStorageAPIService {

	private $l10n;
	private $logger;

	/**
	 * Service to make requests to Onedrive API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IRootFolder $root,
								IConfig $config,
								IJobList $jobList,
								ITempManager $tempManager,
								OnedriveAPIService $onedriveApiService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->config = $config;
		$this->root = $root;
		$this->jobList = $jobList;
		$this->tempManager = $tempManager;
		$this->onedriveApiService = $onedriveApiService;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getStorageSize(string $accessToken, string $userId): array {
		// onedrive storage size
		$params = [];
		$onedriveUserId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id', '');
		$result = $this->onedriveApiService->request($accessToken, $userId, 'me/drive');
		if (isset($result['error']) || !isset($result['quota'], $result['quota']['used'])) {
			return $result;
		}
		$info = [
			'usageInStorage' => $result['quota']['used'],
		];
		$driveId = $result['id'] ?? '';
		return $info;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function startImportOnedrive(string $accessToken, string $userId): array {
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'onedrive_output_dir', '/OneDrive import');
		$targetPath = $targetPath ?: '/OneDrive import';

		$alreadyImporting = $this->config->getUserValue($userId, Application::APP_ID, 'importing_onedrive', '0') === '1';
		if ($alreadyImporting) {
			return ['targetPath' => $targetPath];
		}

		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$folder = $userFolder->newFolder($targetPath);
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
	 * @return array
	 */
	public function importOnedriveJob(string $userId): void {
		$this->logger->error('Importing onedrive files for ' . $userId);
		$importingOnedrive = $this->config->getUserValue($userId, Application::APP_ID, 'importing_onedrive', '0') === '1';
		if (!$importingOnedrive) {
			return;
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'job_running', '1');

		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token', '');
		// import batch of files
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'onedrive_output_dir', '/OneDrive import');
		$targetPath = $targetPath ?: '/OneDrive import';
		// get previous progress
		$importTreeStr = $this->config->getUserValue($userId, Application::APP_ID, 'import_tree', '[]');
		$importTree = ($importTreeStr === '[]' || $importTreeStr === '') ? [] : json_decode($importTreeStr, true);
		// import by batch of 500 MB
		$alreadyImportedSize = $this->config->getUserValue($userId, Application::APP_ID, 'imported_size', '0');
		$alreadyImportedSize = (int) $alreadyImportedSize;
		$alreadyImportedNumber = $this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$alreadyImportedNumber = (int) $alreadyImportedNumber;
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
			$ts = (new \Datetime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', $ts);
			$this->jobList->add(ImportOnedriveJob::class, ['user_id' => $userId]);
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'job_running', '0');
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $targetPath
	 * @param ?int $maxDownloadSize
	 * @param int $alreadyImported
	 * @return array
	 */
	public function importFiles(string $accessToken, string $userId, string $targetPath,
								?int $maxDownloadSize = null, int $alreadyImportedSize, int $alreadyImportedNumber,
								array &$importTree): array {
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
		$onedriveStorageSize = $info['usageInStorage'];

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

	private function downloadDir(string $accessToken, string $userId, Node $topFolder,
								?int $maxDownloadSize, int $downloadedSize, int $totalSeenNumber,
								int $nbDownloaded, string $path, int $alreadyImportedSize, int $alreadyImportedNumber,
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
					$size = $this->getFile($accessToken, $userId, $folder, $item);
					$newDownloadedSize += ($size ?? 0);
					if (!is_null($size) && $size > 0) {
						$newNbDownloaded++;
						$this->config->setUserValue($userId, Application::APP_ID, 'imported_size', $alreadyImportedSize + $newDownloadedSize);
						$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', $alreadyImportedNumber + $newNbDownloaded);
						$ts = (new \Datetime())->getTimestamp();
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
	 * @param string $accessToken
	 * @param string $userId
	 * @param array $fileItem
	 * @param Node $folder
	 * @return ?int downloaded size, null if already existing or network error
	 */
	private function getFile(string $accessToken, string $userId, Node $folder, array $fileItem): ?int {
		$fileName = $fileItem['name'];
		if (!$folder->nodeExists($fileName)) {
			$savedFile = $folder->newFile($fileName);
			$resource = $savedFile->fopen('w');
			$res = $this->onedriveApiService->fileRequest($fileItem['@microsoft.graph.downloadUrl'], $resource);
			if (!isset($res['error'])) {
				if (isset($fileItem['lastModifiedDateTime'])) {
					$d = new \Datetime($fileItem['lastModifiedDateTime']);
					$ts = $d->getTimestamp();
					$savedFile->touch($ts);
				} else {
					$savedFile->touch();
				}
				$stat = $savedFile->stat();
				return $stat['size'] ?? 0;
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
