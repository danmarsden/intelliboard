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
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 * @package    local_intelliboard
 * @copyright  2017 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @website    http://intelliboard.net/
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot .'/local/intelliboard/locallib.php');

class local_intelliboard_notification {

    protected $history = array();

    public function get_instant_notifications($type, $filters = array(), $excluded = array())
    {
        global $DB;
        $valueSeparator = '=>';
        $paramSeparator = ':|:';

        $params = compact('type');
        $sql = "SELECT * 
          FROM {local_intelliboard_ntf} lin
          WHERE lin.type = :type AND lin.state = 1";

        if ($filters) {
            $filterCount = 0;
            foreach ($filters as $key => $value) {
                $sql .= ' AND lin.id IN (SELECT linp.notificationid FROM {local_intelliboard_ntf_pms} linp WHERE linp.name = :name' . $filterCount . " AND linp.value = :value" . $filterCount . ')';
                $params['name' . $filterCount] = $key;
                $params['value' . $filterCount] = $value;
                $filterCount++;
            }
        }

        if ($excluded) {
            $sql .= " AND lin.userid NOT IN (";

            foreach ($excluded as $i => $id) {
                $sql .= ':excluded' . $i . ',';
                $params['excluded' . $i] = $id;
            }

            $sql = rtrim($sql, ',');
            $sql .= ")";
        }

        $result = json_decode(json_encode($DB->get_records_sql($sql, $params)), true);

        $result = array_map(function ($item) use ($paramSeparator, $valueSeparator) {
            $item['email'] = explode(',', $item['email']);
            $item['tags'] = json_decode($item['tags'], true);
            return $item;
        }, $result);

        return $result;

    }

    public function send_notifications($notifications, $event = array(), $params = array())
    {

        if ($notifications) {
            foreach ($notifications as $notification) {
                 $events = array();

                if ($event) {
                    $events[] = $event->get_data();
                } else {
                    $events = $this->get_events_from_queue($notification, $params);
                }

                list($recipients, $results) = $this->{'notification' . $notification['type']}($notification, $events, $params);
                $this->notify($recipients, $results, $notification);
            }
        }

        $this->save_history();

    }

    protected function notify($recipients, $notifications, $notificationType)
    {
        global $CFG;

        $sender = get_admin();
        $sender->firstname = 'Intelliboard';
        $sender->lastname = 'Team';
        $old = $CFG->emailfromvia;
        $oldCharset = $CFG->sitemailcharset;

        $CFG->emailfromvia = EMAIL_VIA_NEVER;
        $CFG->sitemailcharset = 'utf-8';

        foreach($recipients as $i => $recipient) {
            $notification = $notifications[$i];

            if (!$notification['attachment']) {
                $path = '';
                $name = '';
            } else {
                $name = 'export' . round(microtime(true) * 1000) . '.' . $notificationType['attachment'];
                $path = $CFG->dataroot . '\\' . $name;
                file_put_contents($path, $notification['attachment']);
            }
            $recipient->mailformat = 1; //allow html

            email_to_user($recipient, $sender, $notification['subject'], $notification['message'], $notification['message'], $name, $name);

            if ($notification['attachment']) {
                unlink($path);
            }

            $this->add_to_history($recipient, $notificationType);
        }

        $CFG->emailfromvia = $old;
        $CFG->sitemailcharset = $oldCharset;

    }

    protected function add_to_history($recipient, $notification)
    {

        $this->history[] = array(
            'notificationid' => $notification['externalid'],
            'notificationname' => $notification['name'],
            'userid' => $notification['userid'],
            'email' => $recipient->email,
            'timesent' => time()
        );
    }

    protected function save_history()
    {
        global $DB;

        $DB->insert_records('local_intelliboard_ntf_hst', $this->history);
        $this->history = [];
    }


    protected function notification2(&$notification, $events = array())
    {
        global $DB;

        $event = $events[0];
        $user = $DB->get_record('user', array('id' => $event['relateduserid']));

        $result = array(
            'user' => fullname($user),
            'role' => $DB->get_record($event['objecttable'], array('id' => $event['objectid']), 'shortname')->shortname,
            'action' => $event['action']
        );

        $recipients = $this->get_recipients_by_emails($notification['email']);
        $notifications = array_fill(0, count($recipients), $this->prepare_notification($notification, array($result)));

        return array($recipients, $notifications);
    }

    protected function notification12(&$notification, $events = array())
    {
        global $DB, $CFG;

        $result = array();
        foreach ($events as $data) {
            $result[] = array(
                'user' => fullname($DB->get_record('user', array('id' => $data['userid']))),
                'courseName' => $DB->get_record('course', array('id' => $data['courseid']), 'fullname')->fullname,
                'forumName' => $DB->get_record('forum', array("id" => $data['other']['forumid']), 'name')->name,
                'responseLink' => '<a href="' . ($CFG->wwwroot . '/mod/forum/discuss.php?d=' . $data['other']['discussionid'] . '#p' . $data['objectid']) . '"> Response </a>'
            );
        }
        
        $recipients = $this->get_recipients_by_emails($notification['email']);
        $notifications = array_fill(0, count($recipients), $this->prepare_notification($notification, $result));

        return array($recipients, $notifications);
    }

    protected function notification13(&$notification, $events = array())
    {
        global $DB;

        $recipients = array();
        $notifications = array();

        foreach ($events as $data) {
            if (!isset($notifications[$data['relateduserid']])) {
                $notifications[$data['relateduserid']] = array();
                $recipients[$data['relateduserid']] = $DB->get_record('user', array('id' => $data['relateduserid']));
            }
            $item = $DB->get_record('grade_items', array('id' => $data['other']['itemid']), 'itemname, itemmodule');

            $params = array(
                'user' => fullname($recipients[$data['relateduserid']]),
                'courseName' => $DB->get_record('course', array('id' => $data['courseid']), 'fullname')->fullname,
                'activityType' => $item->itemmodule,
                'activityName' => $item->itemname,
                'grade' => $data['other']['finalgrade']
            );

            $notifications[$data['relateduserid']][] = $params;
        }

        foreach ($notifications as $i => $item) {
            $notifications[$i] = $this->prepare_notification($notification, $notifications[$i]);
        }

        return array($recipients, $notifications);
    }

    protected function notification14(&$notification, $events = array())
    {
        global $DB;

        $currentTime = time();
        $dueTime = strtotime('+' . $notification['params']['priorTime']);
        $params = array($currentTime, $dueTime, 'close', 'due', 'expectcompletionon');
        $params = array_merge($params, $notification['params']['activities']);
        $params[] = $dueTime;
        $filterUser = '';

        if (!$DB->count_records('local_intelliboard_assign', array('type' => 'courses', 'userid' => $notification['userid']))) {

            $availableUsers = get_ids("
                SELECT u.id FROM {user} u WHERE u.id IN(
                  SELECT lia.instance as id FROM {local_intelliboard_assign} lia WHERE lia.type = 'users' AND lia.userid = ?
                ) OR u.id IN (
                  SELECT chm.userid FROM {local_intelliboard_assign} lia, {cohort_members} chm
                  WHERE lia.type = 'cohorts' AND lia.userid = ? AND chm.cohortid = lia.instance
                )
            ", array($notification['userid'],$notification['userid']));

            if ($availableUsers) {
                $filterUser = 'AND u.id IN(' . trim(str_repeat('?,', count($availableUsers)), ',') . ')';
                $params = array_merge($params, $availableUsers);
            }
        }

        $users = $DB->get_records_sql('SELECT u.id, u.firstname, u.lastname, u.email, GROUP_CONCAT(\':|:\', cm.name) as activity_names, GROUP_CONCAT(\':|:\', cm.duedate) as activity_duedates
                FROM {user} u
                INNER JOIN {user_enrolments} ue ON ue.userid = u.id
                INNER JOIN {enrol} e ON e.id = ue.enrolid
                INNER JOIN {course} c ON c.id = e.courseid
                INNER JOIN (
                  SELECT cm.id, ' . get_modules_names() . ' as name, MIN(me.timestart) as duedate, cm.course as course'
            . '     FROM {course_modules} cm
                    INNER JOIN {modules} m ON cm.module = m.id
                    INNER JOIN {event} me ON me.modulename = m.name AND me.instance = cm.instance AND me.timestart BETWEEN ? AND ? AND me.eventtype IN(?,?,?)
                    WHERE cm.id IN(' . rtrim(str_repeat('?,', count($notification['params']['activities'])), ',') . ')
                    GROUP BY cm.id HAVING MIN(me.timestart) < ?
                  ) cm ON cm.course = c.id
                LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id
                WHERE cmc.completionstate IS NULL OR cmc.completionstate NOT IN (1)
                ' . $filterUser . '
               GROUP BY u.id
        ', $params);

        $users = array_map(function($user) {
            $user->activity_names = explode(':|:', $user->activity_names);
            $user->activity_duedates = explode(':|:', $user->activity_duedates);
            $user->activities = new stdClass();
            $user->activities->header = array(
                (object) array('name' => 'Name'),
                (object) array('name' => 'Due Date')
            );
            $user->activities->body = array();

            foreach ($user->activity_names as $i => $name) {
                if ($name) {
                    $duedate = $user->activity_duedates[$i];
                    $user->activities->body[] = compact('name', 'duedate');
                }
            }

            return $user;
        }, $users);

        $notifications = array();

        foreach ($users as $user) {
            $notifications[] = $this->prepare_notification($notification, array(), $user->activities);
        }

        return array($users, $notifications);
    }

    protected function notification15(&$notification, $events = array())
    {
        global $DB;

        $result = array();
        foreach ($events as $data) {
            $activityType = explode('_', $data['component'])[1];

            switch ($activityType) {
                case 'assign':
                    $activity = $DB->get_record_sql(
                        'SELECT a.name FROM {assign} a
                         INNER JOIN {assign_submission} ass ON ass.assignment = a.id WHERE ass.id = ?', array($data['objectid'])
                    )->name;
                    break;
                case 'quiz':
                    $activity = $DB->get_record_sql(
                        'SELECT q.name FROM {quiz} q WHERE q.id = ?', array($data['other']['quizid'])
                    )->name;
            }

            $result[] = array(
                'user' => fullname($DB->get_record('user', array('id' => $data['userid']))),
                'activity_type' => $activityType,
                'activity' => $activity,
                'time' => date('Y/m/d', time())
            );
        }

        $recipients = $this->get_recipients_by_emails($notification['email']);
        $notifications = array_fill(0, count($recipients), $this->prepare_notification($notification, array($result)));

        return array($recipients, $notifications);
    }

    protected function notification17(&$notification, $events = array(), $request_params = array())
    {
        global $DB;

        $from = $this->get_border_date($notification['frequency']);
        $to = time();
        $gradesql = intelliboard_grade_sql();
        $params = array($from, $to);

        $filterUser = $this->filter_by_owner($notification['userid'], array(
            'users' => 'u.id',
            'courses' => 'c.id'
        ), $request_params);

        $filterUser = $filterUser? ' AND ' . $filterUser : '';

        $users = $DB->get_records_sql('
                SELECT cmc.id,
                u.firstname as firstname,
                u.lastname as lastname,
                u.email as email,
                gi.itemname as activity,
                c.fullname as course,
                ' . $gradesql . ' as grade
                FROM {user} u
                INNER JOIN {course_modules_completion} cmc ON cmc.userid = u.id AND cmc.completionstate = 3
                INNER JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                INNER JOIN {modules} m ON m.id = cm.module
                INNER JOIN {course} c ON c.id = cm.course
                INNER JOIN {grade_items} gi ON gi.iteminstance = cm.instance AND gi.itemtype = \'mod\' AND gi.itemmodule = m.name
                INNER JOIN {grade_grades} g ON g.itemid = gi.id AND g.userid = u.id AND g.finalgrade IS NOT NULL
                WHERE cmc.timemodified BETWEEN ? AND ?
                ' . $filterUser . '
        ', $params);

        $recipients = array();
        $notifications = array();

        foreach($users as $user) {
            if (!$recipients[$user->email]) {
                $recipients[$user->email] = $user;
                $notifications[$user->email] = new stdClass();
                $notifications[$user->email]->header = array(
                    (object) array("name" => 'Activity'),
                    (object) array("name" => 'Course'),
                    (object) array("name" => 'Grade')
                );
            }

            $notifications[$user->email]->body[] = array('activity' => $user->activity, 'course' => $user->course, 'grade' => $user->grade);
        }

        foreach ($notifications as $i => $item) {
            $notifications[$i] = $this->prepare_notification($notification, array(), $item);
        }

        return array($recipients, $notifications);

    }

    protected function prepare_notification($notification, $params = array(), $attachment = array()) {

        $buffer = array();
        if ($params) {
            foreach ($params as $item) {
                $buffer[] = $this->transform_tags($notification['message'], $notification['tags'], $item);
            }
        } else {
            $buffer[] = $notification['message'];
        }

        $result = array();

        $result['subject'] = $notification['subject'];
        $result['message'] = implode('<hr>', $buffer);

        if ($notification['attachment']) {
            $result['attachment'] = intelliboard_export_report($attachment, $notification['type'], $notification['attachment'], 2);

            if ($notification['attachment'] === 'csv') {
                $result['attachment'] = str_replace('"', '', $result['attachment']);
            }

        } else {
            $result['attachment'] = false;
        }

        return $result;
    }

    protected function get_border_date($frequency)
    {

        $frequency = (int) $frequency;

        switch($frequency) {
            case 2:
                return strtotime('-1 hours');
            case 3:
                return strtotime('-1 days');
            case 4:
                return strtotime('-1 week');
            case 5:
                return strtotime('-1 month');
            case 6:
                return strtotime('-1 year');
            default:
                return time();
        }

    }

    protected function transform_tags($message, $tags, $values)
    {
        $keys = array_map(function($tag) {
            return '[' . $tag . ']';
        }, array_keys($tags));

        $values = array_map(function($value) use ($values) {
            return '<strong>' . $values[$value] . '</strong>';
        }, $tags);

        return str_replace($keys, $values, $message);
    }

    protected function filter_by_owner($user, $columns, $params)
    {
        global $DB;

        $query = [];
        $assign_users = [];
        $assign_courses = [];
        $assign_cohorts = [];

        $assigns = $DB->get_records_sql("SELECT * FROM {local_intelliboard_assign} WHERE userid = :userid", ['userid' => $user]);
        foreach ($assigns as $assign) {
            ${'assign_' . $assign->type}[] = (int) $assign->instance;
        }

        $assign_users_list = implode(",", $assign_users);
        $assign_courses_list = implode(",", $assign_courses);
        $assign_cohorts_list = implode(",", $assign_cohorts);

        foreach ($columns as $type => $column) {
            if ($type == "users") {
                $list = [];

                if ($assign_cohorts_list) {
                    $list = array_merge($list, $DB->get_fieldset_sql("SELECT userid FROM {cohort_members} WHERE cohortid IN ($assign_cohorts_list)"));
                }

                if ($assign_courses_list) {
                    list($learner_roles, $values) = $this->get_filter_in_sql($params->learner_roles,'ra.roleid');
                    $list = array_merge($list, $DB->get_fieldset_sql("SELECT distinct ra.userid FROM {role_assignments} ra, {context} ctx WHERE ctx.id = ra.contextid AND ctx.contextlevel = 50 $learner_roles AND ctx.instanceid IN ($assign_courses_list)", $values));
                }

                $assign_users = array_merge(array_unique($assign_users), array_unique($list));

                if ($assign_users) {
                    $query[] = "$column IN (".implode(",", $assign_users).")";
                }
            } elseif($type == "courses") {
                $list = [];
                $assign_courses = array_unique($assign_courses);

                if ($assign_users_list) {
                    list($learner_roles, $values) = $this->get_filter_in_sql($params->learner_roles,'ra.roleid');
                    $list = array_merge($list, $DB->get_fieldset_sql("SELECT DISTINCT ctx.instanceid FROM {role_assignments} ra, {context} ctx WHERE ctx.id = ra.contextid AND ctx.contextlevel = 50 $learner_roles AND ra.userid IN ($assign_users_list)", $values));
                }
                if ($assign_cohorts_list) {
                    $users_list = $DB->get_fieldset_sql("SELECT userid FROM {cohort_members} WHERE cohortid IN ($assign_cohorts_list)");
                    list($learner_roles, $values) = $this->get_filter_in_sql($params->learner_roles,'ra.roleid');
                    $list = array_merge($list, $DB->get_fieldset_sql("SELECT DISTINCT ctx.instanceid FROM {role_assignments} ra, {context} ctx WHERE ctx.id = ra.contextid AND ctx.contextlevel = 50 $learner_roles AND ra.userid IN (" . implode(",", $users_list) .  ")", $values));
                }

                $assign_courses = array_merge(array_unique($assign_courses), array_unique($list));
                if ($assign_courses) {
                    $query[] = "$column IN (".implode(",", $assign_courses).")";
                }
            } elseif($type == "cohorts" && $assign_cohorts) {
                $query[] = "$column IN (".implode(",", $assign_cohorts).")";
            }
        }

        return $query? " (".implode(" AND ", $query).") " : '';
    }

    protected function get_events_from_queue($notification, $params)
    {
        global $DB;

        $function = 'notification' . $notification['type'] . '_event';

        if (method_exists($this, $function)) {
            $data = $this->$function($notification);

            $filter = $this->filter_by_owner($notification['userid'], array(
                'users' => 't.userid',
                'courses' => 't.courseid'
            ), $params);

            $data['sql'] .= $filter? ' WHERE ' . $filter : '';

            $events = json_decode(json_encode($DB->get_records_sql(
                $data['sql'], $data['params']
            )), true);

            $events = array_map(function($event) {

                foreach ($event as $name => $value) {
                    $nameArr = explode('_', $name);

                    if ($nameArr[0] === 'other') {
                        $event['other'][$nameArr[1]] = $value;
                    }
                }
                return $event;
            }, $events);
        } else {
            $events = array();
        }

        return $events;
    }

    protected function notification12_event($notification)
    {

        $sql = '
            SELECT
                fp.id as objectid,
                fp.userid as userid,
                fd.course as courseid,
                fd.forum as other_forumid,
                fp.discussion as other_discussion
            FROM {forum_posts} as fp
            INNER JOIN {forum_discussions} as fd ON fd.id = fp.discussion
            WHERE modified > ?
       ';

        $params = array($this->get_border_date($notification['frequency']));

        if (!empty($notification['params']['forums'])) {
            $sql .= " AND fd.forum IN(" . rtrim(str_repeat('?,', count($notification['params']['forums'])), ',') . ")";
            $params = array_merge($params, $notification['params']['forums']);
        }

        $sql = 'SELECT t.* FROM (' . $sql . ') AS t';
        return compact('sql', 'params');
    }

    protected function notification13_event($notification)
    {

        $sql = '
            SELECT
                g.id as id,
                g.userid as relateduserid,
                g.userid as userid,
                g.itemid as other_itemid,
                gi.courseid as courseid,
                g.finalgrade as other_finalgrade
            FROM {grade_grades} as g
            INNER JOIN {grade_items} as gi ON g.itemid = gi.id AND gi.itemmodule IN (\'quiz\', \'assign\')
            WHERE g.finalgrade IS NOT NULL AND g.timemodified > ?
       ';
        $params = array($this->get_border_date($notification['frequency']));

        if (!empty($notification['params']['course'])) {
            $sql .= " AND gi.courseid IN(" . rtrim(str_repeat('?,', count($notification['params']['course'])), ',') . ")";
            $params = array_merge($params, $notification['params']['course']);
        }

        $sql = 'SELECT t.* FROM (' . $sql . ') AS t';
        return compact('sql', 'params');

    }

    protected function notification15_event($notification)
    {
        $time = $this->get_border_date($notification['frequency']);

        $sql = 'SELECT t.* FROM ((SELECT
                  s.id AS uniqueid,
                  s.id AS objectid,
                  s.assignment AS other_quizid,
                  s.userid as userid,
                  a.course as courseid,
                  \'mod_assign\' AS component
                FROM {assign_submission} s
                  LEFT JOIN {assign_grades} g ON s.assignment = g.assignment AND s.userid = g.userid AND g.attemptnumber = s.attemptnumber
                  LEFT JOIN {assign} a ON a.id = s.assignment
                WHERE s.latest = 1 AND s.timemodified IS NOT NULL AND s.timecreated > ? AND s.status = \'submitted\' AND (s.timemodified >= g.timemodified OR g.timemodified IS NULL OR g.grade IS NULL))

                UNION ALL

                (SELECT
                  quiza.id AS uniqueid,
                  quiza.id AS objectid,
                  quiza.quiz AS other_quizid,
                  quiza.userid,
                  qz.course as courseid,
                  \'mod_quiz\' AS activity
                FROM {quiz_attempts} quiza
                  LEFT JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                  LEFT JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id AND qas.sequencenumber = (
                        SELECT MAX(sequencenumber)
                        FROM {question_attempt_steps}
                        WHERE questionattemptid = qa.id
                        )
                  LEFT JOIN {quiz} qz ON qz.id = quiza.quiz
                WHERE quiza.preview = 0 AND quiza.state = \'finished\' AND quiza.timemodified > ? AND qas.state=\'needsgrading\'
                )) t
        ';

        $params = array(
            $time,
            $time
        );

        return compact('sql', 'params');
    }

    private function get_filter_in_sql($items, $column)
    {
        global $DB;

        if($items){
            $result = $DB->get_in_or_equal($items, SQL_PARAMS_QM);
            $result[0] = " $column " . $result[0] . " ";
            return $result;
        }

        return array('sql' => '', 'params' => array());
    }

    private function get_recipients_by_emails($emails)
    {
        $template = get_admin();

        return array_map(function($email) use ($template) {
            $user = clone $template;
            $user->email = $email;

            return $user;
        }, $emails);
    }

}
