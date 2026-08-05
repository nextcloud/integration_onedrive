<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\Tests;

use InvalidArgumentException;
use OCA\Onedrive\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase {

	private Notifier $notifier;

	public function setUp(): void {
		parent::setUp();

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []) => vsprintf($text, is_array($parameters) ? $parameters : [$parameters])
		);
		$l->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count) => str_replace('%n', (string)$count, $count === 1 ? $singular : $plural)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('imagePath')->willReturn('img/app-dark.svg');
		$url->method('getAbsoluteURL')->willReturn('http://nc.example.org/img/app-dark.svg');
		$url->method('linkToRouteAbsolute')->willReturn('http://nc.example.org/apps/files');

		$this->notifier = new Notifier(
			$factory,
			$this->createMock(IUserManager::class),
			$this->createMock(INotificationManager::class),
			$url,
		);
	}

	private function prepare(array $subjectParameters, string $expectedSubject, ?string $expectedMessage = null, string $subject = 'import_onedrive_finished', string $app = 'integration_onedrive'): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($subjectParameters);
		$notification->expects($this->once())
			->method('setParsedSubject')
			->with($expectedSubject)
			->willReturnSelf();
		if ($expectedMessage === null) {
			$notification->expects($this->never())->method('setParsedMessage');
		} else {
			$notification->expects($this->once())
				->method('setParsedMessage')
				->with($expectedMessage)
				->willReturnSelf();
		}
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('setLink')->willReturnSelf();

		$this->notifier->prepare($notification, 'en');
	}

	public function testFinishedWithoutFailures(): void {
		$this->prepare(
			['nbImported' => 5, 'nbFailed' => 0, 'targetPath' => '/OneDrive import'],
			'5 files were imported from OneDrive storage.'
		);
	}

	public function testFinishedWithFailures(): void {
		$this->prepare(
			['nbImported' => 5, 'nbFailed' => 2, 'failedFiles' => ['a.jpg', 'b.jpg'], 'targetPath' => '/OneDrive import'],
			'5 files were imported from OneDrive storage.'
			. ' 2 files could not be downloaded, check the server logs for details.',
			'Could not download: a.jpg, b.jpg'
		);
	}

	public function testFinishedWithSingleImportAndSingleFailure(): void {
		$this->prepare(
			['nbImported' => 1, 'nbFailed' => 1, 'failedFiles' => ['a.jpg'], 'targetPath' => '/OneDrive import'],
			'1 file was imported from OneDrive storage.'
			. ' 1 file could not be downloaded, check the server logs for details.',
			'Could not download: a.jpg'
		);
	}

	public function testFinishedWithMoreFailuresThanReportedNames(): void {
		$this->prepare(
			['nbImported' => 5, 'nbFailed' => 12, 'failedFiles' => ['a.jpg', 'b.jpg', 'c.jpg'], 'targetPath' => '/OneDrive import'],
			'5 files were imported from OneDrive storage.'
			. ' 12 files could not be downloaded, check the server logs for details.',
			'Could not download: a.jpg, b.jpg, c.jpg, and 9 more'
		);
	}

	public function testFinishedWithSkippedFiles(): void {
		$this->prepare(
			['nbImported' => 7, 'nbFailed' => 0, 'nbSkipped' => 1993, 'targetPath' => '/OneDrive import'],
			'7 files were imported from OneDrive storage. 1993 files were already there.'
		);
	}

	public function testRerunWithNothingNewExplainsItself(): void {
		$this->prepare(
			['nbImported' => 0, 'nbFailed' => 0, 'nbSkipped' => 2000, 'targetPath' => '/OneDrive import'],
			'0 files were imported from OneDrive storage. 2000 files were already there.'
		);
	}

	public function testNotificationQueuedBeforeUpgradeHasNoFailedCount(): void {
		$this->prepare(
			['nbImported' => 4, 'targetPath' => '/OneDrive import'],
			'4 files were imported from OneDrive storage.'
		);
	}

	public function testThrowsForOtherApp(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('some_other_app');

		$this->expectException(InvalidArgumentException::class);
		$this->notifier->prepare($notification, 'en');
	}

	public function testThrowsForUnknownSubject(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('integration_onedrive');
		$notification->method('getSubject')->willReturn('some_unknown_subject');

		$this->expectException(InvalidArgumentException::class);
		$this->notifier->prepare($notification, 'en');
	}
}
