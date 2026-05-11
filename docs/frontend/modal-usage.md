# Modal 컴포넌트 사용 가이드

> **그누보드7 템플릿 엔진에서 Modal 컴포넌트를 올바르게 사용하는 방법**

## TL;DR (5초 요약)

```text
1. modals 섹션 모달은 openModal 핸들러로 열고, closeModal 핸들러로 닫음
2. 부모 레이아웃 상태 접근: $parent._local, $parent._global (engine-v1.16.0+)
3. 부모 상태 수정: G7Core.state.setParentLocal() 또는 setState target="$parent._local"
4. 로딩 상태 등 공유 상태는 _global 사용 권장 (모달 간 공유 필요 시)
5. 삭제/위험 작업 버튼은 로딩 중 흐림 + 스피너 + 텍스트 변경 필수
```

---

## 목차

1. [모달 위치에 따른 제어 방식](#모달-위치에-따른-제어-방식)
2. [modals 섹션 모달 구조](#modals-섹션-모달-구조)
3. [모달 열기/닫기](#모달-열기닫기)
4. [$parent 바인딩 컨텍스트 (engine-v1.16.0+)](#parent-바인딩-컨텍스트-v1160)
5. [Footer 버튼 배치](#footer-버튼-배치)
6. [위험 작업 버튼 로딩 상태](#위험-작업-버튼-로딩-상태)
7. [일반 액션 버튼 로딩 상태](#일반-액션-버튼-로딩-상태)
8. [완전한 예제](#완전한-예제)
9. [잘못된 패턴](#잘못된-패턴)
10. [체크리스트](#체크리스트)

---

## 모달 위치에 따른 제어 방식

그누보드7 모달 시스템에는 두 가지 배치 방식이 있습니다.

### 1. `modals` 섹션 (권장)

```json
{
  "modals": {
    "delete_confirm_modal": {
      "$ref": "partials/xxx/_modal_delete_confirm.json"
    }
  }
}
```

**특징:**
- 초기 렌더 트리에 포함되지 않음
- `openModal` 핸들러 호출 시 `_global.modalStack`에 동적 추가
- 레이아웃 전체에서 재사용 가능
- **반드시 `openModal`/`closeModal` 핸들러로 제어**

### 2. `children`/`slots` 내부 인라인 (특수 케이스)

```json
{
  "children": [
    {
      "type": "composite",
      "name": "Modal",
      "props": {
        "show": "{{_local.showModal}}"
      }
    }
  ]
}
```

**특징:**
- 초기 렌더 트리에 포함됨
- `show` prop으로 표시/숨김 제어
- 해당 컴포넌트 컨텍스트에서만 사용

### 사용 방식 결정 표

| 모달 위치 | 제어 방식 | show prop | id 필수 |
|----------|----------|-----------|---------|
| `modals` 섹션 | `openModal`/`closeModal` | ❌ 사용 금지 | ✅ 필수 |
| `children` 인라인 | `show` prop 바인딩 | ✅ 필수 | ❌ 선택 |

---

## modals 섹션 모달 구조

### 필수 구조

```json
{
  "meta": {
    "is_partial": true,
    "description": "Modal Description"
  },
  "id": "modal_unique_id",
  "type": "composite",
  "name": "Modal",
  "props": {
    "title": "$t:module.modal.title",
    "size": "medium"
  },
  "children": [
    { "/* 모달 본문 내용 */" },
    { "/* Footer 버튼 Div */" }
  ]
}
```

### 필수 속성

| 속성 | 필수 | 설명 |
|------|------|------|
| `id` | ✅ | `openModal` 핸들러에서 참조하는 고유 ID |
| `type` | ✅ | `"composite"` |
| `name` | ✅ | `"Modal"` |
| `props.title` | ✅ | 모달 제목 (다국어 권장) |
| `props.size` | ❌ | `"sm"`, `"medium"`, `"lg"` 등 |
| `children` | ✅ | 모달 내용 (footer 포함) |

### 금지 속성

| 속성 | 이유 |
|------|------|
| `props.show` | modals 섹션에서는 무시됨 |
| `slots` | 지원되지 않음 - children 사용 |
| `slots.footer` | 지원되지 않음 - children 끝에 Div로 배치 |
| `slots.default` | 지원되지 않음 - children 사용 |

---

## 모달 열기/닫기

### 모달 열기 - `openModal`

```json
{
  "type": "click",
  "handler": "openModal",
  "target": "delete_confirm_modal"
}
```

**주의:** `target` 값은 모달의 `id` 속성과 일치해야 합니다.

### 모달 닫기 - `closeModal`

```json
{
  "type": "click",
  "handler": "closeModal"
}
```

**주의:** 파라미터 없이 호출하면 현재 열린 모달(스택 최상단)이 닫힙니다.

### 데이터와 함께 모달 열기

모달에 데이터를 전달하려면 `openModal` 전에 `setState`로 데이터를 **반드시 `_global`에** 설정합니다.

```json
{
  "type": "click",
  "handler": "sequence",
  "actions": [
    {
      "handler": "setState",
      "params": {
        "target": "global",
        "deleteProduct": {
          "target": "{{$item}}",
          "canDelete": true
        }
      }
    },
    {
      "handler": "openModal",
      "target": "delete_confirm_modal"
    }
  ]
}
```

모달 내에서 `{{_global.deleteProduct.target.id}}`로 접근합니다.

### 모달 데이터는 `_global` 또는 `$parent` 사용

| 저장 위치 | 모달에서 접근 | 권장 용도 |
|----------|--------------|----------|
| `_global` | ✅ 가능 | 로딩 상태, 모달 간 공유 데이터 |
| `$parent._local` | ✅ 가능 (engine-v1.16.0+) | 부모 폼 데이터 읽기/수정 |
| `_local` | ❌ 불가 | 모달 자체의 로컬 상태만 |

```json
// ✅ 올바른 패턴 - _global 사용 (로딩 상태 등)
{
  "handler": "setState",
  "params": {
    "target": "global",
    "isDeleting": true
  }
}

// ✅ 올바른 패턴 - $parent 사용 (부모 폼 데이터 접근, engine-v1.16.0+)
{
  "text": "{{$parent._local.form.product_name}}"
}

// ❌ 잘못된 패턴 - _local은 모달 자체 상태만 접근
{
  "text": "{{_local.form.product_name}}"
}
```

---

## $parent 바인딩 컨텍스트 (engine-v1.16.0+)

모달에서 부모 레이아웃의 상태에 직접 접근하고 수정할 수 있습니다.

### 부모 상태 읽기

```json
{
  "type": "basic",
  "name": "P",
  "text": "상품명: {{$parent._local.form.product_name}}"
}
```

| 경로 | 설명 |
|------|------|
| `$parent._local` | 부모 레이아웃의 로컬 상태 |
| `$parent._global` | 부모 레이아웃의 전역 상태 |
| `$parent._computed` | 부모 레이아웃의 계산된 값 |

### 부모 상태 수정 - 레이아웃 JSON

```json
{
  "type": "click",
  "handler": "setState",
  "params": {
    "target": "$parent._local",
    "form.label_assignments": "{{filteredAssignments}}"
  }
}
```

### 부모 상태 수정 - 커스텀 핸들러

```typescript
export const myHandler: ActionHandler = async (action, context) => {
  const G7Core = (window as any).G7Core;

  // 부모 상태 읽기
  const parentContext = G7Core?.state?.getParent?.();
  const parentLocal = parentContext?._local || {};

  // 부모 상태 수정
  G7Core?.state?.setParentLocal?.({
    'form.field': newValue,
    hasChanges: true,
  });

  // 모달 닫기
  G7Core?.modal?.close?.();
};
```

### $parent vs _global 선택 기준

| 상황 | 권장 방식 | 이유 |
|------|----------|------|
| 부모 폼 데이터 읽기 | `$parent._local` | 데이터 복사 불필요, 항상 최신 값 |
| 부모 폼 데이터 수정 | `setParentLocal()` | 부모 상태 직접 수정 |
| 로딩 상태 | `_global` | 모달 간 공유, 단순함 |
| 모달 결과 전달 | `_global` 또는 `setParentLocal()` | 용도에 따라 선택 |

### 주의사항

1. **핸들러 순서**: 부모 상태 수정 핸들러를 `closeModal`보다 **먼저** 실행

```json
{
  "actions": [
    { "handler": "myModule.updateParentState" },
    { "handler": "closeModal" }
  ]
}
```

2. **캐시 스킵**: `$parent` 경로는 자동으로 캐시되지 않음 (항상 최신 값)

3. **중첩 모달**: 모달 안의 모달에서 `$parent`는 바로 위 부모만 참조

### 편집 모달의 form 데이터 — `_global` namespace 패턴

modals 섹션 모달은 React Portal 로 외부 마운트되어 페이지 단위로 영구 마운트됩니다 (open/close 는 표시 토글만 수행). 따라서 `lifecycle.onMount` 는 페이지 첫 진입 시 1회만 실행되며, close 후에도 모달의 `_local` 은 자동 폐기되지 않습니다.

또한 `setState target:"$parent._local"` 은 부모 페이지의 `_local` 을 직접 변경하므로, 모달 input 의 키스트로크당 호출되면 부모 트리 전체가 리렌더됩니다 (DataGrid·탭·헤더 등 `_local` 을 watch 하는 모든 컴포넌트).

이 두 제약 때문에 편집 모달의 form 데이터는 **모달별 고유 `_global` namespace 키** 안에 두는 것이 표준입니다:

```json
// list 측 click — 객체 통째 set (매 진입 fresh)
{ "handler": "setState", "params": {
  "target": "global",
  "<modal_namespace>": {
    "form": { ... },
    "isNew": true,
    "errors": null,
    "isSaving": false
  }
}}

// 모달 표시 — 경로 명시
{ "value": "{{_global.<modal_namespace>?.form?.X ?? ''}}" }

// 모달 input change — dot path
{ "handler": "setState", "params": {
  "target": "global",
  "<modal_namespace>.form.X": "{{$event.target.value}}"
}}

// 저장 onSuccess — 명시적 정리
[
  { "handler": "closeModal" },
  { "handler": "refetchDataSource", "params": { "dataSourceId": "..." } },
  { "handler": "setState", "params": { "target": "global", "<modal_namespace>": null } }
]
```

**키 분리 규칙**:

- 모달별 고유 키 (예: `identity_policy_form_modal`, `notification_template_form_modal`)
- 모듈 모달은 모듈 prefix 추가 (예: `ecommerce_notification_template_form_modal`)
- 모달 외부 컴포넌트가 해당 키를 표현식으로 참조하지 않을 것 — 모달 내부에서만 read 해야 키스트로크당 모달만 리렌더되고 외부 트리에 영향이 없음

**다국어 객체 / 배열**:

- 다국어 객체 (subject/body 등): `Object.assign({}, _global.<key>?.form?.subject, {[lang]: $event.target.value})` 통째 set (dot path 의 동적 키 한계로 객체 통째 교체)
- 배열 (recipients 등): `(_global.<key>?.recipients ?? []).map/.filter(...)` 통째 교체

### `$parent._local` 의 리렌더 동작

`ParentContextProvider` (engine-v1.17.0+) 의 `triggerModalParentUpdate()` 는 모달이 부모 데이터 변경을 감지할 수 있도록 ParentContextProvider 의 version 을 증가시켜 모달의 리렌더를 트리거합니다. 이는 부모 페이지의 `_local` React state 자체의 변경과는 별도로 동작합니다 — 즉 부모 페이지의 `_local` 이 변경되면 부모 트리도 리렌더됩니다.

따라서 `setState target:"$parent._local"` 은 1회성 commit (저장 직전, 닫기 직후 등) 에만 사용하고, 키스트로크당 호출은 회피합니다 (위 `_global` namespace 패턴 사용).

**관련 파일:**

| 파일 | 역할 |
|------|------|
| `ParentContextProvider.tsx` | 모달 전용 Context, version 상태 관리 |
| `DynamicRenderer.tsx` | `useParentContext` 훅으로 부모 컨텍스트 구독 |
| `ActionDispatcher.ts` | `$parent._local` 변경 시 트리거 호출 |

---

## Footer 버튼 배치

### 올바른 패턴 ✅

Footer 버튼은 `children` 배열의 **마지막 요소**로 Div에 감싸서 배치합니다.

```json
{
  "children": [
    { "/* 본문 내용들 */" },
    {
      "type": "basic",
      "name": "Div",
      "props": {
        "className": "flex justify-end gap-3 mt-6"
      },
      "children": [
        {
          "type": "basic",
          "name": "Button",
          "props": {
            "className": "px-4 py-2 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700"
          },
          "actions": [{ "type": "click", "handler": "closeModal" }],
          "children": [{ "type": "basic", "name": "Span", "text": "$t:common.cancel" }]
        },
        {
          "type": "basic",
          "name": "Button",
          "props": {
            "className": "px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700"
          },
          "actions": [{ "/* 삭제 등 주요 액션 */" }],
          "children": [{ "type": "basic", "name": "Span", "text": "$t:common.delete" }]
        }
      ]
    }
  ]
}
```

### Footer 스타일 규칙

| 요소 | 클래스 |
|------|--------|
| Footer 컨테이너 | `flex justify-end gap-3 mt-6` |
| 취소 버튼 | `px-4 py-2 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700` |
| 위험 버튼 (삭제) | `px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700` |
| 주요 버튼 (확인) | `px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700` |

---

## 위험 작업 버튼 로딩 상태

삭제, 제거, 해제 등 위험한 작업을 수행하는 모달 버튼은 **반드시** 로딩 상태를 표시해야 합니다.

### 필수 구현 요소

| 요소 | 설명 | 필수 |
|------|------|------|
| **`_global` 상태 사용** | 로딩 상태는 반드시 `_global`에 저장 (모달은 `_local` 접근 불가) | ✅ |
| 버튼 비활성화 | `disabled` prop으로 중복 클릭 방지 | ✅ |
| 취소 버튼 비활성화 | 작업 중 취소 버튼도 비활성화 | ✅ |
| 스피너 아이콘 | 로딩 중 회전 아이콘 표시 | ✅ |
| 텍스트 변경 | "삭제" → "삭제 중..." 등 | ✅ |
| opacity 감소 | `disabled:opacity-50` 클래스 | ✅ |

### 모달 내 상태는 `_global` 사용 필수

```json
// ✅ 올바른 패턴 - _global 사용
"disabled": "{{_global.isDeleting}}"
"if": "{{_global.isDeleting}}"
{ "handler": "setState", "params": { "target": "global", "isDeleting": true } }

// ❌ 잘못된 패턴 - _local은 모달에서 접근 불가
"disabled": "{{_global.isDeleting}}"
"if": "{{_global.isDeleting}}"
{ "handler": "setState", "params": { "target": "global", "isDeleting": true } }
```

### 표준 패턴

```json
{
  "type": "basic",
  "name": "Div",
  "props": { "className": "flex justify-end gap-3 mt-6" },
  "children": [
    {
      "type": "basic",
      "name": "Button",
      "props": {
        "className": "px-4 py-2 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed",
        "disabled": "{{_global.isDeleting}}"
      },
      "actions": [{ "type": "click", "handler": "closeModal" }],
      "children": [{ "type": "basic", "name": "Span", "text": "$t:common.cancel" }]
    },
    {
      "type": "basic",
      "name": "Button",
      "props": {
        "className": "flex items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed",
        "disabled": "{{_global.isDeleting}}"
      },
      "actions": [
        {
          "type": "click",
          "handler": "sequence",
          "actions": [
            { "handler": "setState", "params": { "target": "global", "isDeleting": true } },
            {
              "handler": "apiCall",
              "auth_required": true,
              "target": "/api/items/{{_global.deleteItem.id}}",
              "params": { "method": "DELETE" },
              "onSuccess": [
                { "handler": "closeModal" },
                { "handler": "setState", "params": { "target": "global", "isDeleting": false } },
                { "handler": "toast", "params": { "type": "success", "message": "$t:messages.deleted" } },
                { "handler": "refetchDataSource", "params": { "dataSourceId": "items" } }
              ],
              "onError": [
                { "handler": "setState", "params": { "target": "global", "isDeleting": false } },
                { "handler": "toast", "params": { "type": "error", "message": "{{$error.message}}" } }
              ]
            }
          ]
        }
      ],
      "children": [
        {
          "type": "basic",
          "name": "Icon",
          "if": "{{_global.isDeleting}}",
          "props": { "name": "spinner", "className": "w-4 h-4 animate-spin" }
        },
        {
          "type": "basic",
          "name": "Span",
          "text": "{{_global.isDeleting ? '$t:common.deleting' : '$t:common.delete'}}"
        }
      ]
    }
  ]
}
```

### 핵심 구현 포인트

- **버튼 클래스에 `flex items-center gap-2` 추가**: 아이콘과 텍스트를 나란히 배치

- **스피너 아이콘**: `if` 조건으로 로딩 중에만 표시

```json
{
  "type": "basic",
  "name": "Icon",
  "if": "{{_global.isDeleting}}",
  "props": { "name": "spinner", "className": "w-4 h-4 animate-spin" }
}
```

- **텍스트 삼항 연산자**: 상태에 따라 텍스트 변경

```json
{
  "text": "{{_global.isDeleting ? '$t:common.deleting' : '$t:common.delete'}}"
}
```

- **취소 버튼도 비활성화**: 작업 중 모달 닫기 방지

### 다국어 키

| 키 | 한국어 | 영어 |
| --- | ------ | ---- |
| `$t:common.delete` | 삭제 | Delete |
| `$t:common.deleting` | 삭제 중... | Deleting... |
| `$t:common.cancel` | 취소 | Cancel |

---

## 일반 액션 버튼 로딩 상태

저장, 전송, 확인, 다운로드 등 **API를 호출하는 모든 모달 버튼**은 반드시 로딩 상태를 표시해야 합니다.
위험 작업뿐 아니라 일반 액션도 동일한 스피너 패턴을 적용합니다.

### 액션별 상태 변수 네이밍

| 액션 유형 | `_global` 상태 변수 | 버튼 색상 | 텍스트 변경 |
|----------|-------------------|-----------|------------|
| 삭제/제거 | `_global.isDeleting` | `bg-red-600` | 삭제 → 삭제 중... |
| 저장/수정 | `_global.isSaving` | `bg-blue-600` 또는 `bg-indigo-600` | 저장 → 저장 중... |
| 전송 (SMS/Email) | `_global.isSending` | `bg-blue-600` | 전송 → 전송 중... |
| 확인/실행 | `_global.isProcessing` | `bg-blue-600` 또는 해당 색상 | 확인 → 처리 중... |
| 다운로드 | `_global.isDownloading` | `bg-blue-600` | 다운로드 → 다운로드 중... |

### 일반 액션 표준 패턴 (저장 예시)

```json
{
  "type": "basic",
  "name": "Div",
  "props": { "className": "flex justify-end gap-3 mt-6" },
  "children": [
    {
      "type": "basic",
      "name": "Button",
      "props": {
        "type": "button",
        "className": "px-4 py-2 text-sm border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed",
        "disabled": "{{_global.isSaving}}"
      },
      "text": "$t:common.cancel",
      "actions": [{ "type": "click", "handler": "closeModal" }]
    },
    {
      "type": "basic",
      "name": "Button",
      "props": {
        "type": "button",
        "className": "flex items-center gap-2 px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed",
        "disabled": "{{_global.isSaving}}"
      },
      "children": [
        {
          "type": "basic",
          "name": "Icon",
          "if": "{{_global.isSaving}}",
          "props": { "name": "spinner", "className": "w-4 h-4 animate-spin" }
        },
        {
          "type": "basic",
          "name": "Span",
          "text": "{{_global.isSaving ? '$t:common.saving' : '$t:common.save'}}"
        }
      ],
      "actions": [
        {
          "type": "click",
          "handler": "sequence",
          "actions": [
            { "handler": "setState", "params": { "target": "global", "isSaving": true } },
            {
              "handler": "apiCall",
              "auth_required": true,
              "target": "/api/items",
              "params": { "method": "POST", "body": {} },
              "onSuccess": [
                { "handler": "setState", "params": { "target": "global", "isSaving": false } },
                { "handler": "closeModal" },
                { "handler": "toast", "params": { "type": "success", "message": "{{response.message}}" } },
                { "handler": "refetchDataSource", "params": { "dataSourceId": "items" } }
              ],
              "onError": [
                { "handler": "setState", "params": { "target": "global", "isSaving": false } },
                { "handler": "toast", "params": { "type": "error", "message": "{{$error.message}}" } }
              ]
            }
          ]
        }
      ]
    }
  ]
}
```

### 커스텀 핸들러 호출 시 스피너 패턴

커스텀 핸들러를 사용하는 경우에도 동일한 스피너 패턴을 적용합니다.
핸들러 내부에서 `setState`를 직접 관리하는 경우, 버튼의 `disabled`와 `children` 스피너는 동일하게 구현합니다.

```json
{
  "type": "basic",
  "name": "Button",
  "props": {
    "type": "button",
    "className": "flex items-center gap-2 px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed",
    "disabled": "{{_global.isProcessing}}"
  },
  "children": [
    {
      "type": "basic",
      "name": "Icon",
      "if": "{{_global.isProcessing}}",
      "props": { "name": "spinner", "className": "w-4 h-4 animate-spin" }
    },
    {
      "type": "basic",
      "name": "Span",
      "text": "{{_global.isProcessing ? '$t:common.processing' : '$t:common.confirm'}}"
    }
  ],
  "actions": [
    {
      "type": "click",
      "handler": "sequence",
      "actions": [
        { "handler": "setState", "params": { "target": "global", "isProcessing": true } },
        {
          "handler": "myModule.customHandler",
          "params": { "/* 핸들러 파라미터 */" },
          "onSuccess": [
            { "handler": "setState", "params": { "target": "global", "isProcessing": false } },
            { "handler": "closeModal" }
          ],
          "onError": [
            { "handler": "setState", "params": { "target": "global", "isProcessing": false } },
            { "handler": "toast", "params": { "type": "error", "message": "{{$error.message}}" } }
          ]
        }
      ]
    }
  ]
}
```

### 일반 액션 다국어 키

| 키 | 한국어 | 영어 |
| --- | ------ | ---- |
| `$t:common.save` | 저장 | Save |
| `$t:common.saving` | 저장 중... | Saving... |
| `$t:common.confirm` | 확인 | Confirm |
| `$t:common.processing` | 처리 중... | Processing... |
| `$t:common.send` | 전송 | Send |
| `$t:common.sending` | 전송 중... | Sending... |
| `$t:common.download` | 다운로드 | Download |
| `$t:common.downloading` | 다운로드 중... | Downloading... |

---

## 완전한 예제

### 삭제 확인 모달

```json
{
  "meta": {
    "is_partial": true,
    "description": "Delete Confirmation Modal"
  },
  "id": "delete_confirm_modal",
  "type": "composite",
  "name": "Modal",
  "props": {
    "title": "$t:module.delete_modal.title",
    "size": "medium"
  },
  "children": [
    {
      "type": "basic",
      "name": "Div",
      "props": { "className": "space-y-4" },
      "children": [
        {
          "type": "basic",
          "name": "P",
          "props": { "className": "text-base text-gray-900 dark:text-white" },
          "text": "$t:module.delete_modal.confirm_message"
        },
        {
          "type": "basic",
          "name": "P",
          "props": { "className": "text-sm text-red-600 dark:text-red-400 font-medium" },
          "text": "$t:module.delete_modal.warning"
        }
      ]
    },
    {
      "type": "basic",
      "name": "Div",
      "props": { "className": "flex justify-end gap-3 mt-6" },
      "children": [
        {
          "type": "basic",
          "name": "Button",
          "props": {
            "className": "px-4 py-2 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed",
            "disabled": "{{_global.isDeleting}}"
          },
          "actions": [{ "type": "click", "handler": "closeModal" }],
          "children": [{ "type": "basic", "name": "Span", "text": "$t:common.cancel" }]
        },
        {
          "type": "basic",
          "name": "Button",
          "props": {
            "className": "flex items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed",
            "disabled": "{{_global.isDeleting}}"
          },
          "actions": [
            {
              "type": "click",
              "handler": "sequence",
              "actions": [
                {
                  "handler": "setState",
                  "params": { "target": "global", "isDeleting": true }
                },
                {
                  "handler": "apiCall",
                  "auth_required": true,
                  "target": "/api/items/{{_global.modal_data.itemId}}",
                  "params": { "method": "DELETE" },
                  "onSuccess": [
                    { "handler": "closeModal" },
                    { "handler": "setState", "params": { "target": "global", "isDeleting": false } },
                    { "handler": "refetchDataSource", "params": { "dataSourceId": "items" } },
                    { "handler": "toast", "params": { "type": "success", "message": "$t:module.messages.deleted" } }
                  ],
                  "onError": [
                    { "handler": "setState", "params": { "target": "global", "isDeleting": false } },
                    { "handler": "toast", "params": { "type": "error", "message": "{{$error.message}}" } }
                  ]
                }
              ]
            }
          ],
          "children": [
            {
              "type": "basic",
              "name": "Icon",
              "if": "{{_global.isDeleting}}",
              "props": { "name": "spinner", "className": "w-4 h-4 animate-spin" }
            },
            {
              "type": "basic",
              "name": "Span",
              "text": "{{_global.isDeleting ? '$t:common.deleting' : '$t:common.delete'}}"
            }
          ]
        }
      ]
    }
  ]
}
```

---

## 잘못된 패턴

### ❌ slots 사용

```json
{
  "id": "my_modal",
  "type": "composite",
  "name": "Modal",
  "children": [{ "/* 본문 */" }],
  "slots": {
    "footer": [
      { "/* 버튼들 - 이렇게 하면 렌더링되지 않음! */" }
    ]
  }
}
```

**문제:** Modal 컴포넌트는 `slots` 속성을 지원하지 않습니다. footer가 렌더링되지 않습니다.

### ❌ show prop 사용 (modals 섹션)

```json
{
  "modals": {
    "my_modal": {
      "id": "my_modal",
      "type": "composite",
      "name": "Modal",
      "props": {
        "show": "{{_local.showModal}}"
      }
    }
  }
}
```

**문제:** modals 섹션의 모달은 초기에 렌더링되지 않으므로 `show` prop이 무시됩니다.

### ❌ slots.default 사용

```json
{
  "id": "my_modal",
  "type": "composite",
  "name": "Modal",
  "slots": {
    "default": [{ "/* 본문 */" }]
  }
}
```

**문제:** `slots.default` 대신 `children`을 사용해야 합니다.

---

## 모달 내 Extension Point (v1.17.0+)

모달 내부에서도 `extension_point`를 정의할 수 있으며, 플러그인/모듈이 자동으로 주입됩니다.

```json
{
  "id": "address_modal",
  "type": "composite",
  "name": "Modal",
  "children": [
    {
      "type": "extension_point",
      "name": "address_search_slot",
      "props": {
        "onAddressSelect": {
          "handler": "setState",
          "params": {
            "target": "local",
            "form.zipcode": "{{$event.zipcode}}"
          }
        }
      }
    }
  ]
}
```

```text
✅ modals 섹션 내부의 extension_point도 백엔드에서 재귀적으로 처리됨
✅ 동일 extension_point name이 components와 modals에 모두 있으면 양쪽 모두 주입됨
모달 내 extension_point의 callbackAction에서 setState 시 target은 "local" 사용
   (모달 자체의 _local 스코프에 쓰기 — 부모 상태는 "$parent._local" 사용)
```

> 상세: [layout-extensions.md](../../extension/layout-extensions.md) "지원 위치" 섹션 참조

---

## 체크리스트

### 모달 파일 작성 시

```
□ id 속성이 있는가?
□ type: "composite", name: "Modal"인가?
□ props에 show가 없는가? (modals 섹션인 경우)
□ slots 속성이 없는가?
□ 모든 내용이 children에 있는가?
□ footer 버튼이 children 마지막 Div에 있는가?
□ footer Div에 "flex justify-end gap-3 mt-6" 클래스가 있는가?
□ 취소 버튼에 closeModal 핸들러가 있는가?
```

### 위험 작업 버튼 (삭제/제거 등) 작성 시

```
□ 로딩 상태가 _global에 저장되는가? (_local 사용 금지)
□ 삭제 버튼에 disabled:opacity-50 disabled:cursor-not-allowed 클래스가 있는가?
□ 삭제 버튼에 flex items-center gap-2 클래스가 있는가?
□ 취소 버튼도 로딩 중 비활성화되는가?
□ 로딩 중 스피너 아이콘(Icon name="spinner" animate-spin)이 표시되는가?
□ 버튼 텍스트가 로딩 상태에 따라 변경되는가? (삼항 연산자 사용)
□ setState target이 "global"인가?
□ onSuccess에서 closeModal이 setState보다 먼저 호출되는가?
□ onError에서 로딩 상태가 false로 복원되는가?
```

### 모달 호출 시

```
□ openModal 핸들러의 target이 모달 id와 일치하는가?
□ 필요한 데이터를 setState로 미리 설정했는가?
□ 데이터는 _global.modal_data 등에 저장했는가?
```

---

## ZIP 파일 업로드 모달/드로어 패턴 (multipart 백엔드)

ZIP 파일을 업로드해 백엔드에 multipart/form-data 로 전송하는 모달/드로어를 신규 작성할 때는 동일 도메인의 작동 install 모달(`_modal_manual_install.json`)을 정확히 1:1 베껴서 작성한다. 구조는 변경하지 않고 변수명/라우트/i18n 키만 바꾼다.

### 필수 패턴 (전부 충족)

| 항목 | 값 | 이유 |
| ---- | --- | ---- |
| 파일 입력 컴포넌트 | `FileInput` (basic) | UI 디자인 일관성 + onChange 가 `{ target: { value: File } }` 표준 emit |
| onChange setState 값 | `"{{$event.target.value}}"` | 표현식 평가로 File 객체 보존. raw 문자열 `"$event.target.files[0]"` 은 리터럴로 저장됨 |
| File 저장 위치 | `_global` | 모달 격리 스코프 회피 + install 모달과 일관 |
| 파일명 함께 저장 | `{prefix}UploadFileName: "{{$event.target.value?.name}}"` | install 표준 — 일관성 유지 |
| apiCall contentType | `"multipart/form-data"` | `formData: true` 같은 비표준 키 사용 금지 — 엔진의 `handleApiCall` 이 무시함 |
| apiCall body | `"{{ ({ file: _global.X }) }}"` (표현식 string) | 객체 형태 `{ file: "{{...}}" }` 보다 install 패턴과 정확히 동일 |
| 로딩 처리 | `sequence` 로 감싸 `setState({ loading: true })` → apiCall → onSuccess/onError 양쪽에서 `loading: false` | 버튼 disabled + 스피너 표시 일관 패턴 |
| 결과 박스 외부 `if` | `result` 자체 존재 여부 | manifest 가 null 인 검증 실패 케이스에서도 errors 박스 표시 가능 |

### 작성 절차

1. 같은 도메인의 `_modal_manual_install.json` 을 통째 Read
2. 새 partial 파일에 구조 그대로 복사 (FileInput → setState → apiCall sequence)
3. 변수 prefix(예: `pluginUpload` → `pluginPreview`) + 라우트 target + i18n 키 + 결과 표시 영역만 변경
4. 1:1 비교로 위 8가지 항목 동일 여부 검증

### 잘못된 패턴 (재발 방지 — 실제 발생한 누적 실수)

```json
// ❌ raw 문자열 — 리터럴 저장됨, File 손실
"setState": { "previewFile": "$event.target.files[0]" }

// ❌ formData: true 미인식 → JSON 직렬화로 폴백
"params": { "formData": true, "body": { "file": "{{...}}" } }

// ❌ _local 격리 스코프 — modal 닫힘/재열림 시 동작 불안정
"setState": { "target": "local", "previewFile": "..." }

// ❌ 결과 박스가 manifest 만 표시 — 검증 실패 케이스 미표시
"if": "{{_global.result?.manifest}}"
```

---

## 참고 자료

- [actions-handlers.md](./actions-handlers.md) - openModal, closeModal 핸들러 상세
- [layout-json.md](./layout-json.md) - 레이아웃 JSON 전체 구조
- [components.md](./components.md) - Modal 컴포넌트 props

---

## 변경 이력

| 날짜 | 버전 | 변경 내용 |
| ---- | ---- | --------- |
| 2026-01-21 | 1.0 | 초기 작성 - 정상 동작 모달 기준 규정화 |
| 2026-01-21 | 1.1 | 위험 작업 버튼 로딩 상태 패턴 추가 (스피너, 텍스트 변경, 취소 버튼 비활성화) |
| 2026-01-21 | 1.2 | 모달 내 로딩 상태는 반드시 `_global` 사용 (수정: `_local` → `_global`) |
| 2026-01-30 | 1.3 | `$parent` 바인딩 컨텍스트 추가 (engine-v1.16.0) - 부모 레이아웃 상태 접근/수정 지원 |
| 2026-04-28 | 1.4 | ZIP 파일 업로드 모달/드로어 패턴 섹션 추가 (FileInput, contentType, body 표현식, _global, sequence 로딩) |
