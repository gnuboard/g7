# 데이터 소스 - 고급 기능

> **메인 문서**: [data-sources.md](data-sources.md)
> **관련 문서**: [data-binding.md](data-binding.md) | [state-management.md](state-management.md)

---

## 목차

1. [정적 데이터 소스](#정적-데이터-소스)
2. [조건부 데이터 소스 로딩](#조건부-데이터-소스-로딩-if)
3. [복합 조건부 로딩 (conditions)](#복합-조건부-로딩-conditions) ⭐ NEW (engine-v1.10.0+)
4. [에러 처리](#에러-처리)
5. [성공 콜백 (onSuccess)](#성공-콜백-onsuccess) ⭐ NEW (engine-v1.17.0+)
6. [상태 초기화](#상태-초기화-initlocal--initglobal--initisolated)
7. [WebSocket 데이터 소스](#websocket-데이터-소스)
8. [성능 최적화](#성능-최적화)
9. [네이밍 규칙](#네이밍-규칙)
10. [주의사항](#주의사항)
11. [상태 오버라이드 파라미터](#상태-오버라이드-파라미터-engine-v1170)

---

## 정적 데이터 소스

API 호출 없이 정적 데이터를 정의할 수 있습니다.

```json
{
  "data_sources": [
    {
      "id": "status_options",
      "type": "static",
      "data": [
        { "value": "active", "label": "활성" },
        { "value": "inactive", "label": "비활성" },
        { "value": "pending", "label": "대기중" }
      ]
    }
  ]
}
```

---

## 조건부 데이터 소스 로딩 (if)

> **engine-v1.4.0+**: 같은 폼에서 생성/수정 모드를 분기하거나, 권한에 따라 다른 API를 호출해야 할 때 사용합니다.

### 기본 문법

`if` 속성에 표현식을 지정하면 조건이 truthy일 때만 데이터 소스를 fetch합니다.

```json
{
  "data_sources": [
    {
      "id": "user",
      "type": "api",
      "endpoint": "/api/admin/users/{{route.id}}",
      "if": "{{route.id}}",
      "initLocal": "form"
    },
    {
      "id": "user",
      "type": "api",
      "endpoint": "/api/admin/users/template",
      "if": "{{!route.id}}",
      "initLocal": "form"
    }
  ]
}
```

### 동작 원리

1. **조건 평가**: 페이지 로드 시 각 데이터 소스의 `if` 표현식을 평가
2. **중복 ID 처리**: 같은 `id`를 가진 데이터 소스 중 조건을 만족하는 **첫 번째만** 선택
3. **fetch 실행**: 선택된 데이터 소스만 API 호출

```text
레이아웃 로드 → if 조건 평가 → 조건 만족 데이터 소스 선택 → fetch
                                      ↓
              같은 id가 여러 개면 첫 번째 매칭만 사용
```

### 지원 컨텍스트

| 컨텍스트 | 지원 | 예시 |
|---------|------|------|
| `route` | ✅ | `{{route.id}}`, `{{route.slug}}` |
| `query` | ✅ | `{{query.mode}}`, `{{query.tab}}` |
| `_global` | ✅ | `{{_global.isAdmin}}`, `{{_global.currentUser}}` |
| `_local` | ❌ | 렌더링 전에 평가되므로 사용 불가 |

### 사용 예시

#### 1. 생성/수정 폼 분기

```json
{
  "data_sources": [
    {
      "id": "user",
      "type": "api",
      "endpoint": "/api/admin/users/{{route.id}}",
      "auth_required": true,
      "loading_strategy": "blocking",
      "initLocal": "form",
      "if": "{{route.id}}"
    },
    {
      "id": "user",
      "type": "api",
      "endpoint": "/api/admin/users/template",
      "auth_required": true,
      "loading_strategy": "blocking",
      "initLocal": "form",
      "if": "{{!route.id}}"
    }
  ]
}
```

- `/admin/users/create` → `route.id` 없음 → template API 호출
- `/admin/users/123/edit` → `route.id`=123 → users/123 API 호출

#### 2. 쿼리 파라미터 기반 분기

```json
{
  "data_sources": [
    {
      "id": "data",
      "type": "api",
      "endpoint": "/api/admin/users/{{route.id}}",
      "if": "{{query.mode === 'edit'}}"
    },
    {
      "id": "data",
      "type": "api",
      "endpoint": "/api/admin/users/template",
      "if": "{{query.mode !== 'edit'}}"
    }
  ]
}
```

#### 3. 권한 기반 분기

```json
{
  "data_sources": [
    {
      "id": "stats",
      "type": "api",
      "endpoint": "/api/admin/stats/full",
      "if": "{{_global.isAdmin}}"
    },
    {
      "id": "stats",
      "type": "api",
      "endpoint": "/api/admin/stats/basic",
      "if": "{{!_global.isAdmin}}"
    }
  ]
}
```

#### 4. 복잡한 조건 표현식

```json
{
  "data_sources": [
    {
      "id": "premium_content",
      "type": "api",
      "endpoint": "/api/content/premium",
      "if": "{{route.type === 'premium' && _global.isPremiumUser}}"
    },
    {
      "id": "premium_content",
      "type": "api",
      "endpoint": "/api/content/preview",
      "if": "{{route.type === 'premium' && !_global.isPremiumUser}}"
    },
    {
      "id": "premium_content",
      "type": "api",
      "endpoint": "/api/content/basic",
      "if": "{{route.type !== 'premium'}}"
    }
  ]
}
```

### 주의사항

```text
✅ DO: 같은 id로 상호 배타적인 조건 정의
✅ DO: 부정 연산자(!)로 else 케이스 처리
✅ DO: 복잡한 조건은 && || 연산자 활용

CAUTION: _local은 렌더링 전이라 사용 불가
CAUTION: 조건이 모두 false면 해당 id의 데이터 없음
CAUTION: 조건 평가는 페이지 로드 시점에 한 번만 수행

❌ DON'T: 같은 id로 if 없는 데이터 소스와 if 있는 데이터 소스 혼용
❌ DON'T: 중복되는 조건 정의 (첫 번째만 선택됨)
```

---

## 복합 조건부 로딩 (conditions)

> **engine-v1.10.0+**: 기존 `if` 속성의 상위호환으로, AND/OR 그룹을 통해 더 복잡한 조건을 표현할 수 있습니다.

### 타입 정의

```typescript
/**
 * 조건 표현식 (단일 문자열 또는 AND/OR 그룹)
 */
type ConditionExpression =
  | string                              // 단순 표현식: "{{route.id}}"
  | { and: ConditionExpression[] }      // AND 그룹: 모든 조건 true → true
  | { or: ConditionExpression[] };      // OR 그룹: 하나라도 true → true
```

### 기본 문법

#### 1. 단순 문자열 (기존 if와 동일)

```json
{
  "data_sources": [
    {
      "id": "product",
      "type": "api",
      "endpoint": "/api/products/{{route.id}}",
      "conditions": "{{!!route.id}}"
    }
  ]
}
```

#### 2. AND 그룹 (모든 조건 충족)

```json
{
  "data_sources": [
    {
      "id": "product",
      "type": "api",
      "endpoint": "/api/products/{{route.id}}",
      "conditions": {
        "and": ["{{!!route.id}}", "{{_global.hasPermission('view_product')}}"]
      }
    }
  ]
}
```

- `route.id`가 있고 **동시에** `hasPermission('view_product')`가 true일 때만 fetch

#### 3. OR 그룹 (하나라도 충족)

```json
{
  "data_sources": [
    {
      "id": "admin_dashboard",
      "type": "api",
      "endpoint": "/api/admin/dashboard",
      "conditions": {
        "or": ["{{_global.user?.role === 'admin'}}", "{{_global.user?.role === 'manager'}}"]
      }
    }
  ]
}
```

- `role`이 'admin'이거나 'manager'일 때 fetch

#### 4. 중첩 AND/OR

```json
{
  "data_sources": [
    {
      "id": "sales_data",
      "type": "api",
      "endpoint": "/api/sales/report",
      "conditions": {
        "or": [
          "{{_global.user?.isSuperAdmin}}",
          {
            "and": ["{{_global.user?.isAdmin}}", "{{_global.user?.department === 'sales'}}"]
          }
        ]
      }
    }
  ]
}
```

- SuperAdmin이면 fetch **또는**
- Admin이면서 영업부서이면 fetch

### 실전 사용 예시

#### 생성/수정/복사 모드 분기

```json
{
  "data_sources": [
    {
      "id": "existing_product",
      "type": "api",
      "endpoint": "/api/products/{{route.id}}",
      "conditions": "{{!!route.id}}",
      "initLocal": "form"
    },
    {
      "id": "copy_source",
      "type": "api",
      "endpoint": "/api/products/{{query.copy_id}}",
      "conditions": {
        "and": ["{{!route.id}}", "{{!!query.copy_id}}"]
      },
      "initLocal": "form"
    },
    {
      "id": "product_template",
      "type": "api",
      "endpoint": "/api/products/template",
      "conditions": {
        "and": ["{{!route.id}}", "{{!query.copy_id}}"]
      },
      "initLocal": "form"
    }
  ]
}
```

| 시나리오 | URL 예시 | 선택되는 데이터 소스 |
|----------|---------|-------------------|
| 수정 모드 | `/products/123/edit` | `existing_product` |
| 복사 모드 | `/products/create?copy_id=456` | `copy_source` |
| 생성 모드 | `/products/create` | `product_template` |

### if vs conditions

| 속성 | 용도 | 예시 |
|------|------|------|
| `if` | 단일 조건 (간단한 경우) | `"{{route.id}}"` |
| `conditions` | 복합 조건 (AND/OR 필요) | `{ "and": [...] }` |

**우선순위**: `if`와 `conditions`가 모두 있으면 **`if`가 우선** 적용됩니다 (하위 호환성).

```json
{
  "id": "product",
  "endpoint": "/api/products/{{route.id}}",
  "if": "{{ifCondition}}",
  "conditions": "{{conditionsValue}}"
}
// → if가 우선 평가됨
```

### 선택 가이드

```text
✅ if 사용:
  - 단순 존재 여부 체크: "{{route.id}}"
  - 단순 비교: "{{query.mode === 'edit'}}"
  - 부정 조건: "{{!route.id}}"

✅ conditions 사용:
  - AND 조건 필요: 여러 조건이 모두 true여야 할 때
  - OR 조건 필요: 여러 조건 중 하나라도 true면 될 때
  - 중첩 조건: 복잡한 권한 체크, 다중 모드 분기
```

### conditions 사용 시 주의사항

```text
✅ DO: 상호 배타적인 조건 정의 (OR 분기 시)
✅ DO: 기존 if가 충분하면 conditions 사용 불필요
✅ DO: 단락 평가 활용 (AND는 첫 false에서 중단, OR는 첫 true에서 중단)

CAUTION: _local은 렌더링 전이라 사용 불가 (if와 동일)
CAUTION: 조건이 모두 false면 해당 id의 데이터 없음
CAUTION: if와 conditions 동시 사용 시 if 우선

❌ DON'T: 너무 깊은 중첩 (2-3단계 권장)
❌ DON'T: 동일 의미의 조건을 if와 conditions에 중복 정의
```

---

## 에러 처리

### 기본 동작

- API 호출 실패 시 콘솔에 에러 로그 출력
- `DataSourceManagerOptions.onError` 콜백 호출 (선택)
- **Progressive/background 전략**: 에러 발생 시에도 렌더링 계속
- **Blocking 전략**: 에러 발생 시 빈 데이터로 렌더링

### 에러 핸들링 설정 (engine-v1.6.0+)

> **관련 문서**: [액션 핸들러 - 에러 핸들링 시스템](actions-handlers.md#에러-핸들링-시스템-errorhandling)

데이터 소스에서도 액션과 동일한 방식으로 `errorHandling`과 `onError`를 설정할 수 있습니다.

#### 기본 구조

```json
{
  "data_sources": [
    {
      "id": "users",
      "endpoint": "/api/admin/users",
      "method": "GET",
      "loading_strategy": "blocking",
      "errorHandling": {
        "403": {
          "handler": "showErrorPage",
          "params": { "target": "content" }
        },
        "404": {
          "handler": "toast",
          "params": { "type": "warning", "message": "$t:errors.data_not_found" }
        }
      },
      "onError": {
        "handler": "toast",
        "params": { "type": "error", "message": "{{error.message}}" }
      }
    }
  ]
}
```

#### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `errorHandling` | object | ❌ | 에러 코드별 핸들러 매핑 (403, 404, 500, default 등) |
| `onError` | object \| array | ❌ | errorHandling에 매칭되지 않는 에러에 대한 폴백 핸들러 |

#### 처리 흐름

```text
data_source API 에러 발생 (예: 403)
     ↓
data_source errorHandling[403] 있음?
     ├── ✅ → errorHandling[403] 실행 (종료)
     ↓ ❌
data_source errorHandling[default] 있음?
     ├── ✅ → errorHandling[default] 실행 (종료)
     ↓ ❌
data_source onError 있음?
     ├── ✅ → onError 실행 (종료)
     ↓ ❌
레이아웃 errorHandling 확인
     ↓ ❌
템플릿 errorHandling 확인
     ↓ ❌
시스템 기본값 적용 (toast)
```

#### fallback과 errorHandling 동시 사용

`fallback`과 `errorHandling`이 함께 정의된 경우, **errorHandling이 먼저 실행**된 후 fallback 데이터가 렌더링에 사용됩니다.

```json
{
  "id": "user",
  "endpoint": "/api/admin/users/{{route?.id}}",
  "errorHandling": {
    "403": { "handler": "showErrorPage", "params": { "target": "content" } },
    "404": { "handler": "showErrorPage", "params": { "target": "content" } }
  },
  "fallback": { "data": null }
}
```

```text
API 에러 발생 (예: 403)
     ↓
1단계: errorHandling 실행 (showErrorPage 등)
     ↓
2단계: fallback 데이터를 렌더링에 사용
```

```text
fallback이 있어도 errorHandling은 반드시 실행됨
errorHandling 없이 fallback만 있으면 조용히 fallback 데이터 사용 (시스템 기본 에러 핸들링 적용)
```

#### 응답 조건부 에러 처리 (errorCondition, engine-v1.18.0+)

API가 200으로 응답하더라도 응답 데이터의 특정 조건에 따라 에러로 처리할 수 있습니다.
`errorCondition`이 truthy로 평가되면 지정된 `errorCode`의 `errorHandling`이 트리거됩니다.

```json
{
  "id": "user",
  "endpoint": "/api/admin/users/{{route?.id}}",
  "errorCondition": {
    "if": "{{response?.data?.abilities?.can_update === false}}",
    "errorCode": 403
  },
  "errorHandling": {
    "403": { "handler": "showErrorPage", "params": { "target": "content" } }
  },
  "fallback": { "data": null }
}
```

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `errorCondition.if` | string | ✅ | 조건 표현식 (`{{...}}` 형식). truthy일 때 에러 트리거 |
| `errorCondition.errorCode` | number | ✅ | 트리거할 에러 코드 (errorHandling 키와 매칭) |

**표현식 컨텍스트**: `response`로 API 응답 데이터에 접근 (예: `{{response?.data?.abilities?.can_update}}`)

```text
API 성공 응답 (200)
     ↓
errorCondition 평가
     ├── truthy → errorHandling[errorCode] 실행 → fallback 적용 → onSuccess 스킵
     └── falsy  → 정상 처리 (onSuccess 실행)
```

```text
errorCondition은 errorHandling과 함께 정의해야 함 (없으면 조건만 매칭되고 핸들러 미실행)
per-item abilities 기반 권한 차단 시 if 조건 + EmptyState 대신 errorCondition 사용 권장
errorCondition 표현식에서 `!== true` 대신 `=== false` 사용 권장 (abilities 미존재 시 의도치 않은 트리거 방지)
```

#### loading_strategy별 에러 처리

| loading_strategy | 에러 시 동작 | 권장 errorHandling |
|------------------|-------------|-------------------|
| `blocking` | 페이지 렌더링 전 에러 처리 | `showErrorPage` (target: content) |
| `progressive` | 렌더링 후 에러 처리 | `toast` 또는 `setState` |
| `background` | 조용히 실패 | `setState` (에러 상태 저장) |

```text
blocking + showErrorPage(target: "content") 조합 시 주의:
  - 엔진이 자동으로 데드락을 방지함 (engine-v1.18.0+)
  - blocking 데이터소스의 에러 핸들러는 비동기로 실행되고 fallback으로 즉시 blocking 해제
  - 이로 인해 showErrorPage 렌더링은 fallback 적용 후 비동기로 수행됨
  - fallback 필수: blocking + errorHandling 사용 시 반드시 fallback 정의 (미정의 시 데드락 위험)
```

#### 에러 컨텍스트 변수

| 변수 | 설명 |
|------|------|
| `{{error.status}}` | HTTP 상태 코드 |
| `{{error.message}}` | 에러 메시지 |
| `{{error.errors}}` | 필드별 에러 (422) |
| `{{error.data}}` | 전체 응답 데이터 |

### 에러 상태 접근 (engine-v1.2.0+)

데이터 소스 fetch 실패 시 `_dataSourceErrors` 객체를 통해 에러 정보에 접근할 수 있습니다.

#### 데이터 구조

```typescript
interface DataSourceError {
  message: string;    // API 에러 메시지 또는 네트워크 에러 메시지
  status?: number;    // HTTP 상태 코드 (404, 500 등)
}

// _dataSourceErrors 구조
{
  [dataSourceId: string]: DataSourceError
}
```

#### 레이아웃 JSON에서 에러 표시

```json
{
  "id": "api_error_message",
  "type": "basic",
  "name": "Div",
  "if": "{{_dataSourceErrors?.user}}",
  "props": {
    "className": "bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 mb-6"
  },
  "children": [
    {
      "type": "basic",
      "name": "P",
      "props": {
        "className": "text-sm text-red-700 dark:text-red-300"
      },
      "text": "{{_dataSourceErrors?.user?.message ?? '$t:common.error_occurred'}}"
    }
  ]
}
```

#### 사용 예시

| 표현식 | 설명 |
|--------|------|
| `{{_dataSourceErrors?.user}}` | user 데이터 소스 에러 존재 여부 (조건부 렌더링) |
| `{{_dataSourceErrors?.user?.message}}` | 에러 메시지 |
| `{{_dataSourceErrors?.user?.status}}` | HTTP 상태 코드 |

---

## 성공 콜백 (onSuccess)

> **engine-v1.17.0+**: 데이터 소스 fetch 성공 시 실행할 핸들러를 정의합니다.

### 개요

`onSuccess`는 API 요청이 성공하고 데이터가 캐시에 저장된 후 실행됩니다. 데이터 로드 완료 후 조건부 모달 표시, 추가 상태 설정, UI 업데이트 등에 활용합니다.

### 기본 구조

```json
{
  "data_sources": [
    {
      "id": "checkoutData",
      "type": "api",
      "endpoint": "/api/shop/checkout",
      "loading_strategy": "progressive",
      "onSuccess": {
        "handler": "conditions",
        "conditions": [
          {
            "if": "{{(response.data.data.unavailable_items?.length ?? 0) > 0}}",
            "then": {
              "handler": "openModal",
              "target": "unavailable_modal"
            }
          }
        ]
      }
    }
  ]
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `onSuccess` | object \| array | ❌ | 성공 시 실행할 핸들러 (단일 또는 배열) |

### 응답 컨텍스트 변수

| 변수 | 설명 |
|------|------|
| `{{response.data}}` | API 응답 데이터 (전체 - `{ success, message, data }`) |
| `{{response.data.data}}` | API 응답의 data 필드 (실제 데이터) |
| `{{response.sourceId}}` | 데이터 소스 ID |

### 사용 예시

#### 1. 조건부 모달 표시 (체크아웃 구매불가 상품)

```json
{
  "data_sources": [
    {
      "id": "checkoutData",
      "endpoint": "/api/shop/checkout",
      "loading_strategy": "progressive",
      "onSuccess": {
        "handler": "conditions",
        "conditions": [
          {
            "if": "{{(response.data.data.unavailable_items?.length ?? 0) > 0}}",
            "then": {
              "handler": "openModal",
              "target": "cart_unavailable_modal"
            }
          }
        ]
      }
    }
  ]
}
```

- API 응답의 `data.unavailable_items`가 있으면 모달 자동 표시
- **주의**: `response.data`는 전체 API 응답이므로 실제 데이터는 `response.data.data`로 접근
- `progressive` 전략으로 데이터 로드 완료 후 실행

#### 2. 성공 시 토스트 및 상태 업데이트

```json
{
  "onSuccess": [
    {
      "handler": "setState",
      "params": {
        "target": "local",
        "dataLoaded": true
      }
    },
    {
      "handler": "toast",
      "params": {
        "type": "success",
        "message": "$t:common.data_loaded"
      }
    }
  ]
}
```

#### 3. sequence 핸들러 사용

```json
{
  "onSuccess": {
    "handler": "sequence",
    "actions": [
      {
        "handler": "setState",
        "params": {
          "target": "local",
          "resultCount": "{{response.data.total}}"
        }
      },
      {
        "handler": "apiCall",
        "params": {
          "endpoint": "/api/analytics/log",
          "method": "POST",
          "body": {
            "action": "data_loaded",
            "count": "{{response.data.total}}"
          }
        }
      }
    ]
  }
}
```

### onSuccess vs initLocal

| 기능 | 용도 | 실행 시점 |
|------|------|----------|
| `initLocal` | 응답 데이터를 _local 상태에 자동 복사 | 데이터 캐시 저장 직후 |
| `onSuccess` | 조건부 로직, 모달, 토스트 등 액션 실행 | initLocal 이후 |

```text
API 응답 수신
    ↓
데이터 캐시 저장
    ↓
initLocal/initGlobal 적용
    ↓
onSuccess 핸들러 실행  ← 여기서 조건부 모달 등 처리
    ↓
UI 렌더링
```

### 주의사항

```text
✅ DO: progressive/background 전략과 함께 사용
✅ DO: conditions 핸들러로 조건부 실행
✅ DO: 데이터 기반 UI 동작 (모달, 토스트, 리다이렉트)

CAUTION: blocking 전략 시 렌더링 지연 가능
CAUTION: 무거운 작업은 background 전략 권장
CAUTION: 핸들러 실패 시 에러 로그만 출력 (데이터 로드는 정상)

❌ DON'T: onSuccess에서 동일 데이터 소스 refetch (무한 루프 위험)
❌ DON'T: 복잡한 데이터 변환 (initLocal 맵 형태 사용 권장)
```

---

## 상태 초기화 (initLocal / initGlobal / initIsolated)

API 응답 데이터를 `_local`, `_global`, 또는 `_isolated` 상태에 자동으로 복사하는 기능입니다.

### 기본 사용법

```json
{
  "data_sources": [
    {
      "id": "user",
      "type": "api",
      "endpoint": "/api/admin/users/{{route.id}}",
      "method": "GET",
      "auth_required": true,
      "loading_strategy": "progressive",
      "initLocal": "form"
    }
  ]
}
```

위 설정은 `user.data`의 내용을 `_local.form`에 자동 복사합니다.

### initGlobal 문법 (engine-v1.5.0+)

#### 1. 문자열 형태 (전체 응답 저장)

```json
{
  "id": "currentUser",
  "endpoint": "/api/me",
  "initGlobal": "currentUser"
}
// → response.data가 _global.currentUser에 저장
```

#### 2. 객체 형태 (특정 필드만 저장)

```json
{
  "id": "installed_modules",
  "endpoint": "/api/admin/modules/installed",
  "initGlobal": {
    "key": "installedModules",
    "path": "data"
  }
}
// → response.data.data가 _global.installedModules에 저장 (배열만)
```

| 속성 | 타입 | 설명 |
|------|------|------|
| `key` | string | `_global`에 저장할 키 이름 |
| `path` | string | 응답에서 추출할 경로 (점 표기법, 배열 인덱스 지원) |

#### 3. 배열 형태 (여러 전역 상태 동시 초기화) (engine-v1.6.0+)

하나의 데이터 소스에서 여러 전역 상태를 동시에 초기화할 때 사용합니다.

```json
{
  "id": "layout_files",
  "endpoint": "/api/admin/templates/{{route.identifier}}/layouts",
  "loading_strategy": "blocking",
  "initGlobal": [
    "layoutFilesList",
    {
      "key": "selectedLayoutName",
      "path": "[0].name"
    }
  ]
}
// → response.data가 _global.layoutFilesList에 저장 (전체 목록)
// → response.data[0].name이 _global.selectedLayoutName에 저장 (첫 번째 파일명)
```

**지원되는 path 형식**:

| path 예시 | 설명 |
|-----------|------|
| `"name"` | 단순 속성 |
| `"data.name"` | 중첩 속성 |
| `"[0].name"` | 배열 첫 번째 요소의 속성 |
| `"data[0].name"` | 중첩 배열 접근 |
| `"items[0][1].value"` | 다차원 배열 접근 |

**사용 사례**:

- 목록 조회 시 전체 목록과 첫 번째 항목을 동시에 상태에 저장
- 선택형 UI에서 기본 선택값 자동 설정
- 마스터-디테일 패턴에서 마스터 목록과 기본 디테일 동시 로드

### initLocal 문법 (engine-v1.5.0+)

`initLocal`도 동일한 두 가지 형태를 지원합니다:

```json
// 문자열 형태
"initLocal": "form"

// 객체 형태
"initLocal": {
  "key": "form",
  "path": "data"
}
```

### 다중 필드 매핑 (engine-v1.8.0+)

API 응답의 여러 필드를 각각 다른 `_local` 경로에 매핑할 때 사용합니다.

#### 1. dot notation 타겟 경로

타겟 키에 점(.)을 사용하여 중첩 경로에 값을 설정합니다.

```json
{
  "initLocal": {
    "checkout.item_coupons": "promotions.item_coupons",
    "checkout.order_coupon_issue_id": "promotions.order_coupon_issue_id",
    "checkout.use_points": "use_points"
  }
}
// → response.data.promotions.item_coupons가 _local.checkout.item_coupons에 저장
// → response.data.use_points가 _local.checkout.use_points에 저장
```

#### 2. 중첩 객체 표기법

가독성을 위해 중첩 객체 형태로도 작성 가능합니다. 동일한 결과를 생성합니다.

```json
{
  "initLocal": {
    "checkout": {
      "item_coupons": "promotions.item_coupons",
      "order_coupon_issue_id": "promotions.order_coupon_issue_id",
      "use_points": "use_points"
    }
  }
}
// → 위의 dot notation 예시와 동일하게 동작
```

#### 3. 병합 전략 옵션 (`_merge`)

기존 `_local` 값과의 병합 방식을 지정합니다.

```json
{
  "initLocal": {
    "_merge": "deep",
    "checkout": {
      "item_coupons": "promotions.item_coupons"
    }
  }
}
```

| `_merge` 값 | 설명 | 동작 |
|-------------|------|------|
| `"deep"` (기본값) | 깊은 병합 | 기존 `_local` 값 보존, 새 값만 덮어쓰기 (중첩 객체 재귀 병합) |
| `"shallow"` | 얕은 병합 | 최상위 키만 병합, 중첩 객체는 통째로 교체 |
| `"replace"` | 완전 교체 | 기존 `_local` 완전 무시, initLocal 매핑 결과만 남음 |

**3모드 비교 예시** (기존 `_local`: `{ checkout: { name: "기존", coupons: [] }, ui: { tab: "basic" } }`):

```text
initLocal 매핑 결과: { checkout: { coupons: [1, 2] } }

_merge: "deep"    → { checkout: { name: "기존", coupons: [1, 2] }, ui: { tab: "basic" } }
_merge: "shallow" → { checkout: { coupons: [1, 2] }, ui: { tab: "basic" } }
_merge: "replace" → { checkout: { coupons: [1, 2] } }
```

#### 소스 경로 규칙

```text
주의: 소스 경로에 "data." 접두사 사용 금지

actualData = response.data.data ?? response.data
→ actualData는 이미 API 응답의 data 필드를 가리킴

✅ 올바른 경로: "promotions.item_coupons"
❌ 잘못된 경로: "data.promotions.item_coupons"
```

#### 사용 예시: 체크아웃 쿠폰 복원

레이아웃 레벨 `initLocal`로 기본값을 설정하고, 데이터 소스 `initLocal`로 API 값을 매핑합니다.

```json
{
  "initLocal": {
    "checkout": {
      "item_coupons": {},
      "order_coupon_issue_id": null,
      "use_points": 0
    },
    "paymentMethod": "card"
  },
  "data_sources": [
    {
      "id": "checkoutData",
      "endpoint": "/api/shop/checkout",
      "loading_strategy": "progressive",
      "initLocal": {
        "_merge": "deep",
        "checkout": {
          "item_coupons": "promotions.item_coupons",
          "order_coupon_issue_id": "promotions.order_coupon_issue_id",
          "use_points": "use_points"
        }
      }
    }
  ]
}
```

**동작 흐름**:
1. 레이아웃 초기화 시 `_local.checkout`에 기본값 설정
2. API 응답 수신 후 `initLocal` 매핑 실행
3. `_merge: "deep"`으로 기존 `_local.checkout` 값 보존 + API 값 병합
4. 페이지 새로고침 시 저장된 쿠폰 선택 정보가 자동 복원됨

### initIsolated 문법 (engine-v1.14.0+)

`initIsolated`는 API 응답 데이터를 격리된 상태(`_isolated`)에 자동으로 복사합니다. `isolatedState` 속성이 정의된 컴포넌트와 함께 사용합니다.

```json
// 문자열 형태
"initIsolated": "categoryList"

// 객체 형태
"initIsolated": {
  "key": "items",
  "path": "data.list"
}
```

#### 사용 예시

```json
{
  "data_sources": [
    {
      "id": "categories",
      "type": "api",
      "endpoint": "/api/admin/categories",
      "auth_required": true,
      "loading_strategy": "blocking",
      "initIsolated": "categoryList"
    }
  ],
  "components": [
    {
      "type": "Div",
      "isolatedState": {
        "categoryList": [],
        "selectedId": null
      },
      "isolatedScopeId": "category-selector",
      "children": [
        {
          "type": "basic",
          "name": "Div",
          "iteration": { "source": "{{_isolated.categoryList}}", "item": "category" },
          "text": "{{category.name}}"
        }
      ]
    }
  ]
}
```

#### 주의사항

```text
주의: initIsolated는 isolatedState가 정의된 컴포넌트 내에서만 유효합니다.
격리 스코프가 있는 경우 → _isolated[key]에 데이터 복사
격리 스코프가 없는 경우 → 경고 로그 출력, 데이터 복사 안 됨
```

### 동작 흐름

```text
API 응답 수신 → user.data 저장 → _local.form에 복사
                                       ↓
                          컴포넌트에서 {{_local.form.name}} 접근
                                       ↓
                          사용자 수정 → _local.form 업데이트
                                       ↓
                          저장 시 {{_local.form}} 전송
```

### initLocal vs initGlobal vs initIsolated

| 옵션 | 저장 위치 | 범위 | 페이지 이동 시 | 사용 사례 |
|------|----------|------|---------------|----------|
| `initLocal` | `_local[key]` | 현재 레이아웃 | 초기화 | 폼 편집, 탭 상태 |
| `initGlobal` | `_global[key]` | 전체 애플리케이션 | 유지 | 현재 사용자 정보 |
| `initIsolated` | `_isolated[key]` | 격리된 컴포넌트 | 초기화 | 독립 UI 영역, 빈번한 인터랙션 |

### refetchDataSource와 initGlobal/initLocal

`refetchDataSource` 핸들러로 데이터 소스를 다시 fetch하면 `initGlobal`/`initLocal`도 자동으로 다시 적용됩니다.

```json
{
  "data_sources": [
    {
      "id": "current_file",
      "endpoint": "/api/files/{{_global.selectedFileId}}",
      "initGlobal": {
        "key": "editorContent",
        "path": "content"
      }
    }
  ],
  "children": [
    {
      "type": "basic",
      "name": "Div",
      "iteration": { "source": "{{files}}", "item": "file" },
      "actions": [
        {
          "type": "click",
          "handler": "sequence",
          "actions": [
            {
              "handler": "setState",
              "params": {
                "target": "global",
                "selectedFileId": "{{file.id}}"
              }
            },
            {
              "handler": "refetchDataSource",
              "params": {
                "dataSourceId": "current_file"
              }
            }
          ]
        }
      ]
    }
  ]
}
```

위 예시에서 파일 클릭 시:

1. `selectedFileId` 상태 변경
2. `current_file` 데이터 소스 refetch
3. `initGlobal`로 `_global.editorContent` 자동 갱신

```text
주의: 커스텀 컴포넌트(CodeEditor 등)와 함께 사용 시
         컴포넌트의 onChange도 상태를 갱신하므로 initGlobal과 동일한 키 사용 권장
```

### refetchOnMount 옵션 (engine-v1.3.0+)

SPA에서 같은 페이지로 재진입할 때 이전 수정 데이터가 남아있는 문제를 해결합니다.

```json
{
  "data_sources": [
    {
      "id": "board",
      "type": "api",
      "endpoint": "/api/admin/boards/{{route.id}}",
      "method": "GET",
      "auth_required": true,
      "initLocal": "formData",
      "refetchOnMount": true
    }
  ]
}
```

#### 사용 시나리오

| 시나리오 | refetchOnMount | 이유 |
|----------|----------------|------|
| 폼 편집 페이지 | `true` | 재진입 시 원본 데이터로 초기화 필요 |
| 목록 페이지 | `false` | 필터/페이지네이션 상태 유지 |
| 대시보드 | `false` | 캐시된 데이터 재사용 |

---

## WebSocket 데이터 소스

> **engine-v1.7.0+**: 실시간 데이터 업데이트를 위한 WebSocket 구독 기능입니다.
> **백엔드 문서**: [broadcasting.md](../backend/broadcasting.md)

### 개요

WebSocket 데이터 소스는 서버에서 발생하는 실시간 이벤트를 구독하여 UI를 자동으로 업데이트합니다. API 데이터 소스로 초기 데이터를 로드하고, WebSocket 데이터 소스로 실시간 변경을 반영하는 패턴을 사용합니다.

### 기본 구조

```json
{
  "data_sources": [
    {
      "id": "dashboard_resources",
      "type": "api",
      "endpoint": "/api/admin/dashboard/resources",
      "auth_required": true,
      "loading_strategy": "background"
    },
    {
      "id": "dashboard_resources_ws",
      "type": "websocket",
      "channel": "admin.dashboard",
      "event": "dashboard.resources.updated",
      "channel_type": "private",
      "target_source": "dashboard_resources"
    }
  ]
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 기본값 | 설명 |
|------|------|------|--------|------|
| `id` | string | ✅ | - | WebSocket 데이터 소스 고유 ID |
| `type` | string | ✅ | - | `websocket` 고정 |
| `channel` | string | ✅ | - | WebSocket 채널명 (예: `admin.dashboard`) |
| `event` | string | ✅ | - | 구독할 이벤트명 (예: `dashboard.resources.updated`) |
| `channel_type` | string | ❌ | `private` | 채널 타입 (`public`, `private`, `presence`) |
| `target_source` | string | ❌ | - | 이벤트 수신 시 업데이트할 대상 데이터 소스 ID |

### 채널 타입

| 타입 | 설명 | 인증 필요 | 사용 사례 |
|------|------|----------|----------|
| `public` | 모든 사용자 접근 가능 | ❌ | 공개 알림, 시스템 상태 |
| `private` | 인증된 사용자만 접근 | ✅ | 관리자 대시보드, 개인 알림 |
| `presence` | 인증 + 접속자 목록 공유 | ✅ | 채팅방, 협업 기능 |

### 동작 흐름

```text
페이지 로드
    ↓
API 데이터 소스 fetch (dashboard_resources)
    ↓
WebSocket 채널 구독 (admin.dashboard)
    ↓
이벤트 수신 (dashboard.resources.updated)
    ↓
target_source 캐시 업데이트 (dashboard_resources)
    ↓
UI 자동 재렌더링
```

### 사용 예시

#### 1. 대시보드 실시간 업데이트

```json
{
  "data_sources": [
    {
      "id": "dashboard_stats",
      "type": "api",
      "endpoint": "/api/admin/dashboard/stats",
      "auth_required": true,
      "loading_strategy": "progressive"
    },
    {
      "id": "dashboard_stats_ws",
      "type": "websocket",
      "channel": "admin.dashboard",
      "event": "dashboard.stats.updated",
      "channel_type": "private",
      "target_source": "dashboard_stats"
    },
    {
      "id": "dashboard_resources",
      "type": "api",
      "endpoint": "/api/admin/dashboard/resources",
      "auth_required": true,
      "loading_strategy": "background"
    },
    {
      "id": "dashboard_resources_ws",
      "type": "websocket",
      "channel": "admin.dashboard",
      "event": "dashboard.resources.updated",
      "channel_type": "private",
      "target_source": "dashboard_resources"
    }
  ]
}
```

#### 2. 실시간 알림

```json
{
  "data_sources": [
    {
      "id": "notifications",
      "type": "api",
      "endpoint": "/api/admin/notifications",
      "auth_required": true,
      "loading_strategy": "progressive"
    },
    {
      "id": "notifications_ws",
      "type": "websocket",
      "channel": "admin.notifications.{{_global.currentUser.id}}",
      "event": "notification.received",
      "channel_type": "private",
      "target_source": "notifications"
    }
  ]
}
```

#### 3. 공개 채널 (인증 불필요)

```json
{
  "data_sources": [
    {
      "id": "system_status_ws",
      "type": "websocket",
      "channel": "system.status",
      "event": "status.updated",
      "channel_type": "public"
    }
  ]
}
```

### target_source 동작

`target_source`가 지정되면 이벤트 수신 시 해당 ID의 데이터 소스 캐시가 업데이트됩니다.

```text
이벤트 데이터: { cpu: 25, memory: 60, disk: 45 }
target_source: "dashboard_resources"
    ↓
DataSourceManager.dataCache["dashboard_resources"] = 이벤트 데이터
    ↓
{{dashboard_resources.cpu}} → 25로 업데이트
```

`target_source`가 없으면 WebSocket 데이터 소스 자체의 ID로 데이터가 저장됩니다.

### 주의사항

```text
✅ DO: API 데이터 소스와 WebSocket 데이터 소스 함께 사용
✅ DO: target_source로 기존 API 데이터 소스와 연결
✅ DO: Private 채널 사용 시 channels.php에 인증 로직 구현

CAUTION: WebSocket 연결이 끊어지면 실시간 업데이트 중단
CAUTION: 채널 인증 실패 시 구독 실패 (403 에러)
CAUTION: 이벤트명은 백엔드 broadcastAs()와 정확히 일치해야 함

❌ DON'T: WebSocket만으로 초기 데이터 로드 시도
❌ DON'T: 민감한 데이터에 public 채널 사용
❌ DON'T: 너무 많은 채널 동시 구독 (성능 저하)
```

### 디버깅

브라우저 콘솔에서 WebSocket 관련 로그를 확인할 수 있습니다:

```text
[WebSocketManager] 구독 완료: admin.dashboard:dashboard.resources.updated (private)
[WebSocketManager] 연결 성공! Socket ID: 123456789.987654321
[DataSourceManager] WebSocket data received for: dashboard_resources
```

Network 탭 > WS 필터에서 WebSocket 메시지를 직접 확인할 수도 있습니다.

---

## 성능 최적화

1. **병렬 처리**: 동일한 loading_strategy의 데이터 소스는 Promise.all로 병렬 fetch
2. **캐싱**: DataSourceManager에 메모리 캐시 내장 (세션 유지)
3. **최소 요청**: `auto_fetch: false`로 불필요한 API 호출 방지

---

## 네이밍 규칙

```text
✅ DO: 명확하고 설명적인 ID 사용
  - admin_menu, current_user, notifications
  - user_list, product_detail, order_summary

❌ DON'T: 모호하거나 짧은 ID 사용
  - data, items, list
  - a, b, c
```

---

## 주의사항

### 필수 준수 사항

- ✅ `auth_required: true` 시 ApiClient 사용 (토큰 자동 포함)
- ✅ 동일 페이지에서 여러 데이터 소스 병렬 fetch 가능
- ✅ 데이터 소스 ID는 레이아웃 내에서 고유해야 함

### 금지 사항

- ❌ 순환 참조 금지 (A → B → A)
- ❌ 외부 URL 사용 금지 (보안)
- ❌ 동일 ID 중복 정의 금지

---

## 상태 오버라이드 파라미터 (engine-v1.17.0+)

`refetchDataSource` 핸들러 호출 시 상태를 임시로 오버라이드하여 데이터소스의 파라미터 치환에 반영할 수 있습니다. 이는 **refetch 시점에만** 적용되며 실제 상태를 변경하지 않습니다.

### 파라미터

| 파라미터 | 엔진 버전 | 타입 | 설명 |
|----------|----------|------|------|
| `globalStateOverride` | engine-v1.17.0+ | object | refetch 시 `_global` 값 임시 오버라이드 |
| `localStateOverride` | engine-v1.19.0+ | object | refetch 시 `_local` 값 임시 오버라이드 |
| `isolatedStateOverride` | engine-v1.19.0+ | object | refetch 시 `_isolated` 값 임시 오버라이드 |

### 사용 예시

```json
{
  "handler": "refetchDataSource",
  "params": {
    "dataSourceId": "products",
    "globalStateOverride": {
      "currentPage": 1,
      "sortField": "created_at"
    },
    "localStateOverride": {
      "filter": {
        "keyword": "{{_local.searchInput}}"
      }
    }
  }
}
```

### 동작 원리

```text
1. refetchDataSource 호출
2. 오버라이드 파라미터가 있으면 실제 상태와 병합 (오버라이드 우선)
3. 병합된 상태로 데이터소스 endpoint의 {{}} 표현식 치환
4. API 호출 실행
5. 오버라이드는 해당 fetch에만 적용 — 실제 상태는 변경되지 않음
```

### 주의사항

```text
오버라이드는 해당 refetch 요청에만 적용 (실제 상태 미변경)
실제 상태도 함께 변경하려면 setState를 별도로 호출해야 함
✅ sequence에서 setState → refetchDataSource 순서로 사용하면 오버라이드 불필요
✅ 주 사용 사례: 페이지네이션 리셋, 필터 변경 시 즉시 반영
```

---

## 관련 문서

- [데이터 소스 개요](data-sources.md) - 기본 구조, 로딩 전략, 파라미터 치환
- [데이터 바인딩](data-binding.md) - 데이터 바인딩 문법 및 표현식
- [상태 관리](state-management.md) - _global, _local 상태 접근
- [액션 핸들러](actions-handlers.md) - 에러 핸들링 시스템
