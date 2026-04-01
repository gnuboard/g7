# 그누보드7 프론트엔드 개발 가이드

> 이 문서는 그누보드7의 프론트엔드 및 템플릿 시스템 개발 규칙의 인덱스입니다.

---

## 핵심 원칙

```
필수: 기본 컴포넌트 사용 (HTML 태그 직접 사용 금지)
필수: 기본 컴포넌트만 사용 (Div, Button, H2 등)
✅ 필수: 집합 컴포넌트 재사용 우선
✅ 필수: 다크 모드 light/dark variant 함께 지정
```

---

## 템플릿 시스템 개요

그누보드7 템플릿 엔진은 JSON 기반 레이아웃 정의를 통해 화면을 동적으로 생성하는 시스템입니다.

**핵심 특징**:
- JSON 기반 레이아웃 정의 (코드 수정 없이 UI 변경)
- 컴포넌트 기반 아키텍처 (재사용성)
- 동적 데이터 바인딩 (API 자동 연결)
- 자동 발견 메커니즘 (`/templates` 디렉토리 스캔)
- 코어 렌더링 엔진과 템플릿 컴포넌트 분리

**템플릿 타입**:

| 타입 | 레이아웃 편집 | 버전 히스토리 | 용도 |
|------|-------------|--------------|------|
| admin | ❌ 불가 | ❌ 미사용 | 관리자 인터페이스 |
| user | ✅ 가능 | ✅ 자동 생성 | 사용자 웹사이트 |

---

<!-- AUTO-GENERATED-START: frontend-readme-docs -->
### 템플릿별 레퍼런스

| 템플릿 식별자 | 컴포넌트 | 핸들러 | 레이아웃 |
|--------------|---------|--------|--------|
| `sirsoft-admin_basic` | [components.md](templates/sirsoft-admin_basic/components.md) | [handlers.md](templates/sirsoft-admin_basic/handlers.md) | [layouts.md](templates/sirsoft-admin_basic/layouts.md) |
| `sirsoft-basic` | [components.md](templates/sirsoft-basic/components.md) | [handlers.md](templates/sirsoft-basic/handlers.md) | [layouts.md](templates/sirsoft-basic/layouts.md) |

### 컴포넌트 개발

| 문서 | 설명 |
|------|------|
| [component-props-composite.md](component-props-composite.md) | 컴포넌트 Props 레퍼런스 - Composite |
| [component-props.md](component-props.md) | 컴포넌트 Props 레퍼런스 |
| [components-advanced.md](components-advanced.md) | 컴포넌트 고급 기능 |
| [components-patterns.md](components-patterns.md) | 컴포넌트 패턴 및 다국어 |
| [components-types.md](components-types.md) | 컴포넌트 타입별 개발 규칙 |
| [components.md](components.md) | 컴포넌트 개발 규칙 |
| [layout-json-components-loading.md](layout-json-components-loading.md) | 레이아웃 JSON - 데이터 로딩 및 생명주기 |
| [layout-json-components-rendering.md](layout-json-components-rendering.md) | 레이아웃 JSON - 조건부/반복 렌더링 |
| [layout-json-components-slots.md](layout-json-components-slots.md) | 레이아웃 JSON - 슬롯 시스템 |
| [layout-json-components.md](layout-json-components.md) | 레이아웃 JSON - 컴포넌트 (반복 렌더링, Blur, 생명주기, 슬롯) |

### 레이아웃 및 데이터

| 문서 | 설명 |
|------|------|
| [actions-g7core-api.md](actions-g7core-api.md) | 액션 시스템 - G7Core API (React 컴포넌트용) |
| [actions-handlers-navigation.md](actions-handlers-navigation.md) | 액션 핸들러 - 네비게이션 |
| [actions-handlers-state.md](actions-handlers-state.md) | 액션 핸들러 - 상태 관리 |
| [actions-handlers-ui.md](actions-handlers-ui.md) | 액션 핸들러 - UI 인터랙션 |
| [actions-handlers.md](actions-handlers.md) | 액션 핸들러 - 핸들러별 상세 사용법 |
| [actions.md](actions.md) | 액션 핸들러 가이드 |
| [data-binding-i18n.md](data-binding-i18n.md) | 데이터 바인딩 - 다국어 처리 |
| [data-binding.md](data-binding.md) | 데이터 바인딩 및 표현식 |
| [data-sources-advanced.md](data-sources-advanced.md) | 데이터 소스 - 고급 기능 |
| [data-sources.md](data-sources.md) | 데이터 소스 (Data Sources) |
| [g7core-api-advanced.md](g7core-api-advanced.md) | G7Core 전역 API 레퍼런스 - 고급 |
| [g7core-api.md](g7core-api.md) | G7Core 전역 API 레퍼런스 |
| [g7core-helpers.md](g7core-helpers.md) | G7Core 헬퍼 API |
| [layout-json-components-loading.md](layout-json-components-loading.md) | 레이아웃 JSON - 데이터 로딩 및 생명주기 |
| [layout-json-components-rendering.md](layout-json-components-rendering.md) | 레이아웃 JSON - 조건부/반복 렌더링 |
| [layout-json-components-slots.md](layout-json-components-slots.md) | 레이아웃 JSON - 슬롯 시스템 |
| [layout-json-components.md](layout-json-components.md) | 레이아웃 JSON - 컴포넌트 (반복 렌더링, Blur, 생명주기, 슬롯) |
| [layout-json-features-actions.md](layout-json-features-actions.md) | 레이아웃 JSON - 초기화, 모달, 액션, 스크립트 |
| [layout-json-features-error.md](layout-json-features-error.md) | 레이아웃 JSON - 에러 핸들링 |
| [layout-json-features-styling.md](layout-json-features-styling.md) | 레이아웃 JSON - 스타일 및 계산된 값 |
| [layout-json-features.md](layout-json-features.md) | 레이아웃 JSON - 기능 (에러 핸들링, 초기화, 모달, 액션) |
| [layout-json-inheritance.md](layout-json-inheritance.md) | 레이아웃 JSON - 상속 (Extends, Partial, 병합) |
| [layout-json.md](layout-json.md) | 레이아웃 JSON 스키마 |
| [state-management-advanced.md](state-management-advanced.md) | 상태 관리 - 고급 기능 |
| [state-management-forms.md](state-management-forms.md) | 상태 관리 - 폼 자동 바인딩 및 setState |
| [state-management.md](state-management.md) | 전역 상태 관리 |

### 스타일링 및 UI

| 문서 | 설명 |
|------|------|
| [dark-mode.md](dark-mode.md) | 다크 모드 지원 (engine-v1.1.0+) |
| [responsive-layout.md](responsive-layout.md) | 반응형 레이아웃 개발 (engine-v1.1.0+) |
| [tailwind-safelist.md](tailwind-safelist.md) | Tailwind Safelist 가이드 |

### 템플릿 개발

| 문서 | 설명 |
|------|------|
| [editors.md](editors.md) | 에디터 컴포넌트 가이드 |
| [template-development.md](template-development.md) | 템플릿 개발 가이드라인 |
| [template-handlers.md](template-handlers.md) | 템플릿 전용 핸들러 |

### 테스트

| 문서 | 설명 |
|------|------|
| [layout-testing.md](layout-testing.md) | 그누보드7 레이아웃 파일 렌더링 테스트 가이드 |

### 인증 및 보안

| 문서 | 설명 |
|------|------|
| [auth-system.md](auth-system.md) | 인증 시스템 (AuthManager) |
| [modal-usage.md](modal-usage.md) | Modal 컴포넌트 사용 가이드 |
| [security.md](security.md) | 보안 및 검증 |


<!-- AUTO-GENERATED-END: frontend-readme-docs -->

---

## 컴포넌트 타입 요약

| 타입 | 설명 | 예시 |
|------|------|------|
| basic | HTML 태그 래핑 | Button, Input, Div, Icon |
| composite | 기본 컴포넌트 조합 | Card, Modal, PageHeader, DataGrid |
| layout | 자식 요소 배치 | Container, Grid, Flex |

---

## 데이터 바인딩 문법 (빠른 참조)

```json
{
  "props": {
    "title": "{{user.name}}",              // API 데이터
    "userId": "{{route.id}}",              // URL 파라미터
    "label": "$t:dashboard.title",         // 다국어
    "total": "{{users?.data?.total ?? 0}}" // Optional Chaining + Nullish Coalescing
  }
}
```

---

## 다크 모드 색상 매핑 (빠른 참조)

```
배경:   bg-white dark:bg-gray-800
테두리: border-gray-200 dark:border-gray-700
텍스트: text-gray-900 dark:text-white
강조:   bg-green-50 dark:bg-green-900/20
```

---

## 관련 문서

- [AGENTS.md](../../../AGENTS.md) - 그누보드7 개발 가이드 (메인)
- [backend/](../backend/) - 백엔드 개발 규칙
- [database-guide.md](../database-guide.md) - 데이터베이스 규칙
- [testing-guide.md](../testing-guide.md) - 테스트 규칙