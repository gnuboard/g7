# 모듈 개발 기초

> 이 문서는 G7의 모듈 개발 기초를 다룹니다.

---

## TL;DR (5초 요약)

```text
1. 디렉토리: vendor-module (예: sirsoft-ecommerce)
2. 네임스페이스: Modules\Vendor\Module\ (예: Modules\Sirsoft\Ecommerce\)
3. AbstractModule 상속 권장 (ModuleInterface 직접 구현 가능)
4. 필수: module.json (메타데이터 SSoT), Module.php, composer.json, routes/admin.json
5. getName()/getVersion()/getDescription()은 module.json에서 자동 파싱 (하드코딩 불필요)
```

---

## 목차

- [모듈 네이밍 규칙](#모듈-네이밍-규칙)
- [AbstractModule 상속 (권장)](#abstractmodule-상속-권장)
- [ModuleInterface 직접 구현 (레거시)](#moduleinterface-직접-구현-레거시)
- [composer.json 작성법](#composerjson-모듈)
- [모듈 디렉토리 구조](#모듈-디렉토리-구조)
- [관련 문서](#관련-문서)

---

## 모듈 네이밍 규칙

### 디렉토리명

`vendor-module` 형식 (GitHub 스타일)

- 소문자 사용
- 하이픈(-): vendor와 module 구분
- 언더스코어(_): 모듈명 내 단어 구분 (선택적)
- 예: `sirsoft-ecommerce`, `sirsoft-order_management`, `johndoe-blog`

### 네임스페이스

`Modules\Vendor\Module\` 형식

- PascalCase 사용
- 백슬래시(\) 구분
- 예: `Modules\Sirsoft\Ecommerce\`, `Modules\Sirsoft\OrderManagement\`

### 디렉토리명 → 네임스페이스 변환 규칙

```text
필수: 변환 규칙 이해

하이픈(-) → 네임스페이스 구분자(\)
언더스코어(_) → PascalCase 결합

예시:
  sirsoft-ecommerce        → Sirsoft\Ecommerce
  sirsoft-order_management → Sirsoft\OrderManagement
  vendor-my_module_name    → Vendor\MyModuleName
```

### Composer 패키지명

`modules/vendor-module`

- 예: `modules/sirsoft-ecommerce`, `modules/sirsoft-order_management`

### 네이밍 요약표

| 항목 | 형식 | 예시 |
|------|------|------|
| 디렉토리명 | `vendor-module` | `sirsoft-ecommerce`, `sirsoft-order_management` |
| 네임스페이스 | `Modules\Vendor\Module\` | `Modules\Sirsoft\Ecommerce\`, `Modules\Sirsoft\OrderManagement\` |
| Composer 패키지명 | `modules/vendor-module` | `modules/sirsoft-ecommerce`, `modules/sirsoft-order_management` |

### 식별자 검증 규칙

모듈/플러그인/템플릿 공통 식별자 검증 규칙은 [extension-manager.md](./extension-manager.md#식별자-검증-규칙-validextensionidentifier)를 참조하세요.

---

## AbstractModule 상속 (권장)

모듈 개발자는 `AbstractModule`을 상속받아 필수 메서드만 구현하면 됩니다.
`getIdentifier()`와 `getVendor()`는 디렉토리명에서 자동으로 추론됩니다.

### manifest 자동 파싱 메서드 (오버라이드 불필요)

`module.json`에서 자동으로 값을 읽어오는 메서드입니다. 하드코딩 오버라이드가 불필요합니다.

| 메서드 | 반환 타입 | 소스 (module.json) | 기본값 |
|--------|----------|-------------------|--------|
| `getName()` | `string\|array` | `name` | identifier |
| `getVersion()` | `string` | `version` | `'0.0.0'` |
| `getDescription()` | `string\|array` | `description` | `''` |
| `getRequiredCoreVersion()` | `?string` | `g7_version` | `null` |
| `getLicense()` | `?string` | `license` | `null` |
| `getGithubUrl()` | `?string` | `github_url` | `null` |
| `getAssets()` | `array` | `assets` | `[]` |
| `getAssetLoadingConfig()` | `array` | `loading` | strategy: global, priority: 100 |

> **`license` 필드**: `module.json`에 `"license": "MIT"` 등의 라이선스 정보를 포함합니다. 이 값은 API 리소스의 `license` 필드로 노출됩니다. 또한 각 모듈 루트에 `LICENSE` 파일을 포함하여 라이선스 전문을 제공해야 합니다.

### hidden 필드 (선택)

`module.json` 에 `"hidden": true` 를 설정하면 관리자 UI 의 모듈 목록에서 기본 제외됩니다. 학습용 샘플 모듈, 내부 운영 전용 모듈을 일반 사용자에게 감출 때 사용합니다.

- 제외 대상: 관리자 UI (`GET /api/admin/modules` 기본 응답)
- 제외 대상 아님: artisan CLI (`module:list`, `module:install`, `module:activate` 등), 설치/제거/업데이트 감지
- 슈퍼관리자는 "숨김 포함" 토글로 일시 조회 가능 (`?include_hidden=1`)
- artisan CLI 에서도 기본 목록에서는 숨기고, `php artisan module:list --hidden` 으로 숨김 포함 목록을 조회할 수 있습니다
- 사용 사례: 학습용 샘플 모듈(예: `gnuboard7-hello_module`), 내부 전용 통합 모듈

### 자동 추론 메서드 (final - 오버라이드 불가)

| 메서드 | 반환 타입 | 설명 |
|--------|----------|------|
| `getIdentifier()` | `string` | 디렉토리명에서 자동 추론 (예: `sirsoft-ecommerce`) |
| `getVendor()` | `string` | `module.json` 의 `vendor` 필드를 우선 사용. 값이 없으면 디렉토리명의 첫 단어로 폴백 (예: `sirsoft`) |

### 기본값 제공 메서드 (필요시 오버라이드)

| 메서드 | 기본값 | 설명 |
|--------|--------|------|
| `install()` | `true` | 설치 로직 |
| `uninstall()` | `true` | 제거 로직 |
| `activate()` | `true` | 활성화 로직 |
| `deactivate()` | `true` | 비활성화 로직 |
| `getDynamicTables()` | `[]` | 런타임 동적 생성 테이블 목록 (언인스톨 시 Manager가 삭제) |
| `getRoutes()` | 자동 탐지 | `src/routes/api.php`, `src/routes/web.php` |
| `getMigrations()` | 자동 탐지 | `database/migrations` 디렉토리 |
| `getViews()` | `[]` | 뷰 파일 |
| `getPermissions()` | `[]` | 권한 목록 (resource_route_key, owner_key, roles scope_type 지원) |
| `getRoles()` | `[]` | 역할 목록 |
| `getDynamicPermissionIdentifiers()` | `[]` | 런타임 생성 권한 식별자 — stale cleanup 보존 대상 (아래 참조) |
| `getDynamicRoleIdentifiers()` | `[]` | 런타임 생성 역할 식별자 — stale cleanup 보존 대상 |
| `getDynamicMenuSlugs()` | `[]` | 런타임 생성 메뉴 slug — stale cleanup 보존 대상 |
| `getConfig()` | `[]` | 설정 |
| `getAdminMenus()` | `[]` | 관리자 메뉴 |
| `getHookListeners()` | `[]` | 훅 리스너 |
| `getDependencies()` | `[]` | 의존성 |
| `getMetadata()` | `[]` | 메타데이터 |
| `upgrades()` | `[]` | 업그레이드 스텝 (`upgrades/` 디렉토리 자동 발견). **`g7_version >= 7.0.0-beta.5` 인 모듈은 신규 step 이 `AbstractUpgradeStep` 상속 의무** ([upgrade-step-guide §13](upgrade-step-guide.md)) — 미상속 시 `ModuleManager::runUpgradeSteps` 가 `RuntimeException` throw |

#### 동적 권한/역할/메뉴 보존 규칙

모듈이 런타임에 `Permission::updateOrCreate` / `Role::firstOrCreate` / `Menu::create` 등으로 동적 엔티티를 만드는 경우(예: sirsoft-board 의 게시판 slug 별 권한·역할·메뉴), 업데이트 시 `cleanupStale*` 로직이 **정적 정의에 없다** 는 이유로 전수 삭제되는 회귀가 발생한다. 이를 방지하려면 아래 3개 메서드를 override 해 **현재 DB 에 존재해야 하는 동적 식별자 전체** 를 반환한다.

```php
public function getDynamicPermissionIdentifiers(): array
{
    if (! Schema::hasTable('boards')) { return []; }
    $actions = array_keys((array) config('sirsoft-board.board_permission_definitions', []));
    $module = $this->getIdentifier();
    $ids = [];
    foreach (Board::query()->select('slug')->get() as $board) {
        $ids[] = "{$module}.{$board->slug}";                 // 카테고리
        foreach ($actions as $a) {
            $ids[] = "{$module}.{$board->slug}.{$a}";        // 액션
        }
    }
    return $ids;
}
```

보존 원칙:

- **업데이트 경로**: `updateModule()` → `cleanupStaleModuleEntries()` 는 정적 + 동적 식별자를 병합한 expected 목록을 기준으로 stale 판정. 동적 식별자가 정확히 반환되면 유실 없음.
- **언인스톨 경로**: `uninstallModule($deleteData=false)` 는 권한·메뉴·역할을 **보존** (재설치 시 사용자 역할 할당 복원). `deleteData=true` 일 때만 전수 삭제.
- **설치 경로**: `installModule(--force)` 는 cleanup 을 실행하지 않아 동적 엔티티 유실 없음.

### 간결한 모듈 구현 예시

> **참고**: `getName()`, `getVersion()`, `getDescription()`은 `module.json`에서 자동 파싱되므로 오버라이드가 불필요합니다.

```php
<?php

namespace Modules\Sirsoft\Ecommerce;

use App\Extension\AbstractModule;

class Module extends AbstractModule
{
    // getName(), getVersion(), getDescription()은 module.json에서 자동 파싱
    // 별도 오버라이드 불필요

    /**
     * 역할 목록 (필요시 오버라이드)
     */
    public function getRoles(): array
    {
        return [
            [
                'identifier' => 'sirsoft-ecommerce.manager',
                'name' => [
                    'ko' => '이커머스 관리자',
                    'en' => 'Ecommerce Manager',
                ],
                'description' => [
                    'ko' => '이커머스 모듈 관리 권한을 가진 역할',
                    'en' => 'Role with ecommerce module management permissions',
                ],
            ],
        ];
    }

    /**
     * 권한 목록 (필요시 오버라이드)
     *
     * - resource_route_key: 라우트 파라미터명 (scope 체크용, 소유자 개념 없으면 생략)
     * - owner_key: 모델의 소유자 식별 컬럼명 (scope 체크용, 소유자 개념 없으면 생략)
     * - roles: 문자열 배열 또는 {role, scope_type} 객체 배열
     */
    public function getPermissions(): array
    {
        return [
            [
                'identifier' => 'sirsoft-ecommerce.products.view',
                'name' => [
                    'ko' => '상품 조회',
                    'en' => 'View Products',
                ],
                'description' => [
                    'ko' => '상품 목록 및 상세 정보를 조회할 수 있습니다',
                    'en' => 'Can view product list and details',
                ],
                'resource_route_key' => 'product',   // 라우트 파라미터명
                'owner_key' => 'created_by',          // 소유자 컬럼
                // roles에 scope_type 지정 가능 (null=전체, 'self'=본인, 'role'=소유역할)
                'roles' => [
                    ['role' => 'admin', 'scope_type' => null],
                    ['role' => 'sirsoft-ecommerce.manager', 'scope_type' => 'role'],
                ],
            ],
            [
                'identifier' => 'sirsoft-ecommerce.products.create',
                'name' => [
                    'ko' => '상품 생성',
                    'en' => 'Create Products',
                ],
                'description' => [
                    'ko' => '새로운 상품을 생성할 수 있습니다',
                    'en' => 'Can create new products',
                ],
                'resource_route_key' => 'product',
                'owner_key' => 'created_by',
                'roles' => ['admin'],  // 문자열 배열도 허용 (scope_type=null 기본값)
            ],
            [
                'identifier' => 'sirsoft-ecommerce.orders.view',
                'name' => [
                    'ko' => '주문 조회',
                    'en' => 'View Orders',
                ],
                'description' => [
                    'ko' => '주문 목록 및 상세 정보를 조회할 수 있습니다',
                    'en' => 'Can view order list and details',
                ],
                'resource_route_key' => 'order',
                'owner_key' => 'user_id',
                'roles' => ['admin', 'sirsoft-ecommerce.manager'],
            ],
            [
                'identifier' => 'sirsoft-ecommerce.categories.view',
                'name' => [
                    'ko' => '카테고리 조회',
                    'en' => 'View Categories',
                ],
                'description' => [
                    'ko' => '카테고리 목록을 조회할 수 있습니다',
                    'en' => 'Can view category list',
                ],
                // resource_route_key/owner_key 생략 = 소유자 개념 없음 (scope 체크 스킵)
                'roles' => ['admin'],
            ],
        ];
    }

    /**
     * 관리자 메뉴 정의 (필요시 오버라이드)
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'name' => [
                    'ko' => '이커머스',
                    'en' => 'Ecommerce',
                ],
                'slug' => 'ecommerce',
                'url' => '/admin/ecommerce',
                'icon' => 'fa-shopping-cart',
                'order' => 20,
                'children' => [
                    [
                        'name' => [
                            'ko' => '상품 관리',
                            'en' => 'Product Management',
                        ],
                        'slug' => 'sirsoft-ecommerce-products',
                        'url' => '/admin/ecommerce/products',
                        'icon' => 'fa-box',
                        'order' => 1,
                        'permission' => 'sirsoft-ecommerce.products.view',
                    ],
                    [
                        'name' => [
                            'ko' => '주문 관리',
                            'en' => 'Order Management',
                        ],
                        'slug' => 'sirsoft-ecommerce-orders',
                        'url' => '/admin/ecommerce/orders',
                        'icon' => 'fa-receipt',
                        'order' => 2,
                        'permission' => 'sirsoft-ecommerce.orders.view',
                    ],
                ],
            ],
        ];
    }

    /**
     * 훅 리스너 목록 (필요시 오버라이드)
     */
    public function getHookListeners(): array
    {
        return [
            \Modules\Sirsoft\Ecommerce\Listeners\ProductCacheInvalidationListener::class,
            \Modules\Sirsoft\Ecommerce\Listeners\OrderNotificationListener::class,
        ];
    }

    /**
     * 모듈 메타데이터 (필요시 오버라이드)
     */
    public function getMetadata(): array
    {
        return [
            'author' => 'sirsoft',
            'license' => 'MIT',
        ];
    }
}
```

---

## ModuleInterface 직접 구현 (레거시)

> **참고**: 특별한 경우가 아니라면 `AbstractModule` 상속을 권장합니다.

`ModuleInterface`를 직접 구현할 경우, 모든 메서드를 직접 구현해야 합니다:

```php
<?php

namespace Modules\Sirsoft\Ecommerce;

use App\Contracts\Extension\ModuleInterface;

class Module implements ModuleInterface
{
    public function getIdentifier(): string
    {
        return 'sirsoft-ecommerce';
    }

    public function getVendor(): string
    {
        return 'sirsoft';
    }

    // ... 모든 메서드 직접 구현 필요
}
```

---

## composer.json (모듈)

### 기본 구조

```json
{
    "name": "modules/sirsoft-ecommerce",
    "description": "E-commerce module for Gnuboard7 platform by sirsoft",
    "type": "library",
    "authors": [
        {
            "name": "sirsoft",
            "email": "contact@sirsoft.com"
        }
    ],
    "require": {
        "php": "^8.2"
    },
    "autoload": {
        "psr-4": {
            "Modules\\Sirsoft\\Ecommerce\\": "src/"
        }
    }
}
```

### 외부 패키지 의존성

모듈이 외부 Composer 패키지를 필요로 하는 경우, `require`에 추가합니다.

```json
{
    "name": "modules/sirsoft-ecommerce",
    "require": {
        "php": "^8.2",
        "stripe/stripe-php": "^13.0",
        "intervention/image": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "Modules\\Sirsoft\\Ecommerce\\": "src/"
        }
    }
}
```

```text
주의: 루트 composer.json에 모듈 패키지 추가 금지
모듈 설치 시 자동으로 composer install 실행 (Phase 4.5)
modules/{identifier}/vendor/ 디렉토리에 독립 설치
수동 설치: php artisan module:composer-install [identifier]
```

- `php`와 `ext-*` 패키지는 외부 의존성으로 간주되지 않음 (composer install 트리거 안 함)
- 설치된 패키지는 `vendor_autoloads`를 통해 런타임에 자동 로드됨
- 상세: [extension-manager.md](extension-manager.md) "Composer 의존성 관리" 참조

### Factory/Seeder 사용 시 추가 설정

```
필수: database/factories/ 또는 database/seeders/ 사용 시 autoload 등록
등록하지 않으면 테스트에서 "Class not found" 오류 발생
```

Factory 또는 Seeder를 사용하는 모듈은 **반드시** composer.json에 해당 경로를 등록해야 합니다:

```json
{
    "name": "modules/sirsoft-ecommerce",
    "description": "E-commerce module for Gnuboard7 platform by sirsoft",
    "type": "library",
    "version": "1.0.0",
    "license": "MIT",
    "autoload": {
        "psr-4": {
            "Modules\\Sirsoft\\Ecommerce\\": "src/",
            "Modules\\Sirsoft\\Ecommerce\\Database\\Seeders\\": "database/seeders/",
            "Modules\\Sirsoft\\Ecommerce\\Database\\Factories\\": "database/factories/"
        }
    },
    "require": {
        "php": "^8.2"
    }
}
```

### autoload 설정 후 갱신 (필수)

composer.json 수정 후 반드시 autoload 갱신:

```bash
php artisan extension:update-autoload
```

---

## 모듈 디렉토리 구조

```
modules/_bundled/sirsoft-ecommerce/
├── module.json                  # 메타데이터 (이름, 버전, 설명 등 SSoT)
├── module.php                    # ModuleInterface 구현
├── LICENSE                      # 라이선스 전문 (MIT)
├── composer.json                 # 오토로딩 + 외부 패키지 의존성 설정
├── package.json                 # npm 패키지 정의 (에셋 모듈만)
├── vite.config.ts               # Vite 빌드 설정 (에셋 모듈만)
├── tsconfig.json                # TypeScript 설정 (에셋 모듈만)
├── vendor/                      # Composer 의존성 (자동 생성, gitignore 대상)
├── dist/                        # 프론트엔드 빌드 출력 (에셋 모듈만, gitignore 대상)
│   ├── js/module.iife.js
│   └── css/module.css
├── upgrades/                    # 버전 업그레이드 스텝 (AbstractUpgradeStep 상속 — g7_version >= 7.0.0-beta.5 모듈 의무)
│   ├── Upgrade_1_1_0.php        # 1.1.0 버전 업그레이드 스텝 (extends AbstractUpgradeStep)
│   └── data/                    # 버전별 데이터 스냅샷 — 카탈로그 delta / Applier / Migration 동결
│       └── 1.1.0/
│           ├── manifest.json    # kind → delta JSON 매핑
│           ├── *.delta.json     # added/removed/renamed 시드
│           ├── appliers/        # SnapshotApplier 구현 (버전 namespace)
│           └── migrations/      # DataMigration 구현 (변환/핫픽스, 버전 namespace)
├── config/
│   └── ecommerce.php            # 모듈 설정
├── database/
│   ├── factories/               # 테스트용 Factory
│   ├── migrations/              # 마이그레이션
│   └── seeders/                 # 시더
│       ├── DatabaseSeeder.php   # 메인 시더 (설치 + 조건부 샘플)
│       └── Sample/              # 샘플(개발용) 시더
├── lang/                        # 다국어 파일 (PHP 배열)
│   ├── en/
│   └── ko/
├── resources/                   # 리소스 파일
│   ├── js/                      # 프론트엔드 JS 소스 (에셋 모듈만)
│   │   ├── index.ts             # 엔트리 포인트
│   │   └── handlers/            # 커스텀 핸들러
│   ├── css/                     # CSS 소스 (에셋 모듈만)
│   │   └── main.css
│   ├── lang/                    # 프론트엔드 다국어 (JSON)
│   │   ├── ko.json             # 한국어 프론트엔드 다국어
│   │   └── en.json             # 영어 프론트엔드 다국어
│   ├── layouts/                 # 레이아웃 JSON
│   │   ├── admin/               # admin 템플릿 레이아웃
│   │   └── user/                # user 템플릿 레이아웃 (선택)
│   ├── routes/                  # 프론트엔드 라우트 정의
│   │   ├── admin.json           # admin 템플릿 전용 라우트
│   │   └── user.json            # user 템플릿 전용 라우트 (선택)
│   └── views/                   # 뷰 파일 (Blade 템플릿 등)
├── src/
│   ├── Contracts/              # 인터페이스
│   ├── Http/                   # HTTP 계층
│   │   ├── Controllers/        # 컨트롤러
│   │   │   └── Api/
│   │   │       └── Admin/
│   │   ├── Requests/           # FormRequest 클래스
│   │   └── Resources/          # API 리소스
│   ├── Listeners/              # 훅 리스너
│   ├── Models/                 # 모델
│   ├── Providers/              # 프로바이더
│   ├── Repositories/           # 리포지토리
│   ├── Seo/                    # SEO Sitemap 기여자, 캐시 무효화 리스너
│   ├── routes/                 # 라우트 (src 하위)
│   │   ├── api.php
│   │   └── web.php
│   └── Services/               # 서비스
└── tests/                       # 테스트 파일
    ├── Feature/                # 기능 테스트
    └── Unit/                   # 단위 테스트
```

### 디렉토리 설명

| 디렉토리 | 설명 |
|----------|------|
| `module.json` | 메타데이터 SSoT (이름, 버전, 설명, 의존성, 라이선스 등) — 버전 제약 정책은 [changelog-rules.md](changelog-rules.md#8-코어-버전-제약-정책) 참조 |
| `module.php` | ModuleInterface 구현 (진입점) |
| `LICENSE` | 라이선스 전문 (MIT 등) — API 엔드포인트 `GET /api/admin/modules/{identifier}/license`로 제공 |
| `composer.json` | PSR-4 오토로딩 + 외부 패키지 의존성 설정 |
| `package.json` | npm 패키지 정의 (에셋 모듈만 해당) |
| `vite.config.ts` | Vite IIFE 빌드 설정 (에셋 모듈만 해당) |
| `vendor/` | Composer 의존성 디렉토리 (자동 생성, gitignore 대상) |
| `dist/` | 프론트엔드 빌드 출력 (에셋 모듈만 해당, gitignore 대상) |
| `config/` | 모듈별 설정 파일 |
| `database/factories/` | 테스트용 Factory (autoload 등록 필수) |
| `database/migrations/` | 데이터베이스 마이그레이션 |
| `database/seeders/` | 데이터베이스 시더 (autoload 등록 필수). 설치 시더는 루트, 샘플 시더는 `Sample/` 하위 |
| `lang/` | 백엔드 다국어 (PHP 배열) |
| `resources/js/` | 프론트엔드 JS/TS 소스 — 핸들러 정의 등 (에셋 모듈만 해당) |
| `resources/css/` | CSS 소스 (에셋 모듈만 해당) |
| `resources/lang/` | 프론트엔드 다국어 (JSON) |
| `resources/layouts/` | 레이아웃 JSON (admin/, user/ 하위) |
| `resources/routes/admin.json` | admin 템플릿 전용 프론트엔드 라우트 |
| `resources/routes/user.json` | user 템플릿 전용 프론트엔드 라우트 (선택) |
| `src/Http/Controllers/` | 컨트롤러 |
| `src/Http/Requests/` | FormRequest 클래스 |
| `src/Http/Resources/` | API 리소스 |
| `src/Listeners/` | 훅 리스너 |
| `src/Models/` | Eloquent 모델 |
| `src/Repositories/` | 리포지토리 |
| `src/Seo/` | SEO Sitemap 기여자, 캐시 무효화 리스너 |
| `src/Services/` | 비즈니스 로직 서비스 |
| `src/routes/` | API/Web 라우트 |
| `tests/` | 테스트 파일 |

---

## 번들 디렉토리 작업 규칙

```text
필수: 모듈 수정/개발은 _bundled 디렉토리에서만 작업
필수: _bundled 작업 완료 후 반영/검증은 업데이트 프로세스 사용
```

### 개발 워크플로우

```text
1. modules/_bundled/{identifier}/ 에서 코드 수정
2. _bundled에서 직접 테스트 실행으로 검증 (활성 디렉토리 복사 불필요)
3. module.json 버전 올리기
4. php artisan module:update {identifier} 로 활성 디렉토리에 프로덕션 반영
```

### _bundled 직접 테스트 실행

_bundled에서 바로 PHPUnit/Vitest 테스트를 실행할 수 있습니다. `tests/bootstrap.php`가 _bundled 코드를 활성 디렉토리보다 우선 로드합니다.

```bash
# 백엔드 테스트 (_bundled에서 직접 실행)
php vendor/bin/phpunit modules/_bundled/{identifier}/tests
php vendor/bin/phpunit --filter=TestName modules/_bundled/{identifier}/tests

# 프론트엔드 테스트
cd modules/_bundled/{identifier}
powershell -Command "npm run test:run"
```

> 상세: [testing-guide.md](../testing-guide.md) "_bundled 확장 직접 테스트" 참조

### ModuleTestCase 작성 가이드 — 훅 리스너 등록 (CRITICAL)

```text
⚠️ module.php 가 getHookListeners() 를 선언한 모듈은 ModuleTestCase setUp 에서
   훅 리스너를 수동 등록해야 한다.
```

**왜 필요한가**:

- `ModuleManager::loadModules()` 는 활성 디렉토리(`modules/`) 만 스캔하고 `_bundled/` 는 메타데이터만 로드 (`loadBundledModules`)
- 테스트는 `_bundled/{identifier}/tests` 에서 직접 실행되므로 활성 디렉토리에 모듈이 설치되지 않은 환경
- 결과: `module.php::getHookListeners()` 가 선언한 리스너들이 HookManager 에 자동 등록되지 않음
- `applyFilters()` / `doAction()` 호출 시 등록된 리스너가 없어 default 빈 결과만 반환 → 알림 미발송, 캐시 무효화 누락 등 침묵 회귀 발생

**ModuleTestCase setUp 필수 패턴**:

```php
protected function setUp(): void
{
    parent::setUp();

    // 1. 오토로드 / ServiceProvider / 마이그레이션 / 역할 등 기존 로직
    $this->registerModuleAutoload();
    $this->app->register(\Modules\Vendor\YourModule\Providers\YourServiceProvider::class);
    $this->runModuleMigrationIfNeeded();
    $this->createDefaultRoles();

    // 2. _bundled 모듈 인스턴스 + 훅 리스너 수동 등록 (CRITICAL)
    $this->registerBundledModuleInstance();

    // 3. HookManager 스냅샷 (위 등록도 스냅샷에 포함되어 테스트 간 격리)
    $this->snapshotHookManager();
}

protected function registerBundledModuleInstance(): void
{
    $moduleClass = \Modules\Vendor\YourModule\Module::class;

    if (! class_exists($moduleClass)) {
        require_once $this->getModuleBasePath() . '/module.php';
    }

    $module = new $moduleClass();

    /** @var \App\Extension\ModuleManager $manager */
    $manager = $this->app->make(\App\Extension\ModuleManager::class);

    // ModuleManager.modules 메모리 맵에 인스턴스 주입
    $reflection = new \ReflectionClass($manager);
    $modulesProp = $reflection->getProperty('modules');
    $modulesProp->setAccessible(true);
    $current = $modulesProp->getValue($manager);
    if (! isset($current['vendor-your-module'])) {
        $current['vendor-your-module'] = $module;
        $modulesProp->setValue($manager, $current);
    }

    // module.php 의 getHookListeners() 가 선언한 리스너들을 HookManager 에 등록
    if (method_exists($module, 'getHookListeners')) {
        foreach ($module->getHookListeners() as $listenerClass) {
            if (! class_exists($listenerClass)) {
                continue;
            }
            if (! in_array(\App\Contracts\Extension\HookListenerInterface::class, class_implements($listenerClass), true)) {
                continue;
            }
            try {
                \App\Extension\HookListenerRegistrar::register($listenerClass, 'vendor-your-module');
            } catch (\Throwable $e) {
                // 중복 등록 등 무해한 예외는 무시
            }
        }
    }
}
```

**참조 구현**:

- `modules/_bundled/sirsoft-board/tests/ModuleTestCase.php`
- `modules/_bundled/sirsoft-page/tests/ModuleTestCase.php`

**누락 시 증상**:

- 알림 정의 기반 발송 테스트에서 `Notification::assertSentTo` 가 false 반환
- `extract_data` 필터의 `context.skip` / `related_users` / `data` 가 default 빈 결과로 받아져 정책 gate 무력화
- ActivityLog / Cache 등 리스너 의존 동작이 테스트에서만 미작동

**스냅샷/복원 패턴 필수**:

위 setUp 에서 등록한 훅도 `snapshotHookManager()` 로 캡처되어 `tearDown` 의 `restoreHookManager()` 가 테스트 간 격리를 보장한다. 테스트 본문에서 추가한 훅만 정리되고 setUp 의 훅은 보존된다.

### 왜 활성 디렉토리 직접 수정이 금지되는가?

- 활성 디렉토리는 `.gitignore` 대상 → Git에 변경 기록 불가
- 다음 업데이트 시 `_bundled` 소스로 덮어쓰기 → 직접 수정 사항 유실
- 업데이트 프로세스 미수행 시 마이그레이션/권한 동기화/레이아웃 갱신 누락

### 예외: 초기 개발 (아직 _bundled에 미등록)

```text
✅ 허용: 신규 모듈 초기 개발 시 활성 디렉토리에서 직접 작업
전환점: _bundled에 최초 반영한 이후부터는 반드시 _bundled에서만 작업
```

> 상세: [extension-update-system.md](./extension-update-system.md) "번들 디렉토리 개발 워크플로우" 참조

---

## 코드 변경 시 버전/업그레이드 필수

```text
필수: 모듈 코드를 변경한 경우 버전을 올리고 필요 시 업그레이드 스텝을 작성해야 합니다.
버전 변경 없이 _bundled에 반영하면, 이미 설치된 환경에서 업데이트가 감지되지 않습니다.
```

### 필수 작업

1. **`module.json` 버전 올리기**: `version` 필드를 Semantic Versioning에 따라 증가
2. **`_bundled` 동기화**: `modules/_bundled/{identifier}/` 디렉토리에 변경 사항 반영
3. **업그레이드 스텝 작성** (조건부): DB 스키마/환경설정 구조/데이터 마이그레이션이 필요한 경우

### 업그레이드 스텝 작성 기준

| 변경 유형 | 업그레이드 스텝 필요 | 비고 |
|----------|-------------------|------|
| DB 스키마 변경 | ✅ (+ 마이그레이션) | 컬럼/테이블 구조 변경 |
| 환경설정 구조 변경 | ✅ (SettingsMigrator) | 설정 키 이름/구조 변경 |
| 기존 데이터 변환 | ✅ | 데이터 형식 변환, 기본값 |
| 권한/역할/메뉴 추가·수정 | ❌ (자동 동기화) | Module.php에서 정의 |
| 정적 권한/메뉴 제거 | ✅ (cleanup 명시 호출) | #135: 동적 메뉴/권한 보존 |
| 레이아웃 JSON 변경 | ❌ (자동 갱신) | refresh-layout에서 처리 |
| PHP 코드만 변경 | ❌ | 버전만 올리면 됨 |

> 상세: [extension-update-system.md](./extension-update-system.md) "개발자 버전 업데이트 가이드" 참조

---

## SEO 변수 선언 (seoVariables)

모듈이 SEO 메타 데이터(제목/설명)에 동적 변수를 제공하려면 `seoVariables()` 메서드를 오버라이드합니다.

### 오버라이드 시점

- 모듈이 SEO 대상 페이지를 제공하는 경우 (상품 상세, 카테고리 목록 등)
- 모듈 설정의 SEO 템플릿(`seo.meta_{page_type}_title`)에서 `{key}` 변수 치환이 필요한 경우

### 구조

```php
public function seoVariables(): array
{
    return [
        // _common: 모든 page_type에 공통 적용
        '_common' => [
            'site_name' => ['source' => 'core_setting', 'key' => 'general.site_name'],
            'commerce_name' => ['source' => 'setting', 'key' => 'basic_info.shop_name'],
        ],
        // page_type별 변수
        'product' => [
            'product_name' => ['source' => 'data', 'key' => 'product.data.name'],
            'product_description' => ['source' => 'data', 'key' => 'product.data.short_description'],
        ],
        'category' => [
            'category_name' => ['source' => 'data', 'key' => 'category.data.name'],
        ],
        'search' => [
            'keyword_name' => ['source' => 'query', 'key' => 'q'],
        ],
    ];
}
```

### 소스 타입

| source | 설명 | 자동 해석 |
|--------|------|----------|
| `setting` | 해당 모듈의 설정 값 | ✅ |
| `core_setting` | 코어 설정 값 | ✅ |
| `query` | URL 쿼리 파라미터 | ✅ |
| `route` | URL 라우트 파라미터 | ✅ |
| `data` | 데이터소스 응답 데이터 (레이아웃 `vars`에서 매핑 필요) | ❌ |

### 레이아웃에서 사용

레이아웃 JSON의 `meta.seo.extensions`에 모듈을 선언하면 `seoVariables()`가 자동 호출됩니다.

```json
{
    "meta": {
        "seo": {
            "extensions": [{ "type": "module", "id": "sirsoft-ecommerce" }],
            "page_type": "product",
            "vars": {
                "product_name": "{{product.data.name ?? ''}}",
                "product_description": "{{product.data.short_description ?? ''}}"
            }
        }
    }
}
```

- `setting`, `core_setting`, `query`, `route` 소스는 SeoRenderer가 자동 해석
- `data` 소스 변수는 `vars`에서 표현식으로 매핑 필요
- 설치 시 `ValidatesSeoVariables` 트레이트가 변수명 고유성 검증

> 상세: [seo-system.md](../backend/seo-system.md) "SEO 변수 시스템" 참조

---

## 관련 문서

- [index.md](./index.md) - 확장 시스템 개요
- [hooks.md](./hooks.md) - 훅 시스템
- [module-routing.md](./module-routing.md) - 모듈 라우트 규칙
- [module-layouts.md](./module-layouts.md) - 모듈 레이아웃 시스템
- [module-assets.md](./module-assets.md) - 모듈 프론트엔드 에셋 시스템
- [module-commands.md](./module-commands.md) - 모듈 Artisan 커맨드
- [module-i18n.md](./module-i18n.md) - 모듈 다국어
- [활동 로그 시스템](../backend/activity-log.md) - 활동 로그 Listener, DescriptionResolver, Per-Item Bulk 로깅 규칙
- [extension-update-system.md](./extension-update-system.md) - 확장 업데이트 시스템
- [permissions.md](./permissions.md) - 권한 시스템
- [menus.md](./menus.md) - 메뉴 시스템
