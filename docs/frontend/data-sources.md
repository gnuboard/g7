# 데이터 소스 (Data Sources)

> **중요도**: 매우 중요
> **관련 문서**: [data-binding.md](data-binding.md) | [layout-json.md](layout-json.md) | [index.md](index.md)

---

## TL;DR (5초 요약)

```text
1. data_sources 배열에 API 정의: id, endpoint, method
2. 로딩 전략: eager(즉시), lazy(지연), on_demand(명시적)
3. 컴포넌트 바인딩: data="users" 또는 {{users.data}}
4. params 필드: GET → 쿼리스트링, POST/PUT/PATCH → request body
5. ID 고유성: 부모-자식 레이아웃 간 data_sources ID 충돌 금지
6. globalHeaders: 레이아웃에 정의 시 패턴 매칭되는 API에 헤더 자동 적용 (engine-v1.16.0+)
7. contentType: "multipart/form-data" → params를 FormData로 자동 변환 (파일 업로드, engine-v1.19.0+)
```

---

## 분리된 문서

이 문서는 가독성을 위해 다음과 같이 분리되었습니다:

| 문서 | 내용 |
|------|------|
| **data-sources.md** (현재) | 개요, 기본 구조, 로딩 전략, 컴포넌트 바인딩, 엔드포인트 규칙, 파라미터 치환 |
| [data-sources-advanced.md](data-sources-advanced.md) | 정적 데이터, 조건부 로딩, 에러 처리, 상태 초기화 (`initLocal` + `_merge` 3모드), 성능 최적화, 주의사항 |

---

## 목차

1. [개요](#개요)
2. [기본 구조](#기본-구조)
3. [필드 상세 설명](#필드-상세-설명)
4. [로딩 전략 (Loading Strategy)](#로딩-전략-loading-strategy)
5. [실제 사용 예시](#실제-사용-예시)
6. [컴포넌트 데이터 바인딩](#컴포넌트-데이터-바인딩)
7. [엔드포인트 규칙](#엔드포인트-규칙)
8. [파라미터 치환](#파라미터-치환)

---

## 개요

**정의**: 레이아웃 JSON에서 API 데이터를 자동으로 fetch하고 컴포넌트에 바인딩하는 시스템

**목적**:
- 레이아웃 로딩 시 필요한 데이터를 자동으로 fetch
- 병렬 처리를 통한 성능 최적화
- Progressive loading으로 UX 향상
- 컴포넌트와 API 데이터 자동 연결

---

## 기본 구조

```json
{
  "data_sources": [
    {
      "id": "users",
      "type": "api",
      "endpoint": "/api/admin/users",
      "method": "GET",
      "auto_fetch": true,
      "auth_required": true,
      "loading_strategy": "progressive",
      "params": {
        "page": "{{query.page}}",
        "keyword": "{{route.keyword}}"
      }
    }
  ]
}
```

---

## 필드 상세 설명

| 필드 | 타입 | 필수 | 기본값 | 설명 |
|------|------|------|--------|------|
| `id` | string | ✅ | - | 데이터 소스 고유 ID (컴포넌트에서 `{{id.data}}` 형태로 참조) |
| `type` | string | ✅ | - | 데이터 소스 타입 (`api`, `static`, `route_params`, `query_params`, `websocket`) |
| `endpoint` | string | ✅* | - | API 엔드포인트 (type이 `api`일 때 필수) |
| `method` | string | ❌ | `GET` | HTTP 메서드 (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`) |
| `auto_fetch` | boolean | ❌ | `true` | 자동 fetch 여부 (false 시 수동 호출 필요) |
| `auth_mode` | string | ❌ | `none` | 인증 모드: `required` (토큰 필수), `optional` (토큰 있으면 전송), `none` (토큰 안 보냄) |
| `auth_required` | boolean | ❌ | `false` | **deprecated** - `auth_mode` 사용 권장. `true`는 `auth_mode: "required"`와 동일 |
| `loading_strategy` | string | ❌ | `progressive` | 로딩 전략 (`blocking`, `progressive`, `background`) |
| `params` | object | ❌ | `{}` | 요청 파라미터 (GET: 쿼리스트링, POST/PUT/PATCH: request body) |
| `data` | any | ✅* | - | 정적 데이터 (type이 `static`일 때 필수) |
| `initLocal` | string \| object | ❌ | - | API 응답을 `_local[key]`에 자동 복사 (폼 편집용) |
| `initGlobal` | string \| object | ❌ | - | API 응답을 `_global[key]`에 자동 복사 (전역 공유용) |
| `initIsolated` | string \| object | ❌ | - | API 응답을 `_isolated[key]`에 자동 복사 (격리된 영역용, engine-v1.14.0+) |
| `refetchOnMount` | boolean | ❌ | `false` | 페이지 진입 시 항상 데이터를 다시 fetch |
| `contentType` | string | ❌ | `application/json` | 요청 Content-Type. `"multipart/form-data"` 지정 시 params를 FormData로 변환 (engine-v1.19.0+) |
| `if` | string | ❌ | - | 조건부 로딩 표현식 (truthy일 때만 fetch) |
| `conditions` | ConditionExpression | ❌ | - | 복합 조건부 로딩 (AND/OR 그룹 지원, engine-v1.10.0+) |

> **상세 문서**: `initLocal`, `initGlobal`, `initIsolated`, `if`, 에러 처리는 [data-sources-advanced.md](data-sources-advanced.md) 참조

---

## params 필드 동작 방식

> **중요**: `params` 필드의 동작은 HTTP 메서드에 따라 다릅니다.

### HTTP 메서드별 params 처리

| HTTP 메서드 | params 전송 방식 | 예시 |
|------------|-----------------|------|
| `GET` | **쿼리 스트링** | `/api/products?page=1&keyword=test` |
| `POST` | **request body** | `{ "selected_ids": [1, 2, 3] }` |
| `PUT` | **request body** | `{ "name": "홍길동", "email": "test@example.com" }` |
| `PATCH` | **request body** | `{ "status": "active" }` |
| `DELETE` | **쿼리 스트링** | `/api/products/1?force=true` |

### 사용 예시

```json
// GET 요청 - params가 쿼리 스트링으로 전송
{
  "id": "products",
  "endpoint": "/api/products",
  "method": "GET",
  "params": {
    "page": "{{query.page}}",
    "keyword": "{{_local.searchKeyword}}"
  }
}
// 결과: GET /api/products?page=1&keyword=test

// POST 요청 - params가 request body로 전송
{
  "id": "cartItems",
  "endpoint": "/api/cart/query",
  "method": "POST",
  "params": {
    "selected_ids": "{{_local.selectedItems?.length > 0 ? _local.selectedItems : undefined}}"
  }
}
// 결과: POST /api/cart/query, body: { "selected_ids": [1, 2, 3] }
```

### 주의사항

```text
주의: data_sources에서 "body" 필드 미지원 — "params" 사용
필수: POST 요청의 body 데이터도 "params" 필드에 정의
권장: 빈 배열/null 전송 방지를 위한 조건부 표현식 사용
```

### multipart/form-data 지원 (파일 업로드)

> **버전**: engine-v1.19.0+

`contentType: "multipart/form-data"`를 지정하면 `params`가 자동으로 `FormData`로 변환됩니다.
인증 경로(ApiClient)와 비인증 경로(raw fetch) 모두 지원됩니다.

```json
{
  "id": "upload_module",
  "type": "api",
  "endpoint": "/api/admin/modules/manual-install",
  "method": "POST",
  "auto_fetch": false,
  "auth_mode": "required",
  "contentType": "multipart/form-data",
  "params": {
    "file": "{{_global.moduleUploadFile}}",
    "source": "file_upload"
  }
}
```

#### 표현식 기반 contentType

`contentType`은 `{{}}` 표현식을 지원합니다:

```json
{
  "contentType": "{{_global.uploadFile ? 'multipart/form-data' : 'application/json'}}"
}
```

#### FormData 변환 규칙

| params 값 타입 | FormData 처리 |
|---------------|---------------|
| `File` / `Blob` | `formData.append(key, value)` (원본 유지) |
| `string` / `number` / `boolean` | `formData.append(key, String(value))` |
| `object` / `array` | `formData.append(key, JSON.stringify(value))` |
| `null` / `undefined` | 제외 (전송하지 않음) |

#### multipart 주의사항

```text
주의: multipart 사용 시 params의 deep clone(JSON.parse/stringify)이 자동으로 비활성화됨
   → File/Blob 객체가 JSON 직렬화로 파괴되는 것을 방지
주의: Content-Type 헤더를 수동 설정하지 말 것
   → 브라우저가 boundary를 포함한 Content-Type을 자동 생성
필수: 파일 업로드 API는 백엔드에서 multipart/form-data를 기대해야 함
```

### 빈 값 전송 방지 패턴

```json
// ❌ 금지: 빈 배열도 전송됨
"params": {
  "selected_ids": "{{_local.selectedItems}}"
}

// ✅ 권장: 빈 배열일 때 undefined로 제외
"params": {
  "selected_ids": "{{_local.selectedItems?.length > 0 ? _local.selectedItems : undefined}}"
}
```

---

## ID 고유성 규칙

> **중요**: `extends`로 상속받는 레이아웃에서는 부모 레이아웃의 `data_sources` ID와 동일한 ID를 사용하면 **검증 오류**가 발생합니다.

### 규칙

```text
필수: data_sources ID는 부모/자식 레이아웃 간 고유해야 함
자식 레이아웃에서는 고유한 ID 사용
✅ 허용: 부모의 data_sources를 자식에서 직접 참조 ({{부모ID.data}})
```

### 오류 예시

**부모 레이아웃** (`_user_base.json`):
```json
{
  "layout_name": "_user_base",
  "data_sources": [
    { "id": "boards", "endpoint": "/api/boards" },
    { "id": "notifications", "endpoint": "/api/notifications" }
  ]
}
```

**자식 레이아웃** (`board/boards.json`) - ❌ 오류 발생:
```json
{
  "extends": "_user_base",
  "data_sources": [
    { "id": "boards", "endpoint": "/api/boards?limit=100" }  // ❌ 부모와 ID 충돌
  ]
}
```

### 해결 방법

**방법 1**: 자식에서 고유한 ID 사용
```json
{
  "extends": "_user_base",
  "data_sources": [
    { "id": "boardList", "endpoint": "/api/boards?limit=100" }  // ✅ 고유한 ID
  ]
}
```

**방법 2**: 부모의 data_sources 직접 사용 (새로 정의하지 않음)
```json
{
  "extends": "_user_base",
  "data_sources": [],  // 부모의 boards를 그대로 사용
  "components": [
    {
      "props": { "data": "{{boards.data}}" }  // ✅ 부모의 ID 참조
    }
  ]
}
```

### 네이밍 컨벤션 권장

| 부모 ID | 자식 ID (권장) |
|---------|---------------|
| `boards` | `boardList`, `boardsFiltered` |
| `notifications` | `userNotifications`, `notificationList` |
| `cart` | `cartItems`, `cartData` |

---

## 인증 모드 (auth_mode)

> **engine-v1.15.0+**: 비회원/회원 공용 API를 위한 `auth_mode: "optional"` 지원

### 모드 비교표

| 모드 | 토큰 전송 | 토큰 없을 때 | 사용 사례 |
|------|----------|-------------|----------|
| `none` (기본) | ❌ 안 보냄 | 정상 요청 | 공개 API (상품 목록, 게시판 등) |
| `required` | ✅ 필수 | **요청 스킵 → fallback 사용** | 마이페이지, 관리자 API |
| `optional` | ✅ 있으면 전송 | 비회원으로 요청 | **장바구니, 위시리스트** (비회원/회원 공용) |

### 사용 예시

```json
// 공개 API (인증 불필요)
{
  "id": "products",
  "endpoint": "/api/modules/sirsoft-ecommerce/products"
  // auth_mode 미지정 = "none"
}

// 인증 필수 API
{
  "id": "myOrders",
  "endpoint": "/api/modules/sirsoft-ecommerce/orders/my",
  "auth_mode": "required"
}

// 비회원/회원 공용 API (장바구니)
{
  "id": "cart",
  "endpoint": "/api/modules/sirsoft-ecommerce/cart",
  "auth_mode": "optional",
  "headers": {
    "X-Cart-Key": "{{_global.cartKey}}"
  }
}
```

### 동작 원리

```text
auth_mode: "required" 동작 흐름:

1. 토큰 유무 확인 (AuthManager.isAuthenticated())
2. 토큰 있음 → ApiClient 사용 (Authorization: Bearer xxx)
3. 토큰 없음 → 요청 스킵, fallback 데이터 사용 (API 호출하지 않음)

auth_mode: "optional" 동작 흐름:

1. 토큰 유무 확인 (AuthManager.isAuthenticated())
2. 토큰 있음 → ApiClient 사용 (Authorization: Bearer xxx)
3. 토큰 없음 → 일반 fetch 사용 (headers에 X-Cart-Key 등 포함 가능)
```

> **중요**: `auth_mode: "required"`에서 토큰이 없으면 API 요청 자체를 하지 않습니다.
> `fallback`이 정의되어 있으면 fallback 데이터가 렌더링에 사용되고, 없으면 데이터 없이 처리됩니다.
> 이를 통해 비로그인 상태에서 인증 필수 API 호출로 인한 401 → 로그인 리다이렉트 문제를 방지합니다.

### 하위 호환성

`auth_required: true`는 `auth_mode: "required"`와 동일하게 동작합니다.

```json
// 기존 방식 (deprecated)
{ "auth_required": true }

// 권장 방식
{ "auth_mode": "required" }
```

---

## globalHeaders 자동 적용

> **버전**: engine-v1.16.0+

레이아웃에 `globalHeaders`가 정의되어 있으면, 해당 패턴에 매칭되는 모든 data_source API 호출에 헤더가 자동으로 추가됩니다.

### 동작 방식

```json
// 레이아웃 최상위
{
  "globalHeaders": [
    { "pattern": "/api/modules/sirsoft-ecommerce/*", "headers": { "X-Cart-Key": "{{_global.cartKey}}" } }
  ],
  "data_sources": [
    {
      "id": "cart",
      "endpoint": "/api/modules/sirsoft-ecommerce/cart",  // 패턴 매칭됨
      "method": "GET"
      // X-Cart-Key 헤더 자동 포함
    }
  ]
}
```

### 헤더 우선순위

개별 `headers` 속성은 globalHeaders보다 우선합니다:

```json
{
  "globalHeaders": [
    { "pattern": "*", "headers": { "X-Custom": "global-value" } }
  ],
  "data_sources": [
    {
      "id": "products",
      "endpoint": "/api/products",
      "headers": { "X-Custom": "source-value" }  // "source-value"가 사용됨
    }
  ]
}
```

### 상세 문서

- globalHeaders 스키마: [layout-json.md](layout-json.md#전역-헤더-globalheaders)
- 상속 시 병합 규칙: [layout-json-inheritance.md](layout-json-inheritance.md#globalheaders-병합)

---

## 로딩 전략 (Loading Strategy)

> 중요: 그누보드7 템플릿 엔진은 세 가지 로딩 전략을 지원하여 다양한 UX 요구사항에 대응합니다.

### 전략 비교표

| 전략 | 렌더링 시점 | 데이터 fetch 시점 | 사용 사례 | 장점 | 단점 |
|------|------------|------------------|----------|------|------|
| `blocking` | 데이터 로드 완료 후 | 렌더링 전 | SEO 중요 페이지, 핵심 데이터 | 완전한 데이터로 렌더링 | 초기 로딩 느림 |
| `progressive` | 즉시 (스켈레톤 UI) | 렌더링 후 병렬 | 관리자 대시보드, 목록 페이지 | 빠른 첫 렌더링 | 재렌더링 발생 |
| `background` | 즉시 (빈 데이터) | 렌더링 후 비동기 | 부가 정보, 알림 | 초기 렌더링 최소화 | 데이터 없이 표시 |

### 동작 흐름

#### 1. blocking (블로킹)

```text
레이아웃 로드 → data_sources fetch (대기) → 렌더링
                     ↓
            모든 데이터 준비 완료
```

#### 2. progressive (프로그레시브, 기본값)

```text
레이아웃 로드 → 즉시 렌더링 (스켈레톤 UI)
                     ↓
              data_sources fetch (병렬)
                     ↓
              데이터 도착 → 재렌더링 (데이터 표시)
```

#### 3. background (백그라운드)

```text
레이아웃 로드 → 즉시 렌더링 (빈 데이터)
                     ↓
              data_sources fetch (비동기, 결과 무시)
                     ↓
              (사용자는 초기 화면 확인 가능)
```

### 선택 가이드

```text
✅ blocking 사용 케이스:
  - 메타 태그 정보 (SEO)
  - 결제/주문 페이지 (데이터 무결성 중요)
  - 사용자 권한 정보 (보안)

✅ progressive 사용 케이스 (권장):
  - 관리자 대시보드
  - 목록 페이지 (사용자, 상품, 주문 등)
  - 사이드바 메뉴
  - 프로필 정보

✅ background 사용 케이스:
  - 알림 카운트
  - 부가 통계 정보
  - 실시간 업데이트가 필요 없는 데이터
```

---

## 실제 사용 예시

```json
{
  "version": "1.0.0",
  "layout_name": "_admin_base",
  "data_sources": [
    {
      "id": "admin_menu",
      "type": "api",
      "endpoint": "/api/admin/menus",
      "method": "GET",
      "auto_fetch": true,
      "auth_required": true,
      "loading_strategy": "progressive"
    },
    {
      "id": "current_user",
      "type": "api",
      "endpoint": "/api/admin/auth/user",
      "method": "GET",
      "auto_fetch": true,
      "auth_required": true,
      "loading_strategy": "progressive"
    },
    {
      "id": "notifications",
      "type": "api",
      "endpoint": "/api/admin/notifications",
      "method": "GET",
      "auto_fetch": true,
      "auth_required": true,
      "loading_strategy": "progressive"
    }
  ],
  "components": [
    {
      "id": "admin_sidebar",
      "type": "composite",
      "name": "AdminSidebar",
      "props": {
        "menu": "{{admin_menu.data}}"
      }
    },
    {
      "id": "user_profile",
      "type": "composite",
      "name": "UserProfile",
      "props": {
        "user": "{{current_user.data}}"
      }
    }
  ]
}
```

---

## 컴포넌트 데이터 바인딩

### 바인딩 문법

```json
{
  "props": {
    "menu": "{{admin_menu.data}}",
    "user": "{{current_user.data}}",
    "notifications": "{{notifications.data}}"
  },
  "data_binding": {
    "menu": "admin_menu.data",
    "user": "current_user.data",
    "notifications": "notifications.data"
  }
}
```

### 바인딩 규칙

- 데이터 소스 ID로 참조: `{{데이터소스ID.data}}`
- API 응답의 `data` 필드가 자동으로 바인딩됨
- `data_binding` 필드로 명시적 매핑 가능

---

## 엔드포인트 규칙

> **중요**: ApiClient는 baseURL로 '/api'를 사용합니다.

```text
주의: ApiClient는 baseURL로 '/api'를 사용
✅ 필수: endpoint에 '/api' 포함 필요
✅ 자동: DataSourceManager가 중복 제거 처리
```

### 올바른 엔드포인트 작성

```json
// ✅ DO: '/api' 포함 (권장)
{
  "endpoint": "/api/admin/menus"
}

// ✅ DO: '/api' 미포함 (자동 추가)
{
  "endpoint": "/admin/menus"
}

// ❌ DON'T: 절대 경로 사용 금지
{
  "endpoint": "http://example.com/api/admin/menus"
}
```

### DataSourceManager의 endpoint 정규화

```typescript
// DataSourceManager.ts
const normalizedEndpoint = endpoint.startsWith('/api/')
  ? endpoint.substring(4) // '/api/admin/menus' → '/admin/menus'
  : endpoint.startsWith('/api')
  ? endpoint.substring(4) || '/' // '/api' → '/'
  : endpoint; // '/admin/menus' → '/admin/menus'

// ApiClient 호출 시 baseURL '/api'와 결합
// 결과: '/api' + '/admin/menus' = '/api/admin/menus'
```

---

## 파라미터 치환

라우트 및 쿼리 파라미터를 데이터 소스에서 활용할 수 있습니다.

### 기본 문법

| 문법 | 설명 | 예시 |
|------|------|------|
| `{{route.xxx}}` | 라우트 파라미터 | `/admin/users/:id` → `{{route.id}}` |
| `{{query.xxx}}` | 쿼리 파라미터 (점 표기법) | `?page=1` → `{{query.page}}` |
| `{{query['xxx']}}` | 쿼리 파라미터 (대괄호 표기법) | `?filters[0][field]=name` → `{{query['filters[0][field]']}}` |

### Fallback 연산자 (기본값 지정)

쿼리 파라미터가 없을 때 기본값을 지정할 수 있습니다.

```json
{
  "params": {
    "sort_order": "{{query.sort_order || 'desc'}}",
    "filters[0][field]": "{{query['filters[0][field]'] || 'all'}}"
  }
}
```

| 문법 | 설명 |
|------|------|
| `{{query.xxx \|\| 'default'}}` | 점 표기법 + fallback |
| `{{query['xxx'] \|\| 'default'}}` | 대괄호 표기법 + fallback |

**동작 방식**:

- 쿼리 파라미터가 존재하고 빈 문자열이 아니면 → 해당 값 사용
- 쿼리 파라미터가 없거나 빈 문자열이면 → fallback 값 사용

### 빈 파라미터 자동 제거

DataSourceManager는 치환 후 빈 문자열인 파라미터를 자동으로 제거합니다:

```typescript
// 빈 문자열인 파라미터 제거 (API로 전송하지 않음)
Object.keys(params).forEach((key) => {
  if (params[key] === '') {
    delete params[key];
  }
});
```

### 다중 필터 파라미터 예시

```json
{
  "data_sources": [
    {
      "id": "product_detail",
      "type": "api",
      "endpoint": "/api/admin/products/{{route.id}}",
      "method": "GET",
      "auto_fetch": true,
      "auth_required": true,
      "loading_strategy": "blocking"
    },
    {
      "id": "filtered_users",
      "type": "api",
      "endpoint": "/api/admin/users",
      "method": "GET",
      "auto_fetch": true,
      "auth_required": true,
      "loading_strategy": "progressive",
      "params": {
        "page": "{{query.page}}",
        "keyword": "{{query.keyword}}",
        "status": "{{query.status}}",
        "filters[0][field]": "{{query['filters[0][field]'] || 'all'}}",
        "filters[0][value]": "{{query['filters[0][value]']}}",
        "filters[0][operator]": "{{query['filters[0][operator]']}}"
      }
    }
  ]
}
```

---

## 부분 업데이트 API

배열 내 특정 아이템만 업데이트하여 성능을 최적화합니다.

### G7Core.dataSource.updateItem()

```typescript
G7Core.dataSource.updateItem(
  dataSourceId: string,
  itemPath: string,
  itemId: string | number,
  updates: Record<string, any>,
  options?: {
    idField?: string;      // 기본: "id"
    merge?: boolean;       // 기본: true (깊은 병합)
    skipRender?: boolean;  // 기본: false
  }
): boolean;
```

### 사용 예시

```typescript
// 상품 옵션 업데이트
G7Core.dataSource.updateItem(
  'products',
  'data.data[0].options',
  123,  // optionId
  { selling_price: 15000, _modified: true }
);

// 사용자 정보 업데이트
G7Core.dataSource.updateItem(
  'users',
  'data',
  'user-456',
  { name: '홍길동', updated_at: new Date().toISOString() },
  { idField: 'uuid' }
);
```

### set() vs updateItem() 비교

| 메서드 | 용도 | 렌더링 영향 |
|--------|------|-------------|
| `set()` | 전체 데이터소스 교체 | 전체 리렌더링 |
| `updateItem()` | 배열 내 특정 아이템 수정 | 최소 리렌더링 |

### 언제 사용하나요?

```text
✅ updateItem 권장:
  - 인라인 편집 (DataGrid 셀 편집)
  - 목록 아이템 상태 변경 (토글, 체크박스)
  - 부분 필드 업데이트 (가격, 수량 등)

❌ set 권장:
  - 전체 목록 새로고침
  - 정렬/필터 적용 후 결과 교체
  - API 응답 전체 저장
```

---

## 관련 문서

- [고급 데이터 소스](data-sources-advanced.md) - 조건부 로딩, 에러 처리, 상태 초기화
- [데이터 바인딩](data-binding.md) - 데이터 바인딩 문법 및 표현식
- [레이아웃 JSON](layout-json.md) - 레이아웃 JSON 스키마
- [전역 상태 관리](state-management.md) - 전역 상태 접근 및 업데이트
