<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_merqamreports\local;

use core\context;
use core_reportbuilder\local\filters\category as category_filter;
use core_reportbuilder\manager;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use local_merqamreports\admin\setting_companyroots;
use local_merqamreports\output\overview;
use local_merqamreports\reportbuilder\datasource\program_allocations;

/**
 * Tests for the Company overview numbers and access (docs/program-reports.md section 11).
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(overview_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(company_access::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(setting_companyroots::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(overview::class)]
final class overview_service_test extends core_reportbuilder_testcase {
    /** @var \core_course_category Company Alpha (configured) */
    private \core_course_category $alpha;
    /** @var \core_course_category Company Beta (configured) */
    private \core_course_category $beta;
    /** @var \core_course_category Public courses (top-level, not configured) */
    private \core_course_category $public;
    /** @var \core_course_category Sub-category of Alpha */
    private \core_course_category $alphasub;
    /** @var \stdClass[] Programs by key */
    private array $programs = [];
    /** @var int Role holding the report capability */
    private int $roleid;
    /** @var \stdClass Alpha manager */
    private \stdClass $alphamanager;
    /** @var \stdClass Beta manager */
    private \stdClass $betamanager;
    /** @var int The reference time of each test */
    private int $now;

    /**
     * Three top-level categories (two configured as companies), an Alpha sub-category, programs, managers.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        progress_service::reset_caches();
        $this->now = time();

        $gen = $this->getDataGenerator();
        /** @var \tool_muprog_generator $progen */
        $progen = $gen->get_plugin_generator('tool_muprog');

        $this->alpha = $gen->create_category(['name' => 'Company Alpha', 'idnumber' => 'ALPHA']);
        $this->beta = $gen->create_category(['name' => 'Company Beta', 'idnumber' => 'BETA']);
        $this->public = $gen->create_category(['name' => 'Public courses', 'idnumber' => 'FREE']);
        $this->alphasub = $gen->create_category([
            'name' => 'Alpha sub',
            'idnumber' => 'ALPHASUB',
            'parent' => (int)$this->alpha->id,
        ]);

        $where = [
            'pa' => $this->alpha,
            'pa2' => $this->alpha,
            'pb' => $this->beta,
            'ps' => $this->alphasub,
            'pf' => $this->public,
        ];
        foreach ($where as $key => $category) {
            $this->programs[$key] = $progen->create_program([
                'fullname' => 'Program ' . $key,
                'idnumber' => strtoupper($key),
                'contextid' => context\coursecat::instance($category->id)->id,
            ]);
        }
        $this->programs['px'] = $progen->create_program(['fullname' => 'Site program', 'idnumber' => 'PX']);

        set_config(overview_service::SETTING, (int)$this->alpha->id . ',' . (int)$this->beta->id, 'local_merqamreports');

        $this->roleid = $gen->create_role(['shortname' => 'companymanagertest']);
        assign_capability(company_access::CAPABILITY, CAP_ALLOW, $this->roleid, context\system::instance()->id);
        $this->alphamanager = $gen->create_user(['username' => 'alphamanager']);
        role_assign($this->roleid, $this->alphamanager->id, context\coursecat::instance((int)$this->alpha->id)->id);
        $this->betamanager = $gen->create_user(['username' => 'betamanager']);
        role_assign($this->roleid, $this->betamanager->id, context\coursecat::instance((int)$this->beta->id)->id);
    }

    /**
     * Allocate a new (or given) learner to a program, then set allocation fields directly.
     *
     * @param string $programkey
     * @param array $fields allocation fields to set after creation
     * @param \stdClass|null $user an existing learner, or null for a new one
     * @return \stdClass the allocation record
     */
    private function allocate(string $programkey, array $fields = [], ?\stdClass $user = null): \stdClass {
        global $DB;

        $gen = $this->getDataGenerator();
        $user = $user ?? $gen->create_user();
        $allocation = $gen->get_plugin_generator('tool_muprog')->create_program_allocation([
            'programid' => $this->programs[$programkey]->id,
            'userid' => $user->id,
            'timeallocated' => $this->now - 10 * DAYSECS,
            'timestart' => $this->now - 10 * DAYSECS,
        ]);
        if ($fields) {
            $DB->update_record('tool_muprog_allocation', (object)(['id' => $allocation->id] + $fields));
        }
        return $DB->get_record('tool_muprog_allocation', ['id' => $allocation->id], '*', MUST_EXIST);
    }

    /**
     * Add a certificate issue to an allocation.
     *
     * @param \stdClass $allocation
     * @param string $component issue component
     * @param int $archived issue archived flag
     */
    private function add_issue(\stdClass $allocation, string $component = 'tool_muprog', int $archived = 0): void {
        global $DB;

        $issueid = $DB->insert_record('tool_certificate_issues', (object)[
            'userid' => $allocation->userid,
            'templateid' => 0,
            'code' => 'C' . random_string(8),
            'emailed' => 0,
            'timecreated' => $this->now,
            'expires' => 0,
            'data' => '{}',
            'component' => $component,
            'courseid' => null,
            'archived' => $archived,
        ]);
        $DB->insert_record('tool_muprog_cert_issue', (object)[
            'programid' => $allocation->programid,
            'allocationid' => $allocation->id,
            'timecompleted' => $this->now,
            'issueid' => $issueid,
            'timecreated' => $this->now,
        ]);
    }

    /**
     * One allocation in each status in Alpha, plus everything that must not be counted.
     */
    private function create_alpha_mix(): void {
        global $DB;

        $this->allocate('pa'); // Open.
        $overdue = $this->allocate('pa', ['timedue' => $this->now - 2 * DAYSECS]);
        $completed = $this->allocate('pa', ['timecompleted' => $this->now - DAYSECS]);
        $this->allocate('pa', ['timestart' => $this->now + 5 * DAYSECS]); // Not started.
        $this->allocate('pa', ['timeend' => $this->now - DAYSECS]); // Ended.
        $duesoon = $this->allocate('pa2', ['timedue' => $this->now + 10 * DAYSECS]); // Open, due soon.
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $this->allocate('pa2', [], $suspended); // Open, counted (as in the table).

        // Certificates: two issues on one allocation count once, an archived issue counts, another component doesn't.
        $this->add_issue($completed);
        $this->add_issue($completed);
        $this->add_issue($overdue, 'tool_muprog', 1);
        $this->add_issue($duesoon, 'mod_coursecertificate');

        // Not counted: archived allocation, archived program, draft program, sub-category, Public, site, deleted learner.
        $this->allocate('pa', ['archived' => 1]);
        $progen = $this->getDataGenerator()->get_plugin_generator('tool_muprog');
        $archived = $progen->create_program([
            'fullname' => 'Archived program',
            'idnumber' => 'PARCH',
            'contextid' => context\coursecat::instance((int)$this->alpha->id)->id,
        ]);
        $draft = $progen->create_program([
            'fullname' => 'Draft program',
            'idnumber' => 'PDRAFT',
            'contextid' => context\coursecat::instance((int)$this->alpha->id)->id,
        ]);
        $this->programs['parch'] = $archived;
        $this->programs['pdraft'] = $draft;
        $this->allocate('parch');
        $this->allocate('pdraft');
        $DB->set_field('tool_muprog_program', 'archived', 1, ['id' => $archived->id]);
        $DB->set_field('tool_muprog_program', 'draft', 1, ['id' => $draft->id]);
        $this->allocate('ps');
        $this->allocate('pf');
        $this->allocate('px');
        $gone = $this->allocate('pa');
        $DB->set_field('user', 'deleted', 1, ['id' => $gone->userid]);

        // Beta has one allocation.
        $this->allocate('pb');
    }

    /**
     * Test: each viewer gets only their own company as an option; the admin gets the configured ones only.
     */
    public function test_company_options(): void {
        $this->assertSame([(int)$this->alpha->id], array_keys(overview_service::get_company_options((int)$this->alphamanager->id)));
        $this->assertSame([(int)$this->beta->id], array_keys(overview_service::get_company_options((int)$this->betamanager->id)));
        $this->assertSame(
            [(int)$this->alpha->id, (int)$this->beta->id],
            array_keys(overview_service::get_company_options((int)get_admin()->id))
        );
        $nobody = $this->getDataGenerator()->create_user();
        $this->assertSame([], overview_service::get_company_options((int)$nobody->id));
    }

    /**
     * Test: the Alpha numbers (one of each status; archived, draft, sub-category, Public, site and deleted left out).
     */
    public function test_alpha_summary(): void {
        $this->create_alpha_mix();
        $summary = overview_service::get_company_summary((int)$this->alpha->id, $this->now, (int)$this->alphamanager->id);

        $this->assertSame(7, $summary['total']);
        $this->assertSame([
            status::OVERDUE => 1,
            status::OPEN => 3,
            status::NOTSTARTED => 1,
            status::ENDED => 1,
            status::COMPLETED => 1,
        ], $summary['statuses']);
        $this->assertSame(1, $summary['overdue']);
        $this->assertSame(1, $summary['completed']);
        $this->assertSame(1, $summary['duesoon']);
        $this->assertSame(2, $summary['certificates']);
        $this->assertEqualsWithDelta(100 / 7, $summary['completionrate'], 0.001);
        $this->assertSame(['Program pa', 'Program pa2'], array_column($summary['programs'], 'name'));

        $beta = overview_service::get_company_summary((int)$this->beta->id, $this->now, (int)$this->betamanager->id);
        $this->assertSame(1, $beta['total']);
    }

    /**
     * Test: a manager of another company, or a user without the capability, is refused.
     */
    public function test_other_company_refused(): void {
        $this->expectException(\required_capability_exception::class);
        overview_service::get_company_summary((int)$this->alpha->id, $this->now, (int)$this->betamanager->id);
    }

    /**
     * Test: no capability at all, refused.
     */
    public function test_no_capability_refused(): void {
        $nobody = $this->getDataGenerator()->create_user();
        $this->expectException(\required_capability_exception::class);
        overview_service::get_company_summary((int)$this->alpha->id, $this->now, (int)$nobody->id);
    }

    /**
     * Test: a site-wide grant fails closed for a non-admin (overview and data source), not for the admin.
     */
    public function test_sitewide_grant_fails_closed(): void {
        $this->create_alpha_mix();
        $wide = $this->getDataGenerator()->create_user(['username' => 'wide']);
        role_assign($this->roleid, $wide->id, context\system::instance()->id);

        $this->assertTrue(company_access::is_blocked_sitewide((int)$wide->id));
        $this->assertSame([], company_access::get_allowed_category_ids((int)$wide->id));
        $this->assertSame([], overview_service::get_company_options((int)$wide->id));
        $this->assertSame(['1 = 0', []], company_access::get_category_condition('x.id', (int)$wide->id));

        /** @var \core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgen->create_report(['name' => 'All', 'source' => program_allocations::class, 'default' => 0]);
        $rbgen->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username']);
        $this->setUser($wide);
        manager::reset_caches();
        $this->assertSame([], $this->get_custom_report_content((int)$report->get('id')));

        // A normal manager and the admin still work.
        $this->assertFalse(company_access::is_blocked_sitewide((int)$this->alphamanager->id));
        $this->assertFalse(company_access::is_blocked_sitewide((int)get_admin()->id));
        $this->assertCount(2, overview_service::get_company_options((int)get_admin()->id));

        $this->expectException(\required_capability_exception::class);
        overview_service::get_company_summary((int)$this->beta->id, $this->now, (int)$wide->id);
    }

    /**
     * Test: overrides. Prohibit on the company category refuses; Prohibit or Prevent on the sub-category changes nothing.
     */
    public function test_capability_overrides(): void {
        $this->create_alpha_mix();
        $manager = (int)$this->alphamanager->id;
        $subcontext = context\coursecat::instance((int)$this->alphasub->id);

        role_change_permission($this->roleid, $subcontext, company_access::CAPABILITY, CAP_PROHIBIT);
        $this->assertSame(7, overview_service::get_company_summary((int)$this->alpha->id, $this->now, $manager)['total']);

        role_change_permission($this->roleid, $subcontext, company_access::CAPABILITY, CAP_PREVENT);
        $this->assertSame(7, overview_service::get_company_summary((int)$this->alpha->id, $this->now, $manager)['total']);

        $alphacontext = context\coursecat::instance((int)$this->alpha->id);
        role_change_permission($this->roleid, $alphacontext, company_access::CAPABILITY, CAP_PROHIBIT);
        $this->assertSame([], overview_service::get_company_options($manager));
        $this->expectException(\required_capability_exception::class);
        overview_service::get_company_summary((int)$this->alpha->id, $this->now, $manager);
    }

    /**
     * Test: a learner in both companies counts once in each; a moved program takes its allocations along.
     */
    public function test_two_companies_and_moved_program(): void {
        global $DB;

        $learner = $this->getDataGenerator()->create_user();
        $this->allocate('pa', [], $learner);
        $this->allocate('pb', [], $learner);
        $alphatotal = fn(): int => overview_service::get_company_summary(
            (int)$this->alpha->id,
            $this->now,
            (int)$this->alphamanager->id
        )['total'];
        $betatotal = fn(): int => overview_service::get_company_summary(
            (int)$this->beta->id,
            $this->now,
            (int)$this->betamanager->id
        )['total'];
        $this->assertSame(1, $alphatotal());
        $this->assertSame(1, $betatotal());

        // Ownership rule (program-reports.md 11.3): the program's current category decides.
        $betacontextid = context\coursecat::instance((int)$this->beta->id)->id;
        $DB->set_field('tool_muprog_program', 'contextid', $betacontextid, ['id' => $this->programs['pa']->id]);
        $this->assertSame(0, $alphatotal());
        $this->assertSame(2, $betatotal());
    }

    /**
     * Test: no allocations (programs exist): zero numbers, no rate, "No allocations" on the tiles.
     */
    public function test_no_allocations(): void {
        global $PAGE;

        $summary = overview_service::get_company_summary((int)$this->alpha->id, $this->now, (int)$this->alphamanager->id);
        $this->assertSame(0, $summary['total']);
        $this->assertNull($summary['completionrate']);
        $this->assertSame([], $summary['programs']);

        $data = (new overview('Company Alpha', $summary))->export_for_template($PAGE->get_renderer('core'));
        $this->assertFalse($data['hasallocations']);
        $none = get_string('tile_noallocations', 'local_merqamreports');
        $this->assertSame([$none, $none, $none, $none], array_column($data['tiles'], 'value'));
    }

    /**
     * Test: the 30-day window: due exactly now is Open but not "due soon"; now + 30 days counts; one second more doesn't.
     */
    public function test_due_window_boundaries(): void {
        $this->allocate('pa', ['timedue' => $this->now]);
        $this->allocate('pa', ['timedue' => $this->now + 30 * DAYSECS]);
        $this->allocate('pa', ['timedue' => $this->now + 30 * DAYSECS + 1]);
        $this->allocate('pa', ['timedue' => $this->now - 1]);

        $summary = overview_service::get_company_summary((int)$this->alpha->id, $this->now, (int)$this->alphamanager->id);
        $this->assertSame(3, $summary['statuses'][status::OPEN]);
        $this->assertSame(1, $summary['statuses'][status::OVERDUE]);
        $this->assertSame(1, $summary['duesoon']);
    }

    /**
     * Test: the request value. Absent: one option chosen, several = selector only (no summary), none = refused.
     */
    public function test_resolve_absent_parameter(): void {
        $this->assertSame((int)$this->alpha->id, overview_service::resolve_requested_category([(int)$this->alpha->id], null));
        $this->assertNull(overview_service::resolve_requested_category([(int)$this->alpha->id, (int)$this->beta->id], null));
        $this->assertSame((int)$this->beta->id, overview_service::resolve_requested_category(
            [(int)$this->alpha->id, (int)$this->beta->id],
            (string)$this->beta->id
        ));

        // The admin's first visit: only the selector, and the summary query never runs.
        $this->setAdminUser();
        $state = overview_service::prepare_page(null, $this->now);
        $this->assertNull($state['chosen']);
        $this->assertNull($state['summary']);
        $this->assertFalse($state['notconfigured']);

        $this->expectException(\required_capability_exception::class);
        overview_service::resolve_requested_category([], null);
    }

    /**
     * Test: every wrong value gives the same permission error.
     */
    public function test_resolve_bad_values(): void {
        $id = (string)$this->alpha->id;
        $options = [(int)$this->alpha->id];
        $bad = [
            '', 'abc', $id . 'abc', $id . "\n", ' ' . $id, '0' . $id, '-' . $id, '99999999999999999999', $id . "\xff",
            [$id], (string)$this->beta->id, (string)$this->public->id, (string)$this->alphasub->id, '999999', 5,
        ];
        foreach ($bad as $value) {
            try {
                overview_service::resolve_requested_category($options, $value);
                $this->fail('Accepted ' . json_encode($value));
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
    }

    /**
     * Test: through prepare_page(), a manager asking for another company or the Public category is refused.
     */
    public function test_prepare_page_refuses_other_company(): void {
        $this->setUser($this->alphamanager);
        $state = overview_service::prepare_page(null, $this->now);
        $this->assertSame((int)$this->alpha->id, $state['chosen']);
        $this->assertIsArray($state['summary']);

        $bad = [(string)$this->beta->id, (string)$this->public->id, [(string)$this->alpha->id], (int)$this->alpha->id . "\xff"];
        foreach ($bad as $value) {
            try {
                overview_service::prepare_page($value, $this->now);
                $this->fail('Accepted ' . json_encode($value));
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
    }

    /**
     * Test: "Log in as" sessions are refused.
     */
    public function test_loggedinas_refused(): void {
        $this->setAdminUser();
        overview_service::require_real_session(); // A real session passes.
        \core\session\manager::loginas((int)$this->alphamanager->id, context\system::instance());
        $this->expectException(\required_capability_exception::class);
        overview_service::require_real_session();
    }

    /**
     * Test: the setting refuses non-top-level categories when saved.
     */
    public function test_setting_refuses_non_top_level(): void {
        $setting = new setting_companyroots();
        $error = get_string('setting_companyroots_invalid', 'local_merqamreports');
        $this->assertSame($error, $setting->write_setting([(string)$this->alphasub->id]));
        $this->assertSame($error, $setting->write_setting([(string)$this->alpha->id, '999999']));
        $this->assertSame('', $setting->write_setting([(string)$this->alpha->id, (string)$this->beta->id]));
        $this->assertSame([(int)$this->alpha->id, (int)$this->beta->id], overview_service::get_saved_ids());

        // A normal form submission includes the hidden field.
        $this->assertSame('', $setting->write_setting(['xxxxx' => '1', (string)$this->alpha->id]));
        $this->assertSame([(int)$this->alpha->id], overview_service::get_saved_ids());

        // Invalid submissions are refused and keep the previous value.
        foreach ([[[(string)$this->beta->id]], ['xxxxx' => '1', ''], [(string)$this->public->id . 'x']] as $data) {
            $this->assertSame($error, $setting->write_setting($data));
            $this->assertSame([(int)$this->alpha->id], overview_service::get_saved_ids());
        }

        // Clearing every choice saves an empty setting.
        $this->assertSame('', $setting->write_setting(['xxxxx' => '1']));
        $this->assertSame([], overview_service::get_saved_ids());
    }

    /**
     * Test: the page's request reading ($_POST first, then $_GET, raw) through prepare_page().
     */
    public function test_request_value_page_entry(): void {
        $this->setUser($this->alphamanager);
        $id = (string)$this->alpha->id;
        $saveget = $_GET;
        $savepost = $_POST;
        try {
            $_POST = [];
            $_GET = [];
            $this->assertNull(overview_service::get_request_value());
            $_GET = ['category' => $id];
            $state = overview_service::prepare_page(overview_service::get_request_value(), $this->now);
            $this->assertSame((int)$this->alpha->id, $state['chosen']);

            $refused = [
                ['get' => [$id]],
                ['get' => $id . "\xff"],
                ['get' => $id . 'abc'],
                ['get' => $id, 'post' => (string)$this->beta->id], // POST wins, like optional_param().
            ];
            foreach ($refused as $case) {
                $_GET = ['category' => $case['get']];
                $_POST = isset($case['post']) ? ['category' => $case['post']] : [];
                try {
                    overview_service::prepare_page(overview_service::get_request_value(), $this->now);
                    $this->fail('Accepted ' . json_encode($case, JSON_INVALID_UTF8_SUBSTITUTE));
                } catch (\required_capability_exception $e) {
                    $this->assertSame('nopermissions', $e->errorcode);
                }
            }
        } finally {
            $_GET = $saveget;
            $_POST = $savepost;
        }
    }

    /**
     * Test: the rendered selector escapes names once ("&" and entity-like text shown as typed).
     */
    public function test_selector_escaping(): void {
        global $OUTPUT;

        $options = [
            (int)$this->alpha->id => 'Safety & Quality',
            (int)$this->beta->id => 'R&amp;D <b>x</b>',
            (int)$this->public->id => 'A&#38;B',
        ];
        $html = $OUTPUT->render(overview_service::get_selector($options, null));
        $this->assertStringContainsString('Safety &amp; Quality', $html);
        $this->assertStringContainsString('R&amp;amp;D &lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringContainsString('A&amp;#38;B', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    /**
     * Test: names are plain text (no "&amp;"), in the viewer's language.
     */
    public function test_names_plain_text(): void {
        global $DB;

        filter_set_global_state('multilang', TEXTFILTER_ON);
        filter_set_applies_to_strings('multilang', true);
        \filter_manager::reset_caches();
        $DB->set_field('course_categories', 'name', 'Safety & Quality', ['id' => (int)$this->alpha->id]);
        $multilang = '<span lang="en" class="multilang">Fire & safety</span><span lang="ar" class="multilang">السلامة</span>';
        $DB->set_field('tool_muprog_program', 'fullname', $multilang, ['id' => $this->programs['pa']->id]);
        $this->allocate('pa');

        $options = overview_service::get_company_options((int)$this->alphamanager->id);
        $this->assertSame([(int)$this->alpha->id => 'Safety & Quality'], $options);
        $summary = overview_service::get_company_summary((int)$this->alpha->id, $this->now, (int)$this->alphamanager->id);
        $this->assertSame(['Fire & safety'], array_column($summary['programs'], 'name'));
    }

    /**
     * Test: saved ids are re-checked: a moved or deleted company is left out and reported to the admin.
     */
    public function test_saved_ids_rechecked(): void {
        $gen = $this->getDataGenerator();
        $gamma = $gen->create_category(['name' => 'Company Gamma']);
        $saved = implode(',', [(int)$this->alpha->id, (int)$this->beta->id, (int)$gamma->id]);
        set_config(overview_service::SETTING, $saved, 'local_merqamreports');
        $alphalearner = $gen->create_user(['username' => 'alphalearner']);
        $this->allocate('pa', [], $alphalearner);
        $this->allocate('pb', [], $gen->create_user(['username' => 'betalearner']));
        $this->allocate('ps', [], $gen->create_user(['username' => 'sublearner']));
        $betachild = $gen->create_category(['name' => 'Beta child', 'parent' => (int)$this->beta->id]);
        $this->programs['pbc'] = $gen->get_plugin_generator('tool_muprog')->create_program([
            'fullname' => 'Beta child program',
            'idnumber' => 'PBC',
            'contextid' => context\coursecat::instance((int)$betachild->id)->id,
        ]);
        $this->allocate('pbc', [], $gen->create_user(['username' => 'betachildlearner']));

        $this->beta->change_parent((int)$this->alpha->id);

        // The moved company's rows (Beta and Beta's child) don't reach the outer company's manager in
        // the detailed report; Alpha's own sub-category still does (as before).
        /** @var \core_reportbuilder_generator $rbgen */
        $rbgen = $gen->get_plugin_generator('core_reportbuilder');
        $report = $rbgen->create_report(['name' => 'All', 'source' => program_allocations::class, 'default' => 0]);
        $reportid = (int)$report->get('id');
        $rbgen->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'user:username', 'sortenabled' => 1]);
        $seenbyalpha = function () use ($reportid): array {
            $this->setUser($this->alphamanager);
            manager::reset_caches();
            $content = $this->get_custom_report_content($reportid);
            return array_map(static fn(array $row): string => (string)reset($row), $content);
        };
        $this->assertSame(['alphalearner', 'sublearner'], $seenbyalpha());
        $allowed = company_access::get_allowed_category_ids((int)$this->alphamanager->id);
        $this->assertNotContains((int)$this->beta->id, $allowed);
        $this->assertNotContains((int)$betachild->id, $allowed);
        $this->assertContains((int)$this->alphasub->id, $allowed);

        // A normal save of the settings form (Beta is no longer offered) keeps Beta saved, so the guard holds.
        $this->setAdminUser();
        $this->assertSame('', (new setting_companyroots())->write_setting(['xxxxx' => '1', (string)$this->alpha->id]));
        $this->assertContains((int)$this->beta->id, overview_service::get_saved_ids());
        $this->assertSame(['alphalearner', 'sublearner'], $seenbyalpha());

        $this->setAdminUser();
        set_config(overview_service::SETTING, $saved, 'local_merqamreports');
        $gamma->delete_full(false);

        $this->assertSame([(int)$this->alpha->id], array_keys(overview_service::get_valid_companies()));
        $this->assertEqualsCanonicalizing([(int)$this->beta->id, (int)$gamma->id], overview_service::get_invalid_saved_ids());
        $this->assertSame([], overview_service::get_company_options((int)$this->betamanager->id));
        try {
            overview_service::resolve_requested_category(
                array_keys(overview_service::get_company_options((int)get_admin()->id)),
                (string)$this->beta->id
            );
            $this->fail('Moved company accepted');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        // All saved ids invalid: no options; the admin sees the "not configured" state, managers are refused.
        set_config(overview_service::SETTING, (string)$gamma->id, 'local_merqamreports');
        $this->assertSame([], overview_service::get_company_options((int)get_admin()->id));
        $this->setAdminUser();
        $this->assertTrue(overview_service::prepare_page(null, $this->now)['notconfigured']);
        $this->setUser($this->alphamanager);
        $this->expectException(\required_capability_exception::class);
        overview_service::prepare_page(null, $this->now);
    }

    /**
     * Test: parity with a real company report (fixed condition "Course category = Company Alpha", exact).
     *
     * Rows per status (without the two archived statuses) and rows with a certificate equal the overview.
     * Dates are well away from status boundaries; a program in an accessible sub-category is in neither.
     */
    public function test_parity_with_company_report(): void {
        $this->create_alpha_mix();

        /** @var \core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgen->create_report(['name' => 'Alpha', 'source' => program_allocations::class, 'default' => 0]);
        $reportid = (int)$report->get('id');
        $rbgen->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'user:username', 'sortenabled' => 1]);
        $rbgen->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'allocation:status']);
        $rbgen->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'certificate:code']);
        $rbgen->create_condition(['reportid' => $reportid, 'uniqueidentifier' => 'course_category:name']);
        manager::get_report_from_persistent($report)->set_condition_values([
            'course_category:name_operator' => category_filter::EQUAL_TO,
            'course_category:name_value' => (int)$this->alpha->id,
        ]);

        $this->setUser($this->alphamanager);
        manager::reset_caches();
        $rows = array_map('array_values', $this->get_custom_report_content($reportid));

        $labels = status::get_labels();
        $archived = [$labels[status::ARCHIVED], $labels[status::ARCHIVEDCOMPLETED]];
        $rows = array_values(array_filter($rows, static fn(array $row): bool => !in_array($row[1], $archived, true)));

        $summary = overview_service::get_company_summary((int)$this->alpha->id, time(), (int)$this->alphamanager->id);
        $this->assertCount($summary['total'], $rows);
        foreach (overview_service::STATUSES as $code) {
            $inreport = count(array_filter($rows, static fn(array $row): bool => $row[1] === $labels[$code]));
            $this->assertSame($summary['statuses'][$code], $inreport, $labels[$code]);
        }
        $withcertificate = count(array_filter($rows, static fn(array $row): bool => $row[2] !== ''));
        $this->assertSame($summary['certificates'], $withcertificate);
    }
}
