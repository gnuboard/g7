# 메뉴 시스템

> G7의 메뉴 권한 시스템과 메뉴 시더 작성 규칙을 설명합니다.

---

## TL;DR (5초 요약)

```text
1. 구조: User → Role → role_menus 피벗 → Menu
2. 권한: MenuPermissionType (read, write, delete)
3. 모든 메뉴: role_menus에 명시적 역할 권한 필요 (코어/확장 구분 없음)
4. 자동 부여: 메뉴 생성 시 관리자 역할 + 생성자/설치자 역할 자동 부여
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
            'icon' => 'fa-shopping-cart',
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

## 관련 문서

- [permissions.md](./permissions.md) - 권한 시스템 (Role, Permission, 리소스 레벨 권한)
- [module-basics.md](./module-basics.md) - 모듈 기본 구조
- [index.md](./index.md) - 확장 시스템 인덱스
