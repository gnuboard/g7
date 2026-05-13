# 레이아웃 JSON - 컴포넌트 (반복 렌더링, Blur, 생명주기, 슬롯)

> **메인 문서**: [layout-json.md](layout-json.md)
> **관련 문서**: [layout-json-features.md](layout-json-features.md) | [layout-json-inheritance.md](layout-json-inheritance.md) | [components.md](components.md)

---

## TL;DR (5초 요약)

```text
1. if: 조건부 렌더링 (type: "conditional" 사용 금지!)
2. iteration: 배열 반복 (source, item_var, index_var)
2-1. sortable: 드래그앤드롭 정렬 (source, itemKey, strategy, itemTemplate)
3. blur_until_loaded: 데이터 로딩 중 blur 효과
4. lifecycle: onMount, onUnmount 핸들러
5. slot/SlotContainer: 동적 컴포넌트 삽입 영역
```

---

## 하위 문서 안내

| 하위 문서 | 주요 내용 | 설명 |
|----------|----------|------|
| [layout-json-components-rendering.md](layout-json-components-rendering.md) | if, iteration, sortable | 조건부/반복/정렬 렌더링 |
| [layout-json-components-loading.md](layout-json-components-loading.md) | blur_until_loaded, lifecycle | 데이터 로딩 및 생명주기 |
| [layout-json-components-slots.md](layout-json-components-slots.md) | slot, SlotContainer, component_layout | 슬롯 시스템 및 커스텀 렌더링 |

---

## 목차

### 조건부/반복 렌더링 ([상세 문서](layout-json-components-rendering.md))

1. [조건부 렌더링 (if)](layout-json-components-rendering.md#조건부-렌더링-if)
2. [반복 렌더링 (iteration)](layout-json-components-rendering.md#반복-렌더링-iteration)
3. [정렬 가능 렌더링 (sortable)](layout-json-components-rendering.md#정렬-가능-렌더링-sortable)

### 데이터 로딩 및 생명주기 ([상세 문서](layout-json-components-loading.md))

3. [데이터 로딩 중 Blur 효과 (blur_until_loaded)](layout-json-components-loading.md#데이터-로딩-중-blur-효과-blur_until_loaded)
4. [컴포넌트 생명주기 (lifecycle)](layout-json-components-loading.md#컴포넌트-생명주기-lifecycle)

### 슬롯 시스템 ([상세 문서](layout-json-components-slots.md))

5. [동적 슬롯 시스템 (slot, SlotContainer)](layout-json-components-slots.md#동적-슬롯-시스템-slot-slotcontainer)
6. [컴포넌트 내부 커스텀 렌더링 (component_layout)](layout-json-components-slots.md#컴포넌트-내부-커스텀-렌더링-component_layout)

---

## 기능 빠른 참조

### if - 조건부 렌더링

```json
{
  "id": "warning_message",
  "type": "basic",
  "name": "Div",
  "if": "{{_local.showWarning}}",
  "text": "경고 메시지입니다."
}
```

```text
필수: if 속성 사용 (type: "conditional" 미지원)
필수: if 속성 사용
```

→ [상세 문서](layout-json-components-rendering.md#조건부-렌더링-if)

### iteration - 반복 렌더링

```json
{
  "id": "item_list",
  "type": "basic",
  "name": "Ul",
  "iteration": {
    "source": "items.data",
    "item_var": "item",
    "index_var": "idx"
  },
  "children": [
    { "type": "basic", "name": "Li", "text": "{{item.name}}" }
  ]
}
```

→ [상세 문서](layout-json-components-rendering.md#반복-렌더링-iteration)

### blur_until_loaded - 데이터 로딩 중 Blur

```json
// Boolean (기본)
{ "blur_until_loaded": true }

// 표현식 (engine-v1.5.0+)
{ "blur_until_loaded": "{{_global.isSaving}}" }

// 객체 (engine-v1.6.0+)
{ "blur_until_loaded": { "enabled": true, "data_sources": "users" } }
```

→ [상세 문서](layout-json-components-loading.md#데이터-로딩-중-blur-효과-blur_until_loaded)

### lifecycle - 컴포넌트 생명주기

```json
{
  "lifecycle": {
    "onMount": [
      { "type": "click", "handler": "loadSavedData" }
    ],
    "onUnmount": [
      { "type": "click", "handler": "cleanup" }
    ]
  }
}
```

→ [상세 문서](layout-json-components-loading.md#컴포넌트-생명주기-lifecycle)

### slot/SlotContainer - 동적 슬롯

```json
// 슬롯 등록
{
  "id": "filter_row",
  "slot": "{{condition ? 'basic_filters' : 'detail_filters'}}",
  "slotOrder": 1
}

// 슬롯 렌더링
{
  "type": "composite",
  "name": "SlotContainer",
  "props": { "slotId": "basic_filters" }
}
```

→ [상세 문서](layout-json-components-slots.md#동적-슬롯-시스템-slot-slotcontainer)

### component_layout - 커스텀 렌더링

```json
{
  "type": "composite",
  "name": "RichSelect",
  "props": {
    "options": "{{files?.data?.map(f => ({ value: f.name, label: f.name, size: f.size }))}}"
  },
  "component_layout": {
    "item": [
      { "type": "basic", "name": "Span", "text": "{{item.name}} ({{item.size}})" }
    ]
  }
}
```

→ [상세 문서](layout-json-components-slots.md#컴포넌트-내부-커스텀-렌더링-component_layout)

---

## iteration + if 함께 사용 시 주의사항

```text
if가 iteration과 같은 레벨에서 item_var를 참조하면 동작하지 않음!
```

**❌ 잘못된 패턴**:

```json
{
  "iteration": { "source": "{{types}}", "item_var": "itemType" },
  "if": "{{activeTab === itemType}}",
  "children": [...]
}
```

**✅ 올바른 패턴**: if를 children 내부로 이동

```json
{
  "iteration": { "source": "{{types}}", "item_var": "itemType" },
  "children": [
    {
      "if": "{{activeTab === itemType}}",
      "children": [...]
    }
  ]
}
```

→ [상세 문서](layout-json-components-rendering.md#iteration--if-함께-사용-시-주의사항)

---

## 관련 문서

- [레이아웃 JSON 스키마](layout-json.md)
- [레이아웃 상속](layout-json-inheritance.md)
- [레이아웃 기능](layout-json-features.md)
- [컴포넌트 목록](components.md)
- [데이터 바인딩](data-binding.md)
- [상태 관리](state-management.md)
