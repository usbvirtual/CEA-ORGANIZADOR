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
 * Name normalization, roman numerals, academic periods and category patterns.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Name normalization, roman numerals, academic periods and category grouping (pure: no database writes).
 */
class sorter {
    /** @var string[] Default pattern texts, one pattern per line. */
    public const DEFAULT_PATTERNS = [
        'semilla' => "semilla\nplantilla\n=Facultades",
    ];

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
     * Parses a pattern text, one pattern per line.
     *
     * Plain text is "contains", "=text" is an exact name and "/regex/" is a
     * regular expression. Contains and exact values are stored normalized;
     * regular expressions are stored as given and are matched against the
     * normalized name (lowercase, no accents), so write them that way.
     * Invalid regular expressions, blank lines and values that normalize to
     * nothing are dropped.
     *
     * @param string $text
     * @return array List of ['type' => string, 'value' => string].
     */
    public static function parse_patterns(string $text): array {
        $patterns = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line[0] === '/') {
                if (strlen($line) > 1 && @preg_match($line, '') !== false) {
                    $patterns[] = ['type' => 'regex', 'value' => $line];
                }
            } else if ($line[0] === '=') {
                $value = self::normalize(substr($line, 1));
                if ($value !== '') {
                    $patterns[] = ['type' => 'exact', 'value' => $value];
                }
            } else {
                // A value that normalizes to nothing (such as a lone tag) would match every name.
                $value = self::normalize($line);
                if ($value !== '') {
                    $patterns[] = ['type' => 'contains', 'value' => $value];
                }
            }
        }
        return $patterns;
    }

    /**
     * Default patterns, already parsed.
     *
     * @return array ['semilla' => array]
     */
    public static function default_patterns_parsed(): array {
        return ['semilla' => self::parse_patterns(self::DEFAULT_PATTERNS['semilla'])];
    }

    /**
     * Configured patterns, with the defaults for settings that are not set.
     *
     * An empty string set by the admin means "no patterns".
     *
     * @return array ['semilla' => array]
     */
    public static function patterns(): array {
        $result = [];
        foreach (['semilla'] as $group) {
            $value = get_config('tool_ceaorganizador', 'patterns_' . $group);
            if ($value === false || $value === null) {
                $value = self::DEFAULT_PATTERNS[$group];
            }
            $result[$group] = self::parse_patterns((string) $value);
        }
        return $result;
    }

    /**
     * Classifies a category name.
     *
     * At the top level: periodo > semilla > aparte. At lower levels only 'periodo' or 'otro'.
     *
     * @param string $name
     * @param bool $toplevel
     * @param array $patterns As returned by patterns().
     * @return string
     */
    public static function classify(string $name, bool $toplevel, array $patterns): string {
        if (self::parse_period($name) !== null) {
            return 'periodo';
        }
        if (!$toplevel) {
            return 'otro';
        }
        $normalized = self::normalize($name);
        foreach ($patterns['semilla'] ?? [] as $pattern) {
            if (self::matches($normalized, $pattern)) {
                return 'semilla';
            }
        }
        return 'aparte';
    }

    /**
     * Whether a normalized name matches a parsed pattern.
     *
     * @param string $normalized
     * @param array $pattern
     * @return bool
     */
    private static function matches(string $normalized, array $pattern): bool {
        switch ($pattern['type']) {
            case 'exact':
                return $normalized === $pattern['value'];
            case 'regex':
                return @preg_match($pattern['value'], $normalized) === 1;
            default:
                // An empty value would match every name.
                return $pattern['value'] !== '' && strpos($normalized, $pattern['value']) !== false;
        }
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
}
