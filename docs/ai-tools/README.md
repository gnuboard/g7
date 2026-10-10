# 그누보드7 AI 도구

그누보드7 프로젝트의 AI 기반 개발 도구입니다.

## 구성

### [Skills](skills/)

AI 코딩 도구에서 사용하는 슬래시 커맨드 스킬입니다. 코드 검증, 테스트 실행, 문서화 등의 작업을 자동화합니다.

| 스킬 | 설명 |
|------|------|
| [validate.md](skills/validate.md) | 변경 파일·브랜치의 규정 준수 검증 (`/validate [backend│frontend│hook│migration│i18n│branch]`) |
| [run-tests.md](skills/run-tests.md) | 테스트 실행 (`/run-tests [backend│frontend│e2e]`) |
| [create-extension.md](skills/create-extension.md) | 모듈·플러그인·템플릿 스캐폴딩 (`/create-extension <module│plugin│template> <vendor-name>`) |
| [i18n.md](skills/i18n.md) | 다국어 키 추출·언어팩 동기화 (`/i18n <extract│sync>`) |

### [DevTools](devtools/)

그누보드7 템플릿 엔진의 디버깅 정보를 AI 코딩 도구에서 접근할 수 있게 해주는 MCP(Model Context Protocol) 서버입니다. 브라우저에서 수집된 상태, 액션, 캐시, 네트워크 정보를 `g7-state`, `g7-diagnose` 등 30여 개 도구로 조회할 수 있습니다.

자세한 내용은 [devtools/README.md](devtools/README.md)를 참조하세요.

### [agents/](agents/) — DevTools MCP 서버 소스

`docs/ai-tools/agents/` 는 위 DevTools MCP 서버(`src/mcp/g7-devtools-server.ts`)의 소스와 의존성(`@modelcontextprotocol/sdk`, `zod`, `tsx`)만 담는다. 예전의 멀티에이전트 오케스트레이터(g7-agents)는 2026-09-10 제거됐다.
