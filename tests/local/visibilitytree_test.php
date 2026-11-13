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

namespace tool_ceaorganizador\local;

// Cobertura declarada dos veces: el atributo para PHPUnit 11 (Moodle 5.x) y la
// anotación @covers para PHPUnit 9.6 (Moodle 4.x) y el verificador de código.
#[\PHPUnit\Framework\Attributes\CoversClass(visibilitytree::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(journal::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(mover::class)]
/**
 * Tests for «Ocultar todo» and «Mostrar todo», and their undo.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\visibilitytree
 * @covers     \tool_ceaorganizador\local\journal
 * @covers     \tool_ceaorganizador\local\mover
 */
final class visibilitytree_test extends \advanced_testcase {
    /**
     * A tree with a subcategory hidden by the core «Hide» and a course hidden on purpose.
     *
     * R (visible): courses cr1 (visible) and cr2 (hidden on purpose); S1 (visible) with cs1, and S11 under it with
     * cs11; S2 hidden with the core hide(), so its course cs2 is hidden with visibleold 1. O, outside, with co.
     *
     * @return int[] Ids by name.
     */
    private function fixture(): array {
        $generator = $this->getDataGenerator();
        $ids = [];
        $ids['r'] = (int) $generator->create_category(['name' => 'Raíz'])->id;
        $ids['s1'] = (int) $generator->create_category(['name' => 'Sub uno', 'parent' => $ids['r']])->id;
        $ids['s11'] = (int) $generator->create_category(['name' => 'Sub once', 'parent' => $ids['s1']])->id;
        $ids['s2'] = (int) $generator->create_category(['name' => 'Sub dos', 'parent' => $ids['r']])->id;
        $ids['o'] = (int) $generator->create_category(['name' => 'Otra'])->id;
        $courses = ['cr1' => 'r', 'cr2' => 'r', 'cs1' => 's1', 'cs11' => 's11', 'cs2' => 's2', 'co' => 'o'];
        foreach ($courses as $name => $category) {
            $ids[$name] = (int) $generator->create_course([
                'fullname' => 'Curso ' . $name,
                'shortname' => 'VT-' . $name,
                'category' => $ids[$category],
            ])->id;
        }
        mover::set_course_visibility($ids['cr2'], false);
        \core_course_category::get($ids['s2'], MUST_EXIST, true)->hide();
        return $ids;
    }

    /**
     * Visibility of every category and every course, as "visible/visibleold" by id.
     *
     * @return array ['categories' => string[], 'courses' => string[]]
     */
    private function snapshot(): array {
        global $DB;
        $result = [];
        foreach (['categories' => 'course_categories', 'courses' => 'course'] as $key => $table) {
            $result[$key] = [];
            foreach ($DB->get_records($table, null, 'id', 'id, visible, visibleold') as $record) {
                $result[$key][(int) $record->id] = (int) $record->visible . '/' . (int) $record->visibleold;
            }
        }
        return $result;
    }

    /**
     * Visible and visibleold of one item.
     *
     * @param string $table
     * @param int $id
     * @return string "visible/visibleold"
     */
    private function state(string $table, int $id): string {
        global $DB;
        $record = $DB->get_record($table, ['id' => $id], 'visible, visibleold', MUST_EXIST);
        return (int) $record->visible . '/' . (int) $record->visibleold;
    }

    /**
     * Payload of a journal row.
     *
     * @param int $logid
     * @return array
     */
    private function payload(int $logid): array {
        global $DB;
        return json_decode($DB->get_field(journal::TABLE, 'payload', ['id' => $logid], MUST_EXIST), true);
    }

    /**
     * The real limits and chunk size.
     */
    public function test_limits(): void {
        $this->assertSame(2000, visibilitytree::MAX_COURSES);
        $this->assertSame(500, visibilitytree::MAX_CATEGORIES);
        $this->assertSame(200, visibilitytree::CHUNK);
    }

    /**
     * The preview counts only what would change; a chosen category inside another chosen one adds nothing.
     */
    public function test_plan_counts_only_changes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $ids = $this->fixture();
        $before = $this->snapshot();

        $hide = visibilitytree::plan([$ids['r']], false);
        $this->assertSame([$ids['r']], $hide['roots']);
        $this->assertEqualsCanonicalizing([$ids['r'], $ids['s1'], $ids['s11']], array_keys($hide['categories']));
        $this->assertEqualsCanonicalizing([$ids['cr1'], $ids['cs1'], $ids['cs11']], array_keys($hide['courses']));

        $show = visibilitytree::plan([$ids['r']], true);
        $this->assertEqualsCanonicalizing([$ids['s2']], array_keys($show['categories']));
        $this->assertEqualsCanonicalizing([$ids['cr2'], $ids['cs2']], array_keys($show['courses']));

        $nested = visibilitytree::plan([$ids['s1'], $ids['r'], -5, 999999], false);
        $this->assertSame([$ids['r']], $nested['roots']);
        $this->assertSame($hide['hash'], $nested['hash']);
        $this->assertNotSame($hide['hash'], $show['hash']);
        $this->assertSame([], visibilitytree::plan([], false)['categories']);
        // A preview writes nothing.
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * «Ocultar todo» hides the category, every subcategory and every course below it; what was hidden stays as it was;
     * undo gives every item its exact state back, visibleold included.
     */
    public function test_hide_all_and_undo(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $ids = $this->fixture();
        $before = $this->snapshot();
        $plan = visibilitytree::plan([$ids['r']], false);

        $sink = $this->redirectEvents();
        $result = visibilitytree::run([$ids['r']], false, $plan['hash']);
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(3, $result['categories']);
        $this->assertSame(3, $result['courses']);
        // The chosen category is hidden on purpose (visibleold 0), as the core hide() does; the rest keeps 1.
        $this->assertSame('0/0', $this->state('course_categories', $ids['r']));
        $this->assertSame('0/1', $this->state('course_categories', $ids['s1']));
        $this->assertSame('0/1', $this->state('course_categories', $ids['s11']));
        foreach (['cr1', 'cs1', 'cs11'] as $name) {
            $this->assertSame('0/1', $this->state('course', $ids[$name]));
        }
        // Already hidden: untouched.
        $this->assertSame($before['categories'][$ids['s2']], $this->state('course_categories', $ids['s2']));
        $this->assertSame($before['courses'][$ids['cr2']], $this->state('course', $ids['cr2']));
        $this->assertSame($before['courses'][$ids['cs2']], $this->state('course', $ids['cs2']));
        $this->assertSame($before['categories'][$ids['o']], $this->state('course_categories', $ids['o']));
        $this->assertSame($before['courses'][$ids['co']], $this->state('course', $ids['co']));
        // The core API reads the new state (the caches were purged).
        $this->assertFalse((bool) \core_course_category::get($ids['s1'], MUST_EXIST, true)->visible);

        $updated = array_filter($events, fn($event) => $event instanceof \core\event\course_category_updated);
        $this->assertEqualsCanonicalizing(
            [$ids['r'], $ids['s1'], $ids['s11']],
            array_map(fn($event) => (int) $event->objectid, array_values($updated))
        );

        $payload = $this->payload($result['logid']);
        $this->assertSame('visibilitytree', $DB->get_field(journal::TABLE, 'action', ['id' => $result['logid']]));
        $this->assertSame([$ids['r']], $payload['categoryids']);
        $this->assertSame(0, $payload['show']);
        foreach (['categories', 'courses'] as $kind) {
            foreach ($payload[$kind] as $item) {
                $this->assertSame($before[$kind][$item['id']], $item['visible'] . '/' . $item['visibleold']);
            }
        }
        $this->assertCount(3, $payload['categories']);
        $this->assertCount(3, $payload['courses']);

        $undo = journal::undo($result['logid']);

        $this->assertTrue($undo['ok']);
        $this->assertSame($before, $this->snapshot());
        $this->assertTrue((bool) \core_course_category::get($ids['s1'], MUST_EXIST, true)->visible);
    }

    /**
     * «Mostrar todo» shows everything, the subcategory hidden by the core «Hide» and the course hidden on purpose too;
     * undoing it puts back exactly the hidden state, and then undoing the hiding the original one.
     */
    public function test_show_all_and_undo(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $ids = $this->fixture();
        $original = $this->snapshot();
        $hide = visibilitytree::run([$ids['r']], false, visibilitytree::plan([$ids['r']], false)['hash']);
        $hidden = $this->snapshot();

        $plan = visibilitytree::plan([$ids['r']], true);
        $result = visibilitytree::run([$ids['r']], true, $plan['hash']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(4, $result['categories']);
        $this->assertSame(5, $result['courses']);
        foreach (['r', 's1', 's11', 's2'] as $name) {
            $this->assertSame('1/1', $this->state('course_categories', $ids[$name]));
        }
        foreach (['cr1', 'cr2', 'cs1', 'cs11', 'cs2'] as $name) {
            $this->assertSame('1/1', $this->state('course', $ids[$name]));
        }
        $this->assertSame($original['courses'][$ids['co']], $this->state('course', $ids['co']));
        // Nothing left to show.
        $this->assertSame([], visibilitytree::plan([$ids['r']], true)['courses']);

        $this->assertTrue(journal::undo($result['logid'])['ok']);
        $this->assertSame($hidden, $this->snapshot());
        $this->assertTrue(journal::undo($hide['logid'])['ok']);
        $this->assertSame($original, $this->snapshot());
    }

    /**
     * An item whose visibility changed after the operation blocks the whole undo, and nothing is written.
     */
    public function test_undo_conflict(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $ids = $this->fixture();
        $result = visibilitytree::run([$ids['r']], false, visibilitytree::plan([$ids['r']], false)['hash']);
        mover::set_course_visibility($ids['cs1'], true);
        $after = $this->snapshot();

        $undo = journal::undo($result['logid']);

        $this->assertFalse($undo['ok']);
        $this->assertCount(1, $undo['conflicts']);
        $this->assertStringContainsString('Curso cs1', $undo['conflicts'][0]);
        $this->assertSame($after, $this->snapshot());
    }

    /**
     * A preview that no longer matches is refused as stale, before any write.
     */
    public function test_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $ids = $this->fixture();
        $plan = visibilitytree::plan([$ids['r']], false);
        mover::set_course_visibility($ids['cs1'], false);
        $before = $this->snapshot();

        $result = visibilitytree::run([$ids['r']], false, $plan['hash']);

        $this->assertSame(['status' => 'stale', 'logid' => 0, 'categories' => 0, 'courses' => 0], $result);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }

    /**
     * Beyond the limits the preview and the apply are refused with the counts, before any write.
     */
    public function test_caps(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $ids = $this->fixture();
        $before = $this->snapshot();
        $courses = new class extends visibilitytree {
            /** @var int Most courses changed in one operation. */
            public const MAX_COURSES = 2;
        };
        $categories = new class extends visibilitytree {
            /** @var int Most categories changed in one operation. */
            public const MAX_CATEGORIES = 2;
        };

        foreach ([$courses, $categories] as $limited) {
            try {
                $limited::plan([$ids['r']], false);
                $this->fail('Over the limit must be refused');
            } catch (\moodle_exception $e) {
                $this->assertSame('error_toomanyvisibilitytree', $e->errorcode);
                $this->assertSame(3, $e->a->categories);
                $this->assertSame(3, $e->a->courses);
            }
            try {
                $limited::run([$ids['r']], false, 'whatever');
                $this->fail('Over the limit must be refused');
            } catch (\moodle_exception $e) {
                $this->assertSame('error_toomanyvisibilitytree', $e->errorcode);
            }
        }
        // Exactly at the limit it is accepted.
        $exact = new class extends visibilitytree {
            /** @var int Most courses changed in one operation. */
            public const MAX_COURSES = 3;
            /** @var int Most categories changed in one operation. */
            public const MAX_CATEGORIES = 3;
        };
        $this->assertCount(3, $exact::plan([$ids['r']], false)['courses']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }

    /**
     * Without moodle/course:visibility on one course, or moodle/category:manage on one category, nothing changes.
     */
    public function test_capabilities_checked_before_any_write(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $ids = $this->fixture();
        $before = $this->snapshot();
        $generator = $this->getDataGenerator();
        $system = \context_system::instance();
        $cases = [
            'moodle/course:visibility' => ['moodle/category:manage'],
            'moodle/category:manage' => ['moodle/course:visibility'],
        ];
        foreach ($cases as $missing => $granted) {
            $user = $generator->create_user();
            $roleid = $generator->create_role();
            foreach ($granted as $cap) {
                assign_capability($cap, CAP_ALLOW, $roleid, $system->id, true);
            }
            role_assign($roleid, $user->id, $system->id);
            $this->setUser($user);
            $plan = visibilitytree::plan([$ids['r']], false);
            try {
                visibilitytree::run([$ids['r']], false, $plan['hash']);
                $this->fail('A missing capability must refuse everything');
            } catch (\required_capability_exception $e) {
                $this->assertSame(get_capability_string($missing), $e->a);
            }
            $this->assertSame($before, $this->snapshot());
            $this->assertSame(0, $DB->count_records(journal::TABLE));
        }
    }

    /**
     * A failure while recording the row rolls everything back: no change and no row.
     */
    public function test_record_failure_rolls_back(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $ids = $this->fixture();
        $before = $this->snapshot();
        $runner = new class extends visibilitytree {
            /**
             * Fails instead of recording.
             *
             * @param array $payload
             * @return int
             */
            protected static function record_batch(array $payload): int {
                throw new \coding_exception('Simulated failure');
            }
        };

        try {
            $runner::run([$ids['r']], false, $runner::plan([$ids['r']], false)['hash']);
            $this->fail('The failure must reach the caller');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Simulated failure', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }

    /**
     * A failing chunk rolls back only itself: the chunks before stay applied and recorded, and the undo restores them.
     */
    public function test_chunk_failure_keeps_what_was_recorded(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $ids = $this->fixture();
        $before = $this->snapshot();
        // Chunks of two: [R, S1] and [S11] for the categories, then the courses. The second chunk fails.
        $runner = new class extends visibilitytree {
            /** @var int Most items written in one transaction. */
            public const CHUNK = 2;

            /** @var int Chunks recorded so far. */
            private static $chunks = 0;

            /**
             * Fails on the second chunk.
             *
             * @param int $logid
             * @param array $payload
             */
            protected static function update_batch(int $logid, array $payload): void {
                self::$chunks++;
                if (self::$chunks === 2) {
                    throw new \coding_exception('Simulated failure');
                }
                parent::update_batch($logid, $payload);
            }
        };

        $logid = 0;
        try {
            $runner::run([$ids['r']], false, $runner::plan([$ids['r']], false)['hash']);
            $this->fail('The failure must reach the caller');
        } catch (partial_exception $e) {
            $this->assertSame('error_partialbatch', $e->errorcode);
            $this->assertSame(2, $e->moved);
            $this->assertSame(6, $e->total);
            $this->assertStringContainsString('Simulated failure', (string) $e->debuginfo);
            $logid = $e->logid;
        }

        // The ids grow in creation order: R and S1 are the first chunk.
        $this->assertSame('0/0', $this->state('course_categories', $ids['r']));
        $this->assertSame('0/1', $this->state('course_categories', $ids['s1']));
        $this->assertSame($before['categories'][$ids['s11']], $this->state('course_categories', $ids['s11']));
        $this->assertSame($before['courses'], $this->snapshot()['courses']);
        $payload = $this->payload($logid);
        $this->assertEqualsCanonicalizing([$ids['r'], $ids['s1']], array_column($payload['categories'], 'id'));
        $this->assertSame([], $payload['courses']);

        $this->assertTrue(journal::undo($logid)['ok']);
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * The summary of the history names the chosen categories and the counts.
     */
    public function test_summary(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $ids = $this->fixture();
        $summary = visibilitytree::summary([
            'categoryids' => [$ids['r']],
            'show' => 0,
            'categories' => [[], []],
            'courses' => [[]],
        ]);
        $this->assertSame(
            get_string('summary_hidetree', 'tool_ceaorganizador', (object) ['list' => 'Raíz', 'categories' => 2, 'courses' => 1]),
            $summary
        );
    }
}
