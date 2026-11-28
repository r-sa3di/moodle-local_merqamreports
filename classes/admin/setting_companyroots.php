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

namespace local_merqamreports\admin;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Admin setting "Company categories": a multi-select of top-level course categories only.
 *
 * Any other id is refused on save (an error, not silently dropped). The saved ids are checked
 * again whenever they are used ({@see \local_merqamreports\local\overview_service::get_valid_companies()}).
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_companyroots extends \admin_setting_configmultiselect {
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(
            'local_merqamreports/' . \local_merqamreports\local\overview_service::SETTING,
            new \lang_string('setting_companyroots', 'local_merqamreports'),
            new \lang_string('setting_companyroots_desc', 'local_merqamreports'),
            [],
            null
        );
    }

    /**
     * The choices: top-level course categories only.
     *
     * @return bool
     */
    #[\Override]
    public function load_choices() {
        global $DB;

        if (is_array($this->choices)) {
            return true;
        }
        $this->choices = [];
        $categories = $DB->get_records('course_categories', ['parent' => 0], 'sortorder', 'id, name');
        foreach ($categories as $category) {
            $this->choices[(string)$category->id] = format_string(
                $category->name,
                true,
                ['context' => \core\context\coursecat::instance($category->id)]
            );
        }
        return true;
    }

    /**
     * Save only top-level category ids; refuse the whole submission (keeping the old value) otherwise.
     *
     * @param mixed $data the submitted values, plus Moodle's hidden "xxxxx" field
     * @return string empty on success, else the error
     */
    #[\Override]
    public function write_setting($data) {
        if (!is_array($data)) {
            return '';
        }
        $this->load_choices();
        $error = get_string('setting_companyroots_invalid', 'local_merqamreports');
        $save = [];
        foreach ($data as $key => $value) {
            if ($key === 'xxxxx') {
                continue; // Moodle's hidden field, sent even when nothing is selected.
            }
            if (!is_string($value) && !is_int($value)) {
                return $error;
            }
            $value = (string)$value;
            if (!array_key_exists($value, $this->choices)) {
                return $error;
            }
            $save[] = $value;
        }
        // Keep saved companies that still exist but are no longer top-level. They aren't offered as
        // choices, so a normal save would drop them, and the guard in company_access (which keeps
        // their rows from the outer category's managers) would stop applying. They leave the list
        // only when the category is deleted or moved back to the top level and unticked.
        foreach (self::get_misplaced_saved_ids() as $id) {
            $save[] = (string)$id;
        }
        $save = array_values(array_unique($save));
        return $this->config_write($this->name, implode(',', $save)) ? '' : get_string('errorsetting', 'admin');
    }

    /**
     * Saved company ids whose category still exists but is no longer top-level.
     *
     * @return int[]
     */
    public static function get_misplaced_saved_ids(): array {
        global $DB;

        $saved = \local_merqamreports\local\overview_service::get_saved_ids();
        if (!$saved) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($saved, SQL_PARAMS_NAMED);
        $ids = $DB->get_fieldset_select('course_categories', 'id', "id {$insql} AND parent <> 0", $params);
        return array_map('intval', $ids);
    }
}
