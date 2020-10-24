<?php
/**
 * Nextcloud - Onedrive
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Onedrive\AppInfo;

use OCP\IContainer;
use OCP\IUserSession;

use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\Notification\IManager as INotificationManager;

use OCA\Onedrive\Search\OnedriveSearchReposProvider;
use OCA\Onedrive\Search\OnedriveSearchIssuesProvider;
use OCA\Onedrive\Notification\Notifier;

/**
 * Class Application
 *
 * @package OCA\Onedrive\AppInfo
 */
class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_onedrive';

	/**
	 * Constructor
	 *
	 * @param array $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();
		$this->container = $container;
		$this->config = $container->query(\OCP\IConfig::class);

		$manager = $container->query(INotificationManager::class);
        $manager->registerNotifierService(Notifier::class);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(\Closure::fromCallable([$this, 'registerNavigation']));
	}

	public function registerNavigation(IUserSession $userSession): void {
		$user = $userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
			$container = $this->container;

			if ($this->config->getUserValue($userId, self::APP_ID, 'navigation_enabled', '0') === '1') {
				$container->query(\OCP\INavigationManager::class)->add(function () use ($container) {
					$urlGenerator = $container->query(\OCP\IURLGenerator::class);
					$l10n = $container->query(\OCP\IL10N::class);
					return [
						'id' => self::APP_ID,

						'order' => 10,

						// the route that will be shown on startup
						'href' => 'https://onedrive.live.com',

						// the icon that will be shown in the navigation
						// this file needs to exist in img/
						'icon' => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),

						// the title of your application. This will be used in the
						// navigation or on the settings page of your app
						'name' => $l10n->t('Microsoft OneDrive'),
					];
				});
			}
		}
	}
}

