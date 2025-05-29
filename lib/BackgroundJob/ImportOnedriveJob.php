<?php

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\BackgroundJob;

use OCA\Onedrive\Service\OnedriveStorageAPIService;
use OCP\AppFramework\Utility\ITimeFactory;

use OCP\BackgroundJob\QueuedJob;

class ImportOnedriveJob extends QueuedJob {
	/**
	 * @var OnedriveStorageAPIService
	 */
	private $service;

	/**
	 * A QueuedJob to partially import onedrive files and launch following job
	 *
	 */
	public function __construct(ITimeFactory $timeFactory,
		OnedriveStorageAPIService $service) {
		parent::__construct($timeFactory);
		$this->service = $service;
	}

	/**
	 * @param array{user_id: string} $argument
	 * @return void
	 */
	public function run($argument): void {
		$userId = $argument['user_id'];
		$this->service->importOnedriveJob($userId);
	}
}
