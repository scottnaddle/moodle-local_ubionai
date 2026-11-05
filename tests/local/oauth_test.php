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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the per-user OAuth delegation (codes, back channel, token minting and revocation).
 *
 * @covers \local_ubionai\local\oauth
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_ubionai\local\oauth::class)]
final class oauth_test extends \advanced_testcase {
    /** @var string fake shared secret used only in tests */
    private const SECRET = 'phpunit-fake-shared-secret-0123456789abcdef';

    /** @var \stdClass editing teacher */
    private $teacher;
    /** @var \stdClass student */
    private $student;
    /** @var \stdClass course */
    private $course;

    /**
     * Configure the plugin and create a course with a teacher and a student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('hub_url', 'https://hub.example', 'local_ubionai');
        set_config('hub_site_id', 'demo', 'local_ubionai');
        set_config('oauth_secret', self::SECRET, 'local_ubionai');
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->teacher = $gen->create_and_enrol($this->course, 'editingteacher');
        $this->student = $gen->create_and_enrol($this->course, 'student');
    }

    /**
     * Claims of a valid hub message.
     *
     * @param string $typ message type
     * @param array $extra extra claims
     * @return array
     */
    private function claims(string $typ, array $extra = []): array {
        global $CFG;
        return array_merge([
            'typ' => $typ, 'aud' => $CFG->wwwroot, 'site' => 'demo', 'exp' => time() + 60,
            'iss' => 'https://hub.example/t/demo',
        ], $extra);
    }

    /**
     * A verified authorization request.
     *
     * @return array
     */
    private function authz(): array {
        return oauth::verify_authorize_request(oauth::sign($this->claims('authz_req', [
            'state' => str_repeat('a', 32), 'callback' => 'https://hub.example/t/demo/oauth/callback',
            'client_name' => 'Claude', 'redirect_host' => 'claude.ai', 'scope' => 'moodle:read moodle:write',
        ])));
    }

    /**
     * Exchange assertion for a code.
     *
     * @param string $code code
     * @param string|null $state state
     * @return string
     */
    private function exchange_assertion(string $code, ?string $state = null): string {
        return oauth::sign($this->claims('exchange', ['code' => $code, 'state' => $state ?? str_repeat('a', 32)]));
    }

    /**
     * Signed messages are bound to type, audience, site, expiry and the secret.
     */
    public function test_verify_rejects_tampering(): void {
        $this->assertSame('demo', oauth::verify(oauth::sign($this->claims('exchange')), 'exchange')['site']);
        $bad = [
            oauth::sign($this->claims('revoke')),
            oauth::sign($this->claims('exchange', ['aud' => 'https://other.example'])),
            oauth::sign($this->claims('exchange', ['site' => 'other'])),
            oauth::sign($this->claims('exchange', ['exp' => time() - 1])),
            oauth::sign($this->claims('exchange'), str_repeat('x', 40)),
            oauth::sign($this->claims('exchange')) . 'A',
            'not-a-token',
        ];
        foreach ($bad as $token) {
            try {
                oauth::verify($token, 'exchange');
                $this->fail('accepted ' . $token);
            } catch (\moodle_exception $e) {
                $this->assertSame('oauth_badrequest', $e->errorcode);
            }
        }
    }

    /**
     * The browser request may only send the user back to the configured hub callback.
     */
    public function test_authorize_request_callback_must_be_configured_hub(): void {
        $this->assertSame(str_repeat('a', 32), $this->authz()['state']);
        $this->expectException(\moodle_exception::class);
        oauth::verify_authorize_request(oauth::sign($this->claims('authz_req', [
            'state' => str_repeat('a', 32), 'callback' => 'https://evil.example/t/demo/oauth/callback',
        ])));
    }

    /**
     * Without a shared secret nothing is accepted.
     */
    public function test_not_configured(): void {
        set_config('oauth_secret', 'short', 'local_ubionai');
        $this->assertFalse(oauth::is_configured());
        $this->expectException(\moodle_exception::class);
        oauth::verify('a.b', 'exchange');
    }

    /**
     * Site administrators and students cannot connect; teachers can.
     */
    public function test_require_can_connect(): void {
        oauth::require_can_connect((int) $this->teacher->id);
        foreach ([[get_admin()->id, 'oauth_siteadmin'], [$this->student->id, 'oauth_notteacher']] as [$userid, $error]) {
            try {
                oauth::require_can_connect((int) $userid);
                $this->fail('allowed ' . $userid);
            } catch (\moodle_exception $e) {
                $this->assertSame($error, $e->errorcode);
            }
        }
    }

    /**
     * A non-editing teacher can connect too.
     */
    public function test_noneditingteacher_can_connect(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        oauth::require_can_connect((int) $teacher->id);
        $this->assertNotEmpty(oauth::issue_code((int) $teacher->id, $this->authz()));
    }

    /**
     * Admins never get a code.
     */
    public function test_issue_code_refuses_siteadmin(): void {
        $this->expectExceptionMessage(get_string('oauth_siteadmin', 'local_ubionai'));
        oauth::issue_code((int) get_admin()->id, $this->authz());
    }

    /**
     * Consent -> code -> exchange mints a ubionai_user token, assigns the minimal role and authorises
     * the user on the restricted service. The code is single use.
     */
    public function test_exchange_mints_user_service_token(): void {
        global $DB;
        $code = oauth::issue_code((int) $this->teacher->id, $this->authz());
        $result = oauth::exchange($this->exchange_assertion($code));

        $this->assertSame((int) $this->teacher->id, $result['userid']);
        $token = $DB->get_record('external_tokens', ['id' => $result['tokenid']], '*', MUST_EXIST);
        $service = $DB->get_record('external_services', ['shortname' => 'ubionai_user'], '*', MUST_EXIST);
        $this->assertSame($result['wstoken'], $token->token);
        $this->assertEquals($service->id, $token->externalserviceid);
        $this->assertEquals($this->teacher->id, $token->userid);
        $this->assertEquals($this->teacher->id, $token->creatorid);
        $this->assertSame('ubionai-user', $token->name);
        $this->assertGreaterThan(time() + 29 * DAYSECS, (int) $token->validuntil);
        $this->assertTrue($DB->record_exists(
            'external_services_users',
            ['externalserviceid' => $service->id, 'userid' => $this->teacher->id]
        ));

        $roleid = $DB->get_field('role', 'id', ['shortname' => setup::ROLE_SHORTNAME], MUST_EXIST);
        $syscontext = \context_system::instance();
        $this->assertTrue(user_has_role_assignment($this->teacher->id, $roleid, $syscontext->id));
        $this->assertTrue(has_capability('webservice/rest:use', $syscontext, $this->teacher));
        // The minimal role carries nothing else of note.
        $caps = $DB->get_records_menu(
            'role_capabilities',
            ['roleid' => $roleid, 'permission' => CAP_ALLOW],
            '',
            'capability, permission'
        );
        $this->assertSame(['webservice/rest:use'], array_keys($caps));

        try {
            oauth::exchange($this->exchange_assertion($code));
            $this->fail('code reused');
        } catch (\moodle_exception $e) {
            $this->assertSame('oauth_badrequest', $e->errorcode);
        }
    }

    /**
     * The ubionai_user service whitelists the course-authoring helpers the teacher's token may call,
     * and cannot create courses or enrol users (no course creation via MCP).
     */
    public function test_user_service_function_list(): void {
        global $DB;
        $service = $DB->get_record('external_services', ['shortname' => oauth::SERVICE], '*', MUST_EXIST);
        $functions = $DB->get_fieldset_select(
            'external_services_functions',
            'functionname',
            'externalserviceid = ?',
            [$service->id]
        );
        $this->assertContains('core_course_get_contents', $functions);
        $this->assertContains('local_ubionai_add_sections', $functions);
        $this->assertContains('local_ubionai_add_quiz_questions', $functions);
        $this->assertContains('local_ubionai_delete_modules', $functions);
        $this->assertContains('local_ubionai_delete_sections', $functions);
        $this->assertNotContains('core_course_create_courses', $functions);
        $this->assertNotContains('enrol_manual_enrol_users', $functions);
    }

    /**
     * Codes are bound to the hub request state and expire.
     */
    public function test_exchange_state_and_expiry(): void {
        global $DB;
        $code = oauth::issue_code((int) $this->teacher->id, $this->authz());
        try {
            oauth::exchange($this->exchange_assertion($code, str_repeat('b', 32)));
            $this->fail('wrong state accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('oauth_badrequest', $e->errorcode);
        }
        // The failed attempt burnt the code.
        $this->assertSame(0, $DB->count_records('local_ubionai_codes'));

        $code = oauth::issue_code((int) $this->teacher->id, $this->authz());
        $DB->set_field('local_ubionai_codes', 'timeexpires', time() - 1);
        $this->expectException(\moodle_exception::class);
        oauth::exchange($this->exchange_assertion($code));
    }

    /**
     * A user who became site admin after consent is refused at exchange time.
     */
    public function test_exchange_refuses_user_promoted_to_admin(): void {
        global $CFG;
        $code = oauth::issue_code((int) $this->teacher->id, $this->authz());
        set_config('siteadmins', $CFG->siteadmins . ',' . $this->teacher->id);
        $this->expectExceptionMessage(get_string('oauth_siteadmin', 'local_ubionai'));
        oauth::exchange($this->exchange_assertion($code));
    }

    /**
     * Minting refuses other services (moodle_mobile_app) and site administrators.
     */
    public function test_mint_refuses_other_service_and_admin(): void {
        foreach (
            [[(int) $this->teacher->id, 'moodle_mobile_app', 'oauth_wrongservice'],
                [(int) get_admin()->id, oauth::SERVICE, 'oauth_siteadmin']] as [$userid, $service, $error]
        ) {
            try {
                oauth::mint_token($userid, $service);
                $this->fail("minted $service for $userid");
            } catch (\moodle_exception $e) {
                $this->assertSame($error, $e->errorcode);
            }
        }
    }

    /**
     * Revocation only touches ubionai_user tokens of that user; the role goes with the last token.
     */
    public function test_revoke_only_user_service_tokens(): void {
        global $DB;
        $mobile = $DB->get_record('external_services', ['shortname' => 'moodle_mobile_app'], '*', MUST_EXIST);
        $this->setUser($this->teacher);
        $mobiletoken = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $mobile,
            (int) $this->teacher->id,
            \context_system::instance()
        );
        $mobileid = (int) $DB->get_field('external_tokens', 'id', ['token' => $mobiletoken]);
        $this->setAdminUser();

        [, $first] = oauth::mint_token((int) $this->teacher->id);
        [, $second] = oauth::mint_token((int) $this->teacher->id);
        $this->assertSame([$second, $first], array_map('intval', array_keys(oauth::user_tokens((int) $this->teacher->id))));

        // Not a ubionai_user token -> refused, still there.
        $this->assertFalse(oauth::revoke_token((int) $this->teacher->id, $mobileid));
        $this->assertFalse(oauth::revoke_from_hub(oauth::sign($this->claims(
            'revoke',
            ['userid' => (int) $this->teacher->id, 'tokenid' => $mobileid]
        ))));
        $this->assertTrue($DB->record_exists('external_tokens', ['id' => $mobileid]));
        // Someone else's token -> refused.
        $this->assertFalse(oauth::revoke_token((int) $this->student->id, $first));

        $roleid = $DB->get_field('role', 'id', ['shortname' => setup::ROLE_SHORTNAME]);
        $syscontextid = \context_system::instance()->id;
        $this->assertTrue(oauth::revoke_token((int) $this->teacher->id, $first));
        $this->assertTrue(user_has_role_assignment($this->teacher->id, $roleid, $syscontextid));
        $this->assertTrue(oauth::revoke_from_hub(oauth::sign($this->claims(
            'revoke',
            ['userid' => (int) $this->teacher->id, 'tokenid' => $second]
        ))));
        $this->assertSame([], oauth::user_tokens((int) $this->teacher->id));
        $this->assertFalse(user_has_role_assignment($this->teacher->id, $roleid, $syscontextid));
        $this->assertFalse($DB->record_exists('external_services_users', ['userid' => $this->teacher->id]));
        $this->assertTrue($DB->record_exists('external_tokens', ['id' => $mobileid]));
    }
}
