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

/**
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 *
 * @package    local_intelliboard
 * @copyright  2017 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @website    http://intelliboard.net/
 */

require('../../../config.php');
require_once($CFG->dirroot .'/local/intelliboard/locallib.php');
require_once($CFG->dirroot .'/local/intelliboard/externallib.php');
require_once($CFG->dirroot .'/local/intelliboard/student/lib.php');

require_login();
require_capability('local/intelliboard:students', context_system::instance());

$report = optional_param('id', '', PARAM_RAW);
$intelliboard = intelliboard(['task'=>'reports', 'mode' => 1]);
$params = http_build_query(['users'=>$USER->id ]);

$totals = intelliboard_learner_totals($USER->id);

$PAGE->set_url(new moodle_url("/local/intelliboard/student/reports.php"));
$PAGE->set_pagetype('reports');
$PAGE->set_pagelayout('report');
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('intelliboardroot', 'local_intelliboard'));
$PAGE->set_heading(get_string('intelliboardroot', 'local_intelliboard'));
$PAGE->requires->jquery();
$PAGE->requires->js('/local/intelliboard/assets/js/flatpickr.min.js');
$PAGE->requires->js('/local/intelliboard/assets/js/jquery.circlechart.js');
$PAGE->requires->css('/local/intelliboard/assets/css/flatpickr.min.css');
$PAGE->requires->css('/local/intelliboard/assets/css/style.css');
echo $OUTPUT->header();
?>
<div class="intelliboard-page intelliboard-student">
	<?php include("views/menu.php"); ?>
	<div class="intelliboard-content">
		<?php if ($intelliboard->alerts): ?>
			<?php foreach ($intelliboard->alerts as $text => $alert): ?>
				<div class="alert alert-block alert-<?php echo format_string($alert); ?>" role="alert"><?php echo format_text($text); ?></div>
			<?php endforeach; ?>
		<?php endif; ?>

		<div id="iframe">
			<iframe id="iframe" src="<?php echo intelliboard_url(); ?>reports/share/<?php echo $intelliboard->db . '/' . $report; ?>/<?php echo format_string($intelliboard->token); ?>?header=0&frame=1&<?php echo $params; ?>" width="100%" height="800" frameborder="0"></iframe>
			<span id="iframe-loading"><?php echo get_string('loading2', 'local_intelliboard'); ?></span>
		</div>
	</div>
	<?php include("views/footer.php"); ?>
</div>
<?php
echo $OUTPUT->footer();
