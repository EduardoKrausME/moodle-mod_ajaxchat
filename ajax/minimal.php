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
use mod_ajaxchat\local\chat_store;

/**
 * Shared helpers for database-free read endpoints.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function mod_ajaxchat_minimal_fail(int $status, string $message): never {
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["ok" => false, "error" => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mod_ajaxchat_minimal_runtime(): array {
    $runtimeid = isset($_REQUEST["runtime"]) ? (string)$_REQUEST["runtime"] : "";
    $readtoken = isset($_REQUEST["readtoken"]) ? (string)$_REQUEST["readtoken"] : "";
    if (!preg_match('/^[a-f0-9]{64}$/', $runtimeid) || !preg_match('/^[a-f0-9]{64}$/', $readtoken)) {
        mod_ajaxchat_minimal_fail(403, "Invalid runtime.");
    }

    $runtime = chat_store::read_runtime($runtimeid);
    if (!$runtime || empty($runtime["readtoken"]) || !hash_equals((string)$runtime["readtoken"], $readtoken)) {
        mod_ajaxchat_minimal_fail(403, "Expired runtime.");
    }
    return $runtime;
}


function mod_ajaxchat_minimal_store(array $runtime): chat_store {
    $store = new chat_store((int)$runtime["chatid"]);
    if (empty($runtime["path"]) || !hash_equals($store->path(), (string)$runtime["path"])) {
        mod_ajaxchat_minimal_fail(403, "Invalid runtime path.");
    }
    return $store;
}

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
