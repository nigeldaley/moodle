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
 * @package moodlecore
 * @subpackage backup-moodle2
 * @copyright 2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define all the restore steps that will be used by the restore_lesson_activity_task
 */

/**
 * Structure step to restore one lesson activity
 */
class restore_lesson_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('lesson', '/activity/lesson');
        $paths[] = new restore_path_element('lesson_page', '/activity/lesson/pages/page');
        $paths[] = new restore_path_element('lesson_answer', '/activity/lesson/pages/page/answers/answer');
        if ($userinfo) {
            $paths[] = new restore_path_element('lesson_attempt', '/activity/lesson/pages/page/answers/answer/attempts/attempt');
            $paths[] = new restore_path_element('lesson_grade', '/activity/lesson/grades/grade');
            $paths[] = new restore_path_element('lesson_branch', '/activity/lesson/pages/page/branches/branch');
            $paths[] = new restore_path_element('lesson_highscore', '/activity/lesson/highscores/highscore');
            $paths[] = new restore_path_element('lesson_timer', '/activity/lesson/timers/timer');
        }

        // Return the paths wrapped into standard activity structure
        return $this->prepare_activity_structure($paths);
    }

    protected function process_lesson($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->available = $this->apply_date_offset($data->available);
        $data->deadline = $this->apply_date_offset($data->deadline);

        // insert the lesson record
        $newitemid = $DB->insert_record('lesson', $data);
        // inmediately after inserting "activity" record, call this
        $this->apply_activity_instance($newitemid);
    }

    protected function process_lesson_page($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->lessonid = $this->get_new_parentid('lesson');
        $data->prevpageid = (empty($data->prevpageid)) ? 0 : $this->get_mappingid('lesson_page', $data->prevpageid);
        $data->nextpageid = 0; //we don't know the id of the next page as it hasn't been created yet.

        $newitemid = $DB->insert_record('lesson_pages', $data);
        $this->set_mapping('lesson_page', $oldid, $newitemid);

        //now update previous page with newid as the nextpageid
        if (!empty($data->prevpageid)) {
            $prevpage = $DB->get_record('lesson_pages', array('lessonid'=>$data->lessonid, 'id'=>$data->prevpageid));
            if (!empty($prevpage)) {
                $prevpage->nextpageid = $newitemid;
                $DB->update_record('lesson_pages', $prevpage);
            }
        }
    }

    protected function process_lesson_answer($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->lessonid = $this->get_new_parentid('lesson');
        $data->pageid = $this->get_new_parentid('lesson_page');
        $data->answer = $data->answer_text;

        $newitemid = $DB->insert_record('lesson_answers', $data);
        $this->set_mapping('lesson_answer', $oldid, $newitemid);
    }

    protected function process_lesson_attempt($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->lessonid = $this->get_new_parentid('lesson');
        $data->pageid = $this->get_new_parentid('lesson_page');
        $data->answerid = $this->get_new_parentid('lesson_answer');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('lesson_attempts', $data);
    }

    protected function process_lesson_grade($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->lessonid = $this->get_new_parentid('lesson');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('lesson_grades', $data);
        $this->set_mapping('lesson_grade', $oldid, $newitemid);
    }

    protected function process_lesson_branch($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->lessonid = $this->get_new_parentid('lesson');
        $data->pageid = $this->get_new_parentid('lesson_page');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('lesson_branch', $data);
        $this->set_mapping('lesson_branch', $oldid, $newitemid);
    }

    protected function process_lesson_highscore($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->lessonid = $this->get_new_parentid('lesson');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->gradeid = $this->get_mappingid('lesson_grade', $data->gradeid);

        $newitemid = $DB->insert_record('lesson_high_scores', $data);
    }

    protected function process_lesson_timer($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->lessonid = $this->get_new_parentid('lesson');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('lesson_timer', $data);
    }

    protected function after_execute() {
        // Add lesson related files, no need to match by itemname (just internally handled context)
        $this->add_related_files('mod_lesson', 'mediafile', 'id');
        $this->add_related_files('mod_lesson', 'page_contents', 'id');
    }
}