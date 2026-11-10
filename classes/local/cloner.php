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
 * Pure rules to clone seed courses into an academic period.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Period labels, new names and mirrored destination paths. No database access.
 */
class cloner {
    /** @var string Same period expression as sorter::parse_period, with the separator before it. */
    private const PERIOD_WITH_SEPARATOR = '/[\s_-]*(?<!\d)20\d\d\s*[-–_\/ ]\s*(?:0?[12]|ii|i)(?![\p{L}\d])/iu';

    /**
     * Period text such as "2027-01" from a period name, or null when there is none.
     *
     * @param string $periodname
     * @return string|null
     */
    public static function period_label(string $periodname): ?string {
        $period = sorter::parse_period($periodname);
        if ($period === null) {
            return null;
        }
        return sprintf('%d-%02d', $period[0], $period[1]);
    }

    /**
     * Full name of the copy: the period is replaced, or added after a space.
     *
     * @param string $name
     * @param string $label
     * @return string
     */
    public static function new_fullname(string $name, string $label): string {
        return self::with_period($name, $label, ' ');
    }

    /**
     * Short name of the copy: the period is replaced, or added after a hyphen.
     *
     * @param string $short
     * @param string $label
     * @return string
     */
    public static function new_shortname(string $short, string $label): string {
        return self::with_period($short, $label, '-');
    }

    /**
     * Path of a category under its seed root.
     *
     * @param array $categories Map id => ['id' => int, 'parent' => int, 'name' => string].
     * @param int $categoryid
     * @param array $patterns As returned by sorter::patterns().
     * @return array ['root' => int|null, 'relative' => string[]]; names go from the root down, both included.
     */
    public static function mirror(array $categories, int $categoryid, array $patterns): array {
        $none = ['root' => null, 'relative' => []];
        $names = [];
        $seen = [];
        $id = $categoryid;
        while (true) {
            if (isset($seen[$id]) || !isset($categories[$id])) {
                return $none;
            }
            $seen[$id] = true;
            array_unshift($names, (string) $categories[$id]['name']);
            $parent = (int) $categories[$id]['parent'];
            if ($parent === 0) {
                break;
            }
            $id = $parent;
        }
        if (sorter::classify($names[0], true, $patterns) !== 'semilla') {
            return $none;
        }
        return ['root' => $id, 'relative' => $names];
    }

    /**
     * Finds how much of a relative path already exists under the period.
     *
     * @param array $categories Map id => ['id' => int, 'parent' => int, 'name' => string].
     * @param int $periodid
     * @param string[] $relative
     * @return array ['existing' => int, 'missing' => string[]]
     */
    public static function resolve(array $categories, int $periodid, array $relative): array {
        $current = $periodid;
        $relative = array_values($relative);
        foreach ($relative as $index => $name) {
            $found = null;
            foreach ($categories as $category) {
                if (
                    (int) $category['parent'] === $current
                    && sorter::normalize((string) $category['name']) === sorter::normalize($name)
                ) {
                    $found = (int) $category['id'];
                    break;
                }
            }
            if ($found === null) {
                return ['existing' => $current, 'missing' => array_slice($relative, $index)];
            }
            $current = $found;
        }
        return ['existing' => $current, 'missing' => []];
    }

    /**
     * Removes the existing period (and the separator before it) and appends the new one.
     *
     * @param string $name
     * @param string $label
     * @param string $separator
     * @return string
     */
    private static function with_period(string $name, string $label, string $separator): string {
        $base = trim(preg_replace(self::PERIOD_WITH_SEPARATOR, '', $name, 1));
        return $base === '' ? $label : $base . $separator . $label;
    }
}
