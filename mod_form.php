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
 * Activity settings form for mod_ajaxchat.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined("MOODLE_INTERNAL") || die();

require_once($CFG->dirroot . "/course/moodleform_mod.php");

/**
 * Class mod_ajaxchat_mod_form.
 */
class mod_ajaxchat_mod_form extends moodleform_mod {
    /**
     * Method definition.
     *
     * @return mixed Return value.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement("header", "general", get_string("general", "form"));
        $mform->addElement("text", "name", get_string("name"), ["size" => 64]);
        $mform->setType("name", PARAM_TEXT);
        $mform->addRule("name", null, "required", null, "client");
        $mform->addRule("name", get_string("maximumchars", "", 255), "maxlength", 255, "client");

        $this->standard_intro_elements();

        $mform->addElement("advcheckbox", "allowaudio", get_string("allowaudio", "mod_ajaxchat"));
        $mform->setDefault("allowaudio", 1);
        $mform->addHelpButton("allowaudio", "allowaudio", "mod_ajaxchat");

        $mform->addElement("advcheckbox", "allowattachments", get_string("allowattachments", "mod_ajaxchat"));
        $mform->setDefault("allowattachments", 1);
        $mform->addHelpButton("allowattachments", "allowattachments", "mod_ajaxchat");

        $mform->addElement("date_time_selector", "openfrom", get_string("openfrom", "mod_ajaxchat"), ["optional" => true]);
        $mform->addHelpButton("openfrom", "openfrom", "mod_ajaxchat");

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }
}
