# 레이아웃 JSON - 슬롯 시스템

> **메인 문서**: [layout-json-components.md](layout-json-components.md)
> **관련 문서**: [components.md](components.md) 

---

## 목차

1. [동적 슬롯 시스템 (slot, SlotContainer)](#동적-슬롯-시스템-slot-slotcontainer)
2. [컴포넌트 내부 커스텀 렌더링 (component_layout)](#컴포넌트-내부-커스텀-렌더링-component_layout)

---

## 동적 슬롯 시스템 (slot, SlotContainer)

**목적**: 상태에 따라 컴포넌트를 다른 위치(슬롯)로 동적 이동시킵니다.

### 슬롯 시스템 핵심 원칙

```text
중요: 슬롯 시스템은 레이아웃 상속의 slot과 다릅니다
✅ 레이아웃 상속 slot: 부모 레이아웃의 슬롯을 자식이 채우는 구조
✅ 동적 슬롯 시스템: 상태 변화에 따라 컴포넌트가 다른 슬롯으로 이동
✅ 필수: SlotProvider가 상위에 있어야 동작 (DynamicRenderer가 자동 제공)
```

### 동작 원리

1. `slot` 속성이 있는 컴포넌트는 **원래 위치에서 렌더링되지 않음**
2. 해당 컴포넌트는 SlotContext에 등록됨
3. `SlotContainer`가 해당 슬롯 ID의 컴포넌트들을 렌더링

```text
[원래 위치 - hidden]        [SlotContainer - 실제 렌더링]
┌─────────────────┐         ┌─────────────────┐
│ slot="basic"    │ ──────> │ slotId="basic"  │
│ (등록만 함)      │         │ (여기서 렌더링)   │
└─────────────────┘         └─────────────────┘
```

### 기본 구조

```json
{
  "children": [
    {
      "id": "slot_registration_area",
      "type": "basic",
      "name": "Div",
      "props": { "className": "hidden" },
      "children": [
        {
          "id": "filter_row_1",
          "type": "basic",
          "name": "Div",
          "slot": "{{condition ? 'slot_a' : 'slot_b'}}",
          "slotOrder": 1,
          "children": [...]
        }
      ]
    },
    {
      "id": "slot_a_container",
      "type": "composite",
      "name": "SlotContainer",
      "props": { "slotId": "slot_a" }
    },
    {
      "id": "slot_b_container",
      "type": "composite",
      "name": "SlotContainer",
      "props": { "slotId": "slot_b" }
    }
  ]
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `slot` | string | ✅ | 슬롯 ID (표현식 지원, 예: `"basic_filters"` 또는 `"{{condition ? 'a' : 'b'}}"`) |
| `slotOrder` | number | ❌ | 슬롯 내 정렬 순서 (기본: 등록 순서) |

### SlotContainer Props

| Prop | 타입 | 필수 | 설명 |
|------|------|------|------|
| `slotId` | string | ✅ | 렌더링할 슬롯 ID |
| `className` | string | ❌ | 컨테이너 CSS 클래스 |

### 실제 사용 예시: 필터 편집 모드

사용자가 체크박스를 클릭하면 필터가 "기본 필터"와 "상세 필터" 사이를 이동:

```json
{
  "id": "category_filter_row",
  "type": "basic",
  "name": "Div",
  "slot": "{{(_local.visibleFilters || []).includes('category') ? 'basic_filters' : 'detail_filters'}}",
  "slotOrder": 1,
  "props": { "className": "filter-row" },
  "children": [
    {
      "type": "basic",
      "name": "Input",
      "props": {
        "type": "checkbox",
        "className": "{{_local.isEditMode ? 'checkbox mr-2' : 'hidden'}}",
        "checked": "{{(_local.visibleFilters || []).includes('category')}}"
      },
      "actions": [
        {
          "type": "change",
          "handler": "sequence",
          "actions": [
            {
              "handler": "setState",
              "params": {
                "target": "_local",
                "visibleFilters": "{{(_local.visibleFilters || []).includes('category') ? (_local.visibleFilters || []).filter(f => f !== 'category') : [...(_local.visibleFilters || []), 'category']}}"
              }
            },
            {
              "handler": "saveToLocalStorage",
              "params": {
                "key": "g7_product_filter_visible_filters",
                "value": "{{...}}"
              }
            }
          ]
        }
      ]
    },
    {
      "type": "basic",
      "name": "Span",
      "text": "$t:category_label"
    }
  ]
}
```

**SlotContainer 배치**:

```json
{
  "id": "basic_filter_section",
  "type": "basic",
  "name": "Div",
  "children": [
    {
      "id": "basic_filters_slot",
      "type": "composite",
      "name": "SlotContainer",
      "props": {
        "slotId": "basic_filters",
        "className": "flex flex-col"
      }
    }
  ]
},
{
  "id": "detail_filter_wrapper",
  "type": "basic",
  "name": "Div",
  "if": "{{_local.showDetailFilter}}",
  "children": [
    {
      "id": "detail_filters_slot",
      "type": "composite",
      "name": "SlotContainer",
      "props": {
        "slotId": "detail_filters",
        "className": "flex flex-col"
      }
    }
  ]
}
```

### 동작 흐름

```text
1. 초기 상태 (_local.visibleFilters = ['category', 'date'])
   - category_filter_row → slot="basic_filters"
   - date_filter_row → slot="basic_filters"
   - brand_filter_row → slot="detail_filters"

2. 체크박스 클릭 (category 해제)
   - visibleFilters에서 'category' 제거
   - slot 표현식 재평가
   - category_filter_row → slot="detail_filters"로 변경
   - SlotContainer 자동 업데이트

3. 결과
   - basic_filters: [date_filter_row]
   - detail_filters: [category_filter_row, brand_filter_row]
```

### 슬롯 등록 영역 패턴 (권장)

슬롯에 등록할 컴포넌트는 **hidden 영역**에 배치하는 것을 권장:

```json
{
  "id": "slot_registration_area",
  "type": "basic",
  "name": "Div",
  "props": {
    "className": "hidden",
    "aria-hidden": "true"
  },
  "children": [
    { "id": "comp_1", "slot": "slot_a", "slotOrder": 1, ... },
    { "id": "comp_2", "slot": "slot_b", "slotOrder": 2, ... }
  ]
}
```

**이유**:
- `slot` 속성이 있는 컴포넌트는 원래 위치에서 `null`을 반환
- 하지만 부모 Div 구조는 렌더링되므로 hidden으로 숨김
- 실제 렌더링은 SlotContainer에서 수행

### slotOrder 사용

여러 컴포넌트가 같은 슬롯에 등록될 때 순서 지정:

```json
{
  "id": "category_filter", "slot": "basic_filters", "slotOrder": 1
},
{
  "id": "date_filter", "slot": "basic_filters", "slotOrder": 2
},
{
  "id": "status_filter", "slot": "basic_filters", "slotOrder": 3
}
```

**결과**: SlotContainer에서 slotOrder 순으로 렌더링

### 내부 구현 참고 (개발자용)

슬롯 시스템은 다음 컴포넌트로 구현됩니다:

| 파일 | 역할 |
|------|------|
| `SlotContext.tsx` | SlotProvider, useSlotContext 제공 |
| `SlotContainer.tsx` | 슬롯 ID에 등록된 컴포넌트 렌더링 |
| `DynamicRenderer.tsx` | slot 속성 평가, 슬롯 등록 처리 |

**전역 접근**:
- `window.__slotContextValue`로 슬롯 컨텍스트에 직접 접근 가능
- React Context 타이밍 이슈 방지를 위해 전역 변수 사용

### 주의사항

- ✅ `slot` 표현식은 `{{}}` 구문 사용 필수 (동적 슬롯인 경우)
- ✅ 정적 슬롯은 문자열로 직접 지정 가능: `"slot": "basic_filters"`
- ✅ SlotContainer는 해당 slotId가 없으면 빈 상태로 렌더링
- ✅ slotOrder가 같으면 등록 순서대로 렌더링
- slot 속성이 있는 컴포넌트는 원래 위치에서 렌더링되지 않음
- 슬롯 시스템 사용 시 hidden 영역에 등록 컴포넌트 배치 권장
- ❌ SlotProvider 없이는 슬롯 시스템 동작 안 함 (DynamicRenderer가 자동 제공)

### 트러블슈팅


---

## 컴포넌트 내부 커스텀 렌더링 (component_layout)

**목적**: 컴포넌트 내부 항목의 렌더링을 커스터마이징합니다 (RichSelect, CardGrid 등).

### 핵심 원칙

```text
중요: component_layout의 item 컨텍스트는 options/data 배열의 각 항목을 그대로 전달
✅ 필수: {{item.xxx}}로 참조하는 모든 필드가 options/data 배열에 포함되어야 함
❌ 금지: label, value만 포함하고 커스텀 필드 누락
```

### component_layout 기본 구조

```json
{
  "type": "composite",
  "name": "RichSelect",
  "props": {
    "value": "{{_global.selectedFile}}",
    "options": "{{files?.data?.map(f => ({ value: f.name, label: f.name, name: f.name, size: f.size }))}}",
    "placeholder": "$t:common.select_file"
  },
  "component_layout": {
    "item": [
      {
        "type": "basic",
        "name": "Div",
        "props": { "className": "flex flex-col" },
        "children": [
          { "type": "basic", "name": "Span", "text": "{{item.name}}" },
          { "type": "basic", "name": "Span", "text": "{{item.size}}" }
        ]
      }
    ],
    "selected": [
      { "type": "basic", "name": "Span", "text": "{{item.name}}" }
    ]
  }
}
```

### component_layout 지원 컴포넌트

| 컴포넌트   | component_layout 키 | 설명                                 |
|------------|---------------------|--------------------------------------|
| RichSelect | `item`, `selected`  | 드롭다운 항목 및 선택된 항목 렌더링  |
| CardGrid   | `item`              | 카드 항목 렌더링                     |

### component_layout 컨텍스트 변수

| 변수         | 타입    | 설명                                   |
|--------------|---------|----------------------------------------|
| `item`       | object  | 현재 항목 객체 (options 배열의 요소)   |
| `index`      | number  | 현재 항목의 인덱스                     |
| `isSelected` | boolean | 선택 여부 (RichSelect에서 제공)        |

### component_layout 주의사항

- ✅ `options` 배열 생성 시 `component_layout`에서 참조하는 모든 필드 포함 필수
- ✅ `{{item.xxx}}` 바인딩은 `options` 배열의 각 항목에서 `xxx` 필드를 참조
- ❌ `options`에 없는 필드를 `{{item.xxx}}`로 참조하면 빈 값 표시
- 기본 `label`, `value`만 포함하면 커스텀 렌더링 시 데이터 누락

### component_layout 트러블슈팅


---

## 관련 문서

- [레이아웃 JSON 컴포넌트 인덱스](layout-json-components.md)
- [조건부/반복 렌더링](layout-json-components-rendering.md)
- [데이터 로딩 및 생명주기](layout-json-components-loading.md)
- [컴포넌트 목록](components.md)
