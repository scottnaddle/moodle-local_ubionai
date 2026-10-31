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
 * Web service function definitions for local_ubionai.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_ubionai_create_module' => [
        'classname' => 'local_ubionai\external\create_module',
        'description' => 'Create an assign, quiz, forum, page, resource, url, workshop, wiki, glossary, feedback, '
            . 'h5pactivity or openmaic activity in a course section.',
        'type' => 'write',
        'capabilities' => 'moodle/course:manageactivities, mod/*:addinstance',
    ],
    'local_ubionai_update_module' => [
        'classname' => 'local_ubionai\external\update_module',
        'description' => 'Update name, intro, visibility, settings, completion and access restrictions of an activity.',
        'type' => 'write',
        'capabilities' => 'moodle/course:manageactivities',
    ],
    'local_ubionai_add_quiz_questions' => [
        'classname' => 'local_ubionai\external\add_quiz_questions',
        'description' => 'Create questions in the quiz question bank and add them to the quiz.',
        'type' => 'write',
        'capabilities' => 'mod/quiz:manage, moodle/question:add',
    ],
    'local_ubionai_update_section' => [
        'classname' => 'local_ubionai\external\update_section',
        'description' => 'Update the name and/or summary of a course section.',
        'type' => 'write',
        'capabilities' => 'moodle/course:update',
    ],
    'local_ubionai_add_sections' => [
        'classname' => 'local_ubionai\external\add_sections',
        'description' => 'Append named sections to a course.',
        'type' => 'write',
        'capabilities' => 'moodle/course:update',
    ],
    'local_ubionai_set_section_availability' => [
        'classname' => 'local_ubionai\external\set_section_availability',
        'description' => 'Set the access restrictions (availability JSON) of a course section.',
        'type' => 'write',
        'capabilities' => 'moodle/course:update',
    ],
    'local_ubionai_set_rubric' => [
        'classname' => 'local_ubionai\external\set_rubric',
        'description' => 'Define or replace the rubric of an activity grading area or of a rubric-strategy workshop.',
        'type' => 'write',
        'capabilities' => 'moodle/grade:managegradingforms, mod/workshop:editdimensions',
    ],
    'local_ubionai_set_grade_categories' => [
        'classname' => 'local_ubionai\external\set_grade_categories',
        'description' => 'Create or reuse weighted grade categories and move activity grade items into them.',
        'type' => 'write',
        'capabilities' => 'moodle/grade:manage',
    ],
    'local_ubionai_create_badge' => [
        'classname' => 'local_ubionai\external\create_badge',
        'description' => 'Create a course badge with activity completion and/or course grade criteria.',
        'type' => 'write',
        'capabilities' => 'moodle/badges:createbadge, moodle/badges:configurecriteria',
    ],
];
