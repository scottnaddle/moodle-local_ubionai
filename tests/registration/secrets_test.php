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
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(secrets::class)]
#[CoversClass(shared_secret_authenticator::class)]
#[CoversClass(signature_authenticator::class)]
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
        $okm = hash_hkdf('sha256', str_repeat("\x0b", 22), 42, hex2bin('f0f1f2f3f4f5f6f7f8f9'),
            hex2bin('000102030405060708090a0b0c'));
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
     * The derivation matches the hub's interop vectors when the file is available.
     */
    public function test_hub_vectors(): void {
        $candidates = array_filter([
            getenv('UBIONAI_REG_VECTORS') ?: null,
            __DIR__ . '/../fixtures/registration-v1.json',
            __DIR__ . '/../../../wt-wp2/tests/vectors/registration-v1.json',
        ]);
        $file = null;
        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                $file = $candidate;
                break;
            }
        }
        if ($file === null) {
            $this->markTestSkipped('registration-v1.json interop vectors not available');
        }
        $data = json_decode((string) file_get_contents($file), true);
        $cases = $data['mac_keys'] ?? $data['vectors'] ?? $data['cases'] ?? (isset($data['site_secret']) ? [$data] : []);
        $this->assertNotEmpty($cases, 'no vectors in ' . $file);
        foreach ($cases as $case) {
            $version = (int) ($case['secret_version'] ?? $case['version'] ?? 0);
            $key = secrets::derive_mac_key((string) $case['site_secret'], (string) $case['site_key'], $version);
            if (isset($case['mac_key_hex'])) {
                $this->assertSame(strtolower($case['mac_key_hex']), bin2hex($key));
            } else {
                $this->assertSame((string) $case['mac_key'], \local_ubionai\local\oauth::b64($key));
            }
        }
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
