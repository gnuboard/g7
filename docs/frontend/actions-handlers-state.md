# 액션 핸들러 - 상태 관리

> **메인 문서**: [actions-handlers.md](actions-handlers.md)

---

## 목차

1. [apiCall](#apicall)
2. [setState](#setstate)
3. [setError](#seterror)
4. [refetchDataSource](#refetchdatasource)
5. [updateDataSource](#updatedatasource)
6. [appendDataSource](#appenddatasource)
7. [remount](#remount)
8. [sortable (드래그앤드롭 정렬)](#sortable-드래그앤드롭-정렬)
9. [onSuccess/onError 후속 액션](#onsuccessonerror-후속-액션)
10. [API 데이터 바인딩 규칙](#api-데이터-바인딩-규칙)
11. [에러 핸들링 시스템](#에러-핸들링-시스템-errorhandling)

---

## apiCall

API를 호출합니다. **주의: `api`가 아닌 `apiCall`을 사용해야 합니다.**

```json
{
  "type": "click",
  "handler": "apiCall",
  "auth_required": true,
  "target": "/api/admin/users/bulk-status",
  "params": {
    "method": "PATCH",
    "body": {
      "ids": "{{_global.selectedIds}}",
      "status": "active"
    }
  },
  "onSuccess": [
    { "handler": "closeModal" },
    { "handler": "toast", "params": { "type": "success", "message": "$t:common.success" } }
  ]
}
```

### 액션 레벨 속성

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `auth_required` | boolean | false | true이면 Bearer 토큰을 Authorization 헤더에 포함 (401 에러 발생 시 로그인 페이지 리다이렉트) |
| `auth_mode` | string | "required" | 인증 모드: `"required"` (토큰 없으면 에러), `"optional"` (토큰 있으면 포함, 없어도 진행) |

```text
중요: auth_required, auth_mode는 params 안이 아닌 액션 정의 최상위에 선언해야 합니다.
```

#### auth_mode 사용 예시

비회원도 접근 가능하지만, 로그인 시 추가 기능을 제공하는 API:

```json
{
  "handler": "apiCall",
  "target": "/api/cart",
  "auth_mode": "optional",
  "params": { "method": "GET" }
}
```

| auth_mode | 토큰 있음 | 토큰 없음 |
|-----------|----------|----------|
| `"required"` (기본) | Bearer 토큰 전송 | 에러 발생 |
| `"optional"` | Bearer 토큰 전송 | 토큰 없이 요청 진행 |

```json
// ✅ 올바른 사용
{
  "handler": "apiCall",
  "auth_required": true,
  "target": "/api/admin/users",
  "params": { "method": "POST" }
}

// ❌ 잘못된 사용 (Bearer 토큰이 전송되지 않음)
{
  "handler": "apiCall",
  "target": "/api/admin/users",
  "params": { "method": "POST", "auth_required": true }
}
```

### apiCall params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `method` | string | "GET" | HTTP 메서드 (GET, POST, PUT, PATCH, DELETE) |
| `body` | object | - | 요청 본문 (JSON 또는 FormData) |
| `headers` | object | - | 추가 헤더 |
| `contentType` | string | "application/json" | 요청 Content-Type (`"multipart/form-data"` 지정 시 FormData 자동 변환) |

### multipart/form-data 지원 (파일 업로드)

> **버전**: engine-v1.19.0+

`contentType: "multipart/form-data"`를 지정하면 `body`가 자동으로 `FormData`로 변환됩니다.
`Content-Type` 헤더는 설정하지 않으며 (브라우저가 boundary를 포함하여 자동 설정), File/Blob 객체는 원본 그대로 전송됩니다.

```json
{
  "handler": "apiCall",
  "auth_required": true,
  "target": "/api/admin/modules/manual-install",
  "params": {
    "method": "POST",
    "contentType": "multipart/form-data",
    "body": {
      "file": "{{_global.moduleUploadFile}}",
      "source": "file_upload"
    }
  }
}
```

#### FormData 변환 규칙

| body 값 타입 | FormData 처리 |
|-------------|---------------|
| `File` / `Blob` | `formData.append(key, value)` (원본 유지) |
| `string` / `number` / `boolean` | `formData.append(key, String(value))` |
| `object` / `array` | `formData.append(key, JSON.stringify(value))` |
| `null` / `undefined` | 제외 (전송하지 않음) |

#### 주의사항

```text
주의: contentType: "multipart/form-data" 시 Content-Type 헤더를 수동 설정하지 말 것
   → 브라우저가 boundary를 포함한 Content-Type을 자동 생성
주의: File/Blob 객체는 setState로 저장 시 원본 참조 유지됨 (engine-v1.19.0+)
   → deepMergeWithState가 non-plain 객체(File, Blob, Date 등)를 spread 없이 직접 할당
필수: 파일 업로드 API는 백엔드에서 multipart/form-data를 기대해야 함
```

### apiCall body에서 조건부 필드 제외 (undefined 패턴)

드라이버/모드 선택에 따라 **특정 필드만 전송**해야 하는 경우, `undefined`를 반환하는 삼항 표현식을 사용합니다. `JSON.stringify()`는 값이 `undefined`인 키를 자동으로 제거합니다.

```json
{
  "handler": "apiCall",
  "target": "/api/admin/settings/test-mail",
  "params": {
    "method": "POST",
    "body": {
      "mailer": "{{_local.form?.mail?.mailer || 'smtp'}}",
      "from_address": "{{_local.form?.mail?.from_address || ''}}",
      "host": "{{_local.form?.mail?.mailer === 'smtp' ? (_local.form?.mail?.host ?? '') : undefined}}",
      "mailgun_domain": "{{_local.form?.mail?.mailer === 'mailgun' ? (_local.form?.mail?.mailgun_domain ?? '') : undefined}}"
    }
  }
}
```

**동작 원리**:

| 조건 | 표현식 결과 | JSON 직렬화 |
|------|------------|-------------|
| mailer === 'smtp' | `host: "smtp.example.com"` | `"host":"smtp.example.com"` (포함) |
| mailer === 'mailgun' | `host: undefined` | 키 자체가 제거됨 |

**핵심 규칙**:

```text
주의: 불필요한 필드를 빈 문자열('')로 전송하지 말 것
필수: undefined 패턴으로 해당 드라이버 필드만 전송
공통 필드(from_address, from_name 등)는 조건 없이 항상 전송
주의: 모든 드라이버 필드를 항상 전송하면 서버 측 불필요한 처리 유발
```

**삼항 표현식 패턴**:

```text
현재 드라이버 필드:   조건 ? (값 ?? 기본값) : undefined
다른 드라이버 필드:   undefined (JSON에서 제거)
공통 필드:           조건 없이 항상 포함
```

---

### CSRF 토큰

`apiCall`은 자동으로 CSRF 토큰을 처리합니다:
- POST, PUT, PATCH, DELETE 요청 시 `/sanctum/csrf-cookie` 호출
- 쿠키에서 `XSRF-TOKEN`을 추출하여 `X-XSRF-TOKEN` 헤더에 포함

### globalHeaders 자동 적용

> **버전**: engine-v1.16.0+

레이아웃에 `globalHeaders`가 정의되어 있으면, `apiCall` 핸들러도 해당 패턴에 매칭되는 API에 헤더를 자동으로 포함합니다.

```json
// 레이아웃 최상위
{
  "globalHeaders": [
    { "pattern": "/api/modules/sirsoft-ecommerce/*", "headers": { "X-Cart-Key": "{{_global.cartKey}}" } }
  ]
}

// apiCall 핸들러 - X-Cart-Key 헤더 자동 포함
{
  "handler": "apiCall",
  "target": "/api/modules/sirsoft-ecommerce/cart/add",
  "params": {
    "method": "POST",
    "body": { "productId": "{{item.id}}" }
  }
}
```

**헤더 우선순위**: `params.headers` > `globalHeaders`

```json
{
  "handler": "apiCall",
  "target": "/api/modules/sirsoft-ecommerce/cart",
  "params": {
    "method": "GET",
    "headers": { "X-Cart-Key": "custom-key" }  // globalHeaders보다 우선
  }
}
```

> **상세 문서**: [layout-json.md](layout-json.md#전역-헤더-globalheaders)

---

## setState

상태를 변경합니다.

### 병합 모드 (merge 옵션)

> **버전**: engine-v1.18.0+ (`replace` 모드 추가)

setState 핸들러의 `params`에 `merge` 속성을 지정하여 상태 병합 방식을 선택할 수 있습니다.

| `merge` 값 | 동작 | 사용 시점 |
|-------------|------|----------|
| 생략 또는 `"deep"` | 재귀적 깊은 병합 (중첩 객체 보존) | 개별 필드 업데이트 (기본값) |
| `"shallow"` | 최상위 키만 덮어쓰기 (1단계) | 프리셋 적용, 필터 초기화 |
| `"replace"` | 기존 상태 완전 무시, 새 값으로 교체 | 폼 초기화, 서버 데이터로 전체 교체 |

```json
// replace 모드: 기존 _local을 완전히 payload로 교체
{
  "handler": "setState",
  "params": {
    "target": "local",
    "merge": "replace",
    "formData": { "name": "", "price": 0 }
  }
}

// shallow 모드: 최상위 키만 덮어쓰기
{
  "handler": "setState",
  "params": {
    "target": "local",
    "merge": "shallow",
    "filter": { "searchField": "all", "status": "all" }
  }
}
```

> **상세 비교**: [state-management-forms.md](state-management-forms.md#병합-모드-선택)

### 전역 상태 변경 (target: "global")

```json
{
  "type": "change",
  "handler": "setState",
  "params": {
    "target": "global",
    "selectedIds": "{{$args[0]}}",
    "searchQuery": "{{$event.target.value}}"
  }
}
```

### 로컬 상태 변경 (target: "local" 또는 생략)

```json
{
  "type": "change",
  "handler": "setState",
  "params": {
    "isExpanded": true
  }
}
```

### 격리된 상태 변경 (target: "isolated")

> **버전**: engine-v1.14.0+

`isolatedState` 속성이 정의된 컴포넌트 내에서만 동작합니다. 해당 영역의 상태 변경 시 전체 레이아웃이 아닌 격리된 영역만 리렌더링됩니다.

```json
{
  "type": "click",
  "handler": "setState",
  "params": {
    "target": "isolated",
    "selectedId": "{{item.id}}",
    "currentStep": 2
  }
}
```

#### 사용 요건

```text
주의: target: "isolated"는 isolatedState 속성이 정의된 컴포넌트 내에서만 동작합니다.
격리 스코프 외부에서 호출 시 → _local로 폴백되며 경고 로그 출력
isolatedState가 있는 컴포넌트 내에서 호출 시 → 격리된 상태만 업데이트
```

#### 레이아웃 정의 예시

```json
{
  "type": "Div",
  "isolatedState": {
    "selectedCategories": [null, null, null, null],
    "currentStep": 1
  },
  "isolatedScopeId": "category-selector",
  "children": [
    {
      "type": "basic",
      "name": "Button",
      "props": {
        "label": "다음 단계",
        "onClick": {
          "handler": "setState",
          "params": {
            "target": "isolated",
            "currentStep": "{{_isolated.currentStep + 1}}"
          }
        }
      }
    }
  ]
}
```

#### target별 비교

| target | 상태 스코프 | 리렌더링 범위 | 사용 시점 |
| ------ | ----------- | -------------- | ---------- |
| `local` (기본) | `_local` | 전체 레이아웃 | 일반 폼 데이터, 필터 |
| `global` | `_global` | 전체 앱 | 사용자 인증, 사이드바 상태 |
| `isolated` | `_isolated` | 격리된 영역만 | 빈번한 인터랙션 (카테고리 선택, 드래그) |

### 주요 사용 패턴

```json
// 체크박스 선택 ID 저장
{
  "type": "change",
  "handler": "setState",
  "params": {
    "target": "global",
    "selectedIds": "{{$args[0]}}"
  }
}

// 검색어 입력 시 상태 저장
{
  "type": "change",
  "handler": "setState",
  "params": {
    "searchQuery": "{{$event.target.value}}"
  }
}

// 토글 상태 변경
{
  "type": "click",
  "handler": "setState",
  "params": {
    "isExpanded": "{{!_local.isExpanded}}"
  }
}
```

---

## setError

에러 상태를 설정합니다. `apiError` 상태 키에 저장됩니다.

```json
{
  "handler": "setError",
  "target": "{{error.response.message}}"
}
```

### target 값

| 형식 | 설명 |
|------|------|
| `{{error.message}}` | 데이터 바인딩 (에러 객체에서 추출) |
| `$t:errors.login_failed` | 다국어 키 |
| `"로그인에 실패했습니다."` | 직접 문자열 |

---

## refetchDataSource

특정 데이터 소스를 다시 fetch합니다.

```json
{
  "handler": "refetchDataSource",
  "params": {
    "dataSourceId": "modules"
  }
}
```

### refetchDataSource params 구조

| 필드 | 타입 | 필수 | 기본값 | 설명 |
|------|------|------|--------|------|
| `dataSourceId` | string | ✅ | - | 다시 fetch할 데이터 소스 ID |
| `sync` | boolean | ❌ | false | true면 즉시 동기 렌더링 |

### sync 옵션

> **버전**: engine-v1.4.0+

`sync: true`를 사용하면 React의 `startTransition` 없이 즉시 동기적으로 렌더링합니다.

```json
{
  "handler": "refetchDataSource",
  "params": {
    "dataSourceId": "admin_menu",
    "sync": true
  }
}
```

| 케이스 | sync 필요 여부 |
|--------|---------------|
| 드래그 앤 드롭 순서 변경 | ✅ `sync: true` |
| 토글/체크박스 상태 변경 | ✅ `sync: true` |
| 일반 데이터 갱신 | ❌ 기본값 사용 |

### 상태 오버라이드 (engine-v1.17.0+)

refetch 시 상태를 임시로 오버라이드하여 데이터소스 파라미터 치환에 반영할 수 있습니다.

| 필드 | 타입 | 필수 | 엔진 버전 | 설명 |
|------|------|------|----------|------|
| `globalStateOverride` | object | ❌ | engine-v1.17.0+ | `_global` 값 임시 오버라이드 |
| `localStateOverride` | object | ❌ | engine-v1.19.0+ | `_local` 값 임시 오버라이드 |
| `isolatedStateOverride` | object | ❌ | engine-v1.19.0+ | `_isolated` 값 임시 오버라이드 |

```json
{
  "handler": "refetchDataSource",
  "params": {
    "dataSourceId": "products",
    "sync": true,
    "globalStateOverride": {
      "currentPage": 1
    },
    "localStateOverride": {
      "filter": { "status": "active" }
    }
  }
}
```

```text
오버라이드는 해당 refetch에만 적용 (실제 상태 미변경)
✅ 상세 문서: data-sources-advanced.md "상태 오버라이드 파라미터" 섹션
```

---

## updateDataSource

데이터 소스를 직접 업데이트합니다. API 응답을 사용하여 데이터 소스를 갱신할 때 사용합니다. `refetchDataSource`와 달리 추가 API 요청 없이 즉시 데이터를 업데이트합니다.

```json
{
  "handler": "apiCall",
  "target": "/api/checkout",
  "params": { "method": "PUT", "body": "{{_local.checkout}}" },
  "onSuccess": [
    {
      "handler": "updateDataSource",
      "params": {
        "dataSourceId": "checkoutData",
        "data": "{{response}}"
      }
    }
  ]
}
```

### updateDataSource params 구조

| 필드 | 타입 | 필수 | 기본값 | 설명 |
|------|------|------|--------|------|
| `dataSourceId` | string | ✅ | - | 업데이트할 데이터 소스 ID |
| `data` | any | ✅ | - | 새로운 데이터 (API 응답 등) |

### refetchDataSource vs updateDataSource

| 핸들러 | 동작 | 네트워크 요청 | 사용 시점 |
|--------|------|--------------|----------|
| `refetchDataSource` | 데이터 소스의 endpoint를 다시 호출 | ✅ 발생 | 서버에서 최신 데이터를 가져와야 할 때 |
| `updateDataSource` | 전달받은 data로 직접 교체 | ❌ 없음 | PUT/POST 응답으로 즉시 갱신할 때 |

### 사용 예시

**PUT API 응답으로 데이터 소스 갱신** (추가 GET 요청 없이):

```json
{
  "handler": "apiCall",
  "target": "/api/modules/sirsoft-ecommerce/checkout",
  "params": { "method": "PUT", "body": "{{_local.checkout}}" },
  "onSuccess": [
    {
      "handler": "updateDataSource",
      "params": {
        "dataSourceId": "checkoutData",
        "data": "{{response}}"
      }
    },
    {
      "handler": "toast",
      "params": { "type": "success", "message": "$t:common.saved" }
    }
  ]
}
```

```text
주의: onSuccess 콜백에서 response 변수를 사용할 때 $response가 아닌 response 사용
"data": "{{$response}}"  → undefined로 평가됨
"data": "{{response}}"   → 정상 동작
```

---

## appendDataSource

기존 데이터 소스에 새 데이터를 병합합니다. 무한스크롤 구현에 유용합니다.

```json
{
  "handler": "appendDataSource",
  "params": {
    "dataSourceId": "templates",
    "dataPath": "data",
    "newData": "{{response.data}}"
  }
}
```

### appendDataSource params 구조

| 필드 | 타입 | 필수 | 기본값 | 설명 |
|------|------|------|--------|------|
| `dataSourceId` | string | ✅ | - | 대상 데이터 소스 ID |
| `dataPath` | string | ❌ | null | 병합할 데이터 경로 (예: "data") |
| `newData` | array | ✅ | - | 병합할 새 데이터 배열 |

### 무한스크롤 예시

```json
{
  "type": "scroll",
  "debounce": 200,
  "handler": "sequence",
  "actions": [
    {
      "handler": "switch",
      "params": {
        "value": "{{$event.target.scrollHeight - $event.target.scrollTop <= $event.target.clientHeight + 100 && _global.infiniteScroll.hasMore && !_global.infiniteScroll.isLoadingMore}}"
      },
      "cases": {
        "true": {
          "handler": "sequence",
          "actions": [
            {
              "handler": "setState",
              "params": {
                "target": "global",
                "infiniteScroll.isLoadingMore": true
              }
            },
            {
              "handler": "apiCall",
              "auth_required": true,
              "target": "/api/items",
              "params": {
                "method": "GET",
                "query": {
                  "page": "{{_global.infiniteScroll.currentPage + 1}}",
                  "per_page": 20
                }
              },
              "onSuccess": [
                {
                  "handler": "appendDataSource",
                  "params": {
                    "dataSourceId": "items",
                    "dataPath": "data",
                    "newData": "{{response.data}}"
                  }
                },
                {
                  "handler": "setState",
                  "params": {
                    "target": "global",
                    "infiniteScroll.currentPage": "{{_global.infiniteScroll.currentPage + 1}}",
                    "infiniteScroll.hasMore": "{{(response.data?.length ?? 0) >= 20}}",
                    "infiniteScroll.isLoadingMore": false
                  }
                }
              ]
            }
          ]
        }
      }
    }
  ]
}
```

### scroll 이벤트 속성

scroll 이벤트에서 `$event.target`으로 접근 가능한 속성:

| 속성 | 타입 | 설명 |
|------|------|------|
| `scrollHeight` | number | 전체 콘텐츠 높이 |
| `scrollTop` | number | 현재 스크롤 위치 (상단 기준) |
| `clientHeight` | number | 보이는 영역 높이 |
| `scrollWidth` | number | 전체 콘텐츠 너비 |
| `scrollLeft` | number | 현재 스크롤 위치 (좌측 기준) |
| `clientWidth` | number | 보이는 영역 너비 |

---

## remount

컴포넌트를 강제로 리마운트합니다.

```json
{
  "handler": "remount",
  "params": {
    "componentId": "template_card_grid"
  }
}
```

### 사용 사례

- Toggle/Checkbox 상태 복원
- 폼 초기화
- 컴포넌트 상태 리셋

---

## sortable (드래그앤드롭 정렬)

> **버전**: engine-v1.14.0+
> **기반**: @dnd-kit/core + @dnd-kit/sortable (React 네이티브 D&D 라이브러리)

레이아웃 JSON의 `sortable` 속성을 사용하여 드래그앤드롭 정렬을 구현합니다.
HTML5 네이티브 D&D가 아닌 @dnd-kit 기반으로 동작하며, DynamicRenderer가 자동으로 SortableContainer/SortableItemWrapper를 렌더링합니다.

### sortable 속성 구조

| 필드 | 타입 | 필수 | 기본값 | 설명 |
| ---- | ---- | ---- | ------ | ---- |
| `source` | string | ✅ | - | 배열 바인딩 표현식 (예: `"{{_local.form.options}}"`) |
| `itemKey` | string | ❌ | `"id"` | 아이템 고유 키 필드명 |
| `strategy` | string | ❌ | `"verticalList"` | 정렬 전략: `verticalList` / `horizontalList` / `rectSorting` |
| `handle` | string | ❌ | - | 드래그 핸들 CSS 선택자 (예: `"[data-drag-handle]"`) |
| `itemVar` | string | ❌ | `"$item"` | 아이템 컨텍스트 변수명 |
| `indexVar` | string | ❌ | `"$index"` | 인덱스 컨텍스트 변수명 |

### 기본 사용 예시

```json
{
  "type": "basic",
  "name": "Div",
  "sortable": {
    "source": "{{_local.form.additional_options}}",
    "itemKey": "id",
    "strategy": "verticalList",
    "handle": "[data-drag-handle]"
  },
  "itemTemplate": {
    "type": "basic",
    "name": "Div",
    "props": {
      "className": "flex items-center gap-2 p-2 border rounded"
    },
    "children": [
      {
        "type": "basic",
        "name": "Div",
        "props": {
          "data-drag-handle": true,
          "className": "cursor-grab"
        },
        "children": [{ "type": "basic", "name": "Icon", "props": { "name": "fa-grip-vertical" } }]
      },
      {
        "type": "basic",
        "name": "Span",
        "props": { "textContent": "{{$item.name}}" }
      }
    ]
  },
  "actions": [
    {
      "event": "onSortEnd",
      "handler": "setState",
      "params": {
        "target": "local",
        "form.additional_options": "{{$sortedItems}}"
      }
    }
  ]
}
```

### sortable 전용 이벤트

| 이벤트 | 설명 | 컨텍스트 변수 |
| ------ | ---- | ------------- |
| `onSortEnd` | 정렬 완료 시 | `$sortedItems` (정렬된 배열), `$oldIndex`, `$newIndex` |
| `onSortStart` | 드래그 시작 시 | `$activeId` (드래그 중인 아이템 ID) |

### 드래그 핸들

`handle` 속성을 지정하면 해당 CSS 선택자를 가진 요소만 드래그 가능합니다.
`data-drag-handle` 속성이 있는 요소에 DynamicRenderer가 자동으로 드래그 리스너를 바인딩합니다.

```json
{
  "sortable": {
    "source": "{{_local.form.items}}",
    "handle": "[data-drag-handle]"
  }
}
```

핸들을 지정하지 않으면 아이템 전체가 드래그 가능합니다.

### 주의 사항

```text
sortable은 iteration보다 우선 처리됨 (같은 컴포넌트에 둘 다 있으면 sortable만 동작)
sortable 아이템 내부에서는 폼 자동 바인딩(auto-binding)이 비활성화됨
   → 인덱스 기반 경로가 정렬 후 stale 값을 참조하는 문제 방지
   → 핸들러(setState 등)에서 직접 상태를 관리해야 함
onSortEnd에서 setState로 소스 배열을 업데이트해야 정렬 결과가 반영됨
아이템에 고유한 id 필드가 필수 (itemKey로 지정)
HTML5 네이티브 D&D(dragstart/dragover/drop 이벤트)는 sortable에 사용하지 않음
   → @dnd-kit이 PointerSensor/KeyboardSensor로 자체 처리
```

---

## onSuccess/onError 후속 액션

API 호출 등의 액션 후에 후속 액션을 실행할 수 있습니다.

### 배열 지원

`onSuccess`와 `onError`는 **단일 액션 또는 배열**을 지원합니다.

```json
{
  "type": "click",
  "handler": "apiCall",
  "target": "/api/admin/users/bulk-status",
  "params": {
    "method": "PATCH",
    "body": {
      "ids": "{{_global.selectedIds}}",
      "status": "active"
    }
  },
  "onSuccess": [
    { "handler": "closeModal" },
    { "handler": "setState", "params": { "target": "global", "selectedIds": [] } },
    { "handler": "navigate", "params": { "path": "/admin/users", "mergeQuery": true, "query": {} } },
    { "handler": "toast", "params": { "type": "success", "message": "$t:admin.users.modals.bulk_activate_success" } }
  ]
}
```

### onError에서 접근 가능한 데이터

| 바인딩 | 설명 |
|--------|------|
| `{{error.message}}` | 에러 메시지 (번역됨) |
| `{{error.status}}` | HTTP 상태 코드 |
| `{{error.data}}` | API 응답 전체 (success, message, errors 포함) |
| `{{error.data.errors}}` | errors 객체 (추가 에러 정보) |

---

## API 데이터 바인딩 규칙

```text
API 응답 데이터 구조를 절대 추측하지 않음

1. 새로운 API 연동 시 → 실제 API 응답 구조를 컨트롤러 코드에서 확인
2. onSuccess/onError 콜백 → 정확한 컨텍스트 변수 경로 확인 필수
3. 불확실한 경우 → 기존 레이아웃의 유사 패턴 참조
```

### 그누보드7 API 응답 표준 구조

ResponseHelper를 사용하는 모든 API는 다음 구조를 따릅니다:

**성공 응답 (success)**:
```json
{
  "success": true,
  "message": "번역된 메시지",
  "data": { ... }
}
```

**에러 응답 (error)**:
```json
{
  "success": false,
  "message": "번역된 에러 메시지",
  "errors": { ... }
}
```

### 콜백별 컨텍스트 변수 경로

```text
주의: onSuccess/onError 콜백에서 `$response` 사용 금지 — `response` 사용
올바른 변수명: `response` ($ 접두사 없음)
`$response`는 ActionDispatcher 컨텍스트에 존재하지 않는 변수 → undefined 반환
   → preprocessOptionalChaining이 `$response?.data?.xxx`로 변환 → undefined (에러 없이 조용히 실패)
   → fallback 값이 있으면 항상 fallback만 표시되어 버그가 은폐됨
```

| 콜백 | 변수 | 실제 경로 | 설명 |
|------|------|----------|------|
| onSuccess | `response` | - | 전체 API 응답 (권장) |
| onSuccess | `response.data` | - | 성공 시 data 필드 |
| onSuccess | `result` | - | `response`와 동일 (하위 호환성) |
| onError | `error.message` | - | 번역된 에러 메시지 |
| onError | `error.status` | - | HTTP 상태 코드 |
| onError | `error.data` | - | 전체 API 응답 |
| onError | `error.data.errors` | - | errors 객체 |
| onError | `error.data.errors.필드명` | - | 특정 에러 필드 |

### API 데이터 바인딩 체크리스트

```
□ API 응답 구조를 실제로 확인했는가? (추측 금지)
□ ResponseHelper의 success/error 반환 구조를 이해했는가?
□ 중첩된 객체 경로가 정확한가? (예: error.data.errors.field)
□ 배열 vs 객체 구분이 명확한가?
□ Optional Chaining(?.)을 적절히 사용했는가?
```

### 자주 하는 실수

```json
// ❌ 잘못된 예: 응답 구조 추측
"deactivateWarningData": "{{error.response}}"
"deactivateWarningData": "{{error.dependent_templates}}"

// ✅ 올바른 예: 실제 구조 확인 후 사용
"deactivateWarningData": "{{error.data}}"
// 모달에서: {{_global.deactivateWarningData.errors.dependent_templates}}
```

---

## 에러 핸들링 시스템 (errorHandling)

> **버전**: engine-v1.6.0+

HTTP 에러 코드별로 다른 처리 로직을 정의할 수 있습니다.

### errorHandling vs onError 역할 분리

| 속성 | 역할 | 실행 조건 |
|------|------|----------|
| `errorHandling` | 특정 에러 코드별 처리 | 해당 코드 또는 default가 정의된 경우 |
| `onError` | 범용 에러 처리 (폴백) | errorHandling에 해당 코드가 없을 때 |

### 처리 흐름

```text
API 에러 발생 (예: 403)
     ↓
errorHandling[403] 있음?
     ├── ✅ → errorHandling[403] 실행 (종료)
     ↓ ❌
errorHandling[default] 있음?
     ├── ✅ → errorHandling[default] 실행 (종료)
     ↓ ❌
onError 있음?
     ├── ✅ → onError 실행 (종료)
     ↓ ❌
상위 레벨 errorHandling 확인
```

### 기본 사용법

```json
{
  "type": "click",
  "handler": "apiCall",
  "auth_required": true,
  "target": "/api/admin/users/{{id}}",
  "params": { "method": "DELETE" },
  "errorHandling": {
    "403": {
      "handler": "openModal",
      "target": "permission_denied_modal"
    },
    "404": {
      "handler": "toast",
      "params": { "type": "warning", "message": "$t:errors.user_not_found" }
    }
  },
  "onError": {
    "handler": "toast",
    "params": { "type": "error", "message": "{{error.message}}" }
  }
}
```

---

## 관련 문서

- [액션 핸들러 인덱스](actions-handlers.md)
- [네비게이션 핸들러](actions-handlers-navigation.md)
- [UI 핸들러](actions-handlers-ui.md)
- [상태 관리](state-management.md)
- [데이터 소스](data-sources.md)
