# 전역 상태 관리

> **버전**: engine-v1.1.0+
> **관련 문서**: [components.md](components.md) | [data-binding.md](data-binding.md) | [index.md](index.md)

---

## TL;DR (5초 요약)

```text
1. 전역 상태: _global.속성명 (앱 전체 공유, 페이지 이동 시 유지)
2. 로컬 상태: _local.속성명 (레이아웃 전체, 레이아웃 전환 시 초기화, 같은 레이아웃 재진입 시 유지)
3. 격리 상태: _isolated.속성명 (컴포넌트 영역, 성능 최적화용) (engine-v1.14.0+)
4. 바인딩: {{_global.xxx}}, {{_local.xxx}}, {{_isolated.xxx}}
5. 변경: setState 핸들러 (target: "global"|"local"|"isolated")
```

---

## 분리된 문서

이 문서는 가독성을 위해 다음과 같이 분리되었습니다:

| 문서 | 내용 |
|------|------|
| **state-management.md** (현재) | 개요, 전역 상태 특징, _global, _local, 주의사항 |
| [state-management-forms.md](state-management-forms.md) | 로컬 상태 초기화, 폼 자동 바인딩, setState 액션, 깊은 병합, payload 표현식 |
| [state-management-advanced.md](state-management-advanced.md) | 예약된 전역 상태, 사용 사례, 조건부 렌더링, 모듈 동기화, G7Core.state API |

---

## 목차

1. [개요](#개요)
2. [상태 계층 구조](#상태-계층-구조)
3. [전역 상태 특징](#전역-상태-특징)
4. [_global 예약 경로](#_global-예약-경로)
5. [_local 로컬 상태](#_local-로컬-상태)
6. [_isolated 격리된 상태](#_isolated-격리된-상태)
7. [주의사항](#주의사항)

---

## 개요

그누보드7 템플릿 엔진은 레이아웃 JSON 전체에서 공유되는 **전역 상태(Global State)**를 지원합니다. 전역 상태를 활용하면 여러 컴포넌트 간 상태 공유와 UI 상태 관리가 가능합니다.

---

## 상태 계층 구조

> **버전**: engine-v1.14.0+

그누보드7 템플릿 엔진은 4-layer 상태 계층 구조를 지원합니다:

| 레이어 | 범위 | 라이프사이클 | 용도 |
|--------|------|--------------|------|
| `_global` | 앱 전체 | 페이지 이동 시 유지 | 사용자 인증, 사이드바, 설정 |
| `_local` | 현재 레이아웃 | 레이아웃 전환 시 초기화 (같은 레이아웃 재진입 시 유지) | 폼 데이터, 필터, 페이지네이션 |
| `_isolated` | 격리된 컴포넌트 | 컴포넌트 언마운트 시 소멸 | 독립적 UI 영역 (성능 최적화) |
| `_computed` | 계산된 값 | 렌더링마다 재계산 | 파생 데이터 |

### 리렌더링 범위 비교

```text
_global 변경    → 전체 앱 리렌더링 (사이드바, 헤더 등)
_local 변경     → 전체 레이아웃 리렌더링
_isolated 변경  → 해당 격리된 영역만 리렌더링 ✅ 성능 최적화
_computed 변경  → 자동 재계산 (상태 변경 아님)
```

---

## 전역 상태 특징

| 특징 | 설명 |
|------|------|
| **모든 컴포넌트 접근** | 레이아웃 내 어떤 컴포넌트에서든 접근 가능 |
| **`_global` 예약 경로** | `_global.속성명` 형식으로 접근 |
| **자동 재렌더링** | 상태 변경 시 관련 컴포넌트 자동 재렌더링 |
| **페이지 새로고침 시 초기화** | 영구 저장되지 않음 (휘발성) |

---

## _global 예약 경로

전역 상태에 접근하려면 `_global` 예약 경로를 사용합니다.

### 시스템 주입 속성

G7이 자동으로 주입하는 `_global` 속성입니다. 레이아웃에서 읽기 전용으로 사용합니다.

| 속성 | 설명 | 출처 |
|------|------|------|
| `_global.settings` | 관리자 환경설정 (defaults.json 기반 영속 설정) | `G7Config.settings` → `TemplateApp.loadG7Config()` |
| `_global.appConfig` | 시스템 설정 (config/frontend.php 기반 정적 config 값) | `G7Config.appConfig` → `TemplateApp.loadG7Config()` |

```json
{
  "comment": "appConfig 활용 예시 - 동적 Select 옵션",
  "props": {
    "options": "{{_global.appConfig?.supportedTimezones?.map(tz => ({ value: tz, label: tz })) ?? []}}"
  }
}
```

### 기본 사용법

```json
{
  "props": {
    "className": "{{_global.sidebarOpen ? 'open' : 'closed'}}",
    "text": "{{_global.currentTheme}}"
  },
  "if": "{{_global.isLoading}}"
}
```

### 사용 가능한 위치

- `props` 내 값
- `if` 조건
- `actions`의 `payload` 표현식
- 데이터 바인딩 표현식 (`{{}}`)

---

## _local 로컬 상태

레이아웃 내에서 컴포넌트 간 공유되는 **로컬 상태**입니다. `_global`과 달리 레이아웃 단위로 관리됩니다.

### 핵심 원칙

```text
✅ 레이아웃 전체에서 공유: 같은 레이아웃 내 모든 컴포넌트에서 접근 가능
✅ setState로 업데이트: target: "local" 또는 기본값으로 업데이트
✅ iteration 내부 접근: 반복 렌더링 내부에서도 _local 상태 접근 가능
✅ API 에러 저장: onError 핸들러에서 에러 데이터 저장에 활용
```

### 라이프사이클 (SPA 네비게이션)

SPA navigate 시 `_local` 상태는 **레이아웃 이름 기준으로 선택적 초기화**됩니다:

| 전환 유형 | 동작 | 예시 |
| -------- | ---- | --- |
| **다른 레이아웃 전환** | `_local = {}` 완전 초기화 후 새 initLocal 적용 | 주문관리 → 배송정책 |
| **같은 레이아웃 재진입** | 기존 `_local` 유지, undefined 키만 initLocal에서 채움 | 상품목록 → 상품수정 → 상품목록 |

```text
다른 레이아웃 전환 시 이전 _local 키가 잔존하지 않음 (완전 초기화)
✅ 같은 레이아웃 재진입 시 필터, 컬럼 설정 등 사용자 상태 보존
```

### 상태 공유 메커니즘

그누보드7 템플릿 엔진은 `parentComponentContext`를 통해 레이아웃 전체에서 `_local` 상태를 공유합니다:

```text
최상위 DynamicRenderer (state 소유)
    ↓ parentComponentContext 전달
  자식 DynamicRenderer (부모 state 사용)
    ↓ parentComponentContext 전달
  손자 DynamicRenderer (부모 state 사용)
    ↓ ...
```

이 메커니즘 덕분에:

- 버튼의 `onError`에서 `setState`로 설정한 에러 데이터가
- 형제 컴포넌트인 에러 표시 영역에서도 `{{_local.errors}}`로 접근 가능

### 로컬 상태 기본 사용법

```json
{
  "props": {
    "className": "{{_local.activeTab === 'basic' ? 'active' : ''}}",
    "value": "{{_local.searchQuery}}"
  },
  "if": "{{_local.errors && Object.keys(_local.errors).length > 0}}"
}
```

### setState로 로컬 상태 업데이트

```json
{
  "actions": [
    {
      "type": "click",
      "handler": "setState",
      "params": {
        "target": "local",
        "activeTab": "settings"
      }
    }
  ]
}
```

> **참고**: `target`을 생략하면 기본값으로 `"local"`이 사용됩니다.

### API 에러 처리 예시

API 호출 실패 시 에러 데이터를 `_local`에 저장하고 화면에 표시:

```json
{
  "id": "save_button",
  "type": "basic",
  "name": "Button",
  "text": "저장",
  "actions": [
    {
      "type": "click",
      "handler": "apiCall",
      "target": "/api/users",
      "params": { "method": "POST" },
      "onError": [
        {
          "handler": "setState",
          "params": {
            "target": "local",
            "errors": "{{$response?.errors}}"
          }
        }
      ]
    }
  ]
}
```

에러 표시 컴포넌트:

```json
{
  "id": "validation_error",
  "type": "basic",
  "name": "Div",
  "if": "{{_local.errors && Object.keys(_local.errors).length > 0}}",
  "props": {
    "className": "bg-red-50 border border-red-200 rounded-lg p-4"
  },
  "children": [
    {
      "type": "basic",
      "name": "Ul",
      "iteration": {
        "source": "Object.entries(_local.errors ?? {}).flatMap(([field, msgs]) => msgs)",
        "item_var": "message"
      },
      "children": [
        {
          "type": "basic",
          "name": "Li",
          "text": "{{message}}"
        }
      ]
    }
  ]
}
```

### iteration 내부에서 _local 접근

반복 렌더링 내부에서도 `_local` 상태에 접근할 수 있습니다:

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
        "className": "{{_local.selectedId === item.id ? 'bg-blue-100' : 'bg-white'}}"
      },
      "text": "{{item.name}}"
    }
  ]
}
```

### _global vs _local 비교

| 항목 | `_global` | `_local` |
|------|-----------|----------|
| 범위 | 전체 애플리케이션 | 현재 레이아웃 |
| 용도 | 사이드바, 테마, 모달 | 폼 상태, 탭, 에러 |
| 접근 방식 | `{{_global.xxx}}` | `{{_local.xxx}}` |
| setState target | `"global"` | `"local"` (기본값) |
| 페이지 이동 시 | 유지 | 다른 레이아웃: 초기화 / 같은 레이아웃: 유지 |

### 사용 시점

| 사용 O | 사용 X |
|--------|--------|
| 폼 입력값 임시 저장 | 전역 UI 상태 (사이드바) |
| 탭/아코디언 상태 | 여러 페이지에서 공유할 데이터 |
| API 에러 메시지 | 영구 저장이 필요한 데이터 |
| 검색 필터 상태 | 비즈니스 로직 데이터 |

> **상세 문서**: 로컬 상태 초기화, 폼 자동 바인딩은 [state-management-forms.md](state-management-forms.md) 참조

---

## _isolated 격리된 상태

> **버전**: engine-v1.14.0+

`_isolated`는 특정 컴포넌트 영역 내에서만 유효한 격리된 상태입니다. 해당 영역의 상태 변경이 전체 레이아웃을 리렌더링하지 않아 **성능이 향상**됩니다.

### _local vs _isolated 사용 기준

| 기준 | `_local` 사용 | `_isolated` 사용 |
|------|---------------|------------------|
| **리렌더링 범위** | 전체 레이아웃 | 격리된 영역만 |
| **상태 공유** | 레이아웃 내 다른 컴포넌트와 공유 | 해당 영역 내에서만 사용 |
| **성능 최적화** | 불필요 | 빈번한 상호작용 영역 |
| **예시** | 폼 전체 데이터, 필터 설정 | 카테고리 선택, 드래그 상태 |

### 레이아웃에서 정의

`isolatedState` 속성으로 격리된 상태를 정의합니다:

```json
{
  "type": "Div",
  "isolatedState": {
    "selectedItems": [],
    "currentStep": 1
  },
  "isolatedScopeId": "item-selector",
  "children": [...]
}
```

| 속성 | 타입 | 설명 |
|------|------|------|
| `isolatedState` | `object` | 격리된 상태 초기값 |
| `isolatedScopeId` | `string` | (선택) DevTools 식별용 스코프 ID |

### 액션에서 업데이트

`setState` 핸들러에서 `target: "isolated"` 사용:

```json
{
  "handler": "setState",
  "params": {
    "target": "isolated",
    "currentStep": 2,
    "selectedItems": ["{{item.id}}"]
  }
}
```

### 바인딩에서 접근

```json
{
  "text": "{{_isolated.selectedItems.length}}개 선택됨",
  "if": "{{_isolated.currentStep === 2}}",
  "props": {
    "selected": "{{_isolated.selectedItems.includes(item.id)}}"
  }
}
```

### _isolated 라이프사이클

```text
1. 생성: isolatedState 속성이 있는 컴포넌트 마운트 시
2. 업데이트: setState target:"isolated" 또는 isolatedContext.mergeState()
3. 소멸: 해당 컴포넌트 언마운트 시
4. 페이지 이동: 초기화됨
5. 모달 열기/닫기: 모달 내 isolated는 독립, 부모 isolated 유지
```

### 사용 예시: 카테고리 선택

```json
{
  "type": "Div",
  "isolatedState": {
    "selectedCategories": [null, null, null, null],
    "currentLevel": 0
  },
  "children": [
    {
      "type": "basic",
      "name": "CategoryLevel",
      "props": {
        "level": 0,
        "selected": "{{_isolated.selectedCategories[0]}}"
      },
      "actions": [
        {
          "type": "click",
          "handler": "setState",
          "params": {
            "target": "isolated",
            "selectedCategories": "{{[..._isolated.selectedCategories.slice(0, 0), $event.id, null, null, null]}}",
            "currentLevel": 1
          }
        }
      ]
    }
  ]
}
```

### _global vs _local vs _isolated 비교

| 항목 | `_global` | `_local` | `_isolated` |
|------|-----------|----------|-------------|
| 범위 | 전체 앱 | 현재 레이아웃 | 격리된 컴포넌트 |
| 용도 | 사이드바, 테마 | 폼 데이터, 필터 | 독립 UI 영역 |
| 접근 | `{{_global.xxx}}` | `{{_local.xxx}}` | `{{_isolated.xxx}}` |
| target | `"global"` | `"local"` | `"isolated"` |
| 리렌더링 | 전체 앱 | 전체 레이아웃 | 해당 영역만 |
| 페이지 이동 | 유지 | 레이아웃 전환: 초기화 / 같은 레이아웃: 유지 | 초기화 |

### 언제 _isolated를 사용할까?

| 사용 O | 사용 X |
|--------|--------|
| 카테고리/태그 선택 UI | 폼 전체 데이터 |
| 드래그 앤 드롭 상태 | 다른 컴포넌트와 공유할 상태 |
| 아코디언 열림/닫힘 | API 호출 결과 |
| 멀티 셀렉트 체크박스 | 에러 메시지 |
| 자주 변경되는 임시 상태 | 필터/정렬 설정 |

> **주의**: `target: "isolated"`는 `isolatedState` 속성이 정의된 컴포넌트 내에서만 동작합니다. 격리 스코프 외부에서 호출 시 `_local`로 폴백되며 경고 로그가 출력됩니다.

---

## 주의사항

### 권장 사항 (DO)

| 항목 | 설명 |
|------|------|
| ✅ UI 상태 관리 전용 | 전역 상태는 UI 상태 관리에만 사용 |
| ✅ 비즈니스 로직은 API | 비즈니스 로직은 API에서 처리 |
| ✅ target 명확히 지정 | `"global"` 사용 시 `_global.속성`으로 접근 |
| ✅ 표현식은 DataBindingEngine | payload 표현식은 DataBindingEngine이 평가 |
| ✅ 자동 재렌더링 활용 | 상태 변경 시 자동 재렌더링 |

### 주의 사항 (CAUTION)

| 항목 | 설명 |
|------|------|
| 캐싱 안 됨 | 전역 상태는 캐싱되지 않음 (항상 최신 값) |
| 과도한 사용 지양 | 너무 많은 전역 상태는 성능에 영향 |
| 컴포넌트 로컬 우선 | 가능하면 컴포넌트 로컬 상태 사용 |
| 영향 범위 인지 | 전역 상태는 모든 컴포넌트에 영향 |

### 언제 전역 상태를 사용할까?

| 사용 O | 사용 X |
|--------|--------|
| 사이드바 열림/닫힘 | 폼 입력값 |
| 다크모드 상태 | 단일 컴포넌트 토글 |
| 전역 로딩 표시 | API 응답 데이터 |
| 모달 열림 상태 | 비즈니스 로직 상태 |

---

## 관련 문서

- [폼 자동 바인딩 및 setState](state-management-forms.md) - FormContext, 깊은 병합, payload 표현식
- [고급 상태 관리](state-management-advanced.md) - 예약 상태, G7Core.state API, 사용 사례
- [g7core-api.md](g7core-api.md) - G7Core 전역 API 레퍼런스
- [components.md](components.md) - 컴포넌트 개발 규칙
- [data-binding.md](data-binding.md) - 데이터 바인딩 문법
- [layout-json.md](layout-json.md) - 레이아웃 JSON 스키마 (모달 시스템 포함)
- [responsive-layout.md](responsive-layout.md) - 반응형 레이아웃 (전역 상태 활용)
