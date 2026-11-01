# Claude에서 UBION AI Moodle MCP 쓰기 — 예시 파일

Claude Desktop / Claude Code를 UBION AI 허브의 Moodle MCP 엔드포인트에 연결할 때 쓰는 예시입니다.
모든 값은 `<PLACEHOLDER>` 입니다. 실제 토큰·주소는 넣지 말고, 이 파일을 복사한 뒤 내 PC에서만 채우세요.

| 파일 | 용도 |
|---|---|
| [`GUIDE-ko.md`](GUIDE-ko.md) | 설치부터 연결·문제 해결까지 전체 가이드 |
| [`claude_desktop_config.example.json`](claude_desktop_config.example.json) | Claude Desktop `claude_desktop_config.json` 의 `mcpServers` 예시 (macOS/Linux) |
| [`claude-code-mcp-add.example.sh`](claude-code-mcp-add.example.sh) | Claude Code `claude mcp add` 예시 |

원칙

- MCP bearer 토큰은 `chmod 600` 파일에만 둡니다. 설정 파일·명령줄·셸 히스토리·채팅에 붙여 넣지 않습니다.
- 토큰은 실행 시점에 파일에서 읽어 환경변수로 `mcp-remote` 에 넘깁니다(`ps` 에 안 보임).
- Node.js 20.18 이상이 필요합니다. `mcp-remote` 버전은 `<MCP_REMOTE_VERSION>` 으로 고정하세요.
