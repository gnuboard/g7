# 그누보드7 Development Guide

> 이 문서는 그누보드7 오픈소스 CMS 프로젝트의 개발 가이드입니다. AI 에이전트 및 외부 기여자를 위한 참고 자료입니다.

## 빠른 참조 - 상세 가이드 문서

<!-- AUTO-GENERATED-START: docs-quick-reference -->

### 백엔드 [backend/](docs/backend/) (35개)

| 문서 | 설명 | TL;DR 핵심 |
|------|------|-----------|
| [activity-log-hooks.md](docs/backend/activity-log-hooks.md) | 활동 로그 훅 레퍼런스 (Activity Log Hooks Reference) | 코어 66훅 + 이커머스 92훅 + 게시판 32훅 + 페이지 8훅 = 총 198훅 |
| [activity-log.md](docs/backend/activity-log.md) | 활동 로그 시스템 (Activity Log System) | Monolog 기반: Service 훅 → Listener → Log::channel('activity... |
| [admin-settings-access.md](docs/backend/admin-settings-access.md) | Admin 환경설정 값 접근 (`g7_core_settings` vs `config()`) | 동기화 SSoT: storage/app/settings/*.json → SettingsServicePr... |
| [api-documentation.md](docs/backend/api-documentation.md) | API 레퍼런스 문서 규정 (API Documentation) | 모든 API 엔드포인트는 레퍼런스 문서 필수 — 메서드/URI/파라미터/응답 필드 + 요청·응답 예시 ... |
| [api-resources.md](docs/backend/api-resources.md) | API 리소스 | Resource: BaseApiResource 상속 필수 / Collection: BaseApiColl... |
| [authentication.md](docs/backend/authentication.md) | 인증 및 세션 처리 | Laravel Sanctum 토큰 전용 인증 (Bearer 토큰만 사용) |
| [benchmark.md](docs/backend/benchmark.md) | 성능 계측 시스템 (Benchmark) | `g7:bench` 가 4축(list/screen/write/batch)을 잰다 — 계측 대상은 커맨드... |
| [broadcasting.md](docs/backend/broadcasting.md) | Broadcasting (실시간 이벤트) | Laravel Reverb 사용 (WebSocket) |
| [console-confirm.md](docs/backend/console-confirm.md) | 콘솔 yes/no 프롬프트 (ConsoleConfirm) | 콘솔 커맨드의 yes/no 프롬프트는 $this->unifiedConfirm() 사용 — Laravel... |
| [controllers.md](docs/backend/controllers.md) | 컨트롤러 계층 구조 | AdminBaseController / AuthBaseController / PublicBaseCont... |
| [core-config.md](docs/backend/core-config.md) | 코어 설정 (config/core.php) | config/core.php = 코어 권한/역할/메뉴/메일템플릿의 SSoT (Single Source ... |
| [core-update-system.md](docs/backend/core-update-system.md) | 코어 업데이트 시스템 (Core Update System) | 코어 업그레이드 스텝: upgrades/ 디렉토리 (프로젝트 루트), 네임스페이스 App\Upgrades |
| [data-sync-helpers.md](docs/backend/data-sync-helpers.md) | 데이터 동기화 Helper (Data Sync Helpers) | 모든 데이터 동기화는 Service/Seeder 가 Helper 를 호출해 수행 (직접 Model 조작... |
| [dto.md](docs/backend/dto.md) | DTO (Data Transfer Object) 사용 규칙 | DTO 두 패턴 — Value Object(불변 1회 전달) vs Data Carrier(다단계 변형/... |
| [enum.md](docs/backend/enum.md) | Enum 사용 규칙 | 상태/타입/분류 = Enum 필수 (PHP 8.1+ Backed Enum) |
| [exceptions.md](docs/backend/exceptions.md) | Custom Exception 다국어 처리 | 예외 메시지 하드코딩 금지 → __() 함수 필수 |
| [geoip.md](docs/backend/geoip.md) | GeoIP 시스템 (MaxMind GeoLite2) | MaxMind GeoLite2-City DB 기반 IP → 타임존 감지 (SetTimezone 미들웨어... |
| [identity-messages.md](docs/backend/identity-messages.md) | 본인인증 메시지 템플릿 시스템 (Identity Messages) | 알림 시스템(notification_*)과 완전 분리된 IDV 전용 템플릿 인프라 |
| [identity-policies.md](docs/backend/identity-policies.md) | 본인인증 정책 시스템 (Identity Policies) | - |
| [identity-providers.md](docs/backend/identity-providers.md) | IDV Provider 작성 가이드 (Identity Verification Providers) | VerificationProviderInterface 구현 + IdentityProviderManage... |
| [language-pack-service.md](docs/backend/language-pack-service.md) | LanguagePackService (백엔드 Service 레이어) | LanguagePackService 가 install/activate/deactivate/uninsta... |
| [middleware.md](docs/backend/middleware.md) | 미들웨어 등록 규칙 | 인증 필요 미들웨어 → 전역 등록 금지! |
| [notification-system.md](docs/backend/notification-system.md) | 알림 시스템 (Notification System) | GenericNotification 범용 클래스 1개로 모든 알림 처리 (개별 클래스 불필요) |
| [pagination.md](docs/backend/pagination.md) | 대용량 목록 페이지네이션 (Pagination) | 총 건수만 상한을 받는다 — 상한 이하면 정확, 초과면 "이상"(total_relation=at_least) |
| [response-helper.md](docs/backend/response-helper.md) | API 응답 규칙 (ResponseHelper) | 모든 API 응답은 ResponseHelper 사용 |
| [routing.md](docs/backend/routing.md) | 라우트 네이밍 및 경로 | 모든 라우트는 name() 필수: ->name('api.users.index') |
| [search-system.md](docs/backend/search-system.md) | Scout 검색 엔진 시스템 (Search System) | Laravel Scout + DatabaseFulltextEngine: MySQL FULLTEXT + ... |
| [seo-system.md](docs/backend/seo-system.md) | SEO 페이지 생성기 시스템 (SEO Page Generator) | SeoMiddleware: 봇 요청 감지 → ?locale= 파라미터 해석 → SeoRenderer가 ... |
| [service-provider.md](docs/backend/service-provider.md) | 서비스 프로바이더 안전성 | DB 접근 전 .env 파일 존재 확인 필수 |
| [service-repository.md](docs/backend/service-repository.md) | Service-Repository 패턴 | RepositoryInterface 주입 필수 (구체 클래스 직접 주입 금지) |
| [settings-multilingual-enrichment.md](docs/backend/settings-multilingual-enrichment.md) | Settings 카탈로그 다국어 자동 보강 | settings JSON 의 다국어 카탈로그 라벨(_cached_name 등)은 카탈로그 빌드 시점에 보강 |
| [static-asset-publishing.md](docs/backend/static-asset-publishing.md) | 부트스트랩 리소스 정적 게시 (Static Asset Publishing) | 게시물: public/build/ext/{cache_version}/ — 수명주기 이벤트와 운영자 cu... |
| [translatable-seeders.md](docs/backend/translatable-seeders.md) | 다국어 시더 인터페이스 (Translatable Seeders) | 다국어 JSON 컬럼(name 등)을 시드하는 확장 entity 시더는 TranslatableSeede... |
| [user-overrides.md](docs/backend/user-overrides.md) | 사용자 수정 보존 (HasUserOverrides Trait) | 모델에 `use HasUserOverrides;` + `protected array $trackable... |
| [validation.md](docs/backend/validation.md) | 검증 (Validation) | 필수: FormRequest에서 검증 (Service에 검증 로직 배치 금지) |

### 프론트엔드 [frontend/](docs/frontend/) (50개)

| 문서 | 설명 | TL;DR 핵심 |
|------|------|-----------|
| [actions-g7core-api.md](docs/frontend/actions-g7core-api.md) | 액션 시스템 - G7Core API (React 컴포넌트용) | - |
| [actions-handlers-navigation.md](docs/frontend/actions-handlers-navigation.md) | 액션 핸들러 - 네비게이션 | - |
| [actions-handlers-state.md](docs/frontend/actions-handlers-state.md) | 액션 핸들러 - 상태 관리 | - |
| [actions-handlers-ui.md](docs/frontend/actions-handlers-ui.md) | 액션 핸들러 - UI 인터랙션 | - |
| [actions-handlers.md](docs/frontend/actions-handlers.md) | 액션 핸들러 - 핸들러별 상세 사용법 | navigate: 페이지 이동 (path, query, mergeQuery 옵션) |
| [actions.md](docs/frontend/actions.md) | 액션 핸들러 가이드 | 구조: type 또는 event(이벤트), handler(핸들러명), params(옵션) |
| [auth-system.md](docs/frontend/auth-system.md) | 인증 시스템 (AuthManager) | AuthManager: 싱글톤 인증 상태 관리 클래스 |
| [component-props-composite.md](docs/frontend/component-props-composite.md) | 컴포넌트 Props 레퍼런스 - Composite | FileUploader: autoUpload, uploadTriggerEvent, imageCompre... |
| [component-props.md](docs/frontend/component-props.md) | 컴포넌트 Props 레퍼런스 | - |
| [components-advanced.md](docs/frontend/components-advanced.md) | 컴포넌트 고급 기능 | - |
| [components-patterns.md](docs/frontend/components-patterns.md) | 컴포넌트 패턴 및 다국어 | - |
| [components-types.md](docs/frontend/components-types.md) | 컴포넌트 타입별 개발 규칙 | - |
| [components.md](docs/frontend/components.md) | 컴포넌트 개발 규칙 | HTML 태그 직접 사용 금지 (<div> → Div, <button> → Button) |
| [dark-mode.md](docs/frontend/dark-mode.md) | 다크 모드 지원 (engine-v1.1.0+) | Tailwind dark: variant 사용 (예: bg-white dark:bg-gray-800) |
| [data-binding-i18n.md](docs/frontend/data-binding-i18n.md) | 데이터 바인딩 - 다국어 처리 | - |
| [data-binding.md](docs/frontend/data-binding.md) | 데이터 바인딩 및 표현식 | API 데이터: {{user.name}}, URL 파라미터: {{route.id}} |
| [data-sources-advanced.md](docs/frontend/data-sources-advanced.md) | 데이터 소스 - 고급 기능 | - |
| [data-sources.md](docs/frontend/data-sources.md) | 데이터 소스 (Data Sources) | data_sources 배열에 API 정의: id, endpoint, method |
| [editors.md](docs/frontend/editors.md) | 에디터 컴포넌트 가이드 | HtmlEditor: HTML/텍스트 편집, 게시판/상품 설명 등 사용 |
| [g7core-api-advanced.md](docs/frontend/g7core-api-advanced.md) | G7Core 전역 API 레퍼런스 - 고급 | - |
| [g7core-api.md](docs/frontend/g7core-api.md) | G7Core 전역 API 레퍼런스 | G7Core.state: get/set/subscribe 전역 상태 관리 |
| [g7core-helpers.md](docs/frontend/g7core-helpers.md) | G7Core 헬퍼 API | - |
| [identity-guard-interceptor.md](docs/frontend/identity-guard-interceptor.md) | IdentityGuardInterceptor — 코어 본인인증 인터셉터 레퍼런스 | ActionDispatcher.handleApiCall 응답 후처리에서 isIdentityRequire... |
| [identity-verification-ui.md](docs/frontend/identity-verification-ui.md) | 본인인증(IDV) 공통 UI 가이드 | 모든 IDV 강제 지점은 동일한 428 응답 형식을 공유 (코어 9 + 게시판 4 + 이커머스 4 + N) |
| [layout-json-components-loading.md](docs/frontend/layout-json-components-loading.md) | 레이아웃 JSON - 데이터 로딩 및 생명주기 | - |
| [layout-json-components-rendering.md](docs/frontend/layout-json-components-rendering.md) | 레이아웃 JSON - 조건부/반복 렌더링 | - |
| [layout-json-components-slots.md](docs/frontend/layout-json-components-slots.md) | 레이아웃 JSON - 슬롯 시스템 | - |
| [layout-json-components.md](docs/frontend/layout-json-components.md) | 레이아웃 JSON - 컴포넌트 (반복 렌더링, Blur, 생명주기, 슬롯) | if: 조건부 렌더링 (type: "conditional" 사용 금지!) |
| [layout-json-features-actions.md](docs/frontend/layout-json-features-actions.md) | 레이아웃 JSON - 초기화, 모달, 액션, 스크립트 | - |
| [layout-json-features-error.md](docs/frontend/layout-json-features-error.md) | 레이아웃 JSON - 에러 핸들링 | - |
| [layout-json-features-styling.md](docs/frontend/layout-json-features-styling.md) | 레이아웃 JSON - 스타일 및 계산된 값 | - |
| [layout-json-features.md](docs/frontend/layout-json-features.md) | 레이아웃 JSON - 기능 (에러 핸들링, 초기화, 모달, 액션) | classMap: 조건부 CSS 클래스 (key → variants 매핑) |
| [layout-json-inheritance.md](docs/frontend/layout-json-inheritance.md) | 레이아웃 JSON - 상속 (Extends, Partial, 병합) | extends: 베이스 레이아웃 상속 (type: "slot" 위치에 삽입) |
| [layout-json.md](docs/frontend/layout-json.md) | 레이아웃 JSON 스키마 | HTML 태그 직접 사용 금지 → 기본 컴포넌트 사용 (Div, Button, Span) |
| [layout-testing.md](docs/frontend/layout-testing.md) | 그누보드7 레이아웃 파일 렌더링 테스트 가이드 | createLayoutTest()로 테스트 헬퍼 생성, mockApi()로 API 응답 모킹 |
| [modal-usage.md](docs/frontend/modal-usage.md) | Modal 컴포넌트 사용 가이드 | modals 섹션 모달은 openModal 핸들러로 열고, closeModal 핸들러로 닫음 |
| [responsive-layout.md](docs/frontend/responsive-layout.md) | 반응형 레이아웃 개발 (engine-v1.1.0+) | responsive 속성: 컴포넌트 레벨 breakpoint 오버라이드 (권장) |
| [security.md](docs/frontend/security.md) | 보안 및 검증 | 레이아웃 JSON: FormRequest + Custom Rule 10종 검증 (서버 사전 차단) |
| [state-management-advanced.md](docs/frontend/state-management-advanced.md) | 상태 관리 - 고급 기능 | - |
| [state-management-forms.md](docs/frontend/state-management-forms.md) | 상태 관리 - 폼 자동 바인딩 및 setState | - |
| [state-management.md](docs/frontend/state-management.md) | 전역 상태 관리 | 전역 상태: _global.속성명 (앱 전체 공유, 페이지 이동 시 유지) |
| [tailwind-safelist.md](docs/frontend/tailwind-safelist.md) | Tailwind Safelist 가이드 | Tailwind는 빌드 시 사용된 클래스만 CSS에 포함 |
| [template-development.md](docs/frontend/template-development.md) | 템플릿 개발 가이드라인 | 디렉토리: templates/[vendor-template]/ (예: sirsoft-admin_basic) |
| [template-handlers.md](docs/frontend/template-handlers.md) | 템플릿 전용 핸들러 | setLocale: 앱 언어 변경 — 엔진 빌트인 (ActionDispatcher) |
| [components.md](docs/frontend/templates/sirsoft-admin_basic/components.md) | sirsoft-admin_basic 컴포넌트 | Basic 37개: HTML 래핑 (Div, Button, Input, Select, Form, A, ... |
| [handlers.md](docs/frontend/templates/sirsoft-admin_basic/handlers.md) | sirsoft-admin_basic 핸들러 | setLocale: 앱 언어 변경 (locale 파라미터) |
| [layouts.md](docs/frontend/templates/sirsoft-admin_basic/layouts.md) | sirsoft-admin_basic 레이아웃 | 베이스: _admin_base.json (사이드바 + 헤더 + 콘텐츠 슬롯) |
| [components.md](docs/frontend/templates/sirsoft-basic/components.md) | sirsoft-basic 컴포넌트 | Basic 26개: HTML 래핑 (Div, Button, Input, Select, Form, A, ... |
| [handlers.md](docs/frontend/templates/sirsoft-basic/handlers.md) | sirsoft-basic 핸들러 | setTheme/initTheme: 다크/라이트 모드 전환 (admin과 동일 키 공유) |
| [layouts.md](docs/frontend/templates/sirsoft-basic/layouts.md) | sirsoft-basic 레이아웃 | 베이스: _user_base.json (헤더 + 푸터 + 모바일 네비 + 콘텐츠 슬롯) |

### 확장 시스템 [extension/](docs/extension/) (30개)

| 문서 | 설명 | TL;DR 핵심 |
|------|------|-----------|
| [cache-driver.md](docs/extension/cache-driver.md) | 캐시 드라이버 시스템 (CacheInterface) | 모든 캐시 저장은 CacheInterface 사용 (Cache:: 직접 호출 금지) |
| [changelog-rules.md](docs/extension/changelog-rules.md) | Changelog 규칙 (Changelog Rules) | 확장/코어 버전 업 시 CHANGELOG.md에 변경사항 기록 필수 (미기록 시 버전 업 불가) |
| [editor-spec.md](docs/extension/editor-spec.md) | 편집기 스펙 (editor-spec.json) | editor-spec.json = 편집기 팔레트/스타일 컨트롤/중첩 규칙/샘플 데이터/레시피의 선언 (... |
| [extension-manager.md](docs/extension/extension-manager.md) | ExtensionManager (확장 관리자) | composer.json 수정 없음 - 런타임 오토로드 방식 사용 |
| [extension-update-system.md](docs/extension/extension-update-system.md) | 확장 업데이트 시스템 (Extension Update System) | 업데이트 감지 우선순위: GitHub > _bundled (2단계, _pending 미참여) |
| [hooks.md](docs/extension/hooks.md) | 훅 시스템 (Hook System) | Action 훅: doAction() - 부가 작업 (로그, 알림, 캐시) |
| [language-packs.md](docs/extension/language-packs.md) | 언어팩 시스템 (Language Packs) | 코어/번들 확장의 lang/{ko,en}/ 는 가상 보호 행으로 자동 노출 (DB 없이 항상 activ... |
| [layout-extensions.md](docs/extension/layout-extensions.md) | 레이아웃 확장 시스템 (Layout Extensions) | - |
| [menus.md](docs/extension/menus.md) | 메뉴 시스템 | 구조: User → Role → role_menus 피벗 → Menu |
| [module-assets.md](docs/extension/module-assets.md) | 모듈 프론트엔드 에셋 시스템 | module.json에 에셋 매니페스트 정의 (js, css, loading strategy) |
| [module-basics.md](docs/extension/module-basics.md) | 모듈 개발 기초 | 디렉토리: vendor-module (예: sirsoft-ecommerce) |
| [module-commands.md](docs/extension/module-commands.md) | 모듈 Artisan 커맨드 | 목록: php artisan module:list |
| [module-i18n.md](docs/extension/module-i18n.md) | 모듈 다국어 시스템 | 백엔드: /src/lang/{locale}/*.php → __('vendor-module::key') ... |
| [module-identity-settings.md](docs/extension/module-identity-settings.md) | 모듈/플러그인 본인인증(IDV) 설정 통합 가이드 | 정책/목적/메시지: module.php::getIdentity{Policies,Purposes,Mess... |
| [module-layouts.md](docs/extension/module-layouts.md) | 모듈 레이아웃 시스템 | 위치: modules/_bundled/vendor-module/resources/layouts/admi... |
| [module-routing.md](docs/extension/module-routing.md) | 모듈 라우트 규칙 | URL prefix 자동: /api/modules/[vendor-module]/... |
| [module-settings.md](docs/extension/module-settings.md) | 모듈 환경설정 시스템 개발 가이드 | - |
| [permissions.md](docs/extension/permissions.md) | 권한 시스템 | 구조: User → Role → Permission (기능 레벨) |
| [plugin-development.md](docs/extension/plugin-development.md) | 플러그인 개발 가이드 | 디렉토리: plugins/vendor-plugin (예: sirsoft-payment) |
| [sample-extensions.md](docs/extension/sample-extensions.md) | 학습용 샘플 확장 (Sample Extensions) | 샘플 확장 4종: gnuboard7-hello_module / _plugin / _admin_templ... |
| [storage-driver.md](docs/extension/storage-driver.md) | 스토리지 드라이버 시스템 (StorageInterface) | 모든 파일 저장은 StorageInterface 사용 (Storage::disk() 직접 호출 금지) |
| [template-basics.md](docs/extension/template-basics.md) | 템플릿 시스템 기초 | 타입: Admin (관리자용), User (일반사용자용) |
| [template-caching.md](docs/extension/template-caching.md) | 템플릿 캐싱 전략 | - |
| [template-commands.md](docs/extension/template-commands.md) | 템플릿 Artisan 커맨드 | 목록: php artisan template:list |
| [template-idv-bootstrap.md](docs/extension/template-idv-bootstrap.md) | 템플릿 IDV launcher 등록 가이드 | 템플릿 부트스트랩(initTemplate)에서 window.G7Core.identity.setLaunc... |
| [template-routing.md](docs/extension/template-routing.md) | 템플릿 라우트/언어 파일 규칙 | - |
| [template-security.md](docs/extension/template-security.md) | 템플릿 보안 정책 | - |
| [template-workflow.md](docs/extension/template-workflow.md) | 템플릿 개발 워크플로우 | 필수 파일: template.json, routes.json, _base.json, errors/{40... |
| [upgrade-step-guide.md](docs/extension/upgrade-step-guide.md) | 업그레이드 스텝 작성 가이드 (Upgrade Step Guide) | upgrade step 이 실행되는 환경은 경로에 따라 다르다 — 섹션 9 "업그레이드 경로" 먼저 읽기 |
| [vendor-bundle.md](docs/extension/vendor-bundle.md) | Vendor 번들 시스템 (Vendor Bundle System) | - |

### 공통 (5개)

| 문서 | 설명 | TL;DR 핵심 |
|------|------|-----------|
| [cheatsheet.md](docs/cheatsheet.md) | 그누보드7 자주 쓰는 명령어 치트시트 | _bundled에서 레이아웃 JSON만 수정 → 확장 업데이트(--force)만 실행 (빌드 불필요) |
| [database-guide.md](docs/database-guide.md) | 그누보드7 데이터베이스 개발 가이드 | 마이그레이션: 한국어 comment 필수, down() 구현 필수 |
| [requirements.md](docs/requirements.md) | 그누보드7 시스템 요구사항 (System Requirements) | PHP 8.2+ 필수 |
| [testing-guide.md](docs/testing-guide.md) | 그누보드7 테스트 가이드 | 테스트 통과 = 작업 완료 (작성만으로 불충분!) |
| [e2e-testing.md](docs/testing/e2e-testing.md) | 그누보드7 Playwright E2E 테스트 가이드 | - |


### API 레퍼런스 진입점

> 엔드포인트별 요청 파라미터·응답 필드·요청/응답 예시. 공통 규약(Bearer 토큰 인증, 응답 봉투, 페이지네이션, 401/403/422/428)은 진입점 문서 상단에 정리되어 있다.

| 대상 | 진입점 | 문서/엔드포인트 |
|------|--------|----------------|
| 코어 | [docs/backend/api/README.md](docs/backend/api/README.md) | 36 / 324 |


### 확장 API 레퍼런스 (14개 확장, 자동 스캔)

> 각 확장이 소유하는 API 문서 목차. `php artisan api:docgen` 이 생성하며, 이 표는 `{modules,plugins}/_bundled/*/docs/api/README.md` 를 패턴 스캔해 자동 편입된다(확장명 하드코딩 없음).

| 확장 | 유형 | API 문서 목차 | 문서/엔드포인트 |
|------|------|--------------|----------------|
| `gnuboard7-hello_module` | 모듈 | [docs/api/](modules/_bundled/gnuboard7-hello_module/docs/api/README.md) | 1 / 7 |
| `sirsoft-board` | 모듈 | [docs/api/](modules/_bundled/sirsoft-board/docs/api/README.md) | 10 / 80 |
| `sirsoft-ecommerce` | 모듈 | [docs/api/](modules/_bundled/sirsoft-ecommerce/docs/api/README.md) | 33 / 239 |
| `sirsoft-page` | 모듈 | [docs/api/](modules/_bundled/sirsoft-page/docs/api/README.md) | 2 / 17 |
| `sirsoft-ckeditor5` | 플러그인 | [docs/api/](plugins/_bundled/sirsoft-ckeditor5/docs/api/README.md) | 3 / 5 |
| `sirsoft-gdpr` | 플러그인 | [docs/api/](plugins/_bundled/sirsoft-gdpr/docs/api/README.md) | 4 / 15 |
| `sirsoft-marketing` | 플러그인 | [docs/api/](plugins/_bundled/sirsoft-marketing/docs/api/README.md) | 2 / 2 |
| `sirsoft-message_bizppurio` | 플러그인 | [docs/api/](plugins/_bundled/sirsoft-message_bizppurio/docs/api/README.md) | 6 / 21 |
| `sirsoft-pay_kginicis` | 플러그인 | [docs/api/](plugins/_bundled/sirsoft-pay_kginicis/docs/api/README.md) | 5 / 34 |
| `sirsoft-pay_nhnkcp` | 플러그인 | [docs/api/](plugins/_bundled/sirsoft-pay_nhnkcp/docs/api/README.md) | 0 / 0 |
| `sirsoft-pay_nicepayments` | 플러그인 | [docs/api/](plugins/_bundled/sirsoft-pay_nicepayments/docs/api/README.md) | 0 / 0 |
| `sirsoft-tosspayments` | 플러그인 | [docs/api/](plugins/_bundled/sirsoft-tosspayments/docs/api/README.md) | 2 / 4 |
| `sirsoft-verification_kginicis` | 플러그인 | [docs/api/](plugins/_bundled/sirsoft-verification_kginicis/docs/api/README.md) | 2 / 3 |
| `sirsoft-verification_nhnkcp` | 플러그인 | [docs/api/](plugins/_bundled/sirsoft-verification_nhnkcp/docs/api/README.md) | 1 / 1 |


<!-- AUTO-GENERATED-END: docs-quick-reference -->

---

## 프로젝트 개요

**프로젝트명**: 그누보드7
**목적**: 오픈소스 CMS 플랫폼
**설계 원칙**: 코어 수정 최소화, 모듈화, 플러그인 시스템, 템플릿 시스템, 동적 로딩

---

## 버전 동기화 의무

코어 또는 번들 확장의 공개 표면을 수정할 때, 그 변경의 영향 범위에 있는 다른 확장의 버전 제약(`g7_version`, `dependencies.{modules|plugins}`)을 함께 갱신한다.

### ① 코어 → 확장 동기화 (`requires.g7_version`)

- 트리거: 코어 공개 확장 표면(`app/Extension/Abstract*`, `HookManager`, `ExtensionManager`, `ModuleManager`, `PluginManager`, `TemplateManager`, `app/Contracts/Extension/**`, `app/Extension/Helpers/**`, `app/Repositories/Concerns/**`, `app/Seo/Contracts/**`, `app/ActivityLog/**` 공개 API, 루트 `CHANGELOG.md` Added/Changed/Removed) 수정
- 조치: 영향 받는 번들 확장의 `g7_version` 상향 + 각 확장 CHANGELOG 에 변경 기재

### ② 확장 → 확장 동기화 (`dependencies.{modules|plugins}`)

- 트리거: 번들 모듈/플러그인의 공개 Service/Contract/Repository/Model/Route, 발행 훅·이벤트, CHANGELOG 수정
- 조치: 그 확장에 의존하는 다른 번들 확장 전수 스캔 → 최소 버전 제약 상향 여부 판정

### 판정 순서

1. 기존 소비자 API 시그니처/동작을 건드렸는가 → 소비 확장 최소 버전 상향
2. 새 공개 API 가 도입되었는가 → 후보 확장 전수 스캔 후 검토
3. 의존 관계 B 의 공개 API 가 변경되었는가 → A 의 `dependencies.B` 상향
4. 동기화 대상이 없다면 그 근거("순수 내부 리팩토링" 등)를 변경 이력에 기록

> 상세: [changelog-rules.md](docs/extension/changelog-rules.md) "코어 버전 제약 정책"

---

## CRITICAL RULES - 절대 금지 패턴 (DO NOT)

### API/핸들러 호출

| 금지 | 올바른 사용 |
|------|------------|
| `G7Core.actions.execute` | `G7Core.dispatch` |
| `G7Core.api.call` | `G7Core.dispatch({ handler: 'apiCall', ... })` |
| `handler: "api"` | `handler: "apiCall"` |
| `handler: "nav"` | `handler: "navigate"` |
| `handler: "setLocalState"` | `handler: "setState"` + `target: "local"` |
| `navigate` + `replace: true` (URL만 변경 시) | `handler: "replaceUrl"` |
| `navigate` `params.path: "back"` (동작 키워드로 착각) | `handler: "navigateBack"` — path 는 주소로 해석되어 조용히 `/back` 으로 이동한다 |
| `navigate` `params.url` / `href` / `to` 로 목적지 전달 | `params.path` (또는 액션 `target`) — 엔진은 이 둘만 읽는다. 다른 이름은 무시되어 목적지가 `undefined` 가 되고, 예외도 404 도 없이 버튼만 동작하지 않는다 |
| apiCall `params.target` (params 내부) | `target` 은 액션 top-level. params 내부 위치 시 URL 미해석 |
| apiCall `params.onSuccess` / `params.onError` (params 내부) | 액션 top-level. params 내부면 무시됨 |
| `refetchDataSource` `params.id` | `params.dataSourceId` 사용 |
| `handler: "showToast"` | `handler: "toast"` |
| 모달 안에서 부모 `_local.*` 참조 | 데이터소스 응답 필드 또는 `_global` 사용 (모달은 별도 컨텍스트) |

### 데이터 바인딩

| 금지 | 올바른 사용 |
|------|------------|
| `{{products.data}}` | `{{products?.data?.data}}` (배열 경로 확인) |
| `{{value}}` | `{{value ?? ''}}` (fallback 필수) |
| `{{error.data}}` | `{{error.errors}}` (API 응답 구조) |
| `{{error.data?.errors ?? {}}}` | `{{error.errors}}` (`{}}}` 파서 모호성 회피) |
| `$value` (이벤트 값) | `$event.target.value` |
| `{{props.xxx}}` (Partial) | data_sources ID 직접 참조 |
| `{{$response.xxx}}` (onSuccess) | `{{response.xxx}}` ($ 접두사 없음) |

### iteration/반복 렌더링

| 금지 | 올바른 사용 |
|------|------------|
| `"item"`, `"index"` | `"item_var"`, `"index_var"` |
| iteration 내 if 순서 무시 | if가 iteration보다 먼저 평가됨 |

### 컴포넌트 Props

| 금지 | 올바른 사용 |
|------|------------|
| `Icon className="w-4 h-4"` | `className="text-base"` (아래 등가표) 또는 `size` prop |
| `Select valueKey/labelKey` | computed로 `{ value, label }` 변환 |
| Form 내 `Button` type 없음 | `type="button"` 명시 (submit 방지) |
| `options={{options}}` | `options={{options ?? []}}` (fallback) |
| boolean 필드를 `RadioGroup`/`Select` 의 `name` 자동바인딩만으로 폼에 묶기 | `autoBinding: false` + `value: "{{String(_local.form?.필드 ?? 기본값)}}"` + `change` 액션 `"{{$event.target.value === 'true'}}"` 캐스팅. 자동바인딩 value 경로는 `e.target.value` 문자열을 그대로 저장해 서버 `boolean` 규칙에서 422 가 된다 (표시만 보면 정상이라 저장 시점에야 드러남) |
| `options` 지정 커스텀 `Select`(composite) 에 `defaultValue` | `value: "{{상태 ?? 기본값}}"` + `change` 액션 + 열기 지점 상태 시드 — 커스텀 Select 는 value-제어 전용이라 `defaultValue` 는 렌더되지 않고(빈 표시) 숨은 input 도 없어 값이 조용히 미전송된다 (options 없는 네이티브 렌더 경로만 defaultValue 유효) |
| 폼 밖 제출 버튼 `props.form: "X"` 만 선언 | 참조 대상 `Form` 에 `props.id: "X"` 동반 필수 — id 가 없으면 버튼이 어떤 폼에도 연결되지 않아 클릭이 무반응이 된다 (오류 없음) |

Icon 은 `<i>` 글리프라 박스 크기가 곧 `font-size` 다. `w-N h-N` 은 박스만 정하고 글리프는 부모 `font-size` 를 상속하므로 어긋난다. 기존 `w-N h-N` 을 옮길 때는 아래 등가표를 쓴다 (Chrome 실측).

| `w-N h-N` | px | 등가 `className` |
|---|---|---|
| `w-3 h-3` | 12 | `text-xs` |
| `w-3.5 h-3.5` | 14 | `text-sm` |
| `w-4 h-4` | 16 | `text-base` |
| `w-5 h-5` | 20 | `text-xl` |
| `w-6 h-6` | 24 | `text-2xl` |
| `w-12 h-12` | 48 | `text-5xl` |

`size` prop 은 Font Awesome `fa-*` 클래스로 매핑되며 등가가 아니다 — `size="sm"` → `fa-sm` → `font-size: 0.875em`(상대값) + `line-height` 붕괴로 16px 이 12.25×0.88px 이 된다. 새 아이콘에는 써도 되지만, 기존 `w-N h-N` 의 치환용으로는 쓰지 않는다.

### 상태 관리

| 금지 | 올바른 사용 |
|------|------------|
| 스냅샷 기반 setState | 함수형 업데이트 또는 `stateRef.current` |
| closeModal 후 setState | setState 후 closeModal (순서 중요) |
| sortable 내 폼 자동바인딩 | `parentFormContextProp={undefined}` |
| await 후 캡처된 상태 사용 | await 후 `G7Core.state.getLocal()` 재조회 |
| setState params 키에 `{{}}` 사용 | 키는 정적 경로만, 배열 조작은 `.map()`/`.filter()` |

### 핸들러 정의

| 금지 | 올바른 사용 |
|------|------------|
| `{{handler()}}` (표현식에서 호출) | `actions: [{ handler: "xxx" }]` |

### globalHeaders 사용 (engine-v1.16.0+)

| 금지 | 올바른 사용 |
|------|------------|
| `"globalHeaders": { "X-Key": "value" }` | `"globalHeaders": [{ "pattern": "*", "headers": {...} }]` |
| 모든 API에 개별 headers 설정 | globalHeaders로 공통 헤더 정의 |
| pattern 없이 헤더 정의 | pattern 필수 (`*`, `/api/shop/*` 등) |

### 인증/리다이렉트 규칙 (engine-v1.47.0+)

| 금지 | 올바른 사용 |
|------|------------|
| 모듈/플러그인에서 `AuthManager.updateConfig()` 호출 | 템플릿 부트스트랩(`initTemplate`)에서만 호출 |
| `AuthManager.updateConfig({ loginPath: 'https://...' })` (외부 origin) | `loginPath` 는 `/` 로 시작하는 동일 origin path-only |
| `AuthManager.updateConfig({ loginPath: '//evil.com/...' })` (protocol-relative) | `//` 시작 금지 (open redirect 방지) |
| 401 에러 페이지(`errors/401.json`)에서 직접 로그인 리다이렉트 구현 | 코어 `TemplateApp.showRouteError` 가드에 위임 (자동 처리) |

### 정적 확장자 라우트 / 자산 URL 생성

| 금지 | 올바른 사용 |
|------|------------|
| `Route::get('{id}/routes.json', ...)` (`.js`/`.css`/`.json`/`.map` 단일 등록) | `Route::dualSuffix('{id}/routes', 'json', ...)` — 확장자 형태 + 확장자 없는 형태 동시 등록 |
| `Route::get('bundle.js', ...)` (접미사가 종류를 구분해 제거 불가) | `Route::dualSuffixSegment('bundle', 'js', ...)` (`bundle.js` + `bundle/js`) |
| `Route::get('assets/{id}/{path}', ...)` (와일드카드 자산) | `Route::dualAsset('assets/{id}', ...)` (`.../{path}` + `?file=` 쿼리) |
| 서버에서 `'/api/templates/assets/'.$id.'/'.$path` 문자열 조립 | `App\Support\AssetUrl::templateAsset($id, $path)` |
| 프론트에서 `` `/api/templates/${id}/routes.json` `` 템플릿 리터럴 조립 | `resources/js/core/support/assetUrl.ts` 의 `suffixed()` / `templateAsset()` 등 |

정규식 location 은 프리픽스 location 보다 먼저 매칭되므로, 정적 최적화 블록(`location ~* \.(js|css|json)$`)이 있는 서버에서는 확장자 붙은 동적 응답이 `try_files ... /index.php` 폴백 기회 없이 404 가 된다. 서버측 `AssetUrl` 과 프론트측 `assetUrl.ts` 는 동일 규칙을 공유하므로 한쪽만 바꾸면 그 자산만 404 가 된다. 상세: [routing.md](docs/backend/routing.md) "정적 확장자로 끝나는 동적 엔드포인트", [api/README.md](docs/backend/api/README.md) "자산 URL 이중 모드".

### 라우트 캐시 안전성

`route:cache` 가 걸리면 `RouteServiceProvider::boot()` 이 캐시 로드로 분기해 라우트 파일 자체가 실행되지 않는다. 클로저는 직렬화 형태로 복원되므로 문제가 아니다 (`routes/web.php` SPA catch-all 이 증거). 깨지는 것은 오토로드되지 않는 심볼 참조뿐이다.

| 금지 | 올바른 사용 |
|------|------------|
| 라우트 파일에 전역 함수 선언 + 핸들러가 호출 | 로직을 클래스(`app/Support/…`)로 옮기고 핸들러는 위임만 |
| 파일 스코프 변수를 핸들러가 `use` 없이 참조 | 클래스 상수 또는 `use ($var)` 로 클로저에 캡처 |
| 벤더/프로바이더가 `boot()` 에서 조건부 등록하는 라우트에 의존 | 그 URI 를 G7 라우트 파일이 직접 소유 |

전역 함수 위반은 `Call to undefined function` 500 인데 예외의 `file` 이 `laravel-serializable-closure://` 라 원인 파일이 스택에 드러나지 않는다. 프로바이더 등록분이 사라지는 이유는 별개다 — `Router::setCompiledRoutes()` 가 `booted` 콜백에서 라우트 컬렉션을 통째로 교체하므로 그보다 앞선 등록은 조건 충족 여부와 무관하게 폐기된다(프레임워크 자신의 `BroadcastManager::routes()` 는 `routesAreCached()` 가드를 갖지만 모든 패키지가 그렇지는 않다). 정적 검사가 라우트 파일의 전역 함수 선언을 차단한다. 상세: [routing.md](docs/backend/routing.md) "캐시 안전한 라우트 작성".

### 목록 컨텍스트 왕복 (list context round-trip)

페이지네이션 목록 화면과 그에 딸린 상세·형제 상세·작성/수정 폼·확인 모달은 하나의 목록 클러스터다. 이 클러스터 안에서의 이동은 URL 목록 상태(`page`/`search`/`category`/`filters[*]`/정렬/`per_page`)를 손실 없이 보존해야 한다.

| 금지 | 올바른 사용 |
|------|------------|
| 클러스터 내 navigate 에 `mergeQuery` 누락 | `"params": { "path": "…", "mergeQuery": true, "query": {} }` |
| 이전글/다음글 등 형제 상세 이동만 규약에서 누락 | 목록 진입 / 목록 복귀 / 형제 이동 / 폼 취소 / 삭제 후 복귀 전 leg 동일 적용 |
| 현재 값을 그대로 다시 넘기는 키 열거 (`{"del": "{{query.del ?? ''}}"}`) | `mergeQuery` 가 이미 전부 나른다 — 열거는 중복이자 누락 위험 |
| 덮어쓸 키만 남기지 않고 필터 키 전부 재열거 | 값을 바꿔야 하는 키만 남긴다 (페이지 되돌림은 `{"page": ""}`) |
| 새로고침 버튼에 `mergeQuery: false` | 새로고침은 보던 목록을 다시 부르는 것 — 병합 유지 |
| `mergeQuery` 를 표현식으로 분기 (`"{{cond}}"`) | boolean 리터럴 고정 — 분기마다 보존 여부가 갈리면 한쪽이 조용히 상태를 떨군다 |
| `"path": "/board/{slug}/write?parent_id={{id}}"` (인라인 쿼리스트링) | 인라인 쿼리는 병합 시 버려진다 → `query` 객체로 옮긴다 |
| `mergeQuery: true` + `query` 키 생략 | 의도를 드러내도록 `"query": {}` 를 함께 둔다 |
| `"query": []` (배열 리터럴) | `"query": {}` — 동작은 같아 조용히 통과하지만, 나중에 덮어쓸 키를 넣으면 그 값이 버려진다 |
| 목적지가 표현식이라 판정 불가한 이동을 무표시로 둠 (`"{{_global.shopBase}}/products"`) | 클러스터 내 이동이면 `mergeQuery: true`, 밖으로 나가는 이동이면 예외 주석으로 의도를 명시 |
| 의도적 리셋(검색·필터 초기화 / 탭 전환 / 프리셋 적용)에 `mergeQuery: true` | 리셋은 병합하지 않는다 — 병합하면 초기화 버튼이 아무 일도 하지 않는다 |
| 탭 전환(`onTabChange`)이나 겹치지 않는 다른 목록으로의 이동에 `mergeQuery: true` | 목록 정체성이 다르면 승계하지 않는다 — 남의 검색어·페이지가 얹혀 빈 화면이 열린다 |
| 면제 주석은 "병합하지 않는다" 인데 코드는 `mergeQuery: true` | 주석과 코드를 일치시킨다 (주석은 사실이 아니라 선언일 뿐) |
| 검색 실행·페이지 이동 액션에서 `query` 키를 비움 | 값을 바꾸는 액션은 그 값을 직접 넘긴다 (`{"page": "{{$args[0]}}"}`) — 병합만으로는 새 값이 전달되지 않는다 |
| `path` 없이 `query` 만 바꾸는 액션에 `mergeQuery` 누락 (탭 전환 `{"tab": …}`, 항목 선택 `{"id": …, "mode": "view"}`) | `path` 생략은 "현재 주소에 작용" 이라 목록 화면 자신이 대상 — `mergeQuery: true` 없으면 지금 걸린 목록 상태가 통째로 날아간다 |

의도적 리셋(검색 초기화 / 필터 초기화 / 탭 전환 / 프리셋 적용 / 다른 목록으로의 이동)은 예외다. 그 경우 액션 노드 `comment` 에 `audit:allow layout-list-context-navigate-merge-query <사유>` 를 남겨 의도를 코드에 기록한다. 상세: [actions-handlers-navigation.md "목록 컨텍스트 왕복 규약"](docs/frontend/actions-handlers-navigation.md)

### 일괄 처리 목록의 선택 범위

체크박스 선택은 화면 밖(전역/로컬 상태)에 저장된다. 그래서 검색·필터·페이지 이동으로 행이 목록에서 빠져도 그 행의 선택은 남는다. 그 상태에서 일괄 처리를 누르면 사용자가 보고 있지도, 체크하지도 않은 행이 대상이 된다. 확인 모달은 건수만 말하므로 실행 전에 알아챌 방법이 없고, 처리는 정상 성공하므로 실행 후에도 오류가 남지 않는다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 선택을 화면 밖 상태에 저장하는 DataGrid 에 `selectionScope` 미선언 | `"selectionScope": "page"`(일괄 처리 목록) 또는 `"free"`(선택 자체가 저장 대상인 폼)를 **명시** |
| 일괄 처리 버튼이 달린 목록에 `"free"` | `"page"` — 대상은 언제나 "화면에 보이고 체크된 행" |
| 여러 페이지에 걸쳐 고르는 폼 선택기에 `"page"` | `"free"` — 페이지를 넘기면 앞 페이지 선택이 사라져 기능이 깨진다 |
| `selectable` 이 꺼진 화면이라 안전하다고 간주 | 체크박스가 없으면 남은 선택이 **더** 안 보인다 — 범위 판정은 `selectable` 과 무관 |
| `"selectionScope": "{{조건}}"` 표현식 분기 | 리터럴 고정 — 분기마다 보존 여부가 갈리면 한쪽이 조용히 대상 밖 행을 싣는다 |
| 화면마다 검색·필터·페이지 액션에 선택 초기화 액션을 복제 | 컴포넌트가 단일 지점에서 정리 — 액션 복제는 한 곳만 빠져도 같은 결함이 남는다 |

정적 검사가 `onSelectionChange` 가 배선된 DataGrid 를 전수 검사해 미선언을 차단한다. 상세: [component-props.md DataGrid](docs/frontend/component-props.md)

### 중첩 리소스 스코프 / 계층 무결성

| 금지 | 올바른 사용 |
|------|------------|
| 중첩 라우트의 상위 리소스 ID 를 받아만 두고 조회에 미반영 | Repository where 절에 상위 스코프 반영(SSoT) + Service 가 상위 ID 전달 → 교차 접근 시 404 |
| `$request->except(...)` / `->all()` 결과를 Service 쓰기 메서드로 전달 | `$request->validated()` 기준 (FormRequest 미정의 필드가 `$fillable` 로 새는 것 차단) |
| 요청 배열 항목의 `Rule::exists` 에 상위 스코프 미부착 | `Rule::exists(Model::class,'id')->where('order_id', $order->id)` → 422 |
| 수정/순서변경 FormRequest 의 `parent_id` 에 `Rule::exists` 만 부착 | 자손 전체를 검사하는 순환 방지 Rule 부착 (자기참조만 막는 Rule 은 `A→B→A` 통과) |
| 같은 리소스의 두 엔드포인트가 서로 다른 검증 강도 | 부모 변경 경로 전부 동일 강도 — 약한 쪽이 우회로가 된다 |
| 설정값이 정하는 한계를 Service 에서 리터럴로 재클램프 | Service 는 계산만, 상한 검증은 Rule 단일 책임 (이중 클램프 시 깊이 제한이 통째로 무력화) |
| 계층 재귀(path/depth 재계산)에 방문 ID 가드 없음 | 방문 집합으로 유한 종료 — 검증 우회 경로/오염 데이터에서도 무한 루프 금지 |

> 상세: [validation.md "계층 리소스 순환 참조" / "배열 항목의 상위 스코프"](docs/backend/validation.md), [service-repository.md "중첩 리소스 스코프" / "설정 기반 한계값"](docs/backend/service-repository.md)

#### 보안 게이트 대칭성 (KVE-2026-1914/1915/1919)

접근 게이트와 권한 등급 상한은 한 경로에만 있으면 다른 경로가 조용한 우회로가 된다. 게이트는 생산 지점(부모 비밀 판정 · 소유권 판정 · 등급 판정) 한 곳을 SSoT 로 두고, 같은 데이터를 내보내는 소비 경로 전부가 그 게이트를 경유해야 한다.

| 금지 | 올바른 사용 |
|------|------------|
| 비밀/비공개 부모(게시글)의 비밀 게이트를 하위 리소스(댓글·첨부·문의) 독립 엔드포인트에서 재적용하지 않음 | 부모 비밀 판정을 하위 전 경로(훅·서비스·첨부 서빙·댓글 목록)에 재적용 — PostResource 한 곳만으로는 부족하다 (KVE-2026-1914) |
| hash 기반 file-serving(preview/download)이 소유권·비밀·발행 상태 검사 없이 서빙 | preview 와 download 가 동일 게이트 공유 — 미발행·비소유·비밀 첨부는 404 (KVE-2026-1914 A-3/S-1/S-2) |
| User/Role 의 쓰기·상태변경·권한부여 경로가 삭제 경로보다 약한 등급 가드 | 전 경로에 동일 등급-상한(rank ceiling)을 대칭 적용 — 정적 라우트(bulk)는 스코프 미들웨어가 우회되므로 서비스 계층에서 강제한다 (KVE-2026-1919) |
| 저장측 레이아웃 표현식 검증(SafeLayoutExpressions)을 문자열 endpoint 필드에만 부착 | 표현식이 실릴 수 있는 배열 트리 전체(`content`)에 부착 — 문자열 한정 부착은 `is_array` 가드로 무력화되어 no-op 이 된다 (KVE-2026-1915) |
| 배열 트리 순회용 규칙(`NoExternalUrls`)이 문자열 필드에도 부착돼 `is_array` 로 조용히 통과 | 규칙이 문자열 스칼라도 처리하거나, 그 자리에서 떼어낸다 — 부착만 해두고 통과시키는 상태가 최악이다 |
| 같은 저장 대상의 FormRequest 마다 부착 규칙이 다름 (편집기 경로만 누락) | Store·Update·Content·ExtensionContent 4경로 동일 강도 — 편집기 저장 경로가 가장 약하면 그 경로가 우회로다 |
| same-origin 을 `//` 접두·scheme·`/` 시작 **문자열 검사**로만 판정 | 브라우저 URL 파서와 동일 정규화(tab·LF·CR 제거 → 백슬래시를 슬래시로 → 선행 슬래시 런 접기) 후 판정 — `/\/evil.com/x.js` 는 문자열상 path 지만 브라우저는 외부 origin 으로 해석한다. 런타임·저장측·정적검사 3층이 같은 정규화를 공유한다 (KVE-2026-1915 B-2) |
| same-origin 판정만 정규화하고 **신뢰 호스트 추출(`hostOf`)은 원문**으로 판정 | 두 판정이 같은 `if` 안에서 이어지므로 정규화도 공유 — 어긋나면 `https://evil.com\@cdn.신뢰.com/x.js` 가 저장측에서만 신뢰 호스트로 보여 통과한다 |
| 정적 일괄 라우트(`bulk-*`)에 등급 상한만 적용하고 **스코프 축은 비움** | 라우트 모델이 없으면 미들웨어 스코프 검사가 스킵되므로 서비스가 상세 경로와 **같은 스코프 판정**(`PermissionHelper::filterByScope`)을 재적용 — 등급 축만 막으면 스코프 축이 우회로다 (KVE-2026-1919) |
| 권한 상한(ceiling) 검사를 DB 쓰기 **뒤**에 배치 | 가드 → 쓰기 순서 — 쓰기 뒤에 검사하면 거부된 요청이 고아 행·반영된 속성 변경을 남긴다. 회귀 테스트는 403 뿐 아니라 **상태 불변**까지 단언한다 |
| 같은 리소스를 쓰는 public 서비스 메서드 중 일부만 보호 가드 보유 | 형제 public 메서드 전부 동일 가드 — 서비스는 확장에 열려 있으므로 "현재 호출부가 없다" 는 방어가 아니다 |
| 라우트 파라미터가 Model 로 resolve 되지 않는 쓰기 경로를 미들웨어 스코프 검사에 맡김 | 서비스 계층에서 재적용 — 스킵 조건은 정적 경로(`bulk-*`·`reorder`)뿐 아니라 **파라미터명 불일치**(`{id}` + `int` 타입힌트)도 있고, 후자는 상세 경로까지 무가드다 |
| 순서 변경·일괄 작업의 스코프 거부를 "대상 일부 제외" 로 처리 | 순서·트리처럼 집합 전체가 하나의 값인 작업은 **전량 거부** — 일부만 반영하면 나머지와 어긋난 상태가 저장된다 |
| 가시성 판정을 호출부가 넘기는 옵트인 플래그(`$filters['is_public'] ?? false`)에 의존 | 열람자 신원 기반 fail-closed — 옵트인은 호출부가 빠뜨리면 조용히 열린다(읽기만 하고 쓰는 곳이 없는 사문 플래그가 실재했다) |
| 부모 상태로 판정하는 게이트를 `$x->parent && …` 로 작성 | 부모를 못 읽으면 차단 — 부모가 soft-delete 되면 조건이 성립하지 않아 통과한다 |
| 리소스 `abilityMap can_*` 을 연관/타 리소스 권한으로 게이팅 | 그 엔드포인트의 라우트 권한(SSoT)과 **같은 리소스 prefix** — 상승 방지는 게이트 이중화가 아니라 rank ceiling 이 담당한다 |

이 결함군은 예외도 오류도 남기지 않는다 — 약한 경로가 정상 응답을 내보내는 것이 유일한 증상이다. secret 게이트 재적용·hash 서빙 게이트·rank 대칭·URL 판정 3층 동형·정적 bulk 스코프 재적용·가드 선행·형제 메서드 가드 패리티·abilityMap prefix 정합은 의미 판정 영역이라 정적 검사가 일부만 덮으므로, 부모 변경·하위 서빙·등급 경로·URL 검증 지점을 건드릴 때 코드 리뷰에서 대칭성을 확인한다.

> 상세: [validation.md](docs/backend/validation.md), [service-repository.md](docs/backend/service-repository.md), [frontend/security.md](docs/frontend/security.md)

### 제3자 라이브러리는 쓰기 경로를 지정받는다

제3자 라이브러리는 캐시·임시파일 경로를 설정하지 않으면 **자기 설치 폴더**(vendor 안)나 시스템 temp 에 쓴다. 표준 Laravel 배포는 웹서버에 `storage/` 와 `bootstrap/cache` 만 쓰기 권한을 주므로 그 쓰기는 실패하는데, 실패가 예외가 아니라 PHP 경고라 Laravel `HandleExceptions` 가 `ErrorException` 으로 승격시켜 요청이 500 이 된다. 해시당 1회만 기록하는 라이브러리라면 캐시가 영영 생기지 않아 **매 요청이 같은 실패를 반복**한다 — 개발 머신에서는 vendor 가 쓰기 가능해 한 번 성공하고 끝나므로 재현되지 않는다 (공개 #125).

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 제3자 라이브러리를 기본 설정 그대로 인스턴스화 | 캐시·임시파일 경로를 `ExtensionStoragePath::module($id, 'cache/…')` 로 명시 — 기본값은 **라이브러리 자기 설치 폴더**다 |
| 쓰기 경로만 지정하고 디렉토리 생성은 라이브러리에 맡김 | `FilePermissionHelper::ensureWritableDirectory()` 로 **먼저 확보한다** — 라이브러리는 대개 하위 디렉토리만 만들고, base 가 없으면 경고만 내고 끝난다 |
| 확보 절차(억제 생성·chmod·setgid·소유권·쓰기 판정)를 호출부가 자기 안에 복사 | 코어 프리미티브 한 곳에서 수행 — 사본은 서로 다른 하드닝을 갖고 갈라진다(실제로 억제 mkdir·setgid·`clearstatcache` 가 사본마다 한쪽씩 빠져 있었다) |
| 확장 저장 경로를 `storage_path('app/modules/…')` 로 직접 조립 | `ExtensionStoragePath::{module,plugin}()` — 디스크 root 가 단일 출처이고 테스트 환경을 인지하므로, 확장이 `runningUnitTests()` 분기를 복사하지 않는다. 복사본은 한 곳만 빠뜨려도 그 확장의 테스트가 **운영 설정 파일을 덮어쓴다** |
| 캐시 쓰기 실패를 그대로 500 으로 흘림 | 캐시는 성능 장치다 — 확보 실패 시 캐시만 끄고 본래 기능은 계속한다. **정화·검증 자체를 건너뛰는 폴백은 금지** |
| 폴백 통지를 `Log::warning` 으로 남김 | `Log::error` — 출하 기본 로그 수준(`config/settings/defaults.json` 의 `log_level`)이 `error` 라 `warning` 은 기본 설치 상태에서 파일에 기록되지 않는다. 기능은 성공하므로 그 통지가 유일한 흔적이다 |

확보 프리미티브는 **예외도 PHP 경고도 내지 않는다** — `File::ensureDirectoryExists()` 는 `mkdir()` 을 억제 없이 부르므로 생성 실패가 `E_WARNING` → `ErrorException` 으로 승격되어, 막으려던 500 이 다른 줄에서 그대로 난다. 실패는 `bool` 과 사유(`occupied_by_file` / `ancestor_not_writable` / `create_failed` / `not_writable`)로 올라오고, 그 사유를 통지에 실어 운영자가 고칠 대상을 지목한다.

경로는 `ExtensionStoragePath` 가 해석한다. `getBasePath('cache')` 는 `Storage::disk()->path()` 위임이라 비로컬 디스크(S3 등)에서 파일시스템 경로가 아니게 되는데, 그러면 라이브러리가 상대경로를 CWD 기준으로 해석해 **조용히 엉뚱한 곳에 쓴다** — 지금 결함보다 나쁘다. 대부분의 정의 캐시는 `file_put_contents` 로 쓰는 로컬 전용 장치다.

> 상세: [storage-driver.md](docs/extension/storage-driver.md) "제3자 라이브러리에 절대 경로를 넘길 때", [service-repository.md](docs/backend/service-repository.md) "서비스가 제3자 라이브러리를 붙일 때"

### 확장·템플릿 구동 에셋은 자체 제공한다

브라우저가 화면을 그리기 위해 제3자 CDN 에 도달해야 하면, 그 도달 실패는 **예외도 로그도 남기지 않고 화면 기능만 조용히 사라진다.** 폐쇄망·방화벽·광고차단기에서 재현되며 자체 서버 로그에 흔적이 없어 운영자가 원인을 특정할 수 없다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 구동 자산(js/css/웹폰트)을 외부 CDN 에서 실시간 로드 | 확장이 `dist/vendor/{lib}/{version}/` 에 동봉하고 same-origin 서빙 |
| `trusted_script_hosts` 만 선언하고 사유는 생략 | `trusted_script_hosts_reason` 에 호스트별 사유 동반 — 자체 제공이 원칙이고 예외는 근거가 코드에 남는다 |
| 자산 URL 을 문자열로 조립 (`'/api/plugins/assets/'+id+'/…'`) | `G7Core.asset.{template,module,plugin}` — 확장자를 정적 location 이 가로채는 서버에서 조립 URL 만 404 가 된다 |
| AMD 로더·워커에 `G7Core.asset.template()` 결과를 base 로 전달 | `G7Core.asset.templateDir()` — 쿼리 형태(`?file=`)는 뒤에 파일명을 이어 붙일 수 없다. 확장자 없는 모드에서 404 일 수 있으므로 **소비자가 폴백을 갖춘다** |
| CSS 로드에 `onerror` 미설치 또는 `resolve()` 로 삼킴 | `loadStylesheetWithRetry` — 아이콘만으로 조작하는 버튼이 있는 화면에서 스타일 소실은 곧 조작 불능이다 |
| 자산 실패를 `console.error` 한 줄로 끝냄 | `G7Core.assets.notifyFailure({id,label,retry})` — 사용자가 사실을 알고 조치할 수 있어야 한다 |
| 편집기·코드편집기 확보 실패 시 빈 컨테이너를 남김 | 평문 입력(textarea) 폴백 + 저장 계약 유지(`{name}_mode='text'`) + 재시도 시 입력 내용 승계 |
| 확장 `dist/`·`src/` 에 운영자 CSS 를 둠 | 확장 디렉토리 안의 **`custom/`** — 빌드 불필요, 확장 교체가 보존 |
| 번들 확장이 `custom/` 을 담아 배포 | `dist/vendor/` 에 담는다. `custom/` 은 운영자 소유라 보존 계층이 덮어쓰지 않아 **저작자 파일이 영영 반영되지 않는다** |
| 사용자 추가 에셋 URL 을 `ext.cache_version` 으로 무효화 | 파일 서명(수정 시각) — 확장 캐시 버전은 운영자가 파일을 고쳤다고 오르지 않는다 |
| `custom/` 보존을 rename 경로에만 적용 | 교체 **두 경로 모두**(rename · 제자리 동기화 폴백) — 한쪽만 고치면 Windows 잠금 상황에서만 조용히 사라진다 |

동봉 자산은 배포 산출물이므로 `sourceMappingURL` 참조를 남기지 않는다(`.map` 은 gitignore 대상이라 404 가 된다). 인라인 여부는 "없으면 조작 불능인가" 로 가른다 — 아이콘 폰트는 인라인, 글꼴·장식 아이콘은 파일 분리(자산 URL 이 쿼리 형태가 되는 서버에서 CSS 내부 상대 `url()` 이 해석되지 않는 조합이 남는다).

사용자 추가 에셋(`custom/`)은 **출처에 의존하지 않는 서술자**로 해석하고 `core.assets.custom_assets` 필터 훅을 해석기 끝에 둔다. 소비자(뷰 컴포저·프론트 로더·서빙)가 출처를 보면, 나중에 다른 출처(템플릿 환경설정의 화면 입력 등)가 붙을 때 평행 경로가 생기고 "운영자 CSS 가 어디서 오는가" 의 SSoT 가 둘로 갈린다.

> 상세: [module-assets.md](docs/extension/module-assets.md) "사용자 추가 에셋", [static-asset-publishing.md](docs/backend/static-asset-publishing.md)
> 정적 검사가 외부 자산 URL 과 번들 확장의 `custom/` 배포를 차단한다. 서술자 형태와 교체 2경로 보존은 테스트가 잠근다.

### 목록 응답의 하위 컬렉션

목록은 화면이 그 행에서 **실제로 그리는 것**만 싣는다. 행마다 하위 컬렉션을 통째로 직렬화하면 한 페이지를 여는 것만으로 수백~수천 행이 응답에 실린다 (공개 #76 — 상품 100건 × 옵션 20건).

| 금지 | 올바른 사용 |
|------|------------|
| `relationLoaded('x') ? $this->x : $this->whenLoaded('y')` (가짜 가드) | `whenLoaded('x', fn () => ...)` 하나만 — 로드 여부는 **Repository 가** 결정한다 |
| Resource 는 `whenLoaded` 로 방어하는데 Repository 가 목록에서 무조건 eager load | 목록의 `relations:` 는 목록이 **실제로 직렬화하는** 관계만 |
| 개수/합계를 PHP 컬렉션 연산으로 (`$this->options->where(...)->sum(...)`) | `withCount:` / `withSum`(`outerUsing:`) DB 집계 |
| `toListArray()` 를 정의해 두고 컬렉션이 `toArray()` 를 호출 | 컨트롤러/컬렉션이 목록 표현을 **명시 호출** |
| 목록 Resource 안에서 관계 재쿼리 (`$this->images()->first()`) | `relationLoaded` 분기로 로드된 컬렉션에서 고른다 |
| 집계 별칭 존재 여부를 `!== null` 로 판정 | `array_key_exists($alias, $model->getAttributes())` — SUM 은 0건에서 NULL 이라 값 검사로는 "집계 안 함" 과 구분되지 않는다 |
| 목록에서 뺀 값을 대체 경로 없이 제거 | 지연 로드 경로(배치 조회)를 먼저 만들고, 하위호환은 opt-in 파라미터(`?with_options=1`)로 |

착수 전 **소비처를 실측**한다. 화면이 그 값을 실제로 순회·렌더하면 제거는 기능 축소다 — 계획서에 "안 쓴다" 고 적혀 있어도 레이아웃 JSON 을 열어 확인한다.

> 상세: [api-resources.md](docs/backend/api-resources.md), [service-repository.md](docs/backend/service-repository.md)

### 저장값 + 확장 카탈로그 병합 설정의 공개 응답

설정 항목이 "운영자 저장값 + 확장이 훅으로 등록한 카탈로그" 의 병합으로 만들어지면, 저장값은 남아 있는데 카탈로그에서 항목이 사라지는 상태가 생긴다 — 그 확장을 삭제·비활성화했거나, 확장이 자기 기능 토글을 껐을 때다. 병합부는 이를 고아 항목으로 표시하지만 저장값의 `is_active` 는 참 그대로 남는다.

| 금지 | 올바른 사용 |
|------|------------|
| 공개 응답이 저장값 플래그(`is_active`)만 보고 항목을 내보냄 | 카탈로그 소속(`_orphaned`)을 함께 판정 — 공급 확장이 더 이상 제공하지 않는 항목은 공개 응답에서 제거 |
| 고아 항목을 관리자 응답에서도 제거 | 관리자 응답은 유지 — 운영자가 확인하고 지워야 할 대상이다 |
| 소비 화면(레이아웃 JSON)마다 필터를 넣어 차단 | 공개 API 단일 지점에서 차단 — 화면마다 복제하면 템플릿 하나만 빠져도 같은 결함이 남는다 |
| 같은 데이터를 내보내는 공개 엔드포인트가 서로 다른 게터를 사용 | 전 엔드포인트가 같은 공개 게터를 경유 — 한쪽이 raw `getSettings()` 를 쓰면 그 경로만 조용히 뚫린다 |
| 항목 제거 후 배열 인덱스를 그대로 둠 | `array_values()` 로 재정렬 — 비연속 키는 JSON 객체로 직렬화되어 화면 반복이 깨진다 |

이 결함은 예외도 경고도 로그도 남기지 않는다. 이미 제공 불가한 항목이 사용자 화면에서 선택 가능한 상태로 남아 있는 것이 유일한 증상이고, 관리자 화면은 고아 표시로 정상 차단하고 있어 양쪽을 나란히 보지 않으면 드러나지 않는다.

> 상세: [module-settings.md](docs/extension/module-settings.md) "카탈로그 병합 설정의 공개 응답"

### 목록 조회 컬럼 프루닝과 지연 조인

| 금지 | 올바른 사용 |
|------|------------|
| `->paginate($perPage)` / `->paginate($perPage, ['*'])` (컬럼 목록 미지정) | 목록이 실제로 쓰는 컬럼만 명시. 깊은 OFFSET 이 가능한 목록은 `PaginatesWithDeferredJoin` |
| 요청 값에서 온 정렬 컬럼을 그대로 `orderBy` 에 전달 | `ResolvesSortSpec` 으로 닫힌 집합 해석 (방향만 검사하는 `in_array` 는 보호가 아니다) |
| 지연 조인의 `$query` 에 미리 `orderBy`/`with`/`select` 적용 | 필터/where 만 적용해 넘기고, 정렬·관계·컬럼은 trait 인자로 전달 |
| 쿼리에 `with()` 만 하고 `relations:` 인자 생략 | 관계는 `relations:` 로 전달 — trait 이 inner 뿐 아니라 **outer 에서도** eager load 를 지우므로 관계가 조용히 사라진다 (예외·쿼리 오류 없이 응답에서 필드만 없어져 관계를 단언하지 않는 테스트는 전부 통과) |
| 목록 SELECT 에 `SUBSTRING(content, 1, N)` 을 두고 프루닝했다고 간주 | 오버플로 페이지 읽기가 그대로 발생 — 잘라내기는 outer(`$columns`)에서만 |
| 그룹 쿼리(`groupBy`)의 총 건수를 `count()` 로 계산 | `getCountForPagination()` (서브쿼리로 감싸 그룹 수를 센다) |
| raw SQL 안에 테이블명·별칭을 문자열로 조립 | 테이블명은 `(new Model)->getTable()`, 프리픽스는 `DB::getTablePrefix()`, 별칭은 빌더(`join($table.' as uc', …)`)가 만들게 |
| `whereRaw('1 = 0')` / `where($c, DB::raw("({$sub->toSql()})"))` + `mergeBindings` | `whereIn($key, [])` / `where($c, '=', $sub)` (빌더가 바인딩까지 처리) |
| 화면 정렬 셀렉트에 게이트가 모르는 컬럼을 넣기 | `화면 옵션 ⊆ FormRequest 게이트 ⊆ Repository 화이트리스트` — 어긋나면 422 후 직전 목록이 남아 정렬된 것처럼 보인다 |
| 분류값 필터의 허용 어휘를 화면·게이트·기록 지점에 각각 리터럴로 적기 | 어휘는 Enum 단일 출처에서 파생 — `화면 필터 옵션 = 라벨 키 = 실제 기록 어휘`. 부분집합이 되면 빠진 값으로 기록된 행이 어떤 필터 조합으로도 도달 불가하고, 라벨 키가 없는 값은 목록 셀에 원시 키 문자열로 노출된다 |
| 목록 응답이 `last_page > 1` 인데 화면에 페이저·총건수가 없음 | 페이지 이동 컨트롤과 총건수를 함께 노출 — 없으면 1페이지 밖 행이 조용히 잘리고, 잘렸다는 사실조차 화면에 나타나지 않는다 |
| 쿼리 파라미터 불리언에 `boolean` 규칙만 부착 | `prepareForValidation()` 으로 `"true"`/`"false"` 정규화 (쿼리는 문자열로 도착 — 화면이 그 형태로 보내면 목록 전체가 422). 단, 해석 불가한 값은 건드리지 말 것 |
| 같은 화면의 개수 배지와 그 배지가 여는 목록이 서로 다른 엔드포인트 | 배지 계산과 목록 조회는 같은 스코프의 같은 데이터소스에서 |
| 관계 테이블 컬럼 정렬을 `join`+`groupBy` 로 구현 | `SortsByRelatedColumn` 의 상관 서브쿼리 (1:N 조인은 원 행을 부풀려 총 건수·페이지 경계를 깨고, INNER 는 자식 없는 행을 지운다) |
| 관계 정렬을 넣고 인덱스는 그대로 | `(외래키, 정렬컬럼)` 복합 인덱스 마이그레이션 동반 (서브쿼리가 행마다 실행된다) |
| 페이지네이션 정렬을 비고유 컬럼(`created_at` 등)으로만 끝내기 | 정렬 마지막에 기본키를 덧붙여 전순서 보장 (동률 구간에서 인접 페이지가 같은 행을 중복 노출하고 다른 행을 누락한다) |

> 상세: [service-repository.md "목록 조회 컬럼 프루닝과 지연 조인" / "정렬 컬럼 화이트리스트" / "화면 정렬 옵션은 게이트의 부분집합이어야 한다" / "관계 테이블 컬럼 기준 정렬" / "허용되는 Raw 쿼리"](docs/backend/service-repository.md)

### 대용량 목록의 총 건수와 페이지 이동

총 건수 상한과 페이지 이동 범위는 **별개 결정**이다. 묶으면 필요 없이 기능이 깎인다. 총 건수만 상한을 받고, "다음" 이동은 `per_page + 1` 실측으로 끝까지 열어 둔다. 계산이 불가능해지는 것은 마지막 페이지 번호 하나뿐이다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 같은 술어를 `count()` 한 번, `get()` 한 번 실행 | `BoundedPaginator::paginate()` 한 번 (총 건수 + 페이지를 한 번에) |
| `paginate(PHP_INT_MAX)` 후 PHP `array_slice` | 실제 `page`/`per_page` 를 저장소까지 하달 |
| `forPage($page, $perPage + 1)` | offset 은 `per_page` 기준으로 따로 계산 (안 그러면 페이지가 깊어질수록 경계가 밀린다) |
| Scout `->keys()->all()` + 무제한 `whereIn` | 키워드 술어를 페이지 쿼리에 직접 밀어넣기 (`DatabaseFulltextEngine::whereFulltext`) |
| FULLTEXT 원문 키워드를 raw 로 바인딩 | 코어 sanitizer 경유 — `+` `-` `*` `"` 입력이 파싱 오류로 500 이 된다 |
| `whereDate` / `whereYear`+`whereMonth` | `FiltersByDateRange` 의 범위 조건 (컬럼에 함수를 씌우면 인덱스를 못 쓴다) |
| 총 건수를 모르는데 `last_page` 를 1 로 채움 | `null` 로 내보내 화면이 마지막 페이지 점프만 감추게 한다 |
| 상한값을 저장소·화면에 리터럴로 재기입 | `PaginationLimits` 단일 해석 + 확장은 `core.pagination.filter_*` 필터 훅으로만 조정 |
| 결과 크기가 데이터 증가에 비례하는데 상한 없는 `->get()` / `->pluck()` | 목록은 페이지네이션, 순회는 `chunkById`/`lazyById`, 몇 건이면 `limit` — 운영자 등록 수에 묶인 설정성 테이블만 예외이며 그 근거를 코드에 남긴다 |
| 배지·요약 건수를 `int` 하나로 돌려주기 | `BoundedPaginator::count()` 의 `BoundedCount` — 잘린 값과 정확한 값이 구분되지 않으면 잘린 10,000 이 "정확히 10,000 건" 으로 화면에 나간다 |
| 여러 카테고리 건수를 합치며 정확도는 버리기 | 하나라도 부정확하면 합계도 부정확. 단, 특정 탭만 볼 때는 그 카테고리의 정확도만 본다 |
| 정렬 마지막이 비고유 컬럼 | 기본키를 덧붙여 전순서 보장 (동률 구간에서 행이 겹치거나 샌다) |
| 관련도순(`_ft_score`)에 커서 적용 | 계산값은 WHERE 절 경계로 쓸 수 없다 — offset 유지 (`KeysetPaginator::supports` 가 판정) |

> 상세: [pagination.md](docs/backend/pagination.md)

### 검색 인덱스 재생성(리인덱싱)

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 재생성을 자동 트리거(마이그레이션 종료·확장 업데이트 완료 등)에 연결 | 운영자가 **명시적으로 선택**했을 때만 수행. 인덱스 잠금·전체 재색인 비용이 운영 중 사이트를 멈춘다 |
| 재생성 체크 상태를 전역에 남겨 다음 모달 진입에 이월 | 모달 진입 시드와 제출 후 초기화 **양쪽**에서 해제. 이월되면 운영자가 아무것도 누르지 않았는데 재생성이 수행되고, 서버 옵인 가드는 정상이라 HTTP 테스트로는 드러나지 않는다 |
| 모듈·플러그인 모달이 같은 전역 키 공유 | 면마다 별도 키 (한쪽 체크가 다른 쪽으로 전이 금지) |
| 응답 헬퍼가 `JsonResource::resolve()` 만 호출해 `additional()` 유실 | 부가 데이터를 응답 최상위에 병합 — 색인 누락은 오류 없이 "검색 0건" 으로만 나타나므로 응답 페이로드가 유일한 통로다 |
| 재생성 수행을 곧 복구로 간주 | `remaining` 은 **재생성 후 재점검** 결과 — "재생성했다" 와 "복구됐다" 를 구분해 보고 |
| 점검 커맨드의 비-0 종료를 "실행 실패" 로 표시 | 이상 발견 신호다. 종료 코드와 출력을 그대로 노출 |
| 특정 엔진(FULLTEXT) 전용으로 점검·재생성 구현 | `SearchIndexMaintainer` 계약 + `core.search.index_maintainers` 훅 |
| "점검 대상 0" 과 "점검 불가" 를 같은 문구로 보고 | 구분 보고 — 뭉뚱그리면 "인덱스가 다 정상" 으로 읽힌다 |

> 상세: [search-system.md](docs/backend/search-system.md)

### 검색 질의는 활성 엔진이 만든다

검색 엔진은 `core.search.engine_drivers` 훅으로 교체 가능하다. 그런데 그 교체가 실제로 먹는 것은 **활성 엔진을 거치는 경로뿐**이다. 저장소가 구체 엔진 클래스를 지목하면 등록된 엔진은 호출될 기회 자체를 잃고, 오류도 경고도 없이 그 사이트의 검색만 조용히 다른 방식으로 동작한다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| `DatabaseFulltextEngine::whereFulltext(...)` 등 구체 엔진 정적 호출 | `KeywordSearch::apply()` / `::applyAny()` (해석기가 활성 엔진에 위임) |
| `DB::getDriverName() === 'pgsql'` 처럼 드라이버명을 코드에 비교 | 선언형 `config('core.search.*')` + `core.search.like_operators` 필터 훅 |
| 매칭 ID 전량을 PHP 로 적재 후 `whereIn` (`search()->keys()->all()`) | 술어를 페이지 쿼리에 직접 부착 — ID 왕복도 목록 폭발도 없다 |
| 엔진에게 페이지 번호를 넘겨 한 페이지만 받기 | 페이지네이션은 DB 담당. 엔진은 **키 집합 상한**만 책임진다 (`KeywordSearchContext`) |
| 키 집합 상한을 총 건수 상한과 다른 값으로 두기 | 둘 다 `PaginationLimits::resultCap()` — 갈라지면 엔진이 돌려준 건수와 화면 총 건수의 근거가 달라진다 |
| 부분일치 폴백을 "전문검색 없을 때의 임시방편" 으로 취급 | 전문검색 미제공 DBMS 에서는 **그것이 정상 경로** — 와일드카드 escape + 대소문자 규칙을 갖춘다 |
| 확장이 `Model::search()` 를 쓰는 것을 금지로 오해 | Scout 경로는 그대로 유효하다. 새 계약은 대체가 아니라 **추가 통로** |

정적 검사는 이 저장소 안만 볼 수 있다 — 외부 엔진이 상한을 지키는지는 강제할 수 없으므로, 코어는 **값을 손에 쥐어 주는 것**까지 하고 그 값이 도달하는지를 계약 테스트가 고정한다.

> 상세: [search-system.md "키워드 술어는 활성 엔진이 만든다"](docs/backend/search-system.md)

### 통화 단위는 설정이 정한다

G7 은 **기본 통화**(상품·쿠폰·배송비 저장 기준), **표시 통화**(구매자가 고른 통화), **결제 통화**(PG 청구 통화)를 각각 따로 설정한다. 셋은 같을 수도, 모두 다를 수도 있다. 금액을 다루는 지점이 특정 통화를 전제하면 값은 맞고 **단위만 틀린** 금액이 나가며, 예외도 경고도 없다.

| 금지 | 올바른 사용 |
|--------|---------------|
| 다국어 문구에 `:amount원` / `:amount円` | 문구는 `:amount` 로 중립, 호출부가 `ecommerce_format_price($amount, $currency)` 로 포맷해 전달 |
| 레이아웃에서 `{{금액.toLocaleString()}}원` 조립 | 서버가 준 `*_formatted` / `multi_currency_*[통화].formatted` 를 그대로 출력 |
| `formatCurrencyPrice($price, 'KRW')` (통화 코드 리터럴) | `formatBaseCurrency()` / `formatOrderCurrency()` (설정·주문 스냅샷이 통화를 정한다) |
| `number_format($amount).'원'` | 같은 도메인의 통화 인지 헬퍼(`formatOrderChargeAmount()` 등) 경유 |
| `_global.preferredCurrency ?? 'KRW'` | `_global.preferredCurrency ?? _global.defaultCurrency` (둘 다 없으면 `*_formatted` 로 내려간다) |
| 통화표를 코드에 고정(기호·자릿수 5종 표 + 특정 통화 폴백) | 설정의 `symbol` / `decimal_places` 를 읽고, 미설정 시에만 폴백표 |
| `code === 'KRW' ? 0자리 : 2자리` 식 코드 분기 | `decimal_places` 로 판정 (운영자가 추가한 0자리 통화도 포함) |
| 통화 선택 입력의 기본값을 `"KRW"` 로 시드 | 설정의 `default_currency` — 마일리지처럼 **통화별 원장**을 쓰는 도메인은 표시가 아니라 **기록이 틀어진다** |

언어는 통화가 아니다. 한국어 문구에 `원`, 일본어에 `円` 을 박으면 기본 통화가 다른 상점에서 UI 언어가 통화를 결정하게 된다 — 영어 문구가 `:amount` 로 중립인 것이 정답이다.

주문·결제·환불 금액은 **거래 시점 통화로 동결**한다(`currency_snapshot.base_currency`). 운영자가 이후 기본 통화를 바꿔도 과거 주문의 표기는 불변이어야 한다.

동결 대상은 환율만이 아니다 — **소수 자릿수·절사 규칙·환산 분모(base_unit)까지 스냅샷이 SSoT** 다. 이 값들을 현재 설정에서 조회하면, 운영자가 그 통화를 삭제하는 순간 설정에서 사라져 폴백(자릿수 2)이 적용된다. 소수 0자리 통화의 과거 주문 표기가 `¥14,835` → `¥14,835.00` 으로 바뀌고, 3자리 이상으로 설정했던 통화는 표시 금액이 절사된다. 금액 계산은 스냅샷을 쓰는데 표기만 현재 설정을 따라가면 같은 화면 안에서 근거가 갈린다.

| ❌ 금지 | ✅ 올바른 사용 |
| --- | --- |
| 주문·환불 표시에서 `getDecimalPlaces($code)` 를 스냅샷 없이 호출 | `getDecimalPlaces($code, $currencySnapshot)` — 스냅샷이 있으면 그것이 우선 |
| 리소스가 주문 스냅샷을 자식에게 전파하지 않음 | `withOrderCurrency()` 를 전파하는 지점마다 `withCurrencySnapshot()` 도 함께 전파 |
| 상품·카탈로그 표시까지 스냅샷으로 고정 | 현재 판매가는 **현재 설정**이 정답 — 스냅샷 없이 호출한다 |
| 자릿수를 박제하지 않은 구형 스냅샷에서 예외/0 자리 강제 | 박제값이 없으면 현재 설정 폴백을 그대로 탄다 (하위호환) |

> 상세: [api-resources.md](docs/backend/api-resources.md), [service-repository.md](docs/backend/service-repository.md)

### 확장 결제수단은 자기 능력을 선언한다

코어 `PaymentMethodEnum` 은 확장 결제수단 ID(`kginicis_naverpay`, `toss_tosspay` 등)를 모른다. 그래서 능력(PG 필요 여부 / PG 고정 / 환불수단)은 **등록하는 확장이 카탈로그에 선언**하고, 관리자 화면과 서버는 그 선언만 읽는다. 미선언 시 안전 기본값(`needs_pg=true`, `pg_locked=false`, `pg_provider=null`)으로 떨어지는데, 그 조합은 "PG 가 필요한데 어느 PG 인지 모른다" 를 뜻해 화면과 실제 결제 경로가 어긋난다.

| 금지 | 올바른 사용 |
|--------|---------------|
| entry `defaults` 에 `pg_provider` 만 두고 능력 키 생략 | `needs_pg` 명시 선언 (PG 결제창을 거치는가) |
| 자기 PG 전용 수단인데 `pg_provider: null` | `pg_provider: '{자기 provider id}'` + `pg_locked: true` (PG 제공자 등록 리스너의 id 와 동일해야 배지가 이름을 찾는다) |
| 표시를 고치려고 코어 레이아웃 표현식을 정규식 치환 | 카탈로그 선언만 바꾼다 — 레이아웃이 `pg_locked`/`needs_pg` 로 직접 3분기한다 |
| 선언을 바꾸고 기설치본은 그대로 | 저장된 `order_settings.json` 을 정정하는 업그레이드 스텝 동반 (자기 접두사만, 멱등) |

레이아웃 치환 방식은 코어가 그 리터럴을 버리는 순간 조용히 사문화된다 — 합성 입력으로만 검증한 테스트는 계속 통과하므로 사문화가 드러나지 않는다. 정적 검사가 능력 선언 누락을 차단한다.

### 예외를 응답으로 바꾸는 자리

`catch (\Exception)` / `catch (\Throwable)` 는 **도메인 예외가 아닌 것**을 잡는 자리다. 여기서 4xx 를 돌려주면 인프라 장애·코드 결함이 "입력 오류" 로 위장되어 사용자는 고칠 수 없는 안내를 보고 운영자는 장애를 늦게 안다. 그리고 이미 번역된 `$e->getMessage()` 를 응답의 메시지 **키** 자리에 넘기면 키 해석에 실패해 원문(SQL 상태코드·경로 포함 가능)이 그대로 화면에 나간다. 둘 다 예외도 로그도 남기지 않는다.

| 금지 | 올바른 사용 |
|--------|---------------|
| generic catch 가 4xx 반환 | 5xx — 의도적 4xx 는 사유와 함께 판정 테스트의 상수에 선언 |
| generic catch 에서 상태코드 인자 생략 (`moduleError($mod,'key')`) | 상태코드 명시 — 생략 시 `ResponseHelper` 기본값 **400** 이 조용히 적용된다 |
| 도메인 예외를 typed 로 승격한 뒤에도 `catch (\RuntimeException)` 유지 | 승격한 예외로 좁힌다 — 도메인 예외의 **부모**를 잡으면 남는 것은 인프라 예외뿐인데 그것까지 4xx 가 된다 |
| `error($e->getMessage(), 422)` | `error($e->getMessageKey(), 422, null, $e->getMessageParams())` |
| 도메인 예외가 번역문만 들고 다님 | 생성자에 키+치환 파라미터 보관 → `getMessageKey()` / `getMessageParams()` |
| 서비스를 typed 예외로 승격하고 컨트롤러는 generic 만 유지 | 그 서비스 메서드를 호출하는 **컨트롤러 메서드 전수**에 typed catch 추가 (없으면 도메인 사유가 500 이 된다) |
| typed 예외 도입하면서 그 분기의 상태코드도 변경 | typed 는 **기존 상태코드 유지** — 예외 도입이 사용자 계약을 함께 바꾸면 회귀다 |
| 공개(비인증) 엔드포인트 응답에 예외 원문 포함 | 원문은 `Log::error` 로만 — 관리자 전용 면의 `errors` 페이로드는 진단 정보로 허용된다 |
| 원문을 직접 문자열로 조립해 노출 폭을 호출부가 정함 | 노출 폭은 `ResponseHelper` 가 정한다 — Throwable 을 넘기면 `app.debug` 에서만 펼쳐진다 |
| 치환 자리(`:error`)를 가진 키를 파라미터 없이 호출 | 넷째 인자 `messageParams` 로 채운다 — 비워 두면 번역기가 자리표시자를 **그대로 둔 문장**을 돌려줘 운영자 화면에 `:error` 가 노출된다 (실패했을 때만 드러나 정상 흐름 테스트로는 안 잡힌다) |
| 사유를 모른다고 치환 자리를 비워 두기 | 알 수 없으면 일반 문구(`errors.unknown_error`)로 채운다 |
| 원문을 싣지 않기로 한 문구에 `:error` 자리를 남겨 두기 | 그 키에서 **치환 자리 자체를 없앤다** — 자리를 남기면 나중에 예외 원문으로 채우는 회귀를 부른다 |
| 하위 계층이 `false`/`null` 만 돌려주고 실패 사유를 버림 | 사유를 반환 경로에 실어 올린다 (배열 키 `reason` 또는 **뒤에 붙인 선택적 out 파라미터**) — 기존 호출부를 깨지 않는다 |
| 확장 수명주기 훅이 사유 없이 `false` 반환 | `AbstractModule`/`AbstractPlugin` 의 `failWith(__('...'))` — 코어가 그 사유를 원인 자리에 싣는다 |

`message`(첫 인자)와 `errors`(셋째 인자)는 다른 통로다. **키 자리에 원문을 넘기는 것은 언제나 금지**지만, `errors` 페이로드의 원문은 금지 대상이 아니다 — `ResponseHelper::error` 가 문자열 `errors` 를 `500+` 비디버그에서만 차단하고 배열은 통과시키는 것은 `tests/Unit/Helpers/ResponseHelperTest.php` 가 고정한 의도다. 관리자에게 결제대행사·외부 시스템이 돌려준 사유를 감추면 조치 근거가 사라지고, 다국어 키는 유한해서 예상 못 한 실패를 담지 못한다. 판단 축은 "원문이냐 키냐" 가 아니라 **누구에게 / 무엇의 원문인가 / 어느 통로인가** 셋이다.

상세: [exceptions.md "예외 → 응답 매핑"](docs/backend/exceptions.md). `tests/Feature/Http/GenericCatchStatusCodeContractTest.php` 가 코어와 모든 번들 확장의 컨트롤러를 전수 스캔해 두 규칙을 고정한다. 판정기를 한 확장 안에 두면 그 확장 밖의 동형 결함이 검출되지 않는다. 치환 자리 축은 `tests/Feature/Http/ErrorMessageParamSubstitutionTest.php` 가 고정한다 — 호출부를 열거하지 않고 `->error(...)` 전수를 괄호 균형으로 잘라, 키를 실제로 번역해 `:error` 를 요구하는지 판정한다. 같은 판정기가 `new *OperationException(...)` 생성자 축도 덮는다(파라미터 배열이 넷째가 아니라 둘째 인자다). 이 축이 없으면 키를 들고 다니는 예외로 던지는 경로가 통째로 사각이 된다.

### Listener 데이터 접근

| 금지 | 올바른 사용 |
|------|------------|
| Listener 에서 `Model::query/find/where/create` 직접 호출 | Repository 인터페이스 주입 후 위임 |
| Listener 에서 `DB::table()->update(...)` | Repository 의 도메인 의도 메서드 (recalculate*/anonymize* 등) |
| Listener 에서 `$row->save()` / `saveQuietly()` / `delete()` | Repository 의 update/save/delete 호출 |
| Listener 생성자에 구체 Repository 직접 주입 | Repository Interface 주입 |
| Listener 에서 `request()` / `$_POST` 직접 접근 | Service 가 검증 후 도메인 객체로 전달 받기 |
| Filter 훅에 `'type' => 'filter'` 누락 | type 명시 필수 (반환값 무시 회귀 차단) |
| 실패 시 호출자 트랜잭션을 되돌려야 하는 Action 훅에 `'sync' => true` 누락 | 금전 이동(쿠폰 차감·복원, 적립금 차감·복원)은 `sync` 필수. 기본값은 큐 래핑 + `afterCommit` 이라 **커밋 뒤에** 실행되어, 예외를 던져도 롤백되지 않고 오류 응답만 나간 채 데이터가 남는다 (큐 드라이버가 `sync` 여도 동일) |
| 훅 회귀 테스트에서 리스너를 손으로 `addAction` 등록 | `HookListenerRegistrar::register()` 로 **실제 등록 경로**를 태운다 — 손으로 등록하면 큐 래핑을 건너뛰어 커밋 이후 실행 문제를 통과시킨다 |
| Listener 가 `HookListenerInterface` 미구현 (auto-discovery 대상) | implements + `getSubscribedHooks()` 정적 메서드 |

> 상세: [hooks.md "Listener 데이터 접근 규정"](docs/extension/hooks.md), [service-repository.md](docs/backend/service-repository.md)

---

## 템플릿 엔진 내부 버전 (engine-v1.x.x)

코드와 문서에서 `engine-v1.x.x` 형태의 버전은 **템플릿 엔진의 내부 개발 이력**입니다.
그누보드7 공식 버전(`config/app.php`)과는 무관합니다.

- **CHANGELOG**: `resources/js/core/template-engine/CHANGELOG.md`
- **표기법**: `engine-v1.X.Y` (engine- 접두사 필수, `v1.X.Y` 단독 사용 금지)
- **사용처**: @since JSDoc, 인라인 주석, 규정 문서

### 엔진 CHANGELOG 반영 규칙

| 트리거 | 필수 작업 |
|--------|----------|
| `resources/js/core/template-engine/**` 기능 추가 시 | 마이너 버전 업 (engine-v1.X+1.0) + CHANGELOG 기록 |
| `resources/js/core/template-engine/**` 버그 수정 시 | 패치 버전 업 (engine-v1.X.Y+1) + CHANGELOG 기록 |
| `resources/js/core/*.ts` (TemplateApp, G7CoreGlobals 등) 버그 수정 시 | CHANGELOG `[Unreleased]` 또는 해당 버전에 기록 |
| 코드에 `@since` 추가 시 | CHANGELOG 해당 버전에 항목 추가 |
| 규정 문서에 엔진 버전 표기 시 | `engine-v1.X.Y` 형식 사용 |

### 엔진 CHANGELOG 대상 범위

엔진 CHANGELOG에 기록하는 대상은 **엔진 코어 코드**의 변경사항입니다:

| 포함 (엔진 코드) | 제외 (비엔진 코드) |
|------------------|---------------------|
| `resources/js/core/template-engine/**` | `templates/**/src/components/**` (템플릿 컴포넌트) |
| `resources/js/core/TemplateApp.ts` | `modules/**/resources/layouts/**` (모듈 레이아웃) |
| `resources/js/core/G7CoreGlobals.ts` | `resources/layouts/**` (코어 레이아웃 JSON) |
| `resources/js/core/template-engine.ts` | `docs/**` (규정 문서) |
| `resources/js/core/types/` (엔진 타입) | 백엔드 PHP 코드 |

### 엔진 CHANGELOG 작성 형식

Keep a Changelog 표준:

- `### Added` — 새 기능
- `### Fixed` — 버그 수정
- `### Changed` — 기존 기능 변경
- `### Deprecated` — 곧 제거될 기능
- `### Removed` — 제거된 기능

### CHANGELOG 항목 작성 규칙

엔진 코드 수정 후 CHANGELOG 미기록 시 작업 미완료로 간주합니다.

**항목 형식**: `- 수정 내용 요약 (수정 파일명)`

```markdown
# 좋은 예
- setState dot notation 멀티 키 병합 시 이전 키 변경 유실 방지 (ActionDispatcher)
- blocking 데이터소스 + errorHandling 데드락 — fallback 동기 적용, 에러핸들러 비동기 실행

# 나쁜 예
- 버그 수정                      ← 무엇을 수정했는지 불명확
- ActionDispatcher.ts 수정       ← 파일명만으로는 변경 내용 파악 불가
```

**패치 버전 항목 형식**: `- (engine-v1.X.Y) 수정 내용 (파일명)`

```markdown
- (engine-v1.17.5) dataKey 자동 바인딩 컴포넌트에서 setState 호출 시 stale 값 방지
```

**버전 결정 기준**:

| 상황 | 버전 처리 |
|------|----------|
| 새 기능 추가 (핸들러, 속성, API) | 마이너 버전 업: `engine-v1.X+1.0` |
| 기존 기능 버그 수정 | 패치 버전 업: `engine-v1.X.Y+1` |
| 특정 버전에 귀속 불가한 수정 | `[Unreleased]` 섹션에 기록 |
| 릴리스 시 | `[Unreleased]` → `[engine-v1.X.0]`으로 이동 |

**대규모 Fixed 섹션 카테고리 분류** (항목 10개 초과 시):

```markdown
### Fixed

#### 상태 동기화
- 항목 1
- 항목 2

#### 캐시
- 항목 3
```

---

## 공개 CHANGELOG 작성 규칙

코어(`CHANGELOG.md`) 및 확장(`modules/*/CHANGELOG.md`, `plugins/*/CHANGELOG.md`, `templates/*/CHANGELOG.md`)의 릴리즈 CHANGELOG 작성 규칙입니다.

### 톤과 표현

- 사용자/개발자가 읽는 문서이므로 **사용자 관점**으로 작성
- "~할 수 있도록 개선", "~하도록 변경", "~문제 수정" 톤 사용
- 각 불릿은 **1~2줄**로 간결하게
- 내부 구현 상세(클래스명, 파일 경로, 테스트 건수, 훅 체인, DI 패턴)는 포함하지 않음
- 이슈 번호(`#123`)는 포함하지 않음

### 포함/제외 대상

| 포함 | 제외 |
|------|------|
| 사용자에게 보이는 기능 추가/변경 | 내부 파일 경로, 클래스/메서드명 |
| API 변경 (엔드포인트, 파라미터) | 테스트 건수/파일명 |
| 기존 기능의 버그 수정 | 리팩토링 세부사항 |
| 성능 개선 (체감 가능한 것) | 내부 규정/문서 변경 |
| Breaking Change | 내부 작업 이슈 번호 단독 (예: `refs #347`) |
| 엔진 버전 참조 (engine-v1.X.Y) | 코드 패턴 설명 |
| **공개 제보자 attribution** (`(#N @login 님께서 제보해주셨습니다.)`) | — |
| **KISA 등 공식 보안 채널** (`(KISA 측에서 제보해주셨습니다 — KVE-XXXX-XXXXX)`) | — |

### 공개 제보자 attribution

공개 저장소(GitHub) 이슈로 제보·건의된 항목이 출시 CHANGELOG 에 반영되면, 항목 끝에 공개 이슈 번호와 제보자 GitHub 핸들 멘션을 부착합니다.

- 형식: `- (본문) (#N @login 님께서 제보해주셨습니다.)` 또는 `... 건의해주셨습니다.`
- 톤: 버그 리포트는 "제보", 제안형 개선 요청은 "건의"
- 다중 매칭: `(#A @x, #B @y 님께서 제보해주셨습니다.)` / 혼재: `(#A @x 님께서 제보해주시고, #B @y 님께서 건의해주셨습니다.)`
- KISA 등 공식 보안 채널: GitHub 멘션 없이 텍스트 "KISA 측에서" + 공개 가능한 식별자만

### 신규 기능의 버그 수정 제외 규칙

해당 릴리즈에서 **새로 도입한 기능**의 개발 중 버그 수정은 Fixed에 기록하지 않습니다. 사용자 관점에서 그 기능은 해당 릴리즈에서 처음 제공되므로, "추가했다가 고쳤다"는 내부 개발 이력일 뿐입니다.

**판단 기준**: 해당 버그가 **이전 릴리즈에도 존재했던 기능**에서 발생한 것인지 확인

### 기능 그룹핑

Added/Changed/Fixed 내 항목이 10개를 초과하면 `####` 서브 헤딩으로 기능 단위 분류합니다.

### Keep a Changelog 형식

- `## [버전] - YYYY-MM-DD` 헤더 필수
- `### Added` / `### Changed` / `### Fixed` / `### Removed` 카테고리 사용
- 최신 버전이 파일 상단

---

## 레이아웃 JSON 구현 규칙

```
1. 새로운 기능 사용 전 → 반드시 해당 규정 문서에서 지원 여부 확인
2. 지원되지 않는 문법 사용 금지 → 추측/가정으로 구현하지 않음
3. 불확실한 경우 → 기존 레이아웃 패턴 참조
4. 규정 문서에 없는 기능 → 절대 사용 금지
```

### 레이아웃 작성 체크리스트

```
□ 레이아웃 구조가 layout-json.md 스키마와 일치하는가?
□ 사용할 컴포넌트가 components.md에 정의되어 있는가?
□ 컴포넌트 props가 component-props.md에 정의된 것만 사용하는가?
□ 사용할 핸들러가 actions.md에 정의되어 있는가?
□ 핸들러의 params 구조가 actions-handlers.md와 일치하는가?
□ 데이터 바인딩 문법이 data-binding.md에 정의된 형식인가?
□ 다크 모드 클래스가 dark-mode.md 규칙을 따르는가?
□ 기존 유사 레이아웃에서 동일 패턴이 사용되고 있는가?
```

### 주의 사항

```text
필수: 규정 문서에 정의된 핸들러/props/바인딩 문법만 사용 (API 응답 구조도 확인 후 바인딩)
필수: Partial은 컴포넌트 치환만 수행 (computed, data_sources, modals, state 미지원)
필수: data_sources ID 고유성 유지, 조건부 렌더링은 if 속성만 사용 (type: "conditional" 미지원)
```

---

## 테스트 프로토콜

```text
기능 구현 = 테스트 코드 작성 필수
신규 기능 / 도메인 표면 변경 = 시나리오 매니페스트(tests/scenarios/<feature>.yaml) 작성 의무 — 입력 axis cross product + 후속 효과 체인 전수 커버
테스트 통과 = 작업 완료 (작성만으로 불충분!)
기존 테스트 있음 → 변경사항 반영하여 수정 후 실행
기능 구현 시 관련된 모든 계층(백엔드+프론트엔드+레이아웃 렌더링) 테스트 필수
주의: 모듈/플러그인 프론트엔드 테스트는 독립 vitest.config.ts 사용 (루트 config 포함 금지)
필수: 도메인 매트릭스 = 테스트 위치/형식 가이드. 입력 조합 망라 의무는 시나리오 매니페스트가 SSoT
필수: 버그 수정은 먼저 실패하는 회귀 테스트 → fail 확인 → 수정 → green 4단계
필수: 테스트 중 발견한 무관 에러도 같은 세션에서 처리 (stale test 또는 로직 수정)
필수: 릴리스 전 composer test-smoke 통과
```

> 상세: [docs/testing-guide.md](docs/testing-guide.md) — 기능 단위 시나리오 매트릭스, 도메인 매트릭스, Pre-release Smoke Suite, 회귀 테스트 4단계, 무관 에러 처리

### 그누보드7 레이아웃 렌더링 테스트

```text
그누보드7 레이아웃 테스트는 브라우저 기반 E2E가 아님!
Vitest + createLayoutTest() 유틸리티 사용 → 추가 인프라 불필요
"인프라 부족" 이유로 레이아웃 테스트 건너뛰기 절대 금지
레이아웃 테스트는 해당 레이아웃이 속한 확장 디렉토리에 작성
모듈 테스트: modules/_bundled/{id}/resources/js/__tests__/layouts/
템플릿 테스트: templates/_bundled/{id}/__tests__/layouts/
코어 테스트: resources/js/core/template-engine/__tests__/layouts/
```

| 특성 | 설명 |
|------|------|
| **테스트 환경** | Vitest (jsdom) - 브라우저 불필요 |
| **렌더링** | DynamicRenderer를 통한 실제 React 렌더링 |
| **유틸리티** | `createLayoutTest()` - 이미 구축됨 |
| **API 모킹** | `mockApi()` - fetch 자동 모킹 |
| **상태 관리** | `getState()`, `setState()` - 즉시 사용 가능 |
| **액션 트리거** | `triggerAction()` - 핸들러 실행 |

```typescript
import { createLayoutTest, screen } from '../utils/layoutTestUtils';

const testUtils = createLayoutTest(layoutJson);
testUtils.mockApi('products', { response: { data: [] } });
await testUtils.render();
expect(screen.getByTestId('element')).toBeInTheDocument();
testUtils.cleanup();
```

### 테스트 작성 트리거

| 수정 대상 | 테스트 파일 위치 | 테스트 유형 |
|----------|-----------------|-------------|
| `app/Models/*.php` | `tests/Unit/Models/*Test.php` | 모델 메서드, 관계, 스코프 |
| `app/Services/*.php` | `tests/Unit/Services/*Test.php` | 비즈니스 로직 |
| `app/Enums/*.php` | `tests/Unit/Enums/*Test.php` | Enum 메서드 |
| `app/Http/Controllers/**/*.php` | `tests/Feature/**/*Test.php` | API 엔드포인트 |
| `database/migrations/*.php` | 해당 모델/서비스 테스트에서 검증 | 스키마 변경 |
| `templates/**/src/components/**/*.tsx` | `templates/**/__tests__/*.test.tsx` | 컴포넌트 |
| `resources/js/core/**/*.ts` | `resources/js/core/__tests__/*.test.ts` | 템플릿 엔진 |
| `resources/layouts/**/*.json` | `resources/js/core/template-engine/__tests__/layouts/*.test.tsx` | 코어 레이아웃 렌더링 |
| `modules/**/resources/layouts/**/*.json` | `modules/_bundled/{id}/resources/js/__tests__/layouts/*.test.tsx` | 모듈 레이아웃 렌더링 |
| `templates/**/layouts/**/*.json` | `templates/_bundled/{id}/__tests__/layouts/*.test.tsx` | 템플릿 레이아웃 렌더링 |

### 기능 구현 시 전 계층 테스트

| 작업 유형 | 백엔드 (PHPUnit) | 프론트엔드 (Vitest) | 레이아웃 렌더링 (Vitest) |
| ---------- | ----------------- | ------------------- | ---------------------- |
| 새 화면 구현 | API 엔드포인트 테스트 | 컴포넌트 테스트 | 레이아웃 JSON 렌더링 테스트 |
| 기존 화면 수정 | 변경된 API 테스트 | 변경된 컴포넌트 테스트 | 레이아웃 렌더링 회귀 테스트 |
| 데이터 흐름 변경 | Service/Repository 테스트 | 상태 관리 테스트 | 데이터 바인딩 렌더링 테스트 |

### Windows 환경 테스트 규칙

```text
프론트엔드 (npm/Vitest) → PowerShell 래퍼 필수
백엔드 (PHPUnit/Laravel) → Bash 직접 실행
```

**프론트엔드 (템플릿 디렉토리에서 실행 권장)**:

```bash
# 템플릿 디렉토리에서 실행 (해당 템플릿만 테스트)
cd templates/sirsoft-admin_basic
powershell -Command "npm run test:run"              # 전체
powershell -Command "npm run test:run -- DataGrid"  # 특정 테스트

# 루트에서 실행 (모든 테스트)
powershell -Command "npm run test:run"
powershell -Command "npm run test:run -- template-engine"  # 코어 테스트
```

**백엔드**:

```bash
php artisan test
php artisan test --filter=TestName
```

**_bundled 확장 테스트 (활성 디렉토리 복사 불필요)**:

```bash
# _bundled 모듈 테스트 직접 실행
php vendor/bin/phpunit modules/_bundled/sirsoft-ecommerce/tests
php vendor/bin/phpunit --filter=ShippingPolicyControllerTest modules/_bundled/sirsoft-ecommerce/tests

# _bundled 모듈 프론트엔드 테스트
cd modules/_bundled/sirsoft-ecommerce
powershell -Command "npm run test:run"
```

### 필수 준수 사항

```text
필수: 기능 구현 시 모든 계층(백엔드+프론트엔드+레이아웃) 테스트 포함
필수: 테스트 통과 확인 후 완료 선언 (기존 테스트 유지 — 삭제/skip 금지)
필수: createLayoutTest() 유틸리티 활용 (추가 인프라 불필요)
```

> 상세: [testing-guide.md](docs/testing-guide.md) | [layout-testing.md](docs/frontend/layout-testing.md)

---

## npm install 규칙

기본 `npm install`은 `package-lock.json`을 자동 수정할 수 있으므로, lock 파일 변경 의도가 없는 의존성 복구나 작업 환경 재구성에는 `npm install --package-lock=false`를 사용합니다.

| 상황 | 권장 명령어 | 비고 |
| ---- | ----------- | ---- |
| 누락 의존성 복구 / 작업 환경 재구성 | `npm install --package-lock=false` | lock 파일 변경 없이 설치 |
| clean install | `npm ci` | `package.json`과 `package-lock.json`이 동기화된 경우 |
| 의존성 신규 추가/업데이트 | `npm install <pkg>` | lock 변경이 작업 범위에 포함된 경우만 |

lock 파일 변경 의도가 없는 상황에서 `npm install` 단독 실행을 피합니다. `module.json`, `plugin.json`, `template.json`의 `version`을 바꾸면 해당 확장의 `package.json`, `package-lock.json`, `composer.json` 버전도 함께 동기화합니다. 의존성 재설치 없이 lock 파일의 version 필드만 갱신할 때는 `npm install --package-lock-only`를 사용합니다.

---

## 핵심 원칙

### 1. 동적 로딩

```
절대 금지: composer.json에 모듈/플러그인 하드코딩
필수: /modules와 /plugins 디렉토리 스캔으로 자동 발견
```

### 2. 코어 수정 최소화

- 모든 확장은 모듈/플러그인으로 구현
- 훅 시스템을 통한 기능 추가
- 서비스 계층에서 훅 실행

### 3. 계층 분리

```
Controller → Request → Service → RepositoryInterface → Repository → Model
```

### 4. Repository 인터페이스

```
절대 금지: Repository 구체 클래스 직접 타입힌트
필수: Repository 인터페이스를 통한 DI
필수: CoreServiceProvider에서 인터페이스-구현체 바인딩
```

---

## 기술 스택

### 백엔드

- **PHP**: 8.2+
- **Laravel**: 12.x
- **데이터베이스**: MySQL 8.0
- **인증**: Laravel Sanctum 4.x
- **테스트**: PHPUnit 11.x
- **코드 스타일**: Laravel Pint (PSR-12)

---

## 아키텍처 패턴

### 디렉토리 구조 개요

```text
/
├── /app                    # 코어 애플리케이션
├── /modules                # 모듈 디렉토리
│   ├── _bundled/           # 선탑재 확장 소스 (Git 추적)
│   ├── _pending/           # 외부 다운로드 대기소 (Git 제외)
│   └── vendor-module/      # 활성 설치 디렉토리 (Git 제외)
├── /plugins                # 플러그인 디렉토리 (동일 구조)
├── /templates              # 템플릿 디렉토리 (동일 구조)
├── /resources/js/core/     # 코어 렌더링 엔진
└── /public/build/          # Vite 빌드 결과
```

### 네이밍 규칙

| 항목 | 디렉토리명 | 네임스페이스 |
|------|-----------|-------------|
| 모듈 | `sirsoft-ecommerce` | `Modules\Sirsoft\Ecommerce\` |
| 플러그인 | `sirsoft-payment` | `Plugins\Sirsoft\Payment\` |
| 템플릿 | `sirsoft-admin_basic` | - |

---

## 백엔드 개발 - 핵심 요약

> 상세: [docs/backend/](docs/backend/) | [database-guide.md](docs/database-guide.md)

```text
절대 금지: Service 클래스에 검증 로직 구현 → FormRequest + Custom Rule 사용
절대 금지: FormRequest authorize()에서 인증/권한 로직 → permission 미들웨어 사용
필수: __() 함수를 사용한 다국어 처리
필수: 상태/타입/분류는 Enum으로 정의
절대 금지: 인증 필요 미들웨어를 append()로 전역 등록 → appendToGroup('api') 사용
절대 금지: DB CASCADE에 의존한 삭제 → Service에서 명시적 삭제 (훅/파일/로깅 보장)
절대 금지: 로케일 하드코딩 → config('app.supported_locales') 사용
필수: 마이그레이션 한국어 comment 필수, down() 구현 필수
필수: FK 컬럼의 ->comment() 는 ->constrained()/->references()/->on() 앞에 둔다 (뒤에 두면 comment 가 컬럼이 아닌 FK 정의에 부착되어 조용히 사라진다)
필수: 소스 교정만으로는 기설치본이 낫지 않는다 — 마이그레이션은 재실행되지 않으므로 업그레이드 스텝 백필을 함께 작성
필수: 필터가 걸린 쿼리를 순회하며 그 행을 update/delete 하면 chunkById() (키셋 순회)
절대 금지: 그 경우 chunk()/each()/lazy() 사용 — OFFSET 기반이라 처리된 행이 결과에서 이탈한 만큼 커서가 밀려 미처리 행을 조용히 건너뛴다 (250건/청크 100 → 100건 누락, 예외·로그 없음)
주의: ResponseHelper::success($messageKey, $data) — 메시지가 첫 번째 인수
```

갱신값이 항상 필터 소속을 유지해 안전한 경우(예: `whereNotNull` + 갱신값이 항상 non-null)만 예외이며, 그 근거를 코드 주석에 남긴다. 정적 검사가 이 패턴을 검출한다.

> 상세 규칙 (API 리소스, ServiceProvider, validation, 인증, 활동 로그 등): [docs/backend/](docs/backend/) 각 문서 참조

### 컨트롤러 계층

```text
BaseApiController (최상위)
├── AdminBaseController (관리자 전용)
├── AuthBaseController (인증된 사용자)
└── PublicBaseController (공개 API)
```

### 파사드 사용

```text
✅ use Illuminate\Support\Facades\Log; → Log::info()
❌ \Log::info(), auth()->user() 금지
```

---

## 프론트엔드/템플릿 시스템

> 상세: [docs/frontend/](docs/frontend/)

```text
필수: 기본 컴포넌트만 사용 (Div, Button, H2 등 — HTML 태그 직접 사용 금지)
필수: 집합 컴포넌트 재사용 우선
필수: 다크 모드 light/dark variant 함께 지정
필수: HtmlEditor 사용 (RichTextEditor 미구현)
```

---

## 확장 시스템 빠른 참조

> 상세: [docs/extension/](docs/extension/)

```text
필수: 모든 확장 작업은 _bundled 디렉토리에서만 수행 (활성 디렉토리 직접 수정 금지)
필수: 프로덕션 반영은 update 커맨드로만 수행 (_bundled → 활성 디렉토리)
필수: 확장 코드 변경 시 manifest 버전 업 (미변경 시 업데이트 감지 불가)
필수: 버전 업 시 CHANGELOG.md 기록 — Keep a Changelog 표준 (미기록 시 버전 업 불가)
필수: StorageInterface 사용 (Storage::disk() 직접 호출 금지)
필수: ActionDispatcher 에 핸들러를 등록하는 확장은 재등록 진입점을 window 전역에 고정 이름으로 노출 — 모듈 window.__[Name].initModule, 플러그인 window.__[Name].initPlugin (미노출 시 로케일 전환 후 해당 확장 액션이 전부 무반응, 에러·토스트 없음). 진입점은 핸들러 재등록만 수행
필수: 확장 미들웨어는 getMiddleware() 로 부착 대상(targets) 명시 선언 (self-gate) — SP Kernel 미들웨어 그룹 직접 조작·라우트 파일 자기 미들웨어 FQCN 부착 금지, 무규율 전역 개입 금지
필수: 라우트 정의를 바꾸는 지점은 App\Support\RouteCacheHelper::rebuild() 로 라우트 캐시 갱신 — 확장 설치/활성화/비활성화/삭제/업데이트, 코어 업데이트·업그레이드 스텝. route:clear/route:cache 를 각 지점에 직접 흩어 놓지 않는다 (누락 발생, 비우기만 하면 재생성되지 않아 성능 이점 영구 소실). 훅 캐시와 달리 라우트 캐시에는 스캔 폴백이 없어 캐시에 없는 라우트는 예외·경고 없이 404. 파일 교체 중인 코어 업데이트는 중간에 clear(), 끝에서 rebuild(). 템플릿·모듈 설정은 서버 라우트 무관 (상세: docs/backend/routing.md "라우트 캐시")
필수: 확장 라우트는 활성 상태인 확장의 것만 등록한다 — 모듈·플러그인 두 라우트 프로바이더가 같은 기준을 쓴다. 게이트가 한쪽에만 있으면 그 비대칭은 오류가 아니라 "조용히 열린 경로" 로만 나타난다: 비활성화해도 화면·메뉴·에셋만 사라지고 API 는 계속 호출 가능하며, 컨트롤러가 정상 처리하므로 오류도 로그도 남지 않는다
필수: 그 rebuild() 는 확장 상태 캐시 무효화(invalidate*StatusCache()) 뒤에 온다 — route:cache 는 새 앱을 부팅해 라우트를 수집하는데 그 부팅의 확장 라우트 프로바이더는 DB 가 아니라 캐시된 활성 확장 목록(TTL 기본 1일)을 읽으므로, 먼저 구우면 방금 바뀐 상태가 빠진 채 박제되고 자가 회복되지 않는다 (활성화 → 그 확장 API 전량 404 / 비활성화 → 끈 확장 API 가 계속 호출 가능 / 업데이트 → 404 + 훅 리스너 누락). 무효화는 굽기 직전이 아니라 DB 상태 쓰기 직후에 둔다 — 같은 목록을 읽는 굽기가 라우트 캐시 말고도 있다 (오토로드 갱신 안의 훅 매핑 캐시). update 경로만 예외: Updating 전이 직후에는 비우지 않고 (비우면 그 창의 오토로드 갱신이 그 확장을 비활성으로 판정해 훅 리스너를 떨군다) 상태 복원 직후에 비운 뒤 ExtensionManager::regenerateHookCache() 로 훅 캐시를 다시 굽는다. 훅 캐시 폴백은 파일 부재·손상에만 작동해 내용이 stale 한 경우는 조용히 통과한다
필수: 코어 레이아웃에 모듈 UI 주입은 layout_extensions만 사용
필수: 모든 확장 작업은 Artisan 커맨드로 수행
```

> 상세 규칙 (플러그인 의존성, 훅 시스템, 버전 동기화, 업그레이드 스텝 등): [docs/extension/](docs/extension/) 각 문서 참조

### 확장 타입 요약

| 타입 | 네이밍 | 네임스페이스 | 예시 |
|------|--------|-------------|------|
| 모듈 | vendor-module | Modules\Vendor\Module\ | sirsoft-ecommerce |
| 플러그인 | vendor-plugin | Plugins\Vendor\Plugin\ | sirsoft-payment |
| 템플릿 | vendor-template | - | sirsoft-admin_basic |

---

## 한국어 사용 규칙

```
한국어: 사용자 대상 텍스트, 주석, 문서, 커밋 메시지, DB comment
영어: 변수명, 함수명, 클래스명
Laravel 기본 메서드 주석은 영어 유지 (up(), down() 등)
```

---

## 코드 품질

### Laravel Pint

```bash
vendor/bin/pint --dirty
```

### PHPDoc

```php
/**
 * 상품을 생성합니다.
 *
 * @param array $data 상품 생성 데이터
 * @return Product 생성된 상품 모델
 * @throws \Exception 생성 실패 시
 */
public function createProduct(array $data): Product
```

---

## 빌드 vs 확장 업데이트

| 수정 파일 유형 | 필요한 작업 |
|---------------|-------------|
| `*.json` (레이아웃만) | `{type}:update {id} --force` 실행 |
| `*.tsx`, `*.ts` + `*.json` | `{type}:build` + `{type}:update {id} --force` |
| `*.tsx`, `*.ts`만 | `{type}:build` + `{type}:update {id} --force` |
| `lang-packs/_bundled/**` (번들 언어팩 콘텐츠/버전) | `language-pack:update {id} --force` (빌드 불필요) |

```bash
# 확장 업데이트 (_bundled → 활성 반영)
php artisan template:update sirsoft-admin_basic --force
php artisan module:update sirsoft-ecommerce --force
php artisan plugin:update sirsoft-payment --force
php artisan language-pack:update g7-core-ja --force
```

번들 언어팩도 `_bundled` 는 배포 원본일 뿐이다. 설치본(`lang-packs/{id}/`)을 갱신하지 않으면 새로 추가한 번역 키가 런타임에 존재하지 않아 해당 로케일이 조용히 기준 로케일로 폴백한다.

### 배포 산출물의 브라우저 하한

선언 하한은 **Chrome 111 / Safari 16.4 / Firefox 128** 이다 ([requirements.md §7](docs/requirements.md)). 빌드 타깃(`target: 'es2020'`)은 이 하한을 강제하지 못한다 — **ES 연도와 브라우저 지원 연도가 다르기 때문**이다. ES2018 인 정규식 lookbehind 를 WebKit 은 Safari 16.4 에서야 구현했고, 타깃 검사는 그대로 통과시킨다.

정규식 **리터럴** 문법은 그중에서도 다운레벨이 원리상 불가능하다. 번들러는 lookbehind 를 `new RegExp(...)` 로 옮길 뿐이라 파싱 오류가 **런타임 오류로 이동**할 뿐 사라지지 않는다. 따라서 타깃 하향은 해법이 아니다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 배포 JS 산출물에 선언 하한 **초과** 문법·API (`Object.groupBy`·`Promise.withResolvers`·`Array.fromAsync`·`RegExp.escape`·정규식 `v` 플래그) | 하한 이하 문법으로 작성 |
| **부팅 임계 번들**(`public/build/core/template-engine.min.js`, `templates/_bundled/*/dist/js/components.iife.js`)에 정규식 리터럴 전용 문법(lookbehind `(?<!` `(?<=`, `v` 플래그) — 하한과 같은 버전이어도 | 그 두 파일만은 하한 미만 브라우저에서도 **파싱**돼야 한다 |
| 빌드 타깃을 낮춰 해결 시도 | 소스에서 그 문법을 쓰지 않는다 |
| 바이트 길이 비교식 번들러 검출기로 판정 | 문법·API 표 기반 검사 (프린터 표기 차이가 오탐을 낸다) |

부팅 임계 번들 둘은 `async`/`defer` 없는 동기 classic 스크립트다. 파싱에 실패하면 **폴백 안내 화면조차 렌더되지 않아** 사용자에게는 백지 또는 거짓 진단만 남는다. 하한 미만 브라우저에 "지원 범위 밖" 안내를 띄우려면 이 두 파일은 파싱에 성공해야 하므로, 하한과 **정확히 같은** 버전을 요구하는 문법(lookbehind = Safari 16.4)도 금지한다 — 하한 초과만 보는 검사로는 영원히 잡히지 않는 지점이다. 정적 검사가 이 규칙을 강제한다.

### 코어 3-번들 구조 + 공유 런타임 (engine-v1.51.0+)

`core:build` 는 코어 프론트엔드를 3개 IIFE 번들로 빌드한다:

| 번들 | 로드 시점 | vite config |
|------|----------|-------------|
| `template-engine.min.js` | 모든 페이지 (동기 `<script>`) | `vite.config.core.js` |
| `layout-editor.min.js` | `/admin/layout-editor/*` 진입 시 런타임 주입 | `vite.config.editor.js` |
| `devtools.min.js` | 디버그 모드에서만 런타임 주입 | `vite.config.devtools.js` |

lazy 번들(편집기/devtools)이 코어 런타임(DynamicRenderer·엔진 싱글톤·React Context·DevTools 코어)을 재사용할 때는 재번들하지 않고 `window.G7Core.__runtime` 을 빌려 쓴다 — React/컨텍스트/싱글톤 인스턴스 동일성이 강제되기 때문(사본이 둘이면 "Invalid hook call"·컨텍스트 미매칭). 메인 번들이 `G7CoreGlobals` 에서 공유 대상을 `G7Core.__runtime` 에 노출하고, lazy 번들 vite config 는 React 4종(`react`/`react-dom`/`react-dom/client`/`react/jsx-runtime`)을 external→window 로, 코어 런타임 모듈을 `resolveId` 플러그인으로 `__runtime-shims/` 로 치환한다.

### 확장 번들 병합 (서버측 concat)

활성 모듈/플러그인의 프론트엔드 IIFE JS·CSS 는 타입별로 서버에서 하나의 번들로 병합해 서빙한다(`/api/{modules,plugins}/bundle.{js,css}?v={version}`). `ExtensionBundleService` 가 정렬·필터·concat·캐시를 전담하고, 프론트는 `window.G7Config.bundleUrls` 를 읽어 모듈 번들 → 플러그인 번들 순으로 로드한다. 병합 규율:

- priority 순서는 선언형 — 실행 순서는 오직 manifest `loading.priority` 오름차순(`uasort`). 특정 확장 이름을 지목하는 분기를 두지 않는다.
- IIFE 사이는 `\n;\n`(JS)/`\n`(CSS) 로 잇는다. 미사용 시 ASI 경계가 깨져 번들 전체 파싱 에러가 난다.
- 소스맵은 prod strip, dev 는 개별 에셋 서빙 절대 URL 로 rewrite. 개별 에셋 서빙 라우트(`*.map` 포함)는 존치한다.
- 번들 URL 은 반드시 same-origin(`/api/...`). 외부 origin/CDN·protocol-relative 는 gdpr preblocker 에 자기 차단된다.
- 확장 에셋 절대경로는 `getBuiltAssetAbsolutePaths()`(=`getModulePath()`/`getPluginPath()`) 만 쓴다. `base_path("modules"|"plugins")` 직접 조립은 `_bundled` 경로 오해석 → 빈 번들.
- concat 루프는 확장별 try/catch — 실패 확장만 skip 하고 나머지 병합을 지속한다.
- 번들 파일명에 확장 캐시 버전을 포함(`{type}.{version}.{js,css}`). 조합 변경 시 version bump → 새 파일명 → 자동 재생성. 구파일 GC 는 `ext-bundles:cleanup` + `{module,plugin,template}:cache-clear` 가 담당한다. prod 은 version-in-path 디스크 캐시, 비프로덕션은 매 요청 concat.

### 빌드 명령어 (Artisan)

```bash
# 코어 템플릿 엔진 (resources/js/core/template-engine/**)
php artisan core:build                    # 기본: 템플릿 엔진만 빌드
php artisan core:build --full             # 전체 빌드 (npm run build)
php artisan core:build --watch            # 파일 감시 모드

# 모듈 빌드 (기본: _bundled 디렉토리)
php artisan module:build sirsoft-ecommerce          # _bundled에서 빌드
php artisan module:build --all                      # 모든 _bundled 모듈 빌드
php artisan module:build sirsoft-ecommerce --watch   # 활성 디렉토리에서 watch
php artisan module:build sirsoft-ecommerce --active   # 활성 디렉토리에서 빌드

# 템플릿 빌드 (기본: _bundled 디렉토리)
php artisan template:build sirsoft-admin_basic        # _bundled에서 빌드
php artisan template:build --all                      # 모든 _bundled 템플릿 빌드
php artisan template:build sirsoft-admin_basic --watch # 활성 디렉토리에서 watch
php artisan template:build sirsoft-admin_basic --active # 활성 디렉토리에서 빌드

# 플러그인 빌드 (기본: _bundled 디렉토리)
php artisan plugin:build sirsoft-payment              # _bundled에서 빌드
php artisan plugin:build --all                        # 모든 _bundled 플러그인 빌드
php artisan plugin:build sirsoft-payment --watch       # 활성 디렉토리에서 watch
php artisan plugin:build sirsoft-payment --active       # 활성 디렉토리에서 빌드
```

> **빌드 원칙**: 기본값은 `_bundled` 디렉토리. 빌드 결과물은 빌드 경로 내에만 남음.

`_bundled` 의 `dist/`(코어는 `public/build/core/`)는 Git 추적되는 배포 산출물이다 (`*.map` 만 ignore). src 변경 시 커밋 dist 를 `--production` 으로 동반 재빌드한다 — 신규 소스 리터럴이 dist 에 없으면 stale 빌드이며, 정적 검사가 이를 검출한다. 커밋 dist 에 `//# sourceMappingURL=` 참조를 남기지 않는다 — `.map` 은 배포본에 존재하지 않아 브라우저 개발자 도구에서 404 를 유발한다. 코어 3번들 재빌드는 `core:build --production`.

### 빌드는 자기 산출물만 교체한다 (`emptyOutDir`)

모든 vite config 는 `build.emptyOutDir: false` 를 **명시**한다. 기본값 `true` 는 산출물 디렉토리를 통째로 비우는데, 그 디렉토리에는 vite 가 만들지 않는 서빙 자산이 함께 산다.

| 함께 지워지던 것 | 결과 |
|---|---|
| `public/build/core/` 3번들 | 폴백이 없다 — `template-engine.min.js` 는 동기 classic 스크립트라 소실 = **사이트 부팅 불가**, 안내 화면조차 렌더되지 않는다 |
| `public/build/ext/{v}/` 게시본 | 이미 배달된 HTML 의 immutable URL 이 404. 재게시로 새 버전이 생겨도 **그 URL 은 복구되지 않는다** |
| 확장 `dist/vendor/` | 확장이 동봉한 구동 제3자 자산 소실 (자체 제공 원칙 위반) |

소실은 예외도 서버 로그도 남기지 않는다 — 브라우저 404 로만 나타나므로 운영자에게는 흔적이 없다. 잔존하는 구 해시 산출물은 `manifest.json`(또는 고정 파일명)이 선택하므로 참조되지 않는 사표이고, 정리 책임은 빌드 커맨드가 진다.

빌드 커맨드의 산출물 정리는 **활성 디렉토리를 건너뛴다.** 정리는 빌드 *전에* 돌므로 웹이 서빙 중인 `dist/` 를 비우면 빌드 완료까지가 통째로 서빙 공백이 되고, 빌드가 실패하면 빈 채로 남는다. 정리 대상은 `_bundled` / `_pending` 소스 디렉토리뿐이다.

정적 검사가 모든 vite config 의 명시 선언을 강제한다 (기본값 의존 금지 — 규약이 코드에 남지 않으면 다음 편집자가 같은 결함을 재도입한다).
> 활성 디렉토리 반영은 `update` 커맨드로만 수행. `--watch` 모드는 실시간 개발용으로 활성 디렉토리를 자동 사용.

---

## 확장 시스템 Artisan 명령어

```bash
# 코어 업데이트
php artisan core:check-updates                                    # 코어 업데이트 확인
php artisan core:update [--force] [--no-backup] [--no-maintenance] # 코어 업데이트 실행
php artisan core:execute-upgrade-steps --from=X.Y.Z --to=A.B.C [--force]  # 업그레이드 스텝 단독 실행 (HANDOFF 안내/수동 복구용 — 사전·사후 단계 자동 수행)

# 모듈
php artisan module:list
php artisan module:install [identifier]
php artisan module:activate [identifier]
php artisan module:deactivate [identifier]
php artisan module:uninstall [identifier]
php artisan module:composer-install [identifier?] [--all]
php artisan module:cache-clear [identifier?]
php artisan module:seed [identifier] [--sample] [--count=key=value]
php artisan module:check-updates [identifier?]
php artisan module:update [identifier] [--force] [--source=auto|bundled|github]

# 플러그인
php artisan plugin:list
php artisan plugin:install [identifier]
php artisan plugin:activate [identifier]
php artisan plugin:deactivate [identifier]
php artisan plugin:uninstall [identifier]
php artisan plugin:composer-install [identifier?] [--all]
php artisan plugin:cache-clear [identifier?]
php artisan plugin:seed [identifier] [--sample] [--count=key=value]
php artisan plugin:check-updates [identifier?]
php artisan plugin:update [identifier] [--force] [--source=auto|bundled|github]

# 템플릿
php artisan template:list
php artisan template:install [identifier]
php artisan template:activate [identifier]
php artisan template:deactivate [identifier]
php artisan template:uninstall [identifier]
php artisan template:cache-clear
php artisan template:check-updates [identifier?]
php artisan template:update [identifier] [--layout-strategy=overwrite] [--force] [--source=auto|bundled|github]

# Composer 의존성 (모듈/플러그인별 독립 vendor/)
php artisan extension:composer-install

# 오토로드
php artisan extension:update-autoload
```

---

## SEO Artisan 커맨드

```bash
php artisan seo:warmup [--layout=]
php artisan seo:clear [--layout=]
php artisan seo:stats
php artisan seo:generate-sitemap [--sync]
```

---

## 코드 스타일/마이그레이션 명령어

```bash
# 코드 스타일 (Laravel Pint)
vendor/bin/pint --dirty

# 마이그레이션
php artisan make:migration create_[table]_table
php artisan migrate
php artisan migrate:rollback
```

---

## 파일 유형별 규정 확인

파일 수정 **전** 해당 규정 파일을 먼저 확인합니다:

| 수정 대상 파일 패턴 | 작업 전 필수 참조 |
| ------------------- | ------------------ |
| `app/Http/Controllers/**` | [controllers.md](docs/backend/controllers.md), [api-documentation.md](docs/backend/api-documentation.md) |
| `app/Services/**` | [service-repository.md](docs/backend/service-repository.md) |
| `app/Http/Requests/**` | [validation.md](docs/backend/validation.md) |
| `app/Repositories/**` | [service-repository.md](docs/backend/service-repository.md) |
| `app/Http/Resources/**` | [api-resources.md](docs/backend/api-resources.md) |
| `app/**/DTO/**`, `modules/**/src/DTO/**`, `plugins/**/src/DTO/**` | [dto.md](docs/backend/dto.md) |
| `database/migrations/**` | [database-guide.md](docs/database-guide.md) |
| `database/seeders/**` | [database-guide.md](docs/database-guide.md) |
| `resources/layouts/**/*.json` | [layout-json.md](docs/frontend/layout-json.md) |
| `templates/**/layouts/**/*.json` | [layout-json.md](docs/frontend/layout-json.md) |
| `templates/**/src/components/**/*.tsx` | [components.md](docs/frontend/components.md) |
| `modules/**/Listeners/**` | [hooks.md](docs/extension/hooks.md) |
| `plugins/**/Listeners/**` | [hooks.md](docs/extension/hooks.md) |
| `lang/{ko,en}/**/*.php` | [database-guide.md](docs/database-guide.md) (다국어 섹션) — 코어 백엔드 다국어 |
| `lang/{ko,en}.json`, `lang/partial/{ko,en}/**` | [data-binding-i18n.md](docs/frontend/data-binding-i18n.md) — 코어 프론트엔드 다국어 (`$t:core.*`) |
| `lang/**` | [database-guide.md](docs/database-guide.md) (다국어 섹션) |
| `routes/**` | [routing.md](docs/backend/routing.md) |
| `app/Seo/**` | [seo-system.md](docs/backend/seo-system.md) |
| `app/Benchmark/**`, `config/benchmark.php` | [benchmark.md](docs/backend/benchmark.md) |

---

## 참고 파일 위치

- **AbstractModule**: `app/Extension/AbstractModule.php`
- **HookManager**: `app/Extension/HookManager.php`
- **ModuleManager**: `app/Extension/ModuleManager.php`
- **PluginManager**: `app/Extension/PluginManager.php`
- **TemplateManager**: `app/Extension/TemplateManager.php`
- **CoreStorageDriver**: `app/Extension/Storage/CoreStorageDriver.php`
- **ResponseHelper**: `app/Helpers/ResponseHelper.php`
- **ExtensionStatusGuard**: `app/Extension/Helpers/ExtensionStatusGuard.php`
- **ExtensionBackupHelper**: `app/Extension/Helpers/ExtensionBackupHelper.php`
- **ExtensionPendingHelper**: `app/Extension/Helpers/ExtensionPendingHelper.php`
- **ExtensionRoleSyncHelper**: `app/Extension/Helpers/ExtensionRoleSyncHelper.php`
- **ExtensionMenuSyncHelper**: `app/Extension/Helpers/ExtensionMenuSyncHelper.php`
- **SettingsMigrator**: `app/Extension/Helpers/SettingsMigrator.php`
- **UpgradeStepInterface**: `app/Contracts/Extension/UpgradeStepInterface.php`
- **UpgradeContext**: `app/Extension/UpgradeContext.php`
- **SeoRenderer**: `app/Seo/SeoRenderer.php`
- **SeoMiddleware**: `app/Seo/SeoMiddleware.php`
- **SeoCacheManager**: `app/Seo/SeoCacheManager.php`
- **SeoServiceProvider**: `app/Seo/SeoServiceProvider.php`
- **SitemapContributorInterface**: `app/Seo/Contracts/SitemapContributorInterface.php`
- **SitemapGenerator**: `app/Seo/SitemapGenerator.php`
- **ActivityLogChannel**: `app/ActivityLog/ActivityLogChannel.php`
- **ActivityLogHandler**: `app/ActivityLog/ActivityLogHandler.php`
- **ActivityLogProcessor**: `app/ActivityLog/ActivityLogProcessor.php`
- **ResolvesActivityLogType**: `app/ActivityLog/Traits/ResolvesActivityLogType.php`
- **ChangeDetector**: `app/ActivityLog/ChangeDetector.php`
- **CoreActivityLogListener**: `app/Listeners/CoreActivityLogListener.php`
- **BenchmarkProfileRegistry**: `app/Benchmark/BenchmarkProfileRegistry.php`
- **성능 계측 DTO**: `app/Benchmark/DTO/{BenchmarkProfile,BenchmarkRunOptions,BenchmarkResult}.php`
- **BenchmarkAxisRunner**: `app/Benchmark/Contracts/BenchmarkAxisRunner.php`
- **성능 계측 축 실행기**: `app/Benchmark/Axes/{List,Screen,Write,Batch}AxisRunner.php`
- **BenchmarkAxis**: `app/Enums/BenchmarkAxis.php`
