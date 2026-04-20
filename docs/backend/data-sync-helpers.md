# 데이터 동기화 Helper (Data Sync Helpers)

> **배경**: 코어/확장의 업그레이드·시더·설치 경로에서 **config/seeder ↔ DB** 를 완전 동기화 (upsert + stale cleanup) 하기 위한 5종 helper 의 사용 가이드입니다.

## TL;DR (5초 요약)

```text
1. 모든 데이터 동기화는 Service/Seeder 가 Helper 를 호출해 수행 (직접 Model 조작 금지)
2. 4종 전용 helper (Menu/Role/Notification/FilePermission) + 1종 범용 helper (Generic)
3. 모든 helper 는 upsert + stale cleanup 4단계 패턴 (완전 동기화 원칙)
4. user_overrides 보존은 helper 내부 HasUserOverrides trait 위임으로 자동 처리
5. 새 엔티티 도입 시 대부분 GenericEntitySyncHelper 로 충분 — 전용 helper 는 도메인 로직이 필요할 때만
```

## 1. 완전 동기화 원칙 (4단계)

코어/확장의 sync 메서드는 다음 4단계를 반드시 수행합니다:

| 단계 | 설명 | 책임 |
|---|---|---|
| 1. **Upsert** | config → DB (신규 생성 or user_overrides 보존 업데이트) | `Helper::sync*()` |
| 2. **Orphan Delete** | config 에 없는 DB row 삭제 (user_overrides 무관) | `Helper::cleanupStale*()` |
| 3. **Mapping Diff** | 관계 테이블(role_permissions 등) 재정렬 | Role Helper (해당 시) |
| 4. **Dependent Cleanup** | 삭제된 상위 엔티티의 하위 정리 (FK cascade 또는 명시적) | Helper (해당 시) |

이 원칙은 "config 기반 모든 정의 데이터" 에 적용됩니다. 중요한 의미 구분:

- **row 존재 여부**: config 기준으로 결정 (user_overrides 무관)
- **필드 값 (유지 row)**: user_overrides 에 등록된 필드만 보존

## 2. Helper 5종 개요

| Helper | 역할 | 대상 테이블 | 주요 도메인 로직 |
|---|---|---|---|
| `ExtensionMenuSyncHelper` | 메뉴 동기화 | `menus` | 재귀 슬러그 수집, parent/child 처리 |
| `ExtensionRoleSyncHelper` | 역할·권한 동기화 | `roles`, `permissions`, `role_permissions` | 역할-권한 매핑 diff, `user_roles` 피벗 참조 차단 |
| `NotificationSyncHelper` | 알림 동기화 | `notification_definitions`, `notification_templates` | Definition-Template 계층, FK cascade |
| `FilePermissionHelper` | 파일 소유권/권한 복원 | 파일시스템 | 인스톨러 SSoT 경로 순회 |
| `GenericEntitySyncHelper` | **범용** 단일 테이블 동기화 | 단일 unique 키 모델 | 단순 upsert + scope 필터 cleanup |

---

## 3. ExtensionMenuSyncHelper

**파일**: `app/Extension/Helpers/ExtensionMenuSyncHelper.php`

### 주요 메서드

```php
public function syncMenu(
    string $slug,
    ExtensionOwnerType $extensionType,
    string $extensionIdentifier,
    array $newAttributes,
    ?int $parentId = null,
): Menu;

public function cleanupStaleMenus(
    ExtensionOwnerType $extensionType,
    string $extensionIdentifier,
    array $currentSlugs,
): int;

public function collectSlugsRecursive(array $menus): array;
```

### 사용 패턴 (코어 `CoreUpdateService::syncCoreMenus` 말미)

```php
$currentSlugs = $menuSyncHelper->collectSlugsRecursive($coreMenus);
$deleted = $menuSyncHelper->cleanupStaleMenus(
    ExtensionOwnerType::Core, 'core', $currentSlugs
);
```

확장 모듈 업데이트에서도 동일 패턴으로 `cleanupStaleMenus` 호출.

---

## 4. ExtensionRoleSyncHelper

**파일**: `app/Extension/Helpers/ExtensionRoleSyncHelper.php`

### 주요 메서드

```php
public function syncRole(...): Role;

public function syncPermission(...): Permission;

public function cleanupStaleRoles(
    ExtensionOwnerType $extensionType,
    string $extensionIdentifier,
    array $currentIdentifiers,
): int;

public function cleanupStalePermissions(
    ExtensionOwnerType $extensionType,
    string $extensionIdentifier,
    array $currentIdentifiers,
): int;

public function syncAllRoleAssignments(array $permissionRoleMap, array $allExtensionPermIdentifiers): void;
```

### 중요 정책

- `cleanupStaleRoles`: **삭제 대상 역할을 참조하는 `user_roles` 피벗 레코드가 있으면 삭제 차단 + 경고 로그**. 사용자 수동 재배정 유도 (안전장치)
- `cleanupStalePermissions`: 순수 diff 삭제 (권한은 사용자 수정 불가 설계)
- `syncAllRoleAssignments`: 코어는 `core/core` 소유 권한 전체를 diff 기준으로 사용 (이관된 구 식별자도 detach)

---

## 5. NotificationSyncHelper

**파일**: `app/Extension/Helpers/NotificationSyncHelper.php`

### 주요 메서드

```php
public function syncDefinition(array $data): NotificationDefinition;

public function syncTemplate(int $definitionId, array $data): NotificationTemplate;

public function cleanupStaleDefinitions(
    string $extensionType,
    string $extensionIdentifier,
    array $currentTypes,
): int;

public function cleanupStaleTemplates(int $definitionId, array $currentChannels): int;
```

### 사용 패턴 (Seeder)

```php
$helper = app(NotificationSyncHelper::class);
$definedTypes = [];

foreach ($this->getDefaultDefinitions() as $data) {
    $definition = $helper->syncDefinition($data);
    $definedTypes[] = $definition->type;

    $definedChannels = [];
    foreach ($data['templates'] as $template) {
        $helper->syncTemplate($definition->id, $template);
        $definedChannels[] = $template['channel'];
    }
    $helper->cleanupStaleTemplates($definition->id, $definedChannels);
}

$helper->cleanupStaleDefinitions('core', 'core', $definedTypes);
```

**호출처**: `NotificationDefinitionSeeder` (코어), `BoardNotificationDefinitionSeeder`, `EcommerceNotificationDefinitionSeeder`.

---

## 6. FilePermissionHelper

**파일**: `app/Extension/Helpers/FilePermissionHelper.php`

### 주요 메서드

```php
public function chownRecursive(string $path, string $owner, string $group): void;

public function chmodRecursive(string $path, int $mode): void;
```

### 사용

인스톨러 SSoT 경로 전체에 대한 소유권 복원. 실패 시 경로당 1건 로그 + 종료 시 요약 카운트.

대상 경로 (SSoT — `config/app.php restore_ownership` 과 인스톨러 일치):

```text
storage, bootstrap/cache, vendor, modules, modules/_pending,
plugins, plugins/_pending, templates, templates/_pending, storage/app/core_pending
```

---

## 7. GenericEntitySyncHelper

**파일**: `app/Extension/Helpers/GenericEntitySyncHelper.php`

### 역할 구분 — 언제 사용하나?

| 쓰임 | 전용 Helper | GenericEntitySyncHelper |
|---|---|---|
| 다중 모델 관계 (Definition-Template, Role-Permission) | ✅ | ❌ |
| 피벗 참조 차단 / 매핑 diff | ✅ | ❌ |
| 단일 테이블, 단일 unique 키 | ❌ | ✅ |
| 선택적 scope 필터 (type별, extension별) | ❌ | ✅ |

대부분의 모듈 내부 도메인 엔티티 (ShippingType, BoardType, ClaimReason, 향후 Schedule 등) 는 GenericEntitySyncHelper 로 충분.

### 주요 메서드

```php
public function sync(
    string $modelClass,
    array $finder,
    array $attributes,
): Model;

public function cleanupStale(
    string $modelClass,
    array $scopeFilter,        // ['extension_identifier' => 'x', 'type' => 'refund']
    string $keyField,          // 'code', 'slug', 'name'
    array $currentKeys,
): int;
```

### 사용 예시 1 — 스코프 없음

```php
$helper = app(GenericEntitySyncHelper::class);
$definedSlugs = [];

foreach ($boardTypes as $data) {
    $helper->sync(BoardType::class, ['slug' => $data['slug']], $data);
    $definedSlugs[] = $data['slug'];
}

$helper->cleanupStale(BoardType::class, [], 'slug', $definedSlugs);
```

### 사용 예시 2 — scope 필터 (type 별)

```php
$definedByType = [];
foreach ($reasons as $data) {
    $helper->sync(ClaimReason::class, ['type' => $data['type'], 'code' => $data['code']], $data);
    $definedByType[$data['type']][] = $data['code'];
}

foreach ($definedByType as $type => $codes) {
    $helper->cleanupStale(ClaimReason::class, ['type' => $type], 'code', $codes);
}
```

### 사용 예시 3 — 확장 scope (Schedule 도입 예정)

```php
foreach ($extensionSchedules as $data) {
    $helper->sync(Schedule::class, ['name' => $data['name']], $data);
    $definedNames[] = $data['name'];
}

$helper->cleanupStale(
    Schedule::class,
    ['extension_type' => 'module', 'extension_identifier' => 'sirsoft-ecommerce'],
    'name',
    $definedNames,
);
```

### 모델 전제 조건

대상 모델은 **`HasUserOverrides` trait 이 적용** 되어 있어야 합니다. (`sync()` 내부가 `syncOrCreateFromUpgrade` trait 메서드를 호출)

---

## 8. 새 엔티티 도입 시 체크리스트

```text
□ 1. 모델에 `use HasUserOverrides;` + `$trackableFields` 선언
□ 2. 테이블에 `user_overrides` (text/json, nullable) 컬럼 추가 마이그레이션
□ 3. fillable + casts 에 'user_overrides' 추가
□ 4. Seeder 작성 시 GenericEntitySyncHelper 사용 (단순한 경우)
□ 5. Seeder 는 반드시 sync + cleanupStale 둘 다 호출 (완전 동기화)
□ 6. 복잡한 관계 (1:N, 매핑) 가 있다면 전용 helper 고려
□ 7. 테스트 작성: upsert 동작, user_overrides 보존, stale cleanup
```

---

## 9. 절대 금지 패턴

```text
❌ Seeder 에서 직접 `Model::create()` / `Model::updateOrCreate()` 만 호출 (cleanup 누락)
❌ Seeder 에서 `deleteExistingTypes()` 후 `create()` 재생성 패턴 (사용자 수정 전량 손실)
❌ 데이터 정합성 로직을 Service/Controller 에 분산 (helper 로 집중)
❌ HasUserOverrides 미적용 모델에 업그레이드 시더를 연결 (보존 불가)
```

## 10. 참고

- `HasUserOverrides` trait 가이드: [user-overrides.md](user-overrides.md)
- 완전 동기화 원칙: [core-config.md](core-config.md#완전-동기화-원칙)
- 업그레이드 스텝: [extension/upgrade-step-guide.md](../extension/upgrade-step-guide.md)
