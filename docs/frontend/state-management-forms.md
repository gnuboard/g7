# 상태 관리 - 폼 자동 바인딩 및 setState

> **메인 문서**: [state-management.md](state-management.md)
> **관련 문서**: [state-management-advanced.md](state-management-advanced.md) | [data-binding.md](data-binding.md)

---

## 목차

1. [레이아웃 레벨 상태 초기화](#레이아웃-레벨-상태-초기화)
2. [폼 자동 바인딩 (FormContext)](#폼-자동-바인딩-formcontext)
3. [폼 변경 감지 (trackChanges/hasChanges)](#폼-변경-감지-trackchangeshaschanges)
4. [setState 액션](#setstate-액션)
5. [깊은 병합 (Deep Merge)](#깊은-병합-deep-merge)
   - [얕은 병합 (merge: "shallow")](#얕은-병합-merge-shallow)
6. [payload 표현식 평가](#payload-표현식-평가)

---

## 레이아웃 레벨 상태 초기화

> **버전**: engine-v1.11.0+

레이아웃 JSON 최상위에서 `initLocal`, `initGlobal`, `initIsolated`로 정적 초기값을 설정할 수 있습니다.

### initLocal (로컬 상태 초기값)

레이아웃 JSON 최상위에 `initLocal` 블록을 정의하여 `_local` 초기값을 설정합니다:

```json
{
  "version": "1.0.0",
  "layout_name": "admin_board_form",
  "initLocal": {
    "activeTab": "basic",
    "hasChanges": false,
    "isSaving": false,
    "formData": {
      "name": "",
      "slug": "",
      "type": "basic",
      "categories": [],
      "show_view_count": false,
      "use_comment": false
    }
  },
  "body": { ... }
}
```

| 특징 | 설명 |
|------|------|
| ✅ 즉시 사용 가능 | 레이아웃 로드 시 바로 초기값 적용 |
| ✅ 타입 힌트 | boolean 필드에 `false` 지정 시 Toggle 자동 바인딩 작동 |
| 정적 값만 | 동적 표현식(`{{}}`) 미지원 |

> **하위 호환**: 기존 `state` 속성도 `initLocal`과 동일하게 동작합니다 (deprecated).

### initGlobal (전역 상태 초기값)

레이아웃 JSON 최상위에 `initGlobal` 블록을 정의하여 `_global` 초기값을 설정합니다:

```json
{
  "initGlobal": {
    "sidebarOpen": true,
    "theme": "light"
  }
}
```

### initIsolated (격리 상태 초기값)

레이아웃 JSON 최상위에 `initIsolated` 블록을 정의하여 `_isolated` 초기값을 설정합니다:

```json
{
  "initIsolated": {
    "selectedItems": [],
    "filterOptions": {
      "status": "all"
    }
  }
}
```

### 실행 순서 및 병합 동작

```text
1. 레이아웃 initLocal/initGlobal/initIsolated (정적 기본값)
2. 데이터소스 initLocal/initGlobal/initIsolated (API 응답으로 깊은 병합)
3. initActions (동적 초기화)
```

**병합 방식**: 데이터소스의 `initLocal`/`initGlobal`/`initIsolated`가 적용될 때 **깊은 병합**됩니다:
- 레이아웃 레벨 초기값은 기본값으로 유지
- 데이터소스 응답에서 추가되는 값만 병합/덮어쓰기

```json
// 레이아웃 initLocal (기본값)
{
  "initLocal": {
    "formData": {
      "name": "",
      "type": "basic",
      "category": "default"  // 기본값
    }
  }
}

// 데이터소스 응답 (API)
{
  "name": "Product A",
  "type": "premium"
  // category 없음
}

// 결과 (_local.formData)
{
  "name": "Product A",    // API 값
  "type": "premium",      // API 값
  "category": "default"   // 기본값 유지
}
```

---

## 데이터소스 initLocal (API 응답 바인딩)

`data_sources`의 `initLocal` 옵션으로 API 응답을 로컬 상태에 자동 바인딩합니다:

```json
{
  "state": {
    "formData": {
      "name": "",
      "slug": ""
    }
  },
  "data_sources": [
    {
      "id": "board",
      "type": "api",
      "endpoint": "/api/boards/{{route?.id}}",
      "method": "GET",
      "auto_fetch": "{{!!route?.id}}",
      "condition": "{{!!route?.id}}",
      "initLocal": "formData"
    }
  ]
}
```

| 옵션 | 설명 |
|------|------|
| `initLocal` | API 응답 데이터를 저장할 `_local` 경로 (예: `"formData"` → `_local.formData`) |
| `auto_fetch` | 자동 fetch 조건 (편집 모드에서만 fetch) |
| `condition` | API 호출 조건 |

**동작 순서:**

```text
1. 레이아웃 로드 → state.formData 초기값 적용
2. route?.id 존재 → API 자동 호출
3. API 응답 수신 → _local.formData에 응답 데이터 덮어쓰기
4. 컴포넌트 리렌더링 → 폼 필드에 API 데이터 표시
```

### 두 방법 함께 사용 (권장)

```json
{
  "state": {
    "formData": {
      "name": "",
      "type": "basic",
      "use_comment": false
    }
  },
  "data_sources": [
    {
      "id": "board",
      "endpoint": "/api/boards/{{route?.id}}",
      "auto_fetch": "{{!!route?.id}}",
      "initLocal": "formData"
    }
  ]
}
```

- **생성 모드** (`route?.id` 없음): `state.formData` 초기값 사용
- **편집 모드** (`route?.id` 존재): API 응답이 `formData`를 덮어씀

---

## 폼 자동 바인딩 (FormContext)

> **버전**: engine-v1.2.0+

폼 필드의 `value`와 `onChange`를 자동으로 바인딩하는 시스템입니다. `dataKey` prop을 사용하여 verbose한 actions 블록 없이 간결하게 폼을 구성할 수 있습니다.

### 핵심 원칙

```text
✅ dataKey로 폼 데이터 경로 지정: 자식 컴포넌트들이 해당 경로에 자동 바인딩
✅ name prop으로 필드 식별: Input, Textarea, Select의 name이 필드명으로 사용
✅ trackChanges로 변경 추적: hasChanges가 자동으로 true로 설정됨
✅ 명시적 value 우선: value가 지정되어 있으면 자동 바인딩 비활성화
```

### 기존 방식 (verbose)

```json
{
  "name": "Input",
  "props": {
    "name": "title",
    "value": "{{_local.formData.title}}"
  },
  "actions": [
    {
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "local",
        "formData": {
          "...": "{{_local.formData}}",
          "title": "{{event.target.value}}"
        },
        "hasChanges": true
      }
    }
  ]
}
```

### 새로운 방식 (자동 바인딩)

#### 로컬 상태 바인딩 (기본)

```json
{
  "name": "Div",
  "props": {
    "dataKey": "formData",
    "trackChanges": true
  },
  "children": [
    {
      "name": "Input",
      "props": { "name": "title" }
    },
    {
      "name": "Textarea",
      "props": { "name": "content" }
    },
    {
      "name": "Select",
      "props": {
        "name": "category",
        "options": "{{categories}}"
      }
    }
  ]
}
```

#### 전역 상태 바인딩 (`_global.` 접두사)

> **버전**: engine-v1.3.0+

전역 상태에 폼 데이터를 바인딩해야 하는 경우 `_global.` 접두사를 사용합니다.

##### 기본 사용법

```json
{
  "name": "Div",
  "props": {
    "dataKey": "_global.formData",
    "trackChanges": true
  },
  "children": [
    {
      "name": "Input",
      "props": { "name": "title" }
    },
    {
      "name": "Toggle",
      "props": { "name": "isActive" }
    }
  ]
}
```

##### 필수: init_actions로 초기값 설정

`dataKey="_global.xxx"` 패턴을 사용하려면 **반드시** `init_actions`에서 전역 상태를 초기화해야 합니다:

```json
{
  "version": "1.0.0",
  "layout_name": "login_page",
  "init_actions": [
    {
      "handler": "setState",
      "params": {
        "target": "global",
        "loginForm": {
          "email": "",
          "password": ""
        }
      }
    }
  ],
  "components": [
    {
      "type": "basic",
      "name": "Form",
      "dataKey": "_global.loginForm",
      "children": [
        {
          "type": "basic",
          "name": "Input",
          "props": { "name": "email", "type": "email" }
        },
        {
          "type": "basic",
          "name": "Input",
          "props": { "name": "password", "type": "password" }
        }
      ],
      "actions": [
        {
          "type": "submit",
          "handler": "login",
          "params": {
            "body": {
              "email": "{{_global.loginForm.email}}",
              "password": "{{_global.loginForm.password}}"
            }
          }
        }
      ]
    }
  ]
}
```

##### 왜 init_actions가 필요한가?

| 항목 | state 블록 | init_actions |
|------|-----------|--------------|
| 적용 대상 | `_local` 상태만 | `_local` 또는 `_global` 상태 |
| 동적 값 | 불가 | 가능 (`{{query.xxx}}` 등) |
| `dataKey="_global.xxx"` | ❌ 작동 안함 | ✅ 작동함 |

```text
주의: state 블록은 _local에만 적용됨
   - "state": { "formData": {...} } → _local.formData (O)
   - "state": { "_global.formData": {...} } → 작동 안함 (X)

✅ 전역 상태 초기화는 반드시 init_actions 사용
   - init_actions + setState target: "global" → _global.formData (O)
```

##### init_actions에서 _local 상태 초기화 (URL 쿼리 동기화)

> **버전**: engine-v1.4.0+

init_actions에서 `target: "global"`과 `_local` 래퍼를 함께 사용하여 _local 상태를 동적 값으로 초기화할 수 있습니다. 이 패턴은 특히 **URL 쿼리 파라미터를 _local 상태에 동기화**할 때 사용됩니다.

```json
{
  "version": "1.0.0",
  "layout_name": "admin_product_list",
  "init_actions": [
    {
      "handler": "setState",
      "params": {
        "target": "global",
        "_local": {
          "filter": {
            "searchField": "{{query.search_field || 'all'}}",
            "displayStatus": "{{query.display_status || 'all'}}",
            "taxStatus": "{{query.tax_status || 'all'}}",
            "category": "{{query.category || ''}}",
            "keyword": "{{query.keyword || ''}}"
          }
        }
      }
    }
  ],
  "components": [...]
}
```

**동작 원리:**

| 항목 | 설명 |
|------|------|
| `target: "global"` | 상태 저장소 타겟 지정 (전역 스토어 접근) |
| `_local: {...}` | _local 상태 네임스페이스에 데이터 저장 |
| `{{query.xxx}}` | URL 쿼리 파라미터에서 동적 값 바인딩 |

**왜 이 패턴이 필요한가?**

| 방법 | 동적 값 지원 | _local 초기화 | 사용 시점 |
|------|-------------|---------------|----------|
| `state` 블록 | ❌ | ✅ | 정적 초기값만 필요할 때 |
| `init_actions` + `target: "global"` + 직접 키 | ✅ | ❌ (_global에 저장) | 전역 상태 초기화 |
| `init_actions` + `target: "global"` + `_local` 래퍼 | ✅ | ✅ | URL 쿼리 → _local 동기화 |

**사용 사례:**

- 목록 화면에서 필터 값을 URL과 동기화 (뒤로가기/새로고침 시 유지)
- 페이지네이션 상태를 URL에서 복원
- 검색 키워드를 URL 파라미터에서 초기화

##### 동작 방식

- `dataKey`가 `_global.`로 시작하면 `_global.formData`에 바인딩
- 입력값 변경 시 `_global.formData.title`, `_global.formData.isActive` 등이 자동 업데이트
- `trackChanges`가 `true`이면 `_global.hasChanges`가 자동으로 `true`로 설정

##### 사용 사례

- 여러 레이아웃/컴포넌트에서 같은 폼 데이터를 공유해야 할 때
- 모달에서 입력받은 데이터를 부모 페이지에서 사용해야 할 때
- 사이드 패널과 메인 영역 간 데이터 동기화가 필요할 때
- 로그인 폼처럼 실패 시에도 입력값을 유지해야 할 때

#### 격리된 상태 바인딩 (`_isolated.` 접두사)

> **버전**: engine-v1.14.0+

격리된 상태에 폼 데이터를 바인딩해야 하는 경우 `_isolated.` 접두사를 사용합니다. 격리된 상태는 해당 영역 내에서만 유효하며, 상태 변경 시 전체 레이아웃이 아닌 격리된 영역만 리렌더링됩니다.

##### 기본 사용법

```json
{
  "type": "Div",
  "isolatedState": {
    "miniForm": {
      "name": "",
      "email": ""
    }
  },
  "isolatedScopeId": "quick-form",
  "children": [
    {
      "type": "basic",
      "name": "Div",
      "props": {
        "dataKey": "_isolated.miniForm",
        "trackChanges": true
      },
      "children": [
        {
          "type": "basic",
          "name": "Input",
          "props": { "name": "name", "placeholder": "이름" }
        },
        {
          "type": "basic",
          "name": "Input",
          "props": { "name": "email", "type": "email", "placeholder": "이메일" }
        }
      ]
    }
  ]
}
```

##### 필수: isolatedState로 초기값 정의

```text
주의: dataKey="_isolated.xxx" 패턴을 사용하려면
   상위 컴포넌트에 isolatedState 속성이 정의되어 있어야 합니다.

✅ isolatedState가 있는 컴포넌트 내에서만 _isolated 바인딩 가능
❌ isolatedState 없이 _isolated 바인딩 시 → _local로 폴백 + 경고 로그
```

##### 동작 방식

- `dataKey`가 `_isolated.`로 시작하면 `_isolated.miniForm`에 바인딩
- 입력값 변경 시 `_isolated.miniForm.name`, `_isolated.miniForm.email` 자동 업데이트
- 상태 변경 시 격리된 영역만 리렌더링 (성능 최적화)
- `trackChanges`가 `true`이면 `_isolated.hasChanges`가 자동으로 `true`로 설정

##### 사용 사례

| 사례 | 설명 |
|------|------|
| 빈번한 입력 폼 | 검색 필터, 실시간 미리보기 폼 등 잦은 입력이 발생하는 경우 |
| 독립적인 UI 영역 | 사이드 패널, 미니 폼 등 다른 영역과 상태를 공유하지 않는 경우 |
| 성능 최적화 필요 | 입력 시 전체 레이아웃 리렌더링을 방지해야 하는 경우 |

##### _local vs _global vs _isolated 비교

| 접두사 | 스코프 | 리렌더링 범위 | 라이프사이클 | 사용 시점 |
| ------ | ------ | ------------- | ------------ | -------- |
| (없음) / `_local.` | 현재 레이아웃 | 전체 레이아웃 | 페이지 이동 시 초기화 | 일반 폼 데이터 |
| `_global.` | 앱 전체 | 전체 앱 | 유지됨 | 다중 페이지 공유 데이터 |
| `_isolated.` | 격리된 컴포넌트 | 격리된 영역만 | 컴포넌트 언마운트 시 소멸 | 빈번한 인터랙션 영역 |

### Props 설명

| Prop | 타입 | 필수 | 설명 |
|------|------|------|------|
| `dataKey` | string | ✅ | 폼 데이터 경로. 기본적으로 `_local` 바인딩 (예: `"formData"` → `_local.formData`). `_global.` 접두사로 전역 상태 바인딩 가능 |
| `trackChanges` | boolean | ❌ | `true`이면 입력 시 `hasChanges`를 자동으로 `true`로 설정 |
| `debounce` | number | ❌ | 밀리초 단위 debounce 지연 시간. 빈번한 입력 시 성능 최적화에 사용 (예: `300`) |
| `autoBinding` | boolean | ❌ | `false`로 설정 시 해당 필드의 자동 바인딩을 명시적으로 비활성화 (engine-v1.17.6+). 커스텀 핸들러나 G7Core API로 상태를 관리하는 경우 사용 |

### 자동 바인딩 조건

자동 바인딩이 적용되려면 다음 조건을 **모두** 충족해야 합니다:

1. 부모(또는 조상) 컴포넌트에 `dataKey` prop이 있어야 함
2. 자식 컴포넌트에 `name` prop이 있어야 함
3. 자식 컴포넌트에 `value` prop이 **명시적으로 지정되어 있지 않아야** 함

### 자동 바인딩 스킵 조건 (engine-v1.17.6+)

컴포넌트 props에 `autoBinding: false`가 명시되면 해당 필드의 자동 바인딩이 스킵됩니다:

```json
{
  "type": "basic",
  "name": "Input",
  "props": {
    "type": "radio",
    "name": "purchase_restriction",
    "value": "none",
    "checked": "{{(_local.form.purchase_restriction ?? 'none') === 'none'}}",
    "autoBinding": false
  }
}
```

- 스킵된 필드만 자동 바인딩이 해제되며, 같은 폼의 다른 필드는 종전대로 자동 바인딩 유지
- 라디오 버튼, 체크박스 등 자동 바인딩의 `value` 덮어쓰기가 유해한 컴포넌트에서 사용
- `autoBinding: false` 사용 시 `value`/`checked`/`onChange`를 직접 바인딩해야 함

### 지원 컴포넌트

| 컴포넌트 | 바인딩 방식 | 비고 |
|----------|------------|------|
| Input | `value` + `onChange` | text, number, email 등 모든 타입 |
| Textarea | `value` + `onChange` | |
| Select | `value` + `onChange` | |
| Toggle | `value` + `onChange` | boolean 값 자동 감지하여 `checked` 처리 |
| Checkbox | `value` + `onChange` | boolean 값 자동 감지하여 `checked` 처리 |

### 동작 방식

```text
1. DynamicRenderer가 dataKey prop 감지
2. FormProvider로 자식 컴포넌트들을 감싸기
3. 자식 컴포넌트 렌더링 시 name prop 감지
4. state[dataKey][name] 값의 타입에 따라 자동 바인딩:
   - boolean: value + onChange(e.target.checked) - Toggle, Checkbox용
   - 그 외: value + onChange(e.target.value) - Input, Textarea, Select용
5. trackChanges가 true이면 hasChanges도 자동 설정
```

### 중첩 구조 지원

`dataKey`가 있는 컴포넌트 내부에 여러 단계의 자식이 있어도 자동 바인딩이 작동합니다:

```json
{
  "name": "Form",
  "props": { "dataKey": "formData" },
  "children": [
    {
      "name": "Div",
      "props": { "className": "form-group" },
      "children": [
        {
          "name": "Label",
          "text": "제목"
        },
        {
          "name": "Input",
          "props": { "name": "title" }
        }
      ]
    }
  ]
}
```

### 명시적 바인딩 혼용

일부 필드만 명시적으로 바인딩하고 나머지는 자동 바인딩 사용:

```json
{
  "name": "Div",
  "props": { "dataKey": "formData" },
  "children": [
    {
      "name": "Input",
      "props": { "name": "title" }
    },
    {
      "name": "Input",
      "props": {
        "name": "customField",
        "value": "{{_local.customValue}}",
        "onChange": "customHandler"
      }
    }
  ]
}
```

위 예시에서 `title`은 자동 바인딩되고, `customField`는 명시적 `value`가 있으므로 자동 바인딩되지 않습니다.

### setState dot notation 지원

폼 자동 바인딩과 함께 `setState` 액션에서도 dot notation 경로를 지원합니다:

```json
{
  "actions": [
    {
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "_local.formData.title",
        "value": "{{event.target.value}}"
      }
    }
  ]
}
```

이 방식은 기존의 spread 연산자 방식보다 간결하며, 다른 필드를 덮어쓰지 않고 부분 업데이트합니다.

### 주의사항

| 항목 | 설명 |
|------|------|
| Form 태그 필수 아님 | `dataKey`는 어떤 컴포넌트에든 적용 가능 (Div, Section 등) |
| 초기 데이터 필요 | `state.formData` 또는 `data_sources`의 `initLocal`로 초기화 필요 |
| boolean 타입 감지 | 초기값이 boolean이어야 Toggle/Checkbox 자동 바인딩 작동 |
| FileInput 미지원 | File 객체 처리를 위한 별도 로직 필요 |
| debounce 중복 주의 | 자동 바인딩 `debounce`와 액션의 `debounce` 속성을 함께 사용하면 이중 지연 발생 (예: 300ms + 300ms = 600ms). 아래 "debounce 레벨 구분" 참조 |
| autoBinding: false 시 명시적 바인딩 필수 | `autoBinding: false` 사용 시 `value`/`onChange`를 직접 바인딩해야 함. 미설정 시 필드가 빈 상태로 표시됨 (engine-v1.17.6+) |

### debounce 레벨 구분

G7에서 debounce는 두 가지 레벨에서 설정할 수 있습니다. 혼동에 주의하세요.

| 레벨 | 설정 위치 | 적용 대상 | 설명 |
|------|----------|----------|------|
| **컴포넌트 레벨** | `dataKey` 부모의 `debounce` prop | 자동 바인딩 onChange | 폼 자동 바인딩의 상태 업데이트 지연 |
| **액션 레벨** | `actions[].debounce` 속성 | 개별 액션 실행 | 특정 액션(apiCall, refetchDataSource 등)의 실행 지연 |

```json
{
  "type": "basic",
  "name": "Div",
  "props": {
    "dataKey": "formData",
    "debounce": 300
  },
  "children": [
    {
      "type": "basic",
      "name": "Input",
      "props": { "name": "keyword" },
      "actions": [
        {
          "type": "change",
          "handler": "refetchDataSource",
          "params": { "dataSourceId": "search_results" },
          "debounce": 500
        }
      ]
    }
  ]
}
```

```text
위 예시에서 두 debounce가 모두 적용되면:
   - 컴포넌트 debounce (300ms) → _local.formData.keyword 업데이트 지연
   - 액션 debounce (500ms) → refetchDataSource 실행 지연
   - 총 지연: 최대 800ms (이중 지연 발생!)

✅ 권장: 하나만 선택
   - 폼 자동 바인딩만 사용 → 컴포넌트 debounce만 설정
   - 명시적 액션 사용 → 액션 debounce만 설정
```

---

## 폼 변경 감지 (trackChanges/hasChanges)

> **버전**: engine-v1.3.0+

폼 데이터가 변경되었는지 추적하여 저장 버튼 활성화 등의 UI 상태를 자동으로 관리하는 시스템입니다.

### 변경 감지 핵심 원칙

```text
✅ trackChanges로 변경 추적 활성화: 부모 컴포넌트에 trackChanges: true 설정
✅ hasChanges 자동 관리: 입력 시 _local.hasChanges가 자동으로 true로 설정
✅ 초기 로드 시 리셋: initLocal 적용 시 hasChanges가 자동으로 false로 초기화
✅ 저장 성공 시 리셋: onSuccess 핸들러에서 hasChanges: false로 수동 리셋 필요
```

### 기본 사용법

```json
{
  "id": "form_container",
  "type": "basic",
  "name": "Div",
  "dataKey": "formData",
  "trackChanges": true,
  "children": [
    {
      "name": "Input",
      "props": { "name": "title" }
    },
    {
      "name": "Input",
      "props": { "name": "description" }
    }
  ]
}
```

### 저장 버튼과 연동

`hasChanges` 플래그를 사용하여 저장 버튼의 활성화 상태를 제어합니다:

```json
{
  "id": "save_button",
  "type": "basic",
  "name": "Button",
  "props": {
    "disabled": "{{!_local.hasChanges || _local.isSaving}}"
  },
  "text": "$t:common.save",
  "actions": [
    {
      "type": "click",
      "handler": "sequence",
      "actions": [
        {
          "handler": "setState",
          "params": {
            "target": "local",
            "isSaving": true
          }
        },
        {
          "handler": "apiCall",
          "target": "/api/settings",
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
            }
          ],
          "onError": [
            {
              "handler": "setState",
              "params": {
                "target": "local",
                "isSaving": false
              }
            }
          ]
        }
      ]
    }
  ]
}
```

### 취소 버튼과 연동

데이터를 다시 불러와서 폼을 초기 상태로 되돌립니다:

```json
{
  "id": "cancel_button",
  "type": "basic",
  "name": "Button",
  "text": "$t:common.cancel",
  "actions": [
    {
      "type": "click",
      "handler": "refetchDataSource",
      "params": {
        "dataSourceId": "settings"
      }
    }
  ]
}
```

`refetchDataSource` 실행 시 `initLocal`이 다시 적용되면서 `hasChanges`가 자동으로 `false`로 리셋됩니다.

### 동작 흐름

```text
1. 레이아웃 로드
   ↓
2. data_sources의 initLocal 적용
   → _local.formData = API 응답 데이터
   → _local.hasChanges = false (자동 초기화)
   ↓
3. 사용자가 폼 필드 수정
   → _local.formData.필드명 = 새 값
   → _local.hasChanges = true (trackChanges가 true일 때 자동)
   ↓
4. 저장 버튼 활성화 (hasChanges가 true이므로)
   ↓
5. 저장 버튼 클릭 → API 호출
   ↓
6-a. 저장 성공
   → onSuccess에서 hasChanges: false 설정
   → 저장 버튼 비활성화

6-b. 저장 실패
   → hasChanges는 true 유지
   → 저장 버튼 활성화 유지
```

### 변경 감지 Props 설명

| Prop | 타입 | 위치 | 설명 |
|------|------|------|------|
| `dataKey` | string | 부모 컴포넌트 | 폼 데이터 경로 (예: `"formData"` → `_local.formData`) |
| `trackChanges` | boolean | 부모 컴포넌트 | `true`이면 자식 입력 필드 변경 시 `hasChanges` 자동 설정 |
| `hasChanges` | boolean | `_local` 상태 | 폼 데이터 변경 여부 (자동 관리됨) |

### initLocal과 hasChanges 자동 리셋

`data_sources`에서 `initLocal`이 설정된 경우, API 응답이 로컬 상태에 적용될 때 `hasChanges`가 자동으로 `false`로 초기화됩니다:

```json
{
  "data_sources": [
    {
      "id": "settings",
      "type": "api",
      "endpoint": "/api/admin/settings",
      "method": "GET",
      "auto_fetch": true,
      "initLocal": "formData",
      "refetchOnMount": true
    }
  ]
}
```

이 동작은 `DynamicRenderer.tsx`에서 다음과 같이 처리됩니다:

```typescript
// initLocal 적용 시 hasChanges를 false로 초기화
setLocalDynamicState(prev => ({
  loadingActions: prev.loadingActions || {},
  ...localInitData,
  hasChanges: false,
}));
```

### 전역 상태에서의 사용

`dataKey`가 `_global.`로 시작하는 경우, `hasChanges`도 전역 상태에 저장됩니다:

```json
{
  "name": "Div",
  "props": {
    "dataKey": "_global.formData",
    "trackChanges": true
  },
  "children": [...]
}
```

이 경우 `_global.hasChanges`가 자동으로 관리됩니다.

### 변경 감지 주의사항

| 항목 | 설명 |
|------|------|
| 저장 성공 시 수동 리셋 필요 | `onSuccess`에서 `hasChanges: false` 명시적 설정 필요 |
| 원본 값 비교 미지원 | 현재는 입력 발생 시 무조건 dirty로 처리 (원본 값과 동일해도 hasChanges가 true) |
| dataKey 필수 | `trackChanges`는 `dataKey`와 함께 사용해야 동작 |
| refetchDataSource 활용 | 취소 시 `refetchDataSource`로 원본 데이터 다시 로드하면 자동 리셋 |

---

## setState 액션

컴포넌트 로컬 상태 또는 전역 상태를 업데이트하는 액션입니다.

### 기본 문법

```json
{
  "actions": [
    {
      "event": "onClick",
      "type": "setState",
      "target": "component" | "global",
      "payload": {
        "key": "value"
      }
    }
  ]
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `event` | string | ✅ | 이벤트 타입 (`onClick`, `onChange` 등) |
| `type` | string | ✅ | `"setState"` 고정 |
| `target` | string | ✅ | `"component"` (로컬) 또는 `"global"` (전역) |
| `payload` | object | ✅ | 업데이트할 상태 객체 |

### target 옵션 비교

| target | 접근 방식 | 영향 범위 | 사용 시점 |
|--------|----------|----------|----------|
| `"component"` | 해당 컴포넌트 내부 | 해당 컴포넌트만 | 컴포넌트 내부 상태 관리 |
| `"global"` | `_global.속성` | 모든 컴포넌트 | 여러 컴포넌트 간 상태 공유 |

### 컴포넌트 로컬 상태 업데이트

```json
{
  "actions": [
    {
      "event": "onClick",
      "type": "setState",
      "target": "component",
      "payload": {
        "isOpen": true,
        "selectedTab": "profile"
      }
    }
  ]
}
```

### 전역 상태 업데이트

```json
{
  "actions": [
    {
      "event": "onClick",
      "type": "setState",
      "target": "global",
      "payload": {
        "sidebarOpen": false,
        "currentTheme": "dark"
      }
    }
  ]
}
```

---

## 깊은 병합 (Deep Merge)

> **버전**: engine-v1.2.0+

로컬 상태 업데이트 시 중첩 객체의 특정 필드만 업데이트하고 나머지 필드는 자동으로 유지됩니다.

### 병합 모드 선택

| 모드 | 옵션 | 동작 | 사용 시점 |
|------|------|------|----------|
| **깊은 병합** (기본) | 생략 또는 `"merge": "deep"` | 재귀적 깊은 병합 (중첩 객체 보존) | 개별 필드 업데이트 |
| **얕은 병합** | `"merge": "shallow"` | 최상위 키만 덮어쓰기 (1단계) | 프리셋 적용, 필터 초기화 |
| **완전 교체** | `"merge": "replace"` | 기존 상태 완전 무시, 새 값으로 교체 | 폼 초기화, 서버 데이터로 전체 교체 |

#### 3모드 비교 예시

```text
현재 상태: { form: { name: "기존", price: 1000 }, ui: { tab: "basic" } }
업데이트:  { form: { name: "신규" } }

replace:  { form: { name: "신규" } }                              ← ui 키 자체가 사라짐
shallow:  { form: { name: "신규" }, ui: { tab: "basic" } }        ← ui 키 유지, form 전체 교체
deep:     { form: { name: "신규", price: 1000 }, ui: { tab: "basic" } }  ← price도 유지
```

### 완전 교체 (merge: "replace")

> **버전**: engine-v1.18.0+

기존 상태를 완전히 무시하고 새 값으로 교체합니다. 서버에서 받은 데이터로 전체 상태를 덮어쓰거나, 폼을 완전 초기화할 때 사용합니다.

```json
{
  "handler": "setState",
  "params": {
    "target": "_local",
    "merge": "replace",
    "formData": {
      "name": "",
      "price": 0,
      "description": ""
    }
  }
}
```

**주의사항**:

```text
replace는 해당 target의 모든 기존 키를 제거합니다
   - local target: 기존 _local 전체가 payload로 교체됨
   - global target: _global 전체가 payload로 교체됨 (modalStack 등도 사라질 수 있음)
   - 부분 업데이트에는 deep 또는 shallow를 사용하세요
```

### 얕은 병합 (merge: "shallow")

> **버전**: engine-v1.4.0+

전체 객체를 교체해야 할 때 사용합니다. 프리셋 적용이나 폼 초기화 시 기존 값을 모두 덮어쓰고 새 값으로 교체합니다.

```json
{
  "handler": "setState",
  "params": {
    "target": "_local",
    "merge": "shallow",
    "filter": {
      "searchField": "all",
      "displayStatus": "all",
      "taxStatus": "all",
      "category": ""
    }
  }
}
```

**얕은 병합 동작 예시**:

```text
// 현재 상태
{
  filter: { searchField: "name", displayStatus: "visible", taxStatus: "taxable", keyword: "테스트" }
}

// 업데이트 payload (merge: "shallow")
{
  filter: { searchField: "all", displayStatus: "all", taxStatus: "all" }
}

// 결과 (얕은 병합) - filter 전체가 교체됨
{
  filter: { searchField: "all", displayStatus: "all", taxStatus: "all" }
}

// 비교: 깊은 병합이었다면 (기본 동작)
{
  filter: { searchField: "all", displayStatus: "all", taxStatus: "all", keyword: "테스트" }
}
```

**사용 사례**:

| 사례 | 설명 |
|------|------|
| 프리셋 적용 | 저장된 필터 프리셋을 불러와 모든 필터 값 교체 |
| 폼 초기화 | 초기화 버튼 클릭 시 모든 폼 필드를 기본값으로 리셋 |
| 부분 객체 교체 | 특정 최상위 키의 객체를 통째로 교체하되 다른 키는 유지 |

> **참고**: 전체 상태를 서버 데이터로 완전히 교체하려면 `merge: "replace"`를 사용하세요.

**주의사항**:

```text
얕은 병합은 최상위 키만 덮어씁니다
   - filter 키 전체가 교체됨
   - 다른 최상위 키(visibleFilters, isEditMode 등)는 유지됨
```

### 기존 방식 (spread 연산자 필요)

```json
{
  "type": "change",
  "handler": "setState",
  "params": {
    "target": "local",
    "formData": {
      "...": "{{_local.formData}}",
      "name": "{{$event.target.value}}"
    },
    "hasChanges": true
  }
}
```

### 새로운 방식 (깊은 병합)

```json
{
  "type": "change",
  "handler": "setState",
  "params": {
    "target": "local",
    "formData": {
      "name": "{{$event.target.value}}"
    },
    "hasChanges": true
  }
}
```

spread 연산자 없이도 `formData.name`만 업데이트되고 `formData.email`, `formData.slug` 등은 자동으로 유지됩니다.

### 병합 규칙

| 값 타입 | 동작 | 예시 |
|---------|------|------|
| 일반 객체 (Object) | 기존 상태와 병합 | `{ formData: { name: "new" } }` → formData.name만 변경 |
| 배열 (Array) | 덮어쓰기 (병합 안함) | `{ tags: [1, 2] }` → tags 전체 교체 |
| null | 덮어쓰기 | `{ formData: null }` → formData를 null로 설정 |
| 기본 타입 | 덮어쓰기 | `{ count: 5 }` → count를 5로 설정 |

### 동작 예시

```text
// 현재 상태
{
  formData: { name: "old", email: "a@b.com", slug: "old-slug" },
  hasChanges: false
}

// 업데이트 payload
{
  formData: { name: "new" },
  hasChanges: true
}

// 결과 (깊은 병합)
{
  formData: { name: "new", email: "a@b.com", slug: "old-slug" },
  hasChanges: true
}
```

### 하위 호환성

기존 spread 연산자 패턴(`"...": "{{_local.formData}}"`)은 계속 지원됩니다. spread 연산자가 있으면 `evaluateExpressions()`에서 먼저 처리되어 완성된 객체가 전달됩니다.

### 깊은 병합 제한사항

| 항목 | 설명 |
|------|------|
| 1단계 병합만 지원 | `formData.nested.field` 같은 2단계 이상 병합은 미지원 |
| 전역 상태는 미적용 | `target: "global"`인 경우 깊은 병합 미적용 |
| 배열은 병합 안함 | 배열 값은 전체가 교체됨 |

---

## payload 표현식 평가

payload 값에 `{{}}` 표현식을 사용하여 동적으로 값을 계산할 수 있습니다.

### 지원하는 표현식

```json
{
  "actions": [
    {
      "event": "onClick",
      "type": "setState",
      "target": "global",
      "payload": {
        // boolean 토글
        "sidebarOpen": "{{!_global.sidebarOpen}}",

        // 조건부 값 설정 (삼항 연산자)
        "theme": "{{_global.theme == 'dark' ? 'light' : 'dark'}}",

        // 비교 연산
        "isLarge": "{{count > 100}}",

        // 정적 값 (표현식 없음)
        "selectedTab": "profile"
      }
    }
  ]
}
```

### 표현식 타입별 예시

| 표현식 타입 | 예시 | 결과 |
|------------|------|------|
| boolean 토글 | `"{{!_global.isOpen}}"` | `true` ↔ `false` |
| 삼항 연산자 | `"{{_global.theme == 'dark' ? 'light' : 'dark'}}"` | `'light'` 또는 `'dark'` |
| 비교 연산 | `"{{count > 100}}"` | `true` 또는 `false` |
| 정적 값 | `"profile"` | `"profile"` |

---

## 관련 문서

- [상태 관리 개요](state-management.md) - _global, _local 기본 개념
- [고급 상태 관리](state-management-advanced.md) - 예약 상태, G7Core.state API
- [데이터 바인딩](data-binding.md) - `{{}}` 표현식 문법
- [액션 핸들러](actions.md) - setState 핸들러 상세
