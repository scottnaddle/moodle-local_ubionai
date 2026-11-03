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
 * Tests for the site secret storage, HKDF derivation and message authenticators.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ubionai\registration;

use local_ubionai\local\oauth;
use local_ubionai\registration_fake_hub;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/registration_fake_hub.php');

/**
 * Tests for the site secret storage, HKDF derivation and message authenticators.
 *
 * @covers \local_ubionai\registration\secrets
 * @covers \local_ubionai\registration\shared_secret_authenticator
 * @covers \local_ubionai\registration\signature_authenticator
 * @covers \local_ubionai\registration\registrar
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(secrets::class)]
#[CoversClass(shared_secret_authenticator::class)]
#[CoversClass(signature_authenticator::class)]
#[CoversClass(registrar::class)]
final class secrets_test extends \advanced_testcase {
    /**
     * Reset after each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('site_key', registration_fake_hub::SITE_KEY, 'local_ubionai');
    }

    /**
     * Secrets are stored encrypted only, and promotion keeps exactly one current secret.
     */
    public function test_secrets_are_encrypted(): void {
        secrets::store_next(registration_fake_hub::SECRET, 1);
        $raw = (string) get_config('local_ubionai', 'site_secret_next');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString(registration_fake_hub::SECRET, $raw);
        $this->assertSame(registration_fake_hub::SECRET, \core\encryption::decrypt($raw));
        $this->assertNull(secrets::current_version());
        $this->assertSame(1, secrets::next_version());

        secrets::promote_next();
        $this->assertSame(1, secrets::current_version());
        $this->assertNull(secrets::next_version());
        $this->assertFalse(get_config('local_ubionai', 'site_secret_next'));
        $this->assertSame(registration_fake_hub::SECRET, secrets::reveal('current'));

        secrets::store_next(registration_fake_hub::SECRET2, 2);
        secrets::promote_next();
        $this->assertSame(registration_fake_hub::SECRET2, secrets::reveal('current'));

        // No config value of the plugin holds a plaintext secret, and the MAC key is never stored.
        $key = secrets::mac_key(2);
        foreach ((array) get_config('local_ubionai') as $value) {
            $this->assertStringNotContainsString(registration_fake_hub::SECRET, (string) $value);
            $this->assertStringNotContainsString(registration_fake_hub::SECRET2, (string) $value);
            $this->assertStringNotContainsString($key, (string) $value);
            $this->assertStringNotContainsString(bin2hex($key), (string) $value);
        }

        $this->expectException(\coding_exception::class);
        secrets::store_next('short', 3);
    }

    /**
     * hash_hkdf follows RFC 5869 (test case 1) with the argument order this plugin uses.
     */
    public function test_hkdf_rfc5869(): void {
        $okm = hash_hkdf(
            'sha256',
            str_repeat("\x0b", 22),
            42,
            hex2bin('f0f1f2f3f4f5f6f7f8f9'),
            hex2bin('000102030405060708090a0b0c')
        );
        $this->assertSame(
            '3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865',
            bin2hex($okm)
        );
    }

    /**
     * The MAC key is HKDF-SHA256(ikm = secret, salt = "ubionai-site-v1", info = "oauth-mac|<key>|v<n>").
     */
    public function test_mac_key_derivation(): void {
        $info = 'oauth-mac|' . registration_fake_hub::SITE_KEY . '|v1';
        $prk = hash_hmac('sha256', registration_fake_hub::SECRET, 'ubionai-site-v1', true);
        $expected = hash_hmac('sha256', $info . "\x01", $prk, true);

        $this->assertSame($info, secrets::hkdf_info(registration_fake_hub::SITE_KEY, 1));
        $this->assertSame($expected, secrets::derive_mac_key(registration_fake_hub::SECRET, registration_fake_hub::SITE_KEY, 1));

        secrets::store_next(registration_fake_hub::SECRET, 1);
        secrets::promote_next();
        $this->assertSame($expected, secrets::mac_key(1));
        $this->assertNull(secrets::mac_key(2));
    }

    /**
     * The plugin matches the vendored hub interop vectors (tests/fixtures/registration-v1.json, fake values):
     * HKDF MAC key, callback challenge_sha256 and a signed shared_secret message.
     */
    public function test_hub_vectors(): void {
        $file = __DIR__ . '/../fixtures/registration-v1.json';
        $this->assertFileIsReadable($file);
        $data = json_decode((string) file_get_contents($file), true);
        $this->assertIsArray($data);

        // HKDF ikm is the transmitted base64url secret string, not its decoded bytes.
        $secret = (string) $data['site_secret'];
        $sitekey = (string) $data['site_key'];
        $version = (int) $data['secret_version'];
        $this->assertSame($data['hkdf']['info'], secrets::hkdf_info($sitekey, $version));
        $key = secrets::derive_mac_key($secret, $sitekey, $version);
        $this->assertSame($data['hkdf']['length'], strlen($key));
        $this->assertSame($data['mac_key_hex'], bin2hex($key));

        // challenge_sha256 hashes the decoded challenge bytes, not the base64url string.
        $challenge = $data['callback_challenge'];
        $this->assertSame($challenge['challenge_sha256'], registrar::challenge_sha256($challenge['challenge_b64url']));
        $this->assertNotSame($challenge['challenge_sha256'], oauth::b64(hash('sha256', $challenge['challenge_b64url'], true)));

        // Token: base64url(json(sort_keys, compact)) "." base64url(HMAC-SHA256(mac_key, "ubionai-oauth-v1." + body)).
        $claims = $data['message']['claims'];
        $token = $data['message']['token'];
        [$body, $mac] = explode('.', $token);
        $sorted = $claims;
        ksort($sorted);
        $this->assertSame($body, oauth::b64(json_encode($sorted, JSON_UNESCAPED_SLASHES)));
        $this->assertSame($mac, oauth::b64(hash_hmac('sha256', shared_secret_authenticator::MAC_PREFIX . $body, $key, true)));
        $this->assertSame($token, shared_secret_authenticator::sign_with_key($sorted, $key));

        // The authenticator accepts the hub token with the stored secret; the MAC key is bound to the vector site_key.
        set_config('site_key', $sitekey, 'local_ubionai');
        secrets::store_next($secret, $version);
        secrets::promote_next();
        $this->assertEquals($claims, registrar::authenticator('shared_secret')->verify($token));
    }

    /**
     * shared_secret messages are checked with the key selected by kv (current or next only).
     */
    public function test_shared_secret_authenticator(): void {
        secrets::store_next(registration_fake_hub::SECRET, 1);
        secrets::promote_next();
        secrets::store_next(registration_fake_hub::SECRET2, 2);
        $key1 = secrets::derive_mac_key(registration_fake_hub::SECRET, registration_fake_hub::SITE_KEY, 1);
        $key2 = secrets::derive_mac_key(registration_fake_hub::SECRET2, registration_fake_hub::SITE_KEY, 2);
        $auth = registrar::authenticator('shared_secret');
        $this->assertSame('shared_secret', $auth->mode());
        $this->assertSame([1, 2], $auth->describe()['kv']);

        $claims = ['typ' => 'exchange', 'kv' => 1, 'exp' => time() + 60];
        $this->assertSame($claims, $auth->verify(shared_secret_authenticator::sign_with_key($claims, $key1)));
        $claims['kv'] = 2;
        $this->assertSame($claims, $auth->verify(shared_secret_authenticator::sign_with_key($claims, $key2)));

        $bad = [
            shared_secret_authenticator::sign_with_key(['kv' => 1] + $claims, $key2),
            shared_secret_authenticator::sign_with_key(['kv' => 3] + $claims, $key1),
            shared_secret_authenticator::sign_with_key(['typ' => 'exchange'], $key1),
            shared_secret_authenticator::sign_with_key($claims, $key2) . 'A',
            'not-a-token',
        ];
        foreach ($bad as $token) {
            try {
                $auth->verify($token);
                $this->fail('accepted ' . $token);
            } catch (\moodle_exception $e) {
                $this->assertSame('oauth_badrequest', $e->errorcode);
            }
        }
    }

    /**
     * The signature mode is a placeholder (option 2) and refuses to work.
     */
    public function test_signature_stub(): void {
        $auth = registrar::authenticator('signature');
        $this->assertSame('signature', $auth->mode());
        $this->expectException(\coding_exception::class);
        $auth->verify('a.b.c');
    }
}
