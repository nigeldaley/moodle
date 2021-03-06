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
 * Library of functions and constants for module glossary
 * outside of what is required for the core moodle api
 *
 * @package   mod-glossary
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->libdir . '/portfolio/caller.php');

/**
 * class to handle exporting an entire glossary database
 */
class glossary_full_portfolio_caller extends portfolio_module_caller_base {

    private $glossary;
    private $exportdata;
    private $keyedfiles = array(); // keyed on entry

    /**
     * return array of expected call back arguments
     * and whether they are required or not
     *
     * @return array
     */
    public static function expected_callbackargs() {
        return array(
            'id' => true,
        );
    }

    /**
     * load up all data required for this export.
     *
     * @return void
     */
    public function load_data() {
        global $DB;
        if (!$this->cm = get_coursemodule_from_id('glossary', $this->id)) {
            throw new portfolio_caller_exception('invalidid', 'glossary');
        }
        if (!$this->glossary = $DB->get_record('glossary', array('id' => $this->cm->instance))) {
            throw new portfolio_caller_exception('invalidid', 'glossary');
        }
        $entries = $DB->get_records('glossary_entries', array('glossaryid' => $this->glossary->id));
        list($where, $params) = $DB->get_in_or_equal(array_keys($entries));

        $aliases = $DB->get_records_select('glossary_alias', 'entryid ' . $where, $params);
        $categoryentries = $DB->get_records_sql('SELECT ec.entryid, c.name FROM {glossary_entries_categories} ec
            JOIN {glossary_categories} c
            ON c.id = ec.categoryid
            WHERE ec.entryid ' . $where, $params);

        $this->exportdata = array('entries' => $entries, 'aliases' => $aliases, 'categoryentries' => $categoryentries);
        $fs = get_file_storage();
        $context = get_context_instance(CONTEXT_MODULE, $this->cm->id);
        $this->multifiles = array();
        foreach (array_keys($entries) as $entry) {
            $this->keyedfiles[$entry] = array_merge(
                $fs->get_area_files($context->id, 'mod_glossary', 'attachment', $entry, "timemodified", false),
                $fs->get_area_files($context->id, 'mod_glossary', 'entry', $entry, "timemodified", false)
            );
            $this->multifiles = array_merge($this->multifiles, $this->keyedfiles[$entry]);
        }
    }

    /**
     * how long might we expect this export to take
     *
     * @return constant one of PORTFOLIO_TIME_XX
     */
    public function expected_time() {
        $filetime = portfolio_expected_time_file($this->multifiles);
        $dbtime   = portfolio_expected_time_db(count($this->exportdata['entries']));
        return ($filetime > $dbtime) ? $filetime : $dbtime;
    }

    /**
     * return the sha1 of this content
     *
     * @return string
     */
    public function get_sha1() {
        $file = '';
        if ($this->multifiles) {
            $file = $this->get_sha1_file();
        }
        return sha1(serialize($this->exportdata) . $file);
    }

    /**
     * prepare the package ready to be passed off to the portfolio plugin
     *
     * @return void
     */
    public function prepare_package() {
        $entries = $this->exportdata['entries'];
        $aliases = array();
        $categories = array();
        if (is_array($this->exportdata['aliases'])) {
            foreach ($this->exportdata['aliases'] as $alias) {
                if (!array_key_exists($alias->entryid, $aliases)) {
                    $aliases[$alias->entryid] = array();
                }
                $aliases[$alias->entryid][] = $alias->alias;
            }
        }
        if (is_array($this->exportdata['categoryentries'])) {
            foreach ($this->exportdata['categoryentries'] as $cat) {
                if (!array_key_exists($cat->entryid, $categories)) {
                    $categories[$cat->entryid] = array();
                }
                $categories[$cat->entryid][] = $cat->name;
            }
        }
        if ($this->get('exporter')->get('formatclass') == PORTFOLIO_FORMAT_SPREADSHEET) {
            $csv = glossary_generate_export_csv($entries, $aliases, $categories);
            $this->exporter->write_new_file($csv, clean_filename($this->cm->name) . '.csv', false);
            return;
        } else if ($this->get('exporter')->get('formatclass') == PORTFOLIO_FORMAT_LEAP2A) {
            $ids = array(); // keep track of these to make into a selection later
            global $USER, $DB;
            $writer = $this->get('exporter')->get('format')->leap2a_writer($USER);
            $format = $this->exporter->get('format');
            $filename = $this->get('exporter')->get('format')->manifest_name();
            foreach ($entries as $e) {
                $content = glossary_entry_portfolio_caller::entry_content(
                    $this->course,
                    $this->cm,
                    $this->glossary,
                    $e,
                    (array_key_exists($e->id, $aliases) ? $aliases[$e->id] : array()),
                    $format
                );
                $entry = new portfolio_format_leap2a_entry('glossaryentry' . $e->id, $e->concept, 'entry', $content);
                $entry->author    = $DB->get_record('user', array('id' => $e->userid), 'id,firstname,lastname,email');
                $entry->published = $e->timecreated;
                $entry->updated   = $e->timemodified;
                if (!empty($this->keyedfiles[$e->id])) {
                    $writer->link_files($entry, $this->keyedfiles[$e->id], 'glossaryentry' . $e->id . 'file');
                    foreach ($this->keyedfiles[$e->id] as $file) {
                        $this->exporter->copy_existing_file($file);
                    }
                }
                if (!empty($categories[$e->id])) {
                    foreach ($categories[$e->id] as $cat) {
                        // this essentially treats them as plain tags
                        // leap has the idea of category schemes
                        // but I think this is overkill here
                        $entry->add_category($cat);
                    }
                }
                $writer->add_entry($entry);
                $ids[] = $entry->id;
            }
            $selection = new portfolio_format_leap2a_entry('wholeglossary' . $this->glossary->id, get_string('modulename', 'glossary'), 'selection');
            $writer->add_entry($selection);
            $writer->make_selection($selection, $ids, 'Grouping');
            $content = $writer->to_xml();
        }
        $this->exporter->write_new_file($content, $filename, true);
    }

    /**
     * make sure that the current user is allowed to do this
     *
     * @return boolean
     */
    public function check_permissions() {
        return has_capability('mod/glossary:export', get_context_instance(CONTEXT_MODULE, $this->cm->id));
    }

    /**
     * return a nice name to be displayed about this export location
     *
     * @return string
     */
    public static function display_name() {
        return get_string('modulename', 'glossary');
    }

    /**
     * what formats this function *generally* supports
     *
     * @return array
     */
    public static function base_supported_formats() {
        return array(PORTFOLIO_FORMAT_SPREADSHEET, PORTFOLIO_FORMAT_LEAP2A);
    }
}

/**
 * class to export a single glossary entry
 *
 * @package   mod-glossary
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class glossary_entry_portfolio_caller extends portfolio_module_caller_base {

    private $glossary;
    private $entry;
    protected $entryid;
    /*
     * @return array
     */
    public static function expected_callbackargs() {
        return array(
            'entryid' => true,
            'id'      => true,
        );
    }

    /**
     * load up all data required for this export.
     *
     * @return void
     */
    public function load_data() {
        global $DB;
        if (!$this->cm = get_coursemodule_from_id('glossary', $this->id)) {
            throw new portfolio_caller_exception('invalidid', 'glossary');
        }
        if (!$this->glossary = $DB->get_record('glossary', array('id' => $this->cm->instance))) {
            throw new portfolio_caller_exception('invalidid', 'glossary');
        }
        if ($this->entryid) {
            if (!$this->entry = $DB->get_record('glossary_entries', array('id' => $this->entryid))) {
                throw new portfolio_caller_exception('noentry', 'glossary');
            }
            // in case we don't have USER this will make the entry be printed
            $this->entry->approved = true;
        }
        $this->categories = $DB->get_records_sql('SELECT ec.entryid, c.name FROM {glossary_entries_categories} ec
            JOIN {glossary_categories} c
            ON c.id = ec.categoryid
            WHERE ec.entryid = ?', array($this->entryid));
        $context = get_context_instance(CONTEXT_MODULE, $this->cm->id);
        if ($this->entry->sourceglossaryid == $this->cm->instance) {
            if ($maincm = get_coursemodule_from_instance('glossary', $this->entry->glossaryid)) {
                $context = get_context_instance(CONTEXT_MODULE, $maincm->id);
            }
        }
        $this->aliases = $DB->get_record('glossary_alias', array('entryid'=>$this->entryid));
        $fs = get_file_storage();
        $this->multifiles = array_merge(
            $fs->get_area_files($context->id, 'mod_glossary', 'attachment', $this->entry->id, "timemodified", false),
            $fs->get_area_files($context->id, 'mod_glossary', 'entry', $this->entry->id, "timemodified", false)
        );
    }

    /**
     * how long might we expect this export to take
     *
     * @return constant one of PORTFOLIO_TIME_XX
     */
    public function expected_time() {
        return PORTFOLIO_TIME_LOW;
    }

    /**
     * make sure that the current user is allowed to do this
     *
     * @return boolean
     */
    public function check_permissions() {
        $context = get_context_instance(CONTEXT_MODULE, $this->cm->id);
        return has_capability('mod/glossary:exportentry', $context)
            || ($this->entry->userid == $this->user->id && has_capability('mod/glossary:exportownentry', $context));
    }

    /**
     * return a nice name to be displayed about this export location
     *
     * @return string
     */
    public static function display_name() {
        return get_string('modulename', 'glossary');
    }

    /**
     * prepare the package ready to be passed off to the portfolio plugin
     *
     * @return void
     */
    public function prepare_package() {
        global $DB;

        $format = $this->exporter->get('format');
        $content = self::entry_content($this->course, $this->cm, $this->glossary, $this->entry, $this->aliases, $format);

        if ($this->exporter->get('formatclass') === PORTFOLIO_FORMAT_PLAINHTML) {
            $filename = clean_filename($this->entry->concept) . '.html';
            $this->exporter->write_new_file($content, $filename);

        } else if ($this->exporter->get('formatclass') === PORTFOLIO_FORMAT_RICHHTML) {
            if ($this->multifiles) {
                foreach ($this->multifiles as $file) {
                    $this->exporter->copy_existing_file($file);
                }
            }
            $filename = clean_filename($this->entry->concept) . '.html';
            $this->exporter->write_new_file($content, $filename);

        } else if ($this->exporter->get('formatclass') === PORTFOLIO_FORMAT_LEAP2A) {
            $writer = $this->get('exporter')->get('format')->leap2a_writer();
            $entry = new portfolio_format_leap2a_entry('glossaryentry' . $this->entry->id, $this->entry->concept, 'entry', $content);
            $entry->author = $DB->get_record('user', array('id' => $this->entry->userid), 'id,firstname,lastname,email');
            $entry->published = $this->entry->timecreated;
            $entry->updated = $this->entry->timemodified;
            if ($this->multifiles) {
                $writer->link_files($entry, $this->multifiles);
                foreach ($this->multifiles as $file) {
                    $this->exporter->copy_existing_file($file);
                }
            }
            if ($this->categories) {
                foreach ($this->categories as $cat) {
                    // this essentially treats them as plain tags
                    // leap has the idea of category schemes
                    // but I think this is overkill here
                    $entry->add_category($cat->name);
                }
            }
            $writer->add_entry($entry);
            $content = $writer->to_xml();
            $filename = $this->get('exporter')->get('format')->manifest_name();
            $this->exporter->write_new_file($content, $filename);

        } else {
            throw new portfolio_caller_exception('unexpected_format_class', 'glossary');
        }
    }

    /**
     * return the sha1 of this content
     *
     * @return string
     */
    public function get_sha1() {
        if ($this->multifiles) {
            return sha1(serialize($this->entry) . $this->get_sha1_file());
        }
        return sha1(serialize($this->entry));
    }

    /**
     * what formats this function *generally* supports
     *
     * @return array
     */
    public static function base_supported_formats() {
        return array(PORTFOLIO_FORMAT_RICHHTML, PORTFOLIO_FORMAT_PLAINHTML, PORTFOLIO_FORMAT_LEAP2A);
    }

    /**
     * helper function to get the html content of an entry
     * for both this class and the full glossary exporter
     * this is a very simplified version of the dictionary format output,
     * but with its 500 levels of indirection removed
     * and file rewriting handled by the portfolio export format.
     *
     * @param stdclass $course
     * @param stdclass $cm
     * @param stdclass $glossary
     * @param stdclass $entry
     *
     * @return string
     */
    public static function entry_content($course, $cm, $glossary, $entry, $aliases, $format) {
        global $OUTPUT, $DB;
        $entry = clone $entry;
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
        $options = portfolio_format_text_options();
        $options->trusted = $entry->definitiontrust;
        $options->context = $context;

        $output = '<table class="glossarypost dictionary" cellspacing="0">' . "\n";
        $output .= '<tr valign="top">' . "\n";
        $output .= '<td class="entry">' . "\n";

        $output .= '<div class="concept">';
        $output .= format_text($OUTPUT->heading($entry->concept, 3), FORMAT_MOODLE, $options);
        $output .= '</div> ' . "\n";

        $entry->definition = format_text($entry->definition, $entry->definitionformat, $options);
        $output .= portfolio_rewrite_pluginfile_urls($entry->definition, $context->id, 'mod_glossary', 'entry', $entry->id, $format);

        if (isset($entry->footer)) {
            $output .= $entry->footer;
        }

        $output .= '</td></tr>' . "\n";

        if (!empty($aliases)) {
            $aliases = explode(',', $aliases->alias);
            $output .= '<tr valign="top"><td class="entrylowersection">';
            $key = (count($aliases) == 1) ? 'alias' : 'aliases';
            $output .= get_string($key, 'glossary') . ': ';
            foreach ($aliases as $alias) {
                $output .= s($alias) . ',';
            }
            $output = substr($output, 0, -1);
            $output .= '</td></tr>' . "\n";
        }

        if ($entry->sourceglossaryid == $cm->instance) {
            if (!$maincm = get_coursemodule_from_instance('glossary', $entry->glossaryid)) {
                return '';
            }
            $filecontext = get_context_instance(CONTEXT_MODULE, $maincm->id);

        } else {
            $filecontext = $context;
        }
        $fs = get_file_storage();
        if ($files = $fs->get_area_files($filecontext->id, 'mod_glossary', 'attachment', $entry->id, "timemodified", false)) {
            $output .= '<table border="0" width="100%"><tr><td>' . "\n";

            foreach ($files as $file) {
                $output .= $format->file_output($file);
            }
            $output .= '</td></tr></table>' . "\n";
        }

        $output .= '</table>' . "\n";

        return $output;
    }
}

