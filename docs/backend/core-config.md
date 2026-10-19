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
- [schedules — 코어 스케줄 카탈로그 (7.2.0)](#schedules--코어-스케줄-카탈로그-720)
- [사용처](#사용처)
- [관련 문서](#관련-문서)

---

## 파일 구조 개요

`config/core.php`는 최상위 키로 구성되며, 이 문서는 그중 권한·역할·메뉴를 다룹니다.
나머지 키는 각자의 규정 문서가 소유합니다.

```php
return [
    // 이 문서가 다루는 범위
    'permissions' => [...],            // 코어 권한 정의
    'roles' => [...],                  // 코어 역할 정의
    'menus' => [...],                  // 코어 메뉴 정의

    // 다른 문서가 소유하는 키
    'settings_limits' => [...],        // 관리자 환경설정 입력 한계값
    'pagination' => [...],             // 목록 한계값 기본값 → pagination.md
    'search' => [...],                 // DBMS 별 부분일치 연산자 → search-system.md
    'schedules' => [...],              // 코어 스케줄 카탈로그 (7.2.0) → 아래 「schedules」 절, scheduling.md
    'notification_definitions' => [...], // 알림 정의 (본인인증 알림 identity.* 포함) → notification-system.md, identity-messages.md
    'identity_policies' => [...],      // 본인인증 정책 → identity-policies.md
    'identity_purposes' => [...],      // 본인인증 목적 → identity-policies.md
];
```

키를 새로 추가할 때는 그 키를 설명하는 규정 문서를 함께 지정하고 이 목록에 한 줄을 더합니다.
목록이 실제 구조와 어긋나면 "여기 없으니 없는 값" 으로 읽혀 중복 키가 생깁니다.

> **변경 이력 (7.0.0-beta.2)**: `mail_templates` 키는 알림 시스템 통합(#146)으로 제거되었습니다. 코어 메일 템플릿은 `notification_definitions` + `notification_templates` 로 이전되었으며, `database/seeders/NotificationDefinitionSeeder` 가 `notification_definitions` 키를 읽어 시드합니다.
>
> **변경 이력 (7.1.0)**: `identity_messages` 키는 본인인증 메시지의 알림 시스템 통합(#699)으로 제거되었습니다. 본인인증 메일 문구는 `notification_definitions` 의 `identity.default` / `identity.purpose.{purpose}` 항목(`category => 'identity'`)으로 선언합니다.

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
        'order' => 100,            // 정수(소수는 반올림돼 동순위). 코어 카테고리는 100 단위로 두어 사이에 끼울 자리를 남김
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

## schedules — 코어 스케줄 카탈로그 (7.2.0)

코어의 모든 예약 작업 목록입니다. `CoreScheduleSeeder` 가 `schedules` 표(`extension_type=core`, `extension_identifier=core`)로 동기화하고, 스케줄러는 그 행을 읽어 실행합니다. `routes/console.php` 에는 개별 예약이 없으므로 **코어 예약을 더하거나 바꾸는 곳은 여기뿐**입니다. 실행 구조는 [scheduling.md](scheduling.md) 를 봅니다.

```php
'schedules' => [
    // 키 = 행의 name (기계 식별자)
    'geoip-update' => [
        'group' => 'system',
        'label' => ['ko' => 'GeoIP 데이터베이스 갱신', 'en' => 'Update GeoIP database'],
        'description' => ['ko' => '…', 'en' => '…'],
        'command' => 'geoip:update',
        'expression' => '0 3 * * 3',
        'without_overlapping' => 60,
        'enabled_condition' => [
            ['source' => 'core_setting', 'key' => 'geoip.feature_enabled', 'operator' => 'truthy', 'default' => false],
            ['source' => 'core_setting', 'key' => 'geoip.auto_update_enabled', 'operator' => 'truthy', 'default' => true],
            ['source' => 'core_setting', 'key' => 'geoip.license_key', 'operator' => 'not_empty', 'default' => ''],
        ],
    ],
    'sitemap-generate' => [
        'group' => 'seo',
        'label' => ['ko' => '사이트맵 생성', 'en' => 'Generate sitemap'],
        'type' => 'job',
        'command' => \App\Jobs\GenerateSitemapJob::class,
        'expression' => '0 2 * * *',
        'without_overlapping' => 30,
        'dispatch_mode' => 'queue',
        'enabled_condition' => [
            ['source' => 'core_setting', 'key' => 'seo.sitemap_enabled', 'operator' => 'truthy', 'default' => true],
        ],
    ],
],
```

### 항목 필드

| 필드 | 기본값 | 설명 |
| --- | --- | --- |
| (배열 키) | — | 행의 `name`. 바꾸면 다른 행으로 보아 옛 행(운영자 수정 포함)이 지워진다 |
| `group` | — | 카탈로그 전용 분류(DB 에 저장하지 않음). `gc` = 만료 데이터 정리 |
| `label` · `description` | — | `{ko, en}` 다국어. ja 등은 언어팩 `lang-packs/_bundled/g7-core-ja/seed/schedules.json` 이 `core.schedules.config` 필터로 더한다 |
| `type` | `artisan` | `artisan` · `job` |
| `command` | — | artisan 명령(옵션 포함) 또는 Job 클래스 FQCN. artisan 은 `config/schedule_security.php` 허용목록에 있어야 하고, job 은 `job.allowed_classes` 에 있어야 실행된다 |
| `expression` | — | cron 식 (사이트 설정 시간대 기준) |
| `frequency` | `custom` | `everyThirtySeconds` 면 30초 주기 (`expression` 은 `* * * * *`) |
| `timezone` | `null` | 시간대 고정이 필요할 때만 |
| `without_overlapping` | `0` | 중복 실행 방지 잠금 유지 분 (0=끔, 최대 10080) |
| `on_one_server` | `true` | 다중 서버에서 한 서버만 |
| `dispatch_mode` | `sync` | `sync`(바로 실행) · `queue`(큐로 보내기) |
| `history_mode` | `all` | `all` · `failures_only` |
| `notify_on_failure` | `true` | 예약 실행 실패 시 관리자 알림 |
| `timeout` | `null` | 초 |
| `enabled_condition` | `null` | 실행 조건 `[{source, key, operator, value?, default}]` (AND) — 키는 설정 defaults 나 config 에 실제로 있어야 한다 |

검증에 실패한 항목은 그 항목만 건너뛰고 시더가 경고를 출력합니다. 전체 목록과 현재 값은 [scheduling.md 「코어 스케줄 카탈로그 목록」](scheduling.md#13-코어-스케줄-카탈로그-목록) 에 있습니다.

### 동기화 규칙

- **시점**: 설치(`DatabaseSeeder` → `CoreScheduleSeeder`), 코어 업데이트(`CoreUpdateService::reloadCoreConfigAndResync()`).
- **필드 값**: 운영자가 스케줄 관리에서 바꾼 필드(`user_overrides`)는 보존하고 나머지는 카탈로그 값으로 갱신합니다. 기본 시각을 바꾸면 운영자가 손대지 않은 사이트에만 반영됩니다.
- **행 존재**: 카탈로그에서 뺀 코어 행은 다음 동기화 때 실행 이력과 함께 지워집니다. 운영자가 직접 등록한 행(`extension_type` NULL)과 확장 행은 범위 밖입니다.
- **잠금**: 코어 행의 `command`·`type`·`name`·`enabled_condition`·`timezone` 은 관리자 화면에서 바꿀 수 없습니다.

### 함께 추가된 코어 선언 (7.2.0)

스케줄 관리 화면과 큐 관리를 위해 같은 파일의 다른 블록에도 항목이 더해졌습니다.

| 블록 | 항목 | 내용 |
| --- | --- | --- |
| `permissions` | 카테고리 `core.queue_jobs` | `core.queue_jobs.read`(큐 대기·실패 작업과 처리 기록 조회) · `.retry`(실패 작업 재시도) · `.delete`(실패 작업 삭제) — 모두 `type=admin` |
| `menus` | `admin-schedules` | 관리자 메뉴 「스케줄 관리」(`/admin/schedules`)를 활성(`is_active=true`)으로 노출 |
| `settings_limits` | `drivers_queue_record_retention_days_min` · `_max` | 환경설정 > 드라이버 「처리 기록 보관 기간」 입력 한계(1·90일). 설정 API 의 `limits` 메타로 화면에 전달 |
| `notification_definitions` | `schedule_failed` | 예약 작업 실패 알림(관리자, 메일+사이트, 같은 스케줄 하루 1회) — [notification-system.md](notification-system.md) |

---

## 사용처

| 사용처 | 시점 | 설명 |
| ---------- | ---------- | ---------- |
| `RolePermissionSeeder` | 초기 설치 | permissions, roles 데이터 시딩 |
| `CoreAdminMenuSeeder` | 초기 설치 | menus 데이터 시딩 |
| `CoreUpdateService::syncCoreRolesAndPermissions()` | 코어 업데이트 | 권한/역할 동기화 |
| `CoreUpdateService::syncCoreMenus()` | 코어 업데이트 | 메뉴 동기화 |
| `CoreScheduleSeeder` | 초기 설치 · 코어 업데이트(`reloadCoreConfigAndResync()`) | schedules 카탈로그 동기화 (7.2.0) |

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
| `CoreScheduleSeeder` → `ExtensionScheduleSyncHelper::syncSchedules(Core, 'core', …)` | 같은 메서드가 카탈로그에 없는 `core/core` 스케줄 행을 이력과 함께 삭제 |
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
- [scheduling.md](scheduling.md) - 스케줄 등록부·실행 구조 (schedules 카탈로그)
- [upgrade-step-guide.md](../extension/upgrade-step-guide.md) - 업그레이드 스텝 작성
