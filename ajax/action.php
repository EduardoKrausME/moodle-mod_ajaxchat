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
 * Mutation endpoint for mod_ajaxchat.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing
use core\session\manager;
use mod_ajaxchat\action_service;
use mod_ajaxchat\chat_store;

require_once(__DIR__ . "/../../../config.php");

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate");

try {
    $runtimeid = required_param("runtime", PARAM_ALPHANUMEXT);
    $csrf = required_param("csrf_token", PARAM_RAW);
    $action = required_param("action", PARAM_ALPHA);

    if (!confirm_sesskey()) {
        throw new moodle_exception("errorcsrf", "mod_ajaxchat");
    }
    if (empty($SESSION->mod_ajaxchat_runtimes[$runtimeid])) {
        throw new moodle_exception("errorinvalidruntime", "mod_ajaxchat");
    }

    $runtime = $SESSION->mod_ajaxchat_runtimes[$runtimeid];
    if ((int)($runtime["expires"] ?? 0) < time() ||
        empty($runtime["csrf"]) || !hash_equals((string)$runtime["csrf"], $csrf) ||
        (int)($runtime["userid"] ?? 0) !== (int)$USER->id) {
        unset($SESSION->mod_ajaxchat_runtimes[$runtimeid]);
        chat_store::delete_runtime($runtimeid);
        throw new moodle_exception("errorinvalidruntime", "mod_ajaxchat");
    }

    manager::write_close();

    $service = new action_service($runtime, (int)$USER->id);
    echo json_encode($service->execute($action), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(400);
    $message = $exception instanceof moodle_exception
        ? $exception->getMessage()
        : ($exception->getMessage() ?: get_string("error"));
    echo json_encode(["ok" => false, "error" => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
