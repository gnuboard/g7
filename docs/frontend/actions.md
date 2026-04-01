# 액션 핸들러 가이드

> **관련 문서**: [레이아웃 JSON](layout-json.md) | [데이터 바인딩](data-binding.md) | [상태 관리](state-management.md)

---

## TL;DR (5초 요약)

```text
1. 구조: type 또는 event(이벤트), handler(핸들러명), params(옵션)
2. type: 표준 DOM 이벤트 (click, change) → $event.target.value
3. event: 커스텀 콜백 (CodeEditor, DataGrid) → $args[0]
4. 핸들러명 정확히: apiCall (❌ api), navigate (❌ goto)
5. 조합: sequence(순차), parallel(병렬), onSuccess/onError
```

---

## 분리된 문서

이 문서는 가독성을 위해 다음과 같이 분리되었습니다:

| 문서 | 내용 |
|------|------|
| **actions.md** (현재) | 개요, 액션 정의 구조, 내장 핸들러 목록, 주의사항 |
| [actions-handlers.md](actions-handlers.md) | 핸들러별 상세 사용법, onSuccess/onError, errorHandling, 실전 예시 |
| [actions-g7core-api.md](actions-g7core-api.md) | G7Core.dispatch, 편의 API, 이벤트 헬퍼 |

---

## 목차

1. [개요](#개요)
2. [액션 정의 구조](#액션-정의-구조)
3. [내장 핸들러 목록](#내장-핸들러-목록)
4. [주의사항](#주의사항)

---

## 개요

**액션 핸들러**는 레이아웃 JSON에서 사용자 인터랙션(클릭, 폼 제출 등)을 처리하는 시스템입니다.

### 핵심 원칙

```text
중요: 핸들러 이름은 ActionDispatcher에 정의된 이름과 정확히 일치해야 함
✅ 필수: apiCall (❌ api 아님)
✅ 필수: navigate, setState, openModal, closeModal, toast 등
```

---

## 액션 정의 구조

```json
{
  "actions": [
    {
      "type": "click",
      "handler": "navigate",
      "target": "/path",
      "params": {
        "key": "value"
      },
      "onSuccess": [],
      "onError": [],
      "confirm": "확인 메시지",
      "key": "Enter"
    }
  ]
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `type` | string | | 표준 DOM 이벤트 타입 (click, change, submit 등). `event` 필드와 상호 배타적 |
| `event` | string | | 커스텀 콜백 이벤트명 (onSortChange, onChange 등). `type` 필드와 상호 배타적 |
| `handler` | string | ✅ | 액션 핸들러 이름 |
| `target` | string | ❌ | 핸들러별 타겟 값 (URL, 모달 ID 등) |
| `params` | object | ❌ | 핸들러에 전달할 파라미터 |
| `onSuccess` | array | ❌ | 성공 시 실행할 후속 액션 (단일 또는 배열) |
| `onError` | array | ❌ | 실패 시 실행할 후속 액션 (단일 또는 배열) |
| `confirm` | string | ❌ | 실행 전 확인 대화상자 메시지 |
| `key` | string | ❌ | 키보드 이벤트 필터 (Enter, Escape 등) |
| `cases` | object | ❌ | switch 핸들러용 케이스별 액션 정의 |
| `if` | string | ❌ | 조건부 실행 (`{{expression}}` 형식, truthy일 때만 실행) |
| `debounce` | number \| object | ❌ | 연속 호출 제어 (300 또는 { delay, leading, trailing }) |
| `resultTo` | object | ❌ | 핸들러 실행 결과를 상태에 저장 (engine-v1.11.0+). `{ target, key, merge }` |
| `actionRef` | string | ❌ | `named_actions`에 정의된 액션을 참조 (engine-v1.19.0+). handler/params 대신 사용 |

> **참고**: `type`과 `event` 중 하나는 반드시 필요. `type`은 표준 DOM 이벤트용, `event`는 커스텀 콜백용

### actionRef로 named_actions 참조 (engine-v1.19.0+)

`named_actions`에 정의된 재사용 가능한 액션을 `actionRef`로 참조합니다. 동일한 액션이 여러 컴포넌트에서 반복될 때 중복을 제거합니다.

```json
// 레이아웃 최상위에 named_actions 정의
{
  "named_actions": {
    "searchProducts": {
      "handler": "navigate",
      "params": {
        "path": "/admin/products",
        "replace": true,
        "mergeQuery": true,
        "query": { "page": 1, "search_keyword": "{{$computed.keyword || null}}" }
      }
    }
  },
  "components": [
    {
      "name": "Button",
      "actions": [{ "type": "click", "actionRef": "searchProducts" }]
    },
    {
      "name": "Input",
      "actions": [{ "type": "keypress", "key": "Enter", "actionRef": "searchProducts" }]
    }
  ]
}
```

**규칙**:

- `actionRef` 사용 시 `handler`/`params`/`target`은 생략 가능 (named_actions 정의에서 가져옴)
- `type`, `key`, `event` 등 이벤트 바인딩 속성은 인라인에서 지정
- 인라인에 `handler`/`params`를 함께 지정하면 named_actions 값을 **오버라이드**
- `named_actions`는 Partial 파일이 아닌 **부모 레이아웃**에 정의
- 상속(extends) 시 자식 레이아웃의 `named_actions`가 부모를 오버라이드

### resultTo로 핸들러 결과 저장 (engine-v1.11.0+)

핸들러 실행 결과를 상태(`_local`, `_global`, `_isolated`)에 저장합니다. 커스텀 핸들러가 반환한 값을 레이아웃에서 활용할 때 사용합니다.

```json
{
  "handler": "sirsoft-ecommerce.buildProductColumns",
  "params": {
    "baseColumns": [],
    "currencies": "{{ecommerceSettings?.data?.language_currency?.currencies}}"
  },
  "resultTo": {
    "target": "_local",
    "key": "productColumns"
  }
}
```

#### resultTo 필드

| 필드 | 타입 | 필수 | 기본값 | 설명 |
| ---- | ---- | ---- | ------ | ---- |
| `target` | string | ✅ | - | 저장 대상: `"_local"`, `"_global"`, `"_isolated"` |
| `key` | string | ✅ | - | 상태 키 (dot notation 지원, `{{}}` 동적 경로 지원) |
| `merge` | string | ❌ | `"deep"` | 병합 모드: `"replace"`, `"shallow"`, `"deep"` |

#### 병합 모드

| 모드 | 설명 |
| ---- | ---- |
| `replace` | 기존 값을 완전히 교체 |
| `shallow` | 1단계만 병합 (`{ ...existing, ...new }`) |
| `deep` | 중첩 객체까지 재귀 병합 (기본값) |

#### 동적 key 예시

```json
{
  "handler": "sirsoft-ecommerce.calculatePrice",
  "params": { "optionId": "{{option.id}}" },
  "resultTo": {
    "target": "_local",
    "key": "calculatedPrices.{{option.id}}",
    "merge": "shallow"
  }
}
```

#### 주의사항

```text
resultTo는 핸들러가 값을 반환(return)해야 동작 — undefined 반환 시 무시됨
init_actions에서 _local 저장 시 globalStateUpdater 경유 (componentContext 없는 환경)
✅ onSuccess/onError와 독립적으로 동작 — resultTo 저장 후 onSuccess 실행
```

### 이벤트 타입

| type | 설명 | 사용 컴포넌트 |
|------|------|--------------|
| `click` | 클릭 이벤트 | Button, Link, 모든 요소 |
| `change` | 값 변경 이벤트 | Input, Select, Checkbox |
| `submit` | 폼 제출 이벤트 | Form |
| `keydown` | 키보드 누름 이벤트 | Input, 모든 요소 |
| `keyup` | 키보드 뗌 이벤트 | Input, 모든 요소 |
| `focus` | 포커스 획득 | Input, Select |
| `blur` | 포커스 해제 | Input, Select |
| `scroll` | 스크롤 이벤트 | 스크롤 가능한 요소 (Div 등) |
| `mount` | 컴포넌트 마운트 | 모든 요소 |
| `unmount` | 컴포넌트 언마운트 | 모든 요소 |
| `onSortEnd` | sortable 정렬 완료 | sortable 컴포넌트 |
| `onSortStart` | sortable 드래그 시작 | sortable 컴포넌트 |

> **참고**: HTML5 네이티브 드래그 이벤트(`dragstart`, `dragover`, `drop` 등)도 지원되지만,
> 목록 정렬에는 `sortable` 속성 + `onSortEnd` 이벤트 사용을 권장합니다.
> 상세: [actions-handlers-state.md](actions-handlers-state.md#sortable-드래그앤드롭-정렬)

### 커스텀 이벤트 (event 필드)

표준 DOM 이벤트가 아닌 **컴포넌트 콜백**을 처리할 때 사용합니다.

```text
중요: 컴포넌트가 DOM Event 객체가 아닌 값(문자열, 배열 등)을 직접 전달하는 경우
         type 필드 대신 event 필드를 사용하고, $args로 인자에 접근해야 함
```

| 방식 | 필드 | 데이터 접근 | 사용 상황 |
|------|------|-------------|----------|
| 표준 DOM 이벤트 | `type: "change"` | `{{$event.target.value}}` | Input, Select, Checkbox 등 |
| 커스텀 콜백 | `event: "onChange"` | `{{$args[0]}}` | CodeEditor, DataGrid 등 |

#### 예시: CodeEditor onChange 처리

```json
// ✅ 올바름: CodeEditor는 string을 직접 전달
{
  "type": "composite",
  "name": "CodeEditor",
  "props": {
    "value": "{{_global.editorContent}}"
  },
  "actions": [
    {
      "event": "onChange",
      "handler": "setState",
      "params": {
        "target": "global",
        "editorContent": "{{$args[0]}}"
      }
    }
  ]
}

// ❌ 잘못됨: type: "change"는 DOM Event 객체를 기대함
{
  "actions": [
    {
      "type": "change",
      "handler": "setState",
      "params": {
        "editorContent": "{{$event.target.value}}"
      }
    }
  ]
}
```

#### 예시: DataGrid onRowAction 처리

```json
{
  "type": "composite",
  "name": "DataGrid",
  "actions": [
    {
      "event": "onRowAction",
      "handler": "switch",
      "params": {
        "value": "{{$args[0].actionId}}"
      },
      "cases": {
        "delete": {
          "handler": "apiCall",
          "target": "/api/items/{{$args[0].row.id}}",
          "params": { "method": "DELETE" }
        }
      }
    }
  ]
}
```

#### 예시: DataGrid onSelectionChange 처리

```json
// ✅ 올바름: onSelectionChange는 선택된 ID 배열을 직접 전달
{
  "type": "composite",
  "name": "DataGrid",
  "props": {
    "selectable": true,
    "selectedIds": "{{_local.selectedIds ?? []}}"
  },
  "actions": [
    {
      "event": "onSelectionChange",
      "handler": "setState",
      "params": {
        "target": "local",
        "selectedIds": "{{$args[0]}}"
      }
    }
  ]
}

// ❌ 잘못됨: $event는 DOM Event 객체 → DataGrid가 깨짐 (컴포넌트 로드 실패)
{
  "actions": [
    {
      "event": "onSelectionChange",
      "handler": "setState",
      "params": {
        "selectedIds": "{{$event}}"
      }
    }
  ]
}
```

#### 주요 composite 컴포넌트 콜백 목록

```text
필수: 아래 콜백은 모두 event 필드 + $args[0] 패턴 사용 필수
             $event 사용 시 DOM Event 객체가 전달되어 컴포넌트 오류 발생
```

| 컴포넌트 | 콜백 이벤트 | $args[0] 값 |
|----------|------------|-------------|
| DataGrid | `onSelectionChange` | 선택된 ID 배열 `number[]` |
| DataGrid | `onRowAction` | `{ actionId, row }` 객체 |
| DataGrid | `onSortChange` | `{ key, direction }` 객체 |
| DataGrid | `onPageChange` | 페이지 번호 `number` |
| CategoryTree | `onNodeSelect` | 선택된 노드 객체 |
| CodeEditor | `onChange` | 에디터 내용 `string` |
| HtmlEditor | `onChange` | HTML 내용 `string` |
| FileUploader | `onUploadComplete` | 업로드 결과 객체 |
| TagInput | `onChange` | 태그 배열 `string[]` |

#### $args 배열 접근

`event` 필드 사용 시 콜백의 모든 인자가 `$args` 배열에 저장됩니다:

| 바인딩 | 설명 |
|--------|------|
| `{{$args[0]}}` | 첫 번째 인자 |
| `{{$args[1]}}` | 두 번째 인자 |
| `{{$args[0].field}}` | 첫 번째 인자의 field 속성 |

---

## 내장 핸들러 목록

| 핸들러 | 설명 | target 용도 | params 용도 |
|--------|------|-------------|-------------|
| `navigate` | 페이지 이동 | - | path, mergeQuery, query |
| `navigateBack` | 브라우저 뒤로가기 | - | - |
| `navigateForward` | 브라우저 앞으로가기 | - | - |
| `apiCall` | API 호출 | 엔드포인트 URL | method, body, headers |
| `login` | 로그인 (토큰 저장) | 인증 타입 (admin/user) | body (email, password) |
| `logout` | 로그아웃 | - | - |
| `setState` | 상태 변경 | - | target (global/local), 상태 값들 |
| `setError` | 에러 상태 설정 | 에러 메시지 | - |
| `openModal` | 모달 열기 | 모달 ID | - |
| `closeModal` | 모달 닫기 | - | - |
| `showAlert` | 알림 표시 (alert) | 메시지 | - |
| `toast` | 토스트 알림 표시 | - | type, message |
| `switch` | 조건 분기 처리 | - | params.value, cases (케이스별 액션 정의, default 지원) |
| `sequence` | 순차 액션 실행 | - | actions (액션 배열) |
| `parallel` | 병렬 액션 실행 | - | actions (액션 배열) |
| `refetchDataSource` | 데이터 소스 다시 fetch | - | dataSourceId |
| `remount` | 컴포넌트 강제 리마운트 | - | componentId |
| `reloadRoutes` | 라우트 다시 로드 | - | - |
| `reloadTranslations` | 다국어 파일 다시 로드 | - | - |
| `refresh` | 현재 페이지 새로고침 | - | - |
| `stopPropagation` | 이벤트 전파 중지 | - | - |
| `preventDefault` | 기본 동작 방지 | - | - |
| `showErrorPage` | 에러 페이지 렌더링 | - | errorCode, target, containerId, layout |

> **상세 문서**: 각 핸들러별 상세 사용법은 [actions-handlers.md](actions-handlers.md) 참조

---

## Debounce 옵션

연속 호출되는 액션의 실행을 제어하여 성능을 최적화합니다. 텍스트 입력 필드의 실시간 업데이트 등에 유용합니다.

### 기본 사용법

```json
{
  "type": "change",
  "handler": "sirsoft-ecommerce.updateOptionField",
  "params": {
    "productId": "{{row.id}}",
    "field": "selling_price",
    "value": "{{$event.target.value}}"
  },
  "debounce": 300
}
```

### 상세 설정

```json
{
  "type": "change",
  "handler": "myHandler",
  "debounce": {
    "delay": 500,
    "leading": false,
    "trailing": true
  }
}
```

### 옵션 설명

| 옵션 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `delay` | number | 300 | 대기 시간 (ms) |
| `leading` | boolean | false | 첫 호출 즉시 실행 |
| `trailing` | boolean | true | 마지막 호출 후 실행 |

### 주의사항

```text
debounce 사용 시 이벤트 객체는 비동기 접근을 위해 추출됩니다
✅ 정상 접근 가능: $event.target.value, $event.target.checked
❌ 사용 불가: $event.preventDefault(), $event.stopPropagation()
```

### 사용 시나리오

| 시나리오 | debounce 권장 |
|----------|---------------|
| 가격 입력 필드 | ✅ 300ms |
| 검색 입력 필드 | ✅ 300~500ms |
| 텍스트 입력 필드 | ✅ 300ms |
| Toggle/Checkbox | ❌ 즉시 실행 |
| Select 드롭다운 | ❌ 즉시 실행 |
| 버튼 클릭 | ❌ 즉시 실행 |

---

## 주의사항

### 1. 핸들러 이름 정확히 사용

```json
// ✅ 올바른 핸들러 이름
{ "handler": "apiCall" }
{ "handler": "navigate" }
{ "handler": "setState" }

// ❌ 잘못된 핸들러 이름
{ "handler": "api" }
{ "handler": "nav" }
{ "handler": "set_state" }
```

### 2. params vs target 구분

```json
// navigate: params.path 사용
{
  "handler": "navigate",
  "params": { "path": "/admin/users" }
}

// apiCall: target으로 URL 지정
{
  "handler": "apiCall",
  "target": "/api/admin/users"
}

// openModal: target으로 모달 ID 지정
{
  "handler": "openModal",
  "target": "confirm_modal"
}
```

### 3. 상태 변경 target 타입

```json
// 전역 상태 변경
{
  "handler": "setState",
  "params": {
    "target": "global",
    "selectedIds": []
  }
}

// 로컬 상태 변경
{
  "handler": "setState",
  "params": {
    "target": "local",
    "isEditing": true
  }
}
```

### 4. 후속 액션 체인

```json
{
  "handler": "apiCall",
  "target": "/api/admin/users/{{row.id}}",
  "params": { "method": "DELETE" },
  "onSuccess": [
    { "handler": "toast", "params": { "type": "success", "message": "삭제되었습니다" } },
    { "handler": "navigate", "params": { "path": "/admin/users" } }
  ],
  "onError": [
    { "handler": "toast", "params": { "type": "error", "message": "삭제 실패" } }
  ]
}
```

### 5. 확인 다이얼로그

```json
{
  "handler": "apiCall",
  "target": "/api/admin/users/{{row.id}}",
  "params": { "method": "DELETE" },
  "confirm": "정말 삭제하시겠습니까?"
}
```

### 6. 키보드 이벤트 필터링

```json
{
  "type": "keydown",
  "handler": "navigate",
  "params": { "path": "/admin/search" },
  "key": "Enter"
}
```

### 7. $event 바인딩 시 값 추출

```text
주의: {{$event}}를 직접 사용하면 Event 객체가 전달됩니다
Input/Select: "{{$event.target.value}}" 사용
Checkbox/Toggle: "{{$event.target.checked}}" 사용
❌ 잘못됨: "{{$event}}" → NaN 오류 발생
```

```json
// ✅ 올바른 예시 - Input/Select
{
  "type": "change",
  "handler": "setState",
  "params": {
    "searchQuery": "{{$event.target.value}}"
  }
}

// ✅ 올바른 예시 - Checkbox/Toggle
{
  "type": "change",
  "handler": "setState",
  "params": {
    "isActive": "{{$event.target.checked}}"
  }
}

// ❌ 잘못된 예시 - NaN 오류 발생
{
  "type": "change",
  "handler": "myHandler",
  "params": {
    "value": "{{$event}}"
  }
}
```

**주의**: `{{$event}}`를 숫자 필드에 사용하면 `parseFloat(eventObject)`가 NaN을 반환하여 0으로 처리됩니다.

---

## 관련 문서

- [핸들러 상세 사용법](actions-handlers.md) - 각 핸들러별 파라미터, onSuccess/onError, errorHandling
- [G7Core API](actions-g7core-api.md) - React 컴포넌트에서 액션 실행
- [레이아웃 JSON 스키마](layout-json.md) - 전체 레이아웃 구조
- [데이터 바인딩](data-binding.md) - `{{}}` 표현식, `$t:` 다국어
- [상태 관리](state-management.md) - `_global` 전역 상태
