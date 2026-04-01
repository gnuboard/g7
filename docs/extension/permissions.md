# 권한 시스템

> G7의 권한(Permission)과 역할(Role) 시스템

---

## TL;DR (5초 요약)

```text
1. 구조: User → Role → Permission (기능 레벨)
2. 리소스: User → Role → role_menus 피벗 → Menu
3. 네이밍: [module].[entity].[action] (예: users.read)
4. type 필드: admin(관리자용) 또는 user(사용자용), 기본값: admin
5. scope_type: 역할별 접근 스코프 (null=전체, self=본인, role=소유역할)
```

---

## 목차

1. [권한 아키텍처](#권한-아키텍처)
2. [권한 타입 (Permission Type)](#권한-타입-permission-type)
3. [모듈 Role/Permission 자동 관리](#모듈-rolepermission-자동-관리)
4. [Role 정의 규칙](#role-정의-규칙)
5. [Permission에 Role 할당](#permission에-role-할당)
6. [Role 삭제 조건](#role-삭제-조건)
7. [콘솔 커맨드 출력](#콘솔-커맨드-출력)
8. [권한 네이밍](#권한-네이밍)
9. [리소스 레벨 권한](#리소스-레벨-권한)
10. [레이아웃 컴포넌트 권한 필터링](#레이아웃-컴포넌트-권한-필터링)

---

## 권한 아키텍처

G7의 권한 시스템은 두 가지 레벨로 구성됩니다.

### 기능 레벨 권한 (Feature-level)

```text
User → Role → Permission
```

- **User**: 시스템 사용자
- **Role**: 역할 그룹 (여러 Permission을 묶음)
- **Permission**: 개별 권한 (특정 작업 수행 허용)

### 리소스 레벨 권한 (Resource-level)

```text
User → Role → role_menus 피벗 테이블 → Menu
```

- **role_menus**: 메뉴 접근 권한

> **참고**: 첨부파일(Attachment)은 기능 레벨 권한(`core.attachments.*` + `scope_type`)으로 제어합니다. `role_attachments` 독립 시스템은 폐기되었습니다.

---

## 권한 타입 (Permission Type)

권한은 `type` 필드로 사용 컨텍스트를 구분합니다.

### PermissionType Enum

```php
// app/Enums/PermissionType.php

enum PermissionType: string
{
    case Admin = 'admin';  // 관리자 화면용 권한
    case User = 'user';    // 사용자(프론트엔드) 화면용 권한
}
```

### 타입별 용도

| 타입 | 용도 | 예시 |
|------|------|------|
| `admin` | 관리자 화면에서 사용되는 권한 | 사용자 관리, 설정, 모듈 관리 |
| `user` | 사용자(프론트엔드) 화면에서 사용되는 권한 | 내 주문 조회, 프로필 수정 |

### 관리자 판단 기준

```php
// User::isAdmin() 메서드
// 사용자가 보유한 권한 중 type='admin'인 것이 하나라도 있으면 관리자

public function isAdmin(): bool
{
    return $this->roles()
        ->whereHas('permissions', function ($query) {
            $query->where('type', PermissionType::Admin);
        })
        ->exists();
}
```

### 모듈/플러그인에서 권한 타입 지정

```php
// getPermissions() 반환 구조
'permissions' => [
    [
        'action' => 'read',
        'name' => ['ko' => '조회', 'en' => 'Read'],
        'description' => ['ko' => '...', 'en' => '...'],
        'type' => 'admin',  // admin 또는 user (기본값: admin)
        'roles' => ['admin', 'manager'],
    ],
    [
        'action' => 'view_own',
        'name' => ['ko' => '내 정보 조회', 'en' => 'View Own'],
        'type' => 'user',  // 사용자 화면용 권한
        'roles' => ['user'],
    ],
],
```

### Permission 모델 헬퍼

```php
// 타입 확인 메서드
$permission->isAdminPermission();  // type === 'admin'
$permission->isUserPermission();   // type === 'user'

// 스코프
Permission::adminPermissions()->get();  // admin 타입만
Permission::userPermissions()->get();   // user 타입만
```

---

## 모듈 Role/Permission 자동 관리

```text
중요: 모듈 설치 시 Role과 Permission이 자동으로 생성/연결됨
✅ 필수: getRoles()와 getPermissions()의 roles 필드 사용
```

> **플러그인 권한**: 플러그인도 모듈과 동일한 3레벨 계층 구조(`categories`)를 사용합니다. 상세는 [plugin-development.md](plugin-development.md)의 "플러그인 권한 시스템" 섹션을 참조하세요.

### 동작 방식

#### 모듈 설치 시 (module:install)

1. 마이그레이션 실행
2. `getRoles()`에서 정의한 Role 생성
3. `getPermissions()`에서 정의한 Permission 생성
4. Permission의 `roles` 필드에 지정된 Role에 권한 할당
5. 메뉴 생성
6. 시더 실행

#### 모듈 제거 시 (module:uninstall)

1. Role에서 Permission 분리 (detach)
2. Permission 삭제
3. Role 삭제 (조건: `extension_type`이 NULL(사용자 생성) AND 남은 권한 0개)
4. 메뉴 삭제
5. 마이그레이션 롤백

---

## Role 정의 규칙

### identifier 네이밍

```text
[vendor-module].[rolename]
```

### 예시

```text
sirsoft-ecommerce.manager
sirsoft-ecommerce.viewer
sirsoft-sample.manager
```

### getRoles() 반환 구조

```php
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
                'ko' => '이커머스 모듈의 관리 권한을 가진 역할',
                'en' => 'Role with management permissions for ecommerce module',
            ],
        ],
    ];
}
```

**필수 필드**:

| 필드          | 타입   | 설명                                   |
| ------------- | ------ | -------------------------------------- |
| `identifier`  | string | 고유 식별자 (vendor-module.rolename)   |
| `name`        | array  | 다국어 역할명                          |
| `description` | array  | 다국어 설명                            |

---

## Permission에 Role 할당

### getPermissions() roles 필드

```php
public function getPermissions(): array
{
    return [
        [
            'identifier' => 'sirsoft-ecommerce.products.view',
            'name' => ['ko' => '상품 조회', 'en' => 'View Products'],
            'description' => ['ko' => '...', 'en' => '...'],
            'roles' => ['admin', 'sirsoft-ecommerce.manager'],  // Role 지정
        ],
    ];
}
```

### 규칙

- `roles` 필드에 지정된 Role에 자동으로 권한 할당
- 기존 Role의 권한은 `syncWithoutDetaching`으로 보존
- 여러 Role에 동일 권한 할당 가능

### syncWithoutDetaching 동작

```php
// 기존 권한은 유지하면서 새 권한만 추가
$role->permissions()->syncWithoutDetaching($permissionIds);
```

- 모듈 A가 `admin` Role에 권한 할당
- 모듈 B도 `admin` Role에 권한 할당
- 모듈 A 제거 시 모듈 B의 권한은 유지됨

---

## Role 삭제 조건

### 안전한 삭제를 위한 조건

1. `extension_type`이 NULL (사용자 생성 Role만 삭제 가능)
2. `extension_type = 'core'` → 삭제 불가 (코어 역할 보호)
3. `extension_type = 'module'` 또는 `'plugin'` → 삭제 불가 (확장 소유 역할 보호, `ExtensionOwnedRoleDeleteException` 발생)
4. 남은 권한 개수 = 0 (다른 모듈의 권한이 없어야 함)

### 조건을 만족하지 않는 경우

- `extension_type`이 설정된 Role은 `ExtensionOwnedRoleDeleteException` 또는 403 응답
- 남은 권한이 있는 경우 삭제되지 않고 로그에 경고 기록

### Extension Ownership 예시

```php
// 코어 Role (extension_type = 'core', extension_identifier = 'core')
'admin'      // 최고 관리자 — 삭제 불가

// 모듈 Role (extension_type = 'module', extension_identifier = 'sirsoft-ecommerce')
'sirsoft-ecommerce.manager'  // 모듈 소유 — 삭제 불가

// 사용자 생성 Role (extension_type = NULL)
'custom-role'  // 사용자 생성 — 삭제 가능
```

---

## 콘솔 커맨드 출력

### 설치 시

```text
✅ 모듈 "sirsoft-sample" 설치 완료
   - 벤더: sirsoft
   - 버전: 1.0.0
   - 1개 역할 생성됨
   - 4개 권한 생성됨
   - 3개 메뉴 생성됨
```

### 제거 시

```text
모듈 "sirsoft-sample"을(를) 정말 삭제하시겠습니까?
- 1개 역할이 삭제됩니다.
- 4개 권한이 삭제됩니다.
- 3개 메뉴가 삭제됩니다.
```

---

## 권한 네이밍

### 코어 권한

```text
core.[entity].[action]
```

**예시**:

```text
core.templates.layouts.view
core.templates.layouts.create
core.templates.layouts.edit
core.templates.layouts.delete
core.users.view
core.users.manage
```

### 모듈 권한

```text
[vendor-module].[entity].[action]
```

**예시**:

```text
sirsoft-ecommerce.products.view
sirsoft-ecommerce.products.create
sirsoft-ecommerce.products.edit
sirsoft-ecommerce.products.delete
sirsoft-ecommerce.orders.view
sirsoft-ecommerce.orders.manage
```

### 권한 네이밍 규칙 요약

| 구분   | 패턴                            | 예시                                 |
| ------ | ------------------------------- | ------------------------------------ |
| 코어   | `core.[entity].[action]`        | `core.users.view`                    |
| 모듈   | `[vendor-module].[entity].[action]` | `sirsoft-ecommerce.products.create` |

### 일반적인 action 목록

| Action   | 설명                                    |
| -------- | --------------------------------------- |
| `view`   | 조회 권한                               |
| `create` | 생성 권한                               |
| `edit`   | 수정 권한                               |
| `delete` | 삭제 권한                               |
| `manage` | 관리 권한 (view + create + edit + delete) |

---

## 리소스 레벨 권한

G7은 기능 레벨 권한 외에 **리소스 레벨 권한**을 지원합니다. 특정 리소스(메뉴 등)에 대한 세밀한 접근 제어가 가능합니다.

### 적용된 리소스

| 리소스       | 권한 방식 | 설명 |
| ------------ | --------- | ---- |
| Menu         | `role_menus` 피벗 테이블 | 역할별 메뉴 접근 제어 |
| Attachment   | `core.attachments.*` + `scope_type` | 기능 레벨 권한으로 제어 |

> **참고**: 첨부파일은 `role_attachments` 독립 시스템이 아닌, 기존 `permissions` + `scope_type` 시스템으로 통합되었습니다.
> - `core.attachments.create`: 업로드 권한
> - `core.attachments.update`: 수정/순서변경 권한 (`resource_route_key: 'attachment'`, `owner_key: 'created_by'`)
> - `core.attachments.delete`: 삭제 권한 (`resource_route_key: 'attachment'`, `owner_key: 'created_by'`)
> - 공개 다운로드는 `HookManager::checkHookPermission('core.attachment.download')` 으로 제어

---

## API 응답 권한 메타 (is_owner + abilities)

모든 API 리소스 응답에 `is_owner`와 `abilities` (can_*) 필드가 표준화되어 포함됩니다.

### 응답 형식

```json
{
    "id": 1,
    "name": "상품명",
    "is_owner": true,
    "abilities": {
        "can_update": true,
        "can_delete": false
    }
}
```

- `is_owner`: 현재 요청 사용자가 리소스 소유자인지 여부
- `abilities`: `can_*` 키로 통합된 권한 플래그

### 권한 확인 순서 (checkAbility)

```text
1. Admin 역할 → 무조건 true
2. 인증된 사용자 → $user->hasPermission($identifier)
3. 게스트 → PermissionHelper::check($identifier)
```

### 프론트엔드에서 사용

레이아웃 JSON에서 `abilities.can_*` 키로 접근합니다:

```json
{
    "if": "{{item.abilities.can_update}}",
    "type": "Button",
    "props": { "text": "수정" }
}
```

> **상세 구현 규칙**: [api-resources.md](../backend/api-resources.md#권한-메타-표준화-is_owner--abilities) 참조

---

## PermissionHelper — 서비스/컨트롤러에서 권한 체크

`PermissionHelper` 헬퍼 클래스를 사용하여 서비스/컨트롤러 내부에서 권한을 체크합니다.
scope_type 기반 스코프 체크 기능이 내장되어 있습니다.

```php
use App\Helpers\PermissionHelper;

// 기본 권한 체크
PermissionHelper::check('core.users.update');

// 구조화 권한 (OR/AND)
PermissionHelper::checkWithLogic(['or' => ['perm.a', 'perm.b']]);

// scope_type 기반 상세 접근 체크
PermissionHelper::checkScopeAccess($model, 'core.users.update', $user);

// scope_type 기반 목록 필터링 (Repository에서 사용)
PermissionHelper::applyPermissionScope($query, 'core.users.read');

// effective scope 조회
PermissionHelper::getEffectiveScope('core.users.read', $user); // null|'self'|'role'

// 소유자 정보 조회 (캐싱)
PermissionHelper::getOwnerKey('core.users.read');       // 'id'
PermissionHelper::getResourceRouteKey('core.users.read'); // 'user'
```

**메서드 시그니처**:

```php
public static function check(string $ability, ?User $user = null): bool
public static function checkWithLogic(array|object $permissions, ?User $user = null): bool
public static function checkScopeAccess(Model $model, string $permission, ?User $user = null): bool
public static function applyPermissionScope(Builder $query, string $permission, ?User $user = null): void
public static function getEffectiveScope(string $permission, ?User $user = null): ?string
public static function getOwnerKey(string $permission): ?string
public static function getResourceRouteKey(string $permission): ?string
```

### 권한 체크 방식 선택 가이드

| 상황 | 사용 방법 |
|------|----------|
| 라우트 접근 제어 | `permission:` 미들웨어 (scope_type 자동 체크) |
| 서비스 내부 조건부 권한 체크 | `PermissionHelper::check()` 직접 호출 |
| 상세 엔드포인트 스코프 체크 | `PermissionHelper::checkScopeAccess()` |
| 목록 엔드포인트 필터링 | `PermissionHelper::applyPermissionScope()` (Repository에서 호출) |
| API 응답 abilities 플래그 | `HasAbilityCheck` 트레이트 (`abilityMap()`) |
| 리소스 레벨 접근 제어 (메뉴 등) | `role_*` 피벗 테이블 기반 |
| 레이아웃 컴포넌트 필터링 | `permissions` 속성 (서버 사이드 제거) |

### 주의 사항

```text
❌ AuthServiceProvider::checkPermission() 직접 호출 → PermissionHelper::check() 사용
❌ 서비스 내부에서 미들웨어에 의존한 권한 체크 생략 → PermissionHelper로 명시적 체크
❌ except/only/menu 미들웨어 옵션 사용 (제거됨) → scope_type 데이터 기반 시스템 사용
```

---

## scope_type 스코프 시스템

역할별 접근 범위를 `role_permissions` 피벗의 `scope_type` 컬럼으로 제어합니다.
모든 라우트 미들웨어 옵션 (`except:`, `only:`, `menu:`)은 **제거**되었습니다.

### DB 구조

| 테이블 | 컬럼 | 타입 | 용도 |
|--------|------|------|------|
| `permissions` | `resource_route_key` | VARCHAR(50) NULL | 라우트 파라미터명 (예: 'user', 'menu') |
| `permissions` | `owner_key` | VARCHAR(50) NULL | 모델의 소유자 식별 컬럼 (예: 'id', 'created_by') |
| `role_permissions` | `scope_type` | ENUM('self', 'role') NULL | 역할별 접근 스코프 |

### ScopeType Enum

```php
// app/Enums/ScopeType.php
enum ScopeType: string
{
    case Self = 'self';   // 본인 리소스만
    case Role = 'role';   // 소유 역할 공유 사용자의 리소스
}
// null = 전체 접근 (제한 없음)
```

### scope_type 값 정의

| 값 | 의미 | 목록 필터링 | 상세 접근 체크 |
|---|---|---|---|
| `null` | 전체 접근 | 필터 미적용 | 항상 통과 |
| `'self'` | 본인 리소스만 | `WHERE {owner_key} = {user_id}` | `$model->{owner_key} === $user->id` |
| `'role'` | 내 역할 범위 | `WHERE {owner_key} IN (내 역할 공유 사용자)` | 소유자가 내 역할 공유하는지 체크 |

### union 정책 (복수 역할 보유 시)

- **우선순위**: `null`(전체) > `'role'`(소유역할) > `'self'`(본인)
- 여러 역할 중 하나라도 scope_type=null → 전체 접근
- 전부 non-null이면 가장 넓은 범위 적용 (role > self)
- 예: 역할A(scope=self) + 역할B(scope=role) → role 적용

### 미들웨어 처리 흐름

```text
permission:admin,core.users.read  ← 옵션 없음

1. 권한 타입 검증
2. 동적 파라미터 치환
3. 권한 체크 (인증/게스트)
4. scope_type 스코프 체크:
   a. Permission 조회 (캐싱) → resource_route_key, owner_key
   b. resource_route_key null → 통과 (시스템 리소스)
   c. $request->route(resource_route_key) → 모델 resolve
   d. 모델 없음 → 통과 (목록 엔드포인트)
   e. 사용자의 effective scope 확인 (union 정책)
   f. scope=null → 통과
   g. scope='self' → $model->{owner_key} === $user->id
   h. scope='role' → 소유자가 내 역할 공유하는지 체크
```

### 목록 엔드포인트 필터링 (Repository 패턴)

```php
// 모든 Repository에서 한 줄로 적용
$query = User::query();
PermissionHelper::applyPermissionScope($query, 'core.users.read');
// ... 기존 필터/정렬/페이지네이션
```

### 소유자 식별 전체 매핑

| Permission prefix | resource_route_key | owner_key | 비고 |
|---|---|---|---|
| core.users.* | user | id | self: $user->id === $model->id |
| core.menus.* | menu | created_by | |
| core.attachments.update/delete | attachment | created_by | create는 scope 불필요 |
| core.schedules.* | schedule | created_by | |
| core.activity_logs.* | null | null | 소유자 없음 |
| sirsoft-board.boards.* | board | created_by | |
| sirsoft-board.{slug}.*.posts.* | post | user_id | |
| sirsoft-ecommerce.products.* | product | created_by | |
| sirsoft-ecommerce.orders.* | order | user_id | |
| sirsoft-page.pages.* | page | created_by | |

> resource_route_key/owner_key가 null → scope_type UI 미표시, 설정해도 무시

### getPermissions() 포맷 (모듈)

```php
'categories' => [
    [
        'identifier' => 'pages',
        'resource_route_key' => 'page',   // 리소스 라우트 키
        'owner_key' => 'created_by',       // 소유자 컬럼
        'permissions' => [
            [
                'action' => 'read',
                'roles' => ['admin', 'manager'],  // 문자열 → scope_type=null
            ],
            [
                'action' => 'delete',
                'roles' => [
                    ['role' => 'admin', 'scope_type' => null],
                    ['role' => 'manager', 'scope_type' => 'self'],
                ],
            ],
        ],
    ],
],
```

### 역할 관리 UI

- PermissionTree 컴포넌트에 scope_type 드롭다운 추가
- resource_route_key가 있는 권한 + 체크된 상태에서만 드롭다운 표시
- 선택지: 전체(null), 소유역할('role'), 본인만('self')
- 폼 데이터: `permissions: [{id: 1, scope_type: null}, {id: 2, scope_type: 'self'}]`

---

## 레이아웃 컴포넌트 권한 필터링

레이아웃 JSON의 컴포넌트에 `permissions` 속성을 정의하면, **레이아웃 서빙 API에서 해당 컴포넌트를 제거하여 서빙**합니다. 프론트엔드 `if` 조건부 렌더링과 달리 JSON 구조 자체가 클라이언트에 노출되지 않아 보안이 강화됩니다.

### 동작 원리

```text
파이프라인 위치:
[캐시 조회] → [extends/partial 병합] → [applyExtensions] → [★ filterComponentsByPermissions] → [훅] → [응답]
```

- **Post-cache filtering**: 캐시 이후 사용자별 동적 필터링 (캐시 구조 영향 없음, ~1-2ms 추가)
- **AND 조건**: `permissions` 배열의 모든 권한을 충족해야 컴포넌트 포함
- **Admin 바이패스**: `admin` 역할은 `Gate::before()`에 의해 모든 권한 통과

### 필터링 대상 영역

| 영역 | 설명 |
|------|------|
| `components` | 메인 컴포넌트 트리 (재귀 children 포함) |
| `modals[]` | 모달 자체에 permissions → 모달 항목 전체 제거 |
| `modals[].components` | 모달 내부 컴포넌트 트리 (재귀) |
| `defines` | 재사용 컴포넌트 정의 (재귀) |
| partial 내부 | extends/partial 병합 후 필터링이므로 자동 처리 |

### 필터링 규칙

1. 현재 노드의 `permissions` 배열 확인 (없거나 빈 배열이면 통과)
2. `AuthServiceProvider::checkPermissions($permissions, $user)` 호출 (AND 조건)
3. 권한 없으면 해당 노드 및 하위 children **전체 제거** (하위 평가 생략)
4. 권한 있으면 통과 → children을 재귀적으로 독립 평가
5. 필터링 완료 후 모든 노드에서 `permissions` 속성 제거 (클라이언트 노출 방지)

### 상위-하위 중복 선언

```json
{
  "type": "Div",
  "permissions": ["core.users.read"],
  "children": [
    { "type": "Button", "permissions": ["core.users.delete"], "text": "삭제" }
  ]
}
```

| 시나리오 | 상위 결과 | 하위 결과 | 최종 |
|---------|---------|---------|------|
| `read` 없음 | 상위 제거 → 전체 제거 | 평가 안 됨 | 둘 다 없음 |
| `read` 있음 + `delete` 없음 | 통과 | 하위만 제거 | Div만 남음 |
| `read` 있음 + `delete` 있음 | 통과 | 통과 | 둘 다 남음 |

→ 각 노드는 자기 permissions만 독립 평가. 별도 병합 정책 없음.

### 확장(Extension)과의 관계

- Extension Point로 주입된 컴포넌트에 `permissions` → 정상 필터링
- Overlay로 교체된 컴포넌트에 `permissions` → 교체된 컴포넌트의 permissions 적용
- 파이프라인 순서: `applyExtensions` → `filterComponentsByPermissions` (확장 적용 후 필터링)

### 레이아웃 최상위 permissions와의 비교

| 항목 | 최상위 `permissions` | 컴포넌트 `permissions` |
|------|---------------------|----------------------|
| 적용 범위 | 레이아웃 전체 | 개별 컴포넌트 단위 |
| 미충족 시 | 403 응답 | 해당 컴포넌트만 제거 |
| 조건 방식 | AND | AND |
| 처리 시점 | 캐시 조회 전 | 캐시 조회 후 (post-cache) |

### 관련 코드

| 파일 | 역할 |
|------|------|
| `app/Services/LayoutService.php` | `filterComponentsByPermissions()` 메서드 |
| `app/Http/Controllers/Api/Public/PublicLayoutController.php` | 서빙 시 필터링 호출 |
| `app/Rules/ValidLayoutStructure.php` | 컴포넌트 `permissions` 검증 |

> **레이아웃 JSON 문법 상세**: [layout-json.md](../frontend/layout-json.md#컴포넌트별-권한-component-permissions) 참조

---

## Model 권한/역할 체크 메서드

User 모델은 권한/역할 체크를 위한 헬퍼 메서드를 제공합니다.

### 메서드 시그니처

```php
// 단일 권한 체크
$user->hasPermission('core.users.read');
$user->hasPermission('core.users.read', PermissionType::Admin);

// 복수 권한 체크
$user->hasPermissions(['perm.a', 'perm.b'], requireAll: true);  // AND
$user->hasPermissions(['perm.a', 'perm.b'], requireAll: false); // OR

// 역할 체크
$user->hasRole('admin');
$user->hasRoles(['admin', 'manager'], requireAll: false); // OR

// 관리자 판단 (admin 타입 권한 보유 여부)
$user->isAdmin();
```

### 인스턴스 레벨 캐싱

`getEffectiveScopeForPermission()` 메서드는 동일 요청 내 반복 호출 시 DB 쿼리를 방지하기 위해 인스턴스 레벨 캐싱을 사용합니다:

```php
// User 모델 내부
protected array $effectiveScopeCache = [];

public function getEffectiveScopeForPermission(string $identifier): ?string
{
    if (array_key_exists($identifier, $this->effectiveScopeCache)) {
        return $this->effectiveScopeCache[$identifier];
    }
    // ... DB 쿼리 및 union 정책 적용 ...
    return $this->effectiveScopeCache[$identifier] = $result;
}
```

---

## Permission 동기화 및 추적

### Role-Permission 동기화 패턴

역할에 권한을 동기화할 때 이전/이후 상태를 추적합니다:

```php
public function syncPermissions(Role $role, array $permissions): void
{
    // 동기화 전 권한 식별자 캡처 (Listener diff 계산용)
    $previousPermIdentifiers = $role->permissions()->pluck('identifier')->toArray();

    // 피벗 데이터에 granted_at, granted_by 자동 설정
    $pivotData = [];
    foreach ($permissions as $permission) {
        $pivotData[$permission['id']] = [
            'scope_type' => $permission['scope_type'] ?? null,
            'granted_at' => now(),
            'granted_by' => Auth::id(),
        ];
    }

    $role->permissions()->sync($pivotData);

    // 동기화 후 권한 식별자 (Listener에서 diff 계산 가능)
    $currentPermIdentifiers = $role->permissions()->pluck('identifier')->toArray();

    HookManager::doAction('core.role.after_sync_permissions',
        $role, $previousPermIdentifiers, $currentPermIdentifiers);
}
```

### 자기잠금 방지

마지막 admin 역할 보유자가 자신의 admin 역할을 제거하는 것을 방지합니다:

```text
- 관리자가 자기 자신의 역할을 변경하려 하면 → 역할 변경 무시
- 마지막 admin이 admin 역할을 삭제하려 하면 → 거부
```

---

## 비회원(Guest) 권한

### Guest 권한 체크 흐름

```text
비회원 요청 → PermissionMiddleware
  → Guest 역할 조회 (Role::where('identifier', 'guest'))
  → 정적 캐시: self::$guestRoleCache (요청 내 재사용)
  → 권한 매핑 없음 → 기본 허용
  → 권한 매핑 있음 → guest 역할이 해당 권한 보유하는지 확인
```

### 핵심 규칙

| 상황 | 동작 |
| ------ | ------ |
| 권한 미매핑 (permission_hooks 없음) | 모든 사용자(guest 포함) 허용 |
| 권한 매핑 + guest 역할 보유 | 허용 |
| 권한 매핑 + guest 역할 미보유 | 거부 (401/403) |

---

## Role identifier 자동 생성

관리자가 역할 생성 시 `identifier`를 직접 입력하지 않습니다. `RoleService`가 이름에서 자동 생성합니다:

```text
generateIdentifier(name)
    ↓ 배열인 경우 → 영어 이름 우선 (en → ko → 첫 번째 값)
    ↓ Str::slug($baseName, '_') → 슬러그 변환 (예: "Content Manager" → "content_manager")
    ↓ 중복 체크 → 중복 시 _1, _2, ... suffix 자동 추가
    ↓ 반환: 고유한 identifier
```

**예시**:

| 입력 이름 | 생성 identifier |
| ---------- | ---------- |
| `Content Manager` | `content_manager` |
| `Content Manager` (중복) | `content_manager_1` |
| `['ko' => '편집자', 'en' => 'Editor']` | `editor` |

---

## 관련 문서

- [menus.md](./menus.md) - 메뉴 권한 시스템
- [hooks.md](./hooks.md) - 훅 권한 시스템
- [module-basics.md](./module-basics.md) - 모듈 기본 구조
- [index.md](./index.md) - 확장 시스템 인덱스
