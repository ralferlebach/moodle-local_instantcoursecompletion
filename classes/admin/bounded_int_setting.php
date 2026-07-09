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
 * A configtext restricted to an inclusive integer range.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion\admin;

/**
 * Bounded integer setting.
 *
 * The tasks that read these values already clamp a non-positive one to a hard-coded
 * default, but an administrator who enters "50000000" for a per-run budget would not
 * see that until the next cron run stalled on it. Rejecting the value at save time is
 * cheaper than discovering it in a scheduled task log.
 */
class bounded_int_setting extends \admin_setting_configtext {
    /** @var int Smallest value accepted. */
    protected $min;

    /** @var int Largest value accepted. */
    protected $max;

    /**
     * Bounded integer setting constructor.
     *
     * @param string $name           Setting name.
     * @param string $visiblename    Localised setting name.
     * @param string $description    Localised description.
     * @param int    $defaultsetting Default value.
     * @param int    $min            Smallest value accepted.
     * @param int    $max            Largest value accepted.
     */
    public function __construct($name, $visiblename, $description, int $defaultsetting, int $min, int $max) {
        $this->min = $min;
        $this->max = $max;
        parent::__construct($name, $visiblename, $description, (string)$defaultsetting, PARAM_INT);
    }

    /**
     * Reject values outside the configured range.
     *
     * @param string $data The submitted value.
     * @return bool|string True if valid, an error string otherwise.
     */
    public function validate($data) {
        $parentresult = parent::validate($data);
        if ($parentresult !== true) {
            return $parentresult;
        }

        $value = (int)$data;
        if ($value < $this->min || $value > $this->max) {
            return get_string(
                'settingoutofrange',
                'local_instantcoursecompletion',
                (object)['min' => $this->min, 'max' => $this->max]
            );
        }

        return true;
    }
}
