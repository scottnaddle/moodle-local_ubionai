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

namespace local_ubionai\registration;

/**
 * Self-registration state machine, stored in config_plugins local_ubionai/reg_state.
 *
 * awaiting_consent -> consented -> registering -> pending_confirm -> registered.
 * Failures: failed_retryable (adhoc retry scheduled) / failed_action_required (admin must act).
 * unsupported_private: wwwroot judged not reachable from the internet (v1 unsupported).
 * deregistered: consent withdrawn after registration.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class state {
    /** Consent not given yet. */
    public const AWAITING_CONSENT = 'awaiting_consent';
    /** Consent given, registration not started. */
    public const CONSENTED = 'consented';
    /** Registration request in flight. */
    public const REGISTERING = 'registering';
    /** Hub issued a secret; confirm not done yet. */
    public const PENDING_CONFIRM = 'pending_confirm';
    /** Secret confirmed and current. */
    public const REGISTERED = 'registered';
    /** Retryable failure; an adhoc retry is scheduled. */
    public const FAILED_RETRYABLE = 'failed_retryable';
    /** Failure that needs an administrator. */
    public const FAILED_ACTION_REQUIRED = 'failed_action_required';
    /** Private / internal address: not supported in v1. */
    public const UNSUPPORTED_PRIVATE = 'unsupported_private';
    /** Consent withdrawn. */
    public const DEREGISTERED = 'deregistered';

    /** All states. */
    public const ALL = [
        self::AWAITING_CONSENT, self::CONSENTED, self::REGISTERING, self::PENDING_CONFIRM,
        self::REGISTERED, self::FAILED_RETRYABLE, self::FAILED_ACTION_REQUIRED,
        self::UNSUPPORTED_PRIVATE, self::DEREGISTERED,
    ];

    /**
     * Current state (awaiting_consent when unset or unknown).
     *
     * @return string
     */
    public static function get(): string {
        $value = (string) get_config('local_ubionai', 'reg_state');
        return in_array($value, self::ALL, true) ? $value : self::AWAITING_CONSENT;
    }

    /**
     * Set the state.
     *
     * @param string $value one of the constants
     * @return void
     */
    public static function set(string $value): void {
        if (!in_array($value, self::ALL, true)) {
            throw new \coding_exception('Unknown registration state: ' . $value);
        }
        set_config('reg_state', $value, 'local_ubionai');
    }
}
