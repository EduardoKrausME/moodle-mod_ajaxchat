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
 * Restore task for chat activities.
 *
 * @package mod_ajaxchat
 * @category backup
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/mod/ajaxchat/backup/moodle2/restore_ajaxchat_stepslib.php');

/**
 * Register chat restore steps and content mappings.
 */
class restore_ajaxchat_activity_task extends restore_activity_task {
    /**
     * No plugin-specific settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Restore the chat activity.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_ajaxchat_activity_structure_step('ajaxchat_structure', 'ajaxchat.xml'));
    }

    /**
     * Define decoded HTML fields.
     *
     * @return array
     */
    public static function define_decode_contents() {
        return [new restore_decode_content('ajaxchat', ['intro'], 'ajaxchat')];
    }

    /**
     * Define links which are rewritten on course restore.
     *
     * @return array
     */
    public static function define_decode_rules() {
        return [new restore_decode_rule('AJAXCHATVIEW', '/mod/ajaxchat/view.php?id=$1', 'course_module')];
    }

    /**
     * Restore no legacy event log formats.
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        return [];
    }
}
