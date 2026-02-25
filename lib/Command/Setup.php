<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\Command;

use OCA\Onedrive\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Setup extends Command {
	public function __construct(
		private IConfig $config,
		private ICrypto $crypto,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('integration_onedrive:setup')
			->addArgument('client_id', InputArgument::REQUIRED)
			->addArgument('client_secret', InputArgument::REQUIRED)
			->setDescription('Setup the OneDrive OAuth client credentials');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			/** @psalm-suppress DeprecatedMethod */
			$this->config->setAppValue(Application::APP_ID, 'client_id', $input->getArgument('client_id'));
			$encryptedSecret = $this->crypto->encrypt($input->getArgument('client_secret'));
			/** @psalm-suppress DeprecatedMethod */
			$this->config->setAppValue(Application::APP_ID, 'client_secret', $encryptedSecret);
		} catch (\Exception $ex) {
			$output->writeln('<error>Failed to setup client credentials</error>');
			$output->writeln($ex->getMessage());
			return 1;
		}

		return 0;
	}
}
