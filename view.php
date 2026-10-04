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
 * Main AJAX Chat page.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_ajaxchat\chat_store;

require_once(__DIR__ . "/../../config.php");

$id = required_param("id", PARAM_INT);
$cm = get_coursemodule_from_id("ajaxchat", $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$chat = $DB->get_record("ajaxchat", ["id" => $cm->instance], "*", MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability("mod/ajaxchat:view", $context);

$PAGE->set_url(new moodle_url("/mod/ajaxchat/view.php", ["id" => $cm->id]));
$PAGE->set_title(format_string($chat->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$canmanage = has_capability("mod/ajaxchat:manage", $context);
$cansend = has_capability("mod/ajaxchat:send", $context);
$cancreatepoll = has_capability("mod/ajaxchat:createpoll", $context);

$store = new chat_store((int)$chat->id);
$store->merge_state([
    "enabled" => !empty($chat->enabled),
    "allowaudio" => !empty($chat->allowaudio),
    "allowattachments" => !empty($chat->allowattachments),
    "openfrom" => (int)$chat->openfrom,
]);
$state = $store->get_state();

$participants = get_enrolled_users($context, "mod/ajaxchat:view", 0,
    "u.id,u.firstname,u.lastname,u.firstnamephonetic,u.lastnamephonetic,u.middlename,u.alternatename,u.picture,u.imagealt",
    "u.firstname,u.lastname");
$participantdata = [];
$participantids = [];
$blockableids = [];
foreach ($participants as $participant) {
    $participantids[] = (int)$participant->id;
    $blockable = !has_capability("mod/ajaxchat:manage", $context, (int)$participant->id);
    if ($blockable) {
        $blockableids[] = (int)$participant->id;
    }
    $picture = new user_picture($participant);
    $picture->size = 48;
    $participantdata[] = [
        "id" => (int)$participant->id,
        "name" => fullname($participant),
        "avatar" => $picture->get_url($PAGE)->out(false),
        "blocked" => !empty($state["blocked"][(string)$participant->id]),
        "blockable" => $blockable,
        "self" => (int)$participant->id === (int)$USER->id,
    ];
}

$currentpicture = new user_picture($USER);
$currentpicture->size = 64;

$runtimeid = bin2hex(random_bytes(32));
$csrf = bin2hex(random_bytes(32));
$startcursor = $store->current_cursor();
$readtoken = bin2hex(random_bytes(32));
$expires = time() + 4 * HOURSECS;
$runtime = [
    "runtimeid" => $runtimeid,
    "userid" => (int)$USER->id,
    "chatid" => (int)$chat->id,
    "cmid" => (int)$cm->id,
    "path" => $store->path(),
    "csrf" => $csrf,
    "readtoken" => $readtoken,
    "startcursor" => $startcursor,
    "fullname" => fullname($USER),
    "avatar" => $currentpicture->get_url($PAGE)->out(false),
    "cansend" => $cansend,
    "canmanage" => $canmanage,
    "cancreatepoll" => $cancreatepoll,
    "participantids" => $participantids,
    "blockableids" => $blockableids,
    "expires" => $expires,
];

if (!isset($SESSION->mod_ajaxchat_runtimes) || !is_array($SESSION->mod_ajaxchat_runtimes)) {
    $SESSION->mod_ajaxchat_runtimes = [];
}
$SESSION->mod_ajaxchat_runtimes[$runtimeid] = $runtime;

chat_store::write_runtime($runtimeid, [
    "userid" => (int)$USER->id,
    "chatid" => (int)$chat->id,
    "path" => $store->path(),
    "readtoken" => $readtoken,
    "startcursor" => $startcursor,
    "canmanage" => $canmanage,
    "expires" => $expires,
]);

$PAGE->requires->js_call_amd("mod_ajaxchat/chat", "init", [[
    "runtime" => $runtimeid,
    "readtoken" => $readtoken,
    "csrfToken" => $csrf,
    "sesskey" => sesskey(),
    "userid" => (int)$USER->id,
    "canmanage" => $canmanage,
    "cansend" => $cansend,
    "cancreatepoll" => $cancreatepoll,
    "allowaudio" => !empty($chat->allowaudio),
    "allowattachments" => !empty($chat->allowattachments),
    "historyUrl" => (new moodle_url("/mod/ajaxchat/ajax/history.php"))->out(false),
    "pollUrl" => (new moodle_url("/mod/ajaxchat/ajax/poll.php"))->out(false),
    "actionUrl" => (new moodle_url("/mod/ajaxchat/ajax/action.php"))->out(false),
    "fileUrl" => (new moodle_url("/mod/ajaxchat/ajax/file.php"))->out(false),
    "strings" => [
        "deleted" => get_string("deletedmessage", "mod_ajaxchat"),
        "edited" => get_string("edited", "mod_ajaxchat"),
        "blocked" => get_string("blocked", "mod_ajaxchat"),
        "disabled" => get_string("chatdisabled", "mod_ajaxchat"),
        "enablechat" => get_string("enablechat", "mod_ajaxchat"),
        "disablechat" => get_string("disablechat", "mod_ajaxchat"),
        "closepoll" => get_string("closepoll", "mod_ajaxchat"),
        "pollclosed" => get_string("pollclosed", "mod_ajaxchat"),
        "edit" => get_string("edit", "mod_ajaxchat"),
        "delete" => get_string("delete", "mod_ajaxchat"),
        "you" => get_string("you", "mod_ajaxchat"),
        "online" => get_string("online", "mod_ajaxchat"),
        "attachment" => get_string("attachment", "mod_ajaxchat"),
        "availablefromprefix" => get_string("availablefromprefix", "mod_ajaxchat"),
        "chatnotavailableyet" => get_string("chatnotavailableyet", "mod_ajaxchat"),
        "errorloadchat" => get_string("errorloadchat", "mod_ajaxchat"),
        "errorloadhistory" => get_string("errorloadhistory", "mod_ajaxchat"),
        "errorsessionexpired" => get_string("errorsessionexpired", "mod_ajaxchat"),
        "erroraction" => get_string("erroraction", "mod_ajaxchat"),
        "errorsend" => get_string("errorsend", "mod_ajaxchat"),
        "audionotsupported" => get_string("audionotsupported", "mod_ajaxchat"),
        "audioready" => get_string("audioready", "mod_ajaxchat"),
        "microphoneerror" => get_string("microphoneerror", "mod_ajaxchat"),
    ],
]]);

$contextdata = [
    "title" => format_string($chat->name),
    "intro" => format_module_intro("ajaxchat", $chat, $cm->id, false),
    "participants" => $participantdata,
    "canmanage" => $canmanage,
    "cancreatepoll" => $cancreatepoll,
    "allowaudio" => !empty($chat->allowaudio),
    "allowattachments" => !empty($chat->allowattachments),
    "enabled" => !empty($state["enabled"]),
    "currentuser" => fullname($USER),
    "currentavatar" => $currentpicture->get_url($PAGE)->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template("mod_ajaxchat/chat", $contextdata);
$completion = new completion_info($course);
$completion->set_module_viewed($cm);
echo $OUTPUT->footer();
