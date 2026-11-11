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
 * Academic periods in category names (pure: no DOM, no Moodle modules). The expression
 * mirrors sorter::parse_period of the server.
 *
 * @module     tool_ceaorganizador/period
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {normalize} from 'tool_ceaorganizador/treemodel';

const PERIOD = /(?<!\d)(20\d\d)\s*[-–_/ ]\s*(0?[12]|ii|i)(?![\p{L}\d])/iu;
// The same labels the server accepts (cloner::period_label): year 20xx, term 01 or 02.
const LABEL = /^20\d\d-0[12]$/;

/**
 * Year and term of a name, or null when it has no period.
 *
 * @param {String} name Plain (decoded) name.
 * @returns {Number[]|null} [year, term]
 */
export const parsePeriod = (name) => {
    const found = PERIOD.exec(String(name));
    if (!found) {
        return null;
    }
    const term = /^\d+$/.test(found[2]) ? Number(found[2]) : found[2].length;
    return [Number(found[1]), term];
};

/**
 * Suggested label YYYY-PP of a name, or an empty string.
 *
 * @param {String} name Plain name.
 * @returns {String}
 */
export const suggestLabel = (name) => {
    const period = parsePeriod(name);
    return period ? `${period[0]}-${String(period[1]).padStart(2, '0')}` : '';
};

/**
 * Whether a label has the form 20YY-01 or 20YY-02.
 *
 * @param {String} label
 * @returns {Boolean}
 */
export const isValidLabel = (label) => LABEL.test(String(label));

/**
 * Categories whose name has a period, at any level, the most recent first.
 *
 * @param {Object[]} rows Rows with id, name (formatted) and path (names joined, formatted), as treemodel snapshot gives.
 * @param {Function} decode Turns a formatted string into plain text.
 * @returns {Object[]} [{id, text, period, label}] with the decoded path as text.
 */
export const periodOptions = (rows, decode) => rows
    .map((row) => ({id: row.id, text: decode(row.path), period: parsePeriod(decode(row.name)), label: suggestLabel(decode(row.name))}))
    .filter((option) => option.period !== null)
    .sort((a, b) => (b.period[0] - a.period[0]) || (b.period[1] - a.period[1]) || a.text.localeCompare(b.text));

/**
 * Suggested folder under a period: the name of its only direct child that holds, below it, a category whose
 * name starts with «facultad». Children whose name has a period are skipped. Empty when no child or more than one
 * child does.
 *
 * @param {Object[]} rows Rows with id, parent and name (formatted), as treemodel snapshot gives.
 * @param {Number} periodid
 * @param {Function} decode Turns a formatted string into plain text.
 * @returns {String} Decoded name, or ''.
 */
export const suggestContainer = (rows, periodid, decode) => {
    const kids = new Map();
    rows.forEach((row) => {
        if (!kids.has(row.parent)) {
            kids.set(row.parent, []);
        }
        kids.get(row.parent).push(row);
    });
    const holdsFaculty = (id) => {
        const stack = [...(kids.get(id) || [])];
        const seen = new Set();
        while (stack.length) {
            const row = stack.pop();
            if (seen.has(row.id)) {
                continue;
            }
            seen.add(row.id);
            if (normalize(decode(row.name)).trim().startsWith('facultad')) {
                return true;
            }
            stack.push(...(kids.get(row.id) || []));
        }
        return false;
    };
    // A name with a period is not a valid folder: the server rejects it.
    const found = (kids.get(periodid) || []).filter(
        (child) => parsePeriod(decode(child.name)) === null && holdsFaculty(child.id)
    );
    return found.length === 1 ? decode(found[0].name).trim() : '';
};

/**
 * Unix seconds at local midnight of a YYYY-MM-DD value (input type date), or null.
 *
 * @param {String} value
 * @returns {Number|null}
 */
export const dateToTimestamp = (value) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value));
    if (!match) {
        return null;
    }
    return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])).getTime() / 1000;
};
