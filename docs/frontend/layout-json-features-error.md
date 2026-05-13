# 레이아웃 JSON - 에러 핸들링

> **메인 문서**: [layout-json-features.md](layout-json-features.md)
> **관련 문서**: [액션 핸들러 - 에러 핸들링 시스템](actions-handlers-state.md#에러-핸들링-시스템-errorhandling) | [데이터 소스 - 에러 핸들링](data-sources.md#에러-핸들링-설정-v160)

---

## 에러 핸들링 설정 (errorHandling)

> **버전**: engine-v1.6.0+

**목적**: 레이아웃 또는 템플릿 레벨에서 공통 에러 핸들링을 정의합니다. 개별 액션이나 데이터 소스에서 처리되지 않은 에러에 대한 폴백으로 동작합니다.

### 핵심 원칙

```text
✅ 계층적 설정: 템플릿 → 레이아웃 → 액션/데이터소스 (우선순위 역순)
✅ 다양한 표시 방식: showErrorPage, toast, openModal, navigate
✅ 기존 액션 시스템 활용: sequence, parallel 지원
✅ 모든 HTTP 에러 코드 지원: 400, 401, 403, 404, 422, 429, 500, 503 등
```

### 우선순위

```text
액션/데이터소스 errorHandling[코드] > errorHandling[default] > onError > 레이아웃 errorHandling > 템플릿 errorHandling > 시스템 기본값
```

### 레이아웃 레벨 설정

```json
{
  "version": "1.0.0",
  "layout_name": "_admin_base",
  "extends": null,
  "errorHandling": {
    "403": {
      "handler": "toast",
      "params": { "type": "warning", "message": "$t:errors.forbidden" }
    },
    "404": {
      "handler": "showErrorPage",
      "params": { "target": "content" }
    },
    "500": {
      "handler": "showErrorPage",
      "params": { "target": "content" }
    }
  },
  "data_sources": [],
  "components": []
}
```

### 템플릿 레벨 설정 (template.json)

템플릿의 `template.json`에서 전역 에러 핸들링을 정의할 수 있습니다.

```json
{
  "id": "sirsoft-admin_basic",
  "name": "Admin Basic",
  "errorHandling": {
    "401": {
      "handler": "navigate",
      "params": { "path": "/admin/login" }
    },
    "403": {
      "handler": "openModal",
      "target": "forbidden_modal"
    },
    "404": {
      "handler": "navigate",
      "params": { "path": "/admin/404" }
    },
    "422": {
      "handler": "toast",
      "params": { "type": "error", "message": "{{error.message}}" }
    },
    "500": {
      "handler": "navigate",
      "params": { "path": "/admin/500" }
    },
    "default": {
      "handler": "toast",
      "params": { "type": "error", "message": "{{error.message}}" }
    }
  }
}
```

### 처리 흐름

```text
API 에러 발생
     ↓
액션/데이터소스에 errorHandling 있음?
     ├── ✅ → 해당 설정으로 처리 (종료)
     ↓ ❌
레이아웃에 errorHandling 있음?
     ├── ✅ → 해당 설정으로 처리 (종료)
     ↓ ❌
템플릿에 errorHandling 있음?
     ├── ✅ → 해당 설정으로 처리 (종료)
     ↓ ❌
시스템 기본값 적용 (toast)
```

### 사용 가능한 핸들러

| 핸들러 | 설명 |
|--------|------|
| `navigate` | 페이지 이동 |
| `openModal` | 모달 열기 |
| `toast` | 토스트 알림 |
| `setState` | 상태 변경 |
| `sequence` | 순차 실행 |
| `parallel` | 병렬 실행 |
| `showErrorPage` | 에러 페이지 렌더링 |

### sequence/parallel 지원

```json
{
  "errorHandling": {
    "500": {
      "handler": "parallel",
      "actions": [
        {
          "handler": "toast",
          "params": { "type": "error", "message": "$t:errors.server_error" }
        },
        {
          "handler": "openModal",
          "target": "error_report_modal"
        }
      ]
    }
  }
}
```

### 에러 컨텍스트 변수

핸들러 params에서 사용 가능한 변수:

| 변수 | 설명 |
|------|------|
| `{{error.status}}` | HTTP 상태 코드 |
| `{{error.message}}` | 에러 메시지 |
| `{{error.errors}}` | 필드별 에러 (422) |
| `{{error.data}}` | 전체 응답 데이터 |

### 시스템 기본값

`errorHandling` 설정이 어디에도 없을 경우 사용되는 기본값:

```typescript
const DEFAULT_ERROR_HANDLING = {
  401: {
    handler: 'navigate',
    params: { path: '{{auth.loginPath}}' }
  },
  403: {
    handler: 'toast',
    params: { type: 'error', message: '{{error.message}}' }
  },
  404: {
    handler: 'toast',
    params: { type: 'error', message: '{{error.message}}' }
  },
  422: {
    handler: 'toast',
    params: { type: 'error', message: '{{error.message}}' }
  },
  default: {
    handler: 'toast',
    params: { type: 'error', message: '{{error.message}}' }
  }
};
```

### 레이아웃 상속 시 병합 규칙

`errorHandling`은 레이아웃 상속 시 다음 규칙으로 병합됩니다:

- **에러 코드 기반 병합**: 동일한 에러 코드에 대해 자식 레이아웃이 부모를 오버라이드
- **추가**: 부모에 없는 에러 코드는 자식에서 추가됨
- **default 상속**: 자식에 default가 없으면 부모의 default 사용

```json
// 부모 레이아웃 (_admin_base.json)
{
  "errorHandling": {
    "403": { "handler": "toast", "params": { "message": "권한 없음" } },
    "500": { "handler": "showErrorPage", "params": { "target": "full" } }
  }
}

// 자식 레이아웃 (admin_user_list.json)
{
  "extends": "_admin_base",
  "errorHandling": {
    "403": { "handler": "openModal", "target": "forbidden_modal" },
    "404": { "handler": "toast", "params": { "message": "사용자를 찾을 수 없습니다" } }
  }
}

// 병합 결과
{
  "errorHandling": {
    "403": { "handler": "openModal", "target": "forbidden_modal" },  // 자식으로 오버라이드
    "404": { "handler": "toast", "params": { "message": "사용자를 찾을 수 없습니다" } },  // 자식에서 추가
    "500": { "handler": "showErrorPage", "params": { "target": "full" } }  // 부모에서 상속
  }
}
```

### fallback과의 관계

데이터 소스에 `fallback`과 `errorHandling`이 함께 정의된 경우:

```text
✅ errorHandling이 먼저 실행됨 (toast, showErrorPage, navigate 등)
✅ 그 다음 fallback 데이터가 렌더링에 사용됨
fallback이 있어도 errorHandling은 건너뛰지 않음
```

### 에러 전파 방지 (suppress 핸들러)

> @since engine-v1.21.0

특정 에러 코드가 **상위 레벨(레이아웃/템플릿)로 전파되지 않도록** 의도적으로 차단할 때 사용합니다.

**대표 사례**: 비회원의 `/api/auth/user` 401 응답은 정상 동작이므로, 레이아웃의 `errorHandling.401`(showErrorPage 등)로 전파되면 안 됩니다.

```json
{
  "id": "current_user",
  "type": "api",
  "endpoint": "/api/auth/user",
  "auth_required": true,
  "fallback": { "data": [] },
  "errorHandling": {
    "401": {
      "comment": "비회원 401은 정상 — 전파 방지",
      "handler": "suppress"
    }
  }
}
```

**동작 원리**:
- `suppress`는 `ErrorHandlingResolver`에서 truthy 핸들러로 인식 → `level: 'action'`으로 resolve
- 상위 레벨(레이아웃/템플릿) errorHandling으로 전파되지 않음
- `ActionDispatcher`에서 로그만 출력하는 no-op 핸들러로 실행

**사용 가능 위치**:
- 데이터소스 `errorHandling`
- `apiCall` 핸들러의 `errorHandling`
- `default` 키와 함께 사용 가능 (모든 에러 코드 전파 방지)

```text
✅ 에러 전파 방지가 목적일 때 suppress 사용 (무의미한 setState 대신)
✅ fallback과 함께 사용 — suppress로 전파 방지 + fallback으로 기본값 렌더링
실제 에러 처리가 필요한 경우에는 suppress 대신 적절한 핸들러 사용
```

### 에러 페이지에서의 상태 초기화 (engine-v1.28.1+)

에러 페이지(404, 403, 500 등)에서도 `initGlobal`/`initLocal` 매핑이 정상 처리됩니다.

`ErrorPageHandler.renderError()`는 다음 순서로 동작합니다:

1. **error_config 로드** — 에러 코드별 레이아웃 매핑 정보
2. **레이아웃 로드** — `LayoutLoader`를 통한 에러 레이아웃 JSON 로드 (extends 병합 자동 처리)
3. **data_sources fetch** — blocking + progressive 데이터 소스를 모두 fetch (에러 페이지에서는 모든 데이터 로드 후 렌더링)
4. **`processInitOptions()` 호출** — fetch된 데이터를 `initGlobal`/`initLocal` 매핑에 따라 `_global`/`_local`에 저장
5. **렌더링** — `errorCode` + `fetchedData` + `_global` + `_local`을 dataContext로 전달하여 렌더링

이를 통해 에러 페이지에서도:
- `_global.currentUser` 등 인증 상태가 사용 가능
- 에러 페이지 레이아웃에서 헤더/로그인 상태 표시가 정상 동작
- `initGlobal`의 문자열/배열/객체(`{ key, path }`) 형태 모두 지원

```json
{
  "data_sources": [
    {
      "id": "current_user",
      "endpoint": "/api/auth/user",
      "auth_required": true,
      "fallback": { "data": [] },
      "initGlobal": "currentUser",
      "errorHandling": {
        "401": { "handler": "suppress" }
      }
    }
  ]
}
```

> 참고: `processInitOptions()`는 `TemplateApp.processInitOptions()`의 간소화 버전으로, 에러 페이지에서 필요한 형태만 지원합니다. 데이터 fetch 실패 시에도 에러 페이지 렌더링은 중단되지 않습니다.

### 주의사항

```text
✅ 레이아웃 레벨 errorHandling은 해당 레이아웃 내 모든 액션/데이터소스에 적용
✅ 템플릿 레벨 errorHandling은 해당 템플릿의 모든 레이아웃에 적용
✅ 개별 액션/데이터소스에서 오버라이드 가능

extends로 상속받은 레이아웃의 errorHandling도 병합됨
순환 참조 시 상위 레벨 errorHandling 무시됨
```

---

## 배열형 onSuccess/onError 패턴

데이터 소스 및 apiCall 핸들러의 `onSuccess`/`onError`에서 배열 형태로 여러 액션을 순차 실행할 수 있습니다. 이는 `sequence` 핸들러와 동일한 동작이며, `$prev`/`$results` 컨텍스트 변수를 지원합니다.

### 배열형 onSuccess

```json
{
  "handler": "apiCall",
  "target": "/api/admin/settings",
  "params": { "method": "POST", "body": "{{_local.formData}}" },
  "onSuccess": [
    {
      "handler": "setState",
      "params": {
        "target": "local",
        "isSaving": false,
        "hasChanges": false
      }
    },
    {
      "handler": "toast",
      "params": { "type": "success", "message": "$t:common.save_success" }
    },
    {
      "handler": "refetchDataSource",
      "params": { "dataSourceId": "settings" }
    }
  ]
}
```

### 배열형 onError

```json
{
  "onError": [
    {
      "handler": "setState",
      "params": {
        "target": "local",
        "isSaving": false,
        "errors": "{{error.errors ?? {}}}"
      }
    },
    {
      "handler": "toast",
      "params": { "type": "error", "message": "{{error.message}}" }
    }
  ]
}
```

### $prev/$results 지원

배열형 onSuccess/onError에서 이전 액션의 결과를 참조할 수 있습니다:

| 변수 | 설명 |
|------|------|
| `$prev` | 직전 액션의 결과값 |
| `$results` | 모든 이전 액션 결과 배열 |

```text
✅ 배열형 onSuccess/onError는 내부적으로 sequence와 동일하게 처리
✅ 순차 실행: 첫 번째 → 두 번째 → ... 순서 보장
중간 액션이 실패하면 이후 액션은 실행되지 않음
```

---

## 관련 문서

- [레이아웃 JSON 기능 인덱스](layout-json-features.md)
- [스타일 및 계산된 값](layout-json-features-styling.md)
- [초기화 및 모달](layout-json-features-actions.md)
- [액션 핸들러](actions-handlers.md)
