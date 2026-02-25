<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\Command;

use OCA\Onedrive\Service\OnedriveStorageAPIService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StartImport extends Command {
	public function __construct(
		private OnedriveStorageAPIService $onedriveStorageAPIService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('integration_onedrive:start-import')
			->addArgument('user_id', InputArgument::REQUIRED)
			->setDescription('Start OneDrive file import for the given user');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$this->onedriveStorageAPIService->startImportOnedrive($input->getArgument('user_id'));
		} catch (\Exception $ex) {
			$output->writeln('<error>Failed to start import</error>');
			$output->writeln($ex->getMessage());
			return 1;
		}

		return 0;
	}
}
