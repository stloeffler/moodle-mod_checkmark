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
 * This page handles listing of checkmark overrides
 *
 * @package   mod_checkmark
 * @author    Daniel Binder based on assign/overrides.php by Ilya Tregubov
 * @copyright 2020 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(__FILE__) . '/../../config.php');


$cmid = required_param('id', PARAM_INT);
$mode = optional_param('mode', '', PARAM_ALPHA); // One of 'user' or 'group', default is 'group'.

$action   = optional_param('action', '', PARAM_ALPHA);
$redirect = $CFG->wwwroot.'/mod/checkmark/overrides.php?id=' . $cmid . '&amp;mode=group';

list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'checkmark');
$checkmark = $DB->get_record('checkmark', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);

// Check the user has the required capabilities to list overrides.
require_capability('mod/checkmark:manageoverrides', $context);

$cmgroupmode = groups_get_activity_groupmode($cm);
$accessallgroups = ($cmgroupmode == NOGROUPS) || has_capability('moodle/site:accessallgroups', $context);

$overridecountgroup = $DB->count_records('checkmark_overrides', array('userid' => null, 'checkmarkid' => $cm->id));

// Get the course groups that the current user can access.
$groups = $accessallgroups ? groups_get_all_groups($cm->course) : groups_get_activity_allowed_groups($cm);

// Default mode is "group", unless there are no groups.
if ($mode != "user" and $mode != "group") {
    if (!empty($groups)) {
        $mode = "group";
    } else {
        $mode = "user";
    }
}
$groupmode = ($mode == "group");

$url = new moodle_url('/mod/checkmark/overrides.php', array('id' => $cm->id, 'mode' => $mode));

$PAGE->set_url($url);

if ($action == 'movegroupoverride') {
    $id = required_param('id', PARAM_INT);
    $dir = required_param('dir', PARAM_ALPHA);

    if (confirm_sesskey()) {
        move_group_override($id, $dir, $cm->id);
    }
    redirect($redirect);
}

// Display a list of overrides.
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('overrides', 'assign'));
$PAGE->navbar->add(get_string('useroverrides', 'assign'));
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($cm->name, true, array('context' => $context)));

// Delete orphaned group overrides.
/*
$sql = 'SELECT o.id
          FROM {checkmark_overrides} o
     LEFT JOIN {groups} g ON o.groupid = g.id
         WHERE o.groupid IS NOT NULL
               AND g.id IS NULL
               AND o.checkmarkid = ?';
$params = array($cm->id);
$orphaned = $DB->get_records_sql($sql, $params);
if (!empty($orphaned)) {
    $DB->delete_records_list('checkmark_overrides', 'id', array_keys($orphaned));
}
*/
$overrides = [];

// Fetch all overrides.
if ($groupmode) {
    $colname = get_string('group');
    // To filter the result by the list of groups that the current user has access to.
    if ($groups) {
        $params = ['checkmarkid' => $cm->id];
        list($insql, $inparams) = $DB->get_in_or_equal(array_keys($groups), SQL_PARAMS_NAMED);
        $params += $inparams;

        $sql = "SELECT o.*, g.name
                  FROM {checkmark_overrides} o
                  JOIN {groups} g ON o.groupid = g.id
                 WHERE o.checkmarkid = :checkmarkid AND g.id $insql
              ORDER BY o.sortorder";

        $overrides = $DB->get_records_sql($sql, $params);
    }
} else {
    $colname = get_string('user');
    list($sort, $params) = users_order_by_sql('u');
    $params['checkmarkid'] = $cm->instance;

    if ($accessallgroups) {
        $sql = 'SELECT o.*, ' . get_all_user_name_fields(true, 'u') . '
                  FROM {checkmark_overrides} o
                  JOIN {user} u ON o.userid = u.id
                 WHERE o.checkmarkid = :checkmarkid
              ORDER BY ' . $sort;

        $overrides = $DB->get_records_sql($sql, $params);
    } else if ($groups) {
        list($insql, $inparams) = $DB->get_in_or_equal(array_keys($groups), SQL_PARAMS_NAMED);
        $params += $inparams;

        $sql = 'SELECT o.*, ' . get_all_user_name_fields(true, 'u') . '
                  FROM {checkmark_overrides} o
                  JOIN {user} u ON o.userid = u.id
                  JOIN {groups_members} gm ON u.id = gm.userid
                 WHERE o.checkmarkid = :checkmarkid AND gm.groupid ' . $insql . '
              ORDER BY ' . $sort;

        $overrides = $DB->get_records_sql($sql, $params);
    }
}

// Initialise table.
$table = new html_table();
$table->headspan = array(1, 2, 1);
$table->colclasses = array('colname', 'colsetting', 'colvalue', 'colaction');
$table->head = array(
        $colname,
        get_string('overrides', 'assign'),
        get_string('action'),
);

$userurl = new moodle_url('/user/view.php', array());
$groupurl = new moodle_url('/group/overview.php', array('id' => $cm->course));

$overrideediturl = new moodle_url('/mod/checkmark/extend.php');

$hasinactive = false; // Whether there are any inactive overrides.

foreach ($overrides as $override) {

    $fields = array();
    $values = array();
    $active = true;

    // Check for inactive overrides.
    if (!$groupmode) {
        if (!is_enrolled($context, $override->userid)) {
            // User not enrolled.
            $active = false;
        } else if (!\core_availability\info_module::is_user_visible($cm, $override->userid)) {
            // User cannot access the module.
            $active = false;
        }
    }

    // Format allowsubmissionsfromdate.
    if (isset($override->timeavailable) && $override->timeavailable != $checkmark->timeavailable) {
        $fields[] = get_string('open', 'checkmark');
        $values[] = $override->timeavailable > 0 ? userdate($override->timeavailable) : get_string('noopen',
                'checkmark');
    }

    // Format duedate.
    if (isset($override->timedue) && $override->timedue != $checkmark->timedue) {
        $fields[] = get_string('duedate', 'checkmark');
        $values[] = $override->timedue > 0 ? userdate($override->timedue) : get_string('noclose', 'checkmark');
    }

    // Format cutoffdate.
    if (isset($override->cutoffdate) && $override->cutoffdate != $checkmark->cutoffdate) {
        $fields[] = get_string('cutoffdate', 'checkmark');
        $values[] = $override->cutoffdate > 0 ? userdate($override->cutoffdate) : get_string('noclose', 'checkmark');
    }

    // Icons.
    $iconstr = '';

    if ($active) {
        // Edit.
        $editurlstr = $overrideediturl->out(true, array('id' => $cmid, 'type' => \mod_checkmark\overrideform::USER,
                'mode' => \mod_checkmark\overrideform::EDIT, 'users' => $override->userid));
        $iconstr = '<a title="' . get_string('edit') . '" href="'. $editurlstr . '">' .
                $OUTPUT->pix_icon('t/edit', get_string('edit')) . '</a> ';
        // Duplicate.
        $copyurlstr = $overrideediturl->out(true, array('id' => $cmid, 'type' => \mod_checkmark\overrideform::USER,
                'mode' => \mod_checkmark\overrideform::COPY, 'users' => $override->userid));
        $iconstr .= '<a title="' . get_string('copy') . '" href="' . $copyurlstr . '">' .
                $OUTPUT->pix_icon('t/copy', get_string('copy')) . '</a> ';
    }
    // Delete.
    $deleteurlstr = $overrideediturl->out(true, array('id' => $cmid, 'type' => \mod_checkmark\overrideform::USER,
            'mode' => \mod_checkmark\overrideform::DELETE, 'users' => $override->userid));
    $iconstr .= '<a title="' . get_string('delete') . '" href="' . $deleteurlstr . '">' .
            $OUTPUT->pix_icon('t/delete', get_string('delete')) . '</a> ';

    if ($groupmode) {
        $usergroupstr = '<a href="' . $groupurl->out(true,
                        array('group' => $override->groupid)) . '" >' . $override->name . '</a>';

        // Move up.
        if ($override->sortorder > 1) {
            $iconstr .= '<a title="'.get_string('moveup').'" href="overrides.php?cmid=' . $cmid .
                    '&amp;id=' . $override->id .'&amp;action=movegroupoverride&amp;dir=up&amp;sesskey='.sesskey().'">' .
                    $OUTPUT->pix_icon('t/up', get_string('moveup')) . '</a> ';
        } else {
            $iconstr .= $OUTPUT->spacer() . ' ';
        }

        // Move down.
        if ($override->sortorder < $overridecountgroup) {
            $iconstr .= '<a title="'.get_string('movedown').'" href="overrides.php?cmid='.$cmid.
                    '&amp;id=' . $override->id . '&amp;action=movegroupoverride&amp;dir=down&amp;sesskey='.sesskey().'">' .
                    $OUTPUT->pix_icon('t/down', get_string('movedown')) . '</a> ';
        } else {
            $iconstr .= $OUTPUT->spacer() . ' ';
        }


    } else {
        $usergroupstr = html_writer::link($userurl->out(false,
                array('id' => $override->userid, 'course' => $course->id)),
                fullname($override));
    }

    $class = '';
    if (!$active) {
        $class = "dimmed_text";
        $usergroupstr .= '*';
        $hasinactive = true;
    }

    $usergroupcell = new html_table_cell();
    $usergroupcell->rowspan = count($fields);
    $usergroupcell->text = $usergroupstr;
    $actioncell = new html_table_cell();
    $actioncell->rowspan = count($fields);
    $actioncell->text = $iconstr;

    for ($i = 0; $i < count($fields); ++$i) {
        $row = new html_table_row();
        $row->attributes['class'] = $class;
        if ($i == 0) {
            $row->cells[] = $usergroupcell;
        }
        $cell1 = new html_table_cell();
        $cell1->text = $fields[$i];
        $row->cells[] = $cell1;
        $cell2 = new html_table_cell();
        $cell2->text = $values[$i];
        $row->cells[] = $cell2;
        if ($i == 0) {
            $row->cells[] = $actioncell;
        }
        $table->data[] = $row;
    }
}

// Output the table and button.
echo html_writer::start_tag('div', array('id' => 'checkmarkoverrides'));
if (count($table->data)) {
    echo html_writer::table($table);
}
if ($hasinactive) {
    echo $OUTPUT->notification(get_string('inactiveoverridehelp', 'checkmark'), 'dimmed_text');
}

echo html_writer::start_tag('div', array('class' => 'buttons'));
$options = array();
if ($groupmode) {
    if (empty($groups)) {
        // There are no groups.
        echo $OUTPUT->notification(get_string('groupsnone', 'checkmark'), 'error');
        $options['disabled'] = true;
    }
    echo $OUTPUT->single_button($overrideediturl->out(true,
            array('action' => 'addgroup', 'cmid' => $cm->id)),
            get_string('addnewgroupoverride', 'checkmark'), 'post', $options);
} else {
    $users = array();
    // See if there are any users in the checkmark.
    if ($accessallgroups) {
        $users = get_enrolled_users($context, '', 0, 'u.id');
        $nousermessage = get_string('usersnone', 'checkmark');
    } else if ($groups) {
        $enrolledjoin = get_enrolled_join($context, 'u.id');
        list($ingroupsql, $ingroupparams) = $DB->get_in_or_equal(array_keys($groups), SQL_PARAMS_NAMED);
        $params = $enrolledjoin->params + $ingroupparams;
        $sql = "SELECT u.id
                  FROM {user} u
                  JOIN {groups_members} gm ON gm.userid = u.id
                       {$enrolledjoin->joins}
                 WHERE gm.groupid $ingroupsql
                       AND {$enrolledjoin->wheres}
              ORDER BY $sort";
        $users = $DB->get_records_sql($sql, $params);
        $nousermessage = get_string('usersnone', 'checkmark');
    } else {
        $nousermessage = get_string('groupsnone', 'checkmark');
    }
    $info = new \core_availability\info_module($cm);
    $users = $info->filter_user_list($users);

    if (empty($users)) {
        // There are no users.
        echo $OUTPUT->notification($nousermessage, 'error');
        $options['disabled'] = true;
    }
    echo $OUTPUT->single_button($overrideediturl->out(true,
            array('type' => '1', 'id' => $cm->id)),
            get_string('addnewuseroverride', 'checkmark'), 'get', $options);
}
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// Finish the page.
echo $OUTPUT->footer();
