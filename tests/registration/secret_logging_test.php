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
 * The site secret and the derived MAC keys never reach debugging, mtrace, error_log or log events.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ubionai\registration;

use local_ubionai\local\setup;
use local_ubionai\registration_fake_hub;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/registration_fake_hub.php');

/**
 * Decided 2026-11-03 (Scott, contract §8.3 #5): HKDF / hash design unchanged, secrets never logged.
 *
 * Every registration path (register, confirm, rotate, retry task, hub errors, a plugin exception,
 * re-registration, MAC verification, withdrawal) runs with output, debugging, error_log and events
 * captured; no form of site_secret or mac_key may appear in any of them. All values are fake.
 *
 * @covers \local_ubionai\registration\registrar
 * @covers \local_ubionai\registration\secrets
 * @covers \local_ubionai\registration\shared_secret_authenticator
 * @covers \local_ubionai\task\retry_registration
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(registrar::class)]
#[CoversClass(secrets::class)]
#[CoversClass(shared_secret_authenticator::class)]
#[CoversClass(\local_ubionai\task\retry_registration::class)]
final class secret_logging_test extends \advanced_testcase {
    /** @var registration_fake_hub */
    private $hub;
    /** @var string|false previous error_log ini value */
    private $errorlog;
    /** @var string temporary error_log file */
    private $logfile;

    /**
     * Fake hub (that can throw an exception carrying the secret), error_log redirected to a file.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->debug = DEBUG_DEVELOPER;
        set_config('hub_url', registration_fake_hub::BASE, 'local_ubionai');
        set_config('self_registration', 1, 'local_ubionai');
        set_config('consent', 'accepted', 'local_ubionai');
        $this->hub = new class extends registration_fake_hub {
            /** @var string|null throw on the next call to this endpoint, with the secret in the message */
            public $throwon = null;

            /**
             * Optionally fail like a broken transport that puts the request in its exception message.
             *
             * @param string $method GET|POST
             * @param string $url full URL
             * @param string|null $body JSON body
             * @param string[] $headers request headers
             * @return array
             */
            protected function send(string $method, string $url, ?string $body, array $headers): array {
                if ($this->throwon !== null && substr($url, -strlen($this->throwon)) === $this->throwon) {
                    $this->throwon = null;
                    throw new \RuntimeException('transport failed: ' . implode(' ', $headers) . ' ' . $body);
                }
                return parent::send($method, $url, $body, $headers);
            }
        };
        registrar::set_api_for_testing($this->hub);
        precheck::set_resolver_for_testing(static function (string $host): array {
            return ['93.184.215.14'];
        });
        $this->logfile = make_request_directory() . '/error.log';
        touch($this->logfile);
        $this->errorlog = ini_set('error_log', $this->logfile);
    }

    /**
     * Restore error_log and remove the test doubles.
     */
    protected function tearDown(): void {
        if ($this->errorlog !== false) {
            ini_set('error_log', (string) $this->errorlog);
        }
        registrar::set_api_for_testing(null);
        precheck::set_resolver_for_testing(null);
        parent::tearDown();
    }

    /**
     * Encodings a leaked value could take.
     *
     * @param string $value secret (ASCII) or raw key bytes
     * @return string[]
     */
    private static function forms(string $value): array {
        return [
            $value,
            bin2hex($value),
            base64_encode($value),
            rtrim(strtr(base64_encode($value), '+/', '-_'), '='),
        ];
    }

    /**
     * Every registration path, then a search of every log channel for every form of every secret.
     */
    public function test_site_secret_and_mac_key_never_logged(): void {
        global $DB;
        $secret3 = 'phpunit-fake-site-secret-third-' . str_repeat('x', 13);
        $sink = $this->redirectEvents();
        ob_start();

        // 1. Consent registers; confirm blows up with the secret in the exception message.
        $this->hub->queue('register', 201, registration_fake_hub::issued());
        $this->hub->throwon = '/confirm';
        setup::consent_updated('s_local_ubionai_consent');
        $this->assertSame(state::FAILED_ACTION_REQUIRED, state::get());
        $this->assertSame('plugin_error', registrar::status()['last_error']);

        // 2. A hub error whose description echoes the secret, then Retry confirms.
        $this->hub->queue('confirm', 401, ['error' => 'not_a_code', 'error_description' => registration_fake_hub::SECRET]);
        registrar::retry();
        $this->assertSame('bad_response', registrar::status()['last_error']);
        $this->hub->queue('confirm', 200, ['secret_version' => 1, 'secret_state' => 'current']);
        $this->assertSame(state::REGISTERED, registrar::retry());

        // 3. Rotate; confirm is busy; the adhoc task (mtrace) finishes it.
        $this->hub->queue('rotate', 200, registration_fake_hub::issued(2, registration_fake_hub::SECRET2))
            ->queue('confirm', 503, ['error' => 'verifier_busy']);
        $this->assertSame(state::FAILED_RETRYABLE, registrar::rotate());
        set_config('next_retry_at', time() - 1, 'local_ubionai');
        $this->hub->queue('confirm', 200, ['secret_version' => 2, 'secret_state' => 'current']);
        (new \local_ubionai\task\retry_registration())->execute();
        $this->assertSame(state::REGISTERED, state::get());

        // 4. MAC verification with the derived key: a valid and a forged message.
        $key2 = secrets::derive_mac_key(registration_fake_hub::SECRET2, registration_fake_hub::SITE_KEY, 2);
        $claims = ['typ' => 'exchange', 'kv' => 2, 'exp' => time() + 60];
        $auth = registrar::authenticator('shared_secret');
        $this->assertSame($claims, $auth->verify(shared_secret_authenticator::sign_with_key($claims, $key2)));
        try {
            $auth->verify(shared_secret_authenticator::sign_with_key($claims, str_repeat("\0", 32)));
            $this->fail('forged message accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('oauth_badrequest', $e->errorcode);
        }

        // 5. Re-registration as a different site (grants revoked) issues a third secret.
        $this->hub->queue('register', 200, registration_fake_hub::issued(3, $secret3) + ['grants_kept' => false])
            ->queue('confirm', 200, ['secret_version' => 3, 'secret_state' => 'current']);
        $this->assertSame(state::REGISTERED, registrar::register());
        $this->assertFalse(registrar::status()['grants_kept']);
        $key3 = secrets::mac_key(3);
        $this->assertNotNull($key3);

        // 6. Withdrawal sends the current secret one last time.
        $this->hub->queue('deregister', 200, ['status' => 'disabled']);
        set_config('consent', 'declined', 'local_ubionai');
        setup::consent_updated('s_local_ubionai_consent');
        $this->assertSame(state::DEREGISTERED, state::get());

        $output = (string) ob_get_clean();
        $debugging = $this->getDebuggingMessages();
        $this->resetDebugging();
        $events = $sink->get_events();
        $sink->close();

        // The secrets really went over the wire (so a leak would have had something to leak).
        $headers = implode("\n", array_merge(...array_column($this->hub->calls, 'headers')));
        foreach ([registration_fake_hub::SECRET, registration_fake_hub::SECRET2, $secret3] as $secret) {
            $this->assertStringContainsString('Bearer ' . $secret, $headers);
        }
        // And the channels were used.
        $this->assertStringContainsString('local_ubionai registration state', $output);
        $this->assertNotEmpty($debugging);
        $this->assertStringContainsString('local_ubionai registration failed: RuntimeException', $debugging[0]->message);

        $channels = [
            'mtrace/output' => $output,
            'debugging' => json_encode(array_map(static function ($d): array {
                return [$d->message, $d->from ?? ''];
            }, $debugging)),
            'error_log' => (string) file_get_contents($this->logfile),
            'events' => json_encode(array_map(static function (\core\event\base $event): array {
                return $event->get_data() + ['description' => $event->get_description()];
            }, $events)),
            'config_log' => json_encode(array_values($DB->get_records('config_log', ['plugin' => 'local_ubionai']))),
            'config_plugins' => json_encode(array_values($DB->get_records('config_plugins', ['plugin' => 'local_ubionai']))),
            'last_error' => (string) get_config('local_ubionai', 'last_error'),
        ];
        $values = [
            'site_secret v1' => registration_fake_hub::SECRET,
            'site_secret v2' => registration_fake_hub::SECRET2,
            'site_secret v3' => $secret3,
            'mac_key v1' => secrets::derive_mac_key(registration_fake_hub::SECRET, registration_fake_hub::SITE_KEY, 1),
            'mac_key v2' => $key2,
            'mac_key v3' => $key3,
        ];
        foreach ($channels as $channel => $text) {
            foreach ($values as $name => $value) {
                foreach (self::forms($value) as $form) {
                    $this->assertStringNotContainsString($form, $text, "{$name} leaked to {$channel}");
                }
            }
        }
    }
}
