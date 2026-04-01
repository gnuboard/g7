# 데이터 바인딩 및 표현식

> **관련 문서**: [index.md](index.md) | [layout-json.md](layout-json.md) | [data-sources.md](data-sources.md)

---

## TL;DR (5초 요약)

```text
1. API 데이터: {{user.name}}, URL 파라미터: {{route.id}}
2. 상태 접근: {{_global.xxx}}, {{_local.xxx}}, {{_isolated.xxx}}
3. Optional Chaining: {{user?.profile?.name ?? '기본값'}}
4. 파이프 함수: {{created_at | date}}, {{name | truncate(50)}}
5. $get 헬퍼: {{$get(obj, ['key1', 'key2'], fallback)}}
```

---

## 분리된 문서

이 문서는 가독성을 위해 다음과 같이 분리되었습니다:

| 문서 | 내용 |
|------|------|
| **data-binding.md** (현재) | 기본 문법, 데이터 바인딩 표현식, Optional Chaining, 반복 컨텍스트 |
| [data-binding-i18n.md](data-binding-i18n.md) | $localized() 헬퍼, 다국어 처리, 지연 번역, 모듈/플러그인 다국어 병합, 컴포넌트 다국어 처리 |
| [rawMarkers.ts](../../resources/js/core/template-engine/rawMarkers.ts) | `raw:` 마커 — 번역 면제 바인딩 (`{{raw:expression}}`) |

---

## 목차

1. [기본 문법](#기본-문법)
2. [데이터 바인딩 표현식 (engine-v1.1.0+)](#데이터-바인딩-표현식-v110)
3. [Optional Chaining & Nullish Coalescing](#optional-chaining--nullish-coalescing-v110)
4. [파이프 함수 (engine-v1.9.0+)](#파이프-함수-v190)
5. [$get 헬퍼 함수 (engine-v1.9.0+)](#get-헬퍼-함수-v190)
6. [$switch 표현식 (engine-v1.9.0+)](#switch-표현식-v190)
7. [반복 컨텍스트에서의 표현식](#반복-컨텍스트에서의-표현식-v120)
8. [text 속성 복합 바인딩](#text-속성-복합-바인딩-v120)

---

## 기본 문법

레이아웃 JSON에서 동적 데이터를 참조할 때 사용하는 문법입니다.

### 데이터 바인딩 유형

| 문법 | 설명 | 예시 |
|------|------|------|
| `{{data.field}}` | API 데이터 바인딩 | `{{user.name}}` |
| `{{route.param}}` | URL 파라미터 | `/users/:id`의 `{{route.id}}` |
| `{{query.key}}` | 쿼리 스트링 | `?category=electronics`의 `{{query.category}}` |
| `{{state.value}}` | 컴포넌트 상태 | `{{state.isOpen}}` |
| `{{_global.property}}` | 전역 상태 (engine-v1.1.0+) | `{{_global.sidebarOpen}}` |
| `{{_local.property}}` | 로컬 상태 | `{{_local.formData}}` |
| `{{_isolated.property}}` | 격리된 상태 (engine-v1.14.0+) | `{{_isolated.selectedItems}}` |
| `{{raw:expression}}` | 번역 면제 바인딩 (engine-v1.27.0+) | `{{raw:item.jsonData}}` |

### 기본 예시

```json
{
  "props": {
    "title": "{{user.name}}",
    "email": "{{user.email}}",
    "userId": "{{route.id}}",
    "category": "{{query.category_id}}",
    "sidebarOpen": "{{_global.sidebarOpen}}",
    "formData": "{{_local.form}}",
    "selectedCount": "{{_isolated.selectedItems?.length ?? 0}}"
  }
}
```

### 격리된 상태 바인딩 (engine-v1.14.0+)

`_isolated` 상태는 `isolatedState` 속성이 정의된 컴포넌트 내에서만 접근 가능합니다.

```json
{
  "type": "Div",
  "isolatedState": { "selectedId": null, "items": [] },
  "children": [
    {
      "type": "basic",
      "name": "Span",
      "text": "선택됨: {{_isolated.selectedId ?? '없음'}}"
    },
    {
      "type": "basic",
      "name": "Div",
      "iteration": { "source": "{{_isolated.items}}", "item": "item" },
      "props": {
        "className": "{{_isolated.selectedId === item.id ? 'bg-blue-100' : 'bg-white'}}"
      }
    }
  ]
}
```

---

## 데이터 바인딩 표현식 (engine-v1.1.0+)

DataBindingEngine은 `{{}}` 내부에서 다양한 표현식을 지원합니다.

### 1. 변수 참조

```json
{
  "text": "{{user.name}}",
  "count": "{{products.total}}"
}
```

### 2. 삼항 연산자

```json
{
  "className": "{{isActive ? 'bg-blue-500' : 'bg-gray-500'}}",
  "text": "{{count > 0 ? count + ' items' : 'No items'}}",
  "props": {
    "variant": "{{user.isPremium ? 'premium' : 'standard'}}"
  }
}
```

### 3. 논리 연산자

```json
{
  // AND 연산자
  "if": "{{isAdmin && hasPermission}}",

  // OR 연산자
  "className": "{{isLoggedIn || isGuest ? 'visible' : 'hidden'}}",

  // NOT 연산자
  "text": "{{!isEmpty ? 'Has data' : 'Empty'}}"
}
```

### 4. 비교 연산자

```json
{
  "if": "{{count >= 10}}",
  "className": "{{price > 100 ? 'expensive' : 'affordable'}}",
  "text": "{{status == 'active' ? 'Active' : 'Inactive'}}",
  "disabled": "{{stock <= 0}}"
}
```

**지원되는 비교 연산자**:

| 연산자 | 설명 |
|--------|------|
| `==` | 같음 |
| `!=` | 다름 |
| `>` | 크다 |
| `<` | 작다 |
| `>=` | 크거나 같다 |
| `<=` | 작거나 같다 |

### 5. 리터럴 값

```json
{
  // 문자열 리터럴
  "className": "{{isOpen ? 'translate-x-0' : '-translate-x-full'}}",

  // 숫자 리터럴
  "count": "{{count || 0}}",

  // boolean 리터럴
  "enabled": "{{status == 'ready' ? true : false}}",

  // null 리터럴
  "value": "{{data || null}}"
}
```

### 6. 복합 표현식

```json
{
  // 동적 CSS 클래스 생성
  "className": "{{_global.sidebarOpen ? 'translate-x-0' : '-translate-x-full'}} fixed inset-y-0 left-0 z-50 w-64 transition-transform lg:relative lg:translate-x-0",

  // 조건부 텍스트
  "text": "{{user.isOnline && user.status == 'active' ? 'Online' : 'Offline'}}",

  // 중첩 삼항 연산자
  "variant": "{{score >= 90 ? 'excellent' : score >= 70 ? 'good' : 'poor'}}"
}
```

### 사용 가능 위치

- `props.*`: 모든 props 속성
- `className`: 동적 CSS 클래스
- `text`: 텍스트 내용
- `if`: 조건부 렌더링
- `actions[].payload.*`: 액션 payload

---

## Optional Chaining & Nullish Coalescing (engine-v1.1.0+)

데이터 로드 전/후 안전한 접근을 위해 Optional Chaining(`?.`)과 Nullish Coalescing(`??`)을 지원합니다.

```json
{
  // Optional Chaining - null/undefined 안전 접근
  // 데이터가 없어도 에러 발생 안 함
  "text": "{{users?.data?.pagination?.total}}",

  // Nullish Coalescing - 기본값 설정
  // null 또는 undefined인 경우 기본값 반환
  "count": "{{users?.data?.total ?? 0}}",

  // 복합 사용 - 안전한 접근 + 기본값
  "info": "{{products?.items?.length ?? 0}} items"
}
```

### 사용 사례 - API 데이터 로드 전후 처리

```json
{
  // 데이터 로드 전: users가 undefined → 0 표시
  // 데이터 로드 후: users.data.pagination.total 값 표시
  "text": "$t:admin.users.pagination_info|total={{users?.data?.pagination?.total ?? 0}}|from={{users?.data?.pagination?.from ?? 0}}|to={{users?.data?.pagination?.to ?? 0}}"
}
```

### 동작 원리

- 데이터 소스가 로드되기 전: 변수가 `undefined`로 처리되어 `??` 이후의 기본값 반환
- 데이터 소스가 로드된 후: 실제 값으로 자동 업데이트 (리렌더링)

### 주의사항

```text
✅ API 데이터 접근 시 항상 `?.`와 `??` 함께 사용 권장
✅ 초기 렌더링 시 에러 방지를 위해 기본값 필수
✅ 간결한 표현식 사용 권장 (한 줄)
✅ 복잡한 로직은 API에서 처리 후 결과만 바인딩
깊은 중첩 경로에서도 안전하게 작동
에러 발생 시 빈 문자열 또는 기본값 반환
과도한 중첩은 가독성 저하
```

### 실제 사용 사례 - 반응형 레이아웃

```json
{
  "id": "mobile_sidebar",
  "type": "basic",
  "name": "Div",
  "props": {
    "className": "{{_global.sidebarOpen ? 'translate-x-0' : '-translate-x-full'}} fixed inset-y-0 left-0 z-50 w-64 transition-transform duration-300 lg:relative lg:translate-x-0 bg-white"
  }
}
```

---

## 파이프 함수 (engine-v1.9.0+)

파이프 함수를 사용하면 데이터를 체이닝 방식으로 변환할 수 있습니다.

### 기본 문법

```json
{
  "text": "{{created_at | date}}",
  "text": "{{product.name | truncate(50)}}",
  "text": "{{description | stripHtml | truncate(100)}}"
}
```

### 내장 파이프 함수

| 파이프 | 설명 | 예시 |
|--------|------|------|
| `default(value)` | null/undefined/빈값 시 대체 | `{{name \| default('Unknown')}}` |
| `fallback(value)` | null/undefined 시 대체 | `{{data \| fallback([])}}` |
| `date(format)` | 날짜 포맷 | `{{created_at \| date('YYYY-MM-DD')}}` |
| `datetime(format)` | 날짜시간 포맷 | `{{created_at \| datetime}}` |
| `relativeTime` | 상대 시간 | `{{created_at \| relativeTime}}` → "3일 전" |
| `number(decimals)` | 숫자 포맷 | `{{price \| number(2)}}` |
| `truncate(len, suffix)` | 문자열 자르기 | `{{text \| truncate(100, '...')}}` |
| `stripHtml` | HTML 태그 제거 | `{{content \| stripHtml}}` |
| `json` | JSON 문자열 변환 | `{{object \| json}}` |
| `keys` | 객체 키 배열 | `{{object \| keys}}` |
| `values` | 객체 값 배열 | `{{object \| values}}` |
| `first` | 배열 첫 요소 | `{{items \| first}}` |
| `last` | 배열 마지막 요소 | `{{items \| last}}` |
| `join(separator)` | 배열 문자열 결합 | `{{tags \| join(', ')}}` |
| `localized` | 다국어 객체 → 현재 로케일 값 | `{{title \| localized}}` |

### 파이프 체이닝

여러 파이프를 연결하여 순차적으로 변환할 수 있습니다:

```json
{
  "text": "{{content | stripHtml | truncate(100) | default('내용 없음')}}"
}
```

### 주의사항

```text
✅ 파이프는 순수 함수로 동작 (side-effect 없음)
✅ 단일 | = 파이프, || = OR 연산자 (혼동 주의)
✅ 파이프 인자는 괄호 안에 작성
배열 파이프(first, last, join)는 배열 입력 필수
잘못된 입력 시 fallback 값 반환
```

---

## $get 헬퍼 함수 (engine-v1.9.0+)

동적 키를 포함한 깊은 객체 경로 접근과 폴백 값을 지원합니다.

### 기본 문법

```json
{
  "text": "{{$get(object, path, fallback)}}"
}
```

- `object`: 접근할 객체
- `path`: 문자열 또는 문자열 배열 형태의 경로
- `fallback`: 값이 없을 때 반환할 기본값 (선택)

### 사용 예시

**다중 통화 가격 접근**:

```json
{
  "text": "{{$get(product.multi_currency_price, [currency, 'formatted'], product.price_formatted)}}"
}
```

**다국어 객체 접근**:

```json
{
  "text": "{{$get(product.name, _global.locale, product.name.ko)}}"
}
```

**중첩 설정 접근**:

```json
{
  "value": "{{$get(_global.settings, ['drivers', 'mail', 'host'], 'localhost')}}"
}
```

**동적 키 접근**:

```json
{
  "price": "{{$get(prices, [selectedPlan, 'monthly'], 0)}}"
}
```

### 비교: 기존 방식 vs $get

**기존 (복잡)**:

```json
{
  "text": "{{item.multi_currency_unit_price[_global.preferredCurrency ?? 'KRW']?.formatted ?? item.unit_price_formatted}}"
}
```

**개선 (단순)**:

```json
{
  "text": "{{$get(item.multi_currency_unit_price, [_global.preferredCurrency ?? 'KRW', 'formatted'], item.unit_price_formatted)}}"
}
```

### 주의사항

```text
✅ 순수 함수로 동작 (입력 객체 변경 없음)
✅ null/undefined 경로에서 안전하게 폴백 반환
✅ 배열 형태 경로로 동적 키 접근 가능
경로가 빈 배열이면 원본 객체 반환
```

---

## $switch 표현식 (engine-v1.9.0+)

다단계 중첩 삼항 연산자를 선언적 switch-case 패턴으로 대체합니다.

### 기본 문법

```json
{
  "icon": {
    "$switch": "{{tab}}",
    "$cases": {
      "products": "shopping-bag",
      "contents": "book-open",
      "policies": "file-check"
    },
    "$default": "file-text"
  }
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `$switch` | string | ✅ | 평가할 표현식 |
| `$cases` | object | ✅ | 키-값 매핑 (키가 일치하면 해당 값 반환) |
| `$default` | any | ❌ | 일치하는 케이스가 없을 때 기본값 |

### 비교: 기존 방식 vs $switch

**기존 (복잡)**:

```json
{
  "icon": "{{tab === 'products' ? 'shopping-bag' : tab === 'contents' ? 'book-open' : tab === 'policies' ? 'file-check' : 'file-text'}}"
}
```

**개선 (단순)**:

```json
{
  "icon": {
    "$switch": "{{tab}}",
    "$cases": {
      "products": "shopping-bag",
      "contents": "book-open",
      "policies": "file-check"
    },
    "$default": "file-text"
  }
}
```

### 사용 위치

- 모든 props 속성
- `text` 속성
- `className` 속성
- computed 속성 (레이아웃 수준)

### 주의사항

```text
✅ $cases 키와 $switch 결과가 정확히 일치해야 함 (===)
✅ $default가 없고 일치하는 케이스가 없으면 undefined 반환
✅ iteration/cellChildren 컨텍스트에서 각 아이템별 독립 평가
$cases 값에 추가 표현식 바인딩 불가 (정적 값만)
```

---

## 반복 컨텍스트에서의 표현식 (engine-v1.2.0+)

DataGrid의 `cellChildren`, 리스트 아이템 등 **반복 렌더링 컨텍스트**에서도 복잡한 표현식을 사용할 수 있습니다.

```json
{
  "columns": [
    {
      "key": "status",
      "label": "$t:user.status",
      "cellChildren": [
        {
          "type": "basic",
          "name": "Span",
          "props": {
            "className": "{{row.status_variant === 'success' ? 'bg-green-100 text-green-800' : row.status_variant === 'danger' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800'}} px-2.5 py-0.5 rounded-full text-xs font-medium"
          },
          "text": "{{row.status_label}}"
        }
      ]
    }
  ]
}
```

### 지원 기능

| 기능 | 설명 | 예시 |
|------|------|------|
| 삼항 연산자 | 조건부 값 선택 | `{{row.active ? 'active' : 'inactive'}}` |
| 중첩 삼항 | 다중 조건 처리 | `{{a ? 'x' : b ? 'y' : 'z'}}` |
| 논리 연산자 | AND/OR 조건 | `{{row.class \|\| 'default'}}` |
| 문자열 리터럴 | 따옴표 내 특수문자 | `{{active ? 'px-2.5' : 'px-1'}}` |

### 반복 컨텍스트 변수

- `row`: 현재 행 데이터 (DataGrid)
- `value` / `$value`: 현재 셀 값
- `item`: 현재 리스트 아이템 (ul/li 패턴)
- `index`: 현재 인덱스

### 내부 동작

템플릿 엔진은 반복 렌더링 시 `G7Core.renderItemChildren` 헬퍼를 사용하여 각 아이템마다 독립적으로 표현식을 평가합니다. 캐싱으로 인한 데이터 중복 문제를 방지하기 위해 `skipCache` 옵션이 자동으로 적용됩니다.

---

## text 속성 복합 바인딩 (engine-v1.2.0+)

`text` 속성에서 데이터 바인딩과 다국어(`$t:`)를 조합한 복합 표현식을 사용할 수 있습니다.

```json
{
  "id": "error_message",
  "type": "basic",
  "name": "P",
  "text": "{{_global.errorMessage || $t:common.error_occurred}}"
}
```

### 동작 방식

1. `_global.errorMessage`가 설정되어 있으면 해당 값 표시
2. 설정되지 않으면 `$t:common.error_occurred` 번역 키의 값 표시

### 삼항 연산자와 $t: 조합

```json
{
  "text": "{{isError ? $t:common.error : $t:common.success}}"
}
```

### 주요 특징

```text
✅ $t: 토큰은 따옴표로 감싸지 않아도 자동으로 문자열로 처리됨
✅ 표현식 평가 후 $t: 결과가 자동으로 번역됨
✅ ||, &&, ? : 등 모든 JavaScript 표현식 사용 가능
```

---

## 컨텍스트 변수 레퍼런스 (engine-v1.11.0+)

레이아웃 JSON 표현식에서 사용 가능한 **특수 컨텍스트 변수** 목록입니다. 각 변수는 특정 스코프에서만 접근 가능합니다.

### 상태 접근 변수

| 변수 | 설명 | 사용 범위 |
|------|------|----------|
| `_global` | 전역 상태 (앱 전체 공유, 페이지 이동 시 유지) | 어디서나 |
| `_local` | 로컬 상태 (현재 레이아웃 내) | 어디서나 |
| `_isolated` | 격리된 상태 (`isolatedState` 컴포넌트 내) | isolatedState 영역 |
| `_computed` | 계산된 속성 (computed 정의 기반) | 어디서나 |

### 이벤트/콜백 변수

| 변수 | 설명 | 사용 범위 |
|------|------|----------|
| `$event` | DOM 이벤트 객체 (`type` 필드 사용 시) | 액션 핸들러 params |
| `$args` | 커스텀 콜백 인자 배열 (`event` 필드 사용 시) | 액션 핸들러 params |
| `$eventData` | 컴포넌트 이벤트 데이터 (engine-v1.11.0+) | onComponentEvent 핸들러 |

### 계층 접근 변수 (engine-v1.16.0+)

| 변수 | 설명 | 사용 범위 |
|------|------|----------|
| `$parent` | 부모 컴포넌트의 상태 접근 | 모달, expandChildren |
| `$root` | 루트 컴포넌트의 상태 접근 | 중첩 레이아웃 |

```json
// 모달에서 부모 _local 접근
{ "text": "{{$parent._local.selectedUser?.name}}" }

// expandChildren에서 부모 전역 상태 접근
{ "value": "{{$parent._global.currency}}" }
```

```text
$parent._local은 모달 마운트 시 스냅샷 — 모달 열린 후 부모 _local 변경 시 반영 안됨
✅ 리액티브 데이터 필요 시: setState로 모달 자체 _local에 저장 후 사용
✅ setState target: "$parent._local" 또는 "$parent._global"로 부모 상태 직접 수정 가능
```

### 반복/정렬 컨텍스트 변수

| 변수 | 설명 | 사용 범위 |
|------|------|----------|
| `row` | 현재 행 데이터 | DataGrid cellChildren, subRowChildren |
| `item` / `$item` | 현재 반복 아이템 | iteration, sortable itemTemplate |
| `index` / `$index` | 현재 반복 인덱스 | iteration, sortable itemTemplate |
| `$value` | 현재 셀 값 | DataGrid cellChildren |
| `$sortedItems` | 정렬 완료 후 재배열된 배열 (engine-v1.14.0+) | sortable onSortEnd 이벤트 |
| `$oldIndex` / `$newIndex` | 정렬 전/후 인덱스 (engine-v1.14.0+) | sortable onSortEnd 이벤트 |
| `$activeId` | 드래그 중인 아이템 ID (engine-v1.14.0+) | sortable onSortStart 이벤트 |

### sequence 핸들러 컨텍스트 변수

| 변수 | 설명 | 사용 범위 |
|------|------|----------|
| `$prev` | 직전 액션의 결과값 (직접 참조) | sequence 내부 액션 |
| `$results` | 모든 이전 액션 결과 배열 | sequence 내부 액션 |

```json
{
  "handler": "sequence",
  "actions": [
    {
      "handler": "apiCall",
      "target": "/api/products/{{row.id}}",
      "params": { "method": "GET" }
    },
    {
      "handler": "setState",
      "params": {
        "target": "local",
        "productDetail": "{{$prev.data}}"
      }
    }
  ]
}
```

### onSuccess/onError 콜백 변수

| 변수 | 설명 | 사용 범위 |
|------|------|----------|
| `response` | API 성공 응답 전체 | onSuccess 콜백 |
| `response.data` | API 응답 data 필드 | onSuccess 콜백 |
| `result` | `response`와 동일 (하위 호환) | onSuccess 콜백 |
| `error.message` | 번역된 에러 메시지 | onError 콜백 |
| `error.status` | HTTP 상태 코드 | onError 콜백 |
| `error.errors` | 필드별 에러 (422) | onError 콜백 |
| `error.data` | 전체 API 응답 | onError 콜백 |

```text
필수: response 사용 ($response 사용 금지 — $ 접두사 없음)
❌ "{{$response.data}}" → undefined
✅ "{{response.data}}" → 정상 동작
```

### URL/라우트 변수

| 변수 | 설명 | 사용 범위 |
|------|------|----------|
| `route` | URL 경로 파라미터 (예: `/users/:id` → `route.id`) | 어디서나 |
| `query` | 쿼리 스트링 파라미터 (예: `?page=1` → `query.page`) | 어디서나 |

---

## 관련 문서

- [다국어 처리](data-binding-i18n.md) - $localized(), $t:, 컴포넌트 다국어 처리
- [g7core-api.md](g7core-api.md) - G7Core 전역 API 레퍼런스
- [index.md](index.md) - 프론트엔드 가이드 인덱스
- [layout-json.md](layout-json.md) - 레이아웃 JSON 스키마
- [data-sources.md](data-sources.md) - 데이터 소스 시스템
- [state-management.md](state-management.md) - 전역 상태 관리
