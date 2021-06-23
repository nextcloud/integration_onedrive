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

require_once __DIR__ . '/../../vendor/autoload.php';
use Ortic\ColorConverter\Color;
use Ortic\ColorConverter\Colors\Named;

class OnedriveColorService {

	/**
	 * Service to make requests to Onedrive v3 (JSON) API
	 */
	public function __construct () {
	}

	/**
     * @param string $hexColor
     * @return string closest CSS color name
     */
    public function getClosestCssColor(string $hexColor): string {
        $color = Color::fromString($hexColor);
        $rbgColor = [
            'r' => $color->getRed(),
            'g' => $color->getGreen(),
            'b' => $color->getBlue(),
        ];
        // init
        $closestColor = 'black';
        $black = Color::fromString(Named::CSS_COLORS['black']);
        $rgbBlack = [
            'r' => $black->getRed(),
            'g' => $black->getGreen(),
            'b' => $black->getBlue(),
        ];
        $closestDiff = $this->colorDiff($rbgColor, $rgbBlack);

        foreach (Named::CSS_COLORS as $name => $hex) {
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
     * @param array $rgb1 first color
     * @param array $rgb2 second color
     * @return int the distance between colors
     */
    private function colorDiff(array $rgb1, array $rgb2): int {
        return abs($rgb1['r'] - $rgb2['r']) + abs($rgb1['g'] - $rgb2['g']) + abs($rgb1['b'] - $rgb2['b']);
    }
}
