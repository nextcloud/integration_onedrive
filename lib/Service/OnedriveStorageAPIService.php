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
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
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
		$targetPath = $this->l10n->t('Onedrive import');
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

		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token', '');
		//$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token', '');
		//$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
		//$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');
		// import batch of files
		$targetPath = $this->l10n->t('Onedrive import');
		// import by batch of 500 Mo
		$alreadyImportedSize = $this->config->getUserValue($userId, Application::APP_ID, 'imported_size', '0');
		$alreadyImportedSize = (int) $alreadyImportedSize;
		$alreadyImportedNumber = $this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$alreadyImportedNumber = (int) $alreadyImportedNumber;
		$result = $this->importFiles($accessToken, $userId, $targetPath, 500000000, $alreadyImportedSize, $alreadyImportedNumber);
		if (isset($result['error']) || (isset($result['finished']) && $result['finished'])) {
			$this->config->setUserValue($userId, Application::APP_ID, 'importing_onedrive', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'imported_size', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', '0');
			if (isset($result['finished']) && $result['finished']) {
				$this->onedriveApiService->sendNCNotification($userId, 'import_onedrive_finished', [
					'nbImported' => $result['totalSeenNumber'],
					'targetPath' => $targetPath,
				]);
			}
		} else {
			$ts = (new \Datetime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', $ts);
			$this->jobList->add(ImportOnedriveJob::class, ['user_id' => $userId]);
		}
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
								?int $maxDownloadSize = null, int $alreadyImportedSize, int $alreadyImportedNumber): array {
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$folder = $userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
				return ['error' => 'Impossible to create ' . $targetPath . ' folder'];
			}
		}

		$info = $this->getStorageSize($accessToken, $userId);
		if (isset($info['error'])) {
			return $info;
		}
		$onedriveStorageSize = $info['usageInStorage'];

		try {
			$downloadResult = $this->downloadDir(
				$accessToken, $userId, $folder, $maxDownloadSize, 0, 0, 0, '', $alreadyImportedSize, $alreadyImportedNumber
			);
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

	private function downloadDir(string $accessToken, string $userId, Node $folder,
								?int $maxDownloadSize, int $downloadedSize, int $totalSeenNumber,
								int $nbDownloaded, string $path, int $alreadyImportedSize, int $alreadyImportedNumber): array {
		$newDownloadedSize = $downloadedSize;
		$newTotalSeenNumber = $totalSeenNumber;
		$newNbDownloaded = $nbDownloaded;

		$reqPath = $path === ''
			? ''
			: ':/' . $path . ':';
		$endPoint = 'me/drive/root' . $reqPath . '/children';
		$result = $this->onedriveApiService->request($accessToken, $userId, $endPoint);
		if (isset($result['error']) || !isset($result['value']) || !is_array($result['value'])) {
			return [
				'downloadedSize' => $newDownloadedSize,
				'totalSeenNumber' => $newTotalSeenNumber,
				'nbDownloaded' => $newNbDownloaded,
			];
		}
		foreach ($result['value'] as $item) {
			if (isset($item['file'])) {
				$newTotalSeenNumber++;
				$size = $this->getFile($accessToken, $userId, $folder, $item);
				$newDownloadedSize += $size;
				if ($size > 0) {
					$newNbDownloaded++;
					$this->config->setUserValue($userId, Application::APP_ID, 'imported_size', $alreadyImportedSize + $newDownloadedSize);
					$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', $alreadyImportedNumber + $newNbDownloaded);
					$ts = (new \Datetime())->getTimestamp();
					$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', $ts);
				}
				if (!is_null($maxDownloadSize) && $newDownloadedSize >= $maxDownloadSize) {
					throw new MaxDownloadSizeReachedException('Yep');
				}
			} elseif (isset($item['folder'])) {
				// create folder if needed
				if (!$folder->nodeExists($item['name'])) {
					$subFolder = $folder->newFolder($item['name']);
				} else {
					$subFolder = $folder->get($item['name']);
				}
				$subDownloadResult = $this->downloadDir(
					$accessToken, $userId, $subFolder, $maxDownloadSize, $newDownloadedSize, $newTotalSeenNumber, $newNbDownloaded,
					$path . '/' . $item['name'], $alreadyImportedSize, $alreadyImportedNumber
				);
				$newDownloadedSize = $subDownloadResult['downloadedSize'];
				$newTotalSeenNumber = $subDownloadResult['totalSeenNumber'];
				$newNbDownloaded = $subDownloadResult['nbDownloaded'];
			}
		}
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
	private function getFile(string $accessToken, string $userId, Node $folder, array $fileItem): int {
		$fileName = $fileItem['name'];
		if (!$folder->nodeExists($fileName)) {
			$savedFile = $folder->newFile($fileName);
			$resource = $savedFile->fopen('w');
			$res = $this->onedriveApiService->fileRequest($fileItem['@microsoft.graph.downloadUrl'], $resource);
			if (!isset($res['error'])) {
				fclose($resource);
				$savedFile->touch();
				$stat = $savedFile->stat();
				return $stat['size'] ?? 0;
			}
			fclose($resource);
			$savedFile->delete();
		}
		return 0;
	}
}
