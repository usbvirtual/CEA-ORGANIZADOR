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
 * Name transformations: replace, clean and affixes, with a preview.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Applies a list of operations to names and builds the preview rows.
 */
class renamer {
    /** @var int Maximum length of a name. */
    public const MAXLEN = 254;

    /** @var string[] Spanish connectors that stay lowercase. */
    private const CONNECTORS = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'en', 'para', 'por', 'con', 'a'];

    /** @var string Alphanumeric token, with combining marks. */
    private const TOKEN = '/[\p{L}\p{M}\p{N}]+/u';

    /**
     * Applies the operations to a name, in order.
     *
     * @param string $name
     * @param array $ops Operations as documented in the task (replace, clean, affix).
     * @return string
     */
    public static function apply(string $name, array $ops): string {
        foreach ($ops as $op) {
            switch ($op['type'] ?? '') {
                case 'replace':
                    $name = self::op_replace($name, $op);
                    break;
                case 'clean':
                    $name = self::op_clean($name, (string) ($op['case'] ?? 'none'));
                    break;
                case 'affix':
                    $name = self::op_affix($name, $op);
                    break;
            }
        }
        return $name;
    }

    /**
     * Builds the preview rows, only for names that change or have an error.
     *
     * @param array $items List of ['type', 'id', 'name', 'shortname'].
     * @param array $ops Operations, see apply().
     * @param array $manual Map "type:id" => name that overrides the result.
     * @return array List of ['type', 'id', 'old', 'new', 'error'].
     */
    public static function preview(array $items, array $ops, array $manual): array {
        $rows = [];
        foreach ($items as $item) {
            $old = (string) $item['name'];
            $key = $item['type'] . ':' . $item['id'];
            $error = null;
            if (array_key_exists($key, $manual)) {
                $new = trim((string) $manual[$key]);
            } else if (strpos($old, '<') !== false) {
                $new = $old;
                $error = 'multilang';
            } else {
                $new = trim(self::apply($old, $ops));
            }
            if ($error === null) {
                if ($new === '') {
                    $error = 'empty';
                } else if (\core_text::strlen($new) > self::MAXLEN) {
                    $error = 'toolong';
                }
            }
            if ($error === null && $new === $old) {
                continue;
            }
            $rows[] = [
                'type' => $item['type'],
                'id' => (int) $item['id'],
                'old' => $old,
                'new' => $new,
                'error' => $error,
            ];
        }
        return $rows;
    }

    /**
     * Search and replace, literal, optionally whole word and case sensitive.
     *
     * @param string $name
     * @param array $op
     * @return string
     */
    private static function op_replace(string $name, array $op): string {
        $search = (string) ($op['search'] ?? '');
        $replace = (string) ($op['replace'] ?? '');
        if ($search === '') {
            return $name;
        }
        $casesensitive = !empty($op['casesensitive']);
        $wholeword = !empty($op['wholeword']);
        if (!$wholeword && $casesensitive) {
            return str_replace($search, $replace, $name);
        }
        $pattern = preg_quote($search, '/');
        if ($wholeword) {
            $pattern = '(?<![\p{L}\p{N}])' . $pattern . '(?![\p{L}\p{N}])';
        }
        $result = preg_replace_callback(
            '/' . $pattern . '/u' . ($casesensitive ? '' : 'i'),
            function () use ($replace): string {
                return $replace;
            },
            $name
        );
        return $result ?? $name;
    }

    /**
     * Adds or removes a prefix or suffix; both are idempotent.
     *
     * @param string $name
     * @param array $op
     * @return string
     */
    private static function op_affix(string $name, array $op): string {
        $text = (string) ($op['text'] ?? '');
        if ($text === '') {
            return $name;
        }
        $prefix = ($op['position'] ?? '') === 'prefix';
        $length = strlen($text);
        $present = $prefix ? strncmp($name, $text, $length) === 0 : substr($name, -$length) === $text;
        if (($op['mode'] ?? '') === 'remove') {
            if (!$present) {
                return $name;
            }
            return $prefix ? substr($name, $length) : substr($name, 0, strlen($name) - $length);
        }
        if ($present) {
            return $name;
        }
        return $prefix ? $text . $name : $name . $text;
    }

    /**
     * Cleans spaces and hyphens, fixes roman numerals and applies a case mode.
     *
     * @param string $name
     * @param string $case none, title, sentence or upper.
     * @return string
     */
    private static function op_clean(string $name, string $case): string {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        // Only hyphens with a space on at least one side: "2026-2" stays as it is.
        $name = preg_replace('/(?<=\S)(?:\s+-\s*|\s*-\s+)(?=\S)/u', ' - ', $name);
        $name = preg_replace_callback(
            self::TOKEN,
            function (array $m): string {
                $word = $m[0];
                if (strpos($word, 'l') !== false && self::is_letters($word) && sorter::roman_value($word) !== null) {
                    return self::roman_string(sorter::roman_value($word));
                }
                return $word;
            },
            $name
        );
        switch ($case) {
            case 'upper':
                return \core_text::strtoupper($name);
            case 'title':
                return self::words_case($name, true);
            case 'sentence':
                return self::words_case($name, false);
        }
        return $name;
    }

    /**
     * Title or sentence case, keeping acronyms and roman numerals.
     *
     * @param string $name
     * @param bool $title True for title case, false for sentence case.
     * @return string
     */
    private static function words_case(string $name, bool $title): string {
        $allcaps = !preg_match('/\p{Ll}/u', $name);
        $index = 0;
        return preg_replace_callback(
            self::TOKEN,
            function (array $m) use (&$index, $allcaps, $title): string {
                $word = $m[0];
                $first = $index === 0;
                $index++;
                if (!self::is_letters($word)) {
                    return $word;
                }
                $lower = \core_text::strtolower($word);
                if (!$first && in_array($lower, self::CONNECTORS, true)) {
                    return $lower;
                }
                if (sorter::roman_value($word) !== null) {
                    return \core_text::strtoupper($word);
                }
                $length = \core_text::strlen($word);
                if (!$allcaps && $length >= 2 && $length <= 6 && $word === \core_text::strtoupper($word)) {
                    return $word;
                }
                if ($title || $first) {
                    return self::ucfirst($lower);
                }
                return $lower;
            },
            $name
        );
    }

    /**
     * Whether a token is made only of letters.
     *
     * @param string $token
     * @return bool
     */
    private static function is_letters(string $token): bool {
        return preg_match('/^[\p{L}\p{M}]+$/u', $token) === 1;
    }

    /**
     * Uppercases the first character, UTF-8 aware.
     *
     * @param string $text
     * @return string
     */
    private static function ucfirst(string $text): string {
        if (!preg_match('/^(.)(.*)$/su', $text, $m)) {
            return $text;
        }
        return \core_text::strtoupper($m[1]) . $m[2];
    }

    /**
     * Canonical uppercase roman numeral for a value between 1 and 30.
     *
     * @param int $value
     * @return string
     */
    private static function roman_string(int $value): string {
        $units = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX'];
        return str_repeat('X', intdiv($value, 10)) . $units[$value % 10];
    }
}
