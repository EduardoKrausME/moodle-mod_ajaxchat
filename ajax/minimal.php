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

use mod_ajaxchat\chat_store;

/**
 * Shared helpers for authenticated read endpoints.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * mod_ajaxchat_minimal_fail
 *
 * @param int $status
 * @param string $message
 * @return never
 */
function mod_ajaxchat_minimal_fail(int $status, string $message): never {
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["ok" => false, "error" => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * mod_ajaxchat_minimal_runtime
 *
 * @return array
 */
function mod_ajaxchat_minimal_runtime(): array {
    global $SESSION, $USER;

    $runtimeid = required_param("runtime", PARAM_ALPHANUMEXT);
    if (!preg_match('/^[a-f0-9]{64}$/', $runtimeid) ||
        empty($SESSION->mod_ajaxchat_runtimes[$runtimeid])) {
        mod_ajaxchat_minimal_fail(403, get_string("errorinvalidruntime", "mod_ajaxchat"));
    }
    $runtime = $SESSION->mod_ajaxchat_runtimes[$runtimeid];
    if ((int)($runtime["expires"] ?? 0) < time() ||
        (int)($runtime["userid"] ?? 0) !== (int)$USER->id) {
        unset($SESSION->mod_ajaxchat_runtimes[$runtimeid]);
        mod_ajaxchat_minimal_fail(403, get_string("errorinvalidruntime", "mod_ajaxchat"));
    }

    $cm = get_coursemodule_from_id("ajaxchat", (int)$runtime["cmid"], 0, false, MUST_EXIST);
    $course = get_course($cm->course);
    require_login($course, true, $cm);
    $context = \context_module::instance($cm->id);
    require_capability("mod/ajaxchat:view", $context);
    if ((int)$cm->instance !== (int)$runtime["chatid"]) {
        mod_ajaxchat_minimal_fail(403, get_string("errorinvalidruntime", "mod_ajaxchat"));
    }
    $runtime["canmanage"] = has_capability("mod/ajaxchat:manage", $context);
    \core\session\manager::write_close();
    return $runtime;
}


/**
 * mod_ajaxchat_minimal_store
 *
 * @param array $runtime
 * @return chat_store
 */
function mod_ajaxchat_minimal_store(array $runtime): chat_store {
    $store = new chat_store((int)$runtime["chatid"]);
    return $store;
}

/**
 * mod_ajaxchat_minimal_state
 *
 * @param chat_store $store
 * @param array $runtime
 * @return array
 */
function mod_ajaxchat_minimal_state(chat_store $store, array $runtime): array {
    $state = $store->get_state();
    $userid = (int)$runtime["userid"];
    $canmanage = !empty($runtime["canmanage"]);
    $response = [
        "enabled" => !empty($state["enabled"]),
        "allowaudio" => !empty($state["allowaudio"]),
        "allowattachments" => !empty($state["allowattachments"]),
        "openfrom" => (int)($state["openfrom"] ?? 0),
        "blocked" => !empty($state["blocked"][(string)$userid]),
        "locked" => !$canmanage && !empty($state["openfrom"]) && (int)$state["openfrom"] > time(),
        "activepolls" => array_keys($state["activepolls"] ?? []),
    ];
    if ($canmanage) {
        $response["blockeduserids"] = array_map("intval", array_keys($state["blocked"] ?? []));
    }
    return $response;
}
