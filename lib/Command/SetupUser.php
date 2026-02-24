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

class SetupUser extends Command {
	public function __construct(
		private IConfig $config,
		private ICrypto $crypto,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('integration_onedrive:setup-user')
			->addArgument('userId', InputArgument::REQUIRED)
			->addArgument('userName', InputArgument::REQUIRED)
			->addArgument('refresh_token', InputArgument::REQUIRED)
			->addArgument('access_token', InputArgument::REQUIRED)
			->setDescription('Setup user OAuth tokens for OneDrive');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$userId = $input->getArgument('userId');
			$this->config->setUserValue($userId, Application::APP_ID, 'user_name', $input->getArgument('userName'));
			$encryptedRefreshToken = $this->crypto->encrypt($input->getArgument('refresh_token'));
			$this->config->setUserValue($userId, Application::APP_ID, 'refresh_token', $encryptedRefreshToken);
			$encryptedAccessToken = $this->crypto->encrypt($input->getArgument('access_token'));
			$this->config->setUserValue($userId, Application::APP_ID, 'token', $encryptedAccessToken);
			// Set token_expires_at to 0 so the token gets refreshed on first use
			$this->config->setUserValue($userId, Application::APP_ID, 'token_expires_at', '0');
		} catch (\Exception $ex) {
			$output->writeln('<error>Failed to setup user credentials</error>');
			$output->writeln($ex->getMessage());
			return 1;
		}

		return 0;
	}
}
