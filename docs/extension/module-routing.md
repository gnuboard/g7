# 모듈 라우트 규칙

> 이 문서는 모듈의 백엔드 라우트(API/Web)와 프론트엔드 라우트(routes.json) 작성 규칙을 설명합니다.

---

## TL;DR (5초 요약)

```text
1. URL prefix 자동: /api/admin/[vendor-module]/...
2. name prefix 자동: api.[vendor-module]....
3. 활성화된 모듈만 라우트 등록됨
4. 프론트엔드: routes/admin.json, routes/user.json으로 admin/user 분기
5. 레거시 routes.json은 admin으로 폴백 (경고 로그 출력)
```

---

## 목차

1. [핵심 원칙](#1-핵심-원칙)
2. [라우트 등록 조건](#2-라우트-등록-조건)
3. [자동 적용되는 Prefix](#3-자동-적용되는-prefix)
4. [라우트 파일 작성](#4-라우트-파일-작성)
5. [라우트 네임 컨벤션](#5-라우트-네임-컨벤션)
6. [프론트엔드 라우트 (routes.json)](#6-프론트엔드-라우트-routesjson)
7. [캐시 관리](#7-캐시-관리)
8. [관련 문서](#8-관련-문서)

---

## 1. 핵심 원칙

```
중요: ModuleRouteServiceProvider가 URL prefix와 name prefix를 자동 적용
✅ 필수: 모듈 개발자는 모듈 내부 구조만 정의
✅ 조건: 활성화된 모듈만 라우트가 등록됨
```

---

## 2. 라우트 등록 조건

### 활성화된 모듈만 라우트 등록

- `ModuleRouteServiceProvider`가 DB에서 `status = 'active'`인 모듈만 조회
- 설치되지 않거나 비활성화된 모듈의 라우트는 등록되지 않음
- 모듈 활성화/비활성화 시 라우트가 자동으로 등록/해제됨

### 네임스페이스 변환 규칙

```
디렉토리명 → 네임스페이스
sirsoft-sample → Modules\Sirsoft\Sample
sirsoft-ecommerce → Modules\Sirsoft\Ecommerce
vendor-my-module → Modules\Vendor\My\Module
```

---

## 3. 자동 적용되는 Prefix

### API 라우트

| 항목 | 값 |
|------|-----|
| URL prefix | `api/modules/{module-name}` |
| Name prefix | `api.modules.{module-name}.` |

### 웹 라우트

| 항목 | 값 |
|------|-----|
| URL prefix | `modules/{module-name}` |
| Name prefix | `web.modules.{module-name}.` |

---

## 4. 라우트 파일 작성

### 라우트 파일 위치

```
modules/_bundled/{identifier}/src/routes/
├── api.php     # API 라우트
└── web.php     # 웹 라우트
```

### ✅ DO: 모듈 내부 구조만 정의

```php
// modules/_bundled/sirsoft-ecommerce/src/routes/api.php

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('products', [ProductController::class, 'index'])
        ->name('admin.products.index');
    // 최종 Name: api.modules.sirsoft-ecommerce.admin.products.index
    // 최종 URL: /api/modules/sirsoft-ecommerce/admin/products
});
```

### ❌ DON'T: prefix 중복 입력

```php
// 잘못된 예시 - prefix가 중복됨
Route::get('products', [ProductController::class, 'index'])
    ->name('api.modules.sirsoft-ecommerce.admin.products.index');
```

### 라우트 파일 주석 템플릿

```php
<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| [Module Name] API Routes
|--------------------------------------------------------------------------
|
| 주의: ModuleRouteServiceProvider가 자동으로 prefix를 적용합니다.
| - URL prefix: 'api/modules/{module-name}'
| - Name prefix: 'api.modules.{module-name}.'
|
*/

// 라우트 정의...
```

---

## 5. 라우트 네임 컨벤션

### 구조

```
{type}.modules.{module-name}.{area}.{resource}.{action}
```

### 예시

```
api.modules.sirsoft-ecommerce.admin.products.index
api.modules.sirsoft-ecommerce.admin.products.store
api.modules.sirsoft-ecommerce.admin.orders.show
web.modules.sirsoft-ecommerce.checkout.cart
```

### 구성 요소

| 구성 요소 | 설명 | 적용 방식 |
|-----------|------|-----------|
| `type` | api 또는 web | 자동 적용 |
| `modules` | 모듈임을 나타냄 | 자동 적용 |
| `module-name` | 모듈 식별자 | 자동 적용 |
| `area` | admin, public 등 영역 | 개발자 정의 |
| `resource` | 리소스명 | 개발자 정의 |
| `action` | index, store, show, update, destroy 등 | 개발자 정의 |

---

## 6. 프론트엔드 라우트 (routes.json)

```
중요: 백엔드 라우트(routes/api.php)와 별개로 프론트엔드 라우트도 지원
✅ 위치: modules/{identifier}/resources/routes/admin.json, routes/user.json
✅ 병합: 템플릿 타입(admin/user)에 맞는 모듈 routes만 필터링 병합
✅ 레거시: routes.json은 admin으로 폴백 (경고 로그 출력)
✅ 캐시: 모듈 활성화/비활성화 시 자동 무효화
```

### 디렉토리 구조

```
modules/{identifier}/resources/
├── routes/
│   ├── admin.json    ← admin 템플릿 전용 라우트
│   └── user.json     ← user 템플릿 전용 라우트 (선택)
└── routes.json       ← [레거시] 있으면 admin으로 취급 + 경고 로그
```

### admin/user 분기 규칙

| 라우트 파일 | 서빙 대상 | 설명 |
|------------|----------|------|
| `routes/admin.json` | admin 타입 템플릿 | 관리자 화면 라우트 |
| `routes/user.json` | user 타입 템플릿 | 일반 사용자 화면 라우트 |
| `routes.json` (레거시) | admin 타입 템플릿만 | 하위 호환성, 경고 로그 출력 |

**탐색 우선순위**:
1. `routes/{templateType}.json` 우선 탐색
2. (admin 타입만) `routes.json` 레거시 폴백
3. user 타입은 레거시 `routes.json`을 로드하지 않음

### 기본 구조

```json
{
  "version": "1.0.0",
  "routes": [
    {
      "path": "*/admin/sample",
      "layout": "admin_sample_index",
      "auth_required": true,
      "meta": {
        "title": "$t:sirsoft-sample.admin.index.title"
      }
    }
  ]
}
```

### 병합 방식

**병합 우선순위**:

1. 템플릿 routes 배열이 기본
2. 활성화된 모듈 routes를 **템플릿 타입에 맞게** 필터링하여 병합 (`array_merge`)
3. 플러그인 routes는 **admin 템플릿에만** 포함 (settings 페이지 등 admin 전용)
4. version은 템플릿의 version 사용

**레이아웃 필드 자동 변환**:

- 모듈 routes의 `layout` 필드에 모듈 식별자 접두사 자동 추가
- 예: `"admin_sample_index"` → `"sirsoft-sample.admin_sample_index"`

**최종 API 응답**:
```json
{
  "version": "1.0.0",
  "routes": [
    // 템플릿 routes...
    {
      "path": "*/admin/sample",
      "layout": "sirsoft-sample.admin_sample_index",
      "auth_required": true,
      "meta": {
        "title": "$t:sirsoft-sample.admin.index.title"
      }
    }
    // 다른 모듈 routes (템플릿 타입에 맞는 것만)...
  ]
}
```

### 모듈 레이아웃과의 연계

**모듈 레이아웃 정의**:
```
modules/{identifier}/resources/layouts/admin/{layout_name}.json
modules/{identifier}/resources/layouts/user/{layout_name}.json
```

**routes에서 레이아웃 참조**:
```json
{
  "path": "*/admin/sample",
  "layout": "admin_sample_index"
}
```

**레이아웃 조회 우선순위**:
1. 템플릿 오버라이드: `templates/{template}/layouts/overrides/{module}/{layout}.json`
2. 모듈 원본: `modules/{module}/resources/layouts/admin/{layout}.json`

### 다국어 키 사용

**routes.json에서 다국어 참조**:
```json
{
  "meta": {
    "title": "$t:sirsoft-sample.admin.index.title"
  }
}
```

**모듈 다국어 파일**:
```json
// modules/sirsoft-sample/resources/lang/ko.json
{
  "admin": {
    "index": {
      "title": "샘플 모듈 관리"
    }
  }
}
```

**프론트엔드에서 최종 키**:
- 템플릿 다국어 API가 모듈 다국어 자동 병합
- 최종 키: `sirsoft-sample.admin.index.title`

### 구현 세부사항

**TemplateService**:

```php
// 템플릿 + 모듈 routes 병합 (템플릿 타입에 따라 필터링)
public function getRoutesDataWithModules(string $identifier): array

// 템플릿 타입에 맞는 모듈 routes 로드 (routes/{type}.json 우선, 레거시 폴백)
private function loadActiveModulesRoutesData(string $templateType = 'admin'): array
```

**PublicTemplateController API**:
```
GET /api/public/templates/{identifier}/routes
```

**ClearsTemplateCaches Trait**:
```php
protected function clearAllTemplateRoutesCaches(): void
```

---

## 7. 캐시 관리

### 캐시 키 패턴

```
template.routes.{identifier}
```

### 자동 캐시 무효화

| Manager | 메서드 | 동작 |
|---------|--------|------|
| ModuleManager | `activateModule()` | routes 캐시 무효화 |
| ModuleManager | `deactivateModule()` | routes 캐시 무효화 |
| PluginManager | `activatePlugin()` | routes 캐시 무효화 |
| PluginManager | `deactivatePlugin()` | routes 캐시 무효화 |

---

## 8. 관련 문서

- [모듈 기초](module-basics.md) - 모듈 구조, AbstractModule
- [모듈 레이아웃](module-layouts.md) - 레이아웃 등록, 오버라이드
- [모듈 다국어](module-i18n.md) - 백엔드/프론트엔드 다국어
- [템플릿 라우트](template-routing.md) - 템플릿 라우트 규칙
- [확장 시스템 개요](index.md) - 전체 인덱스
