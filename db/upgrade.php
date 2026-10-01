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
 * Upgrade steps for mod_ajaxchat.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined("MOODLE_INTERNAL") || die();

function xmldb_ajaxchat_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026091401) {
        $table = new xmldb_table("ajaxchat");
        $field = new xmldb_field("name", XMLDB_TYPE_CHAR, "255", null, XMLDB_NOTNULL, null, null, "course");
        $dbman->change_field_default($table, $field);

        $table = new xmldb_table("ajaxchat_reactions");
        $field = new xmldb_field("reaction", XMLDB_TYPE_CHAR, "32", null, XMLDB_NOTNULL, null, null, "userid");
        $dbman->change_field_default($table, $field);

        $indexes = [
            ["ajaxchat", "course_idx", ["course"]],
            ["ajaxchat_messages", "user_idx", ["userid"]],
            ["ajaxchat_reactions", "chat_idx", ["ajaxchatid"]],
            ["ajaxchat_polls", "chat_idx", ["ajaxchatid"]],
            ["ajaxchat_poll_options", "poll_idx", ["pollid"]],
        ];
        foreach ($indexes as [$tablename, $indexname, $fields]) {
            $table = new xmldb_table($tablename);
            $index = new xmldb_index($indexname, XMLDB_INDEX_NOTUNIQUE, $fields);
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
        }

        upgrade_mod_savepoint(true, 2026091401, "ajaxchat");
    }

    return true;
}
