<?php

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\Notification;

use OCA\Onedrive\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {

	/** @var IFactory */
	protected $factory;

	/** @var IUserManager */
	protected $userManager;

	/** @var INotificationManager */
	protected $notificationManager;

	/** @var IURLGenerator */
	protected $url;

	/**
	 * @param IFactory $factory
	 * @param IUserManager $userManager
	 * @param INotificationManager $notificationManager
	 * @param IURLGenerator $urlGenerator
	 */
	public function __construct(IFactory $factory,
		IUserManager $userManager,
		INotificationManager $notificationManager,
		IURLGenerator $urlGenerator) {
		$this->factory = $factory;
		$this->userManager = $userManager;
		$this->notificationManager = $notificationManager;
		$this->url = $urlGenerator;
	}

	/**
	 * Identifier of the notifier, only use [a-z0-9_]
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getID(): string {
		return 'integration_onedrive';
	}
	/**
	 * Human readable name describing the notifier
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getName(): string {
		return $this->factory->get('integration_onedrive')->t('OneDrive');
	}

	/**
	 * The sentences an import adds about the files it did not bring, if there were any.
	 */
	private function whatElseHappened(IL10N $l, int $nbSkipped, int $nbFailed): string {
		$content = '';
		if ($nbSkipped > 0) {
			$content .= ' ' . $l->n(
				'%n file was already there.',
				'%n files were already there.',
				$nbSkipped
			);
		}
		if ($nbFailed > 0) {
			$content .= ' ' . $l->n(
				'%n file could not be downloaded, check the server logs for details.',
				'%n files could not be downloaded, check the server logs for details.',
				$nbFailed
			);
		}
		return $content;
	}

	/**
	 * @param INotification $notification
	 * @param string $languageCode The code of the language that should be used to prepare the notification
	 * @return INotification
	 * @throws UnknownNotificationException When the notification was not prepared by a notifier
	 * @since 9.0.0
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'integration_onedrive') {
			// Not my app => throw
			throw new UnknownNotificationException();
		}

		$l = $this->factory->get('integration_onedrive', $languageCode);

		switch ($notification->getSubject()) {
			case 'import_onedrive_finished':
				/** @var array{nbImported?: string, nbFailed?: string, nbSkipped?: string, failedFiles?: string[], targetPath: string} $p */
				$p = $notification->getSubjectParameters();
				$nbImported = (int)($p['nbImported'] ?? 0);
				$nbFailed = (int)($p['nbFailed'] ?? 0);
				$nbSkipped = (int)($p['nbSkipped'] ?? 0);
				$failedFiles = is_array($p['failedFiles'] ?? null) ? $p['failedFiles'] : [];
				$targetPath = $p['targetPath'];
				$content = $l->n('%n file was imported from OneDrive storage.', '%n files were imported from OneDrive storage.', $nbImported)
					. $this->whatElseHappened($l, $nbSkipped, $nbFailed);

				if ($failedFiles !== []) {
					$names = implode(', ', $failedFiles);
					$nbMore = $nbFailed - count($failedFiles);
					$notification->setParsedMessage(
						$nbMore > 0
							? $l->t('Could not download: %1$s, and %2$s more', [$names, (string)$nbMore])
							: $l->t('Could not download: %s', [$names])
					);
				}
				$notification->setParsedSubject($content)
					->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')))
					->setLink($this->url->linkToRouteAbsolute('files.view.index', ['dir' => $targetPath]));
				return $notification;
			case 'import_onedrive_stopped':
				/** @var array{nbImported?: string, nbFailed?: string, nbSkipped?: string, targetPath: string} $p */
				$p = $notification->getSubjectParameters();
				$nbImported = (int)($p['nbImported'] ?? 0);
				$nbFailed = (int)($p['nbFailed'] ?? 0);
				$nbSkipped = (int)($p['nbSkipped'] ?? 0);
				$targetPath = $p['targetPath'];
				$notification
					->setParsedSubject($l->t('The import of your OneDrive files stopped before it was finished, check the server logs for details.'))
					->setParsedMessage(
						$l->n('%n file was imported from OneDrive storage.', '%n files were imported from OneDrive storage.', $nbImported)
						. $this->whatElseHappened($l, $nbSkipped, $nbFailed)
					)
					->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')))
					->setLink($this->url->linkToRouteAbsolute('files.view.index', ['dir' => $targetPath]));
				return $notification;
			default:
				// Unknown subject => Unknown notification => throw
				throw new UnknownNotificationException();
		}
	}
}
