# 레이아웃 JSON - 초기화, 모달, 액션, 스크립트

> **메인 문서**: [layout-json-features.md](layout-json-features.md)

---

## 목차

1. [초기화 액션 (init_actions)](#초기화-액션-init_actions)
2. [Named Actions (named_actions)](#named-actions-named_actions)
3. [모달 시스템 (modals)](#모달-시스템-modals)
4. [컴포넌트 이벤트 구독 (onComponentEvent)](#컴포넌트-이벤트-구독-oncomponentevent)
5. [액션 시스템 (actions)](#액션-시스템-actions)
6. [외부 스크립트 로드 (scripts)](#외부-스크립트-로드-scripts)

---

## 초기화 액션 (init_actions)

**목적**: 레이아웃 로드 시 자동으로 실행할 핸들러를 정의합니다.

### 사용 사례

- 테마 초기화 (`initTheme`)
- 사용자 설정 로드
- 분석 이벤트 전송
- 전역 상태 초기화

### 구조

```json
{
  "init_actions": [
    {
      "handler": "initTheme"
    },
    {
      "handler": "customInit",
      "target": "some_value",
      "params": {
        "key": "value"
      }
    },
    {
      "handler": "apiCall",
      "if": "{{!!query.user_id}}",
      "target": "/api/admin/users/search",
      "params": {
        "method": "GET",
        "query": { "id": "{{query.user_id}}" }
      },
      "onSuccess": [
        {
          "handler": "setState",
          "params": { "target": "local", "userData": "{{response.data}}" }
        }
      ]
    }
  ]
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `handler` | string | ✅ | 실행할 핸들러 이름 (템플릿 handlerMap에 등록된 이름) |
| `target` | string | ❌ | 핸들러에 전달할 타겟 값 |
| `params` | object | ❌ | 핸들러에 전달할 파라미터 |
| `if` | string | ❌ | 조건부 실행 (`{{expression}}` 형식, truthy일 때만 실행) |
| `onSuccess` | object/array | ❌ | 성공 시 실행할 후속 액션 |
| `onError` | object/array | ❌ | 실패 시 실행할 후속 액션 |
| `resultTo` | object | ❌ | 핸들러 결과를 상태에 저장할 위치 |
| `auth_required` | boolean | ❌ | 인증 필요 여부 |

### 실행 시점

1. 레이아웃 JSON 로드
2. blocking 데이터 소스 fetch
3. **초기 렌더링 완료**
4. **`init_actions` 실행** ← 여기서 실행
5. progressive/background 데이터 소스 fetch

### 핸들러 등록 방법

템플릿의 `handlers/index.ts`에서 핸들러를 등록합니다:

```typescript
// handlers/initThemeHandler.ts
export async function initThemeHandler(
  _action: any,
  _context?: any
): Promise<void> {
  // 초기화 로직
  const savedTheme = localStorage.getItem('g7_color_scheme');
  applyTheme(savedTheme || 'auto');
}

// handlers/index.ts
import { initThemeHandler } from './initThemeHandler';

export const handlerMap = {
  initTheme: initThemeHandler,
  // ... 다른 핸들러들
} as const;
```

### 실제 사용 예시

```json
{
  "version": "1.0.0",
  "layout_name": "admin_login",
  "meta": {
    "title": "$t:auth.login.title",
    "auth_required": false
  },
  "data_sources": [],
  "init_actions": [
    {
      "handler": "initTheme"
    }
  ],
  "components": [...]
}
```

### 데이터 바인딩 지원

init_actions는 정적 바인딩을 지원합니다. route params, query, _global, blocking 데이터 등에 접근할 수 있습니다.

```json
{
  "init_actions": [
    {
      "handler": "initWithPage",
      "target": "{{query.page}}",
      "params": {
        "userId": "{{route.id}}",
        "mode": "{{_global.mode}}"
      }
    }
  ]
}
```

**접근 가능한 데이터**:
- `{{route.id}}`, `{{route.slug}}` - URL 라우트 파라미터
- `{{query.page}}`, `{{query.filter}}` - URL 쿼리 파라미터
- `{{_global.xxx}}` - 전역 상태
- blocking 데이터 소스 결과

**주의**: form 데이터나 이벤트 컨텍스트는 init_actions 시점에 존재하지 않습니다. 이런 데이터가 필요하면 컴포넌트의 `lifecycle.onMount`를 사용하세요.

### conditions 핸들러 사용 (engine-v1.24.7+)

init_actions에서 `conditions` 핸들러를 사용하여 조건 분기를 실행할 수 있습니다.

```json
{
  "init_actions": [
    {
      "handler": "conditions",
      "conditions": [
        {
          "if": "{{!!query.error}}",
          "then": {
            "handler": "sequence",
            "params": {
              "actions": [
                {
                  "handler": "setState",
                  "params": {
                    "target": "local",
                    "errorMessage": "{{query.error === 'not_found' ? '$t:common.not_found' : '$t:common.generic_error'}}"
                  }
                },
                {
                  "handler": "toast",
                  "params": { "type": "error", "message": "{{query.error}}" }
                }
              ]
            }
          }
        }
      ]
    }
  ]
}
```

### 주의사항

- ❌ `init_actions`의 핸들러는 반드시 템플릿의 `handlerMap`에 등록되어 있어야 함
- ❌ 등록되지 않은 핸들러는 경고 로그 출력 후 무시됨
- ✅ 핸들러는 순차적으로 실행됨 (병렬 실행 아님)
- ✅ 핸들러 실행 실패 시 다른 핸들러는 계속 실행됨

---

## Named Actions (named\_actions)

**목적**: 레이아웃 최상위에 재사용 가능한 액션을 정의하고, 컴포넌트에서 `actionRef`로 참조합니다. 동일한 액션이 여러 곳에서 반복될 때 중복을 제거합니다.

> **engine-v1.19.0+** | 상속(extends) 시 자식 레이아웃의 named\_actions가 부모를 오버라이드

### 구조

```json
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
    },
    "resetFilters": {
      "handler": "setState",
      "params": { "target": "_local", "key": "filters", "value": {} }
    }
  }
}
```

### actionRef로 참조

```json
{
  "name": "Button",
  "actions": [{ "type": "click", "actionRef": "searchProducts" }]
}
```

```json
{
  "name": "Input",
  "actions": [{ "type": "keypress", "key": "Enter", "actionRef": "searchProducts" }]
}
```

### 인라인 오버라이드

`actionRef`와 함께 인라인 속성을 지정하면 named\_actions 값을 오버라이드합니다:

```json
{
  "actionRef": "searchProducts",
  "type": "click",
  "params": { "path": "/admin/products/v2" }
}
```

### 규칙

- `named_actions`는 레이아웃 최상위 속성으로 정의 (Partial 파일에서는 정의 불가)
- `actionRef` 사용 시 `handler`/`params`/`target` 생략 가능
- `type`, `key`, `event` 등 이벤트 바인딩 속성은 항상 인라인에서 지정
- 상속 시 동일 키의 named\_actions는 자식이 부모를 완전 교체 (병합 아님)
- DevTools "Named Actions" 탭에서 정의 및 참조 이력 확인 가능

---

## 모달 시스템 (modals)

**목적**: 레이아웃에서 사용할 모달 컴포넌트를 정의합니다. 모달은 `_global.activeModal` 상태에 따라 자동으로 열림/닫힘이 제어됩니다.

### 핵심 원칙

```text
✅ 필수: modals 배열에 Modal 컴포넌트 정의
✅ 필수: 각 모달에 고유한 id 부여
✅ 자동: isOpen, onClose props는 템플릿 엔진이 자동 주입
```

### 구조

```json
{
  "modals": [
    {
      "id": "confirm_modal",
      "type": "composite",
      "name": "Modal",
      "props": {
        "title": "$t:modals.confirm_title",
        "width": "400px"
      },
      "children": [...]
    }
  ]
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `id` | string | ✅ | 모달 고유 ID (openModal 핸들러의 target으로 사용) |
| `type` | string | ✅ | 컴포넌트 타입 (보통 "composite") |
| `name` | string | ✅ | 컴포넌트 이름 (보통 "Modal") |
| `props` | object | ❌ | 모달에 전달할 props (title, width 등) |
| `children` | array | ❌ | 모달 본문에 표시할 자식 컴포넌트 |

### 자동 주입되는 Props

템플릿 엔진이 자동으로 다음 props를 주입합니다:

| Prop | 설명 |
|------|------|
| `isOpen` | `_global.activeModal === modal.id` 조건으로 자동 계산 |
| `onClose` | `closeModal` 핸들러로 자동 연결 |

### 모달 열기/닫기 액션

모달을 제어하려면 `openModal`과 `closeModal` 핸들러를 사용합니다:

```json
{
  "actions": [
    {
      "type": "click",
      "handler": "openModal",
      "target": "confirm_modal"
    }
  ]
}
```

```json
{
  "actions": [
    {
      "type": "click",
      "handler": "closeModal"
    }
  ]
}
```

### 실제 사용 예시

```json
{
  "version": "1.0.0",
  "layout_name": "admin_user_list",
  "modals": [
    {
      "id": "bulk_activate_confirm_modal",
      "type": "composite",
      "name": "Modal",
      "props": {
        "title": "$t:admin.users.modals.bulk_activate_title",
        "width": "400px"
      },
      "children": [
        {
          "id": "activate_message",
          "type": "basic",
          "name": "P",
          "text": "$t:admin.users.modals.bulk_activate_confirm"
        },
        {
          "id": "activate_actions",
          "type": "layout",
          "name": "Container",
          "props": {
            "layout": "flex",
            "justify": "end",
            "gap": "2"
          },
          "children": [
            {
              "id": "cancel_btn",
              "type": "basic",
              "name": "Button",
              "props": {
                "variant": "secondary"
              },
              "text": "$t:common.cancel",
              "actions": [
                {
                  "type": "click",
                  "handler": "closeModal"
                }
              ]
            },
            {
              "id": "confirm_btn",
              "type": "basic",
              "name": "Button",
              "props": {
                "variant": "primary"
              },
              "text": "$t:common.confirm",
              "actions": [
                {
                  "type": "click",
                  "handler": "bulkUpdateStatus",
                  "params": {
                    "status": "active"
                  }
                }
              ]
            }
          ]
        }
      ]
    }
  ],
  "components": [
    {
      "id": "activate_button",
      "type": "basic",
      "name": "Button",
      "text": "$t:admin.users.activate",
      "actions": [
        {
          "type": "click",
          "handler": "openModal",
          "target": "bulk_activate_confirm_modal"
        }
      ]
    }
  ]
}
```

### 레이아웃 상속 시 병합 규칙

modals 배열은 레이아웃 상속 시 다음 규칙으로 병합됩니다:

- **ID 기반 병합**: 동일한 ID의 모달은 자식 레이아웃이 부모를 오버라이드
- **추가**: 부모에 없는 ID의 모달은 자식에서 추가됨
- **순서 유지**: 부모 모달 먼저, 자식 모달 나중에 (ID 중복 시 자식으로 대체)

### 멀티 모달 (중첩 모달) 지원 (engine-v1.2.0+)

모달 스택 시스템을 통해 여러 모달을 동시에 열 수 있습니다:

```text
✅ 확인 모달 위에 에러 모달 중첩 표시 가능
✅ closeModal 시 최상위 모달만 닫히고 이전 모달 유지
✅ 스택 위치에 따른 z-index 자동 관리
```

**중첩 모달 시나리오**:

1. 사용자가 "활성화" 버튼 클릭 → `confirm_modal` 열림
2. 확인 버튼 클릭 → API 호출
3. API 오류 발생 → `error_modal`이 `confirm_modal` 위에 중첩 표시
4. 에러 모달 닫기 → 확인 모달이 다시 보임
5. 확인 모달 닫기 → 모든 모달 닫힘

### 주의사항

- ✅ 모달 ID는 레이아웃 내에서 고유해야 함
- ✅ `isOpen`과 `onClose`는 직접 정의하지 않아도 됨 (자동 주입)
- ✅ 모달 본문에서 데이터 바인딩 사용 가능 (`{{_global.selectedItems.length}}` 등)
- ✅ 멀티 모달 지원 - 모달 내부에서 다른 모달 열기 가능 (engine-v1.2.0+)
- 이미 열려있는 모달은 스택에 중복 추가되지 않음

---

## 컴포넌트 이벤트 구독 (onComponentEvent)

> **버전**: engine-v1.11.0+

**목적**: 다른 컴포넌트에서 `emitEvent`로 발생한 이벤트를 레이아웃 JSON에서 구독합니다. 컴포넌트 마운트 시 자동 구독, 언마운트 시 자동 해제됩니다.

### 핵심 원칙

```text
✅ 컴포넌트 레벨에서 이벤트 구독 정의
✅ 마운트 시 자동 구독, 언마운트 시 자동 해제
✅ 이벤트 데이터는 {{_eventData}}로 접근 가능
✅ onSuccess/onError 콜백 지원
```

### 구조

```json
{
  "id": "logo_preview",
  "type": "basic",
  "name": "Div",
  "onComponentEvent": [
    {
      "event": "upload:site_logo",
      "handler": "refetchDataSource",
      "params": { "id": "site_settings" }
    }
  ],
  "children": [...]
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `event` | string | ✅ | 구독할 이벤트 이름 (예: "upload:site_logo") |
| `handler` | string | ✅ | 이벤트 발생 시 실행할 핸들러 |
| `params` | object | ❌ | 핸들러에 전달할 파라미터 |
| `onSuccess` | object/array | ❌ | 핸들러 성공 시 실행할 후속 액션 |
| `onError` | object/array | ❌ | 핸들러 실패 시 실행할 후속 액션 |

### 이벤트 데이터 접근

이벤트 발생 시 전달된 데이터는 `{{_eventData}}`로 접근할 수 있습니다:

```json
{
  "onComponentEvent": [
    {
      "event": "item:selected",
      "handler": "setState",
      "params": {
        "target": "_local",
        "value": {
          "selectedItem": "{{_eventData.item}}"
        }
      }
    }
  ]
}
```

### 실제 사용 예시

**파일 업로드 후 데이터 새로고침**:

TSX 컴포넌트에서 파일 업로드 완료 후 이벤트를 발생시킵니다:

```typescript
// ImageUploader.tsx
const handleUploadComplete = async (result) => {
  await G7Core.componentEvent.emit('upload:site_logo', {
    url: result.url,
    collection: 'site_logo'
  });
};
```

레이아웃 JSON에서 해당 이벤트를 구독하여 데이터를 새로고침합니다:

```json
{
  "id": "logo_preview_container",
  "type": "basic",
  "name": "Div",
  "onComponentEvent": [
    {
      "event": "upload:site_logo",
      "handler": "refetchDataSource",
      "params": { "id": "site_settings" }
    }
  ],
  "children": [
    {
      "id": "logo_image",
      "type": "basic",
      "name": "Image",
      "props": {
        "src": "{{site_settings.data.logo_url}}"
      }
    }
  ]
}
```

**폼 제출 후 UI 업데이트**:

```json
{
  "id": "form_container",
  "type": "basic",
  "name": "Div",
  "onComponentEvent": [
    {
      "event": "form:submitted",
      "handler": "setState",
      "params": {
        "target": "_local",
        "value": { "showSuccess": true, "formData": "{{_eventData.data}}" }
      }
    }
  ],
  "children": [...]
}
```

### emitEvent와 함께 사용

`emitEvent` 핸들러로 이벤트를 발생시키고 `onComponentEvent`로 구독합니다:

```json
{
  "components": [
    {
      "id": "submit_button",
      "type": "basic",
      "name": "Button",
      "text": "저장",
      "actions": [
        {
          "type": "click",
          "handler": "emitEvent",
          "params": {
            "event": "data:saved",
            "data": { "timestamp": "{{Date.now()}}" }
          }
        }
      ]
    },
    {
      "id": "status_display",
      "type": "basic",
      "name": "Div",
      "onComponentEvent": [
        {
          "event": "data:saved",
          "handler": "toast",
          "params": {
            "type": "success",
            "message": "$t:common.saved_successfully"
          }
        }
      ]
    }
  ]
}
```

### lifecycle.onMount와의 차이

| 속성 | onMount | onComponentEvent |
| --- | --- | --- |
| 실행 시점 | 컴포넌트 마운트 시 1회 | 이벤트 발생 시마다 |
| 트리거 | 자동 (마운트) | emitEvent 또는 TSX emit |
| 데이터 접근 | 컨텍스트 데이터 | `{{_eventData}}` + 컨텍스트 |
| 용도 | 초기화 로직 | 컴포넌트 간 통신 |

### 주의사항

```text
✅ event 이름은 필수 (빈 문자열이면 무시됨)
✅ 컴포넌트 언마운트 시 자동으로 구독 해제
✅ 동일 이벤트에 여러 컴포넌트가 구독 가능
✅ 이벤트 핸들러는 순차적으로 실행됨

G7Core.componentEvent가 없으면 구독 설정 실패 (경고 로그 출력)
핸들러 실행 실패 시 에러 로그 출력 후 다른 구독자는 계속 실행
```

---

## 액션 시스템 (actions)

> **상세 문서**: [액션 핸들러 가이드](actions-handlers.md) - 내장 핸들러 목록, 사용법, onSuccess/onError 배열

### 지원 이벤트 타입

| 이벤트 타입 | React 핸들러 | 설명 |
|------------|-------------|------|
| `click` | `onClick` | 클릭 이벤트 |
| `change` | `onChange` | 값 변경 이벤트 (input, select 등) |
| `input` | `onInput` | 입력 이벤트 |
| `submit` | `onSubmit` | 폼 제출 이벤트 |
| `focus` | `onFocus` | 포커스 획득 |
| `blur` | `onBlur` | 포커스 해제 |
| `keydown` | `onKeyDown` | 키 누름 이벤트 |
| `keyup` | `onKeyUp` | 키 떼기 이벤트 |
| `keypress` | `onKeyPress` | 키 입력 이벤트 (IME 조합 완료 후 발생) |
| `mouseenter` | `onMouseEnter` | 마우스 진입 |
| `mouseleave` | `onMouseLeave` | 마우스 이탈 |

### 액션 정의 구조

```json
{
  "actions": [
    {
      "type": "click",           // 이벤트 타입 (필수)
      "handler": "navigate",     // 액션 핸들러 (필수)
      "target": "/path",         // 타겟 (핸들러별 상이)
      "params": {},              // 파라미터 (핸들러별 상이)
      "key": "Enter",            // 키보드 이벤트 필터 (선택)
      "confirm": "확인 메시지"    // 확인 대화상자 (선택)
    }
  ]
}
```

### 키보드 이벤트 필터링 (`key` 필드)

`keydown`/`keyup`/`keypress` 이벤트에서 특정 키만 반응하도록 `key` 필드를 사용합니다:

```json
{
  "actions": [
    {
      "type": "keydown",
      "key": "Enter",
      "handler": "navigate",
      "params": { "path": "/search?q={{_global.searchQuery}}" }
    }
  ]
}
```

**지원 키 값** (JavaScript `KeyboardEvent.key` 표준):

- `Enter`, `Escape`, `Tab`, `Backspace`, `Delete`
- `ArrowUp`, `ArrowDown`, `ArrowLeft`, `ArrowRight`
- `a` ~ `z`, `0` ~ `9` 등

### 실전 예시: 검색 입력 필드

```json
{
  "id": "search_input",
  "type": "basic",
  "name": "Input",
  "props": {
    "type": "text",
    "placeholder": "검색어 입력..."
  },
  "actions": [
    {
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "global",
        "searchQuery": "{{$event.target.value}}"
      }
    },
    {
      "type": "keydown",
      "key": "Enter",
      "handler": "navigate",
      "params": {
        "path": "/search?q={{_global.searchQuery}}"
      }
    }
  ]
}
```

위 예시에서:
- `change` 이벤트: 입력값을 전역 상태에 저장
- `keydown` + `key: "Enter"`: Enter 키를 누르면 검색 페이지로 이동

---

## 외부 스크립트 로드 (scripts)

> **버전**: engine-v1.8.0+
> **관련 문서**: [액션 핸들러 - callExternal](actions-handlers-ui.md#callexternal)

**목적**: 레이아웃 진입 시 외부 스크립트를 1회만 로드합니다. 버튼 클릭마다 스크립트를 로드하는 것보다 효율적입니다.

### 핵심 원칙

```text
✅ 레이아웃 로드 시 1회만 스크립트 로드 (중복 로드 방지)
✅ if 조건으로 조건부 로드 지원 (플러그인 활성화 상태 등)
✅ 비동기 로드 (async 기본값 true)
✅ 스크립트 로드 완료 후 data_sources fetch 진행
```

### 구조

```json
{
  "extends": "_admin_form",
  "scripts": [
    {
      "src": "//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js",
      "id": "daum_postcode_script",
      "if": "{{_global.installedPlugins?.find(p => p.identifier === 'sirsoft-daum_postcode' && p.status === 'active')}}"
    }
  ],
  "components": [...]
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `src` | string | ✅ | 스크립트 URL (프로토콜 없이 `//`로 시작 권장) |
| `id` | string | ✅ | 스크립트 요소 ID (중복 로드 방지용) |
| `if` | string | ❌ | 조건부 로드 표현식 (예: `{{_global.pluginActive}}`) |
| `async` | boolean | ❌ | 비동기 로드 여부 (기본값: true) |

### 실행 시점

```text
1. 레이아웃 JSON 로드
2. 조건 컨텍스트 구성 (route, query, _global)
3. **scripts 로드** ← 여기서 실행
4. data_sources 조건부 필터링
5. blocking 데이터 소스 fetch
6. 초기 렌더링
```

### 조건부 로드 예시

**플러그인 활성화 상태 체크**:

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

**전역 상태 체크**:

```json
{
  "scripts": [
    {
      "src": "https://cdn.example.com/analytics.js",
      "id": "analytics_script",
      "if": "{{_global.settings?.analytics?.enabled}}"
    }
  ]
}
```

### callExternal과 함께 사용

scripts로 로드된 외부 라이브러리는 `callExternal` 핸들러로 호출합니다:

```json
{
  "scripts": [
    {
      "src": "//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js",
      "id": "daum_postcode_script",
      "if": "{{_global.installedPlugins?.find(p => p.identifier === 'sirsoft-daum_postcode' && p.status === 'active')}}"
    }
  ],
  "components": [
    {
      "id": "btn_search_address",
      "type": "basic",
      "name": "Button",
      "text": "주소 검색",
      "actions": [
        {
          "type": "click",
          "handler": "callExternal",
          "params": {
            "constructor": "daum.Postcode",
            "args": { "oncomplete": true },
            "callbackSetState": {
              "basic_info": {
                "zipcode": "zonecode",
                "base_address": "roadAddress"
              }
            },
            "method": "open"
          }
        }
      ]
    }
  ]
}
```

### callbackSetState 파라미터

> **버전**: engine-v1.8.0+

`callExternal` 핸들러의 `callbackSetState` 파라미터를 사용하면 외부 라이브러리의 콜백 데이터를 폼 필드에 직접 매핑할 수 있습니다.

**중첩 객체 구조** (권장):

```json
{
  "callbackSetState": {
    "basic_info": {
      "zipcode": "zonecode",
      "base_address": "roadAddress"
    }
  }
}
```

위 설정은 다음과 같이 동작합니다:
- `_local.basic_info.zipcode` ← 콜백 데이터의 `zonecode` 값
- `_local.basic_info.base_address` ← 콜백 데이터의 `roadAddress` 값

> **주의**: 트러블슈팅 가이드 사례 7-2에 따라, 같은 루트를 공유하는 dot notation(`basic_info.zipcode`, `basic_info.base_address`) 대신 중첩 객체 구조를 사용합니다.

### 주의사항

```text
✅ 스크립트 ID는 고유해야 함 (동일 ID의 스크립트는 중복 로드되지 않음)
✅ if 조건이 없으면 항상 로드됨
✅ 스크립트 로드 실패는 경고만 출력하고 레이아웃 렌더링은 계속 진행됨

스크립트가 로드되지 않은 상태에서 callExternal 호출 시 에러 발생
비활성화된 플러그인의 스크립트는 로드하지 않도록 if 조건 사용 권장
```

---

## 관련 문서

- [레이아웃 JSON 기능 인덱스](layout-json-features.md)
- [스타일 및 계산된 값](layout-json-features-styling.md)
- [에러 핸들링](layout-json-features-error.md)
- [액션 핸들러](actions-handlers.md)
