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
 * Database-free initial history endpoint for mod_ajaxchat.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define("NO_DEBUG_DISPLAY", true);
define("ABORT_AFTER_CONFIG", true);
require_once(__DIR__ . "/../../../config.php");
require_once(__DIR__ . "/../classes/local/chat_store.php");
require_once(__DIR__ . "/minimal.php");

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate");

$runtime = mod_ajaxchat_minimal_runtime();
$store = mod_ajaxchat_minimal_store($runtime);
$state = mod_ajaxchat_minimal_state($store, $runtime);
$historycursor = (string)($runtime["startcursor"] ?? $store->current_cursor());
$messages = [];
$activepolls = [];

if (empty($state["locked"])) {
    $messages = $store->get_history((int)$runtime["userid"], 100);
    foreach ($state["activepolls"] as $pollid) {
        $poll = $store->get_poll((int)$pollid);
        if ($poll && empty($poll["closed"])) {
            $activepolls[] = $store->prepare_poll_for_client($poll, (int)$runtime["userid"]);
        }
    }
}

echo json_encode([
    "ok" => true,
    "messages" => $messages,
    "activepolls" => $activepolls,
    "state" => $state,
    "cursor" => $historycursor,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
