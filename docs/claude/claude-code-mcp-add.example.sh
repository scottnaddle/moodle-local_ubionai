#!/usr/bin/env bash
# Claude Code에 UBION AI Moodle MCP 허브를 등록하는 예시입니다. <PLACEHOLDER> 값을 바꿔서 쓰세요.
# 토큰(MCP bearer)은 이 파일·명령줄·셸 히스토리에 넣지 않습니다. chmod 600 파일에만 둡니다.
set -euo pipefail

MCP_URL='<MCP_ENDPOINT_URL>'                # 예: https://<HUB_HOST>/t/<SITE_ID>/mcp (허브) 또는 https://<MCP_HOST>/mcp (단일 서버)
MCP_TOKEN_FILE='<ABSOLUTE_PATH_TO_TOKEN_FILE>'
MCP_REMOTE_VERSION='<MCP_REMOTE_VERSION>'
SCOPE='<local|project|user>'

# 0) 토큰 파일 권한 확인 (600 또는 400만 허용)
perm=$(stat -c %a "$MCP_TOKEN_FILE" 2>/dev/null || stat -f %Lp "$MCP_TOKEN_FILE")
case "$perm" in 600|400) ;; *) echo "chmod 600 '$MCP_TOKEN_FILE' 후 다시 실행하세요 (현재 $perm)" >&2; exit 1 ;; esac

# 1) 권장: stdio + mcp-remote. 토큰은 실행 시점에 파일에서 읽어 환경변수로만 넘깁니다.
#    작은따옴표 안의 ${AUTH_HEADER}는 mcp-remote가 직접 치환합니다(설정 파일·argv에는 토큰이 남지 않음).
claude mcp add moodle --scope "$SCOPE" \
  --env MCP_URL="$MCP_URL" \
  --env MCP_TOKEN_FILE="$MCP_TOKEN_FILE" \
  --env MCP_REMOTE_VERSION="$MCP_REMOTE_VERSION" \
  -- /bin/sh -c 'AUTH_HEADER="Bearer $(tr -d "\r\n" < "$MCP_TOKEN_FILE")"; export AUTH_HEADER; exec npx -y "mcp-remote@$MCP_REMOTE_VERSION" "$MCP_URL" --transport http-only --header "Authorization:\${AUTH_HEADER}"'

# 2) 대안: Streamable HTTP 직접 연결 (프로젝트 .mcp.json, 환경변수 치환).
#    셸에서 export MOODLE_MCP_TOKEN="$(cat <ABSOLUTE_PATH_TO_TOKEN_FILE>)" 후 claude 실행.
#    .mcp.json 예:
#    { "mcpServers": { "moodle": { "type": "http", "url": "<MCP_ENDPOINT_URL>",
#        "headers": { "Authorization": "Bearer ${MOODLE_MCP_TOKEN}" } } } }
#    주의: `claude mcp add --transport http ... --header "Authorization: Bearer <토큰>"` 처럼
#    토큰을 직접 쓰면 셸 히스토리와 ~/.claude.json 에 평문으로 남습니다. 쓰지 마세요.

# 3) 확인
claude mcp get moodle
