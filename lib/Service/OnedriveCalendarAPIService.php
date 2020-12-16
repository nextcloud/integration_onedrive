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

use OCP\IL10N;
use OCA\DAV\CalDAV\CalDavBackend;
use Sabre\DAV\Exception\BadRequest;
use Psr\Log\LoggerInterface;

use OCA\Onedrive\AppInfo\Application;

class OnedriveCalendarAPIService {

	private $l10n;

	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								CalDavBackend $caldavBackend,
								OnedriveAPIService $onedriveApiService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->caldavBackend = $caldavBackend;
		$this->onedriveApiService = $onedriveApiService;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getCalendarList(string $accessToken, string $userId): array {
		$params = [];
		$result = $this->onedriveApiService->request($accessToken, $userId, 'me/calendars');
		if (isset($result['error']) || !isset($result['value'])) {
			return $result;
		}
		return $result['value'];
	}

	/**
	 * @param string $userId
	 * @param string $uri
	 * @return ?int the calendar ID
	 */
	private function calendarExists(string $userId, string $uri): ?int {
		$res = $this->caldavBackend->getCalendarByUri('principals/users/' . $userId, $uri);
		return is_null($res)
			? null
			: $res['id'];
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return array
	 */
	public function importCalendar(string $accessToken, string $userId, string $calId, string $calName, ?string $color = null): array {
		$params = [];
		if ($color) {
			$params['{http://apple.com/ns/ical/}calendar-color'] = $color;
		}

		$newCalName = trim($calName) . ' (' . $this->l10n->t('Microsoft Calendar import') .')';
		$ncCalId = $this->calendarExists($userId, $newCalName);
		if (is_null($ncCalId)) {
			$ncCalId = $this->caldavBackend->createCalendar('principals/users/' . $userId, $newCalName, $params);
		}

		date_default_timezone_set('UTC');
		$utcTimezone = new \DateTimeZone('-0000');
		$events = $this->getCalendarEvents($accessToken, $userId, $calId);
		$nbAdded = 0;
		foreach ($events as $e) {
			$calData = 'BEGIN:VCALENDAR' . "\n"
				. 'VERSION:2.0' . "\n"
				. 'PRODID:NextCloud Calendar' . "\n"
				. 'BEGIN:VEVENT' . "\n";

			$objectUri = $e['id'] . '-' . $e['changeKey'];
			$calData .= 'UID:' . $ncCalId . '-' . $objectUri . "\n";
			$calData .= isset($e['subject'])
				? 'SUMMARY:' . substr(str_replace("\n", '\n', $e['subject']), 0, 250) . "\n"
				: '';
			// $calData .= isset($e['sequence']) ? ('SEQUENCE:' . $e['sequence'] . "\n") : '';
			$calData .= isset($e['location'], $e['location']['displayName'])
				? ('LOCATION:' . substr(str_replace("\n", '\n', $e['location']['displayName']), 0, 250) . "\n")
				: '';
			$calData .= isset($e['body'], $e['body']['content'])
				? ('DESCRIPTION:' . substr(str_replace("\n", '\n', strip_tags($e['body']['content'])), 0, 250) . "\n")
				: '';
			// $calData .= isset($e['status']) ? ('STATUS:' . strtoupper(str_replace("\n", '\n', $e['status'])) . "\n") : '';

			if (isset($e['createdDateTime'])) {
				$created = new \Datetime($e['createdDateTime']);
				$created->setTimezone($utcTimezone);
				$calData .= 'CREATED:' . $created->format('Ymd\THis\Z') . "\n";
			}

			if (isset($e['lastModifiedDateTime'])) {
				$updated = new \Datetime($e['lastModifiedDateTime']);
				$updated->setTimezone($utcTimezone);
				$calData .= 'LAST-MODIFIED:' . $updated->format('Ymd\THis\Z') . "\n";
			}

			if (isset($e['reminderMinutesBeforeStart'])) {
				$calData .= 'BEGIN:VALARM' . "\n"
					. 'ACTION:DISPLAY' . "\n"
					. 'TRIGGER;RELATED=START:-PT' . $e['reminderMinutesBeforeStart'] . 'M' . "\n"
					. 'END:VALARM' . "\n";
			}

			if (isset($e['recurrence']) && is_array($e['recurrence'])) {
				$parts = [];
				if (isset($e['recurrence']['pattern']['type'])) {
					$freq = 'FREQ=' . strtoupper($e['recurrence']['pattern']['type']);
					$parts[] = $freq;
				}
				if (isset($e['recurrence']['pattern']['interval'])) {
					$interval = 'INTERVAL=' . $e['recurrence']['pattern']['interval'];
					$parts[] = $interval;
				}
				if (isset($e['recurrence']['pattern']['daysOfWeek']) && count($e['recurrence']['pattern']['daysOfWeek']) > 0) {
					$days = [];
					foreach ($e['recurrence']['pattern']['daysOfWeek'] as $day) {
						$days[] = strtoupper(substr($day, 0, 2));
					}
					$parts[] = 'BYDAY=' . implode(',', $days);
				}
				$calData .= 'RRULE:' . implode(';', $parts);
			}

			if (isset($e['start'], $e['start']['dateTime'], $e['end'], $e['end']['dateTime'])) {
				if ($e['isAllDay']) {
					// whole days
					$start = new \Datetime($e['start']['dateTime']);
					$calData .= 'DTSTART;VALUE=DATE:' . $start->format('Ymd') . "\n";
					$end = new \Datetime($e['end']['dateTime']);
					$calData .= 'DTEND;VALUE=DATE:' . $end->format('Ymd') . "\n";
				} else {
					$start = new \Datetime($e['start']['dateTime']);
					$start->setTimezone($utcTimezone);
					$calData .= 'DTSTART;VALUE=DATE-TIME:' . $start->format('Ymd\THis\Z') . "\n";
					$end = new \Datetime($e['end']['dateTime']);
					$end->setTimezone($utcTimezone);
					$calData .= 'DTEND;VALUE=DATE-TIME:' . $end->format('Ymd\THis\Z') . "\n";
				}
			} else {
				// skip entries without any date
				continue;
			}

			$calData .= 'CLASS:PUBLIC' . "\n"
				. 'END:VEVENT' . "\n"
				. 'END:VCALENDAR';

			try {
				$this->caldavBackend->createCalendarObject($ncCalId, $objectUri, $calData);
				$nbAdded++;
			} catch (BadRequest $ex) {
				if (strpos($ex->getMessage(), 'uid already exists') !== false) {
					$this->logger->info('Skip existing event "' . ($e['subject'] ?? 'no title') . '"', ['app' => $this->appName]);
				} else {
					$this->logger->warning('Error when creating calendar event "' . ($e['subject'] ?? 'no title') . '" ' . $ex->getMessage(), ['app' => $this->appName]);
				}
			} catch (\Exception | \Throwable $ex) {
				$this->logger->warning('Error when creating calendar event "' . ($e['subject'] ?? 'no title') . '" ' . $ex->getMessage(), ['app' => $this->appName]);
			}
		}

		$eventGeneratorReturn = $events->getReturn();
		if (isset($eventGeneratorReturn['error'])) {
			return $eventGeneratorReturn;
		}
		return [
			'nbAdded' => $nbAdded,
			'calName' => $newCalName,
		];
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $calId
	 * @return \Generator
	 */
	private function getCalendarEvents(string $accessToken, string $userId, string $calId): \Generator {
		$params = [];
		do {
			$result = $this->onedriveApiService->request($accessToken, $userId, 'me/calendars/'.$calId.'/events', $params);
			if (isset($result['error']) || !isset($result['value'])) {
				return $result;
			}
			foreach ($result['value'] as $event) {
				yield $event;
			}
			if (isset($result['@odata.nextLink'])
				&& $result['@odata.nextLink']
				&& preg_match('/\$skiptoken=/i', $result['@odata.nextLink'])) {
				$params['$skiptoken'] = preg_replace('/.*\$skiptoken=/', '', $result['@odata.nextLink']);
			}
		} while (isset($result['@odata.nextLink']) && $result['@odata.nextLink']);
		return [];
	}
}
