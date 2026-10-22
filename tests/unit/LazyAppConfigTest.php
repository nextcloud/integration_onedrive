<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\Tests;

use ArrayObject;
use OCA\Onedrive\Command\Setup;
use OCA\Onedrive\Controller\ConfigController;
use OCA\Onedrive\Migration\Version3300Date202410151354;
use OCA\Onedrive\Migration\Version3601Date202610211028;
use OCA\Onedrive\Service\OnedriveAPIService;
use OCA\Onedrive\Service\OnedriveStorageAPIService;
use OCA\Onedrive\Settings\Admin;
use OCA\Onedrive\Settings\Personal;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\Storage\IStorage;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig as IGlobalAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Migration\IOutput;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The app config values of this app are lazy: Nextcloud only loads them when the app asks for them.
 * A lazy value read without lazy: true can come back as the default, and a write without it stores
 * the value as a non-lazy one again, so every read and write has to pass the flag.
 */
class LazyAppConfigTest extends TestCase {

	private const LAZY_KEYS = ['client_id', 'client_secret', 'use_popup', 'api_base_url', 'import_batch_size'];

	/** @var array<string, MockObject> constructor arguments by type */
	private array $mocks = [];

	/** @var array<string, string> what the mocked app config holds */
	private array $appValues = [];

	/** @var array<string, string> what the mocked user preferences hold */
	private array $userValues = [];

	/** @var list<array> every app config read and write, with its lazy flag */
	private array $appConfigCalls = [];

	public function setUp(): void {
		parent::setUp();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnCallback(
			function (string $key, string $default = '', bool $lazy = false): string {
				$this->appConfigCalls[] = ['get', $key, $lazy];
				return $this->appValues[$key] ?? $default;
			}
		);
		$appConfig->method('setAppValueString')->willReturnCallback(
			function (string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->appConfigCalls[] = ['set', $key, $value, $lazy];
				return true;
			}
		);
		// the untyped methods store and read the value as a non-lazy one
		$appConfig->expects($this->never())->method('getAppValue');
		$appConfig->expects($this->never())->method('setAppValue');
		$this->mocks[IAppConfig::class] = $appConfig;

		$globalAppConfig = $this->createMock(IGlobalAppConfig::class);
		$globalAppConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '', bool $lazy = false): string {
				$this->appConfigCalls[] = ['get', $app, $key, $lazy];
				return $this->appValues[$key] ?? $default;
			}
		);
		$globalAppConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->appConfigCalls[] = ['set', $app, $key, $value, $lazy];
				return true;
			}
		);
		$globalAppConfig->method('updateLazy')->willReturnCallback(
			function (string $app, string $key, bool $lazy): bool {
				$this->appConfigCalls[] = ['updateLazy', $app, $key, $lazy];
				return true;
			}
		);
		$this->mocks[IGlobalAppConfig::class] = $globalAppConfig;

		// IConfig is only there for user preferences and system values, its app config methods are
		// the legacy ones that cannot read a lazy value
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('getAppValue');
		$config->expects($this->never())->method('setAppValue');
		$config->expects($this->never())->method('deleteAppValue');
		$config->method('getUserValue')->willReturnCallback(
			fn (string $userId, string $appName, string $key, $default = '') => $this->userValues[$key] ?? $default
		);
		$this->mocks[IConfig::class] = $config;

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'encrypted:' . $plain);
		$crypto->method('decrypt')->willReturnCallback(static fn (string $encrypted): string => substr($encrypted, strlen('encrypted:')));
		$this->mocks[ICrypto::class] = $crypto;
	}

	/**
	 * Creates the class with the mocks of this test case as constructor arguments, matched by type.
	 * Arguments without a class type are taken from $byName.
	 */
	private function instantiate(string $class, array $byName = []): object {
		return new $class(...$this->constructorArguments($class, $byName));
	}

	private function constructorArguments(string $class, array $byName = []): array {
		$arguments = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			if (array_key_exists($parameter->getName(), $byName)) {
				$arguments[] = $byName[$parameter->getName()];
				continue;
			}
			$type = $parameter->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type, 'no argument for $' . $parameter->getName());
			$arguments[] = $this->mocks[$type->getName()] ??= $this->createMock($type->getName());
		}
		return $arguments;
	}

	/**
	 * Records what a page hands to the frontend.
	 *
	 * @return ArrayObject<string, mixed> the initial state by key, filled once the page is rendered
	 */
	private function recordInitialState(): ArrayObject {
		$state = new ArrayObject();
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			static function (string $key, $data) use ($state): void {
				$state[$key] = $data;
			}
		);
		$this->mocks[IInitialState::class] = $initialState;
		return $state;
	}

	private function instantiateConfigController(): ConfigController {
		return $this->instantiate(ConfigController::class, ['appName' => 'integration_onedrive', 'userId' => 'user1']);
	}

	/**
	 * IRootFolder::getUserFolder() declares an IUserFolder return type since
	 * Nextcloud 36, older versions have no IUserFolder to mock.
	 */
	private function createUserFolderMock(): Folder|MockObject {
		return $this->createMock(interface_exists(IUserFolder::class) ? IUserFolder::class : Folder::class);
	}

	public function testTheAdminSettingsReadTheAppConfigLazily(): void {
		$this->appValues = ['client_id' => 'the-id', 'client_secret' => 'encrypted:the-secret', 'use_popup' => '1'];

		$this->instantiate(Admin::class)->getForm();

		$this->assertSame([
			['get', 'client_id', true],
			['get', 'client_secret', true],
			['get', 'use_popup', true],
		], $this->appConfigCalls);
	}

	public function testThePersonalSettingsReadTheAppConfigLazily(): void {
		$this->appValues = ['client_id' => 'the-id', 'client_secret' => 'encrypted:the-secret', 'use_popup' => '1'];
		$userFolder = $this->createUserFolderMock();
		$userFolder->method('getStorage')->willReturn($this->createMock(IStorage::class));
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->mocks[IRootFolder::class] = $rootFolder;

		$this->instantiate(Personal::class, ['userId' => 'user1'])->getForm();

		$this->assertSame([
			['get', 'client_id', true],
			['get', 'client_secret', true],
			['get', 'use_popup', true],
		], $this->appConfigCalls);
	}

	public function testTheAdminSettingsDoNotUseThePopupWhenNothingIsStored(): void {
		$state = $this->recordInitialState();

		$this->instantiate(Admin::class)->getForm();

		$this->assertFalse($state['admin-config']['use_popup']);
	}

	public function testThePersonalSettingsDoNotUseThePopupWhenNothingIsStored(): void {
		$state = $this->recordInitialState();
		$userFolder = $this->createUserFolderMock();
		$userFolder->method('getStorage')->willReturn($this->createMock(IStorage::class));
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->mocks[IRootFolder::class] = $rootFolder;

		$this->instantiate(Personal::class, ['userId' => 'user1'])->getForm();

		$this->assertFalse($state['user-config']['use_popup']);
	}

	public function testTheOAuthRedirectGoesBackToTheSettingsWhenNoPopupIsStored(): void {
		$this->appValues = ['client_id' => 'the-id', 'client_secret' => 'encrypted:the-secret'];
		$apiService = $this->createMock(OnedriveAPIService::class);
		$apiService->method('requestOAuthAccessToken')->willReturn(['access_token' => 'access', 'refresh_token' => 'refresh']);
		$apiService->method('request')->willReturn(['displayName' => 'Jane']);
		$this->mocks[OnedriveAPIService::class] = $apiService;
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnArgument(0);
		$this->mocks[IURLGenerator::class] = $urlGenerator;

		$response = $this->instantiateConfigController()->oauthRedirect('the-code');

		$this->assertSame('settings.PersonalSettings.index?onedriveToken=success', $response->getRedirectURL());
	}

	public function testTheAdminConfigIsStoredLazily(): void {
		$response = $this->instantiateConfigController()->setAdminConfig(['use_popup' => '1']);

		$this->assertSame([
			['set', 'use_popup', '1', true],
		], $this->appConfigCalls);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testTheClientCredentialsAreStoredLazilyAndTheSecretEncrypted(): void {
		$response = $this->instantiateConfigController()->setSensitiveAdminConfig(['client_id' => 'the-id', 'client_secret' => 'the-secret']);

		$this->assertSame([
			['set', 'client_id', 'the-id', true],
			['set', 'client_secret', 'encrypted:the-secret', true],
		], $this->appConfigCalls);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public static function foreignAdminConfigKeys(): array {
		return [
			'a key of Nextcloud' => ['setAdminConfig', ['enabled' => 'yes']],
			'a key of Nextcloud next to an own one' => ['setAdminConfig', ['use_popup' => '1', 'enabled' => 'yes']],
			'a key that needs the password confirmation' => ['setAdminConfig', ['client_secret' => 'the-secret']],
			'a key of Nextcloud with the password confirmation' => ['setSensitiveAdminConfig', ['enabled' => 'yes']],
			'a key of the other endpoint' => ['setSensitiveAdminConfig', ['use_popup' => '1']],
		];
	}

	/**
	 * A lazy write of a key like "enabled" would hide it from Nextcloud, which reads it as a
	 * non-lazy value, and the app would show as disabled.
	 */
	#[DataProvider('foreignAdminConfigKeys')]
	public function testTheAdminConfigEndpointsOnlyStoreTheirOwnKeys(string $endpoint, array $values): void {
		$response = $this->instantiateConfigController()->$endpoint($values);

		$this->assertSame([], $this->appConfigCalls, 'nothing is stored');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public static function adminConfigValuesThatAreNotStrings(): array {
		return [
			'a boolean' => ['setAdminConfig', ['use_popup' => true]],
			'null' => ['setAdminConfig', ['use_popup' => null]],
			'a number' => ['setSensitiveAdminConfig', ['client_id' => 42]],
			'an array' => ['setSensitiveAdminConfig', ['client_secret' => ['the-secret']]],
		];
	}

	#[DataProvider('adminConfigValuesThatAreNotStrings')]
	public function testTheAdminConfigEndpointsRejectValuesThatAreNotStrings(string $endpoint, array $values): void {
		$response = $this->instantiateConfigController()->$endpoint($values);

		$this->assertSame([], $this->appConfigCalls, 'nothing is stored');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testTheOAuthRedirectReadsTheAppConfigLazily(): void {
		$this->appValues = ['client_id' => 'the-id', 'client_secret' => 'encrypted:the-secret', 'use_popup' => '1'];
		$apiService = $this->createMock(OnedriveAPIService::class);
		$apiService->expects($this->once())
			->method('requestOAuthAccessToken')
			->with($this->callback(static fn (array $params): bool => $params['client_id'] === 'the-id' && $params['client_secret'] === 'the-secret'))
			->willReturn(['access_token' => 'access', 'refresh_token' => 'refresh']);
		$apiService->method('request')->willReturn(['displayName' => 'Jane']);
		$this->mocks[OnedriveAPIService::class] = $apiService;

		$this->instantiateConfigController()->oauthRedirect('the-code');

		$this->assertSame([
			['get', 'client_id', true],
			['get', 'client_secret', true],
			['get', 'use_popup', true],
		], $this->appConfigCalls);
	}

	public function testTheSetupCommandStoresTheClientCredentialsLazily(): void {
		/** @var Setup $command */
		$command = $this->instantiate(Setup::class);

		$exitCode = $command->run(new ArrayInput(['client_id' => 'the-id', 'client_secret' => 'the-secret']), new NullOutput());

		$this->assertSame([
			['set', 'client_id', 'the-id', true],
			['set', 'client_secret', 'encrypted:the-secret', true],
		], $this->appConfigCalls);
		$this->assertSame(0, $exitCode);
	}

	/**
	 * The service with an HTTP client that answers every request with $body.
	 */
	private function instantiateApiService(string $body): OnedriveAPIService {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$client->method('post')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$this->mocks[IClientService::class] = $clientService;
		return $this->instantiate(OnedriveAPIService::class);
	}

	public function testTheTokenRefreshReadsTheClientCredentialsLazily(): void {
		$this->appValues = ['client_id' => 'the-id', 'client_secret' => 'encrypted:the-secret'];

		$result = $this->instantiateApiService('{"access_token": "new-token"}')->refreshToken('user1');

		$this->assertSame([
			['get', 'client_id', true],
			['get', 'client_secret', true],
		], $this->appConfigCalls);
		$this->assertSame('new-token', $result['access_token'] ?? null);
	}

	public function testTheGraphBaseUrlIsReadLazily(): void {
		$this->appValues = ['api_base_url' => 'http://127.0.0.1:8099/v1.0'];

		$this->instantiateApiService('{}')->request('user1', 'me/drive');

		$this->assertSame([
			['get', 'api_base_url', true],
		], $this->appConfigCalls);
	}

	public function testRequestsGoToMicrosoftGraphWhenNoBaseUrlIsStored(): void {
		$requested = [];
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('{}');
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(static function (string $url) use (&$requested, $response): IResponse {
			$requested[] = $url;
			return $response;
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$this->mocks[IClientService::class] = $clientService;

		$this->instantiate(OnedriveAPIService::class)->request('user1', 'me/drive');

		$this->assertSame(['https://graph.microsoft.com/v1.0/me/drive'], $requested);
	}

	public function testAnImportBatchDownloads500MegabytesWhenNoSizeIsStored(): void {
		$this->userValues = ['importing_onedrive' => '1'];
		$userFolder = $this->createUserFolderMock();
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturnSelf();
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->mocks[IRootFolder::class] = $rootFolder;
		$service = $this->getMockBuilder(OnedriveStorageAPIService::class)
			->setConstructorArgs($this->constructorArguments(OnedriveStorageAPIService::class, ['appName' => 'integration_onedrive']))
			->onlyMethods(['importFiles'])
			->getMock();
		$service->expects($this->once())
			->method('importFiles')
			->with('user1', '/OneDrive import', 500000000)
			->willReturn(['error' => 'stop after the first batch']);

		$service->importOnedriveJob('user1');
	}

	public function testTheImportBatchSizeIsReadLazily(): void {
		$this->userValues = ['importing_onedrive' => '1'];
		$userFolder = $this->createUserFolderMock();
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturnSelf();
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->mocks[IRootFolder::class] = $rootFolder;
		// the drive cannot be reached, which ends the import right after the batch size was read
		$apiService = $this->createMock(OnedriveAPIService::class);
		$apiService->method('request')->willReturn(['error' => 'unreachable']);
		$this->mocks[OnedriveAPIService::class] = $apiService;

		/** @var OnedriveStorageAPIService $service */
		$service = $this->instantiate(OnedriveStorageAPIService::class, ['appName' => 'integration_onedrive']);
		$service->importOnedriveJob('user1');

		$this->assertSame([
			['get', 'import_batch_size', true],
		], $this->appConfigCalls);
	}

	public function testTheSecretEncryptionMigrationStoresTheSecretLazily(): void {
		$this->appValues = ['client_secret' => 'the-secret'];
		$expressions = $this->createMock(IExpressionBuilder::class);
		$queryBuilder = $this->createMock(IQueryBuilder::class);
		foreach (['update', 'set', 'where', 'andWhere', 'select', 'from'] as $method) {
			$queryBuilder->method($method)->willReturnSelf();
		}
		$queryBuilder->method('expr')->willReturn($expressions);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturn($queryBuilder);
		$this->mocks[IDBConnection::class] = $connection;

		$this->instantiate(Version3300Date202410151354::class)
			->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);

		$this->assertSame([
			['get', 'integration_onedrive', 'client_secret', true],
			['set', 'integration_onedrive', 'client_secret', 'encrypted:the-secret', true],
		], $this->appConfigCalls);
	}

	public function testTheMigrationMarksEveryAppConfigValueLazy(): void {
		$this->instantiate(Version3601Date202610211028::class)
			->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);

		$this->assertSame(
			array_map(static fn (string $key): array => ['updateLazy', 'integration_onedrive', $key, true], self::LAZY_KEYS),
			$this->appConfigCalls
		);
	}
}
