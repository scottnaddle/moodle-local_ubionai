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
 * Tests for the domain ownership callback (register_callback.php).
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ubionai\registration;

use local_ubionai\local\oauth;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the domain ownership callback (register_callback.php).
 *
 * @covers \local_ubionai\registration\callback
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(callback::class)]
final class callback_test extends \advanced_testcase {
    /** @var string nonce of the pending registration */
    private $nonce;
    /** @var string challenge of the pending registration */
    private $challenge;

    /**
     * Store a pending registration like registrar::register() does.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('consent', 'accepted', 'local_ubionai');
        $this->nonce = oauth::b64(random_bytes(32));
        $this->challenge = oauth::b64(random_bytes(32));
        $this->pending(time());
    }

    /**
     * Write the pending record.
     *
     * @param int $created creation time
     * @return void
     */
    private function pending(int $created): void {
        set_config('reg_pending', json_encode([
            'nonce' => $this->nonce,
            'challenge' => \core\encryption::encrypt($this->challenge),
            'created' => $created,
        ]), 'local_ubionai');
    }

    /**
     * A valid nonce gets the challenge, wwwroot, plugin version and site identifier hash.
     */
    public function test_valid_nonce(): void {
        global $CFG;
        // Runs without a logged-in user (the hub sends no cookies).
        $this->setUser(null);
        [$status, $body] = callback::handle('GET', $this->nonce);
        $this->assertSame(200, $status);
        $this->assertSame('ubionai-register-callback/v1', $body['typ']);
        $this->assertSame($this->nonce, $body['nonce']);
        $this->assertSame($this->challenge, $body['challenge']);
        $this->assertSame($CFG->wwwroot, $body['wwwroot']);
        $this->assertSame((int) get_config('local_ubionai', 'version'), $body['plugin_version']);
        $this->assertSame(hash('sha256', "ubionai-site-v1\0" . get_site_identifier()), $body['site_identifier_hash']);

        // The same nonce may be fetched again until it expires.
        $this->assertSame(200, callback::handle('GET', $this->nonce)[0]);
    }

    /**
     * Before consent, with a wrong or expired nonce, or without a pending record: the same 404.
     */
    public function test_uniform_404(): void {
        $notfound = [404, ['error' => 'unknown_nonce']];
        $other = oauth::b64(random_bytes(32));
        $this->assertSame($notfound, callback::handle('GET', $other));
        $this->assertSame($notfound, callback::handle('GET', ''));
        $this->assertSame($notfound, callback::handle('GET', substr($this->nonce, 0, 42)));

        $this->pending(time() - registrar::PENDING_TTL - 1);
        $this->assertSame($notfound, callback::handle('GET', $this->nonce));

        $this->pending(time());
        set_config('consent', 'declined', 'local_ubionai');
        $this->assertSame($notfound, callback::handle('GET', $this->nonce));

        set_config('consent', 'accepted', 'local_ubionai');
        unset_config('reg_pending', 'local_ubionai');
        $this->assertSame($notfound, callback::handle('GET', $this->nonce));
    }

    /**
     * Only GET is answered.
     */
    public function test_get_only(): void {
        $this->assertSame(405, callback::handle('POST', $this->nonce)[0]);
        $this->assertSame(405, callback::handle('HEAD', $this->nonce)[0]);
    }
}
