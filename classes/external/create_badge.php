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

namespace local_ubionai\external;

use core_badges\badge;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;
use local_ubionai\local\helper;

/**
 * Create a course badge awarded for completing activities and/or the course with a minimum grade.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_badge extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'name' => new external_value(PARAM_TEXT, 'Badge name'),
            'description' => new external_value(PARAM_TEXT, 'Badge description'),
            'criteria' => new external_value(PARAM_RAW,
                'JSON object: {"activities": [cmid, ...], "min_course_grade": float|null}'),
            'enable' => new external_value(PARAM_BOOL, 'Enable the badge (and award it to users who already qualify)',
                VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Create the badge.
     *
     * @param int $courseid course id
     * @param string $name badge name
     * @param string $description badge description
     * @param string $criteria JSON criteria
     * @param bool $enable whether to enable the badge
     * @return array
     */
    public static function execute(int $courseid, string $name, string $description, string $criteria,
            bool $enable = false): array {
        global $CFG, $DB, $SITE;
        require_once($CFG->libdir . '/badgeslib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'name' => $name,
            'description' => $description,
            'criteria' => $criteria,
            'enable' => $enable,
        ]);
        $name = trim($params['name']);
        $description = trim($params['description']);
        if ($name === '' || \core_text::strlen($name) > 255) {
            throw new invalid_parameter_exception('name must be 1-255 characters');
        }
        if ($description === '') {
            throw new invalid_parameter_exception('description must not be empty');
        }
        $criteriadata = helper::decode_json_object($params['criteria'], 'criteria');
        if ($unknown = array_diff(array_keys($criteriadata), ['activities', 'min_course_grade'])) {
            throw new invalid_parameter_exception('Unknown criteria keys: ' . implode(', ', $unknown));
        }

        if (empty($CFG->enablebadges)) {
            throw new invalid_parameter_exception('Badges are disabled on this site');
        }
        if (empty($CFG->badges_allowcoursebadges)) {
            throw new invalid_parameter_exception('Course badges are disabled on this site');
        }

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('moodle/badges:createbadge', $context);
        require_capability('moodle/badges:configurecriteria', $context);

        $completion = new \completion_info($course);
        $cmids = self::parse_activities($criteriadata['activities'] ?? [], $course, $completion);
        $mingrade = $criteriadata['min_course_grade'] ?? null;
        if ($mingrade !== null && (is_bool($mingrade) || !is_numeric($mingrade) || $mingrade < 0)) {
            throw new invalid_parameter_exception('criteria.min_course_grade must be a number >= 0 or null');
        }
        if (!$cmids && $mingrade === null) {
            throw new invalid_parameter_exception('Give criteria.activities and/or criteria.min_course_grade');
        }
        if ($mingrade !== null && !$completion->is_enabled()) {
            throw new invalid_parameter_exception('criteria.min_course_grade needs completion tracking enabled in the course');
        }

        $warnings = [];
        $transaction = $DB->start_delegated_transaction();

        $url = parse_url($CFG->wwwroot);
        $data = (object) [
            'name' => $name,
            'version' => '',
            'language' => current_language(),
            'description' => $description,
            'imagecaption' => '',
            'issuername' => !empty($CFG->badges_defaultissuername) ? $CFG->badges_defaultissuername : $SITE->fullname,
            'issuerurl' => $url['scheme'] . '://' . $url['host'],
            'issuercontact' => $CFG->badges_defaultissuercontact ?? '',
            'expiry' => 0,
        ];
        $badge = badge::create_badge($data, $course->id);

        // Overall aggregation first, as the criteria page does.
        \award_criteria::build(['criteriatype' => BADGE_CRITERIA_TYPE_OVERALL, 'badgeid' => $badge->id])
            ->save(['agg' => BADGE_CRITERIA_AGGREGATION_ALL]);
        if ($cmids) {
            $activityparams = ['agg' => BADGE_CRITERIA_AGGREGATION_ALL];
            foreach ($cmids as $cmid) {
                $activityparams['module_' . $cmid] = $cmid;
            }
            \award_criteria::build(['criteriatype' => BADGE_CRITERIA_TYPE_ACTIVITY, 'badgeid' => $badge->id])
                ->save($activityparams);
        }
        if ($mingrade !== null) {
            $courseparams = ['course_' . $course->id => $course->id];
            if ($mingrade > 0) {
                $courseparams['grade_' . $course->id] = (float) $mingrade;
            }
            \award_criteria::build(['criteriatype' => BADGE_CRITERIA_TYPE_COURSE, 'badgeid' => $badge->id])
                ->save($courseparams);
            if (!$completion->has_criteria()) {
                $warnings[] = 'The course has no course completion criteria yet; the course criterion is met only '
                    . 'when the course is completed, so configure course completion too.';
            }
        }

        $transaction->allow_commit();

        if (!self::set_image($badge)) {
            $warnings[] = 'No badge image was stored (GD is not available); upload one in the badge settings.';
        }

        $awards = 0;
        $badge = new badge($badge->id);
        if ($params['enable']) {
            $badge->set_status(BADGE_STATUS_ACTIVE);
            $awards = (int) $badge->review_all_criteria();
        }

        return [
            'badgeid' => (int) $badge->id,
            'status' => $badge->get_status_name(),
            'awards' => $awards,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate the activity criteria list.
     *
     * @param mixed $activities decoded criteria.activities
     * @param \stdClass $course course record
     * @param \completion_info $completion course completion info
     * @return int[] cmids
     * @throws invalid_parameter_exception
     */
    private static function parse_activities($activities, \stdClass $course, \completion_info $completion): array {
        if (!is_array($activities) || !array_is_list($activities)) {
            throw new invalid_parameter_exception('criteria.activities must be a list of cmids');
        }
        if (!$activities) {
            return [];
        }
        if (!$completion->is_enabled()) {
            throw new invalid_parameter_exception('Activity criteria need completion tracking enabled in the course');
        }
        $cms = get_fast_modinfo($course)->get_cms();
        $cmids = [];
        foreach ($activities as $i => $cmid) {
            if (!is_int($cmid) || !isset($cms[$cmid])) {
                throw new invalid_parameter_exception("criteria.activities[{$i}] is not an activity of this course");
            }
            if ($cms[$cmid]->completion == COMPLETION_TRACKING_NONE) {
                throw new invalid_parameter_exception("criteria.activities[{$i}]: activity {$cmid} has no completion tracking");
            }
            $cmids[$cmid] = $cmid;
        }
        return array_values($cmids);
    }

    /**
     * Store a generated badge image (GD), falling back to the bundled pix/badge.png.
     *
     * @param badge $badge badge
     * @return bool whether an image was stored
     */
    private static function set_image(badge $badge): bool {
        global $CFG;
        if (empty($CFG->gdversion)) {
            return false;
        }
        $file = make_request_directory() . '/badge.png';
        if (!self::draw_image($file, $badge->name)) {
            copy($CFG->dirroot . '/local/ubionai/pix/badge.png', $file);
        }
        // Deletes the temporary file.
        badges_process_badge_image($badge, $file);
        return true;
    }

    /**
     * Draw a simple round badge, its colour derived from the badge name.
     *
     * @param string $path target PNG path
     * @param string $name badge name
     * @return bool success
     */
    private static function draw_image(string $path, string $name): bool {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            return false;
        }
        $size = 256;
        $image = imagecreatetruecolor($size, $size);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        $hash = crc32($name);
        $red = 40 + ($hash & 0x7f);
        $green = 40 + (($hash >> 8) & 0x7f);
        $blue = 90 + (($hash >> 16) & 0x7f);
        $centre = $size / 2;
        imagefilledellipse($image, $centre, $centre, 248, 248, imagecolorallocate($image, 255, 196, 0));
        imagefilledellipse($image, $centre, $centre, 216, 216, imagecolorallocate($image, $red, $green, $blue));

        // Five-pointed star.
        $points = [];
        for ($i = 0; $i < 10; $i++) {
            $radius = $i % 2 ? 36 : 84;
            $angle = -M_PI / 2 + $i * M_PI / 5;
            $points[] = (int) round($centre + $radius * cos($angle));
            $points[] = (int) round($centre + $radius * sin($angle));
        }
        imagefilledpolygon($image, $points, imagecolorallocate($image, 255, 255, 255));

        $ok = imagepng($image, $path);
        imagedestroy($image);
        return $ok;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'badgeid' => new external_value(PARAM_INT, 'Badge id'),
            'status' => new external_value(PARAM_TEXT, 'Badge status name'),
            'awards' => new external_value(PARAM_INT, 'Number of users awarded on enabling'),
            'warnings' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Warning')),
        ]);
    }
}
