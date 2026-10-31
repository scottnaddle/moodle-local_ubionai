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
     * A legacy token named ubionai-mcp is adopted on reconnect and revoked by disconnect.
     */
    public function test_disconnect_legacy_named_token_is_adopted_on_reconnect(): void {
        global $DB;

        provisioner::provision();
        $ids = self::tracked_ids();
        $this->assertCount(1, $ids);
        $legacyid = $ids[0];

        set_config('issued_token_ids', '', 'local_ubionai');

        $reprovision = provisioner::provision();
        $this->assertSame('adopted', $reprovision['token_action']);
        $this->assertFalse($reprovision['token_created']);

        $user = $DB->get_record('user', ['username' => provisioner::USERNAME], '*', MUST_EXIST);
        $service = $DB->get_record('external_services', ['shortname' => provisioner::SERVICE], '*', MUST_EXIST);
        $this->assertSame(1, $DB->count_records('external_tokens',
            ['externalserviceid' => $service->id, 'userid' => $user->id]));
        $this->assertSame([$legacyid], self::tracked_ids());

        $expected = $DB->get_field('external_tokens', 'token', ['id' => $legacyid], MUST_EXIST);
        $called = false;
        provisioner::with_token(function (string $token) use (&$called, $expected): void {
            $called = true;
            $this->assertSame($expected, $token);
        });
        $this->assertTrue($called);

        $result = provisioner::disconnect();
        $this->assertSame(1, $result['deleted']);
        $this->assertSame(0, $result['untracked']);
        $this->assertFalse($DB->record_exists('external_tokens', ['id' => $legacyid]));
    }

    /**
     * A legacy token with another name is not adopted; a new one is minted and tracked.
     */
    public function test_disconnect_legacy_other_named_token_is_not_adopted(): void {
        global $DB;

        provisioner::provision();
        $ids = self::tracked_ids();
        $this->assertCount(1, $ids);
        $legacyid = $ids[0];
        $DB->set_field('external_tokens', 'name', 'manual-admin', ['id' => $legacyid]);

        set_config('issued_token_ids', '', 'local_ubionai');

        $reprovision = provisioner::provision();
        $this->assertSame('created', $reprovision['token_action']);
        $this->assertTrue($reprovision['token_created']);

        $newids = self::tracked_ids();
        $this->assertCount(1, $newids);
        $this->assertNotSame($legacyid, $newids[0]);
        $this->assertTrue($DB->record_exists('external_tokens', ['id' => $legacyid]));

        $result = provisioner::disconnect();
        $this->assertSame(1, $result['deleted']);
        $this->assertSame(1, $result['untracked']);
        $this->assertFalse($DB->record_exists('external_tokens', ['id' => $newids[0]]));
        $this->assertTrue($DB->record_exists('external_tokens', ['id' => $legacyid]));
    }

    /**
     * Connecting twice reuses the tracked valid token.
     */
    public function test_connect_twice_reuses_token(): void {
        global $DB;

        $first = provisioner::provision();
        $firstids = self::tracked_ids();
        $second = provisioner::provision();
        $secondids = self::tracked_ids();

        $this->assertSame('created', $first['token_action']);
        $this->assertSame('reused', $second['token_action']);
        $this->assertFalse($second['token_created']);
        $this->assertSame($firstids, $secondids);

        $user = $DB->get_record('user', ['username' => provisioner::USERNAME], '*', MUST_EXIST);
        $service = $DB->get_record('external_services', ['shortname' => provisioner::SERVICE], '*', MUST_EXIST);
        $this->assertSame(1, $DB->count_records('external_tokens',
            ['externalserviceid' => $service->id, 'userid' => $user->id]));
    }

    /**
     * Connect after disconnect mints exactly one token; a further connect reuses it.
     */
    public function test_connect_after_disconnect_mints_one(): void {
        global $DB;

        provisioner::provision();
        provisioner::disconnect();
        $created = provisioner::provision();
        $reused = provisioner::provision();

        $this->assertSame('created', $created['token_action']);
        $this->assertSame('reused', $reused['token_action']);
        $this->assertCount(1, self::tracked_ids());

        $user = $DB->get_record('user', ['username' => provisioner::USERNAME], '*', MUST_EXIST);
        $service = $DB->get_record('external_services', ['shortname' => provisioner::SERVICE], '*', MUST_EXIST);
        $this->assertSame(1, $DB->count_records('external_tokens',
            ['externalserviceid' => $service->id, 'userid' => $user->id]));
    }

    /**
     * An untracked valid token named ubionai-mcp is adopted without creating a new row.
     */
    public function test_adopts_untracked_named_token(): void {
        global $DB;

        provisioner::provision();
        $id = self::tracked_ids()[0];

        set_config('issued_token_ids', '', 'local_ubionai');

        $result = provisioner::provision();
        $this->assertSame('adopted', $result['token_action']);
        $this->assertFalse($result['token_created']);
        $this->assertSame([$id], self::tracked_ids());

        $user = $DB->get_record('user', ['username' => provisioner::USERNAME], '*', MUST_EXIST);
        $service = $DB->get_record('external_services', ['shortname' => provisioner::SERVICE], '*', MUST_EXIST);
        $this->assertSame(1, $DB->count_records('external_tokens',
            ['externalserviceid' => $service->id, 'userid' => $user->id]));

        $disconnect = provisioner::disconnect();
        $this->assertSame(1, $disconnect['deleted']);
        $this->assertSame(0, $disconnect['untracked']);
        $this->assertFalse($DB->record_exists('external_tokens', ['id' => $id]));
    }

    /**
     * A valid token with another name is never adopted or deleted.
     */
    public function test_does_not_adopt_other_named_token(): void {
        global $DB;

        provisioner::provision();
        $user = $DB->get_record('user', ['username' => provisioner::USERNAME], '*', MUST_EXIST);
        $service = $DB->get_record('external_services', ['shortname' => provisioner::SERVICE], '*', MUST_EXIST);

        $DB->delete_records('external_tokens',
            ['externalserviceid' => $service->id, 'userid' => $user->id]);
        set_config('issued_token_ids', '', 'local_ubionai');
        $manualid = self::create_manual_token((int) $service->id, (int) $user->id, 'manual-admin');

        $result = provisioner::provision();
        $this->assertSame('created', $result['token_action']);

        $newids = self::tracked_ids();
        $this->assertCount(1, $newids);
        $this->assertNotSame($manualid, $newids[0]);

        $disconnect = provisioner::disconnect();
        $this->assertSame(1, $disconnect['deleted']);
        $this->assertSame(1, $disconnect['untracked']);
        $this->assertFalse($DB->record_exists('external_tokens', ['id' => $newids[0]]));
        $this->assertTrue($DB->record_exists('external_tokens', ['id' => $manualid]));
    }

    /**
     * An expired tracked token is deleted and replaced by a new one.
     */
    public function test_expired_tracked_token_replaced(): void {
        global $DB;

        provisioner::provision();
        $id = self::tracked_ids()[0];
        $DB->set_field('external_tokens', 'validuntil', time() - 10, ['id' => $id]);

        $result = provisioner::provision();
        $this->assertSame('created', $result['token_action']);
        $this->assertFalse($DB->record_exists('external_tokens', ['id' => $id]));

        $newids = self::tracked_ids();
        $this->assertCount(1, $newids);
        $this->assertNotSame($id, $newids[0]);
    }

    /**
     * An expired untracked token named ubionai-mcp is neither adopted nor deleted.
     */
    public function test_expired_named_untracked_not_adopted(): void {
        global $DB;

        provisioner::provision();
        $id = self::tracked_ids()[0];
        $DB->set_field('external_tokens', 'validuntil', time() - 10, ['id' => $id]);
        set_config('issued_token_ids', '', 'local_ubionai');

        $result = provisioner::provision();
        $this->assertSame('created', $result['token_action']);
        $this->assertTrue($DB->record_exists('external_tokens', ['id' => $id]));

        $newids = self::tracked_ids();
        $this->assertCount(1, $newids);
        $this->assertNotSame($id, $newids[0]);
    }

    /**
     * A tracked id that points at another service is untracked but not deleted.
     */
    public function test_tracked_id_on_other_service_untracked_not_deleted(): void {
        global $DB;

        provisioner::provision();
        $id = self::tracked_ids()[0];
        $otherid = $DB->insert_record('external_services', (object) [
            'name' => 'Other service',
            'shortname' => 'otherservice',
            'enabled' => 1,
            'restrictedusers' => 0,
            'downloadfiles' => 0,
            'uploadfiles' => 0,
            'requiredcapability' => '',
            'component' => null,
            'timecreated' => time(),
        ]);
        $DB->set_field('external_tokens', 'externalserviceid', $otherid, ['id' => $id]);

        $result = provisioner::provision();
        $this->assertSame('created', $result['token_action']);
        $this->assertTrue($DB->record_exists('external_tokens', ['id' => $id]));

        $newids = self::tracked_ids();
        $this->assertCount(1, $newids);
        $this->assertNotSame($id, $newids[0]);

        $user = $DB->get_record('user', ['username' => provisioner::USERNAME], '*', MUST_EXIST);
        $service = $DB->get_record('external_services', ['shortname' => provisioner::SERVICE], '*', MUST_EXIST);
        $this->assertSame(1, $DB->count_records('external_tokens',
            ['externalserviceid' => $service->id, 'userid' => $user->id]));
    }

    /**
     * with_token throws when the tracked token is no longer valid.
     */
    public function test_with_token_ignores_invalid_tracked(): void {
        global $DB;

        provisioner::provision();
        $id = self::tracked_ids()[0];
        $DB->set_field('external_tokens', 'validuntil', time() - 10, ['id' => $id]);

        $this->expectException(\coding_exception::class);
        provisioner::with_token(static function (string $token): void {
        });
    }

    /**
     * status() reports whether the tracked token is valid.
     */
    public function test_status_reports_token_valid(): void {
        global $DB;

        provisioner::provision();
        $this->assertTrue(provisioner::status()['token_valid']);

        $id = self::tracked_ids()[0];
        $DB->set_field('external_tokens', 'validuntil', time() - 10, ['id' => $id]);

        $this->assertFalse(provisioner::status()['token_valid']);
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
     * @param string $name token name
     * @return int created token row id
     */
    private static function create_manual_token(int $serviceid, int $userid,
            string $name = 'manual-admin'): int {
        global $DB;

        $token = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            \core_external\util::get_service_by_id($serviceid),
            $userid,
            \context_system::instance(),
            0,
            '',
            $name
        );
        $id = (int) $DB->get_field('external_tokens', 'id', ['token' => $token]);
        unset($token);

        return $id;
    }
}
