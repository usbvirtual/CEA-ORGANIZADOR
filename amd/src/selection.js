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
 * Loads every course of a category and of the categories below it, page after page.
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
        let page = 0;
        let loaded = 0;
        let total = 1;
        while (loaded < total) {
            const result = await api.getCourses(id, '', page);
            total = result.total;
            loaded += result.courses.length;
            page += 1;
            result.courses.forEach((course) => courses.push({id: course.id, category: id}));
            if (result.courses.length === 0) {
                break;
            }
        }
    }
    return courses;
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
