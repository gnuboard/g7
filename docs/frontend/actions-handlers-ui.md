# 액션 핸들러 - UI 인터랙션

> **메인 문서**: [actions-handlers.md](actions-handlers.md)

---

## 목차

1. [login / logout](#login--logout)
2. [openModal / closeModal](#openmodal--closemodal)
3. [showAlert / toast](#showalert--toast)
4. [confirm (액션 속성)](#confirm-액션-속성)
5. [switch](#switch)
6. [conditions](#conditions) ⭐ NEW (engine-v1.10.0+)
7. [sequence / parallel](#sequence--parallel)
8. [reloadTranslations](#reloadtranslations)
9. [showErrorPage](#showerrorpage)
10. [scrollIntoView](#scrollintoview) ⭐ NEW (engine-v1.11.0+)
11. [loadScript](#loadscript)
12. [callExternal](#callexternal)
13. [실전 예시](#실전-예시)

---

## login / logout

### login

로그인을 처리하고 토큰을 저장합니다.

```json
{
  "type": "submit",
  "handler": "login",
  "target": "admin",
  "params": {
    "body": {
      "email": "{{form.email}}",
      "password": "{{form.password}}"
    }
  },
  "onSuccess": [
    {
      "handler": "navigate",
      "params": { "path": "/admin/dashboard" }
    }
  ],
  "onError": [
    {
      "handler": "setError",
      "target": "{{error.response.message}}"
    }
  ]
}
```

### target 값

| 값 | 설명 |
|----|------|
| `admin` | 관리자 인증 |
| `user` | 사용자 인증 |

### logout

로그아웃하고 토큰을 삭제합니다.

```json
{
  "type": "click",
  "handler": "logout",
  "onSuccess": [
    {
      "handler": "navigate",
      "params": { "path": "/admin/login" }
    }
  ]
}
```

---

## openModal / closeModal

모달을 열거나 닫습니다. **모달 스택**을 사용하여 중첩 모달을 지원합니다 (engine-v1.2.0+).

```json
{
  "type": "click",
  "handler": "openModal",
  "target": "bulk_activate_confirm_modal"
}
```

```json
{
  "type": "click",
  "handler": "closeModal"
}
```

**작동 원리**:
- `openModal`: `_global.modalStack`에 모달 ID를 push, `_global.activeModal`도 동시 설정
- `closeModal`: `_global.modalStack`에서 최상위 모달만 제거, 이전 모달 유지

**멀티 모달 지원** (engine-v1.2.0+):

```text
✅ 확인 모달 위에 에러 모달 중첩 표시 가능
✅ closeModal 시 최상위 모달만 닫히고 이전 모달 유지
이미 열려있는 모달은 스택에 중복 추가되지 않음
```

→ Modal 컴포넌트 JSON 구조: [modal-usage.md](./modal-usage.md)

---

## showAlert / toast

### showAlert

브라우저 기본 알림(alert)을 표시합니다.

```json
{
  "type": "click",
  "handler": "showAlert",
  "target": "$t:messages.confirm_delete"
}
```

### toast

토스트 알림을 스택 형태로 표시합니다.

```json
{
  "handler": "toast",
  "params": {
    "type": "success",
    "message": "$t:admin.users.modals.bulk_activate_success"
  }
}
```

### toast params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `type` | string | "info" | 토스트 타입 (success, error, warning, info) |
| `message` | string | - | 표시할 메시지 ($t: 다국어 지원) |
| `icon` | string | 타입별 기본값 | 커스텀 아이콘 이름 |
| `duration` | number | 3000 | 자동 닫힘 시간 (ms), 0이면 자동 닫힘 비활성화 |

---

## confirm (액션 속성)

> **주의**: `confirm`은 핸들러가 아니라 **액션 속성**입니다. `"handler": "confirm"`으로 사용하면 "Unknown action handler" 오류가 발생합니다.

액션 실행 전 브라우저 기본 확인 대화상자(`window.confirm()`)를 표시합니다. 사용자가 "확인"을 클릭하면 핸들러가 실행되고, "취소"를 클릭하면 실행을 중단합니다.

### 기본 사용법

```json
{
  "type": "click",
  "handler": "apiCall",
  "target": "/api/admin/items/{{row.id}}",
  "params": { "method": "DELETE" },
  "confirm": "$t:common.confirm_delete"
}
```

### 커스텀 핸들러와 함께 사용

```json
{
  "type": "click",
  "handler": "sirsoft-ecommerce.removeCountrySetting",
  "confirm": "$t:sirsoft-ecommerce.admin.shipping_policy.form.country_tab_remove_confirm",
  "params": {
    "index": "{{_local.activeCountryTab ?? 0}}"
  }
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `confirm` | string | ❌ | 확인 대화상자에 표시할 메시지 (`$t:` 다국어 지원) |

### 작동 원리

1. 액션이 트리거되면 `confirm` 속성이 있는지 확인
2. `$t:` 접두사가 있으면 다국어 번역 수행
3. `window.confirm(message)` 호출
4. 사용자가 "확인" → 핸들러 실행
5. 사용자가 "취소" → 핸들러 실행 중단 (아무 동작 없음)

### 주의사항

```text
❌ 잘못됨: "handler": "confirm" → Unknown action handler 오류
✅ 올바름: "handler": "실제핸들러", "confirm": "메시지"

❌ 잘못됨: params.onConfirm 콜백 구조
✅ 올바름: confirm 속성 + handler에 실제 실행할 핸들러 지정
```

---

## switch

조건에 따라 다른 액션을 실행합니다.

### 기본 사용법 ($args[0] 기반)

이벤트 핸들러에서 전달된 인자를 케이스 키로 사용합니다.

```json
{
  "event": "onRowAction",
  "type": "action",
  "handler": "switch",
  "cases": {
    "view": {
      "type": "click",
      "handler": "navigate",
      "params": { "path": "/admin/users/{{$args[1].id}}" }
    },
    "edit": {
      "type": "click",
      "handler": "navigate",
      "params": { "path": "/admin/users/{{$args[1].id}}/edit" }
    },
    "delete": {
      "type": "click",
      "handler": "openModal",
      "target": "delete_confirm_modal"
    }
  }
}
```

### params.value 지원 (engine-v1.9.0+)

`params.value`를 통해 동적으로 케이스 키를 지정할 수 있습니다. 데이터 바인딩을 사용하여 플러그인/모듈 환경설정 값을 케이스 키로 활용할 수 있습니다.

```json
{
  "type": "click",
  "handler": "switch",
  "params": {
    "value": "{{_global.plugins['sirsoft-daum_postcode']?.display_mode ?? 'layer'}}"
  },
  "cases": {
    "popup": {
      "handler": "callExternal",
      "params": { "constructor": "daum.Postcode", "method": "open" }
    },
    "layer": {
      "handler": "callExternalEmbed",
      "params": { "constructor": "daum.Postcode" }
    },
    "default": {
      "handler": "callExternalEmbed",
      "params": { "constructor": "daum.Postcode" }
    }
  }
}
```

### default 케이스 지원 (engine-v1.9.0+)

매칭되는 케이스가 없을 경우 `default` 케이스가 실행됩니다.

```json
{
  "handler": "switch",
  "params": { "value": "{{_global.theme}}" },
  "cases": {
    "dark": { "handler": "setState", "params": { "colorScheme": "dark" } },
    "light": { "handler": "setState", "params": { "colorScheme": "light" } },
    "default": { "handler": "setState", "params": { "colorScheme": "auto" } }
  }
}
```

### 작동 원리

1. 케이스 키 결정 (우선순위)
   - `params.value` 값 사용 (데이터 바인딩 지원)
   - `params.value`가 없으면 `$args[0]` 값 사용
2. `cases`에서 해당 키에 매칭되는 액션 정의를 찾음
3. 매칭되는 케이스가 없으면 `default` 케이스 실행
4. `default` 케이스도 없으면 아무 동작 없음

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `params.value` | string | ❌ | 케이스 키 값 (데이터 바인딩 지원, engine-v1.9.0+) |
| `cases` | object | ✅ | 케이스별 액션 정의 |
| `cases.default` | object | ❌ | 기본 케이스 (매칭 없을 때 실행, engine-v1.9.0+) |

---

## conditions

> **버전**: engine-v1.10.0+

조건에 따라 다른 액션을 실행합니다. `switch`의 확장판으로, AND/OR 그룹과 if-else 체인을 지원합니다.

### 기본 사용법 (if-else 체인)

```json
{
  "type": "click",
  "handler": "conditions",
  "conditions": [
    {
      "if": "{{$args[0] === 'edit'}}",
      "then": { "handler": "navigate", "params": { "path": "/edit/{{row.id}}" } }
    },
    {
      "if": "{{$args[0] === 'delete'}}",
      "then": { "handler": "openModal", "params": { "id": "delete_confirm_modal" } }
    },
    {
      "then": { "handler": "toast", "params": { "message": "알 수 없는 액션" } }
    }
  ]
}
```

### 타입 정의

```typescript
type ConditionExpression =
  | string                              // 단순 표현식: "{{user.isAdmin}}"
  | { and: ConditionExpression[] }      // AND 그룹: 모든 조건 true → true
  | { or: ConditionExpression[] };      // OR 그룹: 하나라도 true → true

interface ConditionBranch {
  if?: ConditionExpression;             // 조건 (없으면 else 브랜치)
  then?: ActionDefinition | ActionDefinition[];  // 실행할 액션
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `conditions` | ConditionBranch[] | ✅ | if-else 브랜치 배열 |
| `conditions[].if` | ConditionExpression | ❌ | 조건 표현식 (없으면 else 브랜치) |
| `conditions[].then` | ActionDefinition \| ActionDefinition[] | ✅ | 조건 충족 시 실행할 액션 |

### AND 조건으로 액션 실행

모든 조건이 true일 때만 해당 브랜치가 실행됩니다.

```json
{
  "type": "click",
  "handler": "conditions",
  "conditions": [
    {
      "if": {
        "and": ["{{user.isLoggedIn}}", "{{user.hasPermission}}"]
      },
      "then": { "handler": "navigate", "params": { "path": "/premium" } }
    },
    {
      "then": { "handler": "toast", "params": { "type": "error", "message": "접근 권한 없음" } }
    }
  ]
}
```

### OR 조건으로 액션 실행

하나라도 true면 해당 브랜치가 실행됩니다.

```json
{
  "type": "click",
  "handler": "conditions",
  "conditions": [
    {
      "if": {
        "or": ["{{user.isAdmin}}", "{{user.isManager}}"]
      },
      "then": { "handler": "navigate", "params": { "path": "/admin" } }
    },
    {
      "then": { "handler": "navigate", "params": { "path": "/user" } }
    }
  ]
}
```

### 중첩 AND/OR 조건

복잡한 조건 로직을 표현할 수 있습니다.

```json
{
  "type": "click",
  "handler": "conditions",
  "conditions": [
    {
      "if": {
        "or": [
          "{{user.isSuperAdmin}}",
          {
            "and": ["{{user.isAdmin}}", "{{user.department === 'sales'}}"]
          }
        ]
      },
      "then": { "handler": "navigate", "params": { "path": "/sales-dashboard" } }
    },
    {
      "then": { "handler": "navigate", "params": { "path": "/home" } }
    }
  ]
}
```

### then 배열 (순차 실행)

`then`이 배열이면 `sequence`처럼 순차적으로 실행됩니다.

```json
{
  "type": "click",
  "handler": "conditions",
  "conditions": [
    {
      "if": "{{$args[0] === 'delete'}}",
      "then": [
        { "handler": "setState", "params": { "target": "_local", "deleteTargetId": "{{row.id}}" } },
        { "handler": "openModal", "params": { "id": "delete_confirm_modal" } }
      ]
    }
  ]
}
```

### switch vs conditions 비교

| 특성 | switch | conditions |
|------|--------|------------|
| 케이스 매칭 | 값 일치 (`$args[0]` 또는 `params.value`) | 조건 표현식 평가 |
| AND/OR 조건 | ❌ 미지원 | ✅ 지원 |
| 중첩 조건 | ❌ 미지원 | ✅ 지원 |
| else 브랜치 | `default` 케이스 | `if` 없는 브랜치 |
| then 배열 | ❌ 미지원 | ✅ 지원 (순차 실행) |

### switch vs conditions 선택 가이드

| 상황 | 권장 핸들러 |
|------|-------------|
| 단순 값 매칭 (edit/delete/view) | `switch` |
| 복합 조건 (AND/OR) | `conditions` |
| 중첩 조건 | `conditions` |
| 여러 액션 순차 실행 | `conditions` (then 배열) |

### DataGrid onRowAction 실전 예시

```json
{
  "event": "onRowAction",
  "type": "action",
  "handler": "conditions",
  "conditions": [
    {
      "if": "{{$args[0] === 'edit'}}",
      "then": { "handler": "navigate", "params": { "path": "/products/edit/{{$args[1].id}}" } }
    },
    {
      "if": "{{$args[0] === 'delete'}}",
      "then": [
        { "handler": "setState", "params": { "target": "_local", "deleteTargetId": "{{$args[1].id}}" } },
        { "handler": "openModal", "params": { "id": "delete_confirm_modal" } }
      ]
    },
    {
      "if": "{{$args[0] === 'view'}}",
      "then": { "handler": "navigate", "params": { "path": "/products/view/{{$args[1].id}}" } }
    },
    {
      "then": { "handler": "toast", "params": { "type": "warning", "message": "알 수 없는 액션입니다." } }
    }
  ]
}
```

---

## sequence / parallel

### sequence

여러 액션을 순차적으로 실행합니다.

```json
{
  "type": "click",
  "handler": "sequence",
  "actions": [
    {
      "handler": "setState",
      "params": { "target": "global", "selectedModule": "{{row}}" }
    },
    {
      "handler": "openModal",
      "target": "module_install_modal"
    }
  ]
}
```

### 결과 전달 컨텍스트

| 변수 | 설명 |
|------|------|
| `$prev` | 직전 액션의 결과 |
| `$results` | 모든 이전 결과 배열 |
| `$results[0]` | 특정 인덱스의 결과 접근 |

### parallel

여러 액션을 **병렬로** 실행합니다.

```json
{
  "handler": "parallel",
  "actions": [
    { "handler": "toast", "params": { "type": "success", "message": "완료!" } },
    { "handler": "refetchDataSource", "params": { "dataSourceId": "modules" } },
    { "handler": "refetchDataSource", "params": { "dataSourceId": "admin_menu" } }
  ]
}
```

### sequence vs parallel 비교

| 특성 | sequence | parallel |
|------|----------|----------|
| 실행 방식 | 순차 | 병렬 |
| 에러 처리 | 중간 실패 시 중단 | 일부 실패해도 계속 |
| 결과 전달 | `$prev`, `$results` 사용 | 결과 전달 불가 |

### sequence 고급 동작 (engine-v1.11.0+)

#### 스텝 간 상태 동기화

sequence 내에서 `setState` 실행 후 다음 스텝은 **갱신된 상태**를 참조합니다. 이는 `_computed` 속성도 포함합니다:

```text
1. setState로 _local.price = 500 설정
2. → _computed.totalPrice 자동 재계산 (skipCache: true)
3. → 다음 스텝에서 {{_computed.totalPrice}} 참조 시 최신값 반영
```

#### $prev와 $results 상세

| 변수 | 타입 | 설명 |
|------|------|------|
| `$prev` | any | **직전** 액션의 결과값 (직접 참조, 배열 아님) |
| `$results` | array | **모든** 이전 액션 결과의 배열 |
| `$results[0]` | any | 첫 번째 액션의 결과 |

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
        "productName": "{{$prev.data.name}}",
        "firstResult": "{{$results[0].data.id}}"
      }
    }
  ]
}
```

#### 커스텀 핸들러와 __g7SequenceLocalSync

커스텀 핸들러(React 컴포넌트 내부에서 등록한 핸들러)가 sequence 내에서 `_local` 상태를 변경하면, 다음 스텝에서 해당 변경이 반영되지 않을 수 있습니다. 이를 해결하기 위해 `__g7SequenceLocalSync` 메커니즘이 사용됩니다:

```text
✅ 내장 핸들러 (setState, apiCall 등): 자동으로 상태 동기화
커스텀 핸들러: __g7SequenceLocalSync 콜백을 통해 명시적으로 상태 동기화 필요
✅ 상세: g7core-api-advanced.md 참조
```

#### _isolated 상태 추적

sequence 내에서 `target: "isolated"` setState가 실행되면, 이후 스텝에서 `_isolated` 상태도 최신값으로 추적됩니다.

---

## reloadTranslations

> **Deprecated** (engine-v1.38.0+): 모듈/플러그인/템플릿 라이프사이클 onSuccess 에서는 `reloadExtensions` 사용을 권장합니다. 하위 호환을 위해 유지되며 내부적으로 `TemplateApp.reloadExtensionState()` 로 위임되어 routes/translations/layouts 가 함께 재동기화됩니다. 자세한 내용은 [`reloadExtensions`](actions-handlers-navigation.md#reloadextensions) 참조.

다국어 파일을 다시 로드합니다.

```json
{
  "handler": "reloadTranslations"
}
```

### 사용 사례

- 언어 설정 변경 후 번역 적용 (단독 사용)
- (deprecated) 모듈/플러그인 설치 후 새 번역 적용 → `reloadExtensions` 사용

---

## showErrorPage

> **버전**: engine-v1.6.0+

에러 페이지를 렌더링합니다. URL 변경 없이 현재 페이지에서 에러 페이지를 표시합니다.

```json
{
  "handler": "showErrorPage",
  "params": {
    "errorCode": 403,
    "target": "content"
  }
}
```

### showErrorPage params 구조

| 필드 | 타입 | 필수 | 기본값 | 설명 |
|------|------|------|--------|------|
| `errorCode` | number | ❌ | errorHandling 키값 | 에러 코드 |
| `target` | string | ❌ | `"content"` | `"full"` 또는 `"content"` |
| `containerId` | string | ❌ | - | 렌더링할 컨테이너 ID |
| `layout` | string | ❌ | - | 사용할 레이아웃 경로 |

---

## scrollIntoView

> **버전**: engine-v1.11.0+

특정 요소를 화면에 보이도록 스크롤합니다. MutationObserver를 통한 요소 대기와 컨테이너 내 스크롤을 지원합니다.

### 기본 사용법

```json
{
  "type": "click",
  "handler": "scrollIntoView",
  "params": {
    "selector": "#target_element",
    "behavior": "smooth",
    "block": "center"
  }
}
```

### scrollIntoView params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `selector` | string | - | 스크롤할 대상 요소 CSS 선택자 (필수) |
| `scrollContainer` | string | - | 스크롤 컨테이너 선택자 (지정 시 해당 컨테이너만 스크롤) |
| `behavior` | string | `"smooth"` | 스크롤 동작 (`smooth`, `instant`, `auto`) |
| `block` | string | `"nearest"` | 수직 정렬 (`start`, `center`, `end`, `nearest`) |
| `inline` | string | `"nearest"` | 수평 정렬 (`start`, `center`, `end`, `nearest`) |
| `waitForElement` | boolean | `false` | MutationObserver로 요소 대기 |
| `timeout` | number | `2000` | waitForElement 타임아웃 (ms) |
| `delay` | number | `0` | 실행 전 지연 (ms) |
| `retryCount` | number | `0` | 재시도 횟수 |
| `retryInterval` | number | `50` | 재시도 간격 (ms) |

### 요소 대기 (waitForElement)

조건부 렌더링(`if`)으로 나타나는 요소를 대기할 때 사용합니다. MutationObserver를 사용하여 DOM에 요소가 추가될 때까지 대기합니다.

```json
{
  "handler": "scrollIntoView",
  "params": {
    "selector": "#loading_indicator",
    "waitForElement": true,
    "timeout": 2000,
    "block": "end"
  }
}
```

### 컨테이너 내 스크롤 (scrollContainer)

특정 스크롤 컨테이너 내에서만 스크롤하고 브라우저 전체 스크롤에 영향을 주지 않으려면 `scrollContainer`를 지정합니다.

```json
{
  "handler": "scrollIntoView",
  "params": {
    "selector": "#loading_indicator",
    "scrollContainer": "#template_list",
    "behavior": "smooth",
    "block": "end"
  }
}
```

**작동 원리**:
- `scrollContainer` 미지정: 네이티브 `Element.scrollIntoView()` 사용 (모든 조상 스크롤 영향)
- `scrollContainer` 지정: 해당 컨테이너의 `scrollTop`만 직접 조정 (브라우저 스크롤 유지)

### 무한스크롤 로딩 인디케이터 예시

```json
{
  "id": "template_list",
  "type": "basic",
  "name": "Div",
  "props": {
    "className": "max-h-[calc(90vh-140px)] overflow-y-auto"
  },
  "actions": [
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
                  "handler": "scrollIntoView",
                  "params": {
                    "selector": "#loading_indicator",
                    "scrollContainer": "#template_list",
                    "behavior": "smooth",
                    "block": "end",
                    "waitForElement": true,
                    "timeout": 2000
                  }
                },
                {
                  "handler": "apiCall",
                  "target": "/api/items",
                  "params": {
                    "method": "GET",
                    "query": { "page": "{{_global.infiniteScroll.currentPage + 1}}" }
                  },
                  "onSuccess": [
                    {
                      "handler": "appendDataSource",
                      "params": {
                        "dataSourceId": "items",
                        "dataPath": "data",
                        "newData": "{{response.data}}"
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
  ],
  "children": [
    {
      "id": "loading_indicator",
      "type": "composite",
      "name": "LoadingSpinner",
      "if": "{{_global.infiniteScroll.isLoadingMore}}",
      "props": {
        "size": "sm",
        "text": "$t:common.loading"
      }
    }
  ]
}
```

### block 옵션 비교

| 값 | 설명 |
|----|------|
| `start` | 요소가 컨테이너 상단에 오도록 스크롤 |
| `center` | 요소가 컨테이너 중앙에 오도록 스크롤 |
| `end` | 요소가 컨테이너 하단에 오도록 스크롤 |
| `nearest` | 가장 가까운 방향으로 최소 스크롤 (이미 보이면 스크롤 안 함) |

---

## loadScript

> **버전**: engine-v1.7.0+

외부 스크립트를 동적으로 로드합니다. 외부 서비스(Daum 우편번호, 결제 SDK 등) 연동 시 사용합니다.

```json
{
  "type": "click",
  "handler": "loadScript",
  "params": {
    "src": "//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js",
    "id": "daum_postcode_script"
  },
  "onLoad": {
    "handler": "setState",
    "params": { "daumPostcodeLoaded": true }
  }
}
```

### loadScript params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `src` | string | - | 스크립트 URL (필수) |
| `id` | string | 자동 생성 | 스크립트 요소 ID |
| `async` | boolean | true | 비동기 로드 여부 |
| `defer` | boolean | false | defer 속성 |

### onLoad 콜백

스크립트 로드 완료 시 실행할 액션을 `onLoad`에 정의합니다.

```json
{
  "handler": "loadScript",
  "params": {
    "src": "https://example.com/sdk.js"
  },
  "onLoad": {
    "handler": "callExternal",
    "params": {
      "constructor": "ExampleSDK",
      "method": "init"
    }
  }
}
```

### 중복 로드 방지

동일한 ID의 스크립트가 이미 로드되었거나 DOM에 존재하면 다시 로드하지 않고 `onLoad`만 즉시 실행합니다.

```text
✅ 스크립트 중복 로드 자동 방지
✅ 이미 로드된 경우 onLoad 즉시 실행
```

---

## callExternal

> **버전**: engine-v1.7.0+

외부 스크립트의 생성자나 메서드를 호출합니다. `loadScript`로 로드한 외부 라이브러리를 호출할 때 사용합니다.

```json
{
  "type": "click",
  "handler": "callExternal",
  "params": {
    "constructor": "daum.Postcode",
    "args": { "oncomplete": true },
    "callbackEvent": "postcode:complete",
    "method": "open"
  }
}
```

### callExternal params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `constructor` | string | - | 호출할 생성자 경로 (필수, 예: "daum.Postcode") |
| `args` | object | {} | 생성자에 전달할 인자 객체 |
| `method` | string | - | 인스턴스 생성 후 호출할 메서드 (예: "open") |
| `methodArgs` | array | [] | 메서드에 전달할 인자 배열 |
| `callbackEvent` | string | - | 콜백 결과를 전달할 이벤트명 |
| `embedTarget` | string | - | embed 메서드 사용 시 대상 요소 선택자 |

### 콜백 처리

`args`에서 `true`로 설정된 속성은 콜백 함수로 변환됩니다. 콜백 호출 시 `callbackEvent`로 지정한 이벤트가 발생합니다.

```json
{
  "handler": "callExternal",
  "params": {
    "constructor": "daum.Postcode",
    "args": {
      "oncomplete": true
    },
    "callbackEvent": "postcode:complete"
  }
}
```

위 설정은 다음과 동일합니다:

```javascript
new daum.Postcode({
  oncomplete: (data) => {
    G7Core.componentEvent.emit('postcode:complete', data);
  }
}).open();
```

### 이벤트 구독

콜백 결과는 `G7Core.componentEvent`로 전달됩니다. 컴포넌트에서 `onEvent`로 구독할 수 있습니다:

```json
{
  "actions": [
    {
      "event": "postcode:complete",
      "type": "action",
      "handler": "setState",
      "params": {
        "zonecode": "{{$args[0].zonecode}}",
        "address": "{{$args[0].address}}"
      }
    }
  ]
}
```

### embed 모드

팝업 대신 특정 요소에 임베드하려면 `embedTarget`을 사용합니다:

```json
{
  "handler": "callExternal",
  "params": {
    "constructor": "daum.Postcode",
    "args": { "oncomplete": true },
    "callbackEvent": "postcode:complete",
    "method": "embed",
    "embedTarget": "#postcode_container"
  }
}
```

### Daum 우편번호 연동 전체 예시

```json
{
  "id": "search_address_button",
  "type": "basic",
  "name": "Button",
  "text": "$t:common.search_address",
  "actions": [
    {
      "type": "click",
      "handler": "sequence",
      "actions": [
        {
          "handler": "loadScript",
          "params": {
            "src": "//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js",
            "id": "daum_postcode"
          }
        },
        {
          "handler": "callExternal",
          "params": {
            "constructor": "daum.Postcode",
            "args": { "oncomplete": true },
            "callbackEvent": "postcode:complete",
            "method": "open"
          }
        }
      ]
    }
  ]
},
{
  "id": "address_form",
  "type": "basic",
  "name": "Div",
  "actions": [
    {
      "event": "postcode:complete",
      "type": "action",
      "handler": "setState",
      "params": {
        "zonecode": "{{$args[0].zonecode}}",
        "address": "{{$args[0].address}}",
        "roadAddress": "{{$args[0].roadAddress}}"
      }
    }
  ]
}
```

---

## 실전 예시

### 사용자 일괄 활성화 모달

```json
{
  "id": "bulk_activate_confirm_modal",
  "type": "composite",
  "name": "Modal",
  "props": {
    "title": "$t:admin.users.modals.bulk_activate_title",
    "size": "small"
  },
  "children": [
    {
      "type": "basic",
      "name": "P",
      "text": "$t:admin.users.modals.bulk_activate_confirm|count={{(_global.selectedIds || []).length}}"
    },
    {
      "type": "basic",
      "name": "Div",
      "props": { "className": "flex justify-center gap-4 mt-4" },
      "children": [
        {
          "type": "basic",
          "name": "Button",
          "props": { "variant": "secondary" },
          "text": "$t:common.cancel",
          "actions": [
            { "type": "click", "handler": "closeModal" }
          ]
        },
        {
          "type": "basic",
          "name": "Button",
          "props": { "variant": "primary" },
          "text": "$t:common.confirm",
          "actions": [
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
          ]
        }
      ]
    }
  ]
}
```

### 검색 필드 (Enter 키 검색)

```json
{
  "id": "search_input",
  "type": "basic",
  "name": "Input",
  "props": {
    "type": "text",
    "placeholder": "$t:admin.users.search_placeholder",
    "value": "{{_global.searchQuery || query.search || ''}}"
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
      "type": "keypress",
      "key": "Enter",
      "handler": "navigate",
      "params": {
        "path": "/admin/users",
        "mergeQuery": true,
        "query": {
          "search": "{{_global.searchQuery}}"
        }
      }
    }
  ]
}
```

---

## 관련 문서

- [액션 핸들러 인덱스](actions-handlers.md)
- [네비게이션 핸들러](actions-handlers-navigation.md)
- [상태 핸들러](actions-handlers-state.md)
- [컴포넌트 이벤트](components.md#컴포넌트-간-이벤트-통신)
