# 그누보드7 문서 인덱스

> 그누보드7 오픈소스 CMS 플랫폼의 개발 가이드 문서입니다.

---

<!-- AUTO-GENERATED-START: docs-readme-stats -->
## 카테고리별 통계

| 카테고리 | 문서 수 | 링크 상태 |
|----------|---------|----------|
| [백엔드](backend/) | 40개 | 정상 |
| [프론트엔드](frontend/) | 49개 | 정상 |
| [확장 시스템](extension/) | 38개 | 정상 |
| 공통 | 21개 | 정상 |
| [절대 금지 패턴](rules/) | 0개 | 정상 |
| [AI 도구](ai-tools/) | - | 정상 |

<!-- AUTO-GENERATED-END: docs-readme-stats -->

---

## API 레퍼런스

G7 이 제공하는 REST API 의 엔드포인트별 요청 파라미터·응답 필드·요청/응답 예시입니다.
템플릿의 `data_sources` 를 작성하거나 외부에서 G7 을 연동할 때 참고하세요.

| 문서 | 설명 |
|------|------|
| [backend/api/README.md](backend/api/README.md) | **API 레퍼런스 진입점** — 공통 규약(인증·응답 봉투·페이지네이션·에러) + 코어/확장 전체 목차 |
| [backend/api-documentation.md](backend/api-documentation.md) | API 문서 작성·갱신 규정 (기여자용) |

---

## 작업 유형별 필수 문서

### 레이아웃 작성 (.g7.html)

| 순서 | 문서 | TL;DR 핵심 |
|------|------|----------|
| 1 | [레이아웃 마크업 (`.g7.html`) 형식](frontend/layout-markup.md) | **작성 SSoT 는 `.g7.html`** — 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출(위지윅 편집기·내부 엔진용). `.json` 만 고치면 다음 굽기에 소실되고 화면에도 반영되지 않는다 |
| 2 | [레이아웃 JSON 스키마](frontend/layout-json.md) | HTML 태그 직접 사용 금지 → 기본 컴포넌트 사용 (Div, Button, Span) |
| 3 | [레이아웃 JSON - 컴포넌트](frontend/layout-json-components.md) | if: 조건부 렌더링 (type: "conditional" 사용 금지!) |
| 4 | [레이아웃 JSON - 기능](frontend/layout-json-features.md) | classMap: 조건부 CSS 클래스 (key → variants 매핑) |
| 5 | [레이아웃 JSON - 상속](frontend/layout-json-inheritance.md) | extends: 베이스 레이아웃 상속 (type: "slot" 위치에 삽입) |
| 6 | [컴포넌트 개발 규칙](frontend/components.md) | HTML 태그 직접 사용 금지 |
| 7 | [컴포넌트 Props 레퍼런스](frontend/component-props.md) | - |
| 8 | [sirsoft-basic 컴포넌트](../templates/_bundled/sirsoft-basic/docs/components.md) | 확장 소유 문서 (basic / composite / layout) |
| 9 | [데이터 바인딩 및 표현식](frontend/data-binding.md) | API 데이터: {{user.name}}, URL 파라미터: {{route.id}} |
| 10 | [데이터 바인딩 - 다국어 처리](frontend/data-binding-i18n.md) | - |
| 11 | [액션 핸들러 가이드](frontend/actions.md) | 구조: type 또는 event(이벤트), handler(핸들러명), params(옵션) |
| 12 | [액션 핸들러 - 핸들러별 상세 사용법](frontend/actions-handlers.md) | navigate: 페이지 이동 (path, query, mergeQuery 옵션) |
| 13 | [전역 상태 관리](frontend/state-management.md) | 전역 상태: _global.속성명 (앱 전체 공유, 페이지 이동 시 유지) |
| 14 | [데이터 소스](frontend/data-sources.md) | data_sources 배열에 API 정의: id, endpoint, method |
| 15 | [다크 모드 지원](frontend/dark-mode.md) | Tailwind dark: variant 사용 |

sirsoft-admin_basic 컴포넌트 문서는 확장이 소유합니다 — [templates/_bundled/sirsoft-admin_basic/docs/components.md](../templates/_bundled/sirsoft-admin_basic/docs/components.md) 를 참고하세요.

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

### 백엔드 (40개)

| 문서 | 제목 |
|------|------|
| [activity-log-hooks.md](backend/activity-log-hooks.md) | 활동 로그 훅 레퍼런스 (Activity Log Hooks Reference) |
| [activity-log.md](backend/activity-log.md) | 활동 로그 시스템 (Activity Log System) |
| [admin-settings-access.md](backend/admin-settings-access.md) | Admin 환경설정 값 접근 (`g7_core_settings` vs `config()`) |
| [analytics.md](backend/analytics.md) | 방문자 통계 |
| [api-documentation.md](backend/api-documentation.md) | API 레퍼런스 문서 규정 (API Documentation) |
| [api-resources.md](backend/api-resources.md) | API 리소스 |
| [authentication.md](backend/authentication.md) | 인증 및 세션 처리 |
| [benchmark.md](backend/benchmark.md) | 성능 계측 시스템 (Benchmark) |
| [broadcasting.md](backend/broadcasting.md) | Broadcasting (실시간 이벤트) |
| [console-confirm.md](backend/console-confirm.md) | 콘솔 yes/no 프롬프트 (ConsoleConfirm) |
| [controllers.md](backend/controllers.md) | 컨트롤러 계층 구조 |
| [core-config.md](backend/core-config.md) | 코어 설정 (config/core.php) |
| [core-update-system.md](backend/core-update-system.md) | 코어 업데이트 시스템 (Core Update System) |
| [data-sync-helpers.md](backend/data-sync-helpers.md) | 데이터 동기화 Helper (Data Sync Helpers) |
| [dto.md](backend/dto.md) | DTO (Data Transfer Object) 사용 규칙 |
| [enum.md](backend/enum.md) | Enum 사용 규칙 |
| [exceptions.md](backend/exceptions.md) | Custom Exception 다국어 처리 |
| [geoip.md](backend/geoip.md) | GeoIP 시스템 (MaxMind GeoLite2) |
| [identity-messages.md](backend/identity-messages.md) | 본인인증 알림 (Identity Notifications) |
| [identity-policies.md](backend/identity-policies.md) | 본인인증 정책 시스템 (Identity Policies) |
| [identity-providers.md](backend/identity-providers.md) | IDV Provider 작성 가이드 (Identity Verification Providers) |
| [language-pack-service.md](backend/language-pack-service.md) | LanguagePackService (백엔드 Service 레이어) |
| [middleware.md](backend/middleware.md) | 미들웨어 등록 규칙 |
| [notification-system.md](backend/notification-system.md) | 알림 시스템 (Notification System) |
| [pagination.md](backend/pagination.md) | 대용량 목록 페이지네이션 (Pagination) |
| [password-policy.md](backend/password-policy.md) | 비밀번호 정책 (작성 규칙·재사용 금지·만료) |
| [privacy-retention.md](backend/privacy-retention.md) | 개인정보 보유기간 처리 (Privacy Retention) |
| [README.md](backend/README.md) | 백엔드 개발 가이드 |
| [response-helper.md](backend/response-helper.md) | API 응답 규칙 (ResponseHelper) |
| [reverse-proxy.md](backend/reverse-proxy.md) | 리버스 프록시 환경 (Reverse Proxy) |
| [routing.md](backend/routing.md) | 라우트 네이밍 및 경로 |
| [search-system.md](backend/search-system.md) | Scout 검색 엔진 시스템 (Search System) |
| [seo-system.md](backend/seo-system.md) | SEO 페이지 생성기 시스템 (SEO Page Generator) |
| [service-provider.md](backend/service-provider.md) | 서비스 프로바이더 안전성 |
| [service-repository.md](backend/service-repository.md) | Service-Repository 패턴 |
| [settings-multilingual-enrichment.md](backend/settings-multilingual-enrichment.md) | Settings 카탈로그 다국어 자동 보강 |
| [static-asset-publishing.md](backend/static-asset-publishing.md) | 부트스트랩 리소스 정적 게시 (Static Asset Publishing) |
| [translatable-seeders.md](backend/translatable-seeders.md) | 다국어 시더 인터페이스 (Translatable Seeders) |
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
| [admin-list-filter-convention.md](frontend/admin-list-filter-convention.md) | 관리자 목록 필터 컨벤션 |
| [analytics-tracker.md](frontend/analytics-tracker.md) | 방문자 통계 트래커 |
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
| [identity-guard-interceptor.md](frontend/identity-guard-interceptor.md) | IdentityGuardInterceptor — 코어 본인인증 인터셉터 레퍼런스 |
| [identity-verification-ui.md](frontend/identity-verification-ui.md) | 본인인증(IDV) 공통 UI 가이드 |
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
| [layout-markup.md](frontend/layout-markup.md) | 레이아웃 마크업 (`.g7.html`) 형식 |
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
| [README.md](frontend/templates/README.md) | 템플릿별 컴포넌트·핸들러·레이아웃 문서 |

### 확장 시스템 (38개)

| 문서 | 제목 |
|------|------|
| [admin-template-contract.md](extension/admin-template-contract.md) | 관리자 템플릿 교체 계약 |
| [analytics-extension-points.md](extension/analytics-extension-points.md) | 방문자 통계 확장 기여 지점 |
| [cache-driver.md](extension/cache-driver.md) | 캐시 드라이버 시스템 (CacheInterface) |
| [changelog-rules.md](extension/changelog-rules.md) | Changelog 규칙 (Changelog Rules) |
| [consent-provider.md](extension/consent-provider.md) | 동의 제공자 계약 (Consent Provider) |
| [editor-spec.md](extension/editor-spec.md) | 편집기 스펙 (editor-spec.json) |
| [extension-components.md](extension/extension-components.md) | 확장 컴포넌트 (Extension Components) |
| [extension-documentation.md](extension/extension-documentation.md) | 확장 개발자 문서 (Extension Documentation) |
| [extension-icons.md](extension/extension-icons.md) | 확장 아이콘 |
| [extension-manager.md](extension/extension-manager.md) | ExtensionManager (확장 관리자) |
| [extension-update-system.md](extension/extension-update-system.md) | 확장 업데이트 시스템 (Extension Update System) |
| [hook-catalog.md](extension/hook-catalog.md) | 공개 훅 목록 (Hook Catalog) |
| [hooks.md](extension/hooks.md) | 훅 시스템 (Hook System) |
| [language-packs.md](extension/language-packs.md) | 언어팩 시스템 (Language Packs) |
| [layout-extensions.md](extension/layout-extensions.md) | 레이아웃 확장 시스템 (Layout Extensions) |
| [menus.md](extension/menus.md) | 메뉴 시스템 |
| [module-assets.md](extension/module-assets.md) | 모듈 프론트엔드 에셋 시스템 |
| [module-basics.md](extension/module-basics.md) | 모듈 개발 기초 |
| [module-commands.md](extension/module-commands.md) | 모듈 Artisan 커맨드 |
| [module-i18n.md](extension/module-i18n.md) | 모듈 다국어 시스템 |
| [module-identity-settings.md](extension/module-identity-settings.md) | 모듈/플러그인 본인인증(IDV) 설정 통합 가이드 |
| [module-layouts.md](extension/module-layouts.md) | 모듈 레이아웃 시스템 |
| [module-routing.md](extension/module-routing.md) | 모듈 라우트 규칙 |
| [module-settings.md](extension/module-settings.md) | 모듈 환경설정 시스템 개발 가이드 |
| [permissions.md](extension/permissions.md) | 권한 시스템 |
| [plugin-development.md](extension/plugin-development.md) | 플러그인 개발 가이드 |
| [README.md](extension/README.md) | 그누보드7 확장 시스템 개발 가이드 |
| [sample-extensions.md](extension/sample-extensions.md) | 학습용 샘플 확장 (Sample Extensions) |
| [storage-driver.md](extension/storage-driver.md) | 스토리지 드라이버 시스템 (StorageInterface) |
| [template-basics.md](extension/template-basics.md) | 템플릿 시스템 기초 |
| [template-caching.md](extension/template-caching.md) | 템플릿 캐싱 전략 |
| [template-commands.md](extension/template-commands.md) | 템플릿 Artisan 커맨드 |
| [template-idv-bootstrap.md](extension/template-idv-bootstrap.md) | 템플릿 IDV launcher 등록 가이드 |
| [template-routing.md](extension/template-routing.md) | 템플릿 라우트/언어 파일 규칙 |
| [template-security.md](extension/template-security.md) | 템플릿 보안 정책 |
| [template-workflow.md](extension/template-workflow.md) | 템플릿 개발 워크플로우 |
| [upgrade-step-guide.md](extension/upgrade-step-guide.md) | 업그레이드 스텝 작성 가이드 (Upgrade Step Guide) |
| [vendor-bundle.md](extension/vendor-bundle.md) | Vendor 번들 시스템 (Vendor Bundle System) |

### 공통 (21개)

| 문서 | 제목 |
|------|------|
| [README.md](ai-tools/agents/README.md) | 그누보드7 DevTools MCP 서버 소스 |
| [README.md](ai-tools/devtools/README.md) | 그누보드7 DevTools MCP 서버 |
| [README.md](ai-tools/README.md) | 그누보드7 AI 도구 |
| [create-extension.md](ai-tools/skills/create-extension.md) | 확장 스캐폴딩 (create-extension) |
| [i18n.md](ai-tools/skills/i18n.md) | 다국어 키 추출·언어팩 동기화 (i18n) |
| [run-tests.md](ai-tools/skills/run-tests.md) | 테스트 실행 (run-tests) |
| [validate.md](ai-tools/skills/validate.md) | 규정 준수 검증 (validate) |
| [cheatsheet.md](cheatsheet.md) | 그누보드7 자주 쓰는 명령어 치트시트 |
| [database-guide.md](database-guide.md) | 그누보드7 데이터베이스 개발 가이드 |
| [requirements.md](requirements.md) | 그누보드7 시스템 요구사항 (System Requirements) |
| [backend-misc.md](rules/backend-misc.md) | 백엔드 일반 규칙 |
| [editor-widgets.md](rules/editor-widgets.md) | 레이아웃 편집기·위젯 규칙 |
| [extension-assets.md](rules/extension-assets.md) | 확장 에셋·문서·캐시·의존성 규칙 |
| [frontend-actions-binding.md](rules/frontend-actions-binding.md) | 프론트엔드 액션·바인딩·상태 규칙 |
| [list-context.md](rules/list-context.md) | 목록·검색·페이지 규칙 |
| [README.md](rules/README.md) | 절대 금지 패턴 (CRITICAL RULES) |
| [routing-security.md](rules/routing-security.md) | 라우팅·인증·보안 경계 규칙 |
| [SECURITY.md](SECURITY.md) | 그누보드7 템플릿 엔진 보안 가이드 |
| [testing-guide.md](testing-guide.md) | 그누보드7 테스트 가이드 |
| [e2e-testing.md](testing/e2e-testing.md) | 그누보드7 Playwright E2E 테스트 가이드 |
| [7.0.x-to-7.1.0.md](upgrade/7.0.x-to-7.1.0.md) | 7.0.x → 7.1.0 업그레이드 안내 |

### AI 도구

| 문서 | 설명 |
|------|------|
| [ai-tools/README.md](ai-tools/README.md) | AI 도구 개요 |
| [ai-tools/skills/](ai-tools/skills/) | AI 코딩 도구 스킬 |
| [ai-tools/agents/](ai-tools/agents/) | 멀티에이전트 시스템 소스 |

### API 레퍼런스 진입점

> 엔드포인트별 요청 파라미터·응답 필드·요청/응답 예시. 공통 규약(Bearer 토큰 인증, 응답 봉투, 페이지네이션, 401/403/422/428)은 진입점 문서 상단에 정리되어 있다.

| 대상 | 진입점 | 문서/엔드포인트 |
|------|--------|----------------|
| 코어 | [docs/backend/api/README.md](backend/api/README.md) | 39 / 357 |


### 확장 API 레퍼런스 (16개 확장, 자동 스캔)

> 각 확장이 소유하는 API 문서 목차. `php artisan api:docgen` 이 생성하며, 이 표는 `{modules,plugins}/_bundled/*/docs/api/README.md` 를 패턴 스캔해 자동 편입된다(확장명 하드코딩 없음).

| 확장 | 유형 | API 문서 목차 | 문서/엔드포인트 |
|------|------|--------------|----------------|
| `gnuboard7-hello_module` | 모듈 | [docs/api/](../modules/_bundled/gnuboard7-hello_module/docs/api/README.md) | 1 / 7 |
| `sirsoft-board` | 모듈 | [docs/api/](../modules/_bundled/sirsoft-board/docs/api/README.md) | 12 / 94 |
| `sirsoft-ecommerce` | 모듈 | [docs/api/](../modules/_bundled/sirsoft-ecommerce/docs/api/README.md) | 36 / 248 |
| `sirsoft-page` | 모듈 | [docs/api/](../modules/_bundled/sirsoft-page/docs/api/README.md) | 2 / 17 |
| `sirsoft-ckeditor5` | 플러그인 | [docs/api/](../plugins/_bundled/sirsoft-ckeditor5/docs/api/README.md) | 3 / 5 |
| `sirsoft-gdpr` | 플러그인 | [docs/api/](../plugins/_bundled/sirsoft-gdpr/docs/api/README.md) | 6 / 17 |
| `sirsoft-koreaexim` | 플러그인 | [docs/api/](../plugins/_bundled/sirsoft-koreaexim/docs/api/README.md) | 2 / 4 |
| `sirsoft-marketing` | 플러그인 | [docs/api/](../plugins/_bundled/sirsoft-marketing/docs/api/README.md) | 2 / 2 |
| `sirsoft-message_bizppurio` | 플러그인 | [docs/api/](../plugins/_bundled/sirsoft-message_bizppurio/docs/api/README.md) | 6 / 21 |
| `sirsoft-pay_kginicis` | 플러그인 | [docs/api/](../plugins/_bundled/sirsoft-pay_kginicis/docs/api/README.md) | 6 / 37 |
| `sirsoft-pay_nhnkcp` | 플러그인 | [docs/api/](../plugins/_bundled/sirsoft-pay_nhnkcp/docs/api/README.md) | 0 / 0 |
| `sirsoft-pay_nicepayments` | 플러그인 | [docs/api/](../plugins/_bundled/sirsoft-pay_nicepayments/docs/api/README.md) | 0 / 0 |
| `sirsoft-tosspayments` | 플러그인 | [docs/api/](../plugins/_bundled/sirsoft-tosspayments/docs/api/README.md) | 2 / 4 |
| `sirsoft-totp` | 플러그인 | [docs/api/](../plugins/_bundled/sirsoft-totp/docs/api/README.md) | 1 / 6 |
| `sirsoft-verification_kginicis` | 플러그인 | [docs/api/](../plugins/_bundled/sirsoft-verification_kginicis/docs/api/README.md) | 2 / 3 |
| `sirsoft-verification_nhnkcp` | 플러그인 | [docs/api/](../plugins/_bundled/sirsoft-verification_nhnkcp/docs/api/README.md) | 1 / 1 |


### 확장 개발자 문서 (22개 확장, 자동 스캔)

> 확장을 수정하기 전에 읽는 문서. 설계 의도 · 디렉토리 지도 · 확장점(발행/구독 훅) · 수정 시 동반 의무 · 금지 패턴을 담는다. `php artisan ext:docgen` 이 실측 부분을 유지하며, 이 표는 `{modules,plugins,templates}/_bundled/*/docs/README.md` 를 패턴 스캔해 자동 편입된다(확장명 하드코딩 없음).

| 확장 | 유형 | 에이전트 가이드 | 문서 목차 | 실측 집계 |
|------|------|----------------|----------|----------|
| `gnuboard7-hello_module` | 모듈 | [AGENTS.md](../modules/_bundled/gnuboard7-hello_module/AGENTS.md) | [docs/](../modules/_bundled/gnuboard7-hello_module/docs/README.md) | 훅 2 · 라우트 7 · 모델 1 · 레이아웃 4 |
| `sirsoft-board` | 모듈 | [AGENTS.md](../modules/_bundled/sirsoft-board/AGENTS.md) | [docs/](../modules/_bundled/sirsoft-board/docs/README.md) | 훅 105 · 라우트 109 · 모델 12 · 레이아웃 58 |
| `sirsoft-ecommerce` | 모듈 | [AGENTS.md](../modules/_bundled/sirsoft-ecommerce/AGENTS.md) | [docs/](../modules/_bundled/sirsoft-ecommerce/docs/README.md) | 훅 593 · 라우트 314 · 모델 54 · 레이아웃 247 |
| `sirsoft-page` | 모듈 | [AGENTS.md](../modules/_bundled/sirsoft-page/AGENTS.md) | [docs/](../modules/_bundled/sirsoft-page/docs/README.md) | 훅 21 · 라우트 18 · 모델 3 · 레이아웃 3 |
| `gnuboard7-hello_plugin` | 플러그인 | [AGENTS.md](../plugins/_bundled/gnuboard7-hello_plugin/AGENTS.md) | [docs/](../plugins/_bundled/gnuboard7-hello_plugin/docs/README.md) | 훅 1 · 라우트 0 · 모델 0 · 레이아웃 1 |
| `sirsoft-ckeditor5` | 플러그인 | [AGENTS.md](../plugins/_bundled/sirsoft-ckeditor5/AGENTS.md) | [docs/](../plugins/_bundled/sirsoft-ckeditor5/docs/README.md) | 훅 4 · 라우트 5 · 모델 1 · 레이아웃 2 |
| `sirsoft-daum_postcode` | 플러그인 | [AGENTS.md](../plugins/_bundled/sirsoft-daum_postcode/AGENTS.md) | [docs/](../plugins/_bundled/sirsoft-daum_postcode/docs/README.md) | 훅 0 · 라우트 0 · 모델 0 · 레이아웃 1 |
| `sirsoft-gdpr` | 플러그인 | [AGENTS.md](../plugins/_bundled/sirsoft-gdpr/AGENTS.md) | [docs/](../plugins/_bundled/sirsoft-gdpr/docs/README.md) | 훅 5 · 라우트 19 · 모델 4 · 레이아웃 10 |
| `sirsoft-koreaexim` | 플러그인 | [AGENTS.md](../plugins/_bundled/sirsoft-koreaexim/AGENTS.md) | [docs/](../plugins/_bundled/sirsoft-koreaexim/docs/README.md) | 훅 2 · 라우트 4 · 모델 2 · 레이아웃 12 |
| `sirsoft-marketing` | 플러그인 | [AGENTS.md](../plugins/_bundled/sirsoft-marketing/AGENTS.md) | [docs/](../plugins/_bundled/sirsoft-marketing/docs/README.md) | 훅 4 · 라우트 2 · 모델 2 · 레이아웃 1 |
| `sirsoft-message_bizppurio` | 플러그인 | [AGENTS.md](../plugins/_bundled/sirsoft-message_bizppurio/AGENTS.md) | [docs/](../plugins/_bundled/sirsoft-message_bizppurio/docs/README.md) | 훅 1 · 라우트 21 · 모델 2 · 레이아웃 1 |
| `sirsoft-pay_kginicis` | 플러그인 | [AGENTS.md](../plugins/_bundled/sirsoft-pay_kginicis/AGENTS.md) | [docs/](../plugins/_bundled/sirsoft-pay_kginicis/docs/README.md) | 훅 6 · 라우트 37 · 모델 0 · 레이아웃 1 |
| `sirsoft-pay_nhnkcp` | 플러그인 | [AGENTS.md](../plugins/_bundled/sirsoft-pay_nhnkcp/AGENTS.md) | [docs/](../plugins/_bundled/sirsoft-pay_nhnkcp/docs/README.md) | 훅 8 · 라우트 15 · 모델 0 · 레이아웃 1 |
| `sirsoft-pay_nicepayments` | 플러그인 | [AGENTS.md](../plugins/_bundled/sirsoft-pay_nicepayments/AGENTS.md) | [docs/](../plugins/_bundled/sirsoft-pay_nicepayments/docs/README.md) | 훅 5 · 라우트 14 · 모델 0 · 레이아웃 1 |
| `sirsoft-tosspayments` | 플러그인 | [AGENTS.md](../plugins/_bundled/sirsoft-tosspayments/AGENTS.md) | [docs/](../plugins/_bundled/sirsoft-tosspayments/docs/README.md) | 훅 4 · 라우트 6 · 모델 0 · 레이아웃 1 |
| `sirsoft-totp` | 플러그인 | [AGENTS.md](../plugins/_bundled/sirsoft-totp/AGENTS.md) | [docs/](../plugins/_bundled/sirsoft-totp/docs/README.md) | 훅 1 · 라우트 6 · 모델 1 · 레이아웃 1 |
| `sirsoft-verification_kginicis` | 플러그인 | [AGENTS.md](../plugins/_bundled/sirsoft-verification_kginicis/AGENTS.md) | [docs/](../plugins/_bundled/sirsoft-verification_kginicis/docs/README.md) | 훅 3 · 라우트 2 · 모델 2 · 레이아웃 1 |
| `sirsoft-verification_nhnkcp` | 플러그인 | [AGENTS.md](../plugins/_bundled/sirsoft-verification_nhnkcp/AGENTS.md) | [docs/](../plugins/_bundled/sirsoft-verification_nhnkcp/docs/README.md) | 훅 0 · 라우트 2 · 모델 2 · 레이아웃 1 |
| `gnuboard7-hello_admin_template` | 템플릿 | [AGENTS.md](../templates/_bundled/gnuboard7-hello_admin_template/AGENTS.md) | [docs/](../templates/_bundled/gnuboard7-hello_admin_template/docs/README.md) | 훅 0 · 라우트 1 · 모델 0 · 레이아웃 8 |
| `gnuboard7-hello_user_template` | 템플릿 | [AGENTS.md](../templates/_bundled/gnuboard7-hello_user_template/AGENTS.md) | [docs/](../templates/_bundled/gnuboard7-hello_user_template/docs/README.md) | 훅 0 · 라우트 1 · 모델 0 · 레이아웃 8 |
| `sirsoft-admin_basic` | 템플릿 | [AGENTS.md](../templates/_bundled/sirsoft-admin_basic/AGENTS.md) | [docs/](../templates/_bundled/sirsoft-admin_basic/docs/README.md) | 훅 0 · 라우트 44 · 모델 0 · 레이아웃 184 |
| `sirsoft-basic` | 템플릿 | [AGENTS.md](../templates/_bundled/sirsoft-basic/AGENTS.md) | [docs/](../templates/_bundled/sirsoft-basic/docs/README.md) | 훅 0 · 라우트 49 · 모델 0 · 레이아웃 194 |


<!-- AUTO-GENERATED-END: docs-readme-full-list -->
