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
 * copy marker, period or counter is stripped (rule 'shortname'). The rules are conservative: level numbers, roman
 * or arabic («Cálculo I» and «Cálculo II», «Física 1» and «Física 2»), are never stripped.
 */
class duplicates {
    /** @var string Name of the hidden folder that receives the repeated courses. */
    public const FOLDER = 'Repetidos';

    /** @var string Rules, in the order they are reported. */
    public const RULES = ['name', 'key', 'shortname'];

    /** @var int Shortest base short name that can match, in letters and digits. */
    public const MIN_SHORTNAME = 3;

    /** @var string «Copia de …» or «Copy of …» at the start. */
    private const COPY_PREFIX = '/^(?:copia\s+de|copy\s+of)\s+/';

    /** @var string A trailing copy marker: «copia», «copia 2», «(copia)», «copy 1». */
    private const COPY = '/[\s_-]*\(?\s*(?<![a-z0-9])(?:copia|copy)(?:\s*\d{1,3})?\s*\)?$/';

    /** @var string A trailing period, as sorter::parse_period reads it, maybe in parentheses: «2025-2», «(2025-02)». */
    private const PERIOD = '/[\s_-]*\(?\s*(?<![a-z0-9])20\d\d\s*[-–_\/ ]\s*(?:0?[12]|ii|i)\s*\)?$/u';

    /** @var string A trailing group marker: «Grupo 1», «Grupo A», «G1», «Gr. 2». */
    private const GROUP = '/[\s_-]*\(?\s*(?<![a-z0-9])(?:(?:grupo|group)(?:\s+[a-z]|\s*\d{1,4})|gr?\.?\s?\d{1,4})\s*\)?$/';

    /** @var string A trailing period in a short name; the separator between year and term may be missing. */
    private const SHORT_PERIOD = '/[\s_-]*\(?\s*(?<!\d)20\d\d\s*[-–_\/ ]?\s*(?:0?[12]|ii|i)\s*\)?$/u';

    /** @var string A trailing counter of a short name: «_1» (as a restore names a copy) or «-2». */
    private const COUNTER = '/[_-]\d{1,2}$/';

    /** @var string Characters trimmed from the end of a key. */
    private const TRIM = " \t-_.,:;";

    /**
     * Key of a full name: normalized, without a leading «Copia de» and without trailing copy, period or group markers.
     *
     * @param string $fullname Raw full name.
     * @return string '' when nothing is left.
     */
    public static function key_name(string $fullname): string {
        $key = preg_replace(self::COPY_PREFIX, '', sorter::normalize($fullname));
        return self::strip($key, [self::COPY, self::PERIOD, self::GROUP]);
    }

    /**
     * Key of a short name: normalized, without trailing copy markers, periods or counters.
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

    /**
     * Groups of repeated courses among the courses of one category.
     *
     * Grouping is transitive: A like B by name and B like C by short name put A, B and C in one group. The course
     * that stays is the most recently modified; a tie goes to the highest id.
     *
     * @param array $courses List of ['id' => int, 'fullname' => string, 'shortname' => string, 'timemodified' => int],
     *      raw names. The order is kept inside each group and between groups.
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
        $buckets = array_fill_keys(self::RULES, []);
        foreach ($courses as $i => $course) {
            $names[$i] = sorter::normalize((string) $course['fullname']);
            $values = [
                'name' => $names[$i],
                'key' => self::key_name((string) $course['fullname']),
                'shortname' => self::key_shortname((string) $course['shortname']),
            ];
            foreach ($values as $rule => $value) {
                if ($value !== '') {
                    $buckets[$rule][$value][] = $i;
                }
            }
        }

        $reasons = array_fill(0, $count, []);
        foreach ($buckets as $rule => $list) {
            foreach ($list as $members) {
                if (count($members) < 2) {
                    continue;
                }
                foreach ($members as $i) {
                    $parent[$find($i)] = $find($members[0]);
                    // The key rule is reported only when it links names that differ; otherwise 'name' says it all.
                    if ($rule !== 'key') {
                        $reasons[$i][$rule] = true;
                        continue;
                    }
                    foreach ($members as $j) {
                        if ($names[$j] !== $names[$i]) {
                            $reasons[$i][$rule] = true;
                            break;
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
            $keep = null;
            $ids = [];
            foreach ($members as $i) {
                $course = $courses[$i];
                $ids[] = (int) $course['id'];
                $rank = [(int) $course['timemodified'], (int) $course['id']];
                if ($keep === null || $rank > $keep[0]) {
                    $keep = [$rank, (int) $course['id']];
                }
            }
            $list = [];
            foreach ($members as $i) {
                $id = (int) $courses[$i]['id'];
                $list[] = [
                    'id' => $id,
                    'reasons' => array_values(array_filter(self::RULES, static function (string $rule) use ($reasons, $i): bool {
                        return isset($reasons[$i][$rule]);
                    })),
                    'keep' => $id === $keep[1],
                ];
            }
            sort($ids);
            $groups[] = ['key' => implode('-', $ids), 'ids' => $ids, 'keep' => $keep[1], 'courses' => $list];
        }
        return $groups;
    }
}
