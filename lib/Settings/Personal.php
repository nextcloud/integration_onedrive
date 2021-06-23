<?php
namespace OCA\Onedrive\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCA\Onedrive\AppInfo\Application;

class Personal implements ISettings {

	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IRootFolder
	 */
	private $root;
	/**
	 * @var IUserManager
	 */
	private $userManager;
	/**
	 * @var IInitialState
	 */
	private $initialStateService;
	/**
	 * @var string
	 */
	private $userId;

	public function __construct(IConfig $config,
								IRootFolder $root,
								IUserManager $userManager,
								IInitialState $initialStateService,
								string $userId) {
		$this->config = $config;
		$this->root = $root;
		$this->userManager = $userManager;
		$this->initialStateService = $initialStateService;
		$this->userId = $userId;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$token = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');
		$navigationEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'navigation_enabled', '0');
		$userName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_name');

		// for OAuth
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret') !== '';

		// get free space
		$userFolder = $this->root->getUserFolder($this->userId);
		$freeSpace = $userFolder->getStorage()->free_space('/');
		$user = $this->userManager->get($this->userId);

		$onedriveOutputDir = $this->config->getUserValue($this->userId, Application::APP_ID, 'onedrive_output_dir', '/OneDrive import');

		$userConfig = [
			'token' => $token,
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'navigation_enabled' => ($navigationEnabled === '1'),
			'user_name' => $userName,
			'free_space' => $freeSpace,
			'user_quota' => $user->getQuota(),
			'onedrive_output_dir' => $onedriveOutputDir,
		];
		$this->initialStateService->provideInitialState('user-config', $userConfig);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'migration';
	}

	public function getPriority(): int {
		return 10;
	}
}
