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
 * Text helpers (pure: they only need a DOMParser, which browsers and jsdom provide).
 *
 * Use decodeEntities() ONLY for FORMATTED strings (format_string output, such as
 * the category and course names and paths of get_tree and get_courses, or the
 * history summaries). Never decode the RAW names that rename_* send and receive:
 * they are plain text and decoding would change them. The result must only be
 * assigned with textContent, never inserted as HTML.
 *
 * @module     tool_ceaorganizador/text
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Turns the HTML entities of a formatted string into characters.
 *
 * @param {String} text
 * @returns {String}
 */
export const decodeEntities = (text) => {
    const value = text === null || text === undefined ? '' : String(text);
    if (value.indexOf('&') === -1) {
        return value;
    }
    return new DOMParser().parseFromString(value, 'text/html').documentElement.textContent;
};
