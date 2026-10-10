# 그누보드7 Development Guide

> 그누보드7 오픈소스 CMS 의 개발 가이드다. AI 에이전트와 외부 기여자를 위한 진입점이며, 상세 규칙은 링크된 문서에서 필요할 때 읽는다(점진적 공개).

## 문서 지도

<!-- AUTO-GENERATED-START: docs-quick-reference -->

| 카테고리 | 문서 수 | 진입점 |
|---|---:|---|
| 백엔드 | 39 | [docs/backend/](docs/backend/) |
| 프론트엔드 | 47 | [docs/frontend/](docs/frontend/) |
| 확장 시스템 | 37 | [docs/extension/](docs/extension/) |
| 공통 | 12 | [docs/](docs/) |

전체 목록·작업 유형별 필수 문서·API 레퍼런스·확장 개발자 문서: [docs/README.md](docs/README.md)

<!-- AUTO-GENERATED-END: docs-quick-reference -->

## 프로젝트 개요

**프로젝트명**: 그누보드7
**목적**: 오픈소스 CMS 플랫폼
**설계 원칙**: 코어 수정 최소화, 모듈화, 플러그인 시스템, 템플릿 시스템, 동적 로딩

## 버전 동기화 의무

코어 공개 확장 표면(`app/Extension/Abstract*`·`HookManager`·`app/Contracts/Extension/**`·`app/Extension/Helpers/**`·`app/Repositories/Concerns/**`·`app/Seo/Contracts/**`·`app/ActivityLog/**` 공개 API·[확장이 쓰는 코어 지원 클래스](docs/rules/extension-assets.md#확장이-쓰는-코어-지원-클래스)·루트 `CHANGELOG.md` Added/Changed/Removed)을 바꾸면 영향 번들 확장의 `g7_version` 을, 번들 확장의 공개 Service/Contract/Repository/Model/Route·훅·CHANGELOG 를 바꾸면 의존 확장의 `dependencies.{modules|plugins}` 를 함께 갱신하고, manifest 버전을 바꾸면 `package.json`·`composer.json` 도 같은 버전으로 맞춘다. 판정 순서·조치: [extension-assets.md](docs/rules/extension-assets.md#버전-동기화-의무).

## 핵심 원칙

1. 동적 로딩 — composer.json 에 모듈/플러그인 하드코딩 금지, `/modules`·`/plugins` 스캔으로 자동 발견.
2. 코어 수정 최소화 — 모든 확장은 모듈/플러그인으로, 훅 시스템으로 기능 추가, 서비스 계층에서 훅 실행.
3. 계층 분리 — `Controller → Request → Service → RepositoryInterface → Repository → Model`.
4. Repository 인터페이스 — 구체 클래스 타입힌트 금지, 인터페이스 DI + CoreServiceProvider 바인딩.

## 기술 스택·구조

PHP 8.2+ · Laravel 12.x · MySQL 8.0 · Sanctum 4.x · PHPUnit 11.x · Pint(PSR-12). `/app` 코어, `/modules`·`/plugins`·`/templates` 는 `_bundled/`(선탑재 소스, Git 추적) · `_pending/`(다운로드 대기, 제외) · `vendor-name/`(활성 설치본, 제외), `/resources/js/core/` 렌더링 엔진, `/public/build/` Vite 산출물.

| 항목 | 디렉토리명 | 네임스페이스 |
|------|-----------|-------------|
| 모듈 | `sirsoft-ecommerce` | `Modules\Sirsoft\Ecommerce\` |
| 플러그인 | `sirsoft-payment` | `Plugins\Sirsoft\Payment\` |
| 템플릿 | `sirsoft-admin_basic` | - |

## 백엔드 개발 - 핵심 요약

> 전문: [docs/rules/backend-misc.md](docs/rules/backend-misc.md#백엔드-핵심-원칙) · [데이터베이스 규칙](docs/rules/backend-misc.md#데이터베이스-규칙) · [docs/backend/](docs/backend/)

```text
절대 금지: Service 클래스에 검증 로직 구현 → FormRequest + Custom Rule 사용
절대 금지: FormRequest authorize()에서 인증/권한 로직 → permission 미들웨어 사용
필수: __() 함수를 사용한 다국어 처리
필수: 상태/타입/분류는 Enum으로 정의
절대 금지: 인증 필요 미들웨어를 append()로 전역 등록 → appendToGroup('api') 사용
절대 금지: 로케일 하드코딩 → config('app.supported_locales') 사용
절대 금지: DB CASCADE에 의존한 삭제 → Service에서 명시적 삭제 (훅/파일/로깅 보장)
필수: 마이그레이션 한국어 comment 필수, down() 구현 필수
```

컨트롤러는 BaseApiController → Admin/Auth/Public BaseController 계층을 따르고, 파사드는 `use Illuminate\Support\Facades\Log;` 로 가져온다(`\Log::`, `auth()->user()` 금지).

## 프론트엔드/템플릿 시스템

> 전문: [docs/rules/frontend-actions-binding.md](docs/rules/frontend-actions-binding.md) · [docs/frontend/](docs/frontend/)

```text
필수: 기본 컴포넌트만 사용 (Div, Button, H2 등 — HTML 태그 직접 사용 금지)
필수: 집합 컴포넌트 재사용 우선
필수: 다크 모드 light/dark variant 함께 지정
필수: HtmlEditor 사용 (RichTextEditor 미구현)
```

## 확장 시스템 빠른 참조

> 전문: [docs/rules/extension-assets.md](docs/rules/extension-assets.md#확장-시스템-핵심) · [docs/extension/](docs/extension/)

```text
필수: 모든 확장 작업은 _bundled 디렉토리에서만 수행 (활성 디렉토리 직접 수정 금지)
필수: 프로덕션 반영은 update 커맨드로만 수행 (_bundled → 활성 디렉토리)
필수: 확장 코드 변경 시 manifest 버전 업 (미변경 시 업데이트 감지 불가)
필수: 버전 업 시 CHANGELOG.md 기록 — Keep a Changelog 표준 (미기록 시 버전 업 불가)
필수: StorageInterface 사용 (Storage::disk() 직접 호출 금지)
필수: ActionDispatcher 에 핸들러를 등록하는 확장은 재등록 진입점(모듈 window.__[Name].initModule, 플러그인 window.__[Name].initPlugin)을 window 전역에 노출 (미노출 시 로케일 전환 후 액션 무반응)
필수: 확장 미들웨어는 getMiddleware() 로 부착 대상(targets) 명시 선언 (self-gate) — SP Kernel 미들웨어 그룹 직접 조작·라우트 파일 자기 미들웨어 FQCN 부착 금지, 무규율 전역 개입 금지
필수: 라우트 정의를 바꾸는 지점은 App\Support\RouteCacheHelper::rebuild() 로 라우트 캐시 갱신 (route:clear/route:cache 를 각 지점에 흩어 놓지 않는다)
필수: 확장 컴포넌트는 `{확장식별자}.{이름}` 등록 (접두어 없는 이름은 거부), 대체 권한은 템플릿 전용 — 상세: docs/extension/extension-components.md
필수: 플러그인 설정 UI 는 `resources/layouts/admin/plugin_settings.g7.html`, 짝 `.json` 디스크 상주 필수. 라우트는 `plugins/{identifier}/` 안에 선언
```

## 절대 금지 패턴 (CRITICAL RULES)

표의 왼쪽은 금지, 오른쪽이 올바른 사용이다. 영역별 전문: [docs/rules/README.md](docs/rules/README.md)

| 영역 | 문서 |
|---|---|
| 프론트엔드 액션·바인딩·상태 규칙 | [frontend-actions-binding.md](docs/rules/frontend-actions-binding.md) |
| 레이아웃 편집기·위젯 규칙 | [editor-widgets.md](docs/rules/editor-widgets.md) |
| 라우팅·인증·보안 경계 규칙 | [routing-security.md](docs/rules/routing-security.md) |
| 목록·검색·페이지 규칙 | [list-context.md](docs/rules/list-context.md) |
| 확장 에셋·문서·캐시·의존성 규칙 | [extension-assets.md](docs/rules/extension-assets.md) |
| 백엔드 일반 규칙 | [backend-misc.md](docs/rules/backend-misc.md) |

## 파일 유형별 규정 확인

파일을 수정하기 전에 그 유형의 규정 문서를 읽는다. 아래 표는 파일 규정 매핑에서 자동 생성한다 — 손으로 고치지 않는다.

<!-- AUTO-GENERATED-START: file-type-rules -->
| 수정 대상 파일 패턴 | 작업 전 필수 참조 |
| --- | --- |
| `(modules\|plugins\|templates)/_bundled/{id}/**` | 그 확장의 `AGENTS.md` · `docs/README.md` (설계 의도·동반 의무). 표면이 바뀌면 `php artisan ext:docgen --scope={type}:{id}` |
| `app/Support/**/*.php` | [service-repository](docs/backend/service-repository.md), [validation](docs/backend/validation.md), [testing-guide](docs/testing-guide.md) |
| `app/Http/View/Composers/**` | [service-repository](docs/backend/service-repository.md), [template-basics](docs/extension/template-basics.md), [testing-guide](docs/testing-guide.md) |
| `resources/views/**/*.blade.php` | [template-basics](docs/extension/template-basics.md), [testing-guide](docs/testing-guide.md) |
| 코어 공개 확장 표면(`app/Extension/Abstract*`·`HookManager`·`app/Contracts/Extension/**`) | [changelog-rules](docs/extension/changelog-rules.md) |
| `resources/js/core/{template-engine/**,TemplateApp.ts,template-engine.ts,auth/**,identity/**,support/**}` | [state-management](docs/frontend/state-management.md) |
| 번들 확장 공개 표면(`src/{Services,Contracts,Repositories,Models}/**`·`routes/**`) | [changelog-rules](docs/extension/changelog-rules.md) |
| `**/extensions/**/*.{g7.html,json}` | [layout-markup](docs/frontend/layout-markup.md), [layout-extensions](docs/extension/layout-extensions.md), [layout-json](docs/frontend/layout-json.md) |
| `(modules|plugins|templates)/**/editor-spec.json`·`editor-spec/**/*.json` | [editor-spec](docs/extension/editor-spec.md) |
| `modules/**/layouts/**/*.{g7.html,json}` | [layout-markup](docs/frontend/layout-markup.md), [module-layouts](docs/extension/module-layouts.md), [layout-json](docs/frontend/layout-json.md) |
| `modules/**/lang/**` | [module-i18n](docs/extension/module-i18n.md) |
| `modules/**/Module.php` | [module-basics](docs/extension/module-basics.md) |
| `config/app.php` | [core-config](docs/backend/core-config.md) |
| `modules/**/module.json` | [module-basics](docs/extension/module-basics.md), [module-assets](docs/extension/module-assets.md) |
| `plugins/**/plugin.php` | [plugin-development](docs/extension/plugin-development.md) |
| `plugins/**/Listeners/**` | [hooks](docs/extension/hooks.md), [plugin-development](docs/extension/plugin-development.md), [service-repository](docs/backend/service-repository.md), [testing-guide](docs/testing-guide.md) |
| `templates/**/template.json` | [template-basics](docs/extension/template-basics.md) |
| `plugins/**/plugin.json` | [plugin-development](docs/extension/plugin-development.md) |
| `templates/**/routes.json` | [template-routing](docs/extension/template-routing.md) |
| `**/layouts/**/*.{g7.html,json}` | [layout-markup](docs/frontend/layout-markup.md), [layout-json](docs/frontend/layout-json.md), [actions-handlers](docs/frontend/actions-handlers.md) |
| `**/partials/**/*.{g7.html,json}` | [layout-markup](docs/frontend/layout-markup.md), [layout-json-inheritance](docs/frontend/layout-json-inheritance.md) |
| `**/handlers/**/*.ts` | [g7core-api](docs/frontend/g7core-api.md), [actions-handlers](docs/frontend/actions-handlers.md) |
| `**/components/**/*.tsx` | [components](docs/frontend/components.md), [components](templates/_bundled/sirsoft-admin_basic/docs/components.md), [components](templates/_bundled/sirsoft-basic/docs/components.md) |
| `**/upgrades/Upgrade_*.php` | [upgrade-step-guide](docs/extension/upgrade-step-guide.md) |
| `**/Controllers/**` | [controllers](docs/backend/controllers.md), [response-helper](docs/backend/response-helper.md), [api-documentation](docs/backend/api-documentation.md) |
| `**/Services/*{Calculator,Policy,Rule,Pricing*,Calculation*}.php` | [service-repository](docs/backend/service-repository.md), [testing-guide](docs/testing-guide.md) |
| `**/Services/**` | [service-repository](docs/backend/service-repository.md), [hooks](docs/extension/hooks.md), [testing-guide](docs/testing-guide.md) |
| `**/Repositories/**` | [service-repository](docs/backend/service-repository.md) |
| `**/Requests/**` | [validation](docs/backend/validation.md) |
| `**/Resources/**` | [api-resources](docs/backend/api-resources.md) |
| `**/Exceptions/**` | [exceptions](docs/backend/exceptions.md) |
| `**/Providers/**` | [service-provider](docs/backend/service-provider.md) |
| `**/Listeners/**` | [hooks](docs/extension/hooks.md), [service-repository](docs/backend/service-repository.md), [testing-guide](docs/testing-guide.md) |
| `app/Console/Commands/**` | [console-confirm](docs/backend/console-confirm.md) |
| `**/Models/**` | [service-repository](docs/backend/service-repository.md), [enum](docs/backend/enum.md) |
| `**/Enums/**` | [enum](docs/backend/enum.md) |
| `**/Middleware/**` | [middleware](docs/backend/middleware.md) |
| `composer.json`·`composer.lock` | [vendor-bundle](docs/extension/vendor-bundle.md) |
| `**/Events/**` | [broadcasting](docs/backend/broadcasting.md) |
| `**/migrations/**` | [database-guide](docs/database-guide.md), [testing-guide](docs/testing-guide.md) |
| `**/seeders/**` | [database-guide](docs/database-guide.md) |
| `**/routes/**` | [routing](docs/backend/routing.md), [middleware](docs/backend/middleware.md), [api-documentation](docs/backend/api-documentation.md) |
| `**/lang/**/*.{php,json}` | [database-guide](docs/database-guide.md) |
| `**/tests/**/*.php` | [testing-guide](docs/testing-guide.md) |
| `**/__tests__/**/*.ts(x)` | [testing-guide](docs/testing-guide.md), [layout-testing](docs/frontend/layout-testing.md) |
<!-- AUTO-GENERATED-END: file-type-rules -->

## 그 밖의 규칙

| 주제 | 문서 |
|---|---|
| 엔진 CHANGELOG · 공개 CHANGELOG 작성 | [changelog-rules.md](docs/extension/changelog-rules.md#템플릿-엔진-내부-버전-engine-v1xx) |
| 레이아웃 작성 체크리스트(`.g7.html` 소스 / `.json` 계약) | [layout-json.md](docs/frontend/layout-json.md#레이아웃-구현-규칙-작성-g7html--런타임-계약-json) |
| 테스트 프로토콜 · Windows 실행 · npm install | [testing-guide.md](docs/testing-guide.md#테스트-프로토콜) |
| 빌드·확장 업데이트·Artisan·SEO·코드 스타일·참고 파일 위치 | [cheatsheet.md](docs/cheatsheet.md) |
| 한국어 사용 규칙 · PHPDoc | [cheatsheet.md](docs/cheatsheet.md#한국어-사용-규칙) |
