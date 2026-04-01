# 그누보드7 AI 도구

그누보드7 프로젝트의 AI 기반 개발 도구입니다.

## 구성

### [Skills](skills/)

AI 코딩 도구에서 사용하는 슬래시 커맨드 스킬입니다. 코드 검증, 테스트 실행, 문서화 등의 작업을 자동화합니다.

| 스킬 | 설명 |
|------|------|
| [create-module.md](skills/create-module.md) | 모듈 스캐폴딩 |
| [extract-i18n-keys.md](skills/extract-i18n-keys.md) | 다국어 키 추출 |
| [run-tests.md](skills/run-tests.md) | 테스트 실행 |
| [validate-code.md](skills/validate-code.md) | 백엔드 코드 패턴 검증 |
| [validate-frontend.md](skills/validate-frontend.md) | 프론트엔드 레이아웃 검증 |
| [validate-hook.md](skills/validate-hook.md) | 훅 패턴 검증 |
| [validate-i18n.md](skills/validate-i18n.md) | 다국어 검증 |
| [validate-migration.md](skills/validate-migration.md) | 마이그레이션 검증 |

### [DevTools](devtools/)

그누보드7 템플릿 엔진의 디버깅 정보를 AI 코딩 도구에서 접근할 수 있게 해주는 MCP(Model Context Protocol) 서버입니다. 브라우저에서 수집된 상태, 액션, 캐시, 네트워크 정보를 `g7-state`, `g7-diagnose` 등 30여 개 도구로 조회할 수 있습니다.

자세한 내용은 [devtools/README.md](devtools/README.md)를 참조하세요.

### [Agents](agents/)

AI Agent SDK 기반 멀티에이전트 협업 시스템입니다. 5명의 전문 에이전트(Backend, Frontend, Layout, Template, Reviewer)가 Coordinator의 조율 하에 협업합니다.

자세한 내용은 [agents/README.md](agents/README.md)를 참조하세요.
