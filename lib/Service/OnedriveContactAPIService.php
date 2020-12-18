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

use OCP\Contacts\IManager as IContactManager;
use Sabre\VObject\Component\VCard;
use OCA\DAV\CardDAV\CardDavBackend;
use Psr\Log\LoggerInterface;

use OCA\Onedrive\AppInfo\Application;

class OnedriveContactAPIService {

	/**
	 * Service to make requests to Onedrive v3 (JSON) API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IContactManager $contactsManager,
								CardDavBackend $cdBackend,
								OnedriveAPIService $onedriveApiService) {
		$this->appName = $appName;
		$this->logger = $logger;
		$this->contactsManager = $contactsManager;
		$this->cdBackend = $cdBackend;
		$this->onedriveApiService = $onedriveApiService;
		$this->addrTypes = [
			'homeAddress' => 'HOME',
			'businessAddress' => 'WORK',
			'otherAddress' => 'OTHER',
		];
		$this->phoneTypes = [
			'homePhones' => 'HOME',
			'businessPhones' => 'WORK',
		];
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getContactNumber(string $accessToken, string $userId): array {
		$folders = $this->getContactFolders($accessToken, $userId);
		if (isset($folders['error'])) {
			return $folders;
		}
		$folders[] = ['id' => null, 'displayName' => 'Outlook contacts'];
		$nbContacts = 0;
		foreach ($folders as $folder) {
			$number = $this->getContactNumberInFolder($accessToken, $userId, $folder['id']);
			if (!is_null($number)) {
				$nbContacts += $number;
			}
		}
		return ['nbContacts' => $nbContacts];
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getContactFolders(string $accessToken, string $userId): array {
		$folders = [];
		$params = [];
		do {
			$result = $this->onedriveApiService->request($accessToken, $userId, 'me/contactfolders', $params);
			if (isset($result['error']) || !isset($result['value']) || !is_array($result['value'])) {
				return $result;
			}
			foreach ($result['value'] as $folder) {
				$folders[] = $folder;
			}
			if (isset($result['@odata.nextLink'])
				&& $result['@odata.nextLink']
				&& preg_match('/\$skiptoken=/i', $result['@odata.nextLink'])) {
				$params['$skiptoken'] = preg_replace('/.*\$skiptoken=/', '', $result['@odata.nextLink']);
			}
		} while (isset($result['@odata.nextLink']) && $result['@odata.nextLink']);
		return $folders;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getContactNumberInFolder(string $accessToken, string $userId, ?string $folderId = null): ?int {
		$result = $this->getContactsInFolder($accessToken, $userId, $folderId);
		if (isset($result['error'])) {
			return null;
		}
		$nbContacts = count($result['contacts'] ?? []);
		return $nbContacts;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getContactsInFolder(string $accessToken, string $userId, ?string $folderId = null): array {
		$endPoint = is_null($folderId)
			? 'me/contacts'
			: 'me/contactfolders/' . $folderId . '/contacts';
		$contacts = [];
		$params = [];
		do {
			$result = $this->onedriveApiService->request($accessToken, $userId, $endPoint, $params);
			if (isset($result['error'])) {
				return $result;
			}
			$contacts = array_merge($contacts, $result['value'] ?? []);
			if (isset($result['@odata.nextLink'])
				&& $result['@odata.nextLink']
				&& preg_match('/\$skiptoken=/i', $result['@odata.nextLink'])) {
				$params['$skiptoken'] = preg_replace('/.*\$skiptoken=/', '', $result['@odata.nextLink']);
			}
		} while (isset($result['@odata.nextLink']) && $result['@odata.nextLink']);
		return ['contacts' => $contacts];
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function importContacts(string $accessToken, string $userId): array {
		$nbAdded = 0;

		$folders = $this->getContactFolders($accessToken, $userId);
		if (isset($folders['error'])) {
			return $folders;
		}
		$folders[] = ['id' => null, 'displayName' => 'Outlook contacts'];
		foreach ($folders as $folder) {
			$key = 0;
			$addressBooks = $this->contactsManager->getUserAddressBooks();
			foreach ($addressBooks as $k => $ab) {
				if ($ab->getDisplayName() === $folder['displayName']) {
					$key = intval($ab->getKey());
					break;
				}
			}
			if ($key === 0) {
				$key = $this->cdBackend->createAddressBook('principals/users/' . $userId, $folder['displayName'] . ' (Microsoft calendar)', []);
			}

			$result = $this->getContactsInFolder($accessToken, $userId, $folder['id']);
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['contacts'] as $k => $c) {
				if ($this->importContact($c, $key)) {
					$nbAdded++;
				}
			}
		}
		return ['nbAdded' => $nbAdded];
	}

	private function importContact(array $c, $key): bool {
		// avoid existing contacts
		if ($this->contactExists($c, $key)) {
			return false;
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
		// we don't want empty names
		if (!$displayName && !$familyName && !$firstName) {
			return false;
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
				$addrType = $address['type'] ?? '';
				$country = $address['countryOrRegion'] ?? '';
				$postOfficeBox = '';

				$type = ['TYPE' => $this->addrTypes[$addressKey]];
				$addrProp = $vCard->createProperty('ADR',
					[0 => $postOfficeBox, 1 => $extendedAddress, 2 => $streetAddress, 3 => $city, 4 => $state, 5 => $postalCode, 6 => $country],
					$type
				);
				$vCard->add($addrProp);
			}
		}

		// birthday
		if (isset($c['birthday']) && is_string($c['birthday']) && strlen($c['birthday']) > 0) {
			$date = new \Datetime($c['birthday']);
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
						$type = ['TYPE' => $this->phoneTypes[$phoneKey]];
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

		try {
			$this->cdBackend->createCard($key, 'outlook' . $k, $vCard->serialize());
			return true;
		} catch (\Throwable | \Exception $e) {
			$this->logger->warning('Error when creating contact "' . ($displayName ?? 'no name') . '" ' . json_encode($c), ['app' => $this->appName]);
		}
		return false;
	}

	/**
	 * @param array $contact
	 * @param int $addressBookKey
	 * @return bool
	 */
	private function contactExists(array $contact, int $addressBookKey): bool {
		$displayName = $contact['displayName'] ?? '';
		if ($displayName) {
			$searchResult = $this->contactsManager->search($displayName, ['FN']);
			foreach ($searchResult as $resContact) {
				if ($resContact['FN'] === $displayName && (int)$resContact['addressbook-key'] === $addressBookKey) {
					return true;
				}
			}
		}
		return false;
	}
}
