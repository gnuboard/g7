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
- [mail_templates — 메일 템플릿 정의](#mail_templates--메일-템플릿-정의)
- [사용처](#사용처)
- [관련 문서](#관련-문서)

---

## 파일 구조 개요

`config/core.php`는 4개 최상위 키로 구성됩니다:

```php
return [
    'permissions' => [...],     // 코어 권한 정의
    'roles' => [...],           // 코어 역할 정의
    'menus' => [...],           // 코어 메뉴 정의
    'mail_templates' => [...],  // 코어 메일 템플릿 정의
];
```

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
        'permissions' => [
            [
                'identifier' => 'core.users.read',
                'name' => ['ko' => '사용자 조회', 'en' => 'View Users'],
                'description' => [...],
                'order' => 1,
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
| `resource_route_key` | ❌ | scope 기반 접근 체크 시 라우트 모델 바인딩 키 |
| `owner_key` | ❌ | scope 기반 접근 체크 시 소유자 판단 필드 |

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

## mail_templates — 메일 템플릿 정의

```php
'mail_templates' => [
    [
        'type' => 'welcome',
        'subject' => ['ko' => '[{app_name}] 환영합니다', 'en' => '...'],
        'body' => ['ko' => '<h1>{name}님...</h1>', 'en' => '...'],
        'variables' => [
            ['key' => 'name', 'description' => '수신자 이름'],
            ['key' => 'app_name', 'description' => '사이트명'],
        ],
    ],
],
```

### variables 배열

메일 템플릿에서 사용 가능한 치환 변수를 정의합니다. `{key}` 형태로 subject/body에서 사용됩니다.

---

## 사용처

| 사용처 | 시점 | 설명 |
| ---------- | ---------- | ---------- |
| `RolePermissionSeeder` | 초기 설치 | permissions, roles 데이터 시딩 |
| `CoreAdminMenuSeeder` | 초기 설치 | menus 데이터 시딩 |
| `MailTemplateSeeder` | 초기 설치 | mail_templates 데이터 시딩 |
| `CoreUpdateService::syncCoreRolesAndPermissions()` | 코어 업데이트 | 권한/역할 동기화 |
| `CoreUpdateService::syncCoreMenus()` | 코어 업데이트 | 메뉴 동기화 |
| `CoreUpdateService::syncCoreMailTemplates()` | 코어 업데이트 | 메일 템플릿 동기화 |

**핵심 원칙**: 새 권한/역할/메뉴/메일템플릿 추가 시 이 파일에 추가하면 설치/업데이트 모두 자동 반영됩니다.

---

## 관련 문서

- [permissions.md](../extension/permissions.md) - 권한 시스템 상세
- [module-basics.md](../extension/module-basics.md) - 모듈별 config.php 구조
- [service-provider.md](service-provider.md) - 서비스 프로바이더에서 config 로드
