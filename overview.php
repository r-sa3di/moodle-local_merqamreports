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

/**
 * Company overview page: four tiles and two charts for one company (docs/program-reports.md section 11).
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use core\context;
use local_merqamreports\local\overview_service;

require_login(null, false);
overview_service::require_real_session();

// All checks happen here, before any output. The request value is read raw on purpose
// (overview_service::get_request_value() explains why).
$state = overview_service::prepare_page(overview_service::get_request_value(), time());
$options = $state['options'];
$notconfigured = $state['notconfigured'];
$chosen = $state['chosen'];
$isadmin = is_siteadmin();

$overview = null;
$urlparams = [];
$pagecontext = context\system::instance();
if ($chosen !== null) {
    $overview = new \local_merqamreports\output\overview($options[$chosen], $state['summary']);
    $urlparams = ['category' => $chosen];
    $pagecontext = context\coursecat::instance($chosen);
}

$PAGE->set_context($pagecontext);
$PAGE->set_url(new moodle_url('/local/merqamreports/overview.php', $urlparams));
$PAGE->set_pagelayout('report');
$title = get_string('overview_title', 'local_merqamreports');
$PAGE->set_title($title);
$PAGE->set_heading($title);

echo $OUTPUT->header();

if ($isadmin) {
    $invalid = overview_service::get_invalid_saved_ids();
    if ($invalid) {
        $message = get_string('overview_invalidcategories', 'local_merqamreports', implode(', ', $invalid));
        echo $OUTPUT->notification($message, 'warning');
    }
    if ($notconfigured) {
        $settingsurl = new moodle_url('/admin/settings.php', ['section' => 'local_merqamreports']);
        echo $OUTPUT->notification(get_string('overview_nocategories', 'local_merqamreports', $settingsurl->out()), 'info');
    }
}

if (count($options) > 1) {
    echo $OUTPUT->render(overview_service::get_selector($options, $chosen));
}

if ($overview) {
    echo $PAGE->get_renderer('local_merqamreports')->render($overview);
    echo $OUTPUT->single_button(
        new moodle_url('/reportbuilder/index.php'),
        get_string('overview_openreports', 'local_merqamreports'),
        'get'
    );
} else if (!$notconfigured) {
    echo $OUTPUT->notification(get_string('overview_choosecompany', 'local_merqamreports'), 'info');
}

echo $OUTPUT->footer();
