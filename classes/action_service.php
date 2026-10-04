<?php
/**
 * Handles chat mutations. AJAX mutations use database writes only in this class.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ajaxchat;

use coding_exception;
use core_text;
use dml_exception;
use dml_transaction_exception;
use finfo;
use invalid_parameter_exception;
use moodle_exception;
use RuntimeException;
use Throwable;

/**
 * class action_service
 */
class action_service {
    /** @var array */
    private array $runtime;
    /** @var chat_store */
    private chat_store $store;
    /** @var int */
    private int $userid;

    /**
     * @param array $runtime
     * @param int $userid
     * @throws moodle_exception
     */
    public function __construct(array $runtime, int $userid) {
        $this->runtime = $runtime;
        $this->userid = $userid;
        $this->store = new chat_store((int)$runtime["chatid"]);

        if (($runtime["path"] ?? "") !== $this->store->path()) {
            throw new moodle_exception("errorinvalidruntime", "mod_ajaxchat");
        }
    }

    /**
     * execute
     *
     * @param string $action
     * @return array
     * @throws Throwable
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     */
    public function execute(string $action): array {
        switch ($action) {
            case "send":
                return $this->send_message();
            case "edit":
                return $this->edit_message();
            case "delete":
                return $this->delete_message();
            case "reaction":
                return $this->react_to_message();
            case "togglechat":
                return $this->toggle_chat();
            case "block":
                return $this->block_user();
            case "createpoll":
                return $this->create_poll();
            case "vote":
                return $this->vote_poll();
            case "closepoll":
                return $this->close_poll();
            default:
                throw new invalid_parameter_exception("Unknown chat action.");
        }
    }

    /**
     * send_message
     *
     * @return array
     * @throws Throwable
     * @throws coding_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     */
    private function send_message(): array {
        global $DB;

        $this->require_send_permission();
        $state = $this->require_chat_writable();
        $message = trim((string)optional_param("message", "", PARAM_RAW));
        $message = core_text::substr($message, 0, 5000);
        $uploadkind = optional_param("uploadkind", "", PARAM_ALPHA);
        $attachment = null;

        if (!empty($_FILES["upload"]) && is_array($_FILES["upload"]) && (int)$_FILES["upload"]["error"] !== UPLOAD_ERR_NO_FILE) {
            if ((int)$_FILES["upload"]["error"] !== UPLOAD_ERR_OK) {
                throw new moodle_exception("errorinvalidfile", "mod_ajaxchat");
            }
            if ($uploadkind === "audio" && empty($state["allowaudio"])) {
                throw new moodle_exception("erroraudiooff", "mod_ajaxchat");
            }
            if ($uploadkind !== "audio" && empty($state["allowattachments"])) {
                throw new moodle_exception("errorattachmentsoff", "mod_ajaxchat");
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimetype = strtolower((string)$finfo->file($_FILES["upload"]["tmp_name"]));
            if ($uploadkind === "audio" && ($mimetype === "" || $mimetype === "application/octet-stream")) {
                $clientmime = strtolower(trim((string)($_FILES["upload"]["type"] ?? "")));
                $clientmime = trim(explode(";", $clientmime, 2)[0]);
                $allowedaudio = ["audio/webm", "video/webm", "audio/ogg", "application/ogg", "audio/mp4", "video/mp4", "audio/mpeg", "audio/wav"];
                if (in_array($clientmime, $allowedaudio, true)) {
                    $mimetype = $clientmime;
                }
            }
            try {
                $attachment = $this->store->store_upload(
                    $_FILES["upload"]["tmp_name"],
                    (string)$_FILES["upload"]["name"],
                    $mimetype,
                    $uploadkind === "audio" ? "audio" : "attachment"
                );
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() === "filetoolarge") {
                    throw new moodle_exception("errorfiletoolarge", "mod_ajaxchat");
                }
                throw new moodle_exception("errorinvalidfile", "mod_ajaxchat");
            }
        }

        if ($message === "" && !$attachment) {
            throw new invalid_parameter_exception("Message cannot be empty.");
        }

        $now = time();
        $type = $attachment ? ($attachment["kind"] === "audio" ? "audio" : "attachment") : "text";
        $record = (object)[
            "ajaxchatid" => (int)$this->runtime["chatid"],
            "userid" => $this->userid,
            "type" => $type,
            "message" => $message,
            "filemeta" => $attachment ? json_encode($attachment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            "pollid" => null,
            "timecreated" => $now,
            "timemodified" => 0,
        ];

        $transaction = $DB->start_delegated_transaction();
        try {
            $messageid = (int)$DB->insert_record("ajaxchat_messages", $record);
            $clientmessage = [
                "id" => $messageid,
                "userid" => $this->userid,
                "author" => (string)$this->runtime["fullname"],
                "avatar" => (string)$this->runtime["avatar"],
                "type" => $type,
                "message" => $message,
                "attachment" => $attachment,
                "pollid" => null,
                "timecreated" => $now,
                "timemodified" => 0,
                "deleted" => false,
                "reactionusers" => [],
            ];
            $this->store->write_message($clientmessage);
            $cursor = $this->store->append_event([
                "type" => "message",
                "messageid" => $messageid,
            ]);
            $transaction->allow_commit();
            return ["ok" => true, "message" => $this->store->prepare_message_for_client($clientmessage, $this->userid), "cursor" => $cursor];
        } catch (Throwable $exception) {
            if ($attachment) {
                $this->store->remove_attachment($attachment);
            }
            throw $exception;
        }
    }

    /**
     * edit_message
     *
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     */
    private function edit_message(): array {
        global $DB;

        $this->require_send_permission();
        $this->require_chat_writable();
        $messageid = required_param("messageid", PARAM_INT);
        $text = trim((string)required_param("message", PARAM_RAW));
        $text = core_text::substr($text, 0, 5000);
        if ($text === "") {
            throw new invalid_parameter_exception("Message cannot be empty.");
        }

        $current = $this->require_message($messageid);
        if ((int)$current["userid"] !== $this->userid || time() - (int)$current["timecreated"] > 60) {
            throw new moodle_exception("erroreditwindow", "mod_ajaxchat");
        }
        if (!empty($current["deleted"]) || ($current["type"] ?? "") === "poll") {
            throw new invalid_parameter_exception("Message cannot be edited.");
        }

        $now = time();
        $DB->update_record("ajaxchat_messages", (object)[
            "id" => $messageid,
            "message" => $text,
            "timemodified" => $now,
        ]);

        $updated = $this->store->mutate_message($messageid, function (array $message) use ($text, $now): array {
            $message["message"] = $text;
            $message["timemodified"] = $now;
            return $message;
        });
        $cursor = $this->store->append_event([
            "type" => "edit",
            "messageid" => $messageid,
            "timemodified" => $now,
        ]);

        return ["ok" => true, "message" => $this->store->prepare_message_for_client($updated, $this->userid), "cursor" => $cursor];
    }

    /**
     * delete_message
     *
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws dml_transaction_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     */
    private function delete_message(): array {
        global $DB;

        if (empty($this->runtime["canmanage"])) {
            $this->require_send_permission();
        }
        $messageid = required_param("messageid", PARAM_INT);
        $current = $this->require_message($messageid);
        $canmanage = !empty($this->runtime["canmanage"]);
        $ownwithinwindow = (int)$current["userid"] === $this->userid && time() - (int)$current["timecreated"] <= 60;
        if (!$canmanage && !$ownwithinwindow) {
            throw new moodle_exception("errordeletewindow", "mod_ajaxchat");
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records("ajaxchat_reactions", ["messageid" => $messageid]);
        $deletedpollid = 0;

        if (!empty($current["pollid"])) {
            $pollid = (int)$current["pollid"];
            $deletedpollid = $pollid;
            $DB->delete_records("ajaxchat_poll_votes", ["pollid" => $pollid]);
            $DB->delete_records("ajaxchat_poll_options", ["pollid" => $pollid]);
            $DB->delete_records("ajaxchat_polls", ["id" => $pollid]);
            $this->store->mutate_state(function (array $state) use ($pollid): array {
                unset($state["activepolls"][(string)$pollid]);
                return $state;
            });
            $this->store->remove_poll($pollid);
        }
        $DB->delete_records("ajaxchat_messages", ["id" => $messageid]);

        $this->store->remove_attachment($current["attachment"] ?? null);
        $updated = $this->store->mutate_message($messageid, function (array $message): array {
            $message["message"] = "";
            $message["attachment"] = null;
            $message["reactionusers"] = [];
            $message["pollid"] = null;
            $message["deleted"] = true;
            $message["timemodified"] = time();
            return $message;
        });
        $cursor = $this->store->append_event([
            "type" => "delete",
            "messageid" => $messageid,
            "pollid" => $deletedpollid ?: null,
        ]);
        $transaction->allow_commit();

        return [
            "ok" => true,
            "message" => $this->store->prepare_message_for_client($updated, $this->userid),
            "pollid" => $deletedpollid ?: null,
            "cursor" => $cursor,
        ];
    }

    /**
     * react_to_message
     *
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws dml_transaction_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     */
    private function react_to_message(): array {
        global $DB;

        $this->require_send_permission();
        $this->require_chat_writable();
        $messageid = required_param("messageid", PARAM_INT);
        $reaction = (string)required_param("reaction", PARAM_RAW_TRIMMED);
        $allowed = ["👍", "❤️", "😂", "😮", "😢", "👏"];
        if (!in_array($reaction, $allowed, true)) {
            throw new invalid_parameter_exception("Unsupported reaction.");
        }

        $current = $this->require_message($messageid);
        if (!empty($current["deleted"])) {
            throw new invalid_parameter_exception("Message was deleted.");
        }
        $oldreaction = (string)($current["reactionusers"][(string)$this->userid] ?? "");
        $newreaction = $oldreaction === $reaction ? "" : $reaction;

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records("ajaxchat_reactions", ["messageid" => $messageid, "userid" => $this->userid]);
        if ($newreaction !== "") {
            $DB->insert_record("ajaxchat_reactions", (object)[
                "ajaxchatid" => (int)$this->runtime["chatid"],
                "messageid" => $messageid,
                "userid" => $this->userid,
                "reaction" => $newreaction,
                "timecreated" => time(),
            ]);
        }

        $updated = $this->store->mutate_message($messageid, function (array $message) use ($newreaction): array {
            $message["reactionusers"] = $message["reactionusers"] ?? [];
            if ($newreaction === "") {
                unset($message["reactionusers"][(string)$this->userid]);
            } else {
                $message["reactionusers"][(string)$this->userid] = $newreaction;
            }
            return $message;
        });
        $cursor = $this->store->append_event([
            "type" => "reaction",
            "messageid" => $messageid,
            "userid" => $this->userid,
            "oldreaction" => $oldreaction,
            "reaction" => $newreaction,
        ]);
        $transaction->allow_commit();

        return ["ok" => true, "message" => $this->store->prepare_message_for_client($updated, $this->userid), "cursor" => $cursor];
    }

    /**
     * toggle_chat
     *
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    private function toggle_chat(): array {
        global $DB;

        $this->require_manage_permission();
        $enabled = required_param("enabled", PARAM_BOOL);

        $DB->update_record("ajaxchat", (object)[
            "id" => (int)$this->runtime["chatid"],
            "enabled" => (int)$enabled,
            "timemodified" => time(),
        ]);
        $state = $this->store->merge_state(["enabled" => (bool)$enabled]);
        $cursor = $this->store->append_event([
            "type" => "state",
            "enabled" => (bool)$enabled,
            "updated" => $state["updated"],
        ]);

        return ["ok" => true, "enabled" => (bool)$enabled, "cursor" => $cursor];
    }

    /**
     * block_user
     *
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws dml_transaction_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     */
    private function block_user(): array {
        global $DB;

        $this->require_manage_permission();
        $targetuserid = required_param("userid", PARAM_INT);
        $blocked = required_param("blocked", PARAM_BOOL);
        $participants = array_map("intval", $this->runtime["participantids"] ?? []);
        $blockable = array_map("intval", $this->runtime["blockableids"] ?? []);
        if ($targetuserid === $this->userid || !in_array($targetuserid, $participants, true) ||
            !in_array($targetuserid, $blockable, true)) {
            throw new invalid_parameter_exception("Invalid participant.");
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records("ajaxchat_blocks", ["ajaxchatid" => (int)$this->runtime["chatid"], "userid" => $targetuserid]);
        if ($blocked) {
            $DB->insert_record("ajaxchat_blocks", (object)[
                "ajaxchatid" => (int)$this->runtime["chatid"],
                "userid" => $targetuserid,
                "blockedby" => $this->userid,
                "timecreated" => time(),
            ]);
        }
        $state = $this->store->mutate_state(function (array $state) use ($targetuserid, $blocked): array {
            $state["blocked"] = $state["blocked"] ?? [];
            if ($blocked) {
                $state["blocked"][(string)$targetuserid] = ["by" => $this->userid, "time" => time()];
            } else {
                unset($state["blocked"][(string)$targetuserid]);
            }
            return $state;
        });
        $cursor = $this->store->append_event([
            "type" => "block",
            "userid" => $targetuserid,
            "blocked" => (bool)$blocked,
        ]);
        $transaction->allow_commit();

        return ["ok" => true, "userid" => $targetuserid, "blocked" => (bool)$blocked, "cursor" => $cursor, "updated" => $state["updated"]];
    }

    /**
     * create_poll
     *
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws dml_transaction_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     */
    private function create_poll(): array {
        global $DB;

        $this->require_poll_permission();
        $question = trim((string)required_param("question", PARAM_TEXT));
        $question = core_text::substr($question, 0, 500);
        $position = optional_param("position", "top", PARAM_ALPHA);
        if (!in_array($position, ["top", "side"], true)) {
            $position = "top";
        }
        $rawoptions = optional_param_array("options", [], PARAM_TEXT);
        $options = [];
        foreach ($rawoptions as $option) {
            $option = trim(clean_param((string)$option, PARAM_TEXT));
            if ($option !== "") {
                $options[] = core_text::substr($option, 0, 250);
            }
        }
        $options = array_values(array_unique($options));
        if ($question === "" || count($options) < 2 || count($options) > 10) {
            throw new invalid_parameter_exception("A poll needs a question and between 2 and 10 options.");
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $messageid = (int)$DB->insert_record("ajaxchat_messages", (object)[
            "ajaxchatid" => (int)$this->runtime["chatid"],
            "userid" => $this->userid,
            "type" => "poll",
            "message" => $question,
            "filemeta" => null,
            "pollid" => null,
            "timecreated" => $now,
            "timemodified" => 0,
        ]);
        $pollid = (int)$DB->insert_record("ajaxchat_polls", (object)[
            "ajaxchatid" => (int)$this->runtime["chatid"],
            "messageid" => $messageid,
            "question" => $question,
            "position" => $position,
            "closed" => 0,
            "timecreated" => $now,
            "timeclosed" => 0,
        ]);
        $DB->update_record("ajaxchat_messages", (object)["id" => $messageid, "pollid" => $pollid]);

        $polloptions = [];
        foreach ($options as $sortorder => $optiontext) {
            $optionid = (int)$DB->insert_record("ajaxchat_poll_options", (object)[
                "pollid" => $pollid,
                "optiontext" => $optiontext,
                "sortorder" => $sortorder,
            ]);
            $polloptions[] = ["id" => $optionid, "text" => $optiontext, "sortorder" => $sortorder];
        }

        $poll = [
            "id" => $pollid,
            "messageid" => $messageid,
            "question" => $question,
            "position" => $position,
            "closed" => false,
            "timecreated" => $now,
            "timeclosed" => 0,
            "options" => $polloptions,
            "votes" => [],
        ];
        $message = [
            "id" => $messageid,
            "userid" => $this->userid,
            "author" => (string)$this->runtime["fullname"],
            "avatar" => (string)$this->runtime["avatar"],
            "type" => "poll",
            "message" => $question,
            "attachment" => null,
            "pollid" => $pollid,
            "timecreated" => $now,
            "timemodified" => 0,
            "deleted" => false,
            "reactionusers" => [],
        ];

        $this->store->write_poll($pollid, $poll);
        $this->store->write_message($message);
        $this->store->mutate_state(function (array $state) use ($pollid, $position): array {
            $state["activepolls"] = $state["activepolls"] ?? [];
            $state["activepolls"][(string)$pollid] = $position;
            return $state;
        });
        $clientmessage = $this->store->prepare_message_for_client($message, $this->userid);
        $cursor = $this->store->append_event(["type" => "message", "messageid" => $messageid]);
        $transaction->allow_commit();

        return ["ok" => true, "message" => $clientmessage, "cursor" => $cursor];
    }

    /**
     * vote_poll
     *
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws dml_transaction_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     */
    private function vote_poll(): array {
        global $DB;

        $this->require_send_permission();
        $this->require_chat_writable();
        $pollid = required_param("pollid", PARAM_INT);
        $optionid = required_param("optionid", PARAM_INT);
        $poll = $this->store->get_poll($pollid);
        if (!$poll || !empty($poll["closed"])) {
            throw new invalid_parameter_exception("Poll is closed.");
        }
        $validoption = false;
        foreach ($poll["options"] ?? [] as $option) {
            if ((int)$option["id"] === $optionid) {
                $validoption = true;
                break;
            }
        }
        if (!$validoption) {
            throw new invalid_parameter_exception("Invalid poll option.");
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records("ajaxchat_poll_votes", ["pollid" => $pollid, "userid" => $this->userid]);
        $DB->insert_record("ajaxchat_poll_votes", (object)[
            "pollid" => $pollid,
            "optionid" => $optionid,
            "userid" => $this->userid,
            "timecreated" => time(),
        ]);
        $updated = $this->store->mutate_poll($pollid, function (array $poll) use ($optionid): array {
            $poll["votes"] = $poll["votes"] ?? [];
            $poll["votes"][(string)$this->userid] = $optionid;
            return $poll;
        });
        $cursor = $this->store->append_event(["type" => "poll_changed", "pollid" => $pollid]);
        $transaction->allow_commit();

        return ["ok" => true, "poll" => $this->store->prepare_poll_for_client($updated, $this->userid), "cursor" => $cursor];
    }

    /**
     * close_poll
     *
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     */
    private function close_poll(): array {
        global $DB;

        $this->require_poll_permission();
        $pollid = required_param("pollid", PARAM_INT);
        $poll = $this->store->get_poll($pollid);
        if (!$poll) {
            throw new invalid_parameter_exception("Poll not found.");
        }
        $now = time();
        $DB->update_record("ajaxchat_polls", (object)[
            "id" => $pollid,
            "closed" => 1,
            "timeclosed" => $now,
        ]);
        $updated = $this->store->mutate_poll($pollid, function (array $poll) use ($now): array {
            $poll["closed"] = true;
            $poll["timeclosed"] = $now;
            return $poll;
        });
        $this->store->mutate_state(function (array $state) use ($pollid): array {
            unset($state["activepolls"][(string)$pollid]);
            return $state;
        });
        $cursor = $this->store->append_event(["type" => "poll_changed", "pollid" => $pollid]);

        return ["ok" => true, "poll" => $this->store->prepare_poll_for_client($updated, $this->userid), "cursor" => $cursor];
    }

    /**
     * require_message
     *
     * @param int $messageid
     * @return array
     * @throws invalid_parameter_exception
     */
    private function require_message(int $messageid): array {
        $message = $this->store->get_message($messageid);
        if (!$message || (int)($message["id"] ?? 0) !== $messageid) {
            throw new invalid_parameter_exception("Message not found.");
        }
        return $message;
    }

    /**
     * require_chat_writable
     *
     * @return array
     * @throws moodle_exception
     */
    private function require_chat_writable(): array {
        $state = $this->store->get_state();
        $canmanage = !empty($this->runtime["canmanage"]);
        if (!$canmanage && !empty($state["openfrom"]) && (int)$state["openfrom"] > time()) {
            throw new moodle_exception("errornotavailable", "mod_ajaxchat");
        }
        if (!$canmanage && empty($state["enabled"])) {
            throw new moodle_exception("errorchatdisabled", "mod_ajaxchat");
        }
        if (!$canmanage && !empty($state["blocked"][(string)$this->userid])) {
            throw new moodle_exception("blocked", "mod_ajaxchat");
        }
        return $state;
    }

    /**
     * require_send_permission
     *
     * @return void
     * @throws moodle_exception
     */
    private function require_send_permission(): void {
        if (empty($this->runtime["cansend"])) {
            throw new moodle_exception("nopermissions", "error");
        }
    }

    /**
     * require_manage_permission
     *
     * @return void
     * @throws moodle_exception
     */
    private function require_manage_permission(): void {
        if (empty($this->runtime["canmanage"])) {
            throw new moodle_exception("nopermissions", "error");
        }
    }

    /**
     * require_poll_permission
     *
     * @return void
     * @throws moodle_exception
     */
    private function require_poll_permission(): void {
        if (empty($this->runtime["cancreatepoll"])) {
            throw new moodle_exception("nopermissions", "error");
        }
    }
}
