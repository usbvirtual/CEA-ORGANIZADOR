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
 * Pure rules to find repeated courses inside one category.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Comparison keys of course names and groups of repeated courses. No database access.
 *
 * Two courses are the same when any of these holds: the same normalized full name (rule 'name'); the same full
 * name once a trailing copy, period or group marker is stripped (rule 'key'); the same short name once a trailing
 * copy marker, a period or the «_N» counter is stripped (rule 'shortname'), as long as their names share a
 * significant word. The rules are conservative: level numbers, roman or arabic («Cálculo I» and «Cálculo II»,
 * «Física 1» and «Física 2», «MAT-1» and «MAT-2»), are never stripped, and two courses whose names differ only in a
 * level number or in their group («… G1» and «… G2», «Grupo A» and «Grupo B») are never grouped, by any rule, not
 * even through a third course (see groups()).
 */
class duplicates {
    /** @var string Name of the hidden folder that receives the repeated courses. */
    public const FOLDER = 'Repetidos';

    /** @var string Rules, in the order they are reported. */
    public const RULES = ['name', 'key', 'shortname'];

    /** @var int Shortest base short name that can match, in letters and digits. */
    public const MIN_SHORTNAME = 3;

    /** @var string[] Reasons of a possible group, most cautious first. */
    public const POSSIBLE_REASONS = ['group', 'shortname', 'extra', 'similar'];

    /** @var int Shortest name key compared by edit distance for a possible group. */
    public const MIN_SIMILAR = 12;

    /** @var int Shortest word that counts as significant for the short name rule. */
    public const MIN_WORD = 3;

    /** @var string[] Words that never count as shared, though they have three letters or more. */
    public const STOPWORDS = ['del', 'las', 'los', 'para', 'por', 'con', 'una', 'uno', 'the', 'and', 'for'];

    /** @var string «Copia de …» or «Copy of …» at the start. */
    private const COPY_PREFIX = '/^(?:copia\s+de|copy\s+of)\s+/';

    /** @var string A trailing copy marker: «copia», «copia 2», «(copia)», «copy 1». */
    private const COPY = '/[\s_-]*\(?\s*(?<![a-z0-9])(?:copia|copy)(?:\s*\d{1,3})?\s*\)?$/';

    /** @var string A trailing period, as sorter::parse_period reads it, maybe in parentheses: «2025-2», «(2025-02)». */
    private const PERIOD = '/[\s_-]*\(?\s*(?<![a-z0-9])20\d\d\s*[-–_\/ ]\s*(?:0?[12]|ii|i)\s*\)?$/u';

    /**
     * @var string A trailing group marker: «Grupo 1», «Grupo A», «G1», «Gr. 2». The group id is captured in 'id'
     *      (digits) or 'letter'.
     */
    private const GROUP = '/[\s_-]*\(?\s*(?<![a-z0-9])(?:(?:grupo|group)(?:\s+(?<letter>[a-z])|\s*(?<id>\d{1,4}))'
        . '|gr?\.?\s?(?<gid>\d{1,4}))\s*\)?$/';

    /**
     * @var string A trailing period in a short name, with a separator between year and term: «-2025-2», «_2025-02».
     *      «CAL20261» is not a period: digits glued to the code are part of it.
     */
    private const SHORT_PERIOD = '/[\s_-]*\(?\s*(?<!\d)20\d\d\s*[-–_\/ ]\s*(?:0?[12]|ii|i)\s*\)?$/u';

    /**
     * @var string A trailing «_1», the counter Moodle adds when a short name is taken. Never «-1»: «MAT-1» and «MAT-2»
     *      are two levels, two different courses. A hyphen is stripped only as part of a period (SHORT_PERIOD).
     */
    private const COUNTER = '/_\d{1,2}$/';

    /** @var string Characters trimmed from the end of a key. */
    private const TRIM = " \t-_.,:;";

    /**
     * Key of a full name: normalized, without a leading «Copia de» and without trailing copy, period or group markers.
     *
     * @param string $fullname Raw full name.
     * @return string '' when nothing is left.
     */
    public static function key_name(string $fullname): string {
        return self::parse_name($fullname)[0];
    }

    /**
     * Group of a full name: the id of its trailing group marker, normalized («Grupo 01» and «G1» give '1', «Grupo A»
     * gives 'a'), or '' when it has none.
     *
     * @param string $fullname Raw full name.
     * @return string
     */
    public static function group_id(string $fullname): string {
        return self::parse_name($fullname)[1];
    }

    /**
     * Key of a short name: normalized, without trailing copy markers, periods or the «_N» counter.
     *
     * @param string $shortname Raw short name.
     * @return string '' when fewer than MIN_SHORTNAME letters and digits are left.
     */
    public static function key_shortname(string $shortname): string {
        $key = self::strip(sorter::normalize($shortname), [self::COPY, self::SHORT_PERIOD, self::COUNTER]);
        if (strlen(preg_replace('/[^a-z0-9]/', '', $key)) < self::MIN_SHORTNAME) {
            return '';
        }
        return $key;
    }

    /**
     * Significant words of a name key: three letters or more, not a stopword.
     *
     * @param string $key As key_name() returns it.
     * @return string[] Map word => word.
     */
    public static function words(string $key): array {
        preg_match_all('/[a-z]+/', $key, $matches);
        $words = [];
        foreach ($matches[0] as $word) {
            if (strlen($word) >= self::MIN_WORD && !in_array($word, self::STOPWORDS, true)) {
                $words[$word] = $word;
            }
        }
        return $words;
    }

    /**
     * Splits a name key into its stem and its level numbers.
     *
     * Every run of digits and every isolated roman numeral (sorter::roman_value) is a level; the stem is the key with
     * each level replaced by '#'. «calculo ii» gives ['calculo #', '2'], «fisica 1» gives ['fisica #', '1'].
     *
     * @param string $key As key_name() returns it.
     * @return array [string stem, string levels joined by ',']
     */
    public static function levels(string $key): array {
        preg_match_all('/\d+|[a-z]+|[^a-z\d]+/', $key, $matches);
        $parts = $matches[0];
        $stem = '';
        $levels = [];
        foreach ($parts as $i => $part) {
            if (ctype_digit($part)) {
                $stem .= '#';
                $levels[] = (string) (int) $part;
                continue;
            }
            $isolated = ctype_alpha($part)
                && !(isset($parts[$i - 1]) && ctype_digit($parts[$i - 1]))
                && !(isset($parts[$i + 1]) && ctype_digit($parts[$i + 1]));
            $roman = $isolated ? sorter::roman_value($part) : null;
            if ($roman !== null && $roman > 0) {
                $stem .= '#';
                $levels[] = (string) $roman;
                continue;
            }
            $stem .= $part;
        }
        return [$stem, implode(',', $levels)];
    }

    /**
     * Groups of repeated courses among the courses of one category.
     *
     * Grouping is transitive: A like B by name and B like C by short name put A, B and C in one group. The course
     * that stays is ranked by [visible, activities, timemodified, id] (group_of()): a visible course before a hidden
     * one, then the one with more activities, then the most recently modified, then the highest id.
     *
     * A group never holds two courses whose name keys share a stem (levels()) with different level numbers, nor two
     * different group ids of the same stem: «Cálculo I» and «Cálculo II» stay apart, and so do «… G1» and «… G2»,
     * even when their short names match and even through a third course («Cálculo I» may join «Cálculo I G1», but
     * then not «Cálculo I G2»). Two groups that would break this are not joined; links are made in a fixed order
     * (rule, then course order), so the result does not depend on chance. A short name match links two courses only
     * when their name keys share a significant word (words()).
     *
     * @param array $courses List of ['id' => int, 'fullname' => string, 'shortname' => string, 'timemodified' => int,
     *      'visible' => int (optional, 1 when missing), 'activities' => int (optional, 0 when missing)], raw names. The
     *      order is kept inside each group and between groups.
     * @return array List of ['key' => string (sorted ids joined by '-'), 'ids' => int[] (sorted), 'keep' => int,
     *      'courses' => [['id' => int, 'reasons' => string[], 'keep' => bool]]], only groups of two or more.
     */
    public static function groups(array $courses): array {
        $courses = array_values($courses);
        $count = count($courses);
        $parent = $count ? range(0, $count - 1) : [];
        $find = static function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }
            return $i;
        };

        $names = [];
        $words = [];
        // Per component root: stem => ['levels' => string, 'group' => string ('' when no course of it has one)].
        $profile = [];
        $buckets = array_fill_keys(self::RULES, []);
        foreach ($courses as $i => $course) {
            $names[$i] = sorter::normalize((string) $course['fullname']);
            [$key, $group] = self::parse_name((string) $course['fullname']);
            $values = [
                'name' => $names[$i],
                'key' => $key,
                'shortname' => self::key_shortname((string) $course['shortname']),
            ];
            foreach ($values as $rule => $value) {
                if ($value !== '') {
                    $buckets[$rule][$value][] = $i;
                }
            }
            $words[$i] = self::words($key);
            $profile[$i] = [];
            if ($key !== '') {
                [$stem, $levels] = self::levels($key);
                $profile[$i][$stem] = ['levels' => $levels, 'group' => $group];
            }
        }
        // Joins two components unless that puts two level numbers, or two group ids, of the same stem together.
        $join = static function (int $i, int $j) use (&$parent, &$profile, $find): bool {
            $a = $find($i);
            $b = $find($j);
            if ($a === $b) {
                return true;
            }
            foreach ($profile[$a] as $stem => $mine) {
                if (!isset($profile[$b][$stem])) {
                    continue;
                }
                $theirs = $profile[$b][$stem];
                if ($theirs['levels'] !== $mine['levels']) {
                    return false;
                }
                if ($theirs['group'] !== '' && $mine['group'] !== '' && $theirs['group'] !== $mine['group']) {
                    return false;
                }
            }
            // The joined component keeps, per stem, the levels and the group id it has (if any course has one).
            foreach ($profile[$a] as $stem => $mine) {
                if (!isset($profile[$b][$stem])) {
                    $profile[$b][$stem] = $mine;
                } else if ($profile[$b][$stem]['group'] === '') {
                    $profile[$b][$stem]['group'] = $mine['group'];
                }
            }
            $parent[$a] = $b;
            return true;
        };

        $reasons = array_fill(0, $count, []);
        foreach ($buckets as $rule => $list) {
            foreach ($list as $members) {
                foreach ($members as $position => $i) {
                    foreach (array_slice($members, 0, $position) as $j) {
                        // A short name alone links only names that share a significant word.
                        if ($rule === 'shortname' && !array_intersect_key($words[$i], $words[$j])) {
                            continue;
                        }
                        if (!$join($j, $i)) {
                            continue;
                        }
                        // The key rule is reported only when it links names that differ; otherwise 'name' says it all.
                        if ($rule !== 'key' || $names[$i] !== $names[$j]) {
                            $reasons[$i][$rule] = true;
                            $reasons[$j][$rule] = true;
                        }
                    }
                }
            }
        }

        $byroot = [];
        foreach (array_keys($courses) as $i) {
            $byroot[$find($i)][] = $i;
        }
        $groups = [];
        foreach ($byroot as $members) {
            if (count($members) < 2) {
                continue;
            }
            $memberreasons = [];
            foreach ($members as $i) {
                $mine = $reasons[$i];
                $memberreasons[$i] = array_values(array_filter(self::RULES, static function (string $rule) use ($mine): bool {
                    return isset($mine[$rule]);
                }));
            }
            $groups[] = self::group_of($courses, $memberreasons);
        }
        return $groups;
    }

    /**
     * «Posibles repetidos»: courses whose names are equal or very close but that the safe rules do not group.
     *
     * The tool asks the user about them instead of deciding. Only courses that are in no group of groups() take part,
     * so a confirmed possible group never overlaps a sure one. Reasons, in the order they are tried for a pair:
     * - 'group': the same name key with two different group ids («… G1» and «… G2»): normally NOT repeated;
     * - 'shortname': the same short name key, with names that share no significant word;
     * - 'extra': one name key is the other plus one trailing word of three letters or more, or a parenthesis;
     * - 'similar': the same set of words in another order, or, for keys of 12 characters or more, a Levenshtein
     *   distance of 2 or less.
     * Except for 'group', both keys must have the same level numbers and no conflicting group ids: «Cálculo I» and
     * «Cálculo II», or «Programación» and «Programación I», are never suggested. Pairs are joined transitively; a
     * possible group takes the most cautious reason of its pairs ('group' first).
     *
     * @param array $courses As groups() takes them: the courses of one category.
     * @param array $groups What groups() returned for them.
     * @return array List of groups as groups() returns them, plus 'reason' => string.
     */
    public static function candidates(array $courses, array $groups): array {
        $courses = array_values($courses);
        $grouped = [];
        foreach ($groups as $group) {
            foreach ($group['ids'] as $id) {
                $grouped[$id] = true;
            }
        }
        $info = [];
        foreach ($courses as $i => $course) {
            if (isset($grouped[(int) $course['id']])) {
                continue;
            }
            [$key, $group] = self::parse_name((string) $course['fullname']);
            if ($key === '') {
                continue;
            }
            [$stem, $levels] = self::levels($key);
            $info[$i] = [
                'key' => $key,
                'group' => $group,
                'stem' => $stem,
                'levels' => $levels,
                'short' => self::key_shortname((string) $course['shortname']),
                'words' => self::words($key),
            ];
        }

        $parent = [];
        foreach (array_keys($info) as $i) {
            $parent[$i] = $i;
        }
        $find = static function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }
            return $i;
        };
        $order = array_flip(self::POSSIBLE_REASONS);
        $reasonof = [];
        $indexes = array_keys($info);
        foreach ($indexes as $position => $i) {
            foreach (array_slice($indexes, 0, $position) as $j) {
                $reason = self::pair_reason($info[$j], $info[$i]);
                if ($reason === null) {
                    continue;
                }
                $a = $find($j);
                $b = $find($i);
                $best = $reason;
                foreach ([$a, $b] as $root) {
                    if (isset($reasonof[$root]) && $order[$reasonof[$root]] < $order[$best]) {
                        $best = $reasonof[$root];
                    }
                }
                if ($a !== $b) {
                    $parent[$a] = $b;
                    unset($reasonof[$a]);
                }
                $reasonof[$b] = $best;
            }
        }

        $byroot = [];
        foreach (array_keys($info) as $i) {
            $byroot[$find($i)][] = $i;
        }
        $possible = [];
        foreach ($byroot as $root => $members) {
            if (count($members) < 2) {
                continue;
            }
            $memberreasons = array_fill_keys($members, [$reasonof[$root]]);
            $possible[] = self::group_of($courses, $memberreasons) + ['reason' => $reasonof[$root]];
        }
        return $possible;
    }

    /**
     * Why two courses might be the same, or null when they are not close enough or a veto makes them different.
     *
     * @param array $one Name data of a course, as candidates() builds it.
     * @param array $two
     * @return string|null One of POSSIBLE_REASONS.
     */
    private static function pair_reason(array $one, array $two): ?string {
        // The case the group veto blocks: shown with a warning, the user decides.
        if ($one['key'] === $two['key']) {
            return ($one['group'] !== '' && $two['group'] !== '' && $one['group'] !== $two['group']) ? 'group' : null;
        }
        // Everything else needs the same level numbers and no conflicting group ids.
        if ($one['levels'] !== $two['levels']) {
            return null;
        }
        if ($one['group'] !== '' && $two['group'] !== '' && $one['group'] !== $two['group']) {
            return null;
        }
        if ($one['short'] !== '' && $one['short'] === $two['short'] && !array_intersect_key($one['words'], $two['words'])) {
            return 'shortname';
        }
        [$short, $long] = strlen($one['key']) <= strlen($two['key']) ? [$one['key'], $two['key']] : [$two['key'], $one['key']];
        if (str_starts_with($long, $short . ' ')) {
            $rest = trim(substr($long, strlen($short)), " \t-:");
            if (preg_match('/^[a-z]{3,}$/', $rest) || preg_match('/^\([^()]+\)$/', $rest)) {
                return 'extra';
            }
        }
        $onewords = preg_split('/[^a-z0-9]+/', $one['key'], -1, PREG_SPLIT_NO_EMPTY);
        $twowords = preg_split('/[^a-z0-9]+/', $two['key'], -1, PREG_SPLIT_NO_EMPTY);
        sort($onewords);
        sort($twowords);
        if ($onewords === $twowords) {
            return 'similar';
        }
        if (min(strlen($one['key']), strlen($two['key'])) >= self::MIN_SIMILAR && levenshtein($one['key'], $two['key']) <= 2) {
            return 'similar';
        }
        return null;
    }

    /**
     * Builds a group from some courses: its key, its sorted ids and the course that stays.
     *
     * The course that stays is ranked by [visible, activities, timemodified, id]: a visible course before a hidden
     * one, then the most complete, then the most recently modified, then the highest id.
     *
     * @param array $courses All the courses, by index.
     * @param array $memberreasons Index of each member => its reasons, in the order of the group.
     * @return array ['key', 'ids', 'keep', 'courses' => [['id', 'reasons', 'keep']]]
     */
    private static function group_of(array $courses, array $memberreasons): array {
        $keep = null;
        $ids = [];
        foreach (array_keys($memberreasons) as $i) {
            $course = $courses[$i];
            $ids[] = (int) $course['id'];
            $rank = [
                (int) ($course['visible'] ?? 1) ? 1 : 0,
                (int) ($course['activities'] ?? 0),
                (int) $course['timemodified'],
                (int) $course['id'],
            ];
            if ($keep === null || $rank > $keep[0]) {
                $keep = [$rank, (int) $course['id']];
            }
        }
        $list = [];
        foreach ($memberreasons as $i => $reasons) {
            $id = (int) $courses[$i]['id'];
            $list[] = ['id' => $id, 'reasons' => $reasons, 'keep' => $id === $keep[1]];
        }
        sort($ids);
        return ['key' => implode('-', $ids), 'ids' => $ids, 'keep' => $keep[1], 'courses' => $list];
    }

    /**
     * Key and group id of a full name.
     *
     * @param string $fullname Raw full name.
     * @return array [string key, string group id]
     */
    private static function parse_name(string $fullname): array {
        $key = rtrim((string) preg_replace(self::COPY_PREFIX, '', sorter::normalize($fullname)), self::TRIM);
        $group = '';
        do {
            $before = $key;
            $key = rtrim((string) preg_replace(self::COPY, '', $key), self::TRIM);
            $key = rtrim((string) preg_replace(self::PERIOD, '', $key), self::TRIM);
            if (preg_match(self::GROUP, $key, $match)) {
                // The marker nearest to the name is the group: «Curso G1 (copia)» is group 1.
                $id = ($match['id'] ?? '') . ($match['gid'] ?? '');
                $group = $id !== '' ? (string) (int) $id : (string) ($match['letter'] ?? '');
                $key = rtrim(substr($key, 0, -strlen($match[0])), self::TRIM);
            }
        } while ($key !== $before && $key !== '');
        return [$key, $group];
    }

    /**
     * Strips trailing markers until none is left.
     *
     * @param string $key
     * @param string[] $patterns
     * @return string
     */
    private static function strip(string $key, array $patterns): string {
        $key = rtrim($key, self::TRIM);
        do {
            $before = $key;
            foreach ($patterns as $pattern) {
                $key = rtrim((string) preg_replace($pattern, '', $key), self::TRIM);
            }
        } while ($key !== $before && $key !== '');
        return $key;
    }
}
