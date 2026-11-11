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
 * Web service client of the organiser: one method per function.
 *
 * @module     tool_ceaorganizador/api
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

const PREFIX = 'tool_ceaorganizador_';

/**
 * Default transport: core/ajax.
 *
 * @param {String} methodname Web service function.
 * @param {Object} args Its parameters.
 * @returns {Promise}
 */
const ajaxTransport = (methodname, args) => Ajax.call([{methodname, args}])[0];

let transport = ajaxTransport;

/**
 * Replaces the transport (tests); called with no argument it restores core/ajax.
 *
 * @param {Function} [fn] Function (methodname, args) returning a promise.
 */
export const setTransport = (fn) => {
    transport = fn || ajaxTransport;
};

const call = (name, args = {}) => transport(PREFIX + name, args);

export const getTree = () => call('get_tree');

export const getCourses = (categoryid, search, page) => call('get_courses', {categoryid, search, page});

export const moveCategory = (id, newparent, afterid) => call('move_category', {id, newparent, afterid});

export const moveCourse = (id, categoryid, afterid) => call('move_course', {id, categoryid, afterid});

/**
 * @param {Object} params Plan parameters of sort_preview, as the web service defines them; categoryids
 *                        (with categoryid 0) sorts several categories at once.
 * @returns {Promise}
 */
export const sortPreview = (params) => call('sort_preview', params);

/**
 * @param {Object} params Plan parameters of sort_apply plus the hash of the preview; categoryids as in sortPreview.
 * @returns {Promise}
 */
export const sortApply = (params) => call('sort_apply', params);

/**
 * @param {Object} params {items, ops, manual, includeshortname}.
 * @returns {Promise}
 */
export const renamePreview = (params) => call('rename_preview', params);

/**
 * @param {Object} params {changes}.
 * @returns {Promise}
 */
export const renameApply = (params) => call('rename_apply', params);

/**
 * @param {Object} params {categoryid (0 is the whole site), includecourses}.
 * @returns {Promise} {rows, total, truncated}.
 */
export const suggestPreview = (params) => call('suggest_preview', params);

export const undo = (logid) => call('undo', {logid});

export const history = (page) => call('history', {page});

/**
 * @param {Object} params {items, periodid, periodlabel, startdate, enddate, container}.
 * @returns {Promise}
 */
export const clonePreview = (params) => call('clone_preview', params);

/**
 * @param {Object} params Plan parameters of clone_preview plus {rows, hash}.
 * @returns {Promise}
 */
export const cloneApply = (params) => call('clone_apply', params);

/**
 * @param {Object} params {targetid, sourceids}.
 * @returns {Promise} {rows, emptied, hash}.
 */
export const mergePreview = (params) => call('merge_preview', params);

/**
 * @param {Object} params {targetid, sourceids, rows: [{courseid, action: move|repeated|skip}], hash}.
 * @returns {Promise} {status, logid, moved, repeated, skipped, emptied}.
 */
export const mergeApply = (params) => call('merge_apply', params);

/**
 * @param {Object} params {name}.
 * @returns {Promise} {id, name, created}.
 */
export const createSeedroot = (params) => call('create_seedroot', params);

export const cloneStatus = (batchid) => call('clone_status', {batchid});

/**
 * @param {Number} categoryid Category analysed, with all its subcategories.
 * @returns {Promise} {groups: [{categoryid, path, key, courses}], folder: {id, path}, hash}.
 */
export const dedupePreview = (categoryid) => call('dedupe_preview', {categoryid});

/**
 * @param {Object} params {categoryid, groups: [{key, keep}], hash}; a group left out is not touched.
 * @returns {Promise} {status, logid, moved}.
 */
export const dedupeApply = (params) => call('dedupe_apply', params);

/**
 * @param {Number[]} courseids Courses only.
 * @param {Boolean} visible True shows them, false hides them.
 * @returns {Promise} {changed, skipped, logid}.
 */
export const setVisibility = (courseids, visible) => call('set_visibility', {courseids, visible});

/**
 * @param {Number[]} ids Categories only.
 * @returns {Promise} {delete: [{id, path}], skipped: [{id, name, path, reason}]}.
 */
export const deletePreview = (ids) => call('delete_preview', {ids});

/**
 * @param {Number[]} ids Categories only.
 * @returns {Promise} {status, logid, deleted, skipped: [{id, name, reason}]}.
 */
export const deleteApply = (ids) => call('delete_apply', {ids});
