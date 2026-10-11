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
 * Backup task for a chat activity.
 *
 * @package mod_ajaxchat
 * @category backup
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/mod/ajaxchat/backup/moodle2/backup_ajaxchat_stepslib.php');

/**
 * Register the chat backup structure.
 */
class backup_ajaxchat_activity_task extends backup_activity_task {
    /**
     * No plugin-specific settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Add the data structure to the backup.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_ajaxchat_activity_structure_step('ajaxchat_structure', 'ajaxchat.xml'));
    }

    /**
     * Encode chat view links in course content.
     *
     * @param string $content Course content.
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;
        $base = preg_quote($CFG->wwwroot . '/mod/ajaxchat', '#');
        return preg_replace('#(' . $base . '/view\.php\?id=)([0-9]+)#', '$@AJAXCHATVIEW*$2@$', $content);
    }
}
