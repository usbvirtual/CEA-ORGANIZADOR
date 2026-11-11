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
 * Suggests corrected names: format, glossary, accents learned from the site and plural variants.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Pure suggestion engine: it never touches the database.
 */
class suggester {
    /** @var string Word token, with combining marks. */
    private const WORD = '/[\p{L}\p{M}\p{N}]+/u';

    /**
     * Word groups of a list of names.
     *
     * @param string[] $names
     * @return array Map from the accent-free lowercase word to [lowercase form => count].
     */
    public static function vocabulary(array $names): array {
        $vocabulary = [];
        foreach ($names as $name) {
            preg_match_all(self::WORD, strip_tags((string) $name), $matches);
            foreach ($matches[0] as $word) {
                if (!preg_match('/\p{L}/u', $word)) {
                    continue;
                }
                $form = \core_text::strtolower($word);
                $key = self::key($word);
                $vocabulary[$key][$form] = ($vocabulary[$key][$form] ?? 0) + 1;
            }
        }
        return $vocabulary;
    }

    /**
     * Parses the glossary text, one "wrong => right" rule per line.
     *
     * @param string $text
     * @return array List of [from, to]; lines without "=>" or with an empty side are ignored.
     */
    public static function parse_glossary(string $text): array {
        $rules = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $position = strpos($line, '=>');
            if ($position === false) {
                continue;
            }
            $from = trim(substr($line, 0, $position));
            $to = trim(substr($line, $position + 2));
            if ($from !== '' && $to !== '') {
                $rules[] = [$from, $to];
            }
        }
        return $rules;
    }

    /**
     * Preferred form of the words that differ only by a plural.
     *
     * @param string[] $siblingnames Names of the sibling categories.
     * @param array $vocabulary As returned by vocabulary().
     * @return array Map from the accent-free lowercase word to its preferred form.
     */
    public static function sibling_forms(array $siblingnames, array $vocabulary): array {
        $keys = [];
        foreach ($siblingnames as $name) {
            preg_match_all(self::WORD, strip_tags((string) $name), $matches);
            foreach ($matches[0] as $word) {
                $keys[self::key($word)] = true;
            }
        }
        $keys = array_map('strval', array_keys($keys));
        sort($keys, SORT_STRING);
        $forms = [];
        foreach ($keys as $singular) {
            if (\core_text::strlen($singular) < 3 || !preg_match('/^\p{L}+$/u', $singular)) {
                continue;
            }
            foreach (['s', 'es'] as $suffix) {
                $plural = $singular . $suffix;
                if (!in_array($plural, $keys, true)) {
                    continue;
                }
                // On a tie the plural wins.
                $winner = self::group_count($vocabulary, $plural) >= self::group_count($vocabulary, $singular)
                    ? $plural : $singular;
                $preferred = self::top_form($vocabulary, $winner);
                $forms[$singular] = $preferred;
                $forms[$plural] = $preferred;
            }
        }
        return $forms;
    }

    /**
     * Suggests a new name by chaining the rules: formato, glosario, tilde, variante.
     *
     * @param string $name
     * @param array $vocabulary As returned by vocabulary().
     * @param array $glossary As returned by parse_glossary().
     * @param array $siblingforms As returned by sibling_forms().
     * @return array ['new' => string, 'reasons' => string[]]; the reasons are empty when nothing changes.
     */
    public static function suggest(string $name, array $vocabulary, array $glossary, array $siblingforms = []): array {
        // Multilang names are not touched, like in the rename tool.
        if (strpos($name, '<') !== false) {
            return ['new' => $name, 'reasons' => []];
        }
        $current = $name;
        $reasons = [];
        $steps = [
            'formato' => function (string $text): string {
                return renamer::apply($text, [['type' => 'clean', 'case' => 'none']]);
            },
            'glosario' => function (string $text) use ($glossary): string {
                return self::apply_glossary($text, $glossary);
            },
            'tilde' => function (string $text) use ($vocabulary): string {
                return self::apply_accents($text, $vocabulary);
            },
            'variante' => function (string $text) use ($siblingforms): string {
                return self::apply_siblings($text, $siblingforms);
            },
        ];
        foreach ($steps as $reason => $step) {
            $next = $step($current);
            if ($next !== $current) {
                $reasons[] = $reason;
                $current = $next;
            }
        }
        if ($current === $name) {
            return ['new' => $name, 'reasons' => []];
        }
        return ['new' => $current, 'reasons' => $reasons];
    }

    /**
     * Replaces whole words, ignoring case, with the glossary rules.
     *
     * @param string $text
     * @param array $glossary
     * @return string
     */
    private static function apply_glossary(string $text, array $glossary): string {
        foreach ($glossary as [$from, $to]) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($from, '/') . '(?![\p{L}\p{N}])/iu';
            $result = preg_replace_callback($pattern, function () use ($to): string {
                return $to;
            }, $text);
            $text = $result ?? $text;
        }
        return $text;
    }

    /**
     * Adds the accent to words without one when the site writes them accented, at least twice.
     *
     * @param string $text
     * @param array $vocabulary
     * @return string
     */
    private static function apply_accents(string $text, array $vocabulary): string {
        return preg_replace_callback(self::WORD, function (array $m) use ($vocabulary): string {
            $word = $m[0];
            $key = self::key($word);
            if ($word !== \core_text::specialtoascii($word) || empty($vocabulary[$key])) {
                return $word;
            }
            $forms = $vocabulary[$key];
            arsort($forms);
            $best = (string) array_key_first($forms);
            $count = $forms[$best];
            $second = count($forms) > 1 ? array_values($forms)[1] : 0;
            // Only a clear winner, written with an accent, at least twice.
            if ($count < 2 || $count === $second || $best === \core_text::specialtoascii($best)) {
                return $word;
            }
            return self::match_case($word, $best);
        }, $text) ?? $text;
    }

    /**
     * Unifies plural variants with the preferred form among siblings.
     *
     * @param string $text
     * @param array $siblingforms
     * @return string
     */
    private static function apply_siblings(string $text, array $siblingforms): string {
        if (!$siblingforms) {
            return $text;
        }
        return preg_replace_callback(self::WORD, function (array $m) use ($siblingforms): string {
            $word = $m[0];
            $key = self::key($word);
            if (!isset($siblingforms[$key]) || \core_text::strtolower($word) === $siblingforms[$key]) {
                return $word;
            }
            return self::match_case($word, $siblingforms[$key]);
        }, $text) ?? $text;
    }

    /**
     * Gives the replacement the capitalization of the original word.
     *
     * @param string $original
     * @param string $replacement Lowercase.
     * @return string
     */
    private static function match_case(string $original, string $replacement): string {
        $first = \core_text::substr($original, 0, 1);
        $firstupper = $first !== \core_text::strtolower($first);
        if ($firstupper && $original === \core_text::strtoupper($original)) {
            return \core_text::strtoupper($replacement);
        }
        if ($firstupper) {
            return \core_text::strtoupper(\core_text::substr($replacement, 0, 1)) . \core_text::substr($replacement, 1);
        }
        return $replacement;
    }

    /**
     * Accent-free lowercase key of a word.
     *
     * @param string $word
     * @return string
     */
    private static function key(string $word): string {
        return \core_text::strtolower(\core_text::specialtoascii($word));
    }

    /**
     * Total occurrences of a word group.
     *
     * @param array $vocabulary
     * @param string $key
     * @return int
     */
    private static function group_count(array $vocabulary, string $key): int {
        return array_sum($vocabulary[$key] ?? []);
    }

    /**
     * Most frequent form of a word group, preferring the accented one on a tie.
     *
     * @param array $vocabulary
     * @param string $key
     * @return string The key itself when the group is unknown.
     */
    private static function top_form(array $vocabulary, string $key): string {
        $forms = $vocabulary[$key] ?? [];
        if (!$forms) {
            return $key;
        }
        $best = $key;
        $bestcount = -1;
        foreach ($forms as $form => $count) {
            $form = (string) $form;
            $accented = $form !== \core_text::specialtoascii($form);
            if ($count > $bestcount || ($count === $bestcount && $accented)) {
                $best = $form;
                $bestcount = $count;
            }
        }
        return $best;
    }
}
