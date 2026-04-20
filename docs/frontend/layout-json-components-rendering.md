# 레이아웃 JSON - 조건부/반복 렌더링

> **메인 문서**: [layout-json-components.md](layout-json-components.md)
> **관련 문서**: [data-binding.md](data-binding.md) | [state-management.md](state-management.md)

---

## 목차

1. [조건부 렌더링 (if)](#조건부-렌더링-if)
2. [복합 조건 렌더링 (conditions)](#복합-조건-렌더링-conditions) ⭐ NEW (engine-v1.10.0+)
3. [반복 렌더링 (iteration)](#반복-렌더링-iteration)
4. [정렬 가능 렌더링 (sortable)](#정렬-가능-렌더링-sortable)

---

## 조건부 렌더링 (if)

**목적**: 조건에 따라 컴포넌트를 표시하거나 숨깁니다.

### 핵심 원칙

```text
필수: if 속성 사용 (type: "conditional" 미지원)
필수: if 속성을 사용한 조건부 렌더링
✅ 필수: 조건이 truthy일 때만 컴포넌트가 렌더링됨
```

### 기본 사용법

```json
{
  "id": "warning_message",
  "type": "basic",
  "name": "Div",
  "if": "{{_local.showWarning}}",
  "props": {
    "className": "text-orange-500"
  },
  "text": "경고 메시지입니다."
}
```

### if 표현식

`if` 속성은 JavaScript 표현식을 사용하여 조건을 평가합니다:

```json
// 단순 boolean 확인
"if": "{{_local.isVisible}}"

// 값 비교
"if": "{{_local.status === 'active'}}"

// 복합 조건
"if": "{{_local.count > 0 && _local.isEnabled}}"

// 데이터 존재 확인
"if": "{{user.data?.name}}"

// 배열 길이 확인
"if": "{{items.data?.length > 0}}"
```

### condition 속성 (if의 별칭)

> @since engine-v1.21.1

`condition` 속성은 `if`와 동일하게 동작합니다. 컴포넌트 내부 children(예: DataGrid의 `cellChildren`, `footerCells`)에서 관례적으로 사용됩니다.

```json
{
  "type": "basic",
  "name": "Span",
  "condition": "{{row.discount_amount > 0}}",
  "text": "-{{row.discount_amount | number}}원"
}
```

- 엔진이 prop을 사전 해석하여 `condition`이 boolean이 되어도 정상 동작
- 우선순위: `if` > `condition` > `conditions` (if가 있으면 condition은 무시)

### 잘못된 패턴과 올바른 패턴

**❌ 잘못된 패턴 (type: "conditional" 미지원)**:

```json
{
  "type": "conditional",
  "condition": "{{_local.isVisible}}",
  "true": {
    "type": "basic",
    "name": "Span",
    "text": "보임"
  },
  "false": null
}
```

**✅ 올바른 패턴 (if 속성 사용)**:

```json
{
  "id": "visible_text",
  "type": "basic",
  "name": "Span",
  "if": "{{_local.isVisible}}",
  "text": "보임"
}
```

### 다중 조건 분기 (if-else 패턴)

여러 조건에 따라 다른 컴포넌트를 표시해야 할 경우, 각 조건에 맞는 `if` 속성을 가진 개별 컴포넌트를 사용합니다:

```json
{
  "type": "basic",
  "name": "Div",
  "children": [
    {
      "id": "status_active",
      "type": "basic",
      "name": "Span",
      "if": "{{_local.status === 'active'}}",
      "props": { "className": "text-green-500" },
      "text": "활성"
    },
    {
      "id": "status_inactive",
      "type": "basic",
      "name": "Span",
      "if": "{{_local.status === 'inactive'}}",
      "props": { "className": "text-gray-500" },
      "text": "비활성"
    },
    {
      "id": "status_pending",
      "type": "basic",
      "name": "Span",
      "if": "{{_local.status === 'pending'}}",
      "props": { "className": "text-yellow-500" },
      "text": "대기"
    }
  ]
}
```

### iteration과 함께 사용

조건부 렌더링과 반복 렌더링을 함께 사용할 수 있습니다:

```json
{
  "id": "product_list",
  "type": "basic",
  "name": "Div",
  "if": "{{products.data?.length > 0}}",
  "iteration": {
    "source": "products.data",
    "item_var": "product"
  },
  "children": [
    {
      "type": "basic",
      "name": "Span",
      "text": "{{product.name}}"
    }
  ]
}
```

### iteration + if 함께 사용 시 주의사항

```text
주의: if가 iteration과 같은 레벨에 있고 item_var를 참조하면 동작하지 않음!
   - if는 iteration보다 먼저 평가됨
   - if 평가 시점에 item_var가 아직 바인딩되지 않음
   - 결과: 조건이 항상 false로 평가되어 아무것도 렌더링되지 않음
```

**❌ 잘못된 패턴**: if가 iteration과 같은 레벨에서 item_var 참조

```json
{
  "iteration": {
    "source": "{{types}}",
    "item_var": "itemType"
  },
  "type": "basic",
  "name": "Div",
  "if": "{{activeTab === itemType}}",
  "children": [...]
}
```

**✅ 올바른 패턴**: if를 children 내부로 이동

```json
{
  "iteration": {
    "source": "{{types}}",
    "item_var": "itemType"
  },
  "type": "basic",
  "name": "Div",
  "children": [
    {
      "type": "basic",
      "name": "Div",
      "if": "{{activeTab === itemType}}",
      "children": [...]
    }
  ]
}
```

**평가 순서**:

1. `if` 평가 (iteration 이전) → item_var 미바인딩 상태
2. `iteration` 처리 → item_var 바인딩
3. children 렌더링 → children 내부의 if는 item_var 접근 가능

### 주의사항

- ✅ `if` 속성은 DynamicRenderer에서 평가되어 조건이 falsy면 컴포넌트가 렌더링되지 않음
- ✅ `if` 표현식에서 `{{}}` 구문 사용 필수
- ❌ `type: "conditional"`, `condition`, `true`, `false` 속성은 미지원
- ❌ 중첩된 조건문보다 평면적인 다중 `if` 패턴 권장

---

## 복합 조건 렌더링 (conditions)

> **버전**: engine-v1.10.0+

**목적**: 여러 조건을 AND/OR 그룹으로 조합하여 복잡한 렌더링 조건을 표현합니다. 기존 `if` 속성의 완전한 상위호환입니다.

### 핵심 원칙

```text
✅ conditions는 기존 if의 상위호환 (if가 있으면 if 우선)
✅ AND 그룹: 모든 조건이 true일 때만 렌더링
✅ OR 그룹: 하나라도 true면 렌더링
✅ if-else 체인: 하나라도 매칭되면 렌더링
```

### 타입 정의

```typescript
type ConditionExpression =
  | string                              // 단순 표현식: "{{user.isAdmin}}"
  | { and: ConditionExpression[] }      // AND 그룹: 모든 조건 true → true
  | { or: ConditionExpression[] };      // OR 그룹: 하나라도 true → true

interface ConditionBranch {
  if?: ConditionExpression;             // 조건 (없으면 else 브랜치)
}

type ConditionsProperty = ConditionExpression | ConditionBranch[];
```

### AND 그룹 사용

모든 조건이 true일 때만 컴포넌트가 렌더링됩니다.

```json
{
  "id": "author_info",
  "type": "basic",
  "name": "Div",
  "conditions": {
    "and": ["{{route.id}}", "{{form_data?.data?.author}}"]
  },
  "props": { "className": "author-info" },
  "text": "작성자: {{form_data.data.author.name}}"
}
```

### OR 그룹 사용

하나라도 true면 컴포넌트가 렌더링됩니다.

```json
{
  "id": "admin_section",
  "type": "basic",
  "name": "Div",
  "conditions": {
    "or": ["{{_global.user?.role === 'admin'}}", "{{_global.user?.role === 'manager'}}"]
  },
  "text": "관리자 전용 영역"
}
```

### 중첩 AND/OR 조건

복잡한 조건 로직을 표현할 수 있습니다.

```json
{
  "id": "sales_dashboard",
  "type": "basic",
  "name": "Div",
  "conditions": {
    "or": [
      "{{_global.user?.isSuperAdmin}}",
      {
        "and": ["{{_global.user?.isAdmin}}", "{{_global.user?.department === 'sales'}}"]
      }
    ]
  },
  "text": "영업 대시보드"
}
```

위 조건은 다음과 같이 해석됩니다:

- SuperAdmin이면 렌더링 **또는**
- Admin이면서 영업부서이면 렌더링

### if-else 체인 (브랜치 배열)

여러 조건 중 하나라도 매칭되면 렌더링됩니다. 기존 `if`의 다중 조건 분기 패턴을 단순화합니다.

```json
{
  "id": "permission_content",
  "type": "basic",
  "name": "Div",
  "conditions": [
    { "if": "{{_global.user?.role === 'admin'}}" },
    { "if": "{{_global.user?.role === 'manager'}}" },
    { "if": "{{_global.user?.hasSpecialPermission}}" }
  ],
  "text": "권한이 있는 사용자만 볼 수 있습니다"
}
```

위 조건은 admin, manager, 또는 특별 권한이 있는 경우 렌더링됩니다.

### if vs conditions 우선순위

`if`와 `conditions`가 동시에 있으면 **`if`가 우선**됩니다 (하위 호환).

```json
{
  "id": "test",
  "type": "basic",
  "name": "Div",
  "if": "{{ifCondition}}",
  "conditions": "{{conditionsValue}}",
  "text": "if가 우선됨"
}
```

### if vs conditions 선택 가이드

| 상황 | 권장 |
|------|------|
| 단순 조건 | `if` |
| 여러 조건 AND | `conditions: { "and": [...] }` |
| 여러 조건 OR | `conditions: { "or": [...] }` |
| 복잡한 조합 | `conditions` (중첩 AND/OR) |
| 기존 레이아웃 수정 | 기존 `if` 유지 |

### 주의사항

```text
✅ conditions의 모든 문자열 표현식은 기존 if와 동일한 문법 지원
✅ Optional Chaining(?.), 비교 연산자, 논리 연산자 모두 지원
if와 conditions를 동시에 사용하면 if가 우선
conditions 배열(브랜치)에서 else 브랜치(if 없음)는 컴포넌트 렌더링에서 의미 없음 (액션에서만 사용)
```

---

## 반복 렌더링 (iteration)

**목적**: 배열 데이터를 기반으로 컴포넌트를 반복 렌더링합니다.

### iteration 핵심 원칙

```text
✅ iteration이 있는 컴포넌트는 wrapper로 렌더링됨 (각 아이템마다 해당 컴포넌트 생성)
✅ children이 있으면 wrapper 내부에 children이 렌더링됨
✅ text만 있고 children이 없어도 정상 동작 (wrapper + text)
```

### 구조

```json
{
  "id": "error_list",
  "type": "basic",
  "name": "Ul",
  "iteration": {
    "source": "items.data",
    "item_var": "item",
    "index_var": "idx"
  },
  "children": [
    {
      "type": "basic",
      "name": "Li",
      "text": "{{item.name}}"
    }
  ]
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `source` | string | ✅ | 반복할 배열 데이터 경로 (표현식 지원) |
| `item_var` | string | ✅ | 각 아이템을 참조할 변수명 |
| `index_var` | string | ❌ | 인덱스를 참조할 변수명 (0부터 시작) |

### 사용 패턴

**✅ 패턴 1**: iteration이 있는 컴포넌트에 children 포함 (wrapper + children)

```json
{
  "type": "basic",
  "name": "Div",
  "props": { "className": "flex items-center gap-2" },
  "iteration": {
    "source": "items.data",
    "item_var": "item"
  },
  "children": [
    {
      "type": "basic",
      "name": "Icon",
      "props": { "name": "check" }
    },
    {
      "type": "basic",
      "name": "Span",
      "text": "{{item.name}}"
    }
  ]
}
```

**결과 HTML**:

```html
<div class="flex items-center gap-2"><i>...</i><span>Item 1</span></div>
<div class="flex items-center gap-2"><i>...</i><span>Item 2</span></div>
```

**✅ 패턴 2**: iteration이 있는 컴포넌트에 text만 포함 (wrapper + text)

```json
{
  "type": "basic",
  "name": "Li",
  "iteration": {
    "source": "_local.errors",
    "item_var": "error"
  },
  "text": "{{error}}"
}
```

**결과 HTML**:

```html
<li>Error message 1</li>
<li>Error message 2</li>
```

### source 표현식 지원

`source`에는 단순 경로뿐 아니라 JavaScript 표현식도 사용 가능합니다:

```json
{
  "iteration": {
    "source": "Object.entries(_local.errors ?? {}).flatMap(([field, messages]) => messages)",
    "item_var": "message"
  }
}
```

### _local 상태 접근

iteration 내부 컴포넌트에서도 `_local` 상태에 접근할 수 있습니다. 이는 레이아웃 전체에서 상태가 공유되기 때문입니다.

```json
{
  "iteration": {
    "source": "_local.items",
    "item_var": "item"
  },
  "children": [
    {
      "type": "basic",
      "name": "Div",
      "props": {
        "className": "{{_local.selectedId === item.id ? 'selected' : ''}}"
      },
      "text": "{{item.name}}"
    }
  ]
}
```

### 실제 사용 예시: API 에러 메시지 표시

```json
{
  "id": "validation_error",
  "type": "basic",
  "name": "Div",
  "if": "{{_local.errors && Object.keys(_local.errors).length > 0}}",
  "props": {
    "className": "bg-red-50 border border-red-200 rounded-lg p-4 mb-6"
  },
  "children": [
    {
      "type": "basic",
      "name": "Ul",
      "props": {
        "className": "list-disc list-inside space-y-1"
      },
      "iteration": {
        "source": "Object.entries(_local.errors ?? {}).flatMap(([field, messages]) => messages)",
        "item_var": "message"
      },
      "children": [
        {
          "type": "basic",
          "name": "Li",
          "props": {
            "className": "text-sm text-red-700"
          },
          "text": "{{message}}"
        }
      ]
    }
  ]
}
```

### 주의사항

- ✅ `source`는 배열로 평가되어야 함 (배열이 아니면 경고 로그 출력)
- ✅ `item_var`로 지정한 변수명으로 각 아이템에 접근
- ✅ `index_var`는 선택사항 (필요한 경우에만 지정)
- ✅ children 내부에서 `{{item_var}}`로 데이터 바인딩
- ✅ iteration이 있는 컴포넌트는 **wrapper로 렌더링**되며, 각 아이템마다 해당 컴포넌트가 생성됨
- **iteration 내 컴포넌트에 고유 `id` 권장** - React key 안정성 보장 및 상태 동기화 문제 방지

### iteration 내 컴포넌트 id 패턴 (권장)

iteration 내부에서 상태를 관리하는 컴포넌트(Input, Select, TagInput 등)는 고유 `id`를 지정해야 합니다:

```json
// ✅ 권장: index_var를 활용한 고유 id
{
  "iteration": {
    "source": "{{_local.items}}",
    "item_var": "item",
    "index_var": "idx"
  },
  "children": [
    {
      "id": "input_item_{{idx}}",
      "type": "basic",
      "name": "Input",
      "props": {
        "name": "item_name_{{idx}}",
        "value": "{{item.name}}"
      }
    }
  ]
}

// ✅ 권장: item의 고유 식별자 활용
{
  "id": "tag_input_{{item.id}}",
  "type": "composite",
  "name": "TagInput",
  "props": {
    "value": "{{item.values ?? []}}"
  }
}

// ❌ 비권장: id 없음 (동적 추가/삭제 시 상태 혼선 가능)
{
  "type": "composite",
  "name": "TagInput",
  "props": {
    "value": "{{item.values ?? []}}"
  }
}
```

**id가 필요한 이유**:

- React의 reconciliation 과정에서 컴포넌트 인스턴스 식별에 사용
- id가 없으면 동적으로 행 추가/삭제 시 이전 인스턴스의 내부 상태가 새 인스턴스에 표시될 수 있음
- 특히 내부 상태를 관리하는 컴포넌트(TagInput, Select, Input 등)에서 중요

---

## 정렬 가능 렌더링 (sortable)

> **버전**: engine-v1.14.0+
> **기반**: @dnd-kit/core + @dnd-kit/sortable

`sortable` 속성을 사용하면 드래그앤드롭으로 배열 아이템의 순서를 변경할 수 있습니다.
`iteration`과 유사하지만 정렬 기능이 내장되어 있습니다.

### 핵심 원칙

```text
sortable과 iteration이 같은 컴포넌트에 있으면 sortable만 동작 (우선순위 높음)
sortable 내부에서는 폼 자동 바인딩이 비활성화됨 (stale 값 방지)
onSortEnd에서 setState로 소스 배열을 업데이트해야 정렬 결과가 반영됨
```

### 기본 구조

```json
{
  "type": "basic",
  "name": "Div",
  "sortable": {
    "source": "{{_local.form.items}}",
    "itemKey": "id",
    "strategy": "verticalList",
    "handle": "[data-drag-handle]",
    "itemVar": "$item",
    "indexVar": "$index"
  },
  "itemTemplate": {
    "type": "basic",
    "name": "Div",
    "children": [
      {
        "type": "basic",
        "name": "Div",
        "props": { "data-drag-handle": true, "className": "cursor-grab" },
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
        "form.items": "{{$sortedItems}}"
      }
    }
  ]
}
```

### sortable 속성

| 필드 | 타입 | 필수 | 기본값 | 설명 |
| ---- | ---- | ---- | ------ | ---- |
| `source` | string | ✅ | - | 배열 바인딩 표현식 |
| `itemKey` | string | ❌ | `"id"` | 아이템 고유 키 필드명 |
| `strategy` | string | ❌ | `"verticalList"` | `verticalList` / `horizontalList` / `rectSorting` |
| `handle` | string | ❌ | - | 드래그 핸들 CSS 선택자 |
| `itemVar` | string | ❌ | `"$item"` | 아이템 컨텍스트 변수명 |
| `indexVar` | string | ❌ | `"$index"` | 인덱스 컨텍스트 변수명 |
| `wrapperElement` | string | ❌ | `"div"` | 래퍼 HTML 요소. Table 내부에서 `"tr"` 지정 시 itemTemplate의 children을 `<tr>` 안에 직접 렌더링 (engine-v1.30.0+) |

### itemTemplate

`sortable`과 함께 사용하는 아이템 템플릿입니다. `iteration`의 자식 컴포넌트 역할을 합니다.
`$item`과 `$index`(또는 `itemVar`/`indexVar`로 지정한 변수명)로 각 아이템에 접근합니다.

### sortable 전용 이벤트

| 이벤트 | 컨텍스트 변수 | 설명 |
| ------ | ------------- | ---- |
| `onSortEnd` | `$sortedItems`, `$oldIndex`, `$newIndex` | 정렬 완료 |
| `onSortStart` | `$activeId` | 드래그 시작 |

### 드래그 핸들

`handle`을 지정하면 해당 CSS 선택자를 가진 요소만 드래그 가능합니다.
DynamicRenderer가 `data-drag-handle` 속성을 감지하여 @dnd-kit 리스너를 자동 바인딩합니다.

핸들 미지정 시 아이템 전체가 드래그 가능합니다.

### iteration과의 차이

| 항목 | iteration | sortable |
| ---- | --------- | -------- |
| 용도 | 배열 반복 렌더링 | 드래그앤드롭 정렬 |
| 정렬 지원 | ❌ | ✅ (@dnd-kit 내장) |
| 폼 자동 바인딩 | ✅ | ❌ (비활성화됨) |
| 아이템 정의 | `children` | `itemTemplate` |
| 이벤트 | 없음 | `onSortEnd`, `onSortStart` |

> **상세**: [actions-handlers-state.md](actions-handlers-state.md#sortable-드래그앤드롭-정렬)

---

## 관련 문서

- [레이아웃 JSON 컴포넌트 인덱스](layout-json-components.md)
- [데이터 로딩 및 생명주기](layout-json-components-loading.md)
- [슬롯 시스템](layout-json-components-slots.md)
- [데이터 바인딩](data-binding.md)
