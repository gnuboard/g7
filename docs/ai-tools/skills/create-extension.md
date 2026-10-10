<!-- 자동 생성 문서입니다. 직접 고치지 마세요. -->

# 확장 스캐폴딩 (create-extension)

## 언제

- 사용자가 새 모듈·플러그인·템플릿 생성을 지시할 때. 산출물은 `modules/_bundled/`, `plugins/_bundled/`, `templates/_bundled/` 아래 신규 디렉토리다.
- 기존 확장 수정에는 쓰지 않는다. 그 확장의 `AGENTS.md`·`docs/README.md` 를 따른다.
- 골격이 맞는지 판단이 어려우면 학습용 샘플(`gnuboard7-hello_module`·`gnuboard7-hello_plugin`·`gnuboard7-hello_admin_template`·`gnuboard7-hello_user_template`)을 1:1 비교 기준으로 쓴다.

## 입력

| 인자 | 값 |
| --- | --- |
| `<type>` | `module` · `plugin` · `template` |
| `<vendor-name>` | `vendor-name` 형식. 예 `sirsoft-ecommerce`, `sirsoft-payment`, `sirsoft-admin_modern` |
| `--type=admin` 또는 `--type=user` | template 전용, 기본 `admin`. 이름 권장: admin `vendor-admin_xxx`, user `vendor-xxx` |

이름 변환: 하이픈은 네임스페이스 구분자, 언더스코어는 PascalCase 결합 (`sirsoft-order_management` → `Modules\Sirsoft\OrderManagement\`).

| type | 위치 | 네임스페이스 | 클래스 | manifest |
| --- | --- | --- | --- | --- |
| module | `modules/_bundled/<id>/` | `Modules\Vendor\Name\` | `Module` (`module.php`) | `module.json` |
| plugin | `plugins/_bundled/<id>/` | `Plugins\Vendor\Name\` | `Plugin` (루트 `plugin.php`) | `plugin.json` |
| template | `templates/_bundled/<id>/` | 없음 (컴포넌트 번들) | 없음 | `template.json` (`type` 필수) |

## 절차

1. 규정 문서 읽기 — 공통: [changelog-rules.md](../../extension/changelog-rules.md), [extension-documentation.md](../../extension/extension-documentation.md), [sample-extensions.md](../../extension/sample-extensions.md). type 별 목록은 각 references 상단에 있다.
2. 이름 파싱 — 위 표대로 디렉토리·네임스페이스·클래스명을 정한다. 같은 식별자의 기존 확장이 없는지 먼저 확인한다.
3. 디렉토리 구조 생성 — references 의 트리를 그대로 만든다. 빈 디렉토리도 포함한다.
4. 기본 파일 생성 — references 의 골격을 `[자리표시자]` 치환으로 만든다. 공통 필수: manifest(메타데이터 SSoT, version `0.1.0`), `CHANGELOG.md`(Keep a Changelog, `[0.1.0]`), `LICENSE`(MIT), 다국어 ko/en, 테스트 골격. type 별 포함 의무 — 디렉토리·manifest 만 만들고 끝내지 않는다:
   - plugin: 설정 UI(`plugins/_bundled/<id>/resources/layouts/admin/plugin_settings.g7.html` + 굽기 산출 짝 `.json`, `config/settings/defaults.json`)와 훅 리스너 1개 이상(`src/Listeners/*Listener.php` + `plugin.php` 의 `getHookListeners()` 등록)을 함께 만든다 — plugin.md(아래 「plugin」 절).
   - template: 베이스 레이아웃에 전역 호스트(`toast` 노드 — 옛 문서의 Toast/ModalRoot)를 둔다. 없으면 자식 레이아웃의 `toast`·`openModal` 이 성공으로 기록되고 화면에는 아무것도 안 뜬다 — template.md(아래 「template」 절).
5. 컴포넌트 구현 (template) — `components.json` 에 선언한 컴포넌트(basic·composite·layout)마다 `src/components/{basic,composite,layout}/<Name>.tsx` 를 쓰고 폴더별 `index.ts` 에서 named export 한다. 베이스가 쓰는 전역 호스트(Toast)와 모달(Modal) composite 도 이 단계의 산출물이다. 선언만 하고 구현이 없으면 레지스트리가 그 노드를 렌더하지 않고, `templates/_bundled/<id>/src/index.ts` 가 없는 파일을 export 하면 6단계 build 가 실패한다.
6. 빌드·활성화 확인 — module/plugin: `php artisan extension:update-autoload`. template: 5단계를 마친 뒤 `npm install` → `php artisan template:build <id>` → `template.json` `externals` 정합 점검(`components.basic` 에 `icon` 이 있으면 Font Awesome `style` 항목, 웹폰트는 `type: "webfont"`, `url` 은 https 만). `php artisan <type>:install <id>` → `<type>:activate <id>` 는 DB 를 바꾸므로 기존 허가 범위 안에서만 실행하고, 실행하지 않았으면 명령을 보고에 남긴다.
8. 보고 — 생성 파일 목록·네임스페이스·실행한 명령과 출력(건수)·다음 단계 명령. template 은 타입(`admin`/`user`)과 컴포넌트 수(basic N · composite N · layout N, 선언 수와 구현 수)를 적는다. 운영자가 실제로 쓰려면 더 필요한 것(module: 엔티티별 마이그레이션·설정 UI·시더 / plugin: 설정값·외부 연동 키 / template: 레이아웃 페이지·`features` 에 맞는 클래스·컴포넌트 패턴)을 자율 식별해 적고, 실제 도메인 규모(엔티티·훅·페이지 수)에 맞춰 각 계층을 N 배 확장해야 함을 명시한다.

## 완료 조건

- references 골격 파일이 전부 존재하고 manifest 가 유효한 JSON 이며 `_bundled` 밖을 건드리지 않았다.
- (plugin) 설정 UI 소스·짝 `.json`·훅 리스너가 있고 `getHookListeners()` 에 등록됐다. (template) 베이스에 전역 호스트가 있고 선언한 컴포넌트가 전부 구현·export 됐다.
- 6단계 명령(오토로드 또는 빌드)의 실행 결과가 기록됐다. 실행하지 못한 명령은 이유와 명령을 적었다.
- 7단계 `ext:docgen --init` 을 실행하고 TODO 3곳을 채웠으며 `--check` 와 7단계에 적은 문서 정합 검사가 통과했다.
- 보고에 다음 단계와 추가 필요 항목이 있고, 이력 문서(`/auto-document`)에 명령 출력·건수를 실측으로 남겼다.

## 참조

- references/module.md(아래 「module」 절) · references/plugin.md(아래 「plugin」 절) · references/template.md(아래 「template」 절) — type 별 규정 목록·트리·파일 골격·규칙 요약
- 샘플: `modules/_bundled/gnuboard7-hello_module/`(Memo CRUD + 훅 발행), `plugins/_bundled/gnuboard7-hello_plugin/`(Action + Filter 구독), `templates/_bundled/gnuboard7-hello_admin_template/`, `templates/_bundled/gnuboard7-hello_user_template/` — [sample-extensions.md](../../extension/sample-extensions.md)
- `hidden` 플래그: 학습·내부용 확장은 manifest 에 `"hidden": true` 를 두어 관리자 UI 와 `<type>:list` 기본 출력에서 숨긴다. CLI 설치·활성화는 그대로 동작하고 `--hidden` 또는 `?include_hidden=1` 로 조회한다. 스캐폴딩 기본값은 필드 생략.
- 업그레이드 스텝: [extension-update-system.md](../../extension/extension-update-system.md) · [upgrade-step-guide.md](../../extension/upgrade-step-guide.md)
- 후속 스킬: `/validate backend` · `/validate frontend` · `/auto-document`

---

## module

### 규정 문서

`docs/extension/` 의 module-basics.md · module-routing.md · module-layouts.md · module-i18n.md · module-commands.md · permissions.md · menus.md · hooks.md · changelog-rules.md

### 디렉토리 구조

```text
modules/_bundled/<id>/
├── module.json                  # 메타데이터 SSoT
├── module.php                   # AbstractModule 상속
├── composer.json                # PSR-4 오토로딩
├── CHANGELOG.md                 # Keep a Changelog
├── LICENSE                      # MIT
├── assets/icon.svg              # 확장 아이콘(관례, 선택) — 정사각·100KB 이하
├── config/settings/defaults.json
├── database/{migrations,seeders,factories}/
├── src/
│   ├── lang/{ko,en}/{messages,validation,exceptions}.php   # 백엔드 다국어 (PHP)
│   ├── Contracts/Repositories/  # Repository 인터페이스
│   ├── Enums/  Exceptions/  Listeners/  Models/  Providers/  Repositories/  Services/
│   ├── Http/{Controllers/Admin,Requests/Admin,Resources}/
│   └── routes/web.php
├── resources/
│   ├── lang/{ko,en}.json        # 프론트엔드 다국어 (JSON)
│   ├── layouts/admin/pages/index.g7.html   # 레이아웃 소스 SSoT (.g7.html — 설치 시 서버가 JSON 컴파일)
│   └── routes.json              # 프론트엔드 라우트
└── tests/{Feature,Unit}/
```

### 파일 골격

#### module.json

```json
{
  "identifier": "[vendor-module]",
  "vendor": "[vendor]",
  "name": { "ko": "[모듈명]", "en": "[Module]" },
  "version": "0.1.0",
  "license": "MIT",
  "description": { "ko": "[모듈 설명]", "en": "[Module description]" },
  "g7_version": ">=7.0.0-alpha.1",
  "dependencies": { "modules": {}, "plugins": {} },
  "github_url": null,
  "github_changelog_url": null
}
```

#### module.php

```php
<?php

namespace Modules\[Vendor]\[Module];

use App\Extension\AbstractModule;

class Module extends AbstractModule
{
    /**
     * 모듈 역할 정의
     *
     * @return array<int, array<string, mixed>> 역할 목록
     */
    public function getRoles(): array
    {
        return [
            [
                'identifier' => '[vendor-module].manager',
                'name' => ['ko' => '[모듈명] 관리자', 'en' => '[Module] Manager'],
                'description' => ['ko' => '[모듈명] 관리 권한을 가진 역할', 'en' => 'Role with [module] management permissions'],
            ],
        ];
    }

    /**
     * 모듈 권한 목록 반환 (계층형 구조: categories → permissions)
     *
     * @return array<string, mixed> 권한 정의
     */
    public function getPermissions(): array
    {
        return [
            'name' => ['ko' => '[모듈명]', 'en' => '[Module]'],
            'description' => ['ko' => '[모듈명] 모듈 권한', 'en' => '[Module] module permissions'],
            'categories' => [
                [
                    'identifier' => '[entities]',
                    'resource_route_key' => '[entity]',
                    'owner_key' => 'created_by',
                    'name' => ['ko' => '[엔티티] 관리', 'en' => '[Entity] Management'],
                    'description' => ['ko' => '[엔티티] 관리 권한', 'en' => '[Entity] management permissions'],
                    'permissions' => [
                        ['action' => 'read', 'name' => ['ko' => '[엔티티] 조회', 'en' => 'View [Entity]'], 'description' => ['ko' => '[엔티티] 목록 및 상세 조회', 'en' => 'View [entity] list and details'], 'type' => 'admin', 'roles' => ['admin', '[vendor-module].manager']],
                        ['action' => 'create', 'name' => ['ko' => '[엔티티] 생성', 'en' => 'Create [Entity]'], 'description' => ['ko' => '새 [엔티티] 생성', 'en' => 'Create new [entity]'], 'type' => 'admin', 'roles' => ['admin', '[vendor-module].manager']],
                        ['action' => 'update', 'name' => ['ko' => '[엔티티] 수정', 'en' => 'Update [Entity]'], 'description' => ['ko' => '[엔티티] 내용 수정', 'en' => 'Update [entity] content'], 'type' => 'admin', 'roles' => ['admin', '[vendor-module].manager']],
                        ['action' => 'delete', 'name' => ['ko' => '[엔티티] 삭제', 'en' => 'Delete [Entity]'], 'description' => ['ko' => '[엔티티] 삭제', 'en' => 'Delete [entity]'], 'type' => 'admin', 'roles' => ['admin']],
                    ],
                ],
            ],
        ];
    }

    /**
     * 모듈 설치 시 실행할 시더 목록 반환
     *
     * @return array<class-string<\Illuminate\Database\Seeder>> 시더 클래스 목록
     */
    public function getSeeders(): array
    {
        return [];
    }

    /**
     * 훅 리스너 목록 반환
     *
     * @return array<class-string> 리스너 클래스 목록
     */
    public function getHookListeners(): array
    {
        return [];
    }

    /**
     * 스토리지 디스크 설정 반환
     *
     * @return string 디스크 이름
     */
    public function getStorageDisk(): string
    {
        return config('[vendor-module].attachment.disk', 'modules');
    }

    /**
     * 관리자 메뉴 정의
     *
     * @return array<int, array<string, mixed>> 메뉴 목록
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'name' => ['ko' => '[모듈명]', 'en' => '[Module]'],
                'slug' => '[vendor-module]',
                'url' => '/admin/[module-path]',
                'icon' => 'fas fa-folder',
                'order' => 100,
                'permission' => '[vendor-module].[entities].read',
            ],
        ];
    }
}
```

#### composer.json

```json
{
  "name": "modules/[vendor-module]",
  "description": "[Module description] for G7",
  "type": "library",
  "version": "0.1.0",
  "license": "MIT",
  "require": { "php": "^8.2" },
  "autoload": {
    "psr-4": {
      "Modules\\[Vendor]\\[Module]\\": "src/",
      "Modules\\[Vendor]\\[Module]\\Database\\Seeders\\": "database/seeders/",
      "Modules\\[Vendor]\\[Module]\\Database\\Factories\\": "database/factories/"
    }
  },
  "autoload-dev": {
    "psr-4": { "Modules\\[Vendor]\\[Module]\\Tests\\": "tests/" }
  }
}
```

#### config/settings/defaults.json

```json
{
  "_meta": { "version": "1.0.0", "description": "[모듈명] 환경설정" },
  "defaults": {},
  "frontend_schema": {}
}
```

#### resources/routes.json

```json
{
  "admin": { "prefix": "[module]", "middleware": ["auth:sanctum", "admin"], "routes": [] }
}
```

#### src/routes/web.php

```php
<?php

use Illuminate\Support\Facades\Route;

// API 라우트는 resources/routes.json 에서 자동 등록된다. 추가 웹 라우트만 여기에 정의한다.
```

#### src/lang/ko/messages.php · validation.php · exceptions.php (en 동일)

```php
<?php

return [
    // messages: 일반 메시지 / validation: 검증 메시지 / exceptions: Custom Exception 메시지
];
```

#### resources/lang/ko.json

```json
{
  "title": "[모듈명]",
  "description": "[모듈 설명]",
  "common": { "save": "저장", "cancel": "취소", "delete": "삭제", "edit": "수정", "create": "등록", "search": "검색", "confirm": "확인" }
}
```

#### resources/lang/en.json

```json
{
  "title": "[Module]",
  "description": "[Module description]",
  "common": { "save": "Save", "cancel": "Cancel", "delete": "Delete", "edit": "Edit", "create": "Create", "search": "Search", "confirm": "Confirm" }
}
```

#### CHANGELOG.md

```markdown
## Changelog

All notable changes to this module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

### [0.1.0] - YYYY-MM-DD

#### Added

- 초기 모듈 생성
```

### 규칙 요약

- `module.json` 이 메타데이터 SSoT — identifier·version 을 코드에 하드코딩하지 않는다.
- 루트 `composer.json` 에 모듈을 하드코딩하지 않는다. `/modules` 스캔으로 자동 발견되며 `php artisan extension:update-autoload` 로 갱신한다.
- `AbstractModule` 상속. PSR-4 는 `src/`·`database/seeders`·`database/factories`·`tests/` 까지 매핑한다.
- `getPermissions()` 는 계층형(categories → permissions → action/name/type/roles), `getAdminMenus()` 는 slug/url/icon/order/permission 필드.
- 백엔드 다국어는 `src/lang/`(PHP), 프론트엔드 다국어는 `resources/lang/`(JSON). Custom Exception 메시지는 `__()`.
- Service 는 Repository 인터페이스를 주입한다(구현체 직접 타입힌트 금지).
- `CHANGELOG.md` 필수. 작업은 `_bundled` 에서만 하고 활성 디렉토리는 직접 수정하지 않는다.
- 샘플 `modules/_bundled/gnuboard7-hello_module/` — 계층별 1파일 예(Service·Repository·Controller·Request·Resource·Listener·Migration·Seeder·Factory·Layout·Test). 실제 모듈이 N 엔티티면 해당 계층을 N 배로 확장한다.

---

## plugin

### 규정 문서

`docs/extension/` 의 plugin-development.md · hooks.md · module-settings.md · changelog-rules.md

### 디렉토리 구조

```text
plugins/_bundled/<id>/
├── plugin.json                  # 메타데이터 SSoT
├── plugin.php                   # AbstractPlugin 상속 — 반드시 루트 (src/ 아님)
├── composer.json                # PSR-4 (src/ + ./)
├── CHANGELOG.md                 # Keep a Changelog
├── LICENSE                      # MIT
├── assets/icon.svg              # 확장 아이콘(관례, 선택) — 정사각·100KB 이하
├── config/settings/defaults.json      # 설정 기본값 + frontend_schema
├── resources/
│   ├── extensions/              # layout_extensions JSON
│   ├── lang/{ko,en}.json        # 프론트엔드 다국어 (JSON 만 — 백엔드 PHP 다국어 없음)
│   ├── layouts/admin/plugin_settings.g7.html  # 설정 UI 소스 SSoT (유일한 완전 레이아웃) — 필수
│   └── layouts/admin/plugin_settings.json     # 위 소스의 굽기 산출(layout:decompile --sync) — 필수
├── src/
│   ├── Listeners/[Name]Listener.php  # 훅 리스너 — 필수, plugin.php getHookListeners() 에 등록
│   └── Services/
│   └── routes/web.php           # 웹 라우트 (선택)
└── tests/
    ├── PluginTestCase.php
    └── {Feature,Unit}/
```

### 파일 골격

#### plugin.json

```json
{
  "identifier": "[vendor-plugin]",
  "vendor": "[vendor]",
  "name": { "ko": "[플러그인명]", "en": "[Plugin]" },
  "version": "0.1.0",
  "license": "MIT",
  "description": { "ko": "[플러그인 설명]", "en": "[Plugin description]" },
  "g7_version": ">=7.0.0-alpha.1",
  "dependencies": { "modules": {}, "plugins": {} },
  "github_url": null,
  "github_changelog_url": null,
  "assets": {
    "js": { "entry": "resources/js/index.ts", "output": "dist/js/plugin.iife.js" },
    "handlers": false
  },
  "loading": { "strategy": "global", "priority": 100 }
}
```

#### plugin.php (루트)

```php
<?php

namespace Plugins\[Vendor]\[Plugin];

use App\Extension\AbstractPlugin;

/**
 * [플러그인 설명]
 */
class Plugin extends AbstractPlugin
{
    /**
     * 플러그인 메타데이터 반환
     *
     * @return array<string, mixed> 메타데이터
     */
    public function getMetadata(): array
    {
        return ['author' => '[Vendor]', 'license' => 'MIT', 'homepage' => '', 'keywords' => []];
    }

    /**
     * 플러그인 의존성 반환
     *
     * @return array<string, array<string, string>> 의존성
     */
    public function getDependencies(): array
    {
        return ['modules' => [], 'plugins' => []];
    }

    /**
     * 플러그인 설정 스키마 반환
     *
     * @return array<string, mixed> 설정 스키마
     */
    public function getSettingsSchema(): array
    {
        return [
            // 'setting_key' => [
            //     'type' => 'string', 'default' => '', 'required' => false,
            //     'label' => ['ko' => '설정명', 'en' => 'Setting Name'],
            //     'hint' => ['ko' => '설정 설명', 'en' => 'Setting description'],
            // ],
        ];
    }

    /**
     * 플러그인 설정 기본값 반환
     *
     * @return array<string, mixed> 기본 설정값
     */
    public function getConfigValues(): array
    {
        return [];
    }

    /**
     * 훅 리스너 목록 반환 — 플러그인 리스너는 자동 발견되지 않으므로 여기 올린 것만 등록된다
     *
     * @return array<class-string> 리스너 클래스 목록
     */
    public function getHookListeners(): array
    {
        return [
            \Plugins\[Vendor]\[Plugin]\Listeners\[Name]Listener::class,
        ];
    }

    /**
     * 플러그인이 발행하는 훅 선언 (AbstractPlugin::getHooks) — 구독은 리스너의 getSubscribedHooks() 가 맡는다
     *
     * @return array<int, array<string, mixed>> 훅 정의 배열
     */
    public function getHooks(): array
    {
        return [
            // [
            //     'name' => '[vendor-plugin].hook_name',
            //     'type' => 'action',  // 또는 'filter'
            //     'description' => ['ko' => '훅 설명', 'en' => 'Hook description'],
            //     'parameters' => ['param_name' => 'type - 설명'],
            // ],
        ];
    }
}
```

#### composer.json

`plugin.php` 가 루트에 있으므로 autoload 에 `"./"` 를 포함한다.

```json
{
  "name": "plugins/[vendor-plugin]",
  "description": "[Plugin description] for G7",
  "type": "library",
  "authors": [{ "name": "[vendor]", "email": "" }],
  "require": { "php": "^8.2" },
  "autoload": {
    "psr-4": { "Plugins\\[Vendor]\\[Plugin]\\": ["src/", "./"] }
  }
}
```

#### src/Listeners/[Name]Listener.php

구독은 `HookListenerInterface::getSubscribedHooks()`(static, `'훅.이름' => [옵션]` 맵)로 선언한다. 인터페이스 원본: `app/Contracts/Extension/HookListenerInterface.php`. 실물: `plugins/_bundled/gnuboard7-hello_plugin/src/Listeners/`.

```php
<?php

namespace Plugins\[Vendor]\[Plugin]\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * [리스너 설명]
 */
class [Name]Listener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션(method·priority·type·sync)
     */
    public static function getSubscribedHooks(): array
    {
        return [
            '[vendor-module].[entity].after_create' => ['method' => 'handleCreated', 'priority' => 10],
            '[vendor-module].[entity].filter_list_data' => ['method' => 'filterList', 'priority' => 10, 'type' => 'filter'],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
    }

    /**
     * 생성 직후 처리
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handleCreated(...$args): void
    {
    }

    /**
     * 목록 데이터 가공 — Filter 는 값을 반환한다
     *
     * @param  array  $data  앞선 필터의 반환값
     * @return array 가공한 데이터
     */
    public function filterList(array $data): array
    {
        return $data;
    }
}
```

#### resources/layouts/admin/plugin_settings.g7.html

설정 UI 는 샘플 `plugins/_bundled/gnuboard7-hello_plugin/resources/layouts/admin/plugin_settings.g7.html` 을 복사해 식별자·필드를 치환한다(데이터소스 `GET /api/admin/plugins/{{route.identifier}}/settings` · 저장 `PUT` + `onError` · 권한 `core.plugins.update`). 작성 뒤 짝 `.json` 을 굽는다.

```bash
php artisan layout:decompile plugins/_bundled/[vendor-plugin]/resources/layouts/admin/plugin_settings.g7.html --sync
```

#### config/settings/defaults.json

```json
{
  "_meta": { "version": "1.0.0", "description": "[플러그인명] 환경설정" },
  "defaults": {},
  "frontend_schema": {}
}
```

#### resources/lang/ko.json

```json
{
  "title": "[플러그인명]",
  "description": "[플러그인 설명]",
  "settings": { "title": "[플러그인명] 설정", "description": "[플러그인명] 환경설정을 관리합니다." }
}
```

#### resources/lang/en.json

```json
{
  "title": "[Plugin]",
  "description": "[Plugin description]",
  "settings": { "title": "[Plugin] Settings", "description": "Manage [plugin] settings." }
}
```

#### src/routes/web.php

```php
<?php

use Illuminate\Support\Facades\Route;

// 콜백 URL 등 웹 라우트가 필요한 경우 여기에 정의한다.
```

#### tests/PluginTestCase.php

```php
<?php

namespace Plugins\[Vendor]\[Plugin]\Tests;

use Tests\TestCase;

abstract class PluginTestCase extends TestCase
{
    // 플러그인 테스트 공통 설정
}
```

#### CHANGELOG.md

```markdown
## Changelog

All notable changes to this plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

### [0.1.0] - YYYY-MM-DD

#### Added

- 초기 플러그인 생성
```

### 규칙 요약

- `plugin.json` 이 메타데이터 SSoT — identifier·version 을 코드에 하드코딩하지 않는다.
- `plugin.php` 는 루트에 두고 `AbstractPlugin` 을 상속한다. `composer.json` autoload 에 `"./"` 를 포함한다.
- 다른 확장과는 훅(코어 계약 훅·상대 확장이 문서화한 훅 키)으로 협력하고, 이름으로 참조(식별자·네임스페이스·API 경로·번역 키)하는 확장은 `plugin.json` `dependencies` 에 선언한다 — 책임 경계.
- 완전한 레이아웃은 등록하지 않는다 — `layout_extensions` 만 사용(예외: `plugin_settings`).
- 설정 UI 와 훅 리스너는 골격의 필수 산출물이다 — 빈 `getHookListeners()` 와 설정 화면 없는 플러그인을 완료로 보고하지 않는다.
- 설정 UI 소스 SSoT 는 `plugin_settings.g7.html` 이다. `AbstractPlugin::getSettingsLayout()` 은 `.g7.html` → 짝 `.json` 순으로 실재하는 첫 파일을 읽지만(`layoutSourceCandidates`), 편집기·검증 소비처는 짝 `.json` 을 읽으므로 굽기(`layout:decompile --sync`)를 생략하지 않는다.
- 다국어는 `resources/lang/*.json` 만 있다(백엔드 PHP 다국어 없음).
- `Storage::disk()` 직접 호출 금지 → `StorageInterface`.
- Filter 훅 리스너는 `getSubscribedHooks()` 옵션에 `'type' => 'filter'` 를 명시한다(미지정 시 반환값 무시). `Plugin::getHooks()` 는 발행 훅 선언이지 구독이 아니다.
- 설정 저장 `apiCall` 에는 `onError` 를 둔다 — 저장 실패가 화면에 보이지 않으면 사용자는 성공한 줄 안다. 오류 바인딩은 `{{error.message}}`·`{{error.errors}}`(`$error` 접두사는 엔진이 채우지 않는다).
- `CHANGELOG.md` 필수. 작업은 `_bundled` 에서만 하고 활성 디렉토리는 직접 수정하지 않는다.
- 샘플 `plugins/_bundled/gnuboard7-hello_plugin/` — Listener(Action + Filter)·Service·Settings Schema·Layout Extension·Test 각 1파일. 여러 훅을 구독하면 Listener 를 N 배로 확장한다.

---

## template

### 규정 문서

`docs/extension/` 의 template-basics.md · template-routing.md · template-workflow.md · template-security.md · changelog-rules.md, `docs/frontend/` 의 components.md · layout-testing.md

### 디렉토리 구조

```text
templates/_bundled/<id>/
├── template.json                # 메타데이터 SSoT (type: "admin" | "user")
├── routes.json                  # 프론트엔드 라우트
├── components.json              # 컴포넌트 레지스트리
├── CHANGELOG.md · LICENSE
├── package.json · vite.config.ts · vitest.config.ts · tsconfig.json
├── lang/{ko,en}.json
├── layouts/
│   ├── _admin_base.g7.html 또는 _user_base.g7.html  # 베이스 (data-slot)
│   ├── admin_dashboard.g7.html 또는 home.g7.html # 초기 페이지
│   └── errors/{401,403,404,500,503,maintenance}.json   # 6종 필수
├── src/index.ts · src/test-setup.ts
├── src/components/{basic,composite,layout}/  # <Name>.tsx + 폴더별 index.ts (SKILL 5단계 컴포넌트 구현)
├── assets/{css,fonts,images}/
├── assets/icon.svg                # 확장 아이콘(관례, 선택) — 정사각·100KB 이하
└── __tests__/layouts/           # 레이아웃 렌더링 테스트 (createLayoutTest)
```

### Admin vs User

| 항목 | Admin | User |
| --- | --- | --- |
| type | `"admin"` | `"user"` |
| 라우트 접두사 · auth_required 기본 | `*/admin/*` · `true` | `/` · `false` |
| 버전 히스토리 | 미지원 (레이아웃 읽기 전용) | 지원 (편집 가능) |
| 베이스 레이아웃 | `_admin_base.g7.html` (sidebar + header + content + footer) | `_user_base.g7.html` (header + content + footer) |
| 초기 페이지 | `admin_dashboard.json` | `home.json` |
| 필수 composite | AdminHeader, AdminSidebar, AdminFooter, Modal, Toast | Header, Footer, Modal, Toast |
| features | 없음 | `dark_mode`, `responsive`, `multi_language`, `multi_currency` |

### 파일 골격

#### template.json (admin)

```json
{
  "identifier": "[vendor-template]",
  "vendor": "[vendor]",
  "name": { "ko": "[템플릿명]", "en": "[Template]" },
  "version": "0.1.0",
  "license": "MIT",
  "description": { "ko": "[템플릿 설명]", "en": "[Template description]" },
  "type": "admin",
  "locales": ["ko", "en"],
  "author": { "name": "[vendor]", "email": "", "url": "" },
  "release_date": "YYYY-MM-DD",
  "g7_version": ">=7.0.0-alpha.1",
  "dependencies": { "modules": {}, "plugins": {} },
  "assets": { "css": ["assets/css/main.css"], "js": ["dist/js/components.iife.js"], "fonts": [], "images": [] },
  "components": {
    "basic": ["a", "button", "checkbox", "div", "form", "h1", "h2", "h3", "icon", "img", "input", "label", "li", "nav", "p", "section", "select", "span", "table", "tbody", "td", "textarea", "th", "thead", "tr", "ul"],
    "composite": ["admin-header", "admin-sidebar", "admin-footer", "data-grid", "modal", "toast", "pagination", "page-header", "loading-spinner", "empty-state", "breadcrumb", "card", "dropdown"],
    "layout": ["container", "flex", "grid", "section"]
  },
  // 아이콘은 assets/icon.svg 관례로 자동 감지된다. 다른 경로를 쓸 때만 "icon": "assets/brand/mark.svg" 를 적는다.
  "error_config": {
    "layouts": { "401": "401", "403": "403", "404": "404", "500": "500", "503": "503", "maintenance": "maintenance" }
  },
  "externals": [
    { "id": "fontawesome", "type": "style", "asset": "vendor/font-awesome/6.4.0/css/all.inlined.css" }
  ],
  "github_url": null,
  "github_changelog_url": null
}
```

`externals` 는 최초 HTML 문서에 정적으로 필요한 스타일·웹폰트·스크립트 선언이다. `components.basic` 에 `icon` 을 등록하면 Font Awesome `style` 항목이 있어야 아이콘이 표시된다(Icon 을 안 쓰면 `[]` 또는 생략). 웹폰트는 `"type": "webfont"`. `asset` 은 템플릿 `dist/` 이하 상대 경로이며 라이브러리는 `dist/vendor/{lib}/{version}/` 에 `LICENSE` 와 함께 동봉한다 — 제3자 CDN `url` 은 폐쇄망·광고차단기에서 아이콘이 사라지므로 쓰지 않는다. 자체 제공이 불가능한 서비스 SDK 만 예외이고 `trusted_script_hosts`·`trusted_script_hosts_reason` 을 manifest 에 함께 둔다. 상세: docs/extension/template-basics.md "외부 리소스 (externals)".

#### template.json (user) — admin 과 다른 부분

```json
{
  "type": "user",
  "components": {
    "basic": ["a", "button", "checkbox", "div", "form", "h1", "h2", "h3", "h4", "icon", "img", "input", "label", "li", "nav", "p", "section", "select", "span", "table", "tbody", "td", "textarea", "th", "thead", "tr", "ul"],
    "composite": ["header", "footer", "mobile-nav", "modal", "toast", "pagination", "loading-spinner", "empty-state", "breadcrumb", "card", "dropdown", "search-bar"],
    "layout": ["container", "flex", "grid", "section"]
  },
  "features": { "dark_mode": true, "responsive": true, "multi_language": true, "multi_currency": false }
}
```

`externals` 는 admin 과 동일하게 작성한다(공통 필드).

#### routes.json (admin)

```json
{
  "version": "1.0.0",
  "routes": [
    { "path": "*/admin", "redirect": "/admin/dashboard", "auth_required": true, "meta": { "title": "$t:common.admin" } },
    { "path": "*/admin/login", "layout": "admin_login", "auth_required": false, "meta": { "title": "$t:common.login" } },
    { "path": "*/admin/dashboard", "layout": "admin_dashboard", "auth_required": true, "meta": { "title": "$t:common.dashboard" } },
    { "path": "*/404", "layout": "errors/404", "auth_required": false, "meta": { "title": "$t:common.not_found" } },
    { "path": "*/403", "layout": "errors/403", "auth_required": false, "meta": { "title": "$t:common.forbidden" } },
    { "path": "*/500", "layout": "errors/500", "auth_required": false, "meta": { "title": "$t:common.server_error" } }
  ]
}
```

#### routes.json (user)

```json
{
  "version": "1.0.0",
  "routes": [
    { "path": "/", "layout": "home", "auth_required": false, "meta": { "title": "$t:common.home" } },
    { "path": "/login", "layout": "auth/login", "auth_required": false, "guest_only": true, "meta": { "title": "$t:common.login" } },
    { "path": "/register", "layout": "auth/register", "auth_required": false, "guest_only": true, "meta": { "title": "$t:common.register" } },
    { "path": "/404", "layout": "errors/404", "auth_required": false, "meta": { "title": "$t:common.not_found" } },
    { "path": "/403", "layout": "errors/403", "auth_required": false, "meta": { "title": "$t:common.forbidden" } },
    { "path": "/500", "layout": "errors/500", "auth_required": false, "meta": { "title": "$t:common.server_error" } }
  ]
}
```

#### layouts/_admin_base.g7.html

> 소스는 `.g7.html`(layout-markup.md), 설치 시 서버가 JSON 으로 컴파일해 DB(`template_layouts`)에 등록한다(키 계약: layout-json.md).
> **슬롯은 실제 노드의 `data-slot`** — `type: "slot"` 자리표시 노드 + 별도 `slots` 섹션은 정본이 아니고 강검증도 통과하지 못한다.
> **스캐폴드는 기본 컴포넌트로 조립한다** — `components.composite` 에 선언해도 React 구현이 없으면 `registry.getComponent()` 가 null 이라 그 노드가 통째로 렌더되지 않는다. 구현 뒤 태그(`<admin-sidebar/>`)로 교체한다.
> **전역 호스트는 베이스의 필수 노드다** — `toast` 핸들러는 `_global.toasts` 에 push 만 하고 그리는 것은 `toast` 호스트 노드다(layout-json-inheritance.md). 호스트가 없으면 자식 레이아웃의 토스트가 success 로 기록되고 화면에는 안 뜬다. 호스트가 쓰는 `Toast`·`Modal` composite 는 SKILL 5단계에서 구현한다(참고 구현: `templates/_bundled/sirsoft-admin_basic/src/components/composite/{Toast,Modal}.tsx`).

```html
<layout name="_admin_base" version="1.0.0">
  <meta title="$t:admin.base_layout_title" :is_base="true"/>
  <!-- 전역 호스트 — 자식 레이아웃의 toast 핸들러가 여기로 그려진다 -->
  <toast data-ref="global_toast" toasts="{{_global.toasts}}" position="bottom-center" :duration="5000"/>
  <div data-ref="admin_shell" class="flex min-h-screen bg-gray-100 dark:bg-gray-900">
    <div data-ref="sidebar" class="w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 p-4">
      <h2 data-ref="sidebar_title" class="text-lg font-bold mb-4">$t:admin.sidebar.title</h2>
      <a data-ref="nav_dashboard" href="/admin" class="block px-3 py-2 rounded hover:bg-gray-100 dark:hover:bg-gray-700">$t:admin.sidebar.dashboard</a>
    </div>
    <div data-ref="main_column" class="flex-1 flex flex-col">
      <div data-ref="header" class="flex items-center h-14 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6">
        <span data-ref="header_title" class="text-sm font-medium">$t:admin.header.title</span>
      </div>
      <!-- 자식 레이아웃이 끼워지는 자리 -->
      <div data-ref="main_content" data-slot="content" class="flex-1 p-6"/>
    </div>
  </div>
</layout>
```

#### layouts/_user_base.g7.html

```html
<layout name="_user_base" version="1.0.0">
  <meta title="$t:user.base_layout_title" description="$t:user.base_layout_description" :is_base="true"/>
  <!-- 전역 호스트 — 자식 레이아웃의 toast 핸들러가 여기로 그려진다 -->
  <toast data-ref="global_toast" toasts="{{_global.toasts}}" position="bottom-center" :duration="5000"/>
  <div data-ref="user_layout_root" class="min-h-screen flex flex-col bg-white dark:bg-gray-900">
    <div data-ref="site_header" class="border-b border-gray-200 dark:border-gray-700 px-4 py-3 flex justify-between">
      <a href="/" class="text-lg font-bold">$t:user.header.home_link</a>
    </div>
    <!-- 자식 레이아웃이 끼워지는 자리 -->
    <div data-ref="main_content" data-slot="content" class="flex-1 max-w-3xl w-full mx-auto px-4 py-6"/>
    <div data-ref="site_footer" class="border-t border-gray-200 dark:border-gray-700 px-4 py-3 text-sm">$t:user.footer.copyright</div>
  </div>
</layout>
```

베이스가 아닌 독립 레이아웃(extends 없음, 예: 로그인)은 위 `global_toast` 블록을 그대로 복사해 직접 마운트한다 — 베이스의 호스트를 물려받지 않는다.

#### layouts/errors/404.g7.html (401·403·500·503·maintenance 동일 패턴)

자식 레이아웃은 베이스가 `data-slot` 으로 연 자리를 `<slot name="...">` 으로 채운다. `extends` 의 `_[type]_base` 는 `_admin_base` 또는 `_user_base` 로 바꾼다.

```html
<layout name="errors/404" version="1.0.0" extends="_[type]_base">
  <meta title="$t:errors.404.title" :is_error_layout="true" :error_code="404"/>
  <slot name="content">
    <div data-ref="error_container" class="flex flex-col items-center justify-center min-h-[60vh] text-center px-4">
      <icon data-ref="error_icon" name="fa-solid fa-triangle-exclamation" class="text-6xl text-gray-400 dark:text-gray-500 mb-6"/>
      <h1 data-ref="error_code" class="text-4xl font-bold text-gray-800 dark:text-gray-200 mb-2">404</h1>
      <p data-ref="error_message" class="text-lg text-gray-600 dark:text-gray-400 mb-8">$t:common.page_not_found</p>
      <button data-ref="go_home" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600">
        <on type="click">
          <navigate path="/"/>
        </on>
        <span>$t:common.go_back</span>
      </button>
    </div>
  </slot>
</layout>
```

실물 최소 선례: `templates/_bundled/gnuboard7-hello_{admin,user}_template/layouts/` (베이스·에러 전 6종)

#### components.json

`ComponentRegistry` 가 읽는 매니페스트다. `components.{basic,composite,layout}` 은 **배열**이고 항목마다 `name`·`type` 이 필수다(객체 매핑 `{ "div": "Div" }` 은 로드 단계에서 `MANIFEST_INVALID` 로 거부된다). 레지스트리는 번들의 named export `module[name]` 을 찾아 등록하고 없으면 경고 후 건너뛴다 — 선언한 이름은 전부 번들 진입점(`templates/_bundled/<id>/src/index.ts`)에서 export 돼야 한다. 선언 전량 형태: `templates/_bundled/gnuboard7-hello_admin_template/components.json`. 아래는 항목 형태만 보인 것이다 — 골격 레이아웃이 쓰는 태그(`div`·`a`·`h1`·`h2`·`p`·`span`·`button`·`icon` 등, `template.json` `components.basic` 목록)는 전부 basic 으로 선언하고 구현한다.

```json
{
  "templateId": "[vendor-template]",
  "version": "0.1.0",
  "components": {
    "basic": [
      { "name": "Div", "type": "basic", "description": "HTML div wrapper", "path": "src/components/basic/Div.tsx", "props": { "className": { "type": "string", "required": false, "description": "사용자 정의 클래스" } } }
    ],
    "composite": [
      { "name": "Toast", "type": "composite", "description": "전역 토스트 호스트", "path": "src/components/composite/Toast.tsx", "props": { "toasts": { "type": "array", "required": true, "description": "토스트 목록" } } },
      { "name": "Modal", "type": "composite", "description": "modals 섹션 모달", "path": "src/components/composite/Modal.tsx", "props": {} }
    ],
    "layout": []
  }
}
```

#### lang/ko.json · lang/en.json

`common` 아래 같은 키를 둔다: home, login, logout, register, dashboard, admin, save, cancel, delete, edit, create, search, confirm, back, go_back, not_found, page_not_found, forbidden, server_error, unauthorized, service_unavailable, maintenance.

```json
{ "common": { "home": "홈", "login": "로그인", "dashboard": "대시보드", "go_back": "돌아가기", "not_found": "페이지를 찾을 수 없습니다", "page_not_found": "요청하신 페이지를 찾을 수 없습니다.", "forbidden": "접근 권한이 없습니다", "server_error": "서버 오류", "unauthorized": "인증이 필요합니다", "service_unavailable": "서비스 점검 중", "maintenance": "점검 중" } }
json
{ "common": { "home": "Home", "login": "Login", "dashboard": "Dashboard", "go_back": "Go Back", "not_found": "Page Not Found", "page_not_found": "The page you are looking for could not be found.", "forbidden": "Access Denied", "server_error": "Server Error", "unauthorized": "Authentication Required", "service_unavailable": "Service Unavailable", "maintenance": "Under Maintenance" } }
```

#### src/index.ts · src/components/*/index.ts

템플릿은 컴포넌트를 스스로 등록하지 않는다 — 코어가 `components.json` 을 읽고 이 번들의 export 에서 구현체를 찾는다. 그래서 이 파일은 **구현한 파일만** 다시 내보낸다. 존재하지 않는 파일을 export 하면 `template:build` 가 실패하므로, 폴더별 `index.ts` 에 SKILL 5단계에서 실제로 만든 컴포넌트만 한 줄씩 추가한다.

```typescript
// src/index.ts — 빌드 시 IIFE 로 출력된다
export * from './components/basic';
export * from './components/composite';
// layout 컴포넌트를 구현했으면: export * from './components/layout';
typescript
// src/components/basic/index.ts — 구현한 파일마다 한 줄
export { Div, type DivProps } from './Div';
typescript
// src/components/composite/index.ts
export { Toast } from './Toast';
export { Modal } from './Modal';
tsx
// src/components/basic/Div.tsx — basic 컴포넌트 공통 형태(named export + forwardRef)
import React from 'react';

export interface DivProps extends React.HTMLAttributes<HTMLDivElement> {}

/**
 * 기본 div 컴포넌트
 */
export const Div = React.forwardRef<HTMLDivElement, DivProps>(({ children, className = '', ...props }, ref) => (
  <div ref={ref} className={className} {...props}>
    {children}
  </div>
));

Div.displayName = 'Div';
```

`Toast`·`Modal` 은 참고 구현(`sirsoft-admin_basic/src/components/composite/`)을 보고 이 템플릿의 스타일로 작성한다. 컴포넌트마다 `src/**/__tests__/` 에 렌더 테스트를 둔다.

#### package.json

```json
{
  "name": "[vendor-template]",
  "version": "0.1.0",
  "private": true,
  "scripts": { "dev": "vite", "build": "vite build", "test": "vitest", "test:run": "vitest run" },
  "dependencies": { "react": "^18.2.0", "react-dom": "^18.2.0" },
  "devDependencies": {
    "@testing-library/jest-dom": "^6.0.0",
    "@testing-library/react": "^14.0.0",
    "@types/react": "^18.2.0",
    "@types/react-dom": "^18.2.0",
    "@vitejs/plugin-react": "^4.0.0",
    "jsdom": "^24.0.0",
    "typescript": "^5.0.0",
    "vite": "^5.0.0",
    "vitest": "^1.0.0"
  }
}
```

#### vite.config.ts

```typescript
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

export default defineConfig({
  plugins: [react()],
  build: {
    lib: { entry: resolve(__dirname, 'src/index.ts'), name: '[VendorTemplate]Components', formats: ['iife'], fileName: () => 'components.iife.js' },
    outDir: 'dist/js',
    rollupOptions: { external: ['react', 'react-dom'], output: { globals: { react: 'React', 'react-dom': 'ReactDOM' } } },
  },
});
```

#### vitest.config.ts

```typescript
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  test: { environment: 'jsdom', globals: true, setupFiles: ['./src/test-setup.ts'] },
});
```

#### src/test-setup.ts

`vitest.config.ts` 의 `setupFiles` 가 읽는 파일이므로 함께 만든다.

```typescript
import '@testing-library/jest-dom/vitest';
```

#### tsconfig.json

```json
{
  "compilerOptions": {
    "target": "ES2020", "lib": ["ES2020", "DOM", "DOM.Iterable"], "module": "ESNext", "moduleResolution": "bundler",
    "jsx": "react-jsx", "strict": true, "esModuleInterop": true, "skipLibCheck": true,
    "forceConsistentCasingInFileNames": true, "declaration": true, "outDir": "dist"
  },
  "include": ["src"],
  "exclude": ["node_modules", "dist", "__tests__"]
}
```

#### CHANGELOG.md

```markdown
## Changelog

All notable changes to this template will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

### [0.1.0] - YYYY-MM-DD

#### Added

- 초기 템플릿 생성
```

### 규칙 요약

- `template.json` 이 메타데이터 SSoT. `type` 은 `admin`·`user` 중 하나(필수). `error_config` 에 에러 레이아웃 6종 매핑.
- HTML 태그 직접 사용 금지 → 기본 컴포넌트(Div, Button, Span). `components.json` 에 등록된 컴포넌트만 레이아웃에서 쓴다.
- 다크 모드는 `dark:` variant 를 쌍으로. `features.dark_mode`·`responsive` 를 선언하면 그 동작에 필요한 클래스·컴포넌트 패턴까지 골격에 둔다.
- 빌드 출력은 IIFE(`dist/js/components.iife.js`). build 는 SKILL 5단계(컴포넌트 구현) 뒤에 돌린다 — `components.json` 선언·진입점 export·실제 파일 세 목록이 일치해야 한다. `__tests__/layouts/` 에 레이아웃 렌더링 테스트를 쓴다.
- 베이스 레이아웃에 전역 호스트(`toast`)를 둔다. 독립 레이아웃도 같은 블록을 직접 둔다.
- Font Awesome 6.x 는 `fa-solid` 접두사(`fas` 금지). `icon` 등록 시 `externals` Font Awesome style 항목 필수.
- `CHANGELOG.md` 필수. 작업은 `_bundled` 에서만 하고 활성 디렉토리는 직접 수정하지 않는다.
- 샘플 `templates/_bundled/gnuboard7-hello_admin_template/`(Basic 8 + 에러 6종), `gnuboard7-hello_user_template/`(홈 + Memo 리스트). 페이지가 N 개면 `layouts/` 를 N 배로 확장한다.
