# 레이아웃 JSON 스키마

> **관련 문서**: [컴포넌트 개발](components.md) | [데이터 바인딩](data-binding.md) | [데이터 소스](data-sources.md)

---

## TL;DR (5초 요약)

```text
1. HTML 태그 직접 사용 금지 → 기본 컴포넌트 사용 (Div, Button, Span)
2. 텍스트: text 속성 또는 children 내 Span 사용 (텍스트 직접 배치 금지)
3. 다국어: "$t:key" 형식, 파라미터: "$t:key|param={{value}}"
4. 데이터 바인딩: "{{path.to.data}}" 형식
5. 다크 모드: light/dark 클래스 쌍 필수 (bg-white dark:bg-gray-800)
6. 새 속성 추가 시: UpdateLayoutContentRequest rules에도 추가 필수
7. 권한 제어: permissions 필드로 접근 권한 설정 (AND 로직, 401/403 응답)
8. globalHeaders: 패턴 기반 공통 헤더 자동 주입 (engine-v1.16.0+)
```

---

## 분리된 문서

이 문서는 가독성을 위해 다음과 같이 분리되었습니다:dff

| 문서 | 내용 |
|------|------|
| **layout-json.md** (현재) | 개요, 필수 필드, 컴포넌트 정의, 텍스트 렌더링 |
| [layout-json-features.md](layout-json-features.md) | 에러 핸들링, 초기화 액션, 모달 시스템, 액션 시스템 |
| [layout-json-components.md](layout-json-components.md) | 반복 렌더링(iteration), Blur 효과, 컴포넌트 생명주기 |
| [layout-json-inheritance.md](layout-json-inheritance.md) | 레이아웃 상속, Partial, 병합 구조, 상속 체인 검증 |

---

## 목차

1. [개요](#개요)
2. [필수 필드](#필수-필드)
3. [레이아웃 권한 (permissions)](#레이아웃-권한-permissions)
4. [컴포넌트 정의](#컴포넌트-정의)
5. [격리된 상태 (isolatedState)](#격리된-상태-isolatedstate)
6. [텍스트 렌더링](#텍스트-렌더링)

---

## 개요

**레이아웃 JSON**은 화면 구성을 정의한 JSON 파일로, 데이터베이스 `template_layouts` 테이블에 저장됩니다.

그누보드7 템플릿 시스템에서 레이아웃 JSON은 다음 역할을 합니다:
- 화면에 표시될 컴포넌트 구조 정의
- API 데이터 소스 연결
- 사용자 인터랙션(액션) 처리
- 다국어 및 데이터 바인딩 지원

---

## 필수 필드

```json
{
  "version": "1.0.0",
  "layout_name": "dashboard",
  "meta": {
    "title": "$t:dashboard.title",
    "description": "$t:dashboard.description"
  },
  "data_sources": [],
  "components": []
}
```

**필드 설명**:

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `version` | string | ✅ | 스키마 버전 (예: "1.0.0") |
| `layout_name` | string | ✅ | 레이아웃 식별자 (아래 네이밍 규칙 참조) |
| `meta` | object | ❌ | 메타 정보 (title, description) |
| `data_sources` | array | ✅ | API 데이터 소스 정의 |
| `init_actions` | array | ❌ | 초기화 액션 (레이아웃 로드 시 실행) |
| `modals` | array | ❌ | 모달 컴포넌트 정의 |
| `scripts` | array | ❌ | 외부 스크립트 동적 로드 (engine-v1.8.0+) |
| `errorHandling` | object | ❌ | 레이아웃 레벨 에러 핸들링 설정 (engine-v1.6.0+) |
| `permissions` | string[] | ❌ | 레이아웃 접근 권한 식별자 배열 (engine-v1.15.0+) |
| `globalHeaders` | array | ❌ | 전역 HTTP 헤더 규칙 배열 (engine-v1.16.0+) |
| `meta.seo` | object | ❌ | SEO 페이지 생성기 설정 (아래 참조) |
| `components` | array | ✅ | 컴포넌트 배열 |

### layout_name 네이밍 규칙

```text
✅ 허용 문자: 영문(a-z, A-Z), 숫자(0-9), 언더스코어(_), 슬래시(/), 하이픈(-), 점(.)
✅ 슬래시 사용: 계층 구조 표현 가능 (예: "board/popular", "mypage/orders/show")
✅ 언더스코어 사용: 플랫 구조 표현 가능 (예: "admin_dashboard", "admin_user_list")
파일 경로와 일치: layout_name은 파일 경로(확장자 제외)와 동일해야 함
```

**예시**:

| 파일 경로 | layout_name |
|----------|-------------|
| `layouts/home.json` | `home` |
| `layouts/board/popular.json` | `board/popular` |
| `layouts/mypage/orders/show.json` | `mypage/orders/show` |
| `layouts/admin_dashboard.json` | `admin_dashboard` |

> **상세 문서**:
> - `errorHandling`: [layout-json-features.md](layout-json-features.md#에러-핸들링-설정-errorhandling)
> - `init_actions`: [layout-json-features.md](layout-json-features.md#초기화-액션-init_actions)
> - `modals`: [layout-json-features.md](layout-json-features.md#모달-시스템-modals)
> - `scripts`: [layout-json-features.md](layout-json-features.md#외부-스크립트-로드-scripts)
> - `permissions`: [아래 섹션](#레이아웃-권한-permissions)
> - `globalHeaders`: [아래 섹션](#전역-헤더-globalheaders)
> - `meta.seo`: [아래 섹션](#metaseo-seo-페이지-생성기)

### meta.seo (SEO 페이지 생성기)

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| seo.enabled | boolean | X | SEO 렌더링 활성화 (기본: false) |
| seo.data_sources | string[] | X | SEO 시 사전 로드할 data_sources ID |
| seo.priority | number | X | sitemap priority (0.0~1.0) |
| seo.changefreq | string | X | sitemap changefreq (daily/weekly 등) |
| seo.og | object | X | Open Graph 메타태그 |
| seo.structured_data | object | X | JSON-LD 구조화 데이터 |

Partial에 meta.seo 정의 금지 (무시됨)
meta.seo 추가/변경 시 UpdateLayoutContentRequest 검증 규칙 동기화 필수
data_sources는 부모-자식 간 합집합 병합 (permissions와 동일 전략)

> 상세: [seo-system.md](../backend/seo-system.md)

---

## 레이아웃 권한 (permissions)

> **버전**: engine-v1.15.0+

레이아웃 접근에 필요한 권한을 정의합니다. 권한이 없는 사용자는 레이아웃 서빙 API에서 401/403 응답을 받습니다.

### 기본 문법

```json
{
  "version": "1.0.0",
  "layout_name": "admin_dashboard",
  "permissions": ["core.dashboard.read"],
  "meta": {
    "title": "$t:admin.dashboard.title"
  },
  "components": [...]
}
```

### 동작 규칙

| 조건 | 동작 |
|------|------|
| `permissions` 필드 없음 또는 빈 배열 | 공개 레이아웃 (권한 체크 없음) |
| `permissions`에 값이 있음 | 모든 권한 필요 (AND 로직) |
| 비회원 + 권한 없음 | 401 Unauthorized |
| 회원 + 권한 없음 | 403 Forbidden |
| Admin 역할 | 모든 권한 자동 통과 |

### 권한 식별자 형식

```text
✅ 권한 식별자 규칙: [모듈명].[엔티티].[액션]
✅ 허용 문자: 영문(a-z), 숫자(0-9), 하이픈(-), 언더스코어(_), 점(.)
✅ 예시: core.dashboard.read, sirsoft-ecommerce.products.read
```

### 상속 시 권한 병합

부모-자식 레이아웃 상속 시 permissions는 **합집합**으로 병합됩니다:

```text
부모: { "permissions": ["core.admin.access"] }
자식: { "permissions": ["core.users.read"] }
결과: { "permissions": ["core.admin.access", "core.users.read"] }
```

- 중복 제거 적용 (`array_unique`)
- 병합 결과의 모든 권한을 만족해야 접근 가능 (AND 로직)

### 예시

#### 관리자 레이아웃

```json
{
  "version": "1.0.0",
  "layout_name": "admin_user_list",
  "permissions": ["core.users.read"],
  "meta": { "title": "$t:admin.users.title" },
  "components": [...]
}
```

#### 모듈 관리 레이아웃

```json
{
  "version": "1.0.0",
  "layout_name": "admin_ecommerce_product_list",
  "permissions": ["sirsoft-ecommerce.products.read"],
  "meta": { "title": "$t:admin.ecommerce.products.title" },
  "components": [...]
}
```

#### 공개 레이아웃 (권한 없음)

```json
{
  "version": "1.0.0",
  "layout_name": "home",
  "meta": { "title": "$t:common.home" },
  "components": [...]
}
```

### 일괄 업데이트 커맨드

기존 레이아웃에 권한을 일괄 추가하려면 Artisan 커맨드를 사용합니다:

```bash
# 시뮬레이션 (실제 수정 없음)
php artisan layout:add-permissions --dry-run

# 실제 실행
php artisan layout:add-permissions

# 특정 레이아웃만
php artisan layout:add-permissions --layout=admin_dashboard
```

### 주의사항

```text
권한 식별자는 DB의 permissions 테이블에 존재해야 함
빈 배열 []은 권한 체크 없음과 동일 (공개 레이아웃)
프론트엔드는 401/403 응답 시 적절한 UI 처리 필요 (로그인 페이지 리다이렉트 등)
```

---

## 컴포넌트별 권한 (Component Permissions)

> **버전**: engine-v1.17.0+

개별 컴포넌트에 `permissions` 속성을 추가하면, **서버 사이드에서 해당 컴포넌트를 JSON에서 제거**하여 서빙합니다. 프론트엔드 `if` 조건부 렌더링과 달리, 권한 없는 컴포넌트의 구조 자체가 클라이언트에 노출되지 않습니다.

### 기본 문법

```json
{
  "type": "basic",
  "name": "Div",
  "id": "admin_widget",
  "permissions": ["core.dashboard.admin-widget"],
  "children": [
    { "type": "basic", "name": "H2", "text": "관리자 전용 위젯" }
  ]
}
```

### 동작 규칙

| 조건 | 동작 |
|------|------|
| `permissions` 없음 또는 빈 배열 | 항상 포함 (권한 체크 없음) |
| `permissions`에 값이 있음 | 모든 권한 필요 (AND 로직) |
| 권한 없음 | 컴포넌트 + 하위 children 전체 제거 |
| Admin 역할 | 모든 컴포넌트 자동 통과 |

### 필터링 대상 영역

| 영역 | 설명 |
|------|------|
| `components` | 메인 컴포넌트 트리 (재귀 children 포함) |
| `modals[]` | 모달 자체에 permissions → 모달 전체 제거 |
| `modals[].components` | 모달 내부 컴포넌트 트리 |
| `defines` | 재사용 컴포넌트 정의 |

### 상위-하위 중복 선언

각 노드는 자기 permissions만 독립 평가합니다. 별도 병합 정책 없음:

```json
{
  "type": "basic", "name": "Div",
  "permissions": ["core.users.read"],
  "children": [
    {
      "type": "basic", "name": "Button",
      "permissions": ["core.users.delete"],
      "text": "삭제"
    }
  ]
}
```

| 시나리오 | 상위 | 하위 | 결과 |
|---------|------|------|------|
| read 없음 | 전체 제거 | 평가 안 됨 | 둘 다 없음 |
| read 있음 + delete 없음 | 통과 | 제거 | Div만 남음 |
| read 있음 + delete 있음 | 통과 | 통과 | 둘 다 남음 |

### 레이아웃 최상위 permissions와의 차이

| 구분 | 레이아웃 최상위 | 컴포넌트별 |
|------|---------------|-----------|
| **범위** | 레이아웃 전체 접근 제어 | 개별 컴포넌트 표시/숨김 |
| **실패 시** | 401/403 에러 응답 | 해당 컴포넌트만 JSON에서 제거 |
| **캐싱** | 캐시 전 체크 | post-cache 필터링 (캐시 영향 없음) |

### 확장(모듈/플러그인) 컴포넌트

Extension Point 또는 Overlay로 주입된 컴포넌트에도 `permissions`를 선언할 수 있습니다. 필터링은 `applyExtensions()` 이후에 수행되므로 자동 처리됩니다.

### 주의사항

```text
권한 식별자는 DB의 permissions 테이블에 존재해야 함
보안 목적: 프론트엔드 if 조건과 달리 JSON에 컴포넌트 구조가 노출되지 않음
필터링 후 permissions 속성 자체도 제거됨 (클라이언트 노출 방지)
post-cache filtering — 기존 캐시 구조에 영향 없음
```

---

## 전역 헤더 (globalHeaders)

> **버전**: engine-v1.16.0+

레이아웃에서 정의한 모든 API 호출(data_sources, apiCall 핸들러)에 공통 헤더를 자동으로 주입합니다.

### 기본 문법

```json
{
  "version": "1.0.0",
  "layout_name": "shop_cart",
  "globalHeaders": [
    {
      "pattern": "*",
      "headers": { "X-Request-Source": "user-template" }
    },
    {
      "pattern": "/api/modules/sirsoft-ecommerce/*",
      "headers": { "X-Cart-Key": "{{_global.cartKey}}" }
    }
  ],
  "components": [...]
}
```

### globalHeaders 배열 구조

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `pattern` | string | ✅ | 엔드포인트 매칭 패턴 (`*`, `/api/shop/*` 등) |
| `headers` | object | ✅ | 헤더 키-값 쌍 (표현식 지원) |

### 패턴 매칭 규칙

| 패턴 | 설명 | 매칭 예시 |
|------|------|----------|
| `*` | 모든 API | 전체 적용 |
| `/api/modules/sirsoft-ecommerce/*` | ecommerce 모듈 API | `/api/modules/sirsoft-ecommerce/cart`, `/api/modules/sirsoft-ecommerce/products/123` |
| `/api/shop/*` | shop prefix API | `/api/shop/products`, `/api/shop/categories` |
| `/api/cart` | 정확히 일치 | `/api/cart`만 (하위 경로 제외) |

### 표현식 지원

헤더 값에서 데이터 바인딩 표현식을 사용할 수 있습니다:

```json
{
  "globalHeaders": [
    {
      "pattern": "/api/modules/sirsoft-ecommerce/*",
      "headers": {
        "X-Cart-Key": "{{_global.cartKey}}",
        "X-User-Locale": "{{_global.locale ?? 'ko'}}"
      }
    }
  ]
}
```

**지원되는 컨텍스트**:

| 컨텍스트 | 설명 |
|----------|------|
| `{{_global.xxx}}` | 전역 상태 값 |
| `{{_local.xxx}}` | 로컬 상태 값 |
| `{{route.xxx}}` | 라우트 파라미터 |
| `{{query.xxx}}` | 쿼리 파라미터 |

### 헤더 우선순위

```text
낮음 ← globalHeaders ← source.headers / params.headers → 높음
```

개별 API의 `headers` 속성이 `globalHeaders`의 동일한 키를 덮어씁니다:

```json
{
  "globalHeaders": [
    { "pattern": "*", "headers": { "X-Custom": "global-value" } }
  ],
  "data_sources": [
    {
      "id": "products",
      "endpoint": "/api/products",
      "headers": { "X-Custom": "source-value" }  // globalHeaders보다 우선
    }
  ]
}
```

### 적용 범위

| 적용 대상 | 설명 |
|----------|------|
| `data_sources` | 레이아웃의 data_sources 배열에서 정의한 API |
| `apiCall` 핸들러 | actions에서 `handler: "apiCall"`로 호출하는 API |
| `refetchDataSource` 핸들러 | DataSourceManager를 통해 호출 (자동 적용) |

### 상속 시 병합

부모-자식 레이아웃 상속 시 globalHeaders는 **pattern 기준으로 병합**됩니다:

```text
부모: [{ pattern: "*", headers: { "X-Template": "basic" } }]
자식: [{ pattern: "*", headers: { "X-Page": "cart" } }]
결과: [{ pattern: "*", headers: { "X-Template": "basic", "X-Page": "cart" } }]
```

- 동일 pattern: headers가 병합됨 (자식이 동일 키 덮어씀)
- 다른 pattern: 별도로 유지

> **상세 문서**: [layout-json-inheritance.md](layout-json-inheritance.md#globalheaders-병합)

### 주의사항

```text
표현식 값이 빈 문자열/undefined/null이면 해당 헤더는 전송되지 않음
_isolated 컨텍스트는 지원하지 않음 (data_sources fetch 시점에 격리 스코프 미확정)
시스템 내부 API(레이아웃 로드, 번역 파일 등)에는 적용되지 않음
```

---

## 새 속성 추가 규정

```text
주의: 레이아웃 JSON에 새로운 최상위 속성 추가 시 백엔드 작업 필수
필수: UpdateLayoutContentRequest rules()에도 새 속성 추가
프론트엔드만 수정하고 백엔드 누락 시 저장 후 데이터 사라짐
```

### 왜 필요한가?

Laravel의 `FormRequest::validated()` 메서드는 `rules()`에 정의된 필드만 반환합니다. 따라서:

1. 레이아웃 JSON에 새 속성을 추가해도
2. `UpdateLayoutContentRequest::rules()`에 없으면
3. 저장 시 해당 속성이 **삭제**됩니다

### 새 속성 추가 체크리스트

```
□ 프론트엔드: 레이아웃 JSON에 새 속성 추가
□ 백엔드: UpdateLayoutContentRequest::rules()에 규칙 추가
□ 백엔드: UpdateLayoutContentRequest::messages()에 에러 메시지 추가
□ 다국어: lang/ko/validation.php, lang/en/validation.php 메시지 추가
□ 테스트: 해당 속성이 저장되는지 테스트 추가
```

### 파일 위치

- FormRequest: `app/Http/Requests/Layout/UpdateLayoutContentRequest.php`
- 다국어: `lang/ko/validation.php`, `lang/en/validation.php`
- 테스트: `tests/Feature/Api/Admin/LayoutControllerTest.php`

### 예시: 새 속성 `custom_field` 추가

```php
// UpdateLayoutContentRequest::rules()
'content.custom_field' => ['nullable', 'array'],

// UpdateLayoutContentRequest::messages()
'content.custom_field.array' => __('validation.layout.custom_field.array'),
```

---

## 컴포넌트 정의

```json
{
  "id": "unique_id",
  "type": "basic|composite|layout",
  "name": "ComponentName",
  "props": {
    "title": "$t:dashboard.title",
    "value": "{{data.field}}"
  },
  "children": [],
  "actions": [
    {
      "type": "click",
      "handler": "navigate",
      "target": "/path"
    }
  ],
  "data_binding": {
    "value": "data.field"
  }
}
```

**필드 설명**:

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `id` | string | ✅ | 컴포넌트 고유 ID |
| `type` | string | ✅ | 컴포넌트 타입 (basic/composite/layout) |
| `name` | string | ✅ | 컴포넌트 이름 (components.json에 등록된 이름) |
| `comment` | string | ✅ | 시안 추적 코드 및 역할 설명 (예: "F-002: 상품명 검색 필드") |
| `props` | object | ❌ | 컴포넌트에 전달할 props |
| `text` | string | ❌ | 텍스트 콘텐츠 (최우선 렌더링) |
| `children` | array | ❌ | 자식 컴포넌트 배열 (레이아웃/집합 컴포넌트에서 사용) |
| `iteration` | object | ❌ | 반복 렌더링 설정 (source, item_var, index_var) |
| `actions` | array | ❌ | 액션 핸들러 정의 (클릭, 키보드, 폼 제출 등) |
| `lifecycle` | object | ❌ | 생명주기 핸들러 (onMount, onUnmount) |
| `data_binding` | object | ❌ | 데이터 바인딩 정의 |
| `blur_until_loaded` | boolean \| string | ❌ | 데이터 로딩 중 blur 효과 적용 (boolean 또는 표현식 문자열) |
| `component_layout` | object | ❌ | 컴포넌트 내부 커스텀 렌더링 레이아웃 정의 (RichSelect 등에서 사용) |
| `isolatedState` | object | ❌ | 격리된 상태 초기값 정의 (engine-v1.14.0+) |
| `isolatedScopeId` | string | ❌ | 격리된 스코프 식별자 (engine-v1.14.0+) |
| `autoBinding` | boolean | ❌ | `false`로 설정 시 해당 필드의 폼 자동 바인딩을 명시적으로 비활성화. 커스텀 핸들러나 G7Core API로 상태 관리 시 사용 (engine-v1.17.6+) |

> **상세 문서**:
> - `iteration`: [layout-json-components.md](layout-json-components.md#반복-렌더링-iteration)
> - `blur_until_loaded`: [layout-json-components.md](layout-json-components.md#데이터-로딩-중-blur-효과-blur_until_loaded)
> - `lifecycle`: [layout-json-components.md](layout-json-components.md#컴포넌트-생명주기-lifecycle)
> - `actions`: [layout-json-features.md](layout-json-features.md#액션-시스템-actions)
> - `isolatedState`: [아래 섹션](#격리된-상태-isolatedstate)

---

## 격리된 상태 (isolatedState)

> **버전**: engine-v1.14.0+

특정 컴포넌트 영역 내에서만 유효한 격리된 상태를 정의합니다. 해당 영역의 상태 변경 시 전체 레이아웃이 아닌 격리된 영역만 리렌더링되어 성능이 향상됩니다.

### 기본 문법

```json
{
  "type": "Div",
  "isolatedState": {
    "selectedCategories": [],
    "currentStep": 1,
    "isExpanded": false
  },
  "isolatedScopeId": "category-selector",
  "children": [...]
}
```

### 속성 설명

| 속성 | 타입 | 필수 | 설명 |
| ---- | ---- | ---- | ---- |
| `isolatedState` | object | ✅ | 격리된 상태의 초기값을 정의하는 객체 |
| `isolatedScopeId` | string | ❌ | 외부에서 접근할 때 사용하는 스코프 식별자 |

### 상태 접근 방법

격리된 상태는 `_isolated` 접두사로 접근합니다:

```json
{
  "type": "basic",
  "name": "Button",
  "props": {
    "disabled": "{{_isolated.currentStep === 1}}"
  },
  "text": "{{_isolated.selectedCategories.length}}개 선택됨"
}
```

### 상태 업데이트

`setState` 핸들러에서 `target: "isolated"` 옵션을 사용합니다:

```json
{
  "type": "click",
  "handler": "setState",
  "params": {
    "target": "isolated",
    "currentStep": "{{_isolated.currentStep + 1}}"
  }
}
```

### 사용 시점

| 상황 | 권장 상태 | 이유 |
| ---- | --------- | ---- |
| 빈번한 사용자 인터랙션 | `_isolated` | 리렌더링 최소화 |
| 독립적인 UI 영역 | `_isolated` | 다른 영역과 무관한 상태 |
| 레이아웃 전체에서 공유 필요 | `_local` | 여러 컴포넌트에서 접근 |
| 페이지 이동 후에도 유지 필요 | `_global` | 앱 전역 상태 |

### 라이프사이클

```text
1. 생성: isolatedState 속성이 있는 컴포넌트 마운트 시
2. 업데이트: setState target:"isolated" 또는 G7Core.state.setIsolated()
3. 소멸: 해당 컴포넌트 언마운트 시
4. 페이지 이동: 초기화됨 (언마운트 → 재마운트)
```

### 주의사항

```text
isolatedState 외부에서 target: "isolated" 사용 시 → _local로 폴백 + 경고 로그
isolatedScopeId 중복 시 → 마지막 등록된 스코프 우선 + 경고 로그
중첩 isolatedState 시 → 가장 가까운 스코프 사용
```

### 관련 문서

- [상태 관리 - _isolated](state-management.md#격리된-상태-_isolated)
- [액션 핸들러 - target: isolated](actions-handlers-state.md#격리된-상태-변경-target-isolated)
- [G7Core API - getIsolated/setIsolated](g7core-api.md#getisolated--setisolated-v1140)

---

## 텍스트 렌더링

```
중요: 컴포넌트에 텍스트를 표시할 때는 text 속성을 사용해야 합니다.
필수: text 속성 사용 (DynamicRenderer가 최우선 처리)
필수: children 배열 사용 (하위 컴포넌트 포함)
주의: props.children 사용 불가 (무시됨)
```

### 핵심 원칙

그누보드7 템플릿 엔진의 DynamicRenderer는 다음 우선순위로 렌더링합니다:

1. **`text` 속성** (최우선) - 텍스트 콘텐츠 표시
2. **`children` 배열** - 하위 컴포넌트 렌더링
3. **`props`** - 일반 속성 전달

### 올바른 패턴

```json
{
  "id": "page_title",
  "type": "basic",
  "name": "H1",
  "props": {
    "className": "text-2xl font-bold"
  },
  "text": "대시보드"
}
```

```json
{
  "id": "logo_text",
  "type": "basic",
  "name": "Span",
  "props": {
    "className": "text-white font-bold"
  },
  "text": "G7"
}
```

### 잘못된 패턴

```json
{
  "id": "page_title",
  "type": "basic",
  "name": "H1",
  "props": {
    "className": "text-2xl font-bold",
    "children": "대시보드"  // ❌ props.children은 무시됨
  }
}
```

### 다국어 지원

`text` 속성에서도 다국어 키를 사용할 수 있습니다:

```json
{
  "id": "page_title",
  "type": "basic",
  "name": "H1",
  "props": {
    "className": "text-2xl font-bold"
  },
  "text": "$t:dashboard.title"
}
```

### 데이터 바인딩 지원

`text` 속성에서 데이터 바인딩도 가능합니다:

```json
{
  "id": "user_name",
  "type": "basic",
  "name": "Span",
  "text": "{{user.name}}"
}
```

### children 배열과의 차이

```json
// ✅ text 속성: 단순 텍스트 표시
{
  "id": "title",
  "type": "basic",
  "name": "H1",
  "text": "제목"
}

// ✅ children 배열: 하위 컴포넌트 포함
{
  "id": "card",
  "type": "composite",
  "name": "Card",
  "children": [
    {
      "id": "card_title",
      "type": "basic",
      "name": "H2",
      "text": "카드 제목"
    },
    {
      "id": "card_content",
      "type": "basic",
      "name": "P",
      "text": "카드 내용"
    }
  ]
}
```

### DynamicRenderer 동작 방식

```typescript
// DynamicRenderer.tsx (코어 엔진)
const renderChildren = useMemo(() => {
  // 1. text 속성이 있으면 최우선으로 사용
  if (componentDef.text !== undefined) {
    return componentDef.text;
  }

  // 2. children이 없으면 null
  if (!componentDef.children || componentDef.children.length === 0) {
    return null;
  }

  // 3. children 배열 렌더링
  return componentDef.children.map((childDef, index) => {
    // ...
  });
}, [componentDef.text, componentDef.children]);
```

### 주의사항

- ❌ `props.children`은 DynamicRenderer에서 완전히 무시됩니다
- ✅ React 컴포넌트 개발 시 `children` prop은 정상 작동 (TSX 내부)
- ✅ 레이아웃 JSON에서는 `text` 속성과 `children` 배열만 사용

### 실제 사용 예시

```json
{
  "id": "sidebar_logo",
  "type": "basic",
  "name": "Div",
  "props": {
    "className": "flex items-center gap-3"
  },
  "children": [
    {
      "id": "logo_circle",
      "type": "basic",
      "name": "Div",
      "props": {
        "className": "flex items-center justify-center w-10 h-10 bg-black rounded-full"
      },
      "children": [
        {
          "id": "logo_text",
          "type": "basic",
          "name": "Span",
          "props": {
            "className": "text-white font-bold text-sm"
          },
          "text": "G7"
        }
      ]
    },
    {
      "id": "app_name",
      "type": "basic",
      "name": "Span",
      "props": {
        "className": "font-semibold text-lg"
      },
      "text": "G7"
    }
  ]
}
```

---

## extensionPointProps (engine-v1.15.0+)

`extension_point` 타입 컴포넌트에서 주입되는 컴포넌트에 추가 props를 전달합니다.

```json
{
  "type": "extension_point",
  "name": "product_detail_sidebar",
  "extensionPointProps": {
    "productId": "{{route.id}}",
    "currency": "{{_global.currency ?? 'KRW'}}"
  }
}
```

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `extensionPointProps` | object | ❌ | extension_point에 주입된 컴포넌트에 전달할 props (표현식 지원) |

---

## Deprecated 속성

하위 호환성을 위해 별칭이 유지되는 속성입니다. 새 코드에서는 권장 속성을 사용하세요.

| Deprecated | 권장 속성 | 호환성 | 비고 |
|-----------|----------|--------|------|
| `init_actions` | `initActions` | 별칭 유지 (engine-v1.0.0+) | 카멜케이스 통일 |
| `state` | `initLocal` | 별칭 유지 (engine-v1.11.0+) | `initLocal`이 더 명시적 |
| `auth_required` | `auth_mode` | 별칭 유지 (engine-v1.0.0+) | `auth_mode`가 더 세밀한 제어 지원 |

```text
기존 레이아웃에서 deprecated 속성 사용 시 정상 동작 (별칭 유지)
✅ 신규 레이아웃에서는 권장 속성만 사용
✅ 리팩토링 시 권장 속성으로 변경 권장
```

---

## 관련 문서

- [컴포넌트 개발 규칙](components.md) - basic, composite, layout 컴포넌트
- [데이터 바인딩](data-binding.md) - `{{}}` 표현식, `$t:` 다국어
- [데이터 소스](data-sources.md) - API 데이터 자동 fetch
- [상태 관리](state-management.md) - `_global` 전역 상태
