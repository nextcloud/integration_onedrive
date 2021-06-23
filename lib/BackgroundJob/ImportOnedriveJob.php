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

use OCP\BackgroundJob\QueuedJob;
use OCP\AppFramework\Utility\ITimeFactory;

use OCA\Onedrive\Service\OnedriveStorageAPIService;

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

	public function run($arguments) {
		$userId = $arguments['user_id'];
		$this->service->importOnedriveJob($userId);
	}
}
