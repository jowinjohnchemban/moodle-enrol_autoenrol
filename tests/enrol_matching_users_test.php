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
 * Tests for enrolment of users meeting the conditions by scheduled task.
 *
 * @package    enrol_autoenrol
 * @copyright  2026 Jowin John Chemban
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_autoenrol;

/**
 * Enrol matching users scheduled task.
 *
 * @covers \enrol_autoenrol\task\enrol_matching_users
 * @covers \enrol_autoenrol_plugin::sync_instance
 */
final class enrol_matching_users_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enrol_plugins_enabled', 'autoenrol');
        set_config('autounenrolaction', ENROL_EXT_REMOVED_UNENROL, 'enrol_autoenrol');
    }

    /**
     * Add an autoenrol instance filtering users by department.
     *
     * @param \stdClass $course The course
     * @param int $method Enrol when (customint1)
     * @param string $department Department users must have, empty means no filter
     * @param int $limit Max enrolled users (customint5)
     * @return \stdClass the enrol instance
     */
    private function add_instance(\stdClass $course, int $method, string $department = '', int $limit = 0): \stdClass {
        global $DB;

        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $fields = [
            'status' => ENROL_INSTANCE_ENABLED,
            'roleid' => $studentrole->id,
            'customint1' => $method,
            'customint4' => 1,
            'customint5' => $limit,
            'customint7' => 0,
        ];
        if ($department !== '') {
            $fields['customtext2'] = json_encode([
                'op' => '&',
                'c' => [['type' => 'profile', 'sf' => 'department', 'op' => 'isequalto', 'v' => $department]],
                'showc' => [true],
            ]);
        }
        $instanceid = enrol_get_plugin('autoenrol')->add_instance($course, $fields);

        return $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
    }

    /**
     * Run the scheduled task discarding its output.
     *
     * @return void
     */
    private function run_task(): void {
        ob_start();
        (new task\enrol_matching_users())->execute();
        ob_end_clean();
    }

    /**
     * Check if user is enrolled with given instance.
     *
     * @param \stdClass $instance The enrol instance
     * @param \stdClass $user The user
     * @return bool
     */
    private function is_enrolled_in(\stdClass $instance, \stdClass $user): bool {
        global $DB;

        return $DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]);
    }

    /**
     * Only active users meeting the conditions are enrolled, without any user action.
     */
    public function test_task_enrols_only_matching_users(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $this->add_instance($course, 3, 'Sales');

        $matching = $generator->create_user(['department' => 'Sales']);
        $other = $generator->create_user(['department' => 'Support']);
        $suspended = $generator->create_user(['department' => 'Sales', 'suspended' => 1]);

        $this->run_task();

        $this->assertTrue($this->is_enrolled_in($instance, $matching));
        $this->assertFalse($this->is_enrolled_in($instance, $other));
        $this->assertFalse($this->is_enrolled_in($instance, $suspended));
    }

    /**
     * Users are enrolled on the next run once their account meets the conditions,
     * and handled with autounenrolaction once they no more meet them.
     */
    public function test_task_follows_user_changes(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $this->add_instance($course, 3, 'Sales');

        $user = $generator->create_user(['department' => 'Support']);
        $this->run_task();
        $this->assertFalse($this->is_enrolled_in($instance, $user));

        $DB->set_field('user', 'department', 'Sales', ['id' => $user->id]);
        $this->run_task();
        $this->assertTrue($this->is_enrolled_in($instance, $user));

        $DB->set_field('user', 'department', 'Support', ['id' => $user->id]);
        $this->run_task();
        $this->assertFalse($this->is_enrolled_in($instance, $user));
    }

    /**
     * Instances with other enrol methods are not processed by the task.
     */
    public function test_task_ignores_other_methods(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $this->add_instance($course, 0);

        $user = $generator->create_user();
        $this->run_task();

        $this->assertFalse($this->is_enrolled_in($instance, $user));
    }

    /**
     * Users not yet enrolled by the task are enrolled on login when login enrolments are allowed.
     *
     * @covers \enrol_autoenrol_plugin::sync_user_enrolments
     */
    public function test_login_enrols_matching_user_before_task_run(): void {
        global $USER;

        set_config('loginenrol', 1, 'enrol_autoenrol');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $this->add_instance($course, 3, 'Sales');

        $this->setUser($generator->create_user(['department' => 'Sales']));
        enrol_check_plugins($USER);

        $this->assertTrue($this->is_enrolled_in($instance, $USER));
    }

    /**
     * Users are not enrolled on login when login enrolments are not allowed.
     *
     * @covers \enrol_autoenrol_plugin::sync_user_enrolments
     */
    public function test_login_does_not_enrol_when_login_enrolments_disabled(): void {
        global $USER;

        set_config('loginenrol', 0, 'enrol_autoenrol');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $this->add_instance($course, 3, 'Sales');

        $this->setUser($generator->create_user(['department' => 'Sales']));
        enrol_check_plugins($USER);

        $this->assertFalse($this->is_enrolled_in($instance, $USER));
    }

    /**
     * Build submitted instance form data with the given "Enrol When" value.
     *
     * @param int $method Enrol when (customint1)
     * @return array
     */
    private function get_instance_form_data(int $method): array {
        global $DB;

        return [
            'name' => '',
            'status' => ENROL_INSTANCE_ENABLED,
            'roleid' => $DB->get_field('role', 'id', ['shortname' => 'student']),
            'enrolperiod' => 0,
            'expirynotify' => 0,
            'expirythreshold' => 86400,
            'enrolstartdate' => 0,
            'enrolenddate' => 0,
            'customint1' => $method,
            'customint3' => 0,
            'customint4' => 1,
            'customint5' => 0,
            'customint6' => 0,
            'customint7' => 0,
            'customint8' => 0,
            'customchar1' => '',
            'customchar3' => '-',
            'availabilityconditionsjson' => '',
        ];
    }

    /**
     * Only users with enrol/autoenrol:method may switch an instance to enrolment on meeting conditions.
     *
     * @covers \enrol_autoenrol_plugin::edit_instance_validation
     */
    public function test_validation_requires_method_capability(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $manager = $generator->create_and_enrol($course, 'manager');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $context = \context_course::instance($course->id);
        $plugin = enrol_get_plugin('autoenrol');
        $data = $this->get_instance_form_data(3);

        $newinstance = (object)['id' => null, 'courseid' => $course->id, 'roleid' => $data['roleid'], 'customint1' => 0];
        $this->setUser($teacher);
        $this->assertArrayHasKey('customint1', $plugin->edit_instance_validation($data, [], $newinstance, $context));

        $this->setUser($manager);
        $this->assertSame([], $plugin->edit_instance_validation($data, [], $newinstance, $context));

        // Teachers can keep the value set by a manager.
        $instance = (object)['id' => 1, 'courseid' => $course->id, 'roleid' => $data['roleid'], 'customint1' => 3];
        $this->setUser($teacher);
        $this->assertSame([], $plugin->edit_instance_validation($data, [], $instance, $context));
    }

    /**
     * The task stops enrolling when the instance limit is reached.
     */
    public function test_task_respects_enrolment_limit(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $this->add_instance($course, 3, 'Sales', 1);

        $generator->create_user(['department' => 'Sales']);
        $generator->create_user(['department' => 'Sales']);
        $this->run_task();

        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));
    }
}
