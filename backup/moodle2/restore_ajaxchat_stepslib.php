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
 * Restore chat records and reconstruct the file-backed runtime cache.
 *
 * @package mod_ajaxchat
 * @category backup
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Restore the chat activity and reconstruct the file-backed cache.
 */
class restore_ajaxchat_activity_structure_step extends restore_activity_structure_step {
    /** @var int New chat activity ID. */
    private $chatid = 0;

    /**
     * Register XML paths.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [
            new restore_path_element('ajaxchat', '/activity/ajaxchat'),
            new restore_path_element('ajaxchat_message', '/activity/ajaxchat/messages/message'),
            new restore_path_element('ajaxchat_reaction', '/activity/ajaxchat/messages/message/reactions/reaction'),
            new restore_path_element('ajaxchat_poll', '/activity/ajaxchat/polls/poll'),
            new restore_path_element('ajaxchat_option', '/activity/ajaxchat/polls/poll/options/option'),
            new restore_path_element('ajaxchat_vote', '/activity/ajaxchat/polls/poll/votes/vote'),
            new restore_path_element('ajaxchat_block', '/activity/ajaxchat/blocks/block'),
        ];
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the chat settings.
     *
     * @param array $data Record.
     */
    protected function process_ajaxchat($data) {
        global $DB;
        $data = (object)$data;
        $data->course = $this->get_courseid();
        $this->chatid = (int)$DB->insert_record('ajaxchat', $data);
        $this->apply_activity_instance($this->chatid);
        $store = new \mod_ajaxchat\chat_store($this->chatid);
        $store->initialise_state([
            'enabled' => !empty($data->enabled),
            'allowaudio' => !empty($data->allowaudio),
            'allowattachments' => !empty($data->allowattachments),
            'openfrom' => (int)$data->openfrom,
        ]);
    }

    /**
     * Restore a message and remap its file area item ID.
     *
     * @param array $data Record.
     */
    protected function process_ajaxchat_message($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->ajaxchatid = $this->chatid;
        $data->userid = $this->get_mappingid('user', $data->userid, 0);
        $data->pollid = null;
        if (!$data->userid) {
            return;
        }
        $data->id = null;
        $newid = (int)$DB->insert_record('ajaxchat_messages', $data);
        $this->set_mapping('ajaxchat_message', $oldid, $newid, true);
        $attachment = json_decode((string)($data->filemeta ?? ''), true);
        if ($attachment && ctype_digit((string)($attachment['filekey'] ?? ''))) {
            $attachment['filekey'] = (string)$newid;
            $DB->update_record('ajaxchat_messages', (object)[
                'id' => $newid,
                'filemeta' => json_encode($attachment),
            ]);
        }
        $user = $DB->get_record('user', ['id' => $data->userid],
            'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');
        $store = new \mod_ajaxchat\chat_store($this->chatid);
        $store->write_message([
            'id' => $newid,
            'userid' => (int)$data->userid,
            'author' => $user ? fullname($user) : '',
            'avatar' => '',
            'type' => (string)$data->type,
            'message' => (string)$data->message,
            'attachment' => $attachment ?: null,
            'pollid' => null,
            'timecreated' => (int)$data->timecreated,
            'timemodified' => (int)$data->timemodified,
            'deleted' => false,
            'reactionusers' => [],
        ]);
    }

    /**
     * Restore a reaction with user and message mappings.
     *
     * @param array $data Record.
     */
    protected function process_ajaxchat_reaction($data) {
        global $DB;
        $data = (object)$data;
        $data->ajaxchatid = $this->chatid;
        $data->messageid = $this->get_new_parentid('ajaxchat_message');
        $data->userid = $this->get_mappingid('user', $data->userid, 0);
        if (!$data->messageid || !$data->userid) {
            return;
        }
        $DB->insert_record('ajaxchat_reactions', $data);
        $store = new \mod_ajaxchat\chat_store($this->chatid);
        $store->mutate_message((int)$data->messageid, function (array $message) use ($data): array {
            $message['reactionusers'][(string)$data->userid] = $data->reaction;
            return $message;
        });
    }

    /**
     * Restore a poll and its parent message.
     *
     * @param array $data Record.
     */
    protected function process_ajaxchat_poll($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->ajaxchatid = $this->chatid;
        $data->messageid = $this->get_mappingid('ajaxchat_message', $data->messageid, 0);
        if (!$data->messageid) {
            return;
        }
        $newid = (int)$DB->insert_record('ajaxchat_polls', $data);
        $this->set_mapping('ajaxchat_poll', $oldid, $newid);
        $DB->update_record('ajaxchat_messages', (object)['id' => $data->messageid, 'pollid' => $newid]);
        $store = new \mod_ajaxchat\chat_store($this->chatid);
        $store->write_poll($newid, [
            'id' => $newid,
            'messageid' => $data->messageid,
            'question' => $data->question,
            'position' => $data->position,
            'closed' => !empty($data->closed),
            'timecreated' => (int)$data->timecreated,
            'timeclosed' => (int)$data->timeclosed,
            'options' => [],
            'votes' => [],
        ]);
        $store->mutate_message((int)$data->messageid, function (array $message) use ($newid): array {
            $message['pollid'] = $newid;
            return $message;
        });
        if (!$data->closed) {
            $store->mutate_state(function (array $state) use ($newid, $data): array {
                $state['activepolls'][(string)$newid] = $data->position;
                return $state;
            });
        }
    }

    /**
     * Restore a poll option.
     *
     * @param array $data Record.
     */
    protected function process_ajaxchat_option($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->pollid = $this->get_new_parentid('ajaxchat_poll');
        if (!$data->pollid) {
            return;
        }
        $newid = (int)$DB->insert_record('ajaxchat_poll_options', $data);
        $this->set_mapping('ajaxchat_option', $oldid, $newid);
        $store = new \mod_ajaxchat\chat_store($this->chatid);
        $store->mutate_poll((int)$data->pollid, function (array $poll) use ($newid, $data): array {
            $poll['options'][] = ['id' => $newid, 'text' => $data->optiontext, 'sortorder' => $data->sortorder];
            return $poll;
        });
    }

    /**
     * Restore a vote with mapped users, options and polls.
     *
     * @param array $data Record.
     */
    protected function process_ajaxchat_vote($data) {
        global $DB;
        $data = (object)$data;
        $data->pollid = $this->get_new_parentid('ajaxchat_poll');
        $data->optionid = $this->get_mappingid('ajaxchat_option', $data->optionid, 0);
        $data->userid = $this->get_mappingid('user', $data->userid, 0);
        if (!$data->pollid || !$data->optionid || !$data->userid) {
            return;
        }
        $DB->insert_record('ajaxchat_poll_votes', $data);
        $store = new \mod_ajaxchat\chat_store($this->chatid);
        $store->mutate_poll((int)$data->pollid, function (array $poll) use ($data): array {
            $poll['votes'][(string)$data->userid] = (int)$data->optionid;
            return $poll;
        });
    }

    /**
     * Restore chat blocks.
     *
     * @param array $data Record.
     */
    protected function process_ajaxchat_block($data) {
        global $DB;
        $data = (object)$data;
        $data->ajaxchatid = $this->chatid;
        $data->userid = $this->get_mappingid('user', $data->userid, 0);
        $data->blockedby = $this->get_mappingid('user', $data->blockedby, 0);
        if (!$data->userid || !$data->blockedby) {
            return;
        }
        $DB->insert_record('ajaxchat_blocks', $data);
        $store = new \mod_ajaxchat\chat_store($this->chatid);
        $store->mutate_state(function (array $state) use ($data): array {
            $state['blocked'][(string)$data->userid] = [
                'by' => (int)$data->blockedby,
                'time' => (int)$data->timecreated,
            ];
            return $state;
        });
    }

    /**
     * Restore activity files after all record IDs have been mapped.
     */
    protected function after_execute() {
        $this->add_related_files('mod_ajaxchat', 'intro', null);
        $this->add_related_files('mod_ajaxchat', 'attachment', 'ajaxchat_message');
    }
}
