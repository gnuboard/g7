# 레이아웃 JSON - 기능 (에러 핸들링, 초기화, 모달, 액션)

> **메인 문서**: [layout-json.md](layout-json.md)
> **관련 문서**: [layout-json-components.md](layout-json-components.md) | [layout-json-inheritance.md](layout-json-inheritance.md) | [actions.md](actions.md)

---

## TL;DR (5초 요약)

```text
1. classMap: 조건부 CSS 클래스 (key → variants 매핑)
2. computed: 레이아웃 수준 계산된 값 ($computed.xxx로 접근)
3. errorHandling: HTTP 에러별 처리 (401, 403, 404, 500 등)
4. init_actions: 레이아웃 로드 시 자동 실행 액션
5. modals: 모달 정의 배열, openModal로 호출
6. isolatedState: 격리된 상태 (성능 최적화, engine-v1.14.0+)
```

---

## 하위 문서 안내

| 하위 문서 | 주요 내용 | 설명 |
|----------|----------|------|
| [layout-json-features-styling.md](layout-json-features-styling.md) | classMap, computed | 조건부 스타일 및 계산된 값 |
| [layout-json-features-error.md](layout-json-features-error.md) | errorHandling | HTTP 에러별 처리 설정 |
| [layout-json-features-actions.md](layout-json-features-actions.md) | init_actions, modals, actions, scripts | 초기화, 모달, 액션, 외부 스크립트 |

---

## 목차

### 스타일 및 계산된 값 ([상세 문서](layout-json-features-styling.md))

1. [classMap - 조건부 스타일](layout-json-features-styling.md#classmap---조건부-스타일-v190) (engine-v1.9.0+)
2. [computed - 계산된 값](layout-json-features-styling.md#computed---계산된-값-v190) (engine-v1.9.0+)

### 에러 핸들링 ([상세 문서](layout-json-features-error.md))

3. [에러 핸들링 설정 (errorHandling)](layout-json-features-error.md#에러-핸들링-설정-errorhandling) (engine-v1.6.0+)

### 초기화 및 모달 ([상세 문서](layout-json-features-actions.md))

4. [초기화 액션 (init_actions)](layout-json-features-actions.md#초기화-액션-init_actions)
5. [모달 시스템 (modals)](layout-json-features-actions.md#모달-시스템-modals)
6. [액션 시스템 (actions)](layout-json-features-actions.md#액션-시스템-actions)
7. [외부 스크립트 로드 (scripts)](layout-json-features-actions.md#외부-스크립트-로드-scripts) (engine-v1.8.0+)

---

## 기능 빠른 참조

### classMap - 조건부 스타일

```json
{
  "classMap": {
    "base": "px-2 py-1 rounded-full text-xs font-medium",
    "variants": {
      "success": "bg-green-100 text-green-800",
      "danger": "bg-red-100 text-red-800"
    },
    "key": "{{row.status}}",
    "default": "bg-gray-100 text-gray-600"
  }
}
```

→ [상세 문서](layout-json-features-styling.md#classmap---조건부-스타일-v190)

### computed - 계산된 값

```json
{
  "computed": {
    "displayPrice": "{{$get(product.prices, [_global.currency, 'formatted'], product.price_formatted)}}",
    "canEdit": "{{!product.deleted_at && product.permissions?.can_edit}}"
  }
}
```

→ [상세 문서](layout-json-features-styling.md#computed---계산된-값-v190)

### errorHandling - 에러 핸들링

```json
{
  "errorHandling": {
    "401": { "handler": "navigate", "params": { "path": "/admin/login" } },
    "403": { "handler": "toast", "params": { "type": "warning", "message": "$t:errors.forbidden" } },
    "404": { "handler": "showErrorPage", "params": { "target": "content" } },
    "default": { "handler": "toast", "params": { "type": "error", "message": "{{error.message}}" } }
  }
}
```

→ [상세 문서](layout-json-features-error.md#에러-핸들링-설정-errorhandling)

### init_actions - 초기화 액션

```json
{
  "init_actions": [
    { "handler": "initTheme" },
    { "handler": "customInit", "target": "some_value", "params": { "key": "value" } }
  ]
}
```

→ [상세 문서](layout-json-features-actions.md#초기화-액션-init_actions)

### modals - 모달 시스템

```json
{
  "modals": [
    {
      "id": "confirm_modal",
      "type": "composite",
      "name": "Modal",
      "props": { "title": "$t:modals.confirm_title", "width": "400px" },
      "children": [...]
    }
  ]
}
```

→ [상세 문서](layout-json-features-actions.md#모달-시스템-modals)

### scripts - 외부 스크립트 로드

```json
{
  "scripts": [
    {
      "src": "//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js",
      "id": "daum_postcode_script",
      "if": "{{_global.installedPlugins?.find(p => p.identifier === 'sirsoft-daum_postcode' && p.status === 'active')}}"
    }
  ]
}
```

→ [상세 문서](layout-json-features-actions.md#외부-스크립트-로드-scripts)

### isolatedState - 격리된 상태 (engine-v1.14.0+)

```json
{
  "type": "Div",
  "isolatedState": {
    "selectedCategories": [],
    "currentStep": 1
  },
  "isolatedScopeId": "category-selector",
  "children": [
    {
      "type": "basic",
      "name": "Button",
      "props": { "disabled": "{{_isolated.currentStep === 1}}" },
      "text": "이전",
      "actions": [{
        "type": "click",
        "handler": "setState",
        "params": { "target": "isolated", "currentStep": "{{_isolated.currentStep - 1}}" }
      }]
    }
  ]
}
```

**특징**:

- 해당 영역 내에서만 유효한 격리된 상태
- 상태 변경 시 격리된 영역만 리렌더링 (성능 최적화)
- `_isolated` 접두사로 접근
- `target: "isolated"`로 업데이트

→ [상세 문서](layout-json.md#격리된-상태-isolatedstate)

---

## 관련 문서

- [레이아웃 JSON 스키마](layout-json.md)
- [레이아웃 상속](layout-json-inheritance.md)
- [레이아웃 컴포넌트](layout-json-components.md)
- [액션 핸들러](actions-handlers.md)
- [데이터 소스](data-sources.md)
