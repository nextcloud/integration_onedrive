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
use OCP\Files\IRootFolder;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\BackgroundJob\IJobList;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

use OCA\Onedrive\AppInfo\Application;
use OCA\Onedrive\BackgroundJob\ImportOnedriveJob;

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
								OnedriveAPIService $onedriveApiService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->config = $config;
		$this->root = $root;
		$this->jobList = $jobList;
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
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token', '');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', DEFAULT_DROPBOX_CLIENT_ID);
		$clientID = $clientID ?: DEFAULT_DROPBOX_CLIENT_ID;
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', DEFAULT_DROPBOX_CLIENT_SECRET);
		$clientSecret = $clientSecret ?: DEFAULT_DROPBOX_CLIENT_SECRET;
		// import batch of files
		$targetPath = $this->l10n->t('Onedrive import');
		// import by batch of 500 Mo
		$alreadyImported = $this->config->getUserValue($userId, Application::APP_ID, 'imported_size', '0');
		$alreadyImported = (int) $alreadyImported;
		$result = $this->importFiles($accessToken, $refreshToken, $clientID, $clientSecret, $userId, $targetPath, 500000000, $alreadyImported);
		if (isset($result['error']) || (isset($result['finished']) && $result['finished'])) {
			$this->config->setUserValue($userId, Application::APP_ID, 'importing_onedrive', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'imported_size', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', '0');
			if (isset($result['finished']) && $result['finished']) {
				$this->onedriveApiService->sendNCNotification($userId, 'import_onedrive_finished', [
					'nbImported' => $result['totalSeen'],
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
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param string $userId
	 * @param string $targetPath
	 * @param ?int $maxDownloadSize
	 * @param int $alreadyImported
	 * @return array
	 */
	public function importFiles(string $accessToken, string $refreshToken, string $clientID, string $clientSecret,
								string $userId, string $targetPath,
								?int $maxDownloadSize = null, int $alreadyImported): array {
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
		$downloadedSize = 0;
		$totalSeenSize = 0;

		$params = [
			'limit' => 2000,
			'path' => '',
			'recursive' => true,
			'include_media_info' => false,
			'include_deleted' => false,
			'include_has_explicit_shared_members' => false,
			'include_mounted_folders' => true,
			'include_non_downloadable_files' => false,
		];
		do {
			$suffix = isset($params['cursor']) ? '/continue' : '';
			$result = $this->onedriveApiService->request(
				$accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'files/list_folder' . $suffix, $params, 'POST'
			);
			if (isset($result['error'])) {
				return $result;
			}
			if (isset($result['entries']) && is_array($result['entries'])) {
				foreach ($result['entries'] as $entry) {
					if (isset($entry['.tag']) && $entry['.tag'] === 'file') {
						$totalSeenNumber++;
						$size = $this->getFile($accessToken, $refreshToken, $clientID, $clientSecret, $userId, $entry, $folder);
						if (!is_null($size)) {
							$downloadedSize += $size;
							$this->config->setUserValue($userId, Application::APP_ID, 'imported_size', $alreadyImported + $downloadedSize);
							if ($maxDownloadSize && $downloadedSize > $maxDownloadSize) {
								return [
									'targetPath' => $targetPath,
									'finished' => ($totalSeenSize >= $onedriveStorageSize),
									'totalSeen' => $totalSeenNumber,
								];
							}
						}
					}
				}
			}
			$params = [
				'cursor' => $result['cursor'] ?? '',
			];
		} while (isset($result['has_more'], $result['cursor']) && $result['has_more']);

		return [
			'targetPath' => $targetPath,
			'finished' => true,
			'totalSeen' => $totalSeenNumber,
		];
	}

	/**
	 * @param string $accessToken
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param string $userId
	 * @param array $fileItem
	 * @param Node $topFolder
	 * @return ?int downloaded size, null if already existing or network error
	 */
	private function getFile(string $accessToken, string $refreshToken, string $clientID, string $clientSecret,
							string $userId, array $fileItem, Node $topFolder): ?int {
		$fileName = $fileItem['name'];
		$path = preg_replace('/^\//', '', $fileItem['path_display']);
		$pathParts = pathinfo($path);
		$dirName = $pathParts['dirname'];
		if ($dirname === '.') {
			$saveFolder = $topFolder;
		} else {
			$saveFolder = $this->createAndGetFolder($dirName, $topFolder);
		}
		if (!is_null($saveFolder) && !$saveFolder->nodeExists($fileName)) {
			$res = $this->onedriveApiService->fileRequest($accessToken, $refreshToken, $clientID, $clientSecret, $userId, $fileItem['id']);
			if (!isset($res['error'])) {
				$savedFile = $saveFolder->newFile($fileName, $res['content']);
				return $savedFile->getSize();
			}
		}
		return null;
	}

	/**
	 * @param string $dirName
	 * @param Node $topFolder
	 * @return ?Node
	 */
	private function createAndGetFolder(string $dirName, Node $topFolder): ?Node {
		$dirs = explode('/', $dirName);
		$seenDirs = [];
		$dirNode = $topFolder;
		foreach ($dirs as $dir) {
			if (!$dirNode->nodeExists($dir)) {
				$dirNode = $dirNode->newFolder($dir);
			} else {
				$dirNode = $dirNode->get($dir);
				if ($dirNode->getType() !== FileInfo::TYPE_FOLDER) {
					return null;
				}
			}
		}
		return $dirNode;
	}
}
