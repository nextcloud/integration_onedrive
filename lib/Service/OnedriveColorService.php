<?php

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\Service;

require_once __DIR__ . '/../../vendor/autoload.php';
use Ortic\ColorConverter\Color;
use Ortic\ColorConverter\Colors\Named;

class OnedriveColorService {

	/**
	 * Service to make requests to Onedrive v3 (JSON) API
	 */
	public function __construct() {
	}

	/**
	 * @param string $hexColor
	 * @return string closest CSS color name
	 */
	public function getClosestCssColor(string $hexColor): string {
		/** @var Color $color */
		$color = Color::fromString($hexColor);
		$rbgColor = [
			'r' => $color->getRed(),
			'g' => $color->getGreen(),
			'b' => $color->getBlue(),
		];
		// init
		$closestColor = 'black';
		/** @var Color $black */
		$black = Color::fromString(Named::CSS_COLORS['black']);
		$rgbBlack = [
			'r' => $black->getRed(),
			'g' => $black->getGreen(),
			'b' => $black->getBlue(),
		];
		$closestDiff = $this->colorDiff($rbgColor, $rgbBlack);

		foreach (Named::CSS_COLORS as $name => $hex) {
			/** @var Color $c */
			$c = Color::fromString($hex);
			$rgb = [
				'r' => $c->getRed(),
				'g' => $c->getGreen(),
				'b' => $c->getBlue(),
			];
			$diff = $this->colorDiff($rbgColor, $rgb);
			if ($diff < $closestDiff) {
				$closestDiff = $diff;
				$closestColor = $name;
			}
		}

		return $closestColor;
	}

	/**
	 * @param array{r:int, g:int, b:int} $rgb1 first color
	 * @param array{r:int, g:int, b:int} $rgb2 second color
	 * @return int the distance between colors
	 * @psalm-return 0|positive-int the distance between colors
	 */
	private function colorDiff(array $rgb1, array $rgb2): int {
		return abs($rgb1['r'] - $rgb2['r']) + abs($rgb1['g'] - $rgb2['g']) + abs($rgb1['b'] - $rgb2['b']);
	}
}
