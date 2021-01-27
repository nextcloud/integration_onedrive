<?php
namespace OCA\Onedrive\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IL10N;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Util;
use OCP\IURLGenerator;
use OCP\IInitialStateService;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCA\Onedrive\AppInfo\Application;

class Personal implements ISettings {

	private $request;
	private $config;
	private $dataDirPath;
	private $urlGenerator;
	private $l;

	public function __construct(string $appName,
								IL10N $l,
								IRequest $request,
								IConfig $config,
								IURLGenerator $urlGenerator,
								IRootFolder $root,
								IUserManager $userManager,
								IInitialStateService $initialStateService,
								string $userId) {
		$this->appName = $appName;
		$this->urlGenerator = $urlGenerator;
		$this->request = $request;
		$this->l = $l;
		$this->root = $root;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->initialStateService = $initialStateService;
		$this->userId = $userId;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$token = $this->config->getUserValue($this->userId, Application::APP_ID, 'token', '');
		$navigationEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'navigation_enabled', '0');
		$userName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_name', '');

		// for OAuth
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '') !== '';

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
		$this->initialStateService->provideInitialState($this->appName, 'user-config', $userConfig);
		$response = new TemplateResponse(Application::APP_ID, 'personalSettings');
		return $response;
	}

	public function getSection(): string {
		return 'migration';
	}

	public function getPriority(): int {
		return 10;
	}
}
