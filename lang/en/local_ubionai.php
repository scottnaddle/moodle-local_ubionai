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
 * English strings for local_ubionai.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'UBION AI';

// Privacy.
$string['privacy:metadata:ubionai_hub'] = 'The MCP server / UBION AI hub retrieves data from this site through its Moodle web service and passes it to an external LLM provider so that staff requests (for example course content or grading assistance) can be answered. The plugin itself stores no personal data in Moodle. Deletion of data held by the hub must be requested from the hub operator.';
$string['privacy:metadata:ubionai_hub:fullname'] = 'The user\'s full name is sent so generated content can be attributed and so the hub can address the requesting staff member.';
$string['privacy:metadata:ubionai_hub:email'] = 'The user\'s email address is sent for identification and notification purposes.';
$string['privacy:metadata:ubionai_hub:grades'] = 'Grade data may be sent to the hub for grading assistance and reporting.';
$string['privacy:metadata:ubionai_hub:submissions'] = 'Student assignment and quiz submissions may be sent to the hub for feedback generation.';
$string['privacy:metadata:ubionai_hub:forumauthors'] = 'Forum post authors and post content may be sent to the hub for summarisation.';
$string['privacy:metadata:ubionai_hub:coursecontent'] = 'Course content such as page text, resources and activity settings may be sent to the hub for content generation.';
$string['privacy:metadata:ubionai_hub:prompts'] = 'The text of staff prompts is sent to the hub so it can generate responses.';

// Capability.
$string['ubionai:manageconnection'] = 'Manage the UBION AI connection';

// Settings.
$string['connection'] = 'UBION AI connection';
$string['hub_url'] = 'Hub URL';
$string['hub_url_desc'] = 'Base URL of the UBION AI hub used for site registration. Must start with https://. Leave empty to provision the web service locally without registering with a hub.';
$string['hub_url_invalid'] = 'The hub URL must start with https://.';
$string['connectionpage'] = 'Connection status and actions';

// Admin connection page (connect.php).
$string['connectionstatus'] = 'Connection status';
$string['status_connected'] = 'Connected';
$string['status_notconnected'] = 'Not connected';
$string['status_webservices'] = 'Web services enabled';
$string['status_rest'] = 'REST protocol enabled';
$string['status_serviceuser'] = 'Service user';
$string['status_role'] = 'Role';
$string['status_service'] = 'External service';
$string['status_functions'] = 'Functions enabled';
$string['status_authorised'] = 'User authorised for service';
$string['status_tokens'] = 'Active tokens';
$string['status_untracked_tokens'] = '{$a} token(s) for the UBION AI service user were not issued by this plugin and were left in place. Revoke them manually if they are no longer needed.';
$string['status_tokencreated'] = 'Token created';
$string['status_tokenvalid'] = 'Token valid';
$string['status_tokenlastaccess'] = 'Token last used';
$string['status_never'] = 'Never';
$string['hub_configured'] = 'Hub URL configured';
$string['hub_notconfigured'] = 'No hub URL configured';
$string['token_warning_unused'] = 'A token exists but no hub is registered yet; the token is an active credential. Disconnect to remove it, or register the site with a hub.';
$string['connect'] = 'Connect';
$string['connect_warning'] = 'Connecting creates a service user with a manager-archetype role and issues a permanent web service token. The token has no IP restriction unless one was set via the CLI.';
$string['regcode'] = 'Registration code';
$string['regcode_help'] = 'The one-time registration code issued by the hub administrator. The code is used once during registration and is never stored or logged. Leave empty to provision the local web service without registering with the hub.';
$string['disconnect'] = 'Disconnect';
$string['disconnectconfirm'] = 'This deletes only the web service token(s) issued by this plugin for the UBION AI service user. Tokens not issued by this plugin are kept. The user, role and service are kept. Continue?';
$string['connect_success'] = 'Connection provisioned successfully. Web service token issued for the UBION AI service user.';
$string['connect_success_reused'] = 'Connection provisioned successfully. The existing web service token for the UBION AI service user was reused.';
$string['connect_success_adopted'] = 'Connection provisioned successfully. An existing UBION AI token was adopted and is now managed by this plugin.';
$string['token_adopted'] = 'An existing UBION AI token (name ubionai-mcp) was found and is now managed by this plugin; disconnect will revoke it.';
$string['connect_success_nohub'] = 'Provisioned locally; no hub URL is configured, so the site was not registered with a hub.';
$string['connect_hub_needcode'] = 'The hub is configured but the site is not registered yet. Enter the one-time registration code from the hub administrator and connect again.';
$string['hub_status_registered'] = 'The site was registered with the hub.';
$string['hub_status_invalid_hub_url'] = 'The hub URL is not configured or is invalid. It must be an https URL without credentials, a query or a fragment.';
$string['hub_status_invalid_code'] = 'The registration code format is invalid.';
$string['hub_status_code_rejected'] = 'The hub rejected the registration code. It may be invalid, expired, already used, or bound to a different site URL.';
$string['hub_status_already_registered'] = 'The hub already has a tenant for this site. Ask the hub administrator to remove it before registering again.';
$string['hub_status_registration_failed'] = 'The hub could not verify this site with the web service token. Registration failed.';
$string['hub_status_protocol_error'] = 'The hub rejected the registration request format.';
$string['hub_status_hub_unreachable'] = 'The hub could not be reached.';
$string['hub_status_bad_response'] = 'The hub returned an unexpected response.';
$string['hub_siteid'] = 'Hub site ID';
$string['hub_mcp_endpoint'] = 'MCP endpoint';
$string['hub_egress_ip'] = 'Hub egress IP';
$string['hub_registered_at'] = 'Registered with hub';
$string['hub_egress_hint'] = 'If the hub requires IP restriction, allow {$a} (the hub egress IP) in --iprestriction.';
$string['disconnect_hub_note'] = 'The hub tenant was not removed. Ask the hub administrator to delete it before registering this site again, otherwise registration returns "already registered".';
$string['disconnect_success'] = 'Connection removed. {$a} token(s) deleted.';
$string['disconnect_untracked_warning'] = '{$a} token(s) not issued by this plugin were left in place.';

// Events.
$string['event_site_connected'] = 'UBION AI site connected';
$string['event_site_connected_desc'] = 'The UBION AI web service connection was provisioned.';
$string['event_site_disconnected'] = 'UBION AI site disconnected';
$string['event_site_disconnected_desc'] = 'The UBION AI web service token was deleted.';

// CLI.
$string['cli_regcode_argv_warning'] = 'Warning: --regcode is visible in the process list and shell history. Prefer --regcode-file or --regcode-file=-.';
$string['cli_iprestriction_invalid'] = 'Invalid --iprestriction value: {$a}';
$string['cli_regcode_perms_warning'] = 'Warning: the registration code file is group- or world-readable. Restrict it to the Moodle process user (for example chmod 600).';
$string['cli_usage'] = 'Usage:
    php local/ubionai/cli/connection.php --status
    php local/ubionai/cli/connection.php --connect [--iprestriction=203.0.113.10] [--regcode-file=PATH]
    php local/ubionai/cli/connection.php --connect [--iprestriction=203.0.113.10] --regcode-file=-
    php local/ubionai/cli/connection.php --connect [--regcode=VALUE]
    php local/ubionai/cli/connection.php --disconnect

--regcode-file=PATH reads the first line of a regular file (at most 1 KiB); use - to read stdin.
--regcode=VALUE is supported but visible in the process list and shell history.
--iprestriction=LIST is a comma-separated list of IP addresses or CIDR networks (IPv4/IPv6).
  Entries are normalised; invalid values exit with code 2. It requires --connect.

Connect is idempotent: an existing valid token is reused, a token named
\'ubionai-mcp\' is adopted, otherwise one is created. The result reports
token_action: reused|adopted|created.';
