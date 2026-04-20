# 코어 설정 (config/core.php)

> **목적**: 코어 시스템의 권한, 역할, 메뉴, 메일 템플릿 정의 파일 구조 설명

---

## TL;DR (5초 요약)

```text
1. config/core.php = 코어 권한/역할/메뉴/메일템플릿의 SSoT (Single Source of Truth)
2. 구조: module(1레벨) → categories(2레벨) → permissions(3레벨) 3단계
3. 설치(Seeder) + 업데이트(CoreUpdateService) 모두 이 파일에서 읽음
4. 모듈/플러그인은 config/core.php 대신 자체 config.php에 동일 구조로 정의
5. 수정 시 Seeder/Sync 로직이 자동 반영 (수동 마이그레이션 불필요)
```

---

## 목차

- [파일 구조 개요](#파일-구조-개요)
- [permissions — 권한 정의](#permissions--권한-정의)
- [roles — 역할 정의](#roles--역할-정의)
- [menus — 메뉴 정의](#menus--메뉴-정의)
- [사용처](#사용처)
- [관련 문서](#관련-문서)

---

## 파일 구조 개요

`config/core.php`는 3개 최상위 키로 구성됩니다:

```php
return [
    'permissions' => [...],     // 코어 권한 정의
    'roles' => [...],           // 코어 역할 정의
    'menus' => [...],           // 코어 메뉴 정의
];
```

> **변경 이력 (7.0.0-beta.2)**: `mail_templates` 키는 알림 시스템 통합(#146)으로 제거되었습니다. 코어 메일 템플릿은 `notification_definitions` + `notification_templates` 로 이전되었으며, `database/seeders/NotificationDefinitionSeeder` 가 시드 데이터를 직접 보유합니다.

---

## permissions — 권한 정의

3레벨 계층 구조:

```text
permissions
├── module (1레벨)           → identifier, name, description, order
└── categories (2레벨 배열)
    ├── category 항목        → identifier, name, description, category, order
    └── permissions (3레벨)  → identifier, name, description, order, resource_route_key?, owner_key?
```

### module (1레벨)

```php
'module' => [
    'identifier' => 'core',
    'name' => ['ko' => '코어', 'en' => 'Core'],
    'description' => ['ko' => '코어 시스템 권한', 'en' => 'Core system permissions'],
    'order' => 1,
],
```

### categories (2레벨) + permissions (3레벨)

```php
'categories' => [
    [
        'identifier' => 'core.users',
        'name' => ['ko' => '사용자 관리', 'en' => 'User Management'],
        'description' => [...],
        'category' => 'users',     // 카테고리 슬러그
        'order' => 1,
        'type' => 'admin',         // 카테고리 타입 (선택, 미지정 시 하위 권한에서 추론)
        'permissions' => [
            [
                'identifier' => 'core.users.read',
                'name' => ['ko' => '사용자 조회', 'en' => 'View Users'],
                'description' => [...],
                'order' => 1,
                'type' => 'admin',               // 권한 타입 (선택, 미지정 시 카테고리 타입 상속)
                'resource_route_key' => 'user',  // scope 체크 시 라우트 모델 키 (선택)
                'owner_key' => 'id',             // scope 체크 시 소유자 필드 (선택)
            ],
        ],
    ],
],
```

### 권한 필드 설명

| 필드 | 필수 | 설명 |
| ---------- | ---------- | ---------- |
| `identifier` | ✅ | 권한 식별자 (예: `core.users.read`) |
| `name` | ✅ | 다국어 이름 배열 `['ko' => ..., 'en' => ...]` |
| `description` | ✅ | 다국어 설명 배열 |
| `order` | ✅ | 정렬 순서 |
| `type` | ❌ | 권한 컨텍스트 (`admin` / `user`). 미지정 시 카테고리 타입 상속, 카테고리도 미지정이면 `admin` 기본값 |
| `resource_route_key` | ❌ | scope 기반 접근 체크 시 라우트 모델 바인딩 키 |
| `owner_key` | ❌ | scope 기반 접근 체크 시 소유자 판단 필드 |

### type 필드 우선순위 (카테고리/권한 동기화 규칙)

`CoreUpdateService::syncCoreRolesAndPermissions()`는 다음 우선순위로 type을 결정합니다:

1. **명시 우선**: 카테고리/권한에 `type` 필드가 명시되어 있으면 그 값 사용
2. **하위 추론**: 카테고리에 type 미지정 + 모든 하위 권한이 동일 type → 그 type을 카테고리에도 적용
3. **기본값**: 그 외 → `admin`

권한 레벨 type이 미지정이면 카테고리 type을 상속합니다. (`ModuleManager::syncPermissions()`와 동일 패턴)

### admin/user 권한 분리 사례

같은 도메인이지만 관리자와 사용자 컨텍스트에서 모두 사용해야 한다면 **별도 식별자**로 분리합니다:

```php
// 관리자가 시스템 전체 알림을 관리 (관리자 화면)
[
    'identifier' => 'core.notifications',
    'name' => ['ko' => '알림 (관리자)', 'en' => 'Notifications (Admin)'],
    'type' => 'admin',
    'permissions' => [
        ['identifier' => 'core.notifications.read', 'type' => 'admin', ...],
        ['identifier' => 'core.notifications.update', 'type' => 'admin', ...],
        ['identifier' => 'core.notifications.delete', 'type' => 'admin', ...],
    ],
],
// 사용자가 본인의 알림을 관리 (사용자 화면)
[
    'identifier' => 'core.user-notifications',
    'name' => ['ko' => '알림 (사용자)', 'en' => 'Notifications (User)'],
    'type' => 'user',
    'permissions' => [
        ['identifier' => 'core.user-notifications.read', 'type' => 'user', ...],
        ['identifier' => 'core.user-notifications.update', 'type' => 'user', ...],
        ['identifier' => 'core.user-notifications.delete', 'type' => 'user', ...],
    ],
],
```

```text
⚠️ 함정 경고: permissions.identifier에 단일 unique 제약이 있어 같은 식별자로 admin/user 두 행을 동시에 만들 수 없습니다.
✅ 사용자 컨텍스트 권한이 필요하면 반드시 별도 식별자(예: core.user-notifications.*)를 사용하세요.
✅ 라우트 미들웨어 permission:user,xxx 는 type=user 권한 행이 존재해야만 통과합니다 (식별자 + type 모두 매칭).
```

---

## roles — 역할 정의

```php
'roles' => [
    [
        'identifier' => 'admin',
        'name' => ['ko' => '관리자', 'en' => 'Administrator'],
        'description' => [...],
        'attributes' => ['is_active' => true],
        'permissions' => 'all_leaf',  // 특수 값: 모든 리프 권한 할당
    ],
    [
        'identifier' => 'manager',
        'name' => ['ko' => '매니저', 'en' => 'Manager'],
        'permissions' => ['core.users.read', 'core.users.create', ...],
        'permission_scopes' => [
            'core.users.read' => 'self',     // 본인 리소스만
            'core.users.update' => 'self',
        ],
    ],
],
```

### 역할 필드 설명

| 필드 | 필수 | 설명 |
| ---------- | ---------- | ---------- |
| `identifier` | ✅ | 역할 식별자 (예: `admin`, `manager`) |
| `name` | ✅ | 다국어 이름 배열 |
| `description` | ✅ | 다국어 설명 배열 |
| `attributes` | ❌ | 추가 속성 (`is_active` 등) |
| `permissions` | ✅ | 권한 배열 또는 `'all_leaf'` (모든 리프 권한) |
| `permission_scopes` | ❌ | 권한별 scope_type 매핑 (`'self'` / `'role'` / 미지정=전체) |

---

## menus — 메뉴 정의

```php
'menus' => [
    [
        'slug' => 'admin-dashboard',
        'name' => ['ko' => '대시보드', 'en' => 'Dashboard'],
        'url' => '/admin/dashboard',
        'icon' => 'fas fa-tachometer-alt',
        'parent_id' => null,
        'order' => 1,
        'is_active' => true,
    ],
],
```

---

## 사용처

| 사용처 | 시점 | 설명 |
| ---------- | ---------- | ---------- |
| `RolePermissionSeeder` | 초기 설치 | permissions, roles 데이터 시딩 |
| `CoreAdminMenuSeeder` | 초기 설치 | menus 데이터 시딩 |
| `CoreUpdateService::syncCoreRolesAndPermissions()` | 코어 업데이트 | 권한/역할 동기화 |
| `CoreUpdateService::syncCoreMenus()` | 코어 업데이트 | 메뉴 동기화 |

**핵심 원칙**: 새 권한/역할/메뉴 추가 시 이 파일에 추가하면 설치/업데이트 모두 자동 반영됩니다.

---

## 완전 동기화 원칙

`config/core.php` 는 **코어 권한·역할·메뉴의 SSoT** 입니다. 업데이트 시 sync 는 단순 upsert 가 아니라 **완전 동기화** 를 수행합니다.

### 4단계 패턴

| 단계 | 설명 | 책임 |
|---|---|---|
| 1. **Upsert** | config → DB (신규 생성 or user_overrides 보존 업데이트) | `syncMenu`/`syncRole`/`syncPermission` |
| 2. **Orphan Delete** | config 에 없는 DB row 삭제 (user_overrides 무관) | `cleanupStaleMenus`/`cleanupStaleRoles`/`cleanupStalePermissions` |
| 3. **Mapping Diff** | role_permissions 등 관계 테이블 재정렬 | `syncAllRoleAssignments` |
| 4. **Dependent Cleanup** | FK cascade 또는 명시적 정리 | Service 책임 |

### 중요한 의미 구분

- **row 존재 여부**: config 기준으로만 결정 — user_overrides 무관 (제거된 건 삭제)
- **필드 값 (유지 row)**: user_overrides 에 등록된 필드만 보존, 나머지는 갱신

즉 "사용자가 수정한 row 이니 보존" 이 아닙니다. "config 에 있어야 유지되고, 유지되는 row 중 사용자가 수정한 **필드만** 보존" 입니다.

### 적용 지점

| sync 메서드 | stale cleanup 호출 |
|---|---|
| `CoreUpdateService::syncCoreMenus` | 말미에 `cleanupStaleMenus(Core, 'core', $currentSlugs)` |
| `CoreUpdateService::syncCoreRolesAndPermissions` | 말미에 `cleanupStalePermissions` 및 role identifier 기준 `cleanupStaleRoles` |
| `CoreUpdateService::syncAllRoleAssignments` | `core/core` 소유 권한 **전체** 를 diff 기준으로 사용 (이관된 구 식별자도 detach) |
| 확장 `ModuleManager::updateModule` / `PluginManager::updatePlugin` | 동일 4단계 (확장별 식별자 범위) |

### 예외 안전장치

- `cleanupStaleRoles`: 삭제 대상 역할을 참조하는 `user_roles` 피벗 row 가 있으면 **삭제 차단 + 경고 로그** (사용자 수동 재배정 유도)
- FK cascade: `NotificationDefinition` 삭제 시 `NotificationTemplate` 자동 cascade

### Helper 사용 (권장)

이 원칙은 각 helper 에 캡슐화되어 있습니다. Service/Seeder 에서 직접 Model 조작 금지. 자세한 사용법은 [data-sync-helpers.md](data-sync-helpers.md) 참조.

```php
// 올바른 패턴 (CoreUpdateService::syncCoreMenus 말미)
$currentSlugs = $menuSyncHelper->collectSlugsRecursive($coreMenus);
$menuSyncHelper->cleanupStaleMenus(ExtensionOwnerType::Core, 'core', $currentSlugs);
```

---

## 관련 문서

- [permissions.md](../extension/permissions.md) - 권한 시스템 상세
- [menus.md](../extension/menus.md) - 메뉴 시스템 상세
- [module-basics.md](../extension/module-basics.md) - 모듈별 config.php 구조
- [service-provider.md](service-provider.md) - 서비스 프로바이더에서 config 로드
- [data-sync-helpers.md](data-sync-helpers.md) - 데이터 동기화 Helper 5종
- [user-overrides.md](user-overrides.md) - 사용자 수정 보존 (HasUserOverrides)
- [upgrade-step-guide.md](../extension/upgrade-step-guide.md) - 업그레이드 스텝 작성
