<?php
/**
 * Privacy provider for mod_ajaxchat.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ajaxchat\privacy;

defined("MOODLE_INTERNAL") || die();

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_ajaxchat\chat_store;

/**
 * Class provider
 */
class provider implements
    \core_privacy\local\metadata\provider,
    core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * get_metadata
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table("ajaxchat_messages", [
            "userid" => "privacy:metadata:messages:userid",
            "message" => "privacy:metadata:messages:message",
            "filemeta" => "privacy:metadata:messages:filemeta",
            "timecreated" => "privacy:metadata:messages:timecreated",
        ], "privacy:metadata:messages");
        $collection->add_database_table("ajaxchat_reactions", [
            "userid" => "privacy:metadata:messages:userid",
            "reaction" => "privacy:metadata:reactions",
            "timecreated" => "privacy:metadata:messages:timecreated",
        ], "privacy:metadata:reactions");
        $collection->add_database_table("ajaxchat_poll_votes", [
            "userid" => "privacy:metadata:messages:userid",
            "optionid" => "privacy:metadata:pollvotes",
            "timecreated" => "privacy:metadata:messages:timecreated",
        ], "privacy:metadata:pollvotes");
        $collection->add_database_table("ajaxchat_blocks", [
            "userid" => "privacy:metadata:messages:userid",
            "blockedby" => "privacy:metadata:blocks",
            "timecreated" => "privacy:metadata:messages:timecreated",
        ], "privacy:metadata:blocks");
        return $collection;
    }

    /**
     * get_contexts_for_userid
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $params = [
            "contextlevel" => CONTEXT_MODULE,
            "modname" => "ajaxchat",
            "userid1" => $userid,
            "userid2" => $userid,
            "userid3" => $userid,
            "userid4" => $userid,
            "userid5" => $userid,
        ];
        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                  JOIN {modules} md ON md.id = cm.module AND md.name = :modname
             LEFT JOIN {ajaxchat_messages} m ON m.ajaxchatid = cm.instance AND m.userid = :userid1
             LEFT JOIN {ajaxchat_reactions} r ON r.ajaxchatid = cm.instance AND r.userid = :userid2
             LEFT JOIN {ajaxchat_blocks} b ON b.ajaxchatid = cm.instance
                 AND (b.userid = :userid3 OR b.blockedby = :userid4)
             LEFT JOIN {ajaxchat_polls} p ON p.ajaxchatid = cm.instance
             LEFT JOIN {ajaxchat_poll_votes} v ON v.pollid = p.id AND v.userid = :userid5
                 WHERE ctx.contextlevel = :contextlevel
                   AND (m.id IS NOT NULL OR r.id IS NOT NULL OR b.id IS NOT NULL OR v.id IS NOT NULL)";
        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    /**
     * export_user_data
     *
     * @param approved_contextlist $contextlist
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id("ajaxchat", $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }

            $messages = $DB->get_records("ajaxchat_messages", ["ajaxchatid" => $cm->instance, "userid" => $userid], "timecreated ASC");
            $reactions = $DB->get_records("ajaxchat_reactions", ["ajaxchatid" => $cm->instance, "userid" => $userid], "timecreated ASC");
            $pollids = $DB->get_fieldset_select("ajaxchat_polls", "id", "ajaxchatid = :chatid", ["chatid" => $cm->instance]);
            $votes = [];
            if ($pollids) {
                [$insql, $inparams] = $DB->get_in_or_equal($pollids, SQL_PARAMS_NAMED, "poll");
                $inparams["userid"] = $userid;
                $votes = $DB->get_records_select("ajaxchat_poll_votes", "userid = :userid AND pollid {$insql}", $inparams, "timecreated ASC");
            }
            $blocks = $DB->get_records_select(
                "ajaxchat_blocks",
                "ajaxchatid = :chatid AND (userid = :userid1 OR blockedby = :userid2)",
                ["chatid" => $cm->instance, "userid1" => $userid, "userid2" => $userid],
                "timecreated ASC"
            );

            writer::with_context($context)->export_data([], (object)[
                "messages" => array_values($messages),
                "reactions" => array_values($reactions),
                "pollvotes" => array_values($votes),
                "blocks" => array_values($blocks),
            ]);
        }
    }

    /**
     * delete_data_for_all_users_in_context
     *
     * @param context $context
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if (!$context instanceof context_module) {
            return;
        }
        $cm = get_coursemodule_from_id("ajaxchat", $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $pollids = $DB->get_fieldset_select("ajaxchat_polls", "id", "ajaxchatid = :chatid", ["chatid" => $cm->instance]);
        if ($pollids) {
            [$insql, $params] = $DB->get_in_or_equal($pollids, SQL_PARAMS_NAMED, "poll");
            $DB->delete_records_select("ajaxchat_poll_votes", "pollid {$insql}", $params);
            $DB->delete_records_select("ajaxchat_poll_options", "pollid {$insql}", $params);
        }
        $DB->delete_records("ajaxchat_polls", ["ajaxchatid" => $cm->instance]);
        $DB->delete_records("ajaxchat_reactions", ["ajaxchatid" => $cm->instance]);
        $DB->delete_records("ajaxchat_blocks", ["ajaxchatid" => $cm->instance]);
        $DB->delete_records("ajaxchat_messages", ["ajaxchatid" => $cm->instance]);

        chat_store::remove_instance_directory((int)$cm->instance);
        $store = new chat_store((int)$cm->instance);
        $store->initialise_state([]);
    }

    /**
     * delete_data_for_user
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            self::delete_user_from_context($userid, $context);
        }
    }

    /**
     * get_users_in_context
     *
     * @param userlist $userlist
     * @return void
     * @throws \coding_exception
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }
        $cm = get_coursemodule_from_id("ajaxchat", $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $params = [
            "chatid1" => $cm->instance,
            "chatid2" => $cm->instance,
            "chatid3" => $cm->instance,
            "chatid4" => $cm->instance,
            "chatid5" => $cm->instance,
        ];
        $sql = "SELECT userid FROM {ajaxchat_messages} WHERE ajaxchatid = :chatid1
                UNION SELECT userid FROM {ajaxchat_reactions} WHERE ajaxchatid = :chatid2
                UNION SELECT userid FROM {ajaxchat_blocks} WHERE ajaxchatid = :chatid3
                UNION SELECT blockedby AS userid FROM {ajaxchat_blocks} WHERE ajaxchatid = :chatid4
                UNION SELECT v.userid
                        FROM {ajaxchat_poll_votes} v
                        JOIN {ajaxchat_polls} p ON p.id = v.pollid
                       WHERE p.ajaxchatid = :chatid5";
        $userlist->add_from_sql("userid", $sql, $params);
    }

    /**
     * delete_data_for_users
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user_from_context((int)$userid, $context);
        }
    }

    /**
     * delete_user_from_context
     *
     * @param int $userid
     * @param context $context
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private static function delete_user_from_context(int $userid, context $context): void {
        global $DB;

        if (!$context instanceof context_module) {
            return;
        }
        $cm = get_coursemodule_from_id("ajaxchat", $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $chatid = (int)$cm->instance;
        $store = new chat_store($chatid);

        $messageids = $DB->get_fieldset_select(
            "ajaxchat_messages",
            "id",
            "ajaxchatid = :chatid AND userid = :userid",
            ["chatid" => $chatid, "userid" => $userid]
        );
        $createdpollids = [];
        foreach ($messageids as $messageid) {
            $message = $store->get_message((int)$messageid);
            if ($message) {
                if (!empty($message["pollid"])) {
                    $createdpollids[] = (int)$message["pollid"];
                }
                $store->remove_attachment($message["attachment"] ?? null);
                $store->mutate_message((int)$messageid, function (array $current): array {
                    $current["message"] = "";
                    $current["attachment"] = null;
                    $current["reactionusers"] = [];
                    $current["pollid"] = null;
                    $current["deleted"] = true;
                    $current["author"] = "";
                    $current["avatar"] = "";
                    return $current;
                });
            }
        }

        $reactionmessageids = $DB->get_fieldset_select(
            "ajaxchat_reactions",
            "messageid",
            "ajaxchatid = :chatid AND userid = :userid",
            ["chatid" => $chatid, "userid" => $userid]
        );
        foreach ($reactionmessageids as $messageid) {
            if ($store->get_message((int)$messageid)) {
                $store->mutate_message((int)$messageid, function (array $current) use ($userid): array {
                    unset($current["reactionusers"][(string)$userid]);
                    return $current;
                });
            }
        }

        $createdpollids = array_values(array_unique(array_filter(array_map("intval", $createdpollids))));
        if ($createdpollids) {
            [$createdinsql, $createdparams] = $DB->get_in_or_equal($createdpollids, SQL_PARAMS_NAMED, "createdpoll");
            $DB->delete_records_select("ajaxchat_poll_votes", "pollid {$createdinsql}", $createdparams);
            $DB->delete_records_select("ajaxchat_poll_options", "pollid {$createdinsql}", $createdparams);
            $DB->delete_records_select("ajaxchat_polls", "id {$createdinsql}", $createdparams);
            foreach ($createdpollids as $createdpollid) {
                $store->remove_poll($createdpollid);
            }
            $store->mutate_state(function (array $state) use ($createdpollids): array {
                foreach ($createdpollids as $createdpollid) {
                    unset($state["activepolls"][(string)$createdpollid]);
                }
                return $state;
            });
        }

        $pollids = $DB->get_fieldset_select("ajaxchat_polls", "id", "ajaxchatid = :chatid", ["chatid" => $chatid]);
        if ($pollids) {
            [$insql, $params] = $DB->get_in_or_equal($pollids, SQL_PARAMS_NAMED, "poll");
            $params["userid"] = $userid;
            $votepollids = $DB->get_fieldset_select("ajaxchat_poll_votes", "pollid", "userid = :userid AND pollid {$insql}", $params);
            foreach ($votepollids as $pollid) {
                if ($store->get_poll((int)$pollid)) {
                    $store->mutate_poll((int)$pollid, function (array $poll) use ($userid): array {
                        unset($poll["votes"][(string)$userid]);
                        return $poll;
                    });
                }
            }
            $DB->delete_records_select("ajaxchat_poll_votes", "userid = :userid AND pollid {$insql}", $params);
        }

        $DB->delete_records("ajaxchat_reactions", ["ajaxchatid" => $chatid, "userid" => $userid]);
        $DB->delete_records("ajaxchat_messages", ["ajaxchatid" => $chatid, "userid" => $userid]);
        $DB->delete_records_select(
            "ajaxchat_blocks",
            "ajaxchatid = :chatid AND (userid = :userid1 OR blockedby = :userid2)",
            ["chatid" => $chatid, "userid1" => $userid, "userid2" => $userid]
        );

        $store->mutate_state(function (array $state) use ($userid): array {
            unset($state["blocked"][(string)$userid]);
            foreach ($state["blocked"] ?? [] as $blockeduserid => $info) {
                if ((int)($info["by"] ?? 0) === $userid) {
                    unset($state["blocked"][$blockeduserid]);
                }
            }
            return $state;
        });
    }
}
