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
 * Backup structure of chat settings and user data.
 *
 * @package mod_ajaxchat
 * @category backup
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

/**
 * Backup the chat activity settings and optionally user-generated content.
 */
class backup_ajaxchat_activity_structure_step extends backup_activity_structure_step {
    /**
     * Create nested backup elements.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $chat = new backup_nested_element('ajaxchat', ['id'], [
            'name', 'intro', 'introformat', 'enabled', 'allowaudio',
            'allowattachments', 'openfrom', 'timecreated', 'timemodified',
        ]);
        $messages = new backup_nested_element('messages');
        $message = new backup_nested_element('message', ['id'], [
            'userid', 'type', 'message', 'filemeta', 'pollid', 'timecreated', 'timemodified',
        ]);
        $reactions = new backup_nested_element('reactions');
        $reaction = new backup_nested_element('reaction', ['id'], ['userid', 'reaction', 'timecreated']);
        $polls = new backup_nested_element('polls');
        $poll = new backup_nested_element('poll', ['id'], [
            'messageid', 'question', 'position', 'closed', 'timecreated', 'timeclosed',
        ]);
        $options = new backup_nested_element('options');
        $option = new backup_nested_element('option', ['id'], ['optiontext', 'sortorder']);
        $votes = new backup_nested_element('votes');
        $vote = new backup_nested_element('vote', ['id'], ['optionid', 'userid', 'timecreated']);
        $blocks = new backup_nested_element('blocks');
        $block = new backup_nested_element('block', ['id'], ['userid', 'blockedby', 'timecreated']);

        $chat->add_child($messages);
        $messages->add_child($message);
        $message->add_child($reactions);
        $reactions->add_child($reaction);
        $chat->add_child($polls);
        $polls->add_child($poll);
        $poll->add_child($options);
        $options->add_child($option);
        $poll->add_child($votes);
        $votes->add_child($vote);
        $chat->add_child($blocks);
        $blocks->add_child($block);

        $chat->set_source_table('ajaxchat', ['id' => backup::VAR_ACTIVITYID]);
        $chat->annotate_files('mod_ajaxchat', 'intro', null);
        if ($this->get_setting_value('userinfo')) {
            $message->set_source_table('ajaxchat_messages', ['ajaxchatid' => backup::VAR_PARENTID], 'id ASC');
            $reaction->set_source_table('ajaxchat_reactions', ['messageid' => backup::VAR_PARENTID], 'id ASC');
            $poll->set_source_table('ajaxchat_polls', ['ajaxchatid' => backup::VAR_PARENTID], 'id ASC');
            $option->set_source_table('ajaxchat_poll_options', ['pollid' => backup::VAR_PARENTID], 'id ASC');
            $vote->set_source_table('ajaxchat_poll_votes', ['pollid' => backup::VAR_PARENTID], 'id ASC');
            $block->set_source_table('ajaxchat_blocks', ['ajaxchatid' => backup::VAR_PARENTID], 'id ASC');

            $message->annotate_ids('user', 'userid');
            $reaction->annotate_ids('user', 'userid');
            $vote->annotate_ids('user', 'userid');
            $block->annotate_ids('user', 'userid');
            $block->annotate_ids('user', 'blockedby');
            $message->annotate_files('mod_ajaxchat', 'attachment', 'id');
        }
        return $this->prepare_activity_structure($chat);
    }
}
