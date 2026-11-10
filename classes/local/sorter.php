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

/**
 * Natural ordering, roman numerals, academic periods and category grouping.
 */
class sorter {
    /** @var string[] Default pattern texts, one pattern per line. */
    public const DEFAULT_PATTERNS = [
        'semilla' => "semilla\nplantilla\n=Facultades",
        'facultad' => "/^facultad/\n/^programa/",
    ];

    /** @var int[] Group order at the top level. */
    private const GROUP_ORDER = ['periodo' => 0, 'aparte' => 1, 'semilla' => 2, 'facultad' => 3];

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
     * Parses a pattern text, one pattern per line.
     *
     * Plain text is "contains", "=text" is an exact name and "/regex/" is a
     * regular expression. Contains and exact values are stored normalized;
     * regular expressions are stored as given and are matched against the
     * normalized name (lowercase, no accents), so write them that way.
     * Invalid regular expressions and blank lines are dropped.
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
                $patterns[] = ['type' => 'contains', 'value' => self::normalize($line)];
            }
        }
        return $patterns;
    }

    /**
     * Default patterns, already parsed.
     *
     * @return array ['semilla' => array, 'facultad' => array]
     */
    public static function DEFAULT_PATTERNS_PARSED(): array { // phpcs:ignore moodle.NamingConventions.ValidFunctionName
        return [
            'semilla' => self::parse_patterns(self::DEFAULT_PATTERNS['semilla']),
            'facultad' => self::parse_patterns(self::DEFAULT_PATTERNS['facultad']),
        ];
    }

    /**
     * Configured patterns, with the defaults for settings that are not set.
     *
     * An empty string set by the admin means "no patterns".
     *
     * @return array ['semilla' => array, 'facultad' => array]
     */
    public static function patterns(): array {
        $result = [];
        foreach (['semilla', 'facultad'] as $group) {
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
     * At the top level: periodo > semilla > facultad > aparte. At lower levels
     * only 'periodo' or 'otro'.
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
        foreach (['semilla', 'facultad'] as $group) {
            foreach ($patterns[$group] ?? [] as $pattern) {
                if (self::matches($normalized, $pattern)) {
                    return $group;
                }
            }
        }
        return 'aparte';
    }

    /**
     * Proposed order of sibling categories.
     *
     * @param array $items List of ['id' => int, 'name' => string].
     * @param bool $toplevel
     * @param array $patterns As returned by patterns().
     * @return int[] Ids in the proposed order.
     */
    public static function propose(array $items, bool $toplevel, array $patterns): array {
        $rows = [];
        foreach ($items as $item) {
            $group = self::classify($item['name'], $toplevel, $patterns);
            $rows[] = [
                'id' => (int) $item['id'],
                'name' => $item['name'],
                'rank' => self::GROUP_ORDER[$group] ?? 4,
                'period' => $group === 'periodo' ? self::parse_period($item['name']) : null,
            ];
        }
        usort($rows, function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }
            if ($a['period'] !== null && $b['period'] !== null) {
                $result = ($b['period'][0] <=> $a['period'][0]) ?: ($b['period'][1] <=> $a['period'][1]);
                if ($result !== 0) {
                    return $result;
                }
            }
            return self::compare($a['name'], $b['name']) ?: ($a['id'] <=> $b['id']);
        });
        return array_column($rows, 'id');
    }

    /**
     * Natural order of courses by name, ties by id.
     *
     * @param array $items List of ['id' => int, 'name' => string].
     * @return int[]
     */
    public static function propose_courses(array $items): array {
        usort($items, function (array $a, array $b): int {
            return self::compare($a['name'], $b['name']) ?: ((int) $a['id'] <=> (int) $b['id']);
        });
        return array_map('intval', array_column($items, 'id'));
    }

    /**
     * Whether the proposed order differs from the current one.
     *
     * @param int[] $currentids
     * @param int[] $proposedids
     * @return bool
     */
    public static function differs(array $currentids, array $proposedids): bool {
        return array_values($currentids) !== array_values($proposedids);
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
                return strpos($normalized, $pattern['value']) !== false;
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
