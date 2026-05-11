# 그누보드7 Development Guide

> 이 문서는 그누보드7 오픈소스 CMS 프로젝트의 개발 가이드입니다. AI 에이전트 및 외부 기여자를 위한 참고 자료입니다.

## 빠른 참조 - 상세 가이드 문서

<!-- AUTO-GENERATED-START: docs-quick-reference -->

### 백엔드 [backend/](docs/backend/) (31개)

| 문서 | 설명 | TL;DR 핵심 |
|------|------|-----------|
| [activity-log-hooks.md](docs/backend/activity-log-hooks.md) | 활동 로그 훅 레퍼런스 (Activity Log Hooks Reference) | 코어 66훅 + 이커머스 92훅 + 게시판 32훅 + 페이지 8훅 = 총 198훅 |
| [activity-log.md](docs/backend/activity-log.md) | 활동 로그 시스템 (Activity Log System) | Monolog 기반: Service 훅 → Listener → Log::channel('activity... |
| [admin-settings-access.md](docs/backend/admin-settings-access.md) | Admin 환경설정 값 접근 (`g7_core_settings` vs `config()`) | 동기화 SSoT: storage/app/settings/*.json → SettingsServicePr... |
| [api-resources.md](docs/backend/api-resources.md) | API 리소스 | Resource: BaseApiResource 상속 필수 / Collection: BaseApiColl... |
| [authentication.md](docs/backend/authentication.md) | 인증 및 세션 처리 | Laravel Sanctum 토큰 전용 인증 (Bearer 토큰만 사용) |
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
| [response-helper.md](docs/backend/response-helper.md) | API 응답 규칙 (ResponseHelper) | 모든 API 응답은 ResponseHelper 사용 |
| [routing.md](docs/backend/routing.md) | 라우트 네이밍 및 경로 | 모든 라우트는 name() 필수: ->name('api.users.index') |
| [search-system.md](docs/backend/search-system.md) | Scout 검색 엔진 시스템 (Search System) | Laravel Scout + DatabaseFulltextEngine: MySQL FULLTEXT + ... |
| [seo-system.md](docs/backend/seo-system.md) | SEO 페이지 생성기 시스템 (SEO Page Generator) | SeoMiddleware: 봇 요청 감지 → ?locale= 파라미터 해석 → SeoRenderer가 ... |
| [service-provider.md](docs/backend/service-provider.md) | 서비스 프로바이더 안전성 | DB 접근 전 .env 파일 존재 확인 필수 |
| [service-repository.md](docs/backend/service-repository.md) | Service-Repository 패턴 | RepositoryInterface 주입 필수 (구체 클래스 직접 주입 금지) |
| [settings-multilingual-enrichment.md](docs/backend/settings-multilingual-enrichment.md) | Settings 카탈로그 다국어 자동 보강 | settings JSON 의 다국어 카탈로그 라벨(_cached_name 등)은 카탈로그 빌드 시점에 보강 |
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

### 확장 시스템 [extension/](docs/extension/) (29개)

| 문서 | 설명 | TL;DR 핵심 |
|------|------|-----------|
| [cache-driver.md](docs/extension/cache-driver.md) | 캐시 드라이버 시스템 (CacheInterface) | 모든 캐시 저장은 CacheInterface 사용 (Cache:: 직접 호출 금지) |
| [changelog-rules.md](docs/extension/changelog-rules.md) | Changelog 규칙 (Changelog Rules) | 확장/코어 버전 업 시 CHANGELOG.md에 변경사항 기록 필수 (미기록 시 버전 업 불가) |
| [extension-manager.md](docs/extension/extension-manager.md) | ExtensionManager (확장 관리자) | composer.json 수정 없음 - 런타임 오토로드 방식 사용 |
| [extension-update-system.md](docs/extension/extension-update-system.md) | 확장 업데이트 시스템 (Extension Update System) | 업데이트 감지 우선순위: GitHub > _bundled (2단계, _pending 미참여) |
| [hooks.md](docs/extension/hooks.md) | 훅 시스템 (Hook System) | Action 훅: doAction() - 부가 작업 (로그, 알림, 캐시) |
| [language-packs.md](docs/extension/language-packs.md) | 언어팩 시스템 (Language Packs) | 코어/번들 확장의 lang/{ko,en}/ 는 가상 보호 행으로 자동 노출 (DB 없이 항상 activ... |
| [layout-extensions.md](docs/extension/layout-extensions.md) | 레이아웃 확장 시스템 (Layout Extensions) | - |
| [menus.md](docs/extension/menus.md) | 메뉴 시스템 | 구조: User → Role → role_menus 피벗 → Menu |
| [module-assets.md](docs/extension/module-assets.md) | 모듈 프론트엔드 에셋 시스템 | module.json에 에셋 매니페스트 정의 (js, css, loading strategy) |
| [module-basics.md](docs/extension/module-basics.md) | 모듈 개발 기초 | 디렉토리: vendor-module (예: sirsoft-ecommerce) |
| [module-commands.md](docs/extension/module-commands.md) | 모듈 Artisan 커맨드 | 목록: php artisan module:list |
| [module-i18n.md](docs/extension/module-i18n.md) | 모듈 다국어 시스템 | 백엔드: /lang/{locale}/*.php → __('vendor-module::key') |
| [module-identity-settings.md](docs/extension/module-identity-settings.md) | 모듈/플러그인 본인인증(IDV) 설정 통합 가이드 | 정책/목적/메시지: module.php::getIdentity{Policies,Purposes,Mess... |
| [module-layouts.md](docs/extension/module-layouts.md) | 모듈 레이아웃 시스템 | 위치: modules/_bundled/vendor-module/resources/layouts/admi... |
| [module-routing.md](docs/extension/module-routing.md) | 모듈 라우트 규칙 | URL prefix 자동: /api/admin/[vendor-module]/... |
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

### 공통 (4개)

| 문서 | 설명 | TL;DR 핵심 |
|------|------|-----------|
| [cheatsheet.md](docs/cheatsheet.md) | 그누보드7 자주 쓰는 명령어 치트시트 | _bundled에서 레이아웃 JSON만 수정 → 확장 업데이트(--force)만 실행 (빌드 불필요) |
| [database-guide.md](docs/database-guide.md) | 그누보드7 데이터베이스 개발 가이드 | 마이그레이션: 한국어 comment 필수, down() 구현 필수 |
| [requirements.md](docs/requirements.md) | 그누보드7 시스템 요구사항 (System Requirements) | PHP 8.2+ 필수 |
| [testing-guide.md](docs/testing-guide.md) | 그누보드7 테스트 가이드 | 테스트 통과 = 작업 완료 (작성만으로 불충분!) |


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

- 트리거: 코어 공개 확장 표면(`app/Extension/Abstract*`, `HookManager`, `ExtensionManager`, `ModuleManager`, `PluginManager`, `TemplateManager`, `app/Contracts/Extension/**`, `app/Extension/Helpers/**`, `app/Seo/Contracts/**`, `app/ActivityLog/**` 공개 API, 루트 `CHANGELOG.md` Added/Changed/Removed) 수정
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
| `Icon className="w-4 h-4"` | `Icon size="sm"` 또는 `className="text-sm"` |
| `Select valueKey/labelKey` | computed로 `{ value, label }` 변환 |
| Form 내 `Button` type 없음 | `type="button"` 명시 (submit 방지) |
| `options={{options}}` | `options={{options ?? []}}` (fallback) |

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

### Listener 데이터 접근

| 금지 | 올바른 사용 |
|------|------------|
| Listener 에서 `Model::query/find/where/create` 직접 호출 | Repository 인터페이스 주입 후 위임 |
| Listener 에서 `DB::table()->update(...)` | Repository 의 도메인 의도 메서드 (recalculate*/anonymize* 등) |
| Listener 에서 `$row->save()` / `saveQuietly()` / `delete()` | Repository 의 update/save/delete 호출 |
| Listener 생성자에 구체 Repository 직접 주입 | Repository Interface 주입 |
| Listener 에서 `request()` / `$_POST` 직접 접근 | Service 가 검증 후 도메인 객체로 전달 받기 |
| Filter 훅에 `'type' => 'filter'` 누락 | type 명시 필수 (반환값 무시 회귀 차단) |
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
| Breaking Change | 이슈 번호 |
| 엔진 버전 참조 (engine-v1.X.Y) | 코드 패턴 설명 |

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
주의: ResponseHelper::success($messageKey, $data) — 메시지가 첫 번째 인수
```

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

```bash
# 확장 업데이트 (_bundled → 활성 반영)
php artisan template:update sirsoft-admin_basic --force
php artisan module:update sirsoft-ecommerce --force
php artisan plugin:update sirsoft-payment --force
```

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
> 활성 디렉토리 반영은 `update` 커맨드로만 수행. `--watch` 모드는 실시간 개발용으로 활성 디렉토리를 자동 사용.

---

## 확장 시스템 Artisan 명령어

```bash
# 코어 업데이트
php artisan core:check-updates                                    # 코어 업데이트 확인
php artisan core:update [--force] [--no-backup] [--no-maintenance] # 코어 업데이트 실행

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
| `app/Http/Controllers/**` | [controllers.md](docs/backend/controllers.md) |
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
| `lang/**` | [database-guide.md](docs/database-guide.md) (다국어 섹션) |
| `routes/**` | [routing.md](docs/backend/routing.md) |
| `app/Seo/**` | [seo-system.md](docs/backend/seo-system.md) |

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
