<?php
/**
 * Nextcloud - onedrive
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Onedrive\Service;

use DateTime;
use Exception;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\Onedrive\AppInfo\Application;
use OCP\Contacts\IManager as IContactManager;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\BadRequest;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\InvalidDataException;
use Throwable;

function startsWith(string $haystack, string $needle): bool {
	$length = strlen($needle);
	return (substr($haystack, 0, $length) === $needle);
}

class OnedriveContactAPIService {
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IContactManager
	 */
	private $contactsManager;
	/**
	 * @var CardDavBackend
	 */
	private $cdBackend;
	/**
	 * @var OnedriveAPIService
	 */
	private $onedriveApiService;

	private const ADDRESS_TYPES = [
		'homeAddress' => 'HOME',
		'businessAddress' => 'WORK',
		'otherAddress' => 'OTHER',
	];
	private const PHONE_TYPES = [
		'homePhones' => 'HOME',
		'businessPhones' => 'WORK',
	];
	private const IMPORT_RESULT = [
		'CREATED' => 0,
		'UPDATED' => 1,
		'SKIPPED' => 2,
		'FAILED' => 3,
	];

	/**
	 * Service to make requests to Onedrive v3 (JSON) API
	 */
	public function __construct(string $appName,
		LoggerInterface $logger,
		IContactManager $contactsManager,
		CardDavBackend $cdBackend,
		OnedriveAPIService $onedriveApiService) {
		$this->logger = $logger;
		$this->contactsManager = $contactsManager;
		$this->cdBackend = $cdBackend;
		$this->onedriveApiService = $onedriveApiService;
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function getContactNumber(string $userId): array {
		// first get nb contacts on top level
		$nbContacts = 0;
		$endPoint = 'me/contacts';
		$params = [
			'$select' => 'displayName',
			'$top' => 1000,
		];
		do {
			$result = $this->onedriveApiService->request($userId, $endPoint, $params);
			if (isset($result['error']) || !isset($result['value']) || !is_array($result['value'])) {
				return $result;
			}
			$nbContacts += count($result['value']);
			if (isset($result['@odata.nextLink'])
				&& $result['@odata.nextLink']
				&& preg_match('/\$skip=\d+/i', $result['@odata.nextLink'])) {
				$params['$skip'] = preg_replace('/.*\$skip=/', '', $result['@odata.nextLink']);
			}
		} while (isset($result['@odata.nextLink']) && $result['@odata.nextLink']);

		// then get all the rest in one request
		$params = [
			'$expand' => 'contacts($select=displayName)',
			'$top' => 100,
		];
		do {
			$result = $this->onedriveApiService->request($userId, 'me/contactFolders', $params);
			if (isset($result['error']) || !isset($result['value']) || !is_array($result['value'])) {
				return $result;
			}
			foreach ($result['value'] as $folder) {
				if (isset($folder['contacts']) && is_array($folder['contacts'])) {
					$nbContacts += count($folder['contacts']);
				}
			}
			if (isset($result['@odata.nextLink'])
				&& $result['@odata.nextLink']
				&& preg_match('/\$skip=\d+/i', $result['@odata.nextLink'])) {
				$params['$skip'] = preg_replace('/.*\$skip=/', '', $result['@odata.nextLink']);
			}
		} while (isset($result['@odata.nextLink']) && $result['@odata.nextLink']);

		return ['nbContacts' => $nbContacts];
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function getContactsInTopFolder(string $userId): array {
		$endPoint = 'me/contacts';
		$contacts = [];
		$params = [
			'$top' => 1000,
		];
		do {
			$result = $this->onedriveApiService->request($userId, $endPoint, $params);
			if (isset($result['error'])) {
				return $result;
			}
			$contacts = array_merge($contacts, $result['value'] ?? []);
			if (isset($result['@odata.nextLink'])
				&& $result['@odata.nextLink']
				&& preg_match('/\$skip=\d/i', $result['@odata.nextLink'])) {
				$params['$skip'] = preg_replace('/.*\$skip=/', '', $result['@odata.nextLink']);
			}
		} while (isset($result['@odata.nextLink']) && $result['@odata.nextLink']);
		return $contacts;
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function importContacts(string $userId): array {
		$nbAdded = 0;
		$nbUpdated = 0;
		$nbSkipped = 0;
		$nbFailed = 0;

		// top folder
		$topFolderContacts = $this->getContactsInTopFolder($userId);
		$topFolderName = 'Outlook contacts';
		$importResult = $this->importFolder($userId, $topFolderName, $topFolderContacts);
		$nbAdded += $importResult['nbAdded'] ?? 0;
		$nbUpdated += $importResult['nbUpdated'] ?? 0;
		$nbSkipped += $importResult['nbSkipped'] ?? 0;
		$nbFailed += $importResult['nbFailed'] ?? 0;

		// all the ones in folders in one request
		$endPoint = 'me/contactFolders';
		$params = [
			'$expand' => 'contacts',
			'$top' => 100,
		];
		do {
			$result = $this->onedriveApiService->request($userId, $endPoint, $params);
			if (isset($result['error']) || !isset($result['value']) || !is_array($result['value'])) {
				return $result;
			}
			foreach ($result['value'] as $folder) {
				if (isset($folder['displayName'], $folder['contacts']) && is_array($folder['contacts'])) {
					$importResult = $this->importFolder($userId, $folder['displayName'], $folder['contacts']);
					$nbAdded += $importResult['nbAdded'] ?? 0;
					$nbUpdated += $importResult['nbUpdated'] ?? 0;
					$nbSkipped += $importResult['nbSkipped'] ?? 0;
					$nbFailed += $importResult['nbFailed'] ?? 0;
				}
			}
			if (isset($result['@odata.nextLink'])
				&& $result['@odata.nextLink']
				&& preg_match('/\$skip=\d+/i', $result['@odata.nextLink'])) {
				$params['$skip'] = preg_replace('/.*\$skip=/', '', $result['@odata.nextLink']);
			}
		} while (isset($result['@odata.nextLink']) && $result['@odata.nextLink']);
		return [
			'nbAdded' => $nbAdded,
			'nbUpdated' => $nbUpdated,
			'nbSkipped' => $nbSkipped,
			'nbFailed' => $nbFailed,
		];
	}

	/**
	 * @param string $userId
	 * @param string $folderName
	 * @param array $folderContacts
	 * @return int[]
	 * @throws InvalidDataException
	 * @throws BadRequest
	 */
	private function importFolder(string $userId, string $folderName, array $folderContacts): array {
		$nbAdded = 0;
		$nbUpdated = 0;
		$nbSkipped = 0;
		$nbFailed = 0;
		$key = 0;
		$addressBooks = $this->contactsManager->getUserAddressBooks();
		$folderNameInNC = $folderName . ' (Microsoft calendar)';
		foreach ($addressBooks as $ab) {
			if ($ab->getDisplayName() === $folderNameInNC) {
				$key = intval($ab->getKey());
				break;
			}
		}
		$isAddressBookNew = false;
		if ($key === 0) {
			$key = $this->cdBackend->createAddressBook('principals/users/' . $userId, $folderNameInNC, []);
			$isAddressBookNew = true;
		}

		foreach ($folderContacts as $c) {
			$res = $this->importContact($userId, $c, $key, $isAddressBookNew);
			if ($res === self::IMPORT_RESULT['CREATED']) {
				$nbAdded++;
			} elseif ($res === self::IMPORT_RESULT['UPDATED']) {
				$nbUpdated++;
			} elseif ($res === self::IMPORT_RESULT['SKIPPED']) {
				$nbSkipped++;
			} elseif ($res === self::IMPORT_RESULT['FAILED']) {
				$nbFailed++;
			}
		}
		return [
			'nbAdded' => $nbAdded,
			'nbUpdated' => $nbUpdated,
			'nbSkipped' => $nbSkipped,
			'nbFailed' => $nbFailed,
		];
	}

	private function getContactPhoto(string $userId, array $contact): ?array {
		$endPoint = 'me/contacts/' . $contact['id'] . '/photo/$value';
		$result = $this->onedriveApiService->request($userId, $endPoint, [], 'GET', false);
		if (isset($result['error'])) {
			return null;
		}
		if (is_array($result['headers']['Content-Type']) && count($result['headers']['Content-Type']) > 0) {
			$type = $result['headers']['Content-Type'][0];
		} else {
			$type = $result['headers']['Content-Type'];
		}
		return [
			'type' => $type,
			'content' => $result['body'],
		];
	}

	/**
	 * @param string $userId
	 * @param array $c
	 * @param $key
	 * @param bool $isAddressBookNew
	 * @return int
	 * @throws InvalidDataException
	 */
	private function importContact(string $userId, array $c, $key, bool $isAddressBookNew): int {
		$cardUri = substr($c['id'], 0, 255);
		$cardUri = str_replace('/', '_', $cardUri);

		// check if contact exists and needs to be updated
		$existingContact = null;
		if (!$isAddressBookNew) {
			$existingContact = $this->cdBackend->getCard($key, $cardUri);
			if ($existingContact) {
				$msoftUpdateTime = $c['lastModifiedDateTime'] ?? null;
				if ($msoftUpdateTime === null) {
					$msoftUpdateTimestamp = 0;
				} else {
					try {
						$msoftUpdateTimestamp = (new DateTime($msoftUpdateTime))->getTimestamp();
					} catch (Exception|Throwable $e) {
						$msoftUpdateTimestamp = 0;
					}
				}

				if ($msoftUpdateTimestamp <= $existingContact['lastmodified']) {
					$this->logger->debug('Skipping existing contact which is up-to-date', ['contact' => $c, 'app' => Application::APP_ID]);
					return self::IMPORT_RESULT['SKIPPED'];
				}
			}
		}

		$vCard = new VCard();

		$displayName = $c['displayName'] ?? '';
		$familyName = $c['surname'] ?? '';
		$firstName = $c['givenName'] ?? '';
		if ($displayName) {
			$prop = $vCard->createProperty('FN', $displayName);
			$vCard->add($prop);
		}
		if ($familyName || $firstName) {
			$prop = $vCard->createProperty('N', [0 => $familyName, 1 => $firstName, 2 => '', 3 => '', 4 => '']);
			$vCard->add($prop);
		}

		// address
		foreach (['homeAddress', 'businessAddress', 'otherAddress'] as $addressKey) {
			if (array_key_exists('street', $c[$addressKey])) {
				$address = $c[$addressKey];
				$streetAddress = $address['street'] ?? '';
				$extendedAddress = '';
				$state = $address['state'] ?? '';
				$postalCode = $address['postalCode'] ?? '';
				$city = $address['city'] ?? '';
				//				$addrType = $address['type'] ?? '';
				$country = $address['countryOrRegion'] ?? '';
				$postOfficeBox = '';

				$type = ['TYPE' => self::ADDRESS_TYPES[$addressKey]];
				$addrProp = $vCard->createProperty('ADR',
					[0 => $postOfficeBox, 1 => $extendedAddress, 2 => $streetAddress, 3 => $city, 4 => $state, 5 => $postalCode, 6 => $country],
					$type
				);
				$vCard->add($addrProp);
			}
		}

		// notes
		if (isset($c['personalNotes']) && is_string($c['personalNotes'])) {
			$prop = $vCard->createProperty('NOTE', $c['personalNotes']);
			$vCard->add($prop);
		}

		// birthday
		if (isset($c['birthday']) && is_string($c['birthday']) && strlen($c['birthday']) > 0) {
			$date = new DateTime($c['birthday']);
			$strDate = $date->format('Ymd');

			$type = ['VALUE' => 'DATE'];
			$prop = $vCard->createProperty('BDAY', $strDate, $type);
			$vCard->add($prop);
		}

		if (isset($c['nickName']) && is_string($c['nickName']) && strlen($c['nickName']) > 0) {
			$prop = $vCard->createProperty('NICKNAME', $c['nickName']);
			$vCard->add($prop);
		}

		if (isset($c['emailAddresses']) && is_array($c['emailAddresses'])) {
			foreach ($c['emailAddresses'] as $email) {
				if (isset($email['address'])) {
					$type = null;
					$prop = $vCard->createProperty('EMAIL', $email['address'], $type);
					$vCard->add($prop);
				}
			}
		}

		foreach (['businessPhones', 'homePhones'] as $phoneKey) {
			if (isset($c[$phoneKey]) && is_array($c[$phoneKey])) {
				foreach ($c[$phoneKey] as $ph) {
					if (is_string($ph) && strlen($ph) > 0) {
						$type = ['TYPE' => self::PHONE_TYPES[$phoneKey]];
						$prop = $vCard->createProperty('TEL', $ph, $type);
						$vCard->add($prop);
					}
				}
			}
		}
		if (isset($c['mobilePhone']) && is_string($c['mobilePhone'])) {
			$type = ['TYPE' => 'CELL'];
			$prop = $vCard->createProperty('TEL', $c['mobilePhone'], $type);
			$vCard->add($prop);
		}

		if (isset($c['companyName']) && is_string($c['companyName'])) {
			$prop = $vCard->createProperty('ORG', $c['companyName']);
			$vCard->add($prop);
		}
		if (isset($c['jobTitle']) && is_string($c['jobTitle'])) {
			$prop = $vCard->createProperty('TITLE', $c['jobTitle']);
			$vCard->add($prop);
		}

		// photo
		$photo = $this->getContactPhoto($userId, $c);
		if ($photo !== null) {
			$type = 'JPEG';
			if ($photo['type'] === 'image/png') {
				$type = 'PNG';
			} elseif ($photo['type'] === 'image/jpeg') {
				$type = 'JPEG';
			}
			$b64Photo = stripslashes('data:image/' . strtolower($type) . ';base64\,') . base64_encode($photo['content']);
			try {
				$prop = $vCard->createProperty(
					'PHOTO',
					$b64Photo,
					[
						'type' => $type,
						// 'encoding' => 'b',
					]
				);
				$vCard->add($prop);
			} catch (Exception|Throwable $ex) {
				$this->logger->warning('Error when setting contact photo: ' . $ex->getMessage(), ['app' => Application::APP_ID]);
			}
		}

		if ($existingContact === null || $existingContact === false) {
			try {
				$this->cdBackend->createCard($key, $cardUri, $vCard->serialize());
				return self::IMPORT_RESULT['CREATED'];
			} catch (Throwable|Exception $e) {
				$this->logger->warning('Error when creating contact "' . ($displayName ?? 'no name') . '"', ['contact' => $c, 'app' => Application::APP_ID]);
			}
		} else {
			try {
				$this->cdBackend->updateCard($key, $cardUri, $vCard->serialize());
				return self::IMPORT_RESULT['UPDATED'];
			} catch (Throwable|Exception $e) {
				$this->logger->warning('Error when updating contact "' . ($displayName ?? 'no name') . '"', ['contact' => $c, 'app' => Application::APP_ID]);
			}
		}
		return self::IMPORT_RESULT['FAILED'];
	}
}
