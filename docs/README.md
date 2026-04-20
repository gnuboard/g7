# 그누보드7 문서 인덱스

> 그누보드7 오픈소스 CMS 플랫폼의 개발 가이드 문서입니다.

---

<!-- AUTO-GENERATED-START: docs-readme-stats -->
## 카테고리별 통계

| 카테고리 | 문서 수 | 링크 상태 |
|----------|---------|----------|
| [백엔드](backend/) | 23개 | 정상 |
| [프론트엔드](frontend/) | 49개 | 정상 |
| [확장 시스템](extension/) | 26개 | 정상 |
| 공통 | 19개 | 정상 |
| [AI 도구](ai-tools/) | - | 정상 |

<!-- AUTO-GENERATED-END: docs-readme-stats -->

---

## 작업 유형별 필수 문서

### 레이아웃 JSON 작성

| 순서 | 문서 | TL;DR 핵심 |
|------|------|----------|
| 1 | [레이아웃 JSON 스키마](frontend/layout-json.md) | HTML 태그 직접 사용 금지 → 기본 컴포넌트 사용 (Div, Button, Span) |
| 2 | [레이아웃 JSON - 컴포넌트](frontend/layout-json-components.md) | if: 조건부 렌더링 (type: "conditional" 사용 금지!) |
| 3 | [레이아웃 JSON - 기능](frontend/layout-json-features.md) | classMap: 조건부 CSS 클래스 (key → variants 매핑) |
| 4 | [레이아웃 JSON - 상속](frontend/layout-json-inheritance.md) | extends: 베이스 레이아웃 상속 (type: "slot" 위치에 삽입) |
| 5 | [컴포넌트 개발 규칙](frontend/components.md) | HTML 태그 직접 사용 금지 |
| 6 | [컴포넌트 Props 레퍼런스](frontend/component-props.md) | - |
| 7 | [sirsoft-admin_basic 컴포넌트](frontend/templates/sirsoft-admin_basic/components.md) | Basic (37개), Composite (66개), Layout (8개) |
| 8 | [데이터 바인딩 및 표현식](frontend/data-binding.md) | API 데이터: {{user.name}}, URL 파라미터: {{route.id}} |
| 9 | [데이터 바인딩 - 다국어 처리](frontend/data-binding-i18n.md) | - |
| 10 | [액션 핸들러 가이드](frontend/actions.md) | 구조: type 또는 event(이벤트), handler(핸들러명), params(옵션) |
| 11 | [액션 핸들러 - 핸들러별 상세 사용법](frontend/actions-handlers.md) | navigate: 페이지 이동 (path, query, mergeQuery 옵션) |
| 12 | [전역 상태 관리](frontend/state-management.md) | 전역 상태: _global.속성명 (앱 전체 공유, 페이지 이동 시 유지) |
| 13 | [데이터 소스](frontend/data-sources.md) | data_sources 배열에 API 정의: id, endpoint, method |
| 14 | [다크 모드 지원](frontend/dark-mode.md) | Tailwind dark: variant 사용 |

### 컨트롤러 작성

| 순서 | 문서 | TL;DR 핵심 |
|------|------|----------|
| 1 | [컨트롤러 계층 구조](backend/controllers.md) | AdminBaseController / AuthBaseController / PublicBaseController |
| 2 | [라우트 네이밍 및 경로](backend/routing.md) | 모든 라우트는 name() 필수 |
| 3 | [검증 (Validation)](backend/validation.md) | FormRequest 사용 필수 (Service에 검증 로직 금지) |
| 4 | [API 응답 규칙 (ResponseHelper)](backend/response-helper.md) | 모든 API 응답은 ResponseHelper 사용 |

### Service/Repository 작성

| 순서 | 문서 | TL;DR 핵심 |
|------|------|----------|
| 1 | [Service-Repository 패턴](backend/service-repository.md) | RepositoryInterface 주입 필수 (구체 클래스 직접 주입 금지) |
| 2 | [훅 시스템](extension/hooks.md) | Action 훅: doAction() - 부가 작업 (로그, 알림, 캐시) |

### 마이그레이션 작성

| 순서 | 문서 | TL;DR 핵심 |
|------|------|----------|
| 1 | [데이터베이스 개발 가이드](database-guide.md) | 마이그레이션: 한국어 comment 필수, down() 구현 필수 |

### 모듈 개발

| 순서 | 문서 | TL;DR 핵심 |
|------|------|----------|
| 1 | [모듈 개발 기초](extension/module-basics.md) | 디렉토리: vendor-module (예: sirsoft-ecommerce) |
| 2 | [모듈 라우트 규칙](extension/module-routing.md) | URL prefix 자동: /api/admin/[vendor-module]/... |
| 3 | [모듈 레이아웃 시스템](extension/module-layouts.md) | modules/_bundled/vendor-module/resources/layouts/ |
| 4 | [모듈 다국어 시스템](extension/module-i18n.md) | 백엔드: /lang/{locale}/*.php |
| 5 | [훅 시스템](extension/hooks.md) | Action 훅: doAction() |

### 테스트 작성

| 순서 | 문서 | TL;DR 핵심 |
|------|------|----------|
| 1 | [테스트 가이드](testing-guide.md) | 테스트 통과 = 작업 완료 (작성만으로 불충분!) |
| 2 | [레이아웃 렌더링 테스트 가이드](frontend/layout-testing.md) | createLayoutTest()로 테스트 헬퍼 생성 |

### API 리소스 작성

| 순서 | 문서 | TL;DR 핵심 |
|------|------|----------|
| 1 | [API 리소스](backend/api-resources.md) | BaseApiResource 상속 필수 |
| 2 | [API 응답 규칙 (ResponseHelper)](backend/response-helper.md) | 모든 API 응답은 ResponseHelper 사용 |

### FormRequest 작성

| 순서 | 문서 | TL;DR 핵심 |
|------|------|----------|
| 1 | [검증 (Validation)](backend/validation.md) | FormRequest 사용 필수 (Service에 검증 로직 금지) |
| 2 | [Custom Exception 다국어 처리](backend/exceptions.md) | 예외 메시지 하드코딩 금지 → __() 함수 필수 |

### 권한/메뉴 추가

| 순서 | 문서 | TL;DR 핵심 |
|------|------|----------|
| 1 | [권한 시스템](extension/permissions.md) | 구조: User → Role → Permission (기능 레벨) |
| 2 | [메뉴 시스템](extension/menus.md) | 구조: User → Role → role_menus 피벗 → Menu |

### 다국어 추가

| 순서 | 문서 | TL;DR 핵심 |
|------|------|----------|
| 1 | [모듈 다국어 시스템](extension/module-i18n.md) | 백엔드: /lang/{locale}/*.php |
| 2 | [데이터베이스 개발 가이드](database-guide.md) | 마이그레이션: 한국어 comment 필수, down() 구현 필수 |
| 3 | [데이터 바인딩 - 다국어 처리](frontend/data-binding-i18n.md) | - |

---

<!-- AUTO-GENERATED-START: docs-readme-full-list -->
## 카테고리별 전체 문서 목록

### 백엔드 (23개)

| 문서 | 제목 |
|------|------|
| [activity-log-hooks.md](backend/activity-log-hooks.md) | 활동 로그 훅 레퍼런스 (Activity Log Hooks Reference) |
| [activity-log.md](backend/activity-log.md) | 활동 로그 시스템 (Activity Log System) |
| [api-resources.md](backend/api-resources.md) | API 리소스 |
| [authentication.md](backend/authentication.md) | 인증 및 세션 처리 |
| [broadcasting.md](backend/broadcasting.md) | Broadcasting (실시간 이벤트) |
| [controllers.md](backend/controllers.md) | 컨트롤러 계층 구조 |
| [core-config.md](backend/core-config.md) | 코어 설정 (config/core.php) |
| [core-update-system.md](backend/core-update-system.md) | 코어 업데이트 시스템 (Core Update System) |
| [data-sync-helpers.md](backend/data-sync-helpers.md) | 데이터 동기화 Helper (Data Sync Helpers) |
| [enum.md](backend/enum.md) | Enum 사용 규칙 |
| [exceptions.md](backend/exceptions.md) | Custom Exception 다국어 처리 |
| [geoip.md](backend/geoip.md) | GeoIP 시스템 (MaxMind GeoLite2) |
| [middleware.md](backend/middleware.md) | 미들웨어 등록 규칙 |
| [notification-system.md](backend/notification-system.md) | 알림 시스템 (Notification System) |
| [README.md](backend/README.md) | 백엔드 개발 가이드 |
| [response-helper.md](backend/response-helper.md) | API 응답 규칙 (ResponseHelper) |
| [routing.md](backend/routing.md) | 라우트 네이밍 및 경로 |
| [search-system.md](backend/search-system.md) | Scout 검색 엔진 시스템 (Search System) |
| [seo-system.md](backend/seo-system.md) | SEO 페이지 생성기 시스템 (SEO Page Generator) |
| [service-provider.md](backend/service-provider.md) | 서비스 프로바이더 안전성 |
| [service-repository.md](backend/service-repository.md) | Service-Repository 패턴 |
| [user-overrides.md](backend/user-overrides.md) | 사용자 수정 보존 (HasUserOverrides Trait) |
| [validation.md](backend/validation.md) | 검증 (Validation) |

### 프론트엔드 (49개)

| 문서 | 제목 |
|------|------|
| [actions-g7core-api.md](frontend/actions-g7core-api.md) | 액션 시스템 - G7Core API (React 컴포넌트용) |
| [actions-handlers-navigation.md](frontend/actions-handlers-navigation.md) | 액션 핸들러 - 네비게이션 |
| [actions-handlers-state.md](frontend/actions-handlers-state.md) | 액션 핸들러 - 상태 관리 |
| [actions-handlers-ui.md](frontend/actions-handlers-ui.md) | 액션 핸들러 - UI 인터랙션 |
| [actions-handlers.md](frontend/actions-handlers.md) | 액션 핸들러 - 핸들러별 상세 사용법 |
| [actions.md](frontend/actions.md) | 액션 핸들러 가이드 |
| [auth-system.md](frontend/auth-system.md) | 인증 시스템 (AuthManager) |
| [component-props-composite.md](frontend/component-props-composite.md) | 컴포넌트 Props 레퍼런스 - Composite |
| [component-props.md](frontend/component-props.md) | 컴포넌트 Props 레퍼런스 |
| [components-advanced.md](frontend/components-advanced.md) | 컴포넌트 고급 기능 |
| [components-patterns.md](frontend/components-patterns.md) | 컴포넌트 패턴 및 다국어 |
| [components-types.md](frontend/components-types.md) | 컴포넌트 타입별 개발 규칙 |
| [components.md](frontend/components.md) | 컴포넌트 개발 규칙 |
| [dark-mode.md](frontend/dark-mode.md) | 다크 모드 지원 (engine-v1.1.0+) |
| [data-binding-i18n.md](frontend/data-binding-i18n.md) | 데이터 바인딩 - 다국어 처리 |
| [data-binding.md](frontend/data-binding.md) | 데이터 바인딩 및 표현식 |
| [data-sources-advanced.md](frontend/data-sources-advanced.md) | 데이터 소스 - 고급 기능 |
| [data-sources.md](frontend/data-sources.md) | 데이터 소스 (Data Sources) |
| [editors.md](frontend/editors.md) | 에디터 컴포넌트 가이드 |
| [g7core-api-advanced.md](frontend/g7core-api-advanced.md) | G7Core 전역 API 레퍼런스 - 고급 |
| [g7core-api.md](frontend/g7core-api.md) | G7Core 전역 API 레퍼런스 |
| [g7core-helpers.md](frontend/g7core-helpers.md) | G7Core 헬퍼 API |
| [layout-json-components-loading.md](frontend/layout-json-components-loading.md) | 레이아웃 JSON - 데이터 로딩 및 생명주기 |
| [layout-json-components-rendering.md](frontend/layout-json-components-rendering.md) | 레이아웃 JSON - 조건부/반복 렌더링 |
| [layout-json-components-slots.md](frontend/layout-json-components-slots.md) | 레이아웃 JSON - 슬롯 시스템 |
| [layout-json-components.md](frontend/layout-json-components.md) | 레이아웃 JSON - 컴포넌트 (반복 렌더링, Blur, 생명주기, 슬롯) |
| [layout-json-features-actions.md](frontend/layout-json-features-actions.md) | 레이아웃 JSON - 초기화, 모달, 액션, 스크립트 |
| [layout-json-features-error.md](frontend/layout-json-features-error.md) | 레이아웃 JSON - 에러 핸들링 |
| [layout-json-features-styling.md](frontend/layout-json-features-styling.md) | 레이아웃 JSON - 스타일 및 계산된 값 |
| [layout-json-features.md](frontend/layout-json-features.md) | 레이아웃 JSON - 기능 (에러 핸들링, 초기화, 모달, 액션) |
| [layout-json-inheritance.md](frontend/layout-json-inheritance.md) | 레이아웃 JSON - 상속 (Extends, Partial, 병합) |
| [layout-json.md](frontend/layout-json.md) | 레이아웃 JSON 스키마 |
| [layout-testing.md](frontend/layout-testing.md) | 그누보드7 레이아웃 파일 렌더링 테스트 가이드 |
| [modal-usage.md](frontend/modal-usage.md) | Modal 컴포넌트 사용 가이드 |
| [README.md](frontend/README.md) | 그누보드7 프론트엔드 개발 가이드 |
| [responsive-layout.md](frontend/responsive-layout.md) | 반응형 레이아웃 개발 (engine-v1.1.0+) |
| [security.md](frontend/security.md) | 보안 및 검증 |
| [state-management-advanced.md](frontend/state-management-advanced.md) | 상태 관리 - 고급 기능 |
| [state-management-forms.md](frontend/state-management-forms.md) | 상태 관리 - 폼 자동 바인딩 및 setState |
| [state-management.md](frontend/state-management.md) | 전역 상태 관리 |
| [tailwind-safelist.md](frontend/tailwind-safelist.md) | Tailwind Safelist 가이드 |
| [template-development.md](frontend/template-development.md) | 템플릿 개발 가이드라인 |
| [template-handlers.md](frontend/template-handlers.md) | 템플릿 전용 핸들러 |
| [components.md](frontend/components.md) | sirsoft-admin_basic 컴포넌트 |
| [handlers.md](frontend/handlers.md) | sirsoft-admin_basic 핸들러 |
| [layouts.md](frontend/layouts.md) | sirsoft-admin_basic 레이아웃 |
| [components.md](frontend/components.md) | sirsoft-basic 컴포넌트 |
| [handlers.md](frontend/handlers.md) | sirsoft-basic 핸들러 |
| [layouts.md](frontend/layouts.md) | sirsoft-basic 레이아웃 |

### 확장 시스템 (26개)

| 문서 | 제목 |
|------|------|
| [cache-driver.md](extension/cache-driver.md) | 캐시 드라이버 시스템 (CacheInterface) |
| [changelog-rules.md](extension/changelog-rules.md) | Changelog 규칙 (Changelog Rules) |
| [extension-manager.md](extension/extension-manager.md) | ExtensionManager (확장 관리자) |
| [extension-update-system.md](extension/extension-update-system.md) | 확장 업데이트 시스템 (Extension Update System) |
| [hooks.md](extension/hooks.md) | 훅 시스템 (Hook System) |
| [layout-extensions.md](extension/layout-extensions.md) | 레이아웃 확장 시스템 (Layout Extensions) |
| [menus.md](extension/menus.md) | 메뉴 시스템 |
| [module-assets.md](extension/module-assets.md) | 모듈 프론트엔드 에셋 시스템 |
| [module-basics.md](extension/module-basics.md) | 모듈 개발 기초 |
| [module-commands.md](extension/module-commands.md) | 모듈 Artisan 커맨드 |
| [module-i18n.md](extension/module-i18n.md) | 모듈 다국어 시스템 |
| [module-layouts.md](extension/module-layouts.md) | 모듈 레이아웃 시스템 |
| [module-routing.md](extension/module-routing.md) | 모듈 라우트 규칙 |
| [module-settings.md](extension/module-settings.md) | 모듈 환경설정 시스템 개발 가이드 |
| [permissions.md](extension/permissions.md) | 권한 시스템 |
| [plugin-development.md](extension/plugin-development.md) | 플러그인 개발 가이드 |
| [README.md](extension/README.md) | 그누보드7 확장 시스템 개발 가이드 |
| [storage-driver.md](extension/storage-driver.md) | 스토리지 드라이버 시스템 (StorageInterface) |
| [template-basics.md](extension/template-basics.md) | 템플릿 시스템 기초 |
| [template-caching.md](extension/template-caching.md) | 템플릿 캐싱 전략 |
| [template-commands.md](extension/template-commands.md) | 템플릿 Artisan 커맨드 |
| [template-routing.md](extension/template-routing.md) | 템플릿 라우트/언어 파일 규칙 |
| [template-security.md](extension/template-security.md) | 템플릿 보안 정책 |
| [template-workflow.md](extension/template-workflow.md) | 템플릿 개발 워크플로우 |
| [upgrade-step-guide.md](extension/upgrade-step-guide.md) | 업그레이드 스텝 작성 가이드 (Upgrade Step Guide) |
| [vendor-bundle.md](extension/vendor-bundle.md) | Vendor 번들 시스템 (Vendor Bundle System) |

### 공통 (19개)

| 문서 | 제목 |
|------|------|
| [README.md](ai-tools/agents/README.md) | 그누보드7 Multi-Agent System |
| [README.md](ai-tools/devtools/README.md) | 그누보드7 DevTools MCP 서버 |
| [README.md](ai-tools/README.md) | 그누보드7 AI 도구 |
| [create-module.md](ai-tools/skills/create-module.md) | 모듈 스캐폴딩 (create-module) |
| [create-plugin.md](ai-tools/skills/create-plugin.md) | 플러그인 스캐폴딩 (create-plugin) |
| [create-template.md](ai-tools/skills/create-template.md) | 템플릿 스캐폴딩 (create-template) |
| [extract-i18n-keys.md](ai-tools/skills/extract-i18n-keys.md) | 다국어 키 추출 (extract-i18n-keys) |
| [run-tests.md](ai-tools/skills/run-tests.md) | 테스트 실행 (run-tests) |
| [validate-code.md](ai-tools/skills/validate-code.md) | 코드 패턴 검증 (validate-code) |
| [validate-frontend.md](ai-tools/skills/validate-frontend.md) | 프론트엔드 검증 (validate-frontend) |
| [validate-hook.md](ai-tools/skills/validate-hook.md) | 훅 패턴 검증 (validate-hook) |
| [validate-i18n.md](ai-tools/skills/validate-i18n.md) | 다국어 검증 (validate-i18n) |
| [validate-migration.md](ai-tools/skills/validate-migration.md) | 마이그레이션 검증 (validate-migration) |
| [cheatsheet.md](cheatsheet.md) | 그누보드7 자주 쓰는 명령어 치트시트 |
| [database-guide.md](database-guide.md) | 그누보드7 데이터베이스 개발 가이드 |
| [README.md](README.md) | 그누보드7 문서 인덱스 |
| [requirements.md](requirements.md) | 그누보드7 시스템 요구사항 (System Requirements) |
| [SECURITY.md](SECURITY.md) | 그누보드7 템플릿 엔진 보안 가이드 |
| [testing-guide.md](testing-guide.md) | 그누보드7 테스트 가이드 |

### AI 도구

| 문서 | 설명 |
|------|------|
| [ai-tools/README.md](ai-tools/README.md) | AI 도구 개요 |
| [ai-tools/skills/](ai-tools/skills/) | AI 코딩 도구 스킬 |
| [ai-tools/agents/](ai-tools/agents/) | 멀티에이전트 시스템 소스 |

<!-- AUTO-GENERATED-END: docs-readme-full-list -->
