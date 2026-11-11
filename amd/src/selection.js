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
 * Set of selected categories and courses (pure: no DOM, no Moodle modules).
 *
 * @module     tool_ceaorganizador/selection
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const keyOf = (type, id) => `${type}:${id}`;

/**
 * Ids of the categories among the items of a selection, in their order.
 *
 * @param {Object[]} items Items {type, id}, as selection.items() gives them.
 * @returns {Number[]}
 */
export const selectedCategoryIds = (items) => items.filter((item) => item.type === 'category').map((item) => item.id);

/**
 * Loads every page of get_courses for one category (0 for the whole site) and one search.
 *
 * @param {Object} api Object with getCourses(categoryid, search, page); pages are zero-based.
 * @param {Number} categoryid
 * @param {String} [search]
 * @param {Function} [onPage] Called after each page with (loaded, total).
 * @returns {Promise<{courses: Object[], total: Number}>} The rows as get_courses returns them.
 */
export const loadAllPages = async(api, categoryid, search = '', onPage = null) => {
    const courses = [];
    let page = 0;
    let total = 1;
    while (courses.length < total) {
        const result = await api.getCourses(categoryid, search, page);
        total = Number(result.total) || 0;
        page += 1;
        result.courses.forEach((course) => courses.push(course));
        if (onPage) {
            onPage(courses.length, total);
        }
        if (result.courses.length === 0) {
            break;
        }
    }
    return {courses, total};
};

/**
 * Loads every course of a category and of the categories below it, page after page.
 *
 * Categories whose model says they hold no course are not requested; a category
 * without a course count in the model is requested anyway.
 *
 * @param {Object} api Object with getCourses(categoryid, search, page); pages are zero-based.
 * @param {Object} model Model from treemodel.createModel().
 * @param {Number} categoryid
 * @returns {Promise<Object[]>} [{id, category}]
 */
export const loadSubtreeCourses = async(api, model, categoryid) => {
    const ids = [];
    const walk = (id) => {
        ids.push(id);
        model.children(id).forEach((child) => walk(child.id));
    };
    walk(categoryid);
    const courses = [];
    for (const id of ids) {
        const row = model.get(id);
        const known = row && row.coursecount !== undefined && row.coursecount !== null && row.coursecount !== '';
        if (known && Number(row.coursecount) === 0) {
            continue;
        }
        const result = await loadAllPages(api, id, '');
        result.courses.forEach((course) => courses.push({id: course.id, category: id}));
    }
    return courses;
};

/**
 * Wraps an async task so that only one run happens at a time; a call while it runs is ignored.
 *
 * @param {Function} task Returns a promise (or a value).
 * @returns {Function} Same arguments as the task; returns its promise, or null when ignored.
 *                     The wrapper has running() to ask whether a run is in progress.
 */
export const exclusive = (task) => {
    let current = null;
    const run = (...args) => {
        if (current) {
            return null;
        }
        current = Promise.resolve()
            .then(() => task(...args))
            .finally(() => {
                current = null;
            });
        return current;
    };
    run.running = () => current !== null;
    return run;
};

/**
 * Creates an empty selection.
 *
 * @returns {Object} toggle, has, addCategoryContents, addSearchResults, items, count, clear and onChange.
 */
export const createSelection = () => {
    // Insertion order is kept, so items() is stable.
    const chosen = new Map();
    const listeners = [];

    const notify = () => listeners.forEach((listener) => listener());

    const add = (type, id) => {
        const key = keyOf(type, id);
        if (chosen.has(key)) {
            return false;
        }
        chosen.set(key, {type, id});
        return true;
    };

    const toggle = (type, id) => {
        const key = keyOf(type, id);
        if (chosen.has(key)) {
            chosen.delete(key);
        } else {
            add(type, id);
        }
        notify();
    };

    const has = (type, id) => chosen.has(keyOf(type, id));

    /**
     * Adds a category, every category below it and the courses already loaded in them.
     *
     * @param {Object} model Model from treemodel.createModel().
     * @param {Number} categoryid
     * @param {Array} [courses] Loaded courses: objects {id, category} or plain ids. An object with a
     *                          category outside the subtree is ignored.
     */
    const addCategoryContents = (model, categoryid, courses = []) => {
        const inside = new Set();
        const walk = (id) => {
            inside.add(id);
            add('category', id);
            model.children(id).forEach((child) => walk(child.id));
        };
        walk(categoryid);
        courses.forEach((course) => {
            const id = typeof course === 'object' ? course.id : course;
            if (typeof course === 'object' && course.category !== undefined && !inside.has(course.category)) {
                return;
            }
            add('course', id);
        });
        notify();
    };

    /**
     * Adds the courses of a search.
     *
     * @param {Object[]} rows Courses with an id.
     */
    const addSearchResults = (rows) => {
        rows.forEach((row) => add('course', row.id));
        notify();
    };

    const clear = () => {
        chosen.clear();
        notify();
    };

    return {
        toggle,
        has,
        addCategoryContents,
        addSearchResults,
        items: () => [...chosen.values()].map((item) => ({...item})),
        count: () => chosen.size,
        clear,
        onChange: (listener) => listeners.push(listener),
    };
};
