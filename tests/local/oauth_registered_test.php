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

use local_ubionai\registration\secrets;
use local_ubionai\registration\shared_secret_authenticator;
use local_ubionai\registration\state;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for oauth.php on a self-registered site (shared_secret, /m/<host_key> callback) and its
 * fallback to the legacy oauth_secret + /t/ path.
 *
 * @covers \local_ubionai\local\oauth
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_ubionai\local\oauth::class)]
final class oauth_registered_test extends \advanced_testcase {
    /** Hub base URL used by the tests. */
    private const HUB = 'https://hub.example.test';
    /** Site key the fake hub assigned. */
    private const SITE_KEY = 'www-example-com-moodle-3f9a1c';
    /** Fake site secret (test only). */
    private const SECRET = 'phpunit-fake-site-secret-0123456789abcdefgh';
    /** Second fake site secret (test only). */
    private const SECRET2 = 'phpunit-fake-site-secret-second-0123456789a';
    /** Fake legacy shared secret (test only). */
    private const LEGACY = 'phpunit-fake-shared-secret-0123456789abcdef';
    /** Issuer of the /m/ URL family. */
    private const ISS = self::HUB . '/m/www.example.com/moodle';
    /** Issuer of the /t/ URL family. */
    private const LEGACY_ISS = self::HUB . '/t/' . self::SITE_KEY;

    /** @var \stdClass editing teacher */
    private $teacher;

    /**
     * A site registered with secret version 3 that also still has a legacy oauth_secret.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('hub_url', self::HUB, 'local_ubionai');
        set_config('hub_site_id', 'demo', 'local_ubionai');
        set_config('oauth_secret', self::LEGACY, 'local_ubionai');
        set_config('self_registration', 1, 'local_ubionai');
        set_config('site_key', self::SITE_KEY, 'local_ubionai');
        secrets::store_next(self::SECRET, 3);
        secrets::promote_next();
        set_config('allowed_callbacks', json_encode([
            self::ISS . '/oauth/callback', self::LEGACY_ISS . '/oauth/callback',
        ]), 'local_ubionai');
        state::set(state::REGISTERED);
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $this->teacher = $gen->create_and_enrol($course, 'editingteacher');
    }

    /**
     * Claims of a valid hub message on the /m/ family.
     *
     * @param string $typ message type
     * @param array $extra extra claims
     * @return array
     */
    private function claims(string $typ, array $extra = []): array {
        global $CFG;
        // A null value drops the claim.
        return array_filter(array_merge([
            'typ' => $typ, 'iss' => self::ISS, 'aud' => $CFG->wwwroot, 'site' => self::SITE_KEY,
            'exp' => time() + 60, 'kv' => 3,
        ], $extra), static function ($value): bool {
            return $value !== null;
        });
    }

    /**
     * Authorization request claims.
     *
     * @param array $extra extra claims
     * @return array
     */
    private function authz_claims(array $extra = []): array {
        return $this->claims('authz_req', array_merge([
            'state' => str_repeat('a', 32), 'callback' => self::ISS . '/oauth/callback',
            'client_name' => 'Claude', 'redirect_host' => 'claude.ai', 'scope' => 'moodle:read moodle:write',
        ], $extra));
    }

    /**
     * Sign like the hub: HMAC with the HKDF mac_key of a secret version.
     *
     * @param array $claims claims
     * @param string $secret site secret
     * @param int $version secret version used for the key
     * @return string
     */
    private function hub_sign(array $claims, string $secret = self::SECRET, int $version = 3): string {
        return shared_secret_authenticator::sign_with_key(
            $claims,
            secrets::derive_mac_key($secret, self::SITE_KEY, $version)
        );
    }

    /**
     * Assert that a token is refused as a bad request.
     *
     * @param string $token signed message
     * @param string $label what is being tested
     * @param bool $authorize verify as an authorization request instead of an exchange
     */
    private function assert_rejected(string $token, string $label, bool $authorize = false): void {
        try {
            $authorize ? oauth::verify_authorize_request($token) : oauth::verify($token, 'exchange');
            $this->fail('accepted: ' . $label);
        } catch (\moodle_exception $e) {
            $this->assertSame('oauth_badrequest', $e->errorcode, $label);
        }
    }

    /**
     * A registered site uses the site key, the derived MAC key and the /m/ callback only.
     */
    public function test_registered_mode_and_callback(): void {
        $this->assertSame(oauth::MODE_SHARED, oauth::mode());
        $this->assertTrue(oauth::is_configured());
        $this->assertSame(self::ISS . '/oauth/callback', oauth::callback_url());
        $this->assertSame([self::ISS . '/oauth/callback'], oauth::callback_urls());

        $claims = oauth::verify_authorize_request($this->hub_sign($this->authz_claims()));
        $this->assertSame(self::ISS . '/oauth/callback', $claims['callback']);
        $this->assertSame(self::SITE_KEY, $claims['site']);
        // Without a key, oauth::sign() follows the mode: current mac_key plus kv.
        $this->assertSame(3, oauth::verify(oauth::sign($this->claims('exchange', ['kv' => null])), 'exchange')['kv']);
        $this->assertSame('shared_secret', \local_ubionai\registration\registrar::status()['auth_mode']);
    }

    /**
     * Wrong callbacks are refused even when correctly signed.
     */
    public function test_registered_rejects_bad_callback(): void {
        $bad = [
            '/t/ callback' => ['callback' => self::LEGACY_ISS . '/oauth/callback', 'iss' => self::LEGACY_ISS],
            'other host' => ['callback' => 'https://evil.example/m/www.example.com/moodle/oauth/callback'],
            'other /m/ host key' => ['callback' => self::HUB . '/m/other.example/oauth/callback'],
            'iss does not match callback' => ['iss' => self::HUB . '/m/other.example'],
            'callback suffix' => ['callback' => self::ISS . '/oauth/callback/x'],
            'no callback' => ['callback' => null],
        ];
        foreach ($bad as $label => $extra) {
            $this->assert_rejected($this->hub_sign($this->authz_claims($extra)), $label, true);
        }
    }

    /**
     * Signatures, kv, aud, site, iss, type and expiry are all checked.
     */
    public function test_registered_rejects_bad_signature_and_claims(): void {
        $legacy = oauth::sign($this->claims('exchange', ['kv' => null, 'site' => 'demo']), self::LEGACY);
        $bad = [
            'legacy oauth_secret MAC' => $legacy,
            'legacy MAC with site key' => oauth::sign($this->claims('exchange'), self::LEGACY),
            'secret of another site' => $this->hub_sign($this->claims('exchange'), self::SECRET2),
            'key of another version' => $this->hub_sign($this->claims('exchange'), self::SECRET, 2),
            'unknown kv' => $this->hub_sign($this->claims('exchange', ['kv' => 2]), self::SECRET, 2),
            'kv as string' => $this->hub_sign($this->claims('exchange', ['kv' => '3'])),
            'no kv' => $this->hub_sign($this->claims('exchange', ['kv' => null])),
            'legacy site id' => $this->hub_sign($this->claims('exchange', ['site' => 'demo'])),
            'other audience' => $this->hub_sign($this->claims('exchange', ['aud' => 'https://other.example'])),
            '/t/ issuer' => $this->hub_sign($this->claims('exchange', ['iss' => self::LEGACY_ISS])),
            'no issuer' => $this->hub_sign($this->claims('exchange', ['iss' => null])),
            'wrong type' => $this->hub_sign($this->claims('revoke')),
            'expired' => $this->hub_sign($this->claims('exchange', ['exp' => time() - 1])),
            'tampered MAC' => $this->hub_sign($this->claims('exchange')) . 'A',
            'garbage' => 'not-a-token',
        ];
        $this->assertSame(self::SITE_KEY, oauth::verify($this->hub_sign($this->claims('exchange')), 'exchange')['site']);
        foreach ($bad as $label => $token) {
            $this->assert_rejected($token, $label);
        }
    }

    /**
     * aud is compared with the hub's canonical site URL (lower-case host, no :443, no trailing slash).
     */
    public function test_registered_audience_is_canonical(): void {
        global $CFG;
        $CFG->wwwroot = 'https://WWW.Example.com:443/moodle/';
        $claims = $this->claims('exchange', ['aud' => 'https://www.example.com/moodle']);
        $this->assertSame('exchange', oauth::verify($this->hub_sign($claims), 'exchange')['typ']);
        $this->assert_rejected($this->hub_sign($this->claims('exchange', ['aud' => $CFG->wwwroot])), 'raw wwwroot');
    }

    /**
     * During a rotation the not yet promoted (next) version is accepted too; older ones are not.
     */
    public function test_registered_accepts_next_version(): void {
        secrets::store_next(self::SECRET2, 4);
        $next = $this->hub_sign($this->claims('exchange', ['kv' => 4]), self::SECRET2, 4);
        $this->assertSame(4, oauth::verify($next, 'exchange')['kv']);
        secrets::promote_next();
        $this->assert_rejected($this->hub_sign($this->claims('exchange')), 'retired version 3');
    }

    /**
     * Code exchange and hub revocation work end to end with shared_secret messages.
     */
    public function test_registered_exchange_and_revoke(): void {
        global $DB;
        $authz = oauth::verify_authorize_request($this->hub_sign($this->authz_claims()));
        $code = oauth::issue_code((int) $this->teacher->id, $authz);
        // A legacy-signed exchange for the same code is refused and does not burn the code.
        $redeem = ['code' => $code, 'state' => str_repeat('a', 32)];
        $legacy = $this->claims('exchange', $redeem + ['kv' => null, 'site' => 'demo', 'iss' => self::HUB . '/t/demo']);
        try {
            oauth::exchange(oauth::sign($legacy, self::LEGACY));
            $this->fail('accepted a legacy exchange');
        } catch (\moodle_exception $e) {
            $this->assertSame('oauth_badrequest', $e->errorcode);
        }
        $result = oauth::exchange($this->hub_sign($this->claims('exchange', $redeem)));
        $this->assertSame((int) $this->teacher->id, $result['userid']);
        $this->assertTrue($DB->record_exists('external_tokens', ['id' => $result['tokenid']]));

        $this->assertTrue(oauth::revoke_from_hub($this->hub_sign($this->claims('revoke', [
            'userid' => $result['userid'], 'tokenid' => $result['tokenid'],
        ]))));
        $this->assertFalse($DB->record_exists('external_tokens', ['id' => $result['tokenid']]));
    }

    /**
     * A registered site without a usable /m/ callback on the configured hub accepts nothing.
     */
    public function test_registered_without_m_callback_is_not_configured(): void {
        set_config('allowed_callbacks', json_encode([self::LEGACY_ISS . '/oauth/callback']), 'local_ubionai');
        $this->assertFalse(oauth::is_configured());
        $this->assertNull(oauth::callback_url());
        try {
            oauth::verify($this->hub_sign($this->claims('exchange')), 'exchange');
            $this->fail('accepted without callback');
        } catch (\moodle_exception $e) {
            $this->assertSame('oauth_notconfigured', $e->errorcode);
        }
        // Callbacks stored for another hub do not follow a changed hub_url.
        set_config('allowed_callbacks', json_encode([self::ISS . '/oauth/callback']), 'local_ubionai');
        set_config('hub_url', 'https://other-hub.example.test', 'local_ubionai');
        $this->assertFalse(oauth::is_configured());
    }

    /**
     * A failed rotation (reg_state failed_retryable, next secret pending) keeps the current secret.
     */
    public function test_registered_survives_failed_rotation(): void {
        secrets::store_next(self::SECRET2, 4);
        state::set(state::FAILED_RETRYABLE);
        $this->assertSame(oauth::MODE_SHARED, oauth::mode());
        $this->assertSame(3, oauth::verify($this->hub_sign($this->claims('exchange')), 'exchange')['kv']);
    }

    /**
     * Never confirmed, consent withdrawn, or the self-registration flag off: the legacy path is used.
     */
    public function test_legacy_fallback(): void {
        global $CFG;
        $legacyclaims = [
            'typ' => 'exchange', 'aud' => $CFG->wwwroot, 'site' => 'demo', 'exp' => time() + 60,
            'iss' => self::HUB . '/t/demo',
        ];
        $cases = [
            'flag off' => static function (): void {
                set_config('self_registration', 0, 'local_ubionai');
            },
            'deregistered' => static function (): void {
                state::set(state::DEREGISTERED);
            },
            'never confirmed' => static function (): void {
                secrets::clear();
                secrets::store_next(self::SECRET, 3);
                state::set(state::PENDING_CONFIRM);
            },
        ];
        foreach ($cases as $label => $arrange) {
            $arrange();
            $this->assertSame(oauth::MODE_LEGACY, oauth::mode(), $label);
            $this->assertTrue(oauth::is_configured(), $label);
            $this->assertSame(self::HUB . '/t/demo/oauth/callback', oauth::callback_url(), $label);
            $this->assertSame('demo', oauth::verify(oauth::sign($legacyclaims), 'exchange')['site'], $label);
            $this->assert_rejected($this->hub_sign($this->claims('exchange')), $label . ': shared_secret message');
            $mcallback = oauth::sign($this->authz_claims(['kv' => null, 'site' => 'demo']), self::LEGACY);
            $this->assert_rejected($mcallback, $label . ': /m/ callback', true);
            // Back to the registered set-up for the next case.
            set_config('self_registration', 1, 'local_ubionai');
            state::set(state::REGISTERED);
        }
    }
}
