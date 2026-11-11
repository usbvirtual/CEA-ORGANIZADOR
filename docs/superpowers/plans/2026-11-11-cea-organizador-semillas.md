# CEA-ORGANIZADOR · Sugerir correcciones, Unificar semillas y ajuste del clonado · Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox syntax.

**Goal:** add «Sugerir correcciones» and «Unificar semillas», and make «Clonar a periodo» start the mirror path below the seed root with an optional container folder.

**Architecture:**
- Pure rule engine: `classes/local/suggester.php`.
- Merge plan and runner: `classes/local/mergeplan.php` and `classes/local/mergerunner.php`, with the journal action `merge`.
- New services: `suggest_preview`, `merge_preview`, `merge_apply` and `create_seedroot`. Applying suggestions reuses `rename_apply`.
- The cloner mirror loses the seed root, and a `container` parameter is added to the clone services.
- New JS panels: `suggestpanel` and `mergepanel`. The clone panel gets a «Carpeta bajo el periodo» field.

**Spec:** `docs/superpowers/specs/2026-11-11-cea-organizador-semillas-design.md`

## Global Constraints

- **Plugin rules unchanged:**
  - Moodle 4.5+ (`requires = 2024100700`), `supported = [405, 503]`, PHP 8.1–8.4.
  - `\core_external` services.
  - Lock, one transaction and `guard::rollback` on every write.
  - Raw names stored and compared; formatted names decoded in JS and shown with `textContent`. No `innerHTML`.
  - Bootstrap 4+5 dual classes.
  - es/en lang files, alphabetical, with literal JS keys.
  - LF line endings.
  - moodle-cs 0/0.
- **Test rules:**
  - `final` classes, with `#[CoversClass]` before the docblock and `@covers` inside it.
  - `$this->setAdminUser()` in every test that reads categories or courses.
  - Generator category helpers typed `\core_course_category`.
  - Fully-qualified exception names.
  - `preventResetByRollback` in rollback tests.
  - No helper named like a TestCase method.
  - `core_course_category::get(…, MUST_EXIST, true)`.
- **Limits:**
  - suggestions: 2000 rows;
  - suggestions apply through `rename_apply`: 500 per batch;
  - merge: 1000 courses.
- `version.php`: bump the version on every task that adds strings, settings, services or db changes.

## Review Focus

1. **A `tilde` suggestion that only appears once in the site must not be proposed.** Test: `suggester_test::test_accent_needs_two_occurrences` (Task 1).
2. **Merging when source and target contain the same category name with different accents creates no duplicate category.** Test: `mergeplan_test::test_accent_insensitive_match` (Task 3).
3. **Undoing a merge must not delete a category that received other content afterwards.** Test: `mergerunner_test::test_undo_keeps_nonempty_created_category` (Task 4).
4. **A clone with `container` must not duplicate the container folder if it already exists with different accents.** Test: `cloneplan_test::test_container_existing_accent_insensitive` (Task 6).
5. **The merge target inside a source is rejected.** Test: `mergeplan_test::test_target_inside_source_rejected` (Task 3).

---

### Task 1: Suggestion engine (`suggester`, pure)

**Files:**
- Create: `classes/local/suggester.php`
- Test: `tests/local/suggester_test.php`
- Modify: `settings.php` (textarea `glossary`), `lang/*`, `version.php`

**Produces:**
- `suggester::vocabulary(string[] $names): array`: map from normalized word to `[form => count]`.
- `suggester::parse_glossary(string $text): array`: list of `[from, to]`; invalid lines are ignored.
- `suggester::suggest(string $name, array $vocabulary, array $glossary, array $siblingforms = []): array`: returns `['new' => string, 'reasons' => string[]]`.
  - Chain order: formato → glosario → tilde → variante.
  - `siblingforms` maps a normalized word to its preferred form among siblings.
  - Formato is `renamer::apply($name, [['type' => 'clean', 'case' => 'none']])`.
  - Tilde:
    - applies per word only when the word has no accent;
    - the accented candidate must be the most frequent form, with a count of at least 2;
    - the original capitalization is kept (first letter, all upper).
- `suggester::sibling_forms(string[] $siblingnames, array $vocabulary): array`: for words that differ only by a plural (`s`/`es`, `ción`/`ciones`), the preferred form is the most frequent in the vocabulary; on a tie, the plural wins.

**Tests:**
- `test_format`
- `test_accent_from_site`
- `test_accent_needs_two_occurrences`
- `test_accent_keeps_case`
- `test_glossary_whole_word_case_insensitive`
- `test_sibling_plural_variant`
- `test_chain_reasons`
- `test_no_change_returns_same`

Settings and strings:
- `glossary`, `glossary_desc`
- reasons: `reason_formato`, `reason_tilde`, `reason_variante`, `reason_glosario`

### Task 2: Suggestion service and panel

**Files:**
- Create: `classes/external/suggest_preview.php`, `amd/src/suggestpanel.js`, `tests/external/suggest_external_test.php`, `tests/js/suggestpanel.test.mjs`
- Modify: `db/services.php`, `api.js`, `organizer.js` (top-bar button and category menu entry), `treeview.js` (menu hook), `styles.css`, `lang/*`, `version.php`

**Service:** `suggest_preview(categoryid, includecourses)` (read).
- Builds the vocabulary from ALL category names and course fullnames on the site, using one query each.
- Takes the scope (a subtree, or the whole site when 0) and computes `sibling_forms` per parent.
- Returns rows with `{type, id, old (raw), new (raw), reasons[], group (formatted path)}`, capped at 2000 and ordered by group, then old name.
- Checks the system context and the plugin's manage capability.

**JS panel:**
- Rows are grouped, with a checkbox (checked by default), an editable `new` field (raw value) and reason chips.
- «Aplicar seleccionadas (N)» calls `api.renameApply` with `changes` built from `{type, id, old, new}`.
- Batches of 500 show a toast with Deshacer.
- Includes «Marcar/desmarcar todo».
- Raw names are never decoded; `group` is decoded.

**Tests:**
- PHPUnit: `clean_returnvalue`, the scope, `includecourses = false`, and the capability check.
- JS: unchecked rows are not sent, edits are sent, and `<b>` is rendered as text.

### Task 3: Merge plan (`mergeplan`)

**Files:**
- Create: `classes/local/mergeplan.php`
- Test: `tests/local/mergeplan_test.php`

**Produces:** `mergeplan::build(int $targetid, int[] $sourceids): array` → `['rows' => [{courseid, fullname, from, to, toid (0 if new), newcategories[], duplicate, action}], 'emptied' => [{id, path}], 'hash']`.
- For each course under each source, at any depth, compute `relative` = the names from the source's child down to the course's category, and resolve it with `cloner::resolve($cats, $targetid, $relative)`.
- `duplicate` is true when `toid > 0` and a course with the same `normalize(fullname)` exists in `toid`. Duplicate rows default to `action = 'skip'`; all other rows default to `'move'`.
- `emptied` lists the source categories, the source roots included, that would hold no courses and no remaining subcategories after the default actions.
- The hash is the sha1 of `targetid`, the sorted `sourceids`, and per row `[courseid, toid, to, duplicate]`.
- Validation:
  - the target and the sources must exist;
  - no source equals the target, contains it, or is contained in it;
  - at most 1000 courses (`error_toomanymerge`).

**Tests:**
- `test_mirror_paths_and_new_categories`
- `test_accent_insensitive_match`
- `test_duplicate_detection_default_skip`
- `test_target_inside_source_rejected`
- `test_source_inside_target_rejected`
- `test_emptied_list`
- `test_hash_stable`

### Task 4: Merge runner, journal and undo

**Files:**
- Create: `classes/local/mergerunner.php`
- Modify: `classes/local/journal.php` (action `merge`: record, conflicts, restore), `classes/external/undo.php` (native checks for `merge`)
- Test: `tests/local/mergerunner_test.php`

**Produces:** `mergerunner::run(int $targetid, int[] $sourceids, array $rows, string $hash): array` → `{status, logid, moved, skipped, emptied}`.
- Takes the lock, then rebuilds the plan and checks the hash (returns `stale` on mismatch).
- Uses the rows' actions; plan rows not listed in `$rows` keep their default action.
- Permissions, checked before any write:
  - `category:manage` on the target, on each source, and on the anchor of each new category;
  - `course:changecategory` on each moved course.
- In one transaction:
  1. create the missing categories (cached by parent + normalized name);
  2. call `move_courses()`, grouped by destination;
  3. call `journal::record('merge', summary, {created, items: [{id, fromcategory, tocategory}]})`.
- Rolls back through `guard::rollback`.

**Undo:**
- Conflict when a course is no longer in `tocategory` or `fromcategory` no longer exists.
- Restore moves each course back, then deletes the created categories that are empty (no courses, no children), deepest first.

**Tests:**
- `test_run_moves_and_creates`
- `test_run_skips_duplicates_by_default`
- `test_stale`
- `test_requires_capability`
- `test_undo_restores_and_deletes_created`
- `test_undo_keeps_nonempty_created_category`
- `test_undo_conflict`

### Task 5: Merge services and panel

**Files:**
- Create:
  - `classes/external/merge_preview.php`
  - `classes/external/merge_apply.php`
  - `classes/external/create_seedroot.php` (write: creates a top-level category named after the `name` param, default «Semillas»; requires `category:manage` in the system context; it is not journaled, because native deletion undoes it)
  - `amd/src/mergepanel.js`
  - `tests/external/merge_external_test.php`
  - `tests/js/mergepanel.test.mjs`
- Modify: `db/services.php`, `api.js`, `organizer.js` (top-bar button «Unificar semillas» and category menu entry with that category as source), `historypanel.js` (`merge` is undoable), `styles.css`, `lang/*`, `version.php`

**JS panel:**
- Target picker with a «Crear categoría de semillas» button, and source pickers that exclude the target and its subtree.
- Preview table: course / from / to, with the «Nueva» tag (also in visually hidden text) and a «Repetido» toggle between move and skip.
- «Unificar (N)» button; `stale` reloads the preview.
- After apply: a toast with Deshacer, plus the list of emptied categories with links to `/course/management.php?categoryid=X`.

**Tests:**
- PHPUnit: `clean_returnvalue` and capability checks for the 3 services.
- JS: rows with `skip` are sent as skip, a duplicate toggle changes the count, the emptied list renders, and names render as text.

### Task 6: Clone mirror without the seed root, and `container`

**Files:**
- Modify:
  - `classes/local/cloner.php`: `mirror` returns `relative` WITHOUT the root
  - `classes/local/cloneplan.php`: new parameter `string $container = ''`; the path resolves under the period, then under the container when it is not empty
  - `classes/local/clonerunner.php`: creates the container when it is missing
  - `clone_preview.php` and `clone_apply.php`: `container` param, `PARAM_TEXT`, `VALUE_DEFAULT ''`, included in the hash
  - `amd/src/clonepanel.js`: field «Carpeta bajo el periodo», whose default is the name of the period's single child that contains `facultad` categories, else empty
  - the affected tests, `lang/*`, `version.php`, `INSTALACION.md`

**Tests:**
- Update the existing expectations: mirror paths no longer include «Facultades».
- `test_container_creates_folder`
- `test_container_existing_accent_insensitive`
- `test_no_container_hangs_from_period`
- JS: the container is sent and the default is detected.

### Task 7: Docs

Update `INSTALACION.md` and `README.md`:
- the sections «Sugerir correcciones» (glossary syntax, what is never changed) and «Unificar semillas» (recommended order: suggest first, then unify, then clone; duplicates are never deleted);
- the clone container.

Update `CLAUDE.md` with the design decisions.

Verify:
- run `npm test`, check-strings, moodle-cs and package.
