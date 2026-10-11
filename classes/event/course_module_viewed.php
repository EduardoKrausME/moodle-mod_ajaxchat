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
 * Chat viewed event for standard course activity logs.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ajaxchat\event;

/**
 * Chat course module viewed.
 */
class course_module_viewed extends \core\event\course_module_viewed {
    /**
     * Initialise event properties.
     */
    protected function init(): void {
        $this->data["objecttable"] = "ajaxchat";
        $this->data["crud"] = "r";
        $this->data["edulevel"] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Provide the activity mapping for restored course logs.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ["db" => "ajaxchat", "restore" => "ajaxchat"];
    }
}
