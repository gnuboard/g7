# 메뉴 시스템

> G7의 메뉴 권한 시스템과 메뉴 시더 작성 규칙을 설명합니다.

---

## TL;DR (5초 요약)

```text
1. 구조: User → Role → role_menus 피벗 → Menu
2. 권한: MenuPermissionType (read, write, delete)
3. 모든 메뉴: role_menus에 명시적 역할 권한 필요 (코어/확장 구분 없음)
4. 자동 부여: 메뉴 생성 시 관리자 역할 + 생성자/설치자 역할 자동 부여, 확장이 선언한 역할에는 그 역할 권한으로 열 수 있는 확장 메뉴 부여
5. 시더: Menu::create() + grantAdminRoleToMenus() 패턴
```

---

## 목차

1. [메뉴 권한 아키텍처](#메뉴-권한-아키텍처)
2. [role_menus 피벗 테이블](#role_menus-피벗-테이블)
3. [MenuPermissionType Enum](#menupermissiontype-enum)
4. [Menu 모델의 HasRoleBasedAccess 구현](#menu-모델의-hasrolebasedaccess-구현)
5. [메뉴 시더에서 권한 부여](#메뉴-시더에서-권한-부여)
6. [메뉴 권한 확인 방법](#메뉴-권한-확인-방법)
7. [권한 체계 요약](#권한-체계-요약)

---

## 메뉴 권한 아키텍처

G7에서 메뉴 권한은 **역할 기반(Role-based)**으로 관리됩니다.

```
User → Role → role_menus → Menu
              (pivot)
```

### 코어 메뉴 vs 모듈 메뉴

| 메뉴 유형 | 식별 | 기본 정책 |
|----------|------|----------|
| 코어 메뉴 | `extension_type = 'core'` | role_menus에 명시적 역할 권한 필요 |
| 모듈 메뉴 | `extension_type = 'module'` | 확장 활성 상태 + 명시적 역할 권한 필요 |
| 플러그인 메뉴 | `extension_type = 'plugin'` | 확장 활성 상태 + 명시적 역할 권한 필요 |
| 사용자 생성 메뉴 | `extension_type = NULL` | role_menus에 명시적 역할 권한 필요 |

---

## role_menus 피벗 테이블

### 테이블 구조

```php
Schema::create('role_menus', function (Blueprint $table) {
    $table->id();
    $table->foreignId('role_id')->constrained()->cascadeOnDelete();
    $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
    $table->enum('permission_type', ['read', 'write', 'delete'])->default('read');
    $table->timestamps();

    $table->unique(['role_id', 'menu_id', 'permission_type'], 'uk_role_menu_permission');
});
```

### 설계 원칙

- **레코드 존재 = 권한 허용**: `is_allowed` 컬럼 없이 레코드 존재 여부로 판단
- **복합 유니크 인덱스**: 동일 역할-메뉴에 여러 권한 타입 부여 가능
- **Cascade Delete**: 역할 또는 메뉴 삭제 시 자동 정리

---

## MenuPermissionType Enum

```php
// app/Enums/MenuPermissionType.php

enum MenuPermissionType: string
{
    case Read = 'read';
    case Write = 'write';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Read => '읽기/접근',
            self::Write => '수정',
            self::Delete => '삭제',
        };
    }
}
```

### 권한 타입 설명

| 타입 | 값 | 설명 |
|------|-----|------|
| Read | `read` | 메뉴 조회/접근 권한 |
| Write | `write` | 메뉴 관련 데이터 수정 권한 |
| Delete | `delete` | 메뉴 관련 데이터 삭제 권한 |

---

## Menu 모델의 HasRoleBasedAccess 구현

`Menu` 모델은 `HasRoleBasedAccess` 인터페이스를 구현하여 역할 기반 접근 제어를 지원합니다.

### 인터페이스 구현

```php
// app/Models/Menu.php

class Menu extends Model implements HasRoleBasedAccess
{
    /**
     * 접근 가능한 역할들 (권한 타입 포함)
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_menus')
            ->withPivot('permission_type')
            ->withTimestamps();
    }

    /**
     * 특정 권한 타입을 가진 역할들 조회
     */
    public function rolesWithPermission(BackedEnum|string $permissionType): BelongsToMany
    {
        $type = $permissionType instanceof BackedEnum
            ? $permissionType->value
            : $permissionType;

        return $this->belongsToMany(Role::class, 'role_menus')
            ->withPivot('permission_type')
            ->withTimestamps()
            ->wherePivot('permission_type', $type);
    }

    /**
     * 역할 권한이 설정되어 있는지 확인
     */
    public function hasRolePermissions(): bool
    {
        return $this->roles()->exists();
    }

    /**
     * 리소스 소유자의 ID 반환
     */
    public function getOwnerId(): ?int
    {
        return $this->created_by;
    }
}
```

### scopeAccessibleBy 쿼리 스코프

```php
/**
 * 사용자에게 접근 가능한 메뉴들만 조회하는 스코프
 *
 * 권한 체크 로직:
 * 1. 관리자는 모든 메뉴 접근 가능
 * 2. 코어/사용자 생성 메뉴: role_menus에 명시적 역할 권한 필요
 * 3. 모듈/플러그인 메뉴: 확장이 활성화되어 있고 역할 권한이 있어야 접근 가능
 *
 * 메뉴 생성 시 관리자 역할 + 생성자/설치자 역할이 자동 부여되므로
 * role_menus 레코드가 없는 메뉴는 누구에게도 보이지 않습니다.
 */
public function scopeAccessibleBy(Builder $query, User $user): Builder
{
    if ($user->hasRole('admin')) {
        return $query;
    }

    $userRoleIds = $user->roles()->pluck('roles.id')->toArray();

    return $query->where(function (Builder $query) use ($userRoleIds) {
        // 코어/사용자 생성 메뉴: 명시적 역할 권한 필요
        $query->where(function (Builder $q) use ($userRoleIds) {
            $q->where(function ($q2) {
                    $q2->whereNull('extension_type')
                        ->orWhere('extension_type', 'core');
                })
                ->whereHas('roles', function (Builder $roleQ) use ($userRoleIds) {
                    $roleQ->whereIn('roles.id', $userRoleIds)
                        ->where('role_menus.permission_type', MenuPermissionType::Read->value);
                });
        });
    })->orWhere(function (Builder $query) use ($userRoleIds) {
        // 모듈/플러그인 메뉴 (확장이 활성화되어 있고 역할 권한이 있는 경우)
        $query->whereIn('extension_type', ['module', 'plugin'])
            ->whereHas('roles', function (Builder $roleQ) use ($userRoleIds) {
                $roleQ->whereIn('roles.id', $userRoleIds)
                    ->where('role_menus.permission_type', MenuPermissionType::Read->value);
            });
    });
}
```

---

## 메뉴 시더에서 권한 부여

### 예시: EcommerceMenuSeeder

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Menu;
use App\Models\Role;
use App\Models\Module;
use App\Enums\MenuPermissionType;

class EcommerceMenuSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('이커머스 메뉴 생성을 시작합니다.');

        $module = Module::where('identifier', 'sirsoft-ecommerce')->first();

        // 메뉴 생성
        $ecommerceMenu = Menu::create([
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'slug' => 'sirsoft-ecommerce',
            'url' => '/admin/sirsoft-ecommerce',
            'icon' => 'fas fa-shopping-cart',
            'module_id' => $module->id,
        ]);

        // 권한 부여
        $this->grantMenuPermissions($module->id);

        $this->command->info('이커머스 메뉴가 생성되었습니다.');
    }

    /**
     * 역할에 메뉴 권한 부여
     */
    private function grantMenuPermissions(int $moduleId): void
    {
        $adminRole = Role::where('identifier', 'admin')->first();
        $managerRole = Role::where('identifier', 'sirsoft-ecommerce.manager')->first();

        $ecommerceMenus = Menu::where('module_id', $moduleId)->get();

        foreach ($ecommerceMenus as $menu) {
            // 관리자: 모든 권한
            $adminRole->menus()->attach($menu->id, [
                'permission_type' => MenuPermissionType::Read->value,
            ]);
            $adminRole->menus()->attach($menu->id, [
                'permission_type' => MenuPermissionType::Write->value,
            ]);
            $adminRole->menus()->attach($menu->id, [
                'permission_type' => MenuPermissionType::Delete->value,
            ]);

            // 매니저: 읽기/쓰기 권한만
            if ($managerRole) {
                $managerRole->menus()->attach($menu->id, [
                    'permission_type' => MenuPermissionType::Read->value,
                ]);
                $managerRole->menus()->attach($menu->id, [
                    'permission_type' => MenuPermissionType::Write->value,
                ]);
            }
        }

        $this->command->info("역할에 {$ecommerceMenus->count()}개의 메뉴 권한을 부여했습니다.");
    }
}
```

### syncWithoutDetaching 사용 (기존 권한 유지)

```php
// 기존 권한을 유지하면서 새 권한 추가
$role->menus()->syncWithoutDetaching([
    $menuId => ['permission_type' => 'read'],
]);
```

---

## 메뉴 권한 확인 방법

### 1. User 모델에서 확인

```php
// 기본 (read 권한)
$user->hasMenuPermission($menuId);

// 특정 권한 타입
$user->hasMenuPermission($menuId, 'write');
$user->hasMenuPermission($menuId, MenuPermissionType::Delete);
```

### 2. 쿼리 스코프로 필터링

```php
// 사용자가 접근 가능한 메뉴 조회
$accessibleMenus = Menu::accessibleBy($user)->get();

// 계층 구조와 함께 조회
$accessibleMenus = Menu::accessibleBy($user)
    ->whereNull('parent_id')
    ->with(['children' => function ($query) use ($user) {
        $query->accessibleBy($user);
    }])
    ->get();
```

### 3. Role 모델에서 확인

```php
// 역할에 메뉴 권한 부여
$role->menus()->attach($menuId, ['permission_type' => 'read']);

// 역할의 모든 메뉴 조회
$role->menus()->get();

// 특정 권한 타입의 메뉴만 조회
$role->menus()->wherePivot('permission_type', 'write')->get();
```

---

## 권한 체계 요약

### 권한 체크 흐름

```
┌─────────────────────────────────────────────────────────────┐
│  1. 관리자(admin) 역할 확인                                  │
│     → admin이면 모든 메뉴 접근 허용                          │
├─────────────────────────────────────────────────────────────┤
│  2. 코어/사용자 생성 메뉴 (extension_type = 'core' 또는 NULL)│
│     → role_menus에 명시적 역할 권한 필요                    │
│     → 메뉴 생성 시 관리자+생성자 역할 자동 부여             │
├─────────────────────────────────────────────────────────────┤
│  3. 모듈/플러그인 메뉴 (extension_type = 'module'/'plugin') │
│     → 확장이 활성화(active) 상태여야 함                      │
│     → role_menus에 명시적 권한 필요                         │
└─────────────────────────────────────────────────────────────┘
```

### 권한 타입별 정리

| 권한 타입 | 용도 | 예시 |
|----------|------|------|
| `read` | 메뉴 표시, 페이지 접근 | 네비게이션 표시 |
| `write` | 데이터 생성/수정 | 상품 등록, 수정 |
| `delete` | 데이터 삭제 | 상품 삭제 |

### 동적 메뉴 보존

모듈/플러그인이 런타임에 동적으로 생성한 메뉴(예: 게시판 모듈의 개별 게시판 메뉴)는 확장 업데이트 시 자동으로 보존됩니다.

- `cleanupStaleMenus()`/`cleanupStalePermissions()`의 **자동 호출은 폐기**되었습니다
- 정적 메뉴/권한의 추가·수정은 업데이트 시 자동 동기화됩니다
- 정적 메뉴/권한을 **제거**해야 하는 경우, UpgradeStep에서 cleanup helper를 명시적으로 호출하세요
- 상세: [extension-update-system.md](./extension-update-system.md) "정적 메뉴/권한 제거 시 cleanup 사용 예시" 참조

### 메뉴 역할 할당 규칙

운영자는 메뉴의 접근 역할을 세 경로로 바꿉니다 — 메뉴 단건 편집, 역할 편집 화면의 「접근 가능 메뉴」(`menu_ids`), 메뉴 관리의 「여러 개 선택」(`PATCH /api/admin/menus/bulk-roles`). 세 경로는 같은 규칙을 따릅니다.

- **관리자(`admin`) 역할은 모든 메뉴에 항상 있습니다.** 메뉴 편집에서 역할을 모두 빼도 관리자 역할은 남고, 관리자 역할을 빼는 요청은 거부(422)됩니다.
- **상·하위는 함께 움직입니다.** 하위 메뉴에 역할을 주면 상위 메뉴에도 주고, 상위 메뉴에서 빼면 하위 메뉴에서도 뺍니다.
- **액터가 관리할 수 없는 메뉴(비활성 확장 메뉴 등)의 기존 할당은 건드리지 않습니다.**
- 바뀐 메뉴마다 `core.menu.after_sync_roles` 가 발화하고, 운영자가 바꾼 역할은 `user_overrides` 에 기록돼 확장 업데이트가 덮어쓰지 않습니다. 일괄 경로는 앞뒤로 `core.menu.before_bulk_update_roles`·`core.menu.after_bulk_update_roles` 도 발화합니다.
- 관리 목록(`GET /api/admin/menus`)은 메뉴마다 부여된 역할 ID 를 `role_ids` 로 싣습니다. 역할 이름·권한 타입은 단건 조회(`GET /api/admin/menus/{id}`)의 `roles` 를 씁니다.

### 확장이 선언한 역할의 메뉴

확장이 `getRoles()` 로 선언한 역할(예: 이커머스 관리자, SSO 운영자)에는 확장 메뉴 동기화가 그 확장 메뉴 중 **역할이 가진 권한으로 열 수 있는 메뉴**를 붙입니다. 새 설치, 확장 업데이트, 플러그인 활성화, 활성 확장 재동기화가 모두 같은 경로(`ExtensionMenuSyncHelper::grantMenusToDeclaredRoles()`)를 지나므로, 이미 운영 중인 사이트도 다음 동기화 때 채워집니다.

- 메뉴의 필요 권한은 `getAdminMenus()` 정의의 `permission` 키입니다. 그 화면이 쓰는 목록 API 의 권한을 적습니다. 권한 범위(본인 것만 등)가 있는 권한도 가진 것으로 봅니다.
- 필요 권한이 없는 상위 메뉴(하위 메뉴 묶음)는 붙는 하위 메뉴가 하나라도 있을 때 붙습니다.
- `permission` 을 적지 않은 단독 메뉴는 판정할 근거가 없어 선언 역할에 붙이지 않습니다. 이런 메뉴를 선언 역할에 보이려면 `permission` 을 적거나 확장이 직접 붙입니다.
- 운영자가 역할 관리·메뉴 관리에서 그 역할을 넣거나 뺀 메뉴(메뉴 `user_overrides` 에 역할 식별자)는 건드리지 않습니다. 상위 메뉴에서 뺐으면 그 아래 메뉴도 붙이지 않습니다.
- 붙이기만 하고 떼지 않습니다. 역할에서 권한을 빼도 이미 붙은 메뉴는 남으므로, 메뉴도 함께 빼려면 역할 관리의 「접근 가능 메뉴」에서 뺍니다.

### 확장 비활성화와 메뉴

모듈의 관리자 메뉴는 코어가 모듈을 제거(uninstall)할 때만 지웁니다. 플러그인은 메뉴 정리를 플러그인 자신이 하므로, **번들 플러그인**은 같은 방식을 관례로 따릅니다 — 관리자 메뉴를 **비활성화할 때 지우지 않고, 제거할 때 지웁니다.** 비활성 확장의 메뉴는 위 「권한 체크 흐름」 3번에 따라 사이드바에 보이지 않으므로 지울 필요가 없고, 지웠다가 재활성화 때 새로 만들면 운영자가 그 메뉴에 지정해 둔 역할이 사라집니다. 번들 플러그인은 활성화 때 기본 역할을 메뉴에 다시 붙이더라도, 운영자가 그 역할을 뺀 메뉴(`user_overrides` 에 역할 식별자가 있는 메뉴)는 건너뜁니다.

제3자 플러그인에는 이 방식을 강제하지 않습니다. 다만 `deactivate()` 에서 메뉴를 지우면 재활성화 때 운영자의 역할 지정이 사라지므로, 메뉴 정리는 `uninstall()` 에서 하고 기본 역할을 다시 붙일 때는 `user_overrides` 를 확인하는 것을 권장합니다.

### Deprecated 항목

다음 항목들은 더 이상 사용하지 않습니다:

| 항목 | 대체 방법 |
|------|----------|
| `menu_permissions` 테이블 | `role_menus` 피벗 테이블 |
| `MenuPermission` 모델 | `Role::menus()` 관계 |
| `is_allowed` 컬럼 | 레코드 존재 여부로 판단 |
| `User::menuPermissions()` | `User::hasMenuPermission()` |
| `module_id` 컬럼 | `extension_type` + `extension_identifier` |
| `module_identifier` 컬럼 | `extension_identifier` |

---

## 완전 동기화 (Stale Cleanup)

코어/확장 업그레이드 시 메뉴 동기화는 **완전 동기화** — config/manifest 에서 제거된 메뉴는 DB 에서도 삭제됩니다. 유지되는 메뉴는 사용자 수정(`user_overrides` 컬럼) 에 따라 필드 단위 보존이 적용됩니다.

### 적용 지점

| 경로 | 호출 시점 | cleanup 대상 |
|---|---|---|
| `CoreUpdateService::syncCoreMenus` 말미 | 코어 업데이트 | `core/core` 소유 메뉴 전체 slug |
| `ModuleManager::updateModule` 내 sync 블록 말미 | 모듈 업데이트 | 해당 모듈 소유 메뉴 |

### 핵심 정책

- **row 존재 여부**: config 기준 (user_overrides 무관 삭제)
- **필드 값 (유지 row)**: user_overrides 에 등록된 필드 (`name`, `icon`, `order`, `url`, `is_active`, `parent_id`) 보존, 나머지 갱신
- **`is_active`**: config/manifest 정의값 채택 (기본 `true`). `is_active: false` 로 명시 정의된 메뉴는 sync 시에도 비활성 상태 유지. 운영자가 UI 에서 토글한 경우 `user_overrides` 에 `'is_active'` 가 마킹되어 재시드 시 운영자 선택 보존
- **role-menu 매핑**: 메뉴 삭제 시 `role_menus` 피벗은 FK cascade 로 자동 정리
- **user_overrides 검사**: 다국어 컬럼(`name`)은 column-level (`'name'`, legacy) + dot-path sub-key (`'name.ko'` / `'name.en'`, beta.4 도입) 양쪽 호환. 어느 형태로든 마킹되어 있으면 컬럼 전체를 보존 대상으로 간주

### 사용자 수정 기록

`Menu` 모델은 `HasUserOverrides` trait + `$trackableFields = ['name', 'icon', 'order', 'url', 'is_active', 'parent_id']` 적용. 사용자가 UI/API 로 수정하면 trait 이 자동으로 user_overrides 에 누적 기록합니다 (mass update 포함).

`icon` 은 정규형으로 비교합니다 — 레거시 문자열 `'fas fa-users'` 와 구조형 `['type' => 'class', 'value' => 'fas fa-users']`, 공백만 다른 `' fas  fa-users '` 는 모두 같은 값이라, 같은 아이콘을 다시 저장해도 수정으로 기록되지 않고 이후 확장 업데이트의 아이콘 갱신이 계속 들어옵니다.

### Helper (권장)

직접 Model 조작 금지. `ExtensionMenuSyncHelper` 를 통해 sync/cleanup 수행. 자세한 사용법은 [data-sync-helpers.md](../backend/data-sync-helpers.md#3-extensionmenusynchelper) 참조.

## 메뉴 아이콘 지정

`getAdminMenus()`(플러그인도 같음)·`config/core.php` 의 `icon` 은 세 형태를 받습니다(코어 7.2.1).

```php
'icon' => 'fas fa-folder',                                   // Font Awesome 클래스 (fa-solid fa-folder 도 같음)
'icon' => ['type' => 'class', 'value' => 'fa-regular fa-star'],
'icon' => ['type' => 'image', 'path' => 'assets/menu.png'],  // 확장 루트 기준 상대경로 — png·webp·jpg·jpeg·gif
'icon' => ['type' => 'svg', 'path' => 'assets/menu.svg'],    // 설치·업데이트 때 읽어 검사한 뒤 인라인 SVG 코드로 저장
'icon' => ['type' => 'svg', 'value' => '<svg viewBox="0 0 24 24"><path fill="currentColor" d="…"/></svg>'],
```

- **클래스**: 스타일까지 적은 완전한 클래스(`fas fa-x`·`fa-solid fa-x`·`far`·`fab`)를 씁니다. 스타일 없는 `fa-x` 는 쓰지 않습니다.
- **이미지**: 자기 패키지 안 파일만 됩니다. 응답 시점에 확장 자산 주소(버전 쿼리 포함)로 바뀌어 하위 폴더 설치·정적 캐시에서도 그대로 보입니다. `/` 절대경로·`http(s)://`·`//`·`data:`·`..`·역슬래시·`.svg` 는 거부합니다.
- **SVG**: 32KB 이하, 허용한 요소만 쓸 수 있습니다 — `svg`·`g`·`defs`·`title`·`desc`·`rect`·`circle`·`ellipse`·`line`·`polyline`·`polygon`·`path`·`text`·`tspan`·`linearGradient`·`radialGradient`·`stop`·`clipPath`. 그 밖의 요소(`style`·`use`·`image`·`foreignObject`·`mask`·`pattern`·`filter`·`symbol`·애니메이션 등)와 스크립트·`on*` 속성·외부 `href` 는 거부합니다. 화면은 한 번 더 정제해서 기본 도형 속성만 남기고 그리므로(`style`·`class` 같은 속성은 지워짐) 색은 `fill`·`stroke` 속성으로 주세요. `fill="currentColor"` 를 쓰면 메뉴 글자색(라이트·다크)을 따릅니다. 같은 SVG 가 여러 메뉴에 있으면 `id` 가 겹쳐 그라디언트 참조가 엉킬 수 있으니 단색·`currentColor` 를 권장합니다.
- **잘못된 선언**(없는 파일·금지 경로·위험 SVG·알 수 없는 형)은 설치·업데이트를 막지 않습니다. 그 메뉴만 아이콘 없이 저장하고, 로그(`메뉴 아이콘 선언 무시`)와 라이프사이클 경고 `menu_icon`(관리자 결과 창 「메뉴 아이콘」)으로 알립니다.
- 운영자가 관리자 화면에서 아이콘을 바꾸면(업로드 포함) `user_overrides` 에 `icon` 이 기록되어 이후 업데이트가 덮지 않습니다.
- 메뉴 API 는 `icon`(클래스일 때만 문자열, 이미지·SVG 면 null — 외부 관리자 템플릿 호환)과 `icon_spec`(구조형)을 함께 내려 줍니다 — [admin-template-contract.md](admin-template-contract.md).
- `core.menu.*` 훅이 넘기는 메뉴 모델의 `icon` 값은 클래스 아이콘이면 클래스 문자열, 이미지·SVG 아이콘이면 구조형 JSON 문자열입니다. 서버 코드에서 해석할 때는 `MenuIconSpec::fromStored($menu->icon)` 을 쓰고, 화면에 쓸 값은 메뉴 API 의 `icon_spec` 을 씁니다.

---

## 관련 문서

- [permissions.md](./permissions.md) - 권한 시스템 (Role, Permission, 리소스 레벨 권한)
- [module-basics.md](./module-basics.md) - 모듈 기본 구조
- [README.md](./README.md) - 확장 시스템 인덱스
- [../backend/core-config.md](../backend/core-config.md#완전-동기화-원칙) - 완전 동기화 원칙
- [../backend/data-sync-helpers.md](../backend/data-sync-helpers.md) - 데이터 동기화 Helper
- [../backend/user-overrides.md](../backend/user-overrides.md) - 사용자 수정 보존
