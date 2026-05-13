# 언어팩 시스템 (Language Packs)

## TL;DR (5초 요약)

```text
1. 코어/번들 확장의 lang/{ko,en}/ 는 가상 보호 행으로 자동 노출 (DB 없이 항상 active+protected, 수정 불가)
2. lang-packs/_bundled/ 패키지(ja, fr 등)는 비보호 — 사용자가 install/uninstall/update/activate/deactivate 자유
3. 활성화 시 의존성 + 버전 호환성 자동 검사 (호스트 확장 active + requires.target_version 제약)
4. 호스트 확장 비활성화 시 종속 언어팩 자동 비활성화. 재활성화 시 모달로 "다음 언어팩도 활성화 하시겠습니까" 질의
5. 업데이트 우선순위: GitHub 1순위 + bundled 폴백 (force 시 bundled 우선) — 모듈/플러그인 패턴 동일
```

## 개요

G7 의 언어팩 시스템은 모듈/플러그인/템플릿 시스템과 동일한 멘탈 모델을 재사용한 **설치 가능한 다국어 아티팩트** 입니다. `lang-packs/` 디렉토리가 쓰기 가능 영역으로 분리되어 있어 코어 업데이트 시에도 언어팩이 유실되지 않습니다.

## 디렉토리 구조

```
lang-packs/
├── _bundled/                                   # Git 추적 (번들 메타데이터)
│   ├── g7-core-ko/
│   │   └── language-pack.json
│   ├── g7-core-en/
│   ├── g7-template-sirsoft-admin_basic-ko/
│   ├── g7-template-sirsoft-admin_basic-en/
│   ├── g7-template-sirsoft-basic-ko/
│   └── g7-template-sirsoft-basic-en/
├── _pending/                                   # Git 제외, 업로드 임시
└── {identifier}/                               # Git 제외, 활성 설치본
    ├── language-pack.json
    ├── backend/{locale}/*.php                  # Laravel trans 파일
    ├── frontend/*.json                         # 템플릿 다국어 fragment
    └── seed/*.json                             # 시드 다국어 데이터
```

## Manifest 명세 (language-pack.json)

언어팩 매니페스트는 모듈/플러그인/템플릿 매니페스트와 동일한 필드 구조로 정렬되어 있어 외부 작성자가 다른 확장과 동일한 표준으로 언어팩을 만들 수 있습니다.

```json
{
    "identifier": "g7-core-ja",
    "namespace": "g7",
    "vendor": "sirsoft",
    "name": {
        "ko": "G7 코어 일본어 언어팩",
        "en": "G7 core Japanese language pack",
        "ja": "G7 コア 日本語 言語パック"
    },
    "description": {
        "ko": "G7 코어 일본어 언어팩 (번들)",
        "en": "G7 core Japanese language pack (bundled)",
        "ja": "G7 コア 日本語 言語パック(バンドル)"
    },
    "version": "1.0.0-beta.1",
    "license": "MIT",
    "scope": "core",
    "target_identifier": null,
    "locale": "ja",
    "locale_name": "Japanese",
    "locale_native_name": "日本語",
    "text_direction": "ltr",
    "g7_version": ">=7.0.0-beta.4",
    "requires": {
        "target_version": null,
        "depends_on_core_locale": false
    },
    "github_url": "",
    "github_changelog_url": ""
}
```

### 필드

| 필드 | 필수 | 설명 |
|---|---|---|
| `identifier` | ✓ | 전역 고유 — 네이밍 공식: `{vendor-or-namespace}-{scope}-{target?}-{locale}` |
| `namespace` | ✓ | 식별자 prefix (예: `g7`, `acme`). `identifier` 의 prefix 와 일치해야 함 |
| `vendor` | ✓ | 제작자 식별자 (kebab-case). `namespace` 와는 의미 분리 — 동일 namespace 의 패키지를 여러 벤더가 발행 가능 |
| `name` | ✓ | 다국어 객체 — 최소 `ko`/`en` 키 + 자체 로케일 키. 카드 UI 표시명 |
| `description` | ✓ | 다국어 객체 — 동일 키 규칙. 상세 모달 설명 |
| `version` | ✓ | semver. 번들 패키지는 패치 단위 bump 후 CHANGELOG 항목 작성 |
| `license` | ✓ | SPDX 식별자 (예: `MIT`) |
| `scope` | ✓ | `core` / `module` / `plugin` / `template` |
| `target_identifier` | ✓ | scope=core 면 `null`, 그 외 호스트 확장 식별자 |
| `locale` | ✓ | IETF BCP-47 (예: `ja`, `zh-CN`, `pt-BR`) |
| `locale_name` | ✓ | 영문 언어명 (예: `Japanese`, `Simplified Chinese`) |
| `locale_native_name` | ✓ | 언어 선택 UI 표시명 (예: `日本語`) |
| `text_direction` | ✓ | `ltr` / `rtl` |
| `g7_version` | ✓ | 코어 호환 버전 제약 (top-level — 모듈/플러그인 매니페스트와 일관). 모듈 매니페스트의 `g7_version` 과 동일 의미 |
| `requires.target_version` | ✓ | scope=module/plugin/template 일 때 호스트 확장 버전 제약. scope=core 면 `null` |
| `requires.depends_on_core_locale` | ✓ | true 일 때 활성화 시 동일 locale 의 코어 언어팩 활성 상태를 강제 |
| `github_url` | ✓ | GitHub 저장소 URL (없으면 빈 문자열). 비어있지 않으면 GitHub 기반 업데이트 경로 활성화 — 모듈/플러그인 매니페스트와 동일 동작 |
| `github_changelog_url` | ✓ | GitHub release/CHANGELOG URL (없으면 빈 문자열) |

### 콘텐츠 파일

매니페스트의 `contents` 필드는 사용하지 않습니다. 패키지 디렉토리 (`lang-packs/{identifier}/`) 의 `backend/{locale}/`, `frontend/`, `seed/` 하위 파일이 자동 발견됩니다.

- `backend/{locale}/*.php` — Laravel `trans()` 파일 (코어/모듈/플러그인 PHP 다국어)
- `frontend/{locale}.json` — 템플릿 풀 페이로드
- `frontend/partial/{locale}/*.json` — 템플릿/모듈 partial 페이로드
- `seed/{entity}.json` — 다국어 시드 (NotificationDefinition, IdentityMessageDefinition 등)

### 들여쓰기 규칙

- `language-pack.json` (매니페스트) — **4-space** (모듈/플러그인/템플릿 매니페스트와 일관)
- 콘텐츠 JSON (`frontend/*.json`, `seed/*.json`) — 2-space

## 슬롯과 다중 벤더

**슬롯** = `(scope, target_identifier, locale)` 튜플. 동일 슬롯에 여러 벤더가 공존할 수 있으나 **active 는 1개만 허용** (`language_packs_slot_active_unique` functional index).

```
slot (core, null, ja):
  - sirsoft-core-ja  (active, vendor=sirsoft)
  - acme-core-ja     (installed, vendor=acme)
```

UI 에서 라디오로 즉시 전환 가능. 활성 팩이 제거되면 slot 의 다음 후보가 자동 승격됩니다.

## 설치 흐름

1. ZIP/GitHub/URL → `_pending/{tmp}/` 추출
2. `LanguagePackManifestValidator` → manifest 구조 검증
3. 보안 검사: `backend/` 외 PHP 파일 차단, `eval`/`include` 패턴 차단
4. 의존성 검사: `depends_on_core_locale=true` 면 코어 언어팩 active 여부 확인
5. 대상 확장 존재 확인 (modules/plugins/templates 테이블)
6. 다운그레이드 차단 (version_compare)
7. `_pending` → `lang-packs/{identifier}/` 이동
8. DB 레코드 생성/업데이트, 슬롯이 비어있으면 자동 active 승격
9. `HookManager::doAction('core.language_packs.after_activate', $pack)` → `SyncDatabaseTranslations` 리스너가 DB JSON 컬럼 갱신

### 웹 인스톨러 (`public/install/`) 동반 설치

신규 사이트 초기 설치 시 4단계 확장 선택 화면에서 번들 언어팩(`lang-packs/_bundled/*`)을 함께 선택할 수 있습니다.

- 인스톨러는 Laravel 부팅 전 단계로 동작하므로 `lang-packs/_bundled/*/language-pack.json` 매니페스트를 직접 스캔하여 카드를 노출합니다.
- 카드는 `locale` 별 서브헤딩으로 그룹핑되며, `scope` 가 `module`/`plugin`/`template` 인 항목은 종속 확장(`target_identifier`)이 함께 선택되어야 활성화됩니다. 종속 확장을 해제하면 그 언어팩 카드는 자동으로 비활성화 + 선택 해제됩니다.
- 코어 언어팩(`scope: "core"`)은 `target_identifier` 가 없으므로 항상 활성화 상태입니다.
- default 선택 정책은 모두 OFF — 사용자가 명시 선택. (관리자 설치 모달의 "모든 후보 ON" 정책과 차별화하여, 신규 사이트가 불필요한 언어팩으로 시작하지 않도록 함.)
- 설치 실행 시 5단계의 모든 확장 install/activate 가 완료된 뒤 `php artisan language-pack:install {identifier} --source=bundled` 가 선택된 각 언어팩에 대해 호출됩니다(자동 활성화 default).
- 언어팩 1건 설치 실패는 best-effort 처리 — 전체 설치를 중단하지 않고 경고 로그만 남긴 뒤 다음 언어팩으로 진행합니다. 코어/모듈/플러그인 install 실패와 달리 rollback 을 발생시키지 않습니다.

## 시더 통합 (HookManager 필터)

기존 시더에 `applyFilters` 1줄만 추가하면 자동으로 다국어 키가 병합됩니다.

```php
// database/seeders/RolePermissionSeeder.php
$config = config('core.permissions');
$config = HookManager::applyFilters('core.permissions.config', $config);
foreach ($config['categories'] as $cat) { ... }
```

| 필터명 | 적용 위치 |
|---|---|
| `core.permissions.config` | RolePermissionSeeder |
| `core.roles.config` | RolePermissionSeeder |
| `core.menus.config` | CoreAdminMenuSeeder |
| `seed.notifications.translations` | NotificationDefinitionSeeder |
| `seed.identity_messages.translations` | IdentityMessageDefinitionSeeder (본인인증 메일 메시지 정의 — provider × scope × scope_value 복합 키) |
| `seed.{vendor-module}.{entity}.translations` | 모듈 시더 (예: `seed.sirsoft-ecommerce.shipping_types.translations`, `seed.sirsoft-ecommerce.notifications.translations`, `seed.sirsoft-board.board_types.translations`) |
| `seed.{vendor-module}.identity_messages.translations` | 모듈/플러그인 IDV 메시지 정의 — `ModuleManager::syncModuleIdentityMessages` / `PluginManager::syncPluginIdentityMessages` 가 발화 |
| `module.{id}.admin_menus.translations` | ModuleManager::createModuleMenus() — 모듈 admin_menus 동기화 시 |
| `module.{id}.roles.translations` | ModuleManager::createModuleRoles() — 모듈 roles 동기화 시 |
| `module.{id}.permissions.translations` | ModuleManager::createModulePermissions() — 모듈 권한 트리 동기화 시 |
| `plugin.{id}.roles.translations` | PluginManager::createPluginRoles() — 플러그인 roles 동기화 시 |
| `plugin.{id}.permissions.translations` | PluginManager::createPluginPermissions() — 플러그인 권한 트리 동기화 시 |
| `template.language.merge` | TemplateService::getLanguageDataWithModules() |

**확장 시드 필터 자동 결선** (`LanguagePackServiceProvider::registerExtensionSeedFilters`): 활성 모듈/플러그인 언어팩의 `seed/*.json` 마다 위 패턴의 필터에 listener 가 자동 등록됩니다. 매칭 키는 entry 의 컬럼을 우선순위(`code` > `slug` > `key` > `identifier` > `id`) 로 자동 감지합니다.

엔티티별 결선 정책:
- `notifications.json` → `seed.{target}.notifications.translations` 필터 (Definition × Template 3-tier 구조)
- `identity_messages.json` → `seed.{target}.identity_messages.translations` 필터 (Definition × Template × Channel — provider/scope_type/scope_value 복합 키 매칭)
- `menus.json` → `module.{target}.admin_menus.translations` 필터 (모듈 전용)
- `roles.json` → `{scope}.{target}.roles.translations` 필터 (module/plugin)
- `permissions.json` → `{scope}.{target}.permissions.translations` 필터 (3-레벨 트리: module/categories/permissions)
- 기타 → `seed.{target}.{entity}.translations` 필터 (단순 entity 시드)

### IDV 도메인 lang pack 커버리지

`config/core.php` 의 본인인증 SSoT 4개 블록 중 lang pack seed 대상은 1개:

| SSoT 블록 | lang pack seed | 사유 |
|---|---|---|
| `notification_definitions` | ✓ `seed/notifications.json` | 정의/템플릿이 다국어 데이터 직접 보유 |
| `identity_messages` | ✓ `seed/identity_messages.json` | 메일 본문/제목이 다국어 데이터 직접 보유 |
| `identity_policies` | ❌ | `IdentityPolicy` 모델에 다국어 필드 부재 — 관리자 UI 라벨은 `lang/{locale}/identity.php` 또는 `frontend/partial/admin.json` 의 i18n 키로 처리 |
| `identity_purposes` | ❌ | `label_key`/`description_key` 참조 패턴 — 실제 ja 라벨은 `frontend/partial/admin.json::identity.purposes.*` 키로 처리 |

**`identity_messages.json` 스키마 예시**:

```json
{
  "mail.purpose.signup": {
    "definition": {
      "name": "会員登録認証",
      "description": "会員登録時のメール認証"
    },
    "templates": {
      "mail": {
        "subject": "[アプリ] 会員登録認証コード",
        "body": "<p>認証コード: {code}</p>"
      }
    }
  }
}
```

복합 키는 `{channel}.{scope_type}.{scope_value}` 또는 `{channel}.provider_default` 형태 — config/core.php::identity_messages 의 array key 와 동일.

**확장 권한 트리 시드 키 포맷**: 권한은 ModuleManager 가 DB row identifier 를 `{module-id}.{cat-id}.{perm-id}` 로 prefix 하므로 시드도 동일 포맷을 사용합니다. 권한 노드 식별자는 `action`(이커머스 등) 또는 `identifier`(명시적) 둘 중 존재하는 키를 사용합니다.

## user_overrides 보존 정책

`HasUserOverrides` 사용 모델(Permission/Role/Menu/NotificationDefinition/NotificationTemplate/Module/Plugin/Template)에서 사용자가 직접 수정한 컬럼/locale 키는 언어팩이 덮어쓰지 않습니다.

| 케이스 | 동작 |
|---|---|
| A: 기존 row 에 해당 locale 키 부재 | 추가 (overrides 무관) |
| B: locale 키 존재 + user_overrides 등록 | **건너뜀** (사용자 보존) |
| C: locale 키 존재 + user_overrides 미등록 | 시드 값으로 덮어쓰기 |

비활성화/제거 시에도 user_overrides 등록 locale 은 JSON 에서 제거되지 않습니다. 모든 보존 결정은 `language_pack` 채널에 감사 로그로 기록됩니다.

## 백엔드 번역 namespace fallback 메커니즘

Laravel `Translator` 의 FileLoader 는 `addNamespace($namespace, $hint)` 가 단일 hint 만 보유하며 덮어쓰는 구조이므로, 모듈/플러그인 자체의 `src/lang` namespace 등록 후 같은 namespace 로 언어팩이 추가되면 모듈의 ko/en 등록이 통째로 사라지는 회귀가 발생합니다.

`LanguagePackTranslator` 가 이를 회피하기 위한 namespace fallback 경로를 별도 보유합니다.

- `addNamespaceFallbackPath($namespace, $locale, $path)` — namespace ⇒ locale ⇒ 경로 배열로 누적 등록
- `load($namespace, $group, $locale)` — 표준 hint 로 1차 로드 후 namespace fallback 경로의 PHP 배열 파일을 누락 키만 보완 병합 (기존 번역 우선)

이 메커니즘으로 모듈은 `src/lang/{ko,en}/*.php` 의 표준 namespace 등록을 그대로 유지하고, 활성 ja 언어팩은 fallback 경로(`lang-packs/g7-module-{id}-ja/backend/ja/`) 에서만 ja 키를 보완합니다.

## 프론트엔드 다국어 병합의 wrap 정책

`MergeFrontendLanguage` 가 활성 언어팩의 `frontend/*.json` 을 병합할 때 scope 별로 root key 처리가 다릅니다.

- core / template scope — root 에 평탄 병합 (TemplateService 의 ko 데이터 구조와 일치)
- module / plugin scope — `[$pack->target_identifier => $frontend]` 로 wrap 후 병합

wrap 이 필요한 이유: TemplateService 가 모듈/플러그인 자체 ko 데이터를 `[$identifier => $data]` 로 wrap 하므로, 언어팩 데이터도 동일 구조여야 `$t:sirsoft-ecommerce.admin.*` 같은 표현식이 ja 활성 시에도 정확한 경로로 해석됩니다.

## 권한

| 권한 | 설명 |
|---|---|
| `core.language_packs.read` | 언어팩 목록 및 상세 조회 |
| `core.language_packs.install` | ZIP/GitHub/URL 설치 |
| `core.language_packs.manage` | 활성화/비활성화/제거 |

## REST API

| 엔드포인트 | 메서드 | 권한 |
|---|---|---|
| `/api/admin/language-packs` | GET | `read` |
| `/api/admin/language-packs/{id}` | GET | `read` |
| `/api/admin/language-packs/install-from-file` | POST | `install` |
| `/api/admin/language-packs/install-from-github` | POST | `install` |
| `/api/admin/language-packs/install-from-url` | POST | `install` |
| `/api/admin/language-packs/{id}/activate` | POST | `manage` |
| `/api/admin/language-packs/{id}/deactivate` | POST | `manage` |
| `/api/admin/language-packs/{id}` | DELETE | `manage` |

## 보안

1. **ZIP slip 방지** — `ZipInstallHelper` 의 기존 검증 재사용
2. **PHP 격리** — `backend/` 디렉토리 외 PHP 파일 거부
3. **위험 함수 차단** — `eval/include/require/exec/system/popen` 등 정적 분석으로 거부
4. **파일 크기 제한** — 10MB
5. **체크섬 검증** — URL 설치 시 SHA-256 옵션

## 외부 패키지형 vs 메타데이터 전용 번들

번들 언어팩은 두 가지 형태를 가질 수 있습니다.

| 형태 | `contents` | 실제 번역 자산 위치 | 사용 사례 |
|---|---|---|---|
| **메타데이터 전용** | 빈 배열 | 코어/확장 트리(`lang/{locale}/`, `templates/_bundled/*/lang/{locale}.json`) | 코어 fallback locale (ko/en) — 트리 자체에 포함된 자산을 시스템에 등록만 하기 위함 |
| **외부 패키지형** | 실제 파일 경로 | 패키지 디렉토리 내(`backend/{locale}/*.php`, `frontend/*.json`, `seed/*.json`) | 추가 로케일 (ja, zh-CN 등) — 코어 트리를 건드리지 않고 새 locale 추가 |

기본 ko/en 은 메타데이터 전용 — 실제 PHP/JSON 파일은 코어 트리에 그대로 두고 manifest 1개로 시스템에 등록만 합니다. 새 locale (예: 일본어) 은 외부 패키지형으로 도입하여 **코어 코드 변경 0** 을 유지합니다.

## 공식 일본어 번들 언어팩 (g7-*-ja)

G7 는 일본어 번들 언어팩 12종을 공식 제공합니다. ko 원본을 LLM 으로 자동 번역하여 빌드된 산출물이며, 사용자 환경에서는 일반 번들 확장과 동일하게 `language-pack:install --source=bundled` 로 설치합니다.

### 패키지 구성

| scope | 식별자 | target_identifier |
|---|---|---|
| core | `g7-core-ja` | (null) |
| template | `g7-template-sirsoft-admin_basic-ja` | sirsoft-admin_basic |
| template | `g7-template-sirsoft-basic-ja` | sirsoft-basic |
| template | `g7-template-gnuboard7-hello_admin_template-ja` | gnuboard7-hello_admin_template |
| template | `g7-template-gnuboard7-hello_user_template-ja` | gnuboard7-hello_user_template |
| module | `g7-module-sirsoft-ecommerce-ja` | sirsoft-ecommerce |
| module | `g7-module-sirsoft-board-ja` | sirsoft-board |
| module | `g7-module-sirsoft-page-ja` | sirsoft-page |
| module | `g7-module-gnuboard7-hello_module-ja` | gnuboard7-hello_module |
| plugin | `g7-plugin-sirsoft-ckeditor5-ja` | sirsoft-ckeditor5 |
| plugin | `g7-plugin-sirsoft-marketing-ja` | sirsoft-marketing |
| plugin | `g7-plugin-sirsoft-tosspayments-ja` | sirsoft-tosspayments |

### 입력 → 산출 매핑

| 패키지 | ko 원문 소스 | 산출 위치 |
|---|---|---|
| `g7-core-ja` backend | `lang/ko/*.php` (33개) | `lang-packs/_bundled/g7-core-ja/backend/ja/*.php` |
| `g7-core-ja` seed | `config/core.php` (permissions/roles/menus) + `NotificationDefinitionSeeder` | `seed/{permissions,roles,menus,notifications}.json` |
| `g7-template-{tpl}-ja` | `templates/_bundled/{tpl}/lang/ko.json` + `lang/partial/ko/*.json` | `frontend/ja.json` + `frontend/partial/*.json` |
| `g7-module-{mod}-ja` | `modules/_bundled/{mod}/{src,resources}/lang/ko/*.php` + `resources/lang/ko.json` + `resources/lang/partial/ko/**/*.json` (재귀) | `backend/ja/*.php` + `frontend/ja.json` + `frontend/partial/**/*.json` |
| `g7-module-sirsoft-ecommerce-ja` seed | `ShippingTypeSeeder`, `ClaimReasonSeeder`, `EcommerceNotificationDefinitionSeeder` ko 추출 | `seed/{shipping_types,claim_reasons,notifications}.json` |
| `g7-module-sirsoft-board-ja` seed | `BoardTypeSeeder::DEFAULT_BOARD_TYPES` 상수 추출 | `seed/board_types.json` |
| 모듈 ja 팩 (공통) | `module.php` 의 `getAdminMenus` / `getRoles` / `getPermissions` 추출 | `seed/{menus,roles,permissions}.json` |
| 플러그인 ja 팩 (공통) | `plugin.php` 의 `getRoles` / `getPermissions` 추출 | `seed/{roles,permissions}.json` |
| 모든 확장 ja 팩 | `module.json` / `plugin.json` / `template.json` 의 `name`/`description` ko | `seed/manifest.json` |
| `g7-plugin-{pl}-ja` | `plugins/_bundled/{pl}/lang/ko/*.php` | `backend/ja/*.php` |

### 빌드 source kind

| kind | 동작 |
|---|---|
| `php-dir` | 디렉토리의 모든 *.php 를 ko → ja 로 번역 |
| `json-file` | 단일 JSON 파일 ($partial 디렉티브 경로 정규화 포함) |
| `json-dir` | 디렉토리의 모든 *.json **재귀** (admin/* 같은 서브디렉토리 포함) |
| `core-seed` | `config/core.php` 에서 permissions/roles/menus ko 추출 |
| `notif-seed` | NotificationDefinitionSeeder 의 getDefaultDefinitions 추출 |
| `ext-seed` | 확장 시더의 메서드/상수 추출 (entity 별 매칭 키 자동 감지) |
| `ext-spec-seed` | module.php / plugin.php 의 getAdminMenus / getRoles / getPermissions 결과 추출. 권한은 `{module-id}.{cat-id}.{perm-id}` prefixed identifier 로 출력 |
| `manifest-seed` | manifest 파일의 name/description ko 추출 → seed/manifest.json |

### 번역 규칙

- placeholder 보존: `:attribute`, `{count}`, `{{var}}`, `%s`, `:other`
- HTML 태그 보존: `<a>`, `<br>`, `<strong>`, `<span>`
- 키(key) 변형 금지 — value 만 번역
- 통화/숫자 단위 변환 금지 (런타임 처리)
- 공식체(です·ます) 강제, 친근체 금지
- 한글 미포함 value(영문/숫자/기호만) 는 번역 스킵 → 원문 유지
- 고정 용어집 50항목으로 일관성 강제 (예: `관리자 → 管理者`, `장바구니 → カート`)

### 설치 및 활성화

```bash
# 12개 패키지를 번들 소스로 설치 (자동 활성)
php artisan language-pack:install g7-core-ja --source=bundled
php artisan language-pack:install g7-template-sirsoft-admin_basic-ja --source=bundled
# ... (나머지 10개 동일)

# 확인
php artisan language-pack:list --scope=core
php artisan language-pack:list --scope=module
```

### 기존 ko/en 변경 시 번들 ja 동기화 의무

코어/모듈/플러그인/템플릿의 ko 또는 en 다국어 키를 추가/수정/제거할 때마다 대응하는 번들 ja 패키지(`lang-packs/_bundled/g7-*-ja/`) 의 키 셋을 동기화해야 한다. 동기화하지 않으면 일본어 화면에서 미번역 fallback(ko/en) 이 노출되어 UX 가 손상된다.

번들 ja 동기화/빌드 자동화 도구는 메인테이너 영역이며, 외부 기여자는 ko 원본 변경 후 PR 시 메인테이너가 동기 빌드를 수행한다. 자기 환경에서 검증이 필요하다면 ko 키를 직접 ja 패키지의 대응 위치(`lang-packs/_bundled/g7-*-ja/backend/ja/`, `frontend/`, `seed/`) 에 수동 추가/수정/삭제할 수 있다 — 키만 일치하면 시스템이 정상 fallback 한다.

#### 잉여 키 (ko 에서 제거 후 번들 잔류) 처리

ko 에서 키 제거 시 번들 ja 의 대응 키도 수동 제거 권장 (자동 제거 도구 없음 — 의도치 않은 제거 방지).

#### 번들 언어팩 버전 + CHANGELOG 작성 규정

번들 언어팩 자산을 수정할 때마다 모듈/플러그인/템플릿 확장과 동일한 수준의 버전 + CHANGELOG 관리를 적용한다.

- **버전 bump 시점**: 패키지가 외부에 한 번이라도 출시(릴리즈/배포) 된 이후의 수정에 한해 버전을 올린다. 아직 출시되지 않은 번들 패키지의 사전 보강(키 추가/번역 정정 등) 은 `1.0.0` 그대로 유지한다.
- **버전 bump 단위**: 출시 후 키 추가/누락 보강은 패치 (1.0.0 → 1.0.1), 의미적으로 큰 변경이나 제거는 마이너 (1.0.0 → 1.1.0).
- **CHANGELOG.md**: 패키지 루트에 `CHANGELOG.md` 작성. Keep a Changelog 표준 (`## [버전] - YYYY-MM-DD` + `### Added/Changed/Fixed/Removed`).
- **톤**: 사용자 관점, 1~2줄 불릿. 내부 파일 경로/내부 함수명/이슈 번호 미기재.
- **신규 기능의 개발 중 결함**: 같은 릴리즈에 처음 도입된 키의 누락 보강 등은 Fixed 가 아닌 Added 로 기록.
- **번역 자체 수정** (예: 어색한 표현 교정): `### Changed` 로 기록.
- **자동화**: 빌드 스크립트는 기존 manifest 의 version 을 읽어 보존하므로, 수동 bump 한 1.0.1 등이 다음 빌드에서 1.0.0 으로 덮어쓰이지 않는다.

### 외부 언어팩 패키지 개발 · 배포

번들 ja 와 동일한 디렉토리 구조의 언어팩 패키지를 자체 작성하여 별도 배포할 수 있다. 코어 트리는 변경하지 않으므로 코어 업데이트와 충돌하지 않는다.

```text
my-lang-pack-zh/
├── language-pack.json          # 매니페스트 (식별자, scope, target_identifier, locale, version, manifest 키 셋)
├── backend/
│   └── zh-CN/                  # 백엔드 PHP 키 (스코프가 코어/모듈/플러그인일 때)
│       └── *.php
├── frontend/                   # 프론트엔드 JSON 키 (스코프가 모듈/플러그인/템플릿일 때)
│   ├── zh-CN.json
│   └── partial/
│       └── *.json
└── seed/                       # 초기 데이터 번역 (스코프별 entity 키 매칭 — permissions/menus/notifications 등)
    └── *.json
```

매니페스트(`language-pack.json`) 필드는 번들 ja 패키지를 참고. `scope` 는 `core`/`module`/`plugin`/`template` 중 하나, `target_identifier` 는 대상 확장 식별자(코어 스코프는 null), `locale` 은 [BCP 47](https://tools.ietf.org/html/bcp47) 코드.

### 설치

```bash
# 로컬 디렉토리에서 설치
php artisan language-pack:install /path/to/my-lang-pack-zh --source=local

# 또는 zip 으로 배포 후 URL 에서 설치
php artisan language-pack:install https://example.com/my-lang-pack-zh.zip --source=url
```

설치 후 `language-pack:list` 로 확인. 다른 로케일 패키지와 동일하게 활성/비활성/제거가 가능하며, 의존성 검증(`core_locale_missing` 등) 도 동일하게 적용된다.

## 의존성 검증 — 설치 차단 사유 (UI 인라인 안내)

미설치 번들 행과 설치 모달은 다음 4가지 사유로 설치 차단을 표시한다 (`LanguagePackService::resolveInstallBlockedReason()` 가 단일 SSoT). 모듈/플러그인 시스템과 동일 강도.

| 사유 키 | 발생 조건 |
| ------- | --------- |
| `core_locale_missing` | scope ≠ core + 코어 동일 locale 팩 미활성 |
| `target_not_installed` | 대상 모듈/플러그인/템플릿이 DB 에 없음 |
| `target_inactive` | 대상은 설치되었지만 status ≠ active |
| `target_version_too_old` | manifest `requires.target_version` 미충족 |

`LanguagePackResource::resourceMeta()` 가 차단 사유 존재 시 `abilities.can_install` 을 false 로 강제 — UI 행/모달 버튼이 권한과 무관하게 disabled.

## is_protected 정책 (모듈/플러그인/템플릿과 일관)

번들 언어팩(`lang-packs/_bundled/*`) 은 manifest 의 `is_protected` 선언을 따른다. 기본값은 `false` — 모듈/플러그인/템플릿이 번들이라도 자유 제거 가능한 것과 동일.

`LanguagePackBundledRegistrar` 가 동기화하는 `bundled_with_extension` 레코드(모듈/플러그인/템플릿 자체에 포함된 lang 디렉토리에서 자동 등록되는 가상 행) 만 `is_protected: true` 로 고정 — 부모 확장 lifecycle 에 종속되어 독립 제거가 불가하기 때문.

## Manifest 미리보기 (3종 확장과 동등)

ZIP 업로드 전 manifest 검증 결과를 사전 확인할 수 있다. 모듈/플러그인/템플릿 시스템에도 동일 패턴으로 추가됨.

- 라우트: `POST /api/admin/{language-packs|modules|plugins|templates}/manifest-preview`
- 응답: `{ manifest, validation: { errors[], is_valid, already_installed, existing_version } }`
- 검증 실패 시에도 HTTP 200 — `validation.is_valid: false` + `errors[]` 노출
- UI: 각 도메인의 수동 설치 모달(`_modal_manual_install.json`) 에 "manifest 미리보기" 버튼 → 별도 드로어 partial(`_drawer_manifest_preview.json`) 로 ZIP 업로드 + 결과 표시
- 드로어 작성 패턴: [modal-usage.md ZIP 파일 업로드 모달/드로어 패턴](../frontend/modal-usage.md#zip-파일-업로드-모달드로어-패턴-multipart-백엔드)

## 관련 파일

- `app/Models/LanguagePack.php`
- `app/Services/LanguagePackService.php`
- `app/Services/LanguagePack/LanguagePackRegistry.php`
- `app/Services/LanguagePack/LanguagePackManifestValidator.php`
- `app/Services/LanguagePack/LanguagePackSeedInjector.php`
- `app/Services/LanguagePack/LanguagePackTranslator.php`
- `app/Listeners/LanguagePack/SyncDatabaseTranslations.php`
- `app/Listeners/LanguagePack/MergeFrontendLanguage.php`
- `app/Providers/LanguagePackServiceProvider.php`
- `app/Http/Controllers/Api/Admin/LanguagePackController.php`
- `database/migrations/2026_04_27_000001_create_language_packs_table.php`
- `templates/_bundled/sirsoft-admin_basic/layouts/admin_language_pack_list.json`
