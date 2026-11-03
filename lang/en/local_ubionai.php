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
$string['privacy:metadata:ubionai_hub:siteurl'] = 'When the site registers with the hub, the site URL, Moodle and plugin versions and a hash of the site identifier are sent so the hub can verify and identify the site.';
$string['privacy:metadata:ubionai_hub:consent'] = 'The consent record (terms version, accepted scopes and acceptance time) is sent to the hub when the site registers. No administrator name, email or user id is sent.';

// Capability.
$string['ubionai:manageconnection'] = 'Manage the UBION AI connection';

// Settings.
$string['connection'] = 'UBION AI connection';
$string['hub_url'] = 'Hub URL';
$string['hub_url_desc'] = 'Base URL of the UBION AI hub used for site registration. Must start with https://. Advanced: leave empty to use the default hub (https://mcp.ubion.global) for automatic registration. Enter another hub URL here if UBION gives you one. With the manual (legacy) Connect flow an empty value provisions the web service locally without registering with a hub.';
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
$string['disconnectconfirm'] = 'This deletes the web service token(s) tracked by this plugin for the UBION AI service user: the token(s) it issued, and a token adopted by plugin version 0.5.0 if there is one. Other tokens, including a \'ubionai-mcp\' token created by the setup script, are kept. The user, role and service are kept. Continue?';
$string['connect_success'] = 'Connection provisioned successfully. Web service token issued for the UBION AI service user.';
$string['connect_success_reused'] = 'Connection provisioned successfully. The existing web service token for the UBION AI service user was reused.';
$string['connect_success_rotated'] = 'Connection provisioned successfully. An existing \'ubionai-mcp\' token not issued by this plugin was found; it was not reused. A new web service token was issued for the hub instead.';
$string['token_legacy_kept'] = '{$a} \'ubionai-mcp\' token(s) not issued by this plugin (for example by the setup script) were left in place. They are never sent to the hub and Disconnect does not delete them. Once nothing else uses them, revoke them with: php local/ubionai/cli/connection.php --connect --revoke-legacy';
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
    php local/ubionai/cli/connection.php --connect [--iprestriction=203.0.113.10] [--regcode-file=PATH] [--revoke-legacy]
    php local/ubionai/cli/connection.php --connect [--iprestriction=203.0.113.10] --regcode-file=-
    php local/ubionai/cli/connection.php --connect [--regcode=VALUE]
    php local/ubionai/cli/connection.php --disconnect

--regcode-file=PATH reads the first line of a regular file (at most 1 KiB); use - to read stdin.
--regcode=VALUE is supported but visible in the process list and shell history.
--iprestriction=LIST is a comma-separated list of IP addresses or CIDR networks (IPv4/IPv6).
  Entries are normalised; invalid values exit with code 2. It requires --connect.
--revoke-legacy deletes \'ubionai-mcp\' tokens of the service user that this plugin did not
  issue (for example from the setup script). It requires --connect.

Connect is idempotent: a valid token issued by this plugin is reused, otherwise a new one is
created. An existing \'ubionai-mcp\' token not issued by this plugin is never reused or sent to
the hub; it is left in place unless --revoke-legacy is given. The result reports
token_action: reused|rotated|created (rotated = created while such a token exists).';

// Phase 0 spike: post-install consent screen.
$string['consent'] = 'Connect this site to UBION AI (Claude connector)';
$string['consent_desc'] = 'Saving "Agree and connect" sends this site\'s URL, name and version to the UBION AI hub, turns on web services and the REST protocol, and lets teachers connect Claude with their own Moodle login. Course and student data are then processed by the hub and by Anthropic (Claude). Choose "Not now" to stop this prompt; you can connect later from Site administration > Plugins > Local plugins.';
$string['consent_accepted'] = 'Agree and connect';
$string['consent_declined'] = 'Not now';

// Easy Connect spike 2: per-user OAuth.
$string['ubionai:connect'] = 'Connect Claude with own Moodle account';
$string['hub_site_id'] = 'Hub site id';
$string['hub_site_id_desc'] = 'Site id of this Moodle on the hub (the <site> in https://hub/t/<site>/mcp). Given by the hub operator.';
$string['oauth_secret'] = 'Hub OAuth shared secret';
$string['oauth_secret_desc'] = 'Shared secret that signs the messages between the hub and this site for teacher logins (at least 32 characters). Must equal the hub file secrets/tenants/<site>/oauth.secret.';
$string['oauth_notconfigured'] = 'Claude connections are not configured on this site (hub URL, hub site id or shared secret missing).';
$string['oauth_badrequest'] = 'The connection request is invalid or has expired. Start again from Claude.';
$string['oauth_notallowed'] = 'This account cannot connect Claude.';
$string['oauth_siteadmin'] = 'Site administrators cannot connect Claude with their account. Use a teacher account.';
$string['oauth_notteacher'] = 'Only teachers can connect Claude.';
$string['oauth_wrongservice'] = 'Tokens can only be issued for the UBION AI per-user service.';
$string['oauth_noservice'] = 'The UBION AI per-user web service is missing or disabled.';
$string['oauth_consent_title'] = 'Connect Claude to Moodle';
$string['oauth_consent_intro'] = 'An application asks to use Moodle on your behalf, with your own permissions.';
$string['oauth_consent_user'] = 'Moodle account';
$string['oauth_consent_site'] = 'Moodle site';
$string['oauth_consent_client'] = 'Application';
$string['oauth_consent_hub'] = 'Through hub';
$string['oauth_consent_scope'] = 'Access';
$string['oauth_consent_notice'] = 'If you allow, the hub receives a web service token for your account that is limited to the UBION AI functions and expires in 30 days. Course and student data you open from Claude are processed by the hub and by Anthropic. You can revoke the connection at any time from your profile (Claude connections).';
$string['oauth_consent_allow'] = 'Allow';
$string['oauth_consent_deny'] = 'Cancel';
$string['connections'] = 'Claude connections';
$string['connections_none'] = 'You have no Claude connections.';
$string['connections_created'] = 'Connected';
$string['connections_lastused'] = 'Last used';
$string['connections_expires'] = 'Expires';
$string['connections_revoke'] = 'Revoke';
$string['connections_revoked'] = 'Connection revoked.';
$string['privacy:metadata:local_ubionai_codes'] = 'One-time codes issued after a teacher allows a Claude connection; deleted when redeemed (within two minutes).';
$string['privacy:metadata:local_ubionai_codes:userid'] = 'The user who allowed the connection.';
$string['privacy:metadata:local_ubionai_codes:timecreated'] = 'When the code was issued.';

// Self-registration (WP1).
$string['self_registration'] = 'Register automatically with the hub';
$string['self_registration_desc'] = 'When consent is saved, register this site with the UBION AI hub automatically (the hub checks a callback on this site and issues a site secret). Turn off to keep only the manual flow with a hub site id and shared secret from the hub operator.';
$string['task_retry_registration'] = 'Retry UBION AI hub registration';
$string['reg_heading'] = 'Hub registration';
$string['reg_state'] = 'Registration status';
$string['reg_state_awaiting_consent'] = 'Waiting for consent';
$string['reg_state_consented'] = 'Consent given, not registered yet';
$string['reg_state_registering'] = 'Registering';
$string['reg_state_pending_confirm'] = 'Waiting for confirmation by the hub';
$string['reg_state_registered'] = 'Registered';
$string['reg_state_failed_retryable'] = 'Registration failed, retrying automatically';
$string['reg_state_failed_action_required'] = 'Registration failed, action required';
$string['reg_state_unsupported_private'] = 'Not supported: the site is not reachable from the internet';
$string['reg_state_deregistered'] = 'Registration withdrawn';
$string['reg_disabled'] = 'Automatic registration is turned off. The manual connection below is used.';
$string['reg_hub'] = 'Hub';
$string['reg_site_key'] = 'Site key';
$string['reg_secret_version'] = 'Secret version';
$string['reg_tier'] = 'Tier';
$string['reg_tenant_status'] = 'Hub tenant status';
$string['reg_limits'] = 'Limits';
$string['reg_tier_limited'] = 'Limited (lowest tier for self-registered sites)';
$string['reg_tier_standard'] = 'Standard';
$string['reg_tenant_status_active'] = 'Active';
$string['reg_tenant_status_pending'] = 'Pending (waiting for the hub operator)';
$string['reg_tenant_status_disabled'] = 'Disabled by the hub operator';
$string['reg_tenant_pending'] = 'The hub registered this site but has not activated it yet. Teachers can connect once the hub operator activates it.';
$string['reg_grants_kept'] = 'Re-registered as the same Moodle site: existing teacher connections were kept.';
$string['reg_grants_revoked'] = 'Re-registered, but the hub did not recognise this as the same Moodle site (the site identifier changed), so it revoked every teacher connection. Teachers must connect again.';
$string['reg_limit_max_teachers'] = 'Teachers: {$a}';
$string['reg_limit_daily_requests'] = 'Requests per day: {$a}';
$string['reg_limit_concurrency'] = 'Concurrent requests: {$a}';
$string['reg_limit_llm_proxy'] = 'Hub LLM proxy: {$a}';
$string['reg_limits_help'] = 'Provisional limits set by the hub operator. A teacher beyond the teacher limit cannot connect (teachers already connected keep working); requests beyond the daily limit are refused until 00:00 UTC.';
$string['reg_last_error'] = 'Last error';
$string['reg_last_attempt'] = 'Last attempt';
$string['reg_next_retry'] = 'Next automatic retry';
$string['reg_registered_at'] = 'Registered';
$string['reg_retry_exhausted'] = 'Automatic retries stopped after 24 hours. Fix the cause and press Retry.';
$string['reg_mcp_url'] = 'MCP URL for teachers';
$string['reg_mcp_url_help'] = 'Teachers add this URL as a custom connector in Claude and sign in with their own Moodle account.';
$string['reg_copy'] = 'Copy';
$string['reg_egress'] = 'If a firewall or WAF protects this site, allow requests from the hub address(es): {$a}';
$string['reg_url_changed'] = 'The site address ($CFG->wwwroot) has changed since registration. Register again.';
$string['reg_maintenance_note'] = 'Registration does not work while the site is in maintenance mode, because the hub must reach this site.';
$string['reg_retry'] = 'Retry';
$string['reg_rotate'] = 'Reissue secret';
$string['reg_rotate_confirm'] = 'Ask the hub for a new site secret? The current secret keeps working until the new one is confirmed.';
$string['reg_result'] = 'Registration status: {$a}';
$string['reg_adminonly'] = 'Only site administrators can manage hub registration.';
$string['reg_error_invalid_request'] = 'The hub rejected the request format. Please report this plugin bug.';
$string['reg_error_invalid_site_url'] = 'The site address ($CFG->wwwroot) is not valid for registration.';
$string['reg_error_unsupported_scheme'] = 'The site address must use https.';
$string['reg_error_consent_required'] = 'Consent is required. Save "Agree and connect" in the plugin settings.';
$string['reg_error_consent_outdated'] = 'The accepted terms are outdated. Upgrade the plugin and accept the new terms.';
$string['reg_error_private_address_not_supported'] = 'Not supported in this version: the site must be reachable from the internet (private, internal and local addresses are refused).';
$string['reg_error_port_not_allowed'] = 'The site address uses a port the hub does not allow.';
$string['reg_error_host_blocked'] = 'The hub operator has disabled or blocked this site, so it cannot register again. Contact UBION.';
$string['reg_error_site_not_found'] = 'The hub does not know this site key. The site registers again.';
$string['reg_error_invalid_site_credentials'] = 'The hub rejected the site secret. The site registers again.';
$string['reg_error_registration_in_progress'] = 'Another registration for this site is in progress. Retrying shortly.';
$string['reg_error_nonce_reused'] = 'The registration nonce was already used. Retrying with a new one.';
$string['reg_error_site_managed_by_operator'] = 'This site address is already set up by the hub operator (manual setup), so automatic registration was refused. Contact UBION: the operator links the site by hand.';
$string['reg_error_nothing_to_confirm'] = 'The hub had nothing to confirm. The site registers again.';
$string['reg_error_pending_secret_expired'] = 'The new secret was not confirmed in time. The site registers again.';
$string['reg_error_request_too_large'] = 'The request was too large. Please report this plugin bug.';
$string['reg_error_unsupported_media_type'] = 'The request type was refused. Please report this plugin bug.';
$string['reg_error_site_url_mismatch'] = 'The host sent does not match the site address. Check $CFG->wwwroot and any proxy in between.';
$string['reg_error_callback_mismatch'] = 'The hub could not verify this site. Check that $CFG->wwwroot is correct and that a reverse proxy does not change requests.';
$string['reg_error_callback_redirected'] = 'The hub was redirected when calling this site. Make sure $CFG->wwwroot is exactly the address the site is served on (no www/http redirect, no SSO gate on local/ubionai/register_callback.php).';
$string['reg_error_plugin_upgrade_required'] = 'The hub requires a newer plugin version. Upgrade local_ubionai.';
$string['reg_error_rate_limited'] = 'Too many registration attempts. Retrying later.';
$string['reg_error_callback_unreachable'] = 'The hub could not connect to this site. Allow the hub address in the firewall. Retrying automatically.';
$string['reg_error_callback_bad_response'] = 'The hub got an unexpected answer from this site (a WAF or bot protection may block it). Retrying automatically.';
$string['reg_error_callback_timeout'] = 'This site answered the hub too slowly. Retrying automatically.';
$string['reg_error_registration_disabled'] = 'Registration is temporarily disabled on the hub. Retrying in 6 hours.';
$string['reg_error_verifier_busy'] = 'The hub is busy. Retrying shortly.';
$string['reg_error_internal_error'] = 'The hub had an internal error. Retrying automatically.';
$string['reg_error_hub_unreachable'] = 'The hub could not be reached (check outbound https and proxy settings). Retrying automatically.';
$string['reg_error_bad_response'] = 'The hub returned an unexpected response.';
$string['reg_error_invalid_hub_url'] = 'The hub URL setting is invalid. It must be an https URL without credentials, a query or a fragment.';
$string['reg_error_plugin_error'] = 'Registration stopped because of an internal plugin error. Press Retry; if it persists, check the debugging log.';
$string['reg_error_retry_exhausted'] = 'Automatic retries stopped after 24 hours.';
$string['reg_error_not_consented'] = 'Consent has not been given.';
$string['cli_register_usage'] = 'Usage:
    php local/ubionai/cli/register.php --status
    php local/ubionai/cli/register.php --retry
    php local/ubionai/cli/register.php --rotate

--status  show the registration state (no secrets)
--retry   register now (or confirm a pending secret), restarting the retry schedule
--rotate  ask the hub for a new site secret and confirm it';
