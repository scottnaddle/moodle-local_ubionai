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
 * Korean strings for local_ubionai (hub self-registration). Missing strings fall back to English.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'UBION AI';
$string['self_registration'] = '허브에 자동 등록';
$string['self_registration_desc'] = '동의를 저장하면 이 사이트를 UBION AI 허브에 자동으로 등록합니다(허브가 이 사이트의 콜백을 확인한 뒤 사이트 비밀을 발급). 끄면 허브 운영자가 준 사이트 ID·공유 비밀을 쓰는 수동 방식만 사용합니다.';
$string['task_retry_registration'] = 'UBION AI 허브 등록 재시도';
$string['reg_heading'] = '허브 등록';
$string['reg_state'] = '등록 상태';
$string['reg_state_awaiting_consent'] = '동의 대기';
$string['reg_state_consented'] = '동의함, 아직 등록 전';
$string['reg_state_registering'] = '등록 중';
$string['reg_state_pending_confirm'] = '허브 확인 대기';
$string['reg_state_registered'] = '등록됨';
$string['reg_state_failed_retryable'] = '등록 실패, 자동 재시도 중';
$string['reg_state_failed_action_required'] = '등록 실패, 관리자 조치 필요';
$string['reg_state_unsupported_private'] = '미지원: 인터넷에서 접속할 수 없는 사이트';
$string['reg_state_deregistered'] = '등록 철회됨';
$string['reg_disabled'] = '자동 등록이 꺼져 있습니다. 아래 수동 연결을 사용합니다.';
$string['reg_hub'] = '허브';
$string['reg_site_key'] = '사이트 키';
$string['reg_secret_version'] = '비밀 버전';
$string['reg_tier'] = '등급';
$string['reg_tenant_status'] = '허브 테넌트 상태';
$string['reg_limits'] = '한도';
$string['reg_last_error'] = '마지막 오류';
$string['reg_last_attempt'] = '마지막 시도';
$string['reg_next_retry'] = '다음 자동 재시도';
$string['reg_registered_at'] = '등록 시각';
$string['reg_retry_exhausted'] = '24시간 동안 자동 재시도했지만 실패해 중단했습니다. 원인을 해결한 뒤 [다시 시도]를 누르세요.';
$string['reg_mcp_url'] = '교사용 MCP URL';
$string['reg_mcp_url_help'] = '교사는 이 URL을 Claude의 사용자 지정 커넥터로 추가하고 자신의 Moodle 계정으로 로그인합니다.';
$string['reg_copy'] = '복사';
$string['reg_egress'] = '방화벽이나 WAF가 이 사이트를 보호한다면 허브 주소의 요청을 허용하세요: {$a}';
$string['reg_url_changed'] = '등록한 뒤 사이트 주소($CFG->wwwroot)가 바뀌었습니다. 다시 등록하세요.';
$string['reg_maintenance_note'] = '유지보수 모드에서는 허브가 이 사이트에 접속할 수 없어 등록되지 않습니다.';
$string['reg_retry'] = '다시 시도';
$string['reg_rotate'] = '비밀 재발급';
$string['reg_rotate_confirm'] = '허브에 새 사이트 비밀을 요청할까요? 새 비밀이 확인될 때까지 현재 비밀이 계속 동작합니다.';
$string['reg_result'] = '등록 상태: {$a}';
$string['reg_adminonly'] = '허브 등록은 사이트 관리자만 관리할 수 있습니다.';
$string['reg_error_invalid_request'] = '허브가 요청 형식을 거부했습니다. 플러그인 버그로 보고해 주세요.';
$string['reg_error_invalid_site_url'] = '사이트 주소($CFG->wwwroot)가 등록에 쓸 수 없는 형식입니다.';
$string['reg_error_unsupported_scheme'] = '사이트 주소는 https여야 합니다.';
$string['reg_error_consent_required'] = '동의가 필요합니다. 플러그인 설정에서 [동의하고 연결]을 저장하세요.';
$string['reg_error_consent_outdated'] = '동의한 약관 버전이 오래되었습니다. 플러그인을 업그레이드하고 새 약관에 동의하세요.';
$string['reg_error_private_address_not_supported'] = '이 버전에서는 지원하지 않습니다: 인터넷에서 접속 가능한 주소가 필요합니다(사설·내부·로컬 주소 불가).';
$string['reg_error_port_not_allowed'] = '사이트 주소의 포트를 허브가 허용하지 않습니다.';
$string['reg_error_host_blocked'] = '허브 운영자가 이 사이트를 차단했습니다. UBION에 문의하세요.';
$string['reg_error_site_not_found'] = '허브가 이 사이트 키를 알지 못합니다. 다시 등록합니다.';
$string['reg_error_invalid_site_credentials'] = '허브가 사이트 비밀을 거부했습니다. 다시 등록합니다.';
$string['reg_error_registration_in_progress'] = '이 사이트의 다른 등록이 진행 중입니다. 잠시 후 다시 시도합니다.';
$string['reg_error_nonce_reused'] = '이미 사용한 등록 nonce입니다. 새 nonce로 다시 시도합니다.';
$string['reg_error_site_managed_by_operator'] = '이 사이트는 허브 운영자가 수동으로 관리합니다. 자동 등록으로 바꾸려면 UBION에 문의하세요.';
$string['reg_error_nothing_to_confirm'] = '허브에 확인할 비밀이 없습니다. 다시 등록합니다.';
$string['reg_error_pending_secret_expired'] = '새 비밀을 제때 확인하지 못했습니다. 다시 등록합니다.';
$string['reg_error_request_too_large'] = '요청이 너무 큽니다. 플러그인 버그로 보고해 주세요.';
$string['reg_error_unsupported_media_type'] = '요청 형식이 거부되었습니다. 플러그인 버그로 보고해 주세요.';
$string['reg_error_site_url_mismatch'] = '보낸 호스트가 사이트 주소와 다릅니다. $CFG->wwwroot와 중간 프록시를 확인하세요.';
$string['reg_error_callback_mismatch'] = '허브가 이 사이트를 확인하지 못했습니다. $CFG->wwwroot가 맞는지, 리버스 프록시가 요청을 바꾸지 않는지 확인하세요.';
$string['reg_error_callback_redirected'] = '허브가 이 사이트를 호출할 때 리다이렉트되었습니다. $CFG->wwwroot가 실제 접속 주소와 정확히 같은지 확인하세요(www·http 이동, local/ubionai/register_callback.php 앞의 SSO 게이트 없음).';
$string['reg_error_plugin_upgrade_required'] = '허브가 더 새 플러그인 버전을 요구합니다. local_ubionai를 업그레이드하세요.';
$string['reg_error_rate_limited'] = '등록 시도가 너무 많습니다. 나중에 다시 시도합니다.';
$string['reg_error_callback_unreachable'] = '허브가 이 사이트에 접속하지 못했습니다. 방화벽에서 허브 주소를 허용하세요. 자동으로 다시 시도합니다.';
$string['reg_error_callback_bad_response'] = '허브가 이 사이트에서 예상하지 못한 응답을 받았습니다(WAF·봇 차단 가능성). 자동으로 다시 시도합니다.';
$string['reg_error_callback_timeout'] = '이 사이트의 응답이 너무 느렸습니다. 자동으로 다시 시도합니다.';
$string['reg_error_registration_disabled'] = '허브에서 등록이 잠시 꺼져 있습니다. 6시간 후 다시 시도합니다.';
$string['reg_error_verifier_busy'] = '허브가 바쁩니다. 잠시 후 다시 시도합니다.';
$string['reg_error_internal_error'] = '허브 내부 오류입니다. 자동으로 다시 시도합니다.';
$string['reg_error_hub_unreachable'] = '허브에 접속할 수 없습니다(아웃바운드 https·프록시 설정 확인). 자동으로 다시 시도합니다.';
$string['reg_error_bad_response'] = '허브가 예상하지 못한 응답을 보냈습니다.';
$string['reg_error_invalid_hub_url'] = '허브 URL 설정이 올바르지 않습니다. 인증 정보·쿼리·프래그먼트 없는 https URL이어야 합니다.';
$string['reg_error_plugin_error'] = '플러그인 내부 오류로 등록이 중단되었습니다. [다시 시도]를 누르고, 계속되면 디버깅 로그를 확인하세요.';
$string['reg_error_retry_exhausted'] = '24시간 후 자동 재시도를 중단했습니다.';
$string['reg_error_not_consented'] = '동의하지 않았습니다.';
