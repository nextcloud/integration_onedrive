<?php

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\Service;

use DateTime;
use DateTimeZone;
use Exception;
use Generator;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Onedrive\AppInfo\Application;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\BadRequest;
use Throwable;

class OnedriveCalendarAPIService {
	private IL10N $l10n;


	private LoggerInterface $logger;

	private CalDavBackend $caldavBackend;

	private OnedriveColorService $colorService;

	private OnedriveAPIService $onedriveApiService;

	public function __construct(
		LoggerInterface $logger,
		IL10N $l10n,
		CalDavBackend $caldavBackend,
		OnedriveColorService $colorService,
		OnedriveAPIService $onedriveApiService) {
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->caldavBackend = $caldavBackend;
		$this->colorService = $colorService;
		$this->onedriveApiService = $onedriveApiService;
	}

	/**
	 * @param string $userId
	 * @return array{error?: string}
	 */
	public function getCalendarList(string $userId): array {
		$result = $this->onedriveApiService->request($userId, 'me/calendars');
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

	private function getCategories(string $userId): array {
		$result = $this->onedriveApiService->request($userId, 'me/outlook/masterCategories');
		if (isset($result['error']) || !isset($result['value']) || !is_array($result['value'])) {
			return [];
		}
		$categoryColors = [];
		$convColors = [
			'preset0' => '#ff0000',
			'preset1' => '#ff8c00',
			'preset2' => '#ffab45',
			'preset3' => '#fff100',
			'preset4' => '#47d041',
			'preset5' => '#30c6cc',
			'preset6' => '#73aa24',
			'preset7' => '#00bcf2',
			'preset8' => '#8764b8',
			'preset9' => '#f495bf',
			'preset10' => '#a0aeb2',
			'preset11' => '#004b60',
			'preset12' => '#b1adab',
			'preset13' => '#5d5a58',
			'preset14' => '#000000',
			'preset15' => '#750b1c',
			'preset16' => '#ca5010',
			'preset17' => '#ab620d',
			'preset18' => '#c19c00',
			'preset19' => '#004b1c',
			'preset20' => '#004b50',
			'preset21' => '#0b6a0b',
			'preset22' => '#002050',
			'preset23' => '#32145a',
			'preset24' => '#5c005c',
		];
		foreach ($result['value'] as $v) {
			/** @var string $preset */
			$preset = $v['color'];
			if (array_key_exists($preset, $convColors)) {
				$categoryColors[$v['displayName']] = $this->colorService->getClosestCssColor($convColors[$preset]);
			}
		}
		return $categoryColors;
	}

	/**
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return array{nbAdded?: int, calName?: string, error?: string}
	 */
	public function importCalendar(string $userId, string $calId, string $calName, ?string $color = null): array {
		$setPositions = [
			'first' => 1,
			'second' => 2,
			'third' => 3,
			'fourth' => 4,
			'fifth' => 5,
			'penultimate' => -2,
			'last' => -1,
		];
		$params = [];
		if ($color) {
			$params['{http://apple.com/ns/ical/}calendar-color'] = $color;
		}

		$categories = $this->getCategories($userId);

		$newCalName = trim($calName) . ' (' . $this->l10n->t('Microsoft Calendar import') . ')';
		/** @var ?string $ncCalId */
		$ncCalId = $this->calendarExists($userId, $newCalName);
		if (is_null($ncCalId)) {
			/** @var string $ncCalId */
			$ncCalId = $this->caldavBackend->createCalendar('principals/users/' . $userId, $newCalName, $params);
		}

		date_default_timezone_set('UTC');
		$utcTimezone = new DateTimeZone('-0000');
		$events = $this->getCalendarEvents($userId, $calId);
		$nbAdded = 0;
		/** @var array $e */
		foreach ($events as $e) {
			$calData = 'BEGIN:VCALENDAR' . "\n"
				. 'VERSION:2.0' . "\n"
				. 'PRODID:NextCloud Calendar' . "\n"
				. 'BEGIN:VEVENT' . "\n";

			/** @var string $objectUri */
			$objectUri = $e['iCalUId'];
			$calData .= 'UID:' . $ncCalId . '-' . $objectUri . "\n";
			$calData .= isset($e['subject'])
				? 'SUMMARY:' . substr(str_replace("\n", '\n', $e['subject']), 0, 250) . "\n"
				: '';
			// $calData .= isset($e['sequence']) ? ('SEQUENCE:' . $e['sequence'] . "\n") : '';
			$calData .= isset($e['location'], $e['location']['displayName'])
				? ('LOCATION:' . substr(str_replace("\n", '\n', $e['location']['displayName']), 0, 250) . "\n")
				: '';
			$calData .= isset($e['body'], $e['body']['content'])
				? ('DESCRIPTION:' . substr(str_replace("\n", '\n', trim(strip_tags($e['body']['content']))), 0, 250) . "\n")
				: '';
			// $calData .= isset($e['status']) ? ('STATUS:' . strtoupper(str_replace("\n", '\n', $e['status'])) . "\n") : '';

			// color
			if (isset($e['categories']) && is_array($e['categories']) && count($e['categories']) > 0
				&& array_key_exists($e['categories'][0], $categories)) {
				$calData .= 'COLOR:' . $categories[$e['categories'][0]] . "\n";
			}

			if (isset($e['createdDateTime'])) {
				$created = new \DateTime($e['createdDateTime']);
				$created->setTimezone($utcTimezone);
				$calData .= 'CREATED:' . $created->format('Ymd\THis\Z') . "\n";
			}

			if (isset($e['lastModifiedDateTime'])) {
				$updated = new \DateTime($e['lastModifiedDateTime']);
				$updated->setTimezone($utcTimezone);
				$calData .= 'LAST-MODIFIED:' . $updated->format('Ymd\THis\Z') . "\n";
			}

			if (isset($e['reminderMinutesBeforeStart'])) {
				$calData .= 'BEGIN:VALARM' . "\n"
					. 'ACTION:DISPLAY' . "\n"
					. 'TRIGGER;RELATED=START:-PT' . $e['reminderMinutesBeforeStart'] . 'M' . "\n"
					. 'END:VALARM' . "\n";
			}

			if (isset($e['recurrence'], $e['recurrence']['pattern'], $e['recurrence']['pattern']['type'])) {
				$parts = [];
				$type = $e['recurrence']['pattern']['type'];
				if ($type === 'daily') {
					$parts[] = 'FREQ=' . strtoupper($type);
				} elseif ($type === 'weekly') {
					$parts[] = 'FREQ=' . strtoupper($type);
					if (isset($e['recurrence']['pattern']['daysOfWeek']) && count($e['recurrence']['pattern']['daysOfWeek']) > 0) {
						$days = [];
						foreach ($e['recurrence']['pattern']['daysOfWeek'] as $day) {
							$days[] = strtoupper(substr($day, 0, 2));
						}
						$parts[] = 'BYDAY=' . implode(',', $days);
					}
				} elseif ($type === 'relativeMonthly') {
					$parts[] = 'FREQ=MONTHLY';
					if (isset($e['recurrence']['pattern']['daysOfWeek']) && count($e['recurrence']['pattern']['daysOfWeek']) > 0) {
						$days = [];
						foreach ($e['recurrence']['pattern']['daysOfWeek'] as $day) {
							$days[] = strtoupper(substr($day, 0, 2));
						}
						$parts[] = 'BYDAY=' . implode(',', $days);
					}
					if (isset($e['recurrence']['pattern']['index'])) {
						$index = $e['recurrence']['pattern']['index'];
						$parts[] = 'BYSETPOS=' . (string)$setPositions[$index];
					}
				} elseif ($type === 'absoluteMonthly') {
					$parts[] = 'FREQ=MONTHLY';
					if (isset($e['recurrence']['pattern']['dayOfMonth'])) {
						$parts[] = 'BYMONTHDAY=' . $e['recurrence']['pattern']['dayOfMonth'];
					}
				} elseif ($type === 'relativeYearly') {
					$parts[] = 'FREQ=YEARLY';
					if (isset($e['recurrence']['pattern']['daysOfWeek']) && count($e['recurrence']['pattern']['daysOfWeek']) > 0) {
						$days = [];
						foreach ($e['recurrence']['pattern']['daysOfWeek'] as $day) {
							$days[] = strtoupper(substr($day, 0, 2));
						}
						$parts[] = 'BYDAY=' . implode(',', $days);
					}
					if (isset($e['recurrence']['pattern']['month'])) {
						$parts[] = 'BYMONTH=' . $e['recurrence']['pattern']['month'];
					}
					if (isset($e['recurrence']['pattern']['dayOfMonth']) && $e['recurrence']['pattern']['dayOfMonth'] > 0) {
						$parts[] = 'BYMONTHDAY=' . $e['recurrence']['pattern']['dayOfMonth'];
					}
					if (isset($e['recurrence']['pattern']['index'])) {
						$index = $e['recurrence']['pattern']['index'];
						$parts[] = 'BYSETPOS=' . (string)$setPositions[$index];
					}
				} elseif ($type === 'absoluteYearly') {
					$parts[] = 'FREQ=YEARLY';
					if (isset($e['recurrence']['pattern']['month'])) {
						$parts[] = 'BYMONTH=' . $e['recurrence']['pattern']['month'];
					}
					if (isset($e['recurrence']['pattern']['dayOfMonth']) && $e['recurrence']['pattern']['dayOfMonth'] > 0) {
						$parts[] = 'BYMONTHDAY=' . $e['recurrence']['pattern']['dayOfMonth'];
					}
				}
				if (isset($e['recurrence']['pattern']['interval'])) {
					$parts[] = 'INTERVAL=' . $e['recurrence']['pattern']['interval'];
				}
				if (isset($e['recurrence']['range']['endDate'])) {
					$endDate = new DateTime($e['recurrence']['range']['endDate']);
					$endDate->setTimezone($utcTimezone);
					$parts[] = 'UNTIL=' . $endDate->format('Ymd\THis\Z');
				} elseif (isset($e['recurrence']['range']['numberOfOccurrences']) && $e['recurrence']['range']['numberOfOccurrences'] > 0) {
					$parts[] = 'COUNT=' . $e['recurrence']['range']['numberOfOccurrences'];
				}
				if (isset($e['recurrence']['pattern']['firstDayOfWeek'])) {
					$parts[] = 'WKST=' . strtoupper(substr($e['recurrence']['pattern']['firstDayOfWeek'], 0, 2));
				}
				$calData .= 'RRULE:' . implode(';', $parts) . "\n";
			}

			if (isset($e['start'], $e['start']['dateTime'], $e['end'], $e['end']['dateTime'])) {
				if ($e['isAllDay']) {
					// whole days
					$start = new \DateTime($e['start']['dateTime']);
					$calData .= 'DTSTART;VALUE=DATE:' . $start->format('Ymd') . "\n";
					$end = new \DateTime($e['end']['dateTime']);
					$calData .= 'DTEND;VALUE=DATE:' . $end->format('Ymd') . "\n";
				} else {
					$start = new \DateTime($e['start']['dateTime']);
					$start->setTimezone($utcTimezone);
					$calData .= 'DTSTART;VALUE=DATE-TIME:' . $start->format('Ymd\THis\Z') . "\n";
					$end = new \DateTime($e['end']['dateTime']);
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
					$this->logger->info('Skip existing event "' . ($e['subject'] ?? 'no title') . '"', ['app' => Application::APP_ID]);
				} else {
					$this->logger->warning('Error when creating calendar event "' . ($e['subject'] ?? 'no title') . '" ' . $ex->getMessage(), ['app' => Application::APP_ID]);
				}
			} catch (Exception|Throwable $ex) {
				$this->logger->warning('Error when creating calendar event "' . ($e['subject'] ?? 'no title') . '" ' . $ex->getMessage(), ['app' => Application::APP_ID]);
			}
		}

		/** @var array $eventGeneratorReturn */
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
	 * @param string $userId
	 * @param string $calId
	 * @return Generator<int, mixed, mixed,  array{body?: resource|string, error?: string, headers?: array<array-key, mixed>}>
	 */
	private function getCalendarEvents(string $userId, string $calId): Generator {
		$params = [];
		do {
			$result = $this->onedriveApiService->request($userId, 'me/calendars/' . $calId . '/events', $params);
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
