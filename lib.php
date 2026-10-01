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
 * Core callbacks for mod_ajaxchat.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_ajaxchat\local\chat_store;

defined("MOODLE_INTERNAL") || die();

function ajaxchat_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_OTHER;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_GROUPS:
            return false;
        case FEATURE_BACKUP_MOODLE2:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_COMMUNICATION;
        default:
            return null;
    }
}

function ajaxchat_add_instance($data, $mform = null) {
    global $DB;

    $now = time();
    $data->timecreated = $now;
    $data->timemodified = $now;
    $data->enabled = 1;
    $data->id = $DB->insert_record("ajaxchat", $data);

    $store = new chat_store($data->id);
    $store->initialise_state([
        "enabled" => true,
        "allowaudio" => !empty($data->allowaudio),
        "allowattachments" => !empty($data->allowattachments),
        "openfrom" => (int)$data->openfrom,
    ]);

    return $data->id;
}

function ajaxchat_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    $DB->update_record("ajaxchat", $data);

    $store = new chat_store($data->id);
    $store->merge_state([
        "allowaudio" => !empty($data->allowaudio),
        "allowattachments" => !empty($data->allowattachments),
        "openfrom" => (int)$data->openfrom,
    ]);

    return true;
}

function ajaxchat_delete_instance($id) {
    global $DB;

    if (!$DB->record_exists("ajaxchat", ["id" => $id])) {
        return false;
    }

    $polls = $DB->get_records("ajaxchat_polls", ["ajaxchatid" => $id], "", "id");
    foreach ($polls as $poll) {
        $DB->delete_records("ajaxchat_poll_votes", ["pollid" => $poll->id]);
        $DB->delete_records("ajaxchat_poll_options", ["pollid" => $poll->id]);
    }

    $DB->delete_records("ajaxchat_polls", ["ajaxchatid" => $id]);
    $DB->delete_records("ajaxchat_reactions", ["ajaxchatid" => $id]);
    $DB->delete_records("ajaxchat_blocks", ["ajaxchatid" => $id]);
    $DB->delete_records("ajaxchat_messages", ["ajaxchatid" => $id]);
    $DB->delete_records("ajaxchat", ["id" => $id]);

    chat_store::remove_instance_directory($id);
    return true;
}

function ajaxchat_get_coursemodule_info($coursemodule) {
    global $DB;

    $instance = $DB->get_record("ajaxchat", ["id" => $coursemodule->instance], "id,name,intro,introformat,openfrom", IGNORE_MISSING);
    if (!$instance) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $instance->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro("ajaxchat", $instance, $coursemodule->id, false);
    }
    if ($instance->openfrom > time()) {
        $info->customdata = ["openfrom" => $instance->openfrom];
    }
    return $info;
}
