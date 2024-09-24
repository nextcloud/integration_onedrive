<?php
/**
 * Nextcloud - Onedrive
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Onedrive\AppInfo;

use Closure;
use OCA\Onedrive\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

use OCP\IConfig;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IURLGenerator;

use OCP\IUserSession;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/../../vendor/autoload.php';

class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_onedrive';
	public const IMPORT_JOB_TIMEOUT = 3600;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(Closure::fromCallable([$this, 'registerNavigation']));
	}

	public function registerNavigation(IUserSession $userSession): void {
		$user = $userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
			/** @var ContainerInterface $container */
			$container = $this->getContainer();
			$config = $container->get(IConfig::class);

			if ($config->getUserValue($userId, self::APP_ID, 'navigation_enabled', '0') === '1') {
				/** @var INavigationManager $navManager */
				$navManager = $container->get(INavigationManager::class);
				$navManager->add(function () use ($container) {
					/** @var IURLGenerator $urlGenerator */
					$urlGenerator = $container->get(IURLGenerator::class);
					/** @var IL10N $l10n */
					$l10n = $container->get(IL10N::class);
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
