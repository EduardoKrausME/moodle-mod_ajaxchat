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
 * AJAX Chat service endpoints for browser actions and polling.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ajaxchat\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use invalid_parameter_exception;
use mod_ajaxchat\action_service;
use mod_ajaxchat\chat_store;
use moodle_exception;

/**
 * Service that validates the live user context on every request.
 */
class api extends external_api {
    /**
     * Define external parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            "runtime" => new external_value(PARAM_ALPHANUMEXT, "Current page runtime id"),
            "operation" => new external_value(PARAM_ALPHA, "history, poll, or action"),
            "payload" => new external_value(PARAM_RAW, "JSON object of operation arguments", VALUE_DEFAULT, "{}"),
        ]);
    }

    /**
     * Fetch history, poll changes, or perform an authenticated non-upload mutation.
     *
     * @param string $runtime Current page runtime.
     * @param string $operation Operation name.
     * @param string $payload JSON argument object.
     * @return string JSON response.
     */
    public static function execute(string $runtime, string $operation, string $payload = "{}"): string {
        global $USER, $SESSION, $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), [
            "runtime" => $runtime,
            "operation" => $operation,
            "payload" => $payload,
        ]);
        $id = $params["runtime"];
        if (!preg_match('/^[a-f0-9]{64}$/', $id) || empty($SESSION->mod_ajaxchat_runtimes[$id])) {
            throw new moodle_exception("errorinvalidruntime", "mod_ajaxchat");
        }
        $session = $SESSION->mod_ajaxchat_runtimes[$id];
        if ((int)($session["expires"] ?? 0) < time() ||
            (int)($session["userid"] ?? 0) !== (int)$USER->id) {
            unset($SESSION->mod_ajaxchat_runtimes[$id]);
            throw new moodle_exception("errorinvalidruntime", "mod_ajaxchat");
        }
        $cm = get_coursemodule_from_id("ajaxchat", (int)$session["cmid"], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        require_login($course, true, $cm);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability("mod/ajaxchat:view", $context);
        if ((int)$cm->instance !== (int)$session["chatid"]) {
            throw new moodle_exception("errorinvalidruntime", "mod_ajaxchat");
        }
        $PAGE->set_context($context);
        $session["cansend"] = has_capability("mod/ajaxchat:send", $context);
        $session["canmanage"] = has_capability("mod/ajaxchat:manage", $context);
        $session["cancreatepoll"] = has_capability("mod/ajaxchat:createpoll", $context);
        $data = json_decode($params["payload"], true);
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new invalid_parameter_exception("Invalid JSON chat request.");
        }

        // Polling is frequent: do not hold the session lock while reading the cache.
        \core\session\manager::write_close();
        $store = new chat_store((int)$session["chatid"]);
        if ($params["operation"] === "history") {
            return self::json(self::history($store, $session));
        }
        if ($params["operation"] === "poll") {
            return self::json(self::poll($store, $session, (string)($data["cursor"] ?? "")));
        }
        if ($params["operation"] === "action") {
            $action = (string)($data["action"] ?? "");
            if (!preg_match('/^[a-z]+$/', $action) || isset($data["upload"])) {
                throw new invalid_parameter_exception("Invalid chat action.");
            }
            return self::json((new action_service($session, (int)$USER->id, $data))->execute($action));
        }
        throw new invalid_parameter_exception("Unknown chat operation.");
    }

    /**
     * Define the JSON result format.
     *
     * @return external_value
     */
    public static function execute_returns(): external_value {
        return new external_value(PARAM_RAW, "JSON chat response");
    }

    /**
     * Get the current chat state for the active user.
     *
     * @param chat_store $store Cache.
     * @param array $runtime Authorized runtime.
     * @return array
     */
    private static function state(chat_store $store, array $runtime): array {
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

    /**
     * Read the current history and active polls.
     *
     * @param chat_store $store Cache.
     * @param array $runtime Authorized runtime.
     * @return array
     */
    private static function history(chat_store $store, array $runtime): array {
        $state = self::state($store, $runtime);
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
        return [
            "ok" => true,
            "messages" => $messages,
            "activepolls" => $activepolls,
            "state" => $state,
            "cursor" => (string)($runtime["startcursor"] ?? $store->current_cursor()),
        ];
    }

    /**
     * Read incremental activity events.
     *
     * @param chat_store $store Cache.
     * @param array $runtime Authorized runtime.
     * @param string $cursor Client cursor.
     * @return array
     */
    private static function poll(chat_store $store, array $runtime, string $cursor): array {
        $state = self::state($store, $runtime);
        if (!empty($state["locked"])) {
            return [
                "ok" => true,
                "events" => [],
                "cursor" => $store->current_cursor(),
                "state" => $state,
            ];
        }
        $cursor = $store->clamp_cursor($cursor, (string)($runtime["startcursor"] ?? $store->current_cursor()));
        $result = $store->read_events($cursor, 200);
        $events = [];
        foreach ($result["events"] as $event) {
            $type = (string)($event["type"] ?? "");
            if (in_array($type, ["message", "edit", "delete"], true) && !empty($event["messageid"])) {
                $message = $store->get_message((int)$event["messageid"]);
                if ($message) {
                    $key = $type === "message" ? "message" : "currentmessage";
                    $event[$key] = $store->prepare_message_for_client($message, (int)$runtime["userid"]);
                }
            }
            if ($type === "poll_changed" && !empty($event["pollid"])) {
                $poll = $store->get_poll((int)$event["pollid"]);
                if ($poll) {
                    $event["poll"] = $store->prepare_poll_for_client($poll, (int)$runtime["userid"]);
                }
            }
            if ($type === "block" && empty($runtime["canmanage"]) &&
                (int)($event["userid"] ?? 0) !== (int)$runtime["userid"]) {
                continue;
            }
            $events[] = $event;
        }
        return ["ok" => true, "events" => $events, "cursor" => $result["cursor"], "state" => $state];
    }

    /**
     * Serialize the response for core/ajax.
     *
     * @param array $response Result.
     * @return string
     */
    private static function json(array $response): string {
        return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
