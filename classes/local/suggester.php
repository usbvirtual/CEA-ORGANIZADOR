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

    /** @var string[] Accent-free words whose accent changes the meaning: never accented automatically. */
    private const AMBIGUOUS = [
        'si', 'mas', 'el', 'tu', 'te', 'se', 'de', 'mi', 'solo', 'aun', 'que', 'como', 'cuando', 'donde',
        'quien', 'cual', 'cuanto', 'este', 'esta', 'ese', 'esa', 'aquel', 'o',
    ];

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
     * Plural unifications among sibling names.
     *
     * Two sibling names pair up only when they are identical except for one word at the same
     * position, and one form is the other plus "s" or "es" (so "ción" pairs with "ciones").
     * The preferred form is the one with more occurrences in the vocabulary; a tie goes to the plural.
     *
     * @param string[] $siblingnames Names of the sibling categories (same parent).
     * @param array $vocabulary As returned by vocabulary().
     * @return array Map from sibling name (as given) to [accent-free lowercase word => preferred
     *     word as written in the vocabulary]; only names that must change appear. Pass the entry of the
     *     name being suggested as the fourth argument of suggest().
     */
    public static function sibling_forms(array $siblingnames, array $vocabulary): array {
        $parsed = [];
        foreach (array_values(array_unique(array_map('strval', $siblingnames))) as $name) {
            preg_match_all(self::WORD, strip_tags($name), $matches);
            $parsed[$name] = [
                'keys' => array_map([self::class, 'key'], $matches[0]),
                'skeleton' => preg_replace(self::WORD, '', strip_tags($name)),
            ];
        }
        $forms = [];
        foreach ($parsed as $namea => $a) {
            foreach ($parsed as $nameb => $b) {
                if ($namea === $nameb || count($a['keys']) !== count($b['keys']) || $a['skeleton'] !== $b['skeleton']) {
                    continue;
                }
                $diff = array_keys(array_diff_assoc($a['keys'], $b['keys']));
                if (count($diff) !== 1) {
                    continue;
                }
                $singular = (string) $a['keys'][$diff[0]];
                $plural = (string) $b['keys'][$diff[0]];
                // Each ordered pair is visited once as (singular, plural).
                if (
                    \core_text::strlen($singular) < 3 || !preg_match('/^\p{L}+$/u', $singular)
                        || !in_array($plural, [$singular . 's', $singular . 'es'], true)
                ) {
                    continue;
                }
                $pluralwins = self::group_count($vocabulary, $plural) >= self::group_count($vocabulary, $singular);
                $loser = $pluralwins ? $namea : $nameb;
                $from = $pluralwins ? $singular : $plural;
                $to = self::top_form($vocabulary, $pluralwins ? $plural : $singular);
                if (!isset($forms[$loser][$from])) {
                    $forms[$loser][$from] = $to;
                }
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
     * @param array $siblingforms The entry of this very name in sibling_forms() (or empty).
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
        // Glossary output may bring its own spacing: clean once more so the result is stable.
        $clean = $steps['formato']($current);
        if ($clean !== $current) {
            $current = $clean;
            if (!in_array('formato', $reasons, true)) {
                array_unshift($reasons, 'formato');
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
            $pattern = '/(?<![\p{L}\p{M}\p{N}])' . preg_quote($from, '/') . '(?![\p{L}\p{M}\p{N}])/iu';
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
            if (
                $word !== \core_text::specialtoascii($word) || empty($vocabulary[$key])
                    || in_array($key, self::AMBIGUOUS, true) || preg_match('/\p{N}/u', $word)
            ) {
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
     * Unifies plural variants with the preferred form among siblings, copying that form.
     *
     * @param string $text
     * @param array $siblingforms Map from accent-free word to the preferred word as written.
     * @return string
     */
    private static function apply_siblings(string $text, array $siblingforms): string {
        if (!$siblingforms) {
            return $text;
        }
        return preg_replace_callback(self::WORD, function (array $m) use ($siblingforms): string {
            $word = $m[0];
            $key = self::key($word);
            if (!isset($siblingforms[$key])) {
                return $word;
            }
            return self::match_case($word, (string) $siblingforms[$key]);
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
