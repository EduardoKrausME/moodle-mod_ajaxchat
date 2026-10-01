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
 * Lists AJAX Chat activities in a course.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . "/../../config.php");

$id = required_param("id", PARAM_INT);
$course = get_course($id);
require_course_login($course);

$PAGE->set_url(new moodle_url("/mod/ajaxchat/index.php", ["id" => $course->id]));
$PAGE->set_title(get_string("modulenameplural", "mod_ajaxchat"));
$PAGE->set_heading($course->fullname);

$instances = get_all_instances_in_course("ajaxchat", $course);

$table = new html_table();
$table->head = [get_string("name"), get_string("openfrom", "mod_ajaxchat")];
foreach ($instances as $instance) {
    $url = new moodle_url("/mod/ajaxchat/view.php", ["id" => $instance->coursemodule]);
    $table->data[] = [
        html_writer::link($url, format_string($instance->name)),
        !empty($instance->openfrom) ? userdate($instance->openfrom) : "-",
    ];
}

echo $OUTPUT->header();
echo html_writer::table($table);
echo $OUTPUT->footer();
