<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Natural ordering, roman numerals and academic periods.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Natural ordering, roman numerals and academic periods.
 */
class sorter {
    /**
     * Lowercases, removes accents and tags, and collapses spaces.
     *
     * @param string $name
     * @return string
     */
    public static function normalize(string $name): string {
        return \core_text::strtolower(self::clean($name));
    }

    /**
     * Natural comparison of two names.
     *
     * Digit runs and isolated roman numerals compare as integers, and a
     * number goes before text. Ties fall back to the normalized names.
     *
     * @param string $a
     * @param string $b
     * @return int
     */
    public static function compare(string $a, string $b): int {
        $ta = self::tokenize(self::clean($a));
        $tb = self::tokenize(self::clean($b));
        $count = min(count($ta), count($tb));
        for ($i = 0; $i < $count; $i++) {
            $result = self::compare_tokens($ta[$i], $tb[$i]);
            if ($result !== 0) {
                return $result;
            }
        }
        if (count($ta) !== count($tb)) {
            return count($ta) <=> count($tb);
        }
        return strcmp(self::normalize($a), self::normalize($b)) <=> 0;
    }

    /**
     * Detects an academic period such as "2026-02" or "2026 II".
     *
     * @param string $name
     * @return array|null [year, term] or null.
     */
    public static function parse_period(string $name): ?array {
        $pattern = '/(?<!\d)(20\d\d)\s*[-–_\/ ]\s*(0?[12]|ii|i)(?![\p{L}\d])/iu';
        if (!preg_match($pattern, $name, $m)) {
            return null;
        }
        $term = ctype_digit($m[2]) ? (int) $m[2] : strlen($m[2]);
        return [(int) $m[1], $term];
    }

    /**
     * Value of a roman numeral between I and XXX, or null.
     *
     * Case sensitive: only a lowercase "l" is read as "I", because that is a
     * real typing mistake ("Xll"); an uppercase "L" stays 50 and is rejected.
     *
     * @param string $token
     * @return int|null
     */
    public static function roman_value(string $token): ?int {
        $upper = strtoupper(str_replace('l', 'I', $token));
        if (!preg_match('/^(XXX|XX|X)?(IX|IV|V?I{0,3})$/', $upper, $m) || $upper === '') {
            return null;
        }
        $tens = ['' => 0, 'X' => 10, 'XX' => 20, 'XXX' => 30];
        $units = [
            '' => 0, 'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4,
            'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9,
        ];
        return $tens[$m[1]] + $units[$m[2]];
    }

    /**
     * Removes tags and accents and collapses spaces, keeping the case.
     *
     * @param string $name
     * @return string
     */
    private static function clean(string $name): string {
        $name = \core_text::specialtoascii(strip_tags($name));
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Splits a name into runs of digits, letters and other characters.
     *
     * @param string $name
     * @return array Each item is [type, text, number]; type is "n" or "t".
     */
    private static function tokenize(string $name): array {
        preg_match_all('/\d+|\p{L}+|[^\d\p{L}]+/u', $name, $matches);
        $parts = $matches[0];
        $tokens = [];
        foreach ($parts as $i => $part) {
            if (ctype_digit($part)) {
                $tokens[] = ['n', $part, $part];
                continue;
            }
            $isword = preg_match('/^\p{L}+$/u', $part) === 1;
            $isolated = !(isset($parts[$i - 1]) && ctype_digit($parts[$i - 1]))
                && !(isset($parts[$i + 1]) && ctype_digit($parts[$i + 1]));
            $roman = ($isword && $isolated) ? self::roman_value($part) : null;
            if ($roman !== null) {
                $tokens[] = ['n', $part, (string) $roman];
            } else {
                $tokens[] = ['t', \core_text::strtolower($part), ''];
            }
        }
        return $tokens;
    }

    /**
     * Compares two tokens; numbers go before text.
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    private static function compare_tokens(array $a, array $b): int {
        if ($a[0] === 'n' && $b[0] === 'n') {
            // Digit runs can exceed PHP_INT_MAX, so compare them as strings.
            $x = ltrim($a[2], '0');
            $y = ltrim($b[2], '0');
            if (strlen($x) !== strlen($y)) {
                return strlen($x) <=> strlen($y);
            }
            return strcmp($x, $y) <=> 0;
        }
        if ($a[0] !== $b[0]) {
            return $a[0] === 'n' ? -1 : 1;
        }
        return strcmp($a[1], $b[1]) <=> 0;
    }
}
