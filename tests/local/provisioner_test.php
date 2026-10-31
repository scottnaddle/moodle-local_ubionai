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

namespace local_ubionai\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for token tracking and disconnect in the provisioner.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ubionai\local\provisioner
 */
final class provisioner_test extends \advanced_testcase {
    /**
     * Reset state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Provisioning stores exactly one tracked token id.
     */
    public function test_provision_tracks_token(): void {
        global $DB;

        $result = provisioner::provision();
        $this->assertTrue($result['token_created']);

        $ids = self::tracked_ids();
        $this->assertCount(1, $ids);

        $user = $DB->get_record('user', ['username' => provisioner::USERNAME], '*', MUST_EXIST);
        $this->assertTrue($DB->record_exists('external_tokens', ['id' => $ids[0], 'userid' => $user->id]));

        $status = provisioner::status();
        $this->assertSame(1, $status['token_count']);
        $this->assertSame(1, $status['tokens_tracked']);
        $this->assertSame(0, $status['tokens_untracked']);
    }

    /**
     * Provisioning normalises the iprestriction for the authorisation and tracked token.
     */
    public function test_provision_normalises_iprestriction(): void {
        global $DB;

        provisioner::provision(' 203.0.113.10 , 2001:DB8::/32 ');

        $user = $DB->get_record('user', ['username' => provisioner::USERNAME], '*', MUST_EXIST);
        $service = $DB->get_record('external_services', ['shortname' => provisioner::SERVICE], '*', MUST_EXIST);
        $expected = '203.0.113.10,2001:db8::/32';

        $this->assertSame($expected, $DB->get_field('external_services_users', 'iprestriction',
            ['externalserviceid' => $service->id, 'userid' => $user->id]));

        $ids = self::tracked_ids();
        $this->assertCount(1, $ids);
        $this->assertSame($expected, $DB->get_field('external_tokens', 'iprestriction', ['id' => $ids[0]]));
    }

    /**
     * Re-provisioning does not rewrite the iprestriction of untracked tokens.
     */
    public function test_provision_leaves_untracked_iprestriction(): void {
        global $DB;

        provisioner::provision();
        $user = $DB->get_record('user', ['username' => provisioner::USERNAME], '*', MUST_EXIST);
        $service = $DB->get_record('external_services', ['shortname' => provisioner::SERVICE], '*', MUST_EXIST);
        $manualid = self::create_manual_token((int) $service->id, (int) $user->id);
        $DB->set_field('external_tokens', 'iprestriction', '198.51.100.1', ['id' => $manualid]);

        provisioner::provision('203.0.113.10');

        $ids = self::tracked_ids();
        $this->assertCount(1, $ids);
        $this->assertSame('198.51.100.1', $DB->get_field('external_tokens', 'iprestriction',
            ['id' => $manualid]));
        $this->assertSame('203.0.113.10', $DB->get_field('external_tokens', 'iprestriction',
            ['id' => $ids[0]]));
    }

    /**
     * Provisioning rejects an invalid iprestriction.
     */
    public function test_provision_rejects_invalid_iprestriction(): void {
        $this->expectException(\invalid_parameter_exception::class);
        provisioner::provision('1.2.3.4 # office');
    }

    /**
     * Disconnect deletes only tracked rows and reports the untracked ones.
     */
    public function test_disconnect_deletes_only_tracked_tokens(): void {
        global $DB;

        provisioner::provision();
        $ids = self::tracked_ids();
        $this->assertCount(1, $ids);
        $trackedid = $ids[0];

        $user = $DB->get_record('user', ['username' => provisioner::USERNAME], '*', MUST_EXIST);
        $service = $DB->get_record('external_services', ['shortname' => provisioner::SERVICE], '*', MUST_EXIST);
        $manualid = self::create_manual_token((int) $service->id, (int) $user->id);

        $status = provisioner::status();
        $this->assertSame(2, $status['token_count']);
        $this->assertSame(1, $status['tokens_tracked']);
        $this->assertSame(1, $status['tokens_untracked']);

        $result = provisioner::disconnect();

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(1, $result['untracked']);
        $this->assertFalse($DB->record_exists('external_tokens', ['id' => $trackedid]));
        $this->assertTrue($DB->record_exists('external_tokens', ['id' => $manualid]));
        $this->assertSame('', (string) get_config('local_ubionai', 'issued_token_ids'));
    }

    /**
     * A legacy install deletes nothing and leaves its existing token in place.
     */
    public function test_disconnect_legacy_install(): void {
        global $DB;

        provisioner::provision();
        $ids = self::tracked_ids();
        $this->assertCount(1, $ids);
        $legacyid = $ids[0];

        set_config('issued_token_ids', '', 'local_ubionai');

        $result = provisioner::disconnect();

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(1, $result['untracked']);
        $this->assertTrue($DB->record_exists('external_tokens', ['id' => $legacyid]));

        $reprovision = provisioner::provision();
        $this->assertTrue($reprovision['token_created']);
        $newids = self::tracked_ids();
        $this->assertCount(1, $newids);
        $this->assertNotSame($legacyid, $newids[0]);

        $expected = $DB->get_field('external_tokens', 'token', ['id' => $newids[0]], MUST_EXIST);
        $called = false;
        provisioner::with_token(function (string $token) use (&$called, $expected): void {
            $called = true;
            $this->assertSame($expected, $token);
        });
        $this->assertTrue($called);
    }

    /**
     * Stale tracked ids are pruned from config.
     */
    public function test_tracked_ids_are_pruned(): void {
        provisioner::provision();
        $ids = self::tracked_ids();
        $this->assertCount(1, $ids);

        set_config('issued_token_ids', '99999999,' . $ids[0], 'local_ubionai');

        $status = provisioner::status();
        $this->assertSame(1, $status['tokens_tracked']);
        $this->assertSame((string) $ids[0], (string) get_config('local_ubionai', 'issued_token_ids'));
    }

    /**
     * with_token throws when no token is tracked.
     */
    public function test_with_token_requires_tracked_token(): void {
        provisioner::provision();
        set_config('issued_token_ids', '', 'local_ubionai');

        $this->expectException(\coding_exception::class);
        provisioner::with_token(static function (string $token): void {
        });
    }

    /**
     * Read the tracked ids from config.
     *
     * @return int[]
     */
    private static function tracked_ids(): array {
        $raw = (string) get_config('local_ubionai', 'issued_token_ids');
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return $ids;
    }

    /**
     * Create a token row that the plugin does not track.
     *
     * @param int $serviceid external service id
     * @param int $userid service user id
     * @return int created token row id
     */
    private static function create_manual_token(int $serviceid, int $userid): int {
        global $DB;

        $token = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            \core_external\util::get_service_by_id($serviceid),
            $userid,
            \context_system::instance(),
            0,
            '',
            'manual'
        );
        $id = (int) $DB->get_field('external_tokens', 'id', ['token' => $token]);
        unset($token);

        return $id;
    }
}
