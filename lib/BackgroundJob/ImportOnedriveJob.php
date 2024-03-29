<?php

/**
 * Nextcloud - integration_onedrive
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
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
