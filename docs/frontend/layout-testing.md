# 그누보드7 레이아웃 파일 렌더링 테스트 가이드

> 이 문서는 레이아웃 JSON 파일을 실제 렌더링하고 런타임 동작을 검증하는 테스트 시스템 사용법을 설명합니다.

---

## TL;DR (5초 요약)

```text
1. createLayoutTest()로 테스트 헬퍼 생성, mockApi()로 API 응답 모킹
2. render() 후 validateSlots()와 validateDataBindings()로 레이아웃 검증
3. assertNoValidationErrors()로 슬롯 누락/타입 불일치 자동 감지
4. API 응답 구조: { success: true, data: { data: [], meta: {} } }
5. cleanup() 필수 호출로 테스트 격리
```

---

## 목차

- [개요](#개요)
- [파일 위치](#파일-위치)
- [기본 사용법](#기본-사용법)
- [API 레퍼런스](#api-레퍼런스)
- [테스트 패턴](#테스트-패턴)
- [레이아웃 검증 기능](#레이아웃-검증-기능)
- [테스트 실행](#테스트-실행)
- [체크리스트](#체크리스트)

---

## 개요

### 목적

레이아웃 테스트 시스템은 다음 목적으로 사용됩니다:

| 용도 | 설명 |
|------|------|
| **TDD** | 새 기능/UI 개발 시 요구사항 커버리지 확인 |
| **BDD** | 개발 후 동작 검증 |
| **회귀 테스트** | 버그 수정 후 재발 방지 |

### 핵심 특징

- **실제 렌더링**: DynamicRenderer를 통한 실제 React 렌더링
- **API 모킹**: fetch 모킹으로 데이터소스 응답 제어
- **상태 관리**: `_global`, `_local` 상태 조작 및 검증
- **액션 트리거**: navigate, setState 등 액션 실행
- **모달/토스트**: UI 피드백 추적
- **다국어**: TranslationEngine 연동

---

## 파일 위치

### 테스트 유틸리티 (공유)

```
resources/js/core/template-engine/__tests__/
├── utils/
│   ├── mockApiUtils.ts       # API 모킹 유틸리티
│   └── layoutTestUtils.ts    # 레이아웃 테스트 헬퍼
└── layouts/
    └── example.test.tsx      # createLayoutTest() 사용 예제 (코어 전용)
```

### 레이아웃 렌더링 테스트 위치 규칙

```
필수: 레이아웃 렌더링 테스트는 해당 레이아웃이 속한 확장(모듈/템플릿) 디렉토리에 작성 (코어 디렉토리에 모듈/템플릿 테스트 작성 금지)
코어 __tests__/layouts/에는 example.test.tsx(유틸리티 예제)만 존재
```

| 레이아웃 소속 | 테스트 파일 위치 |
|-------------|-----------------|
| 모듈 (`modules/**/resources/layouts/**`) | `modules/_bundled/{id}/resources/js/__tests__/layouts/*.test.tsx` |
| 템플릿 (`templates/**/layouts/**`) | `templates/_bundled/{id}/__tests__/layouts/*.test.tsx` |
| 코어 (`resources/layouts/**`) | `resources/js/core/template-engine/__tests__/layouts/*.test.tsx` |

### 확장 디렉토리 테스트 구조

```
modules/_bundled/{vendor-module}/
├── resources/
│   ├── layouts/admin/*.json           # ← 레이아웃 JSON
│   └── js/__tests__/
│       └── layouts/*.test.tsx         # ← 해당 레이아웃 테스트
├── package.json
└── vitest.config.ts

templates/_bundled/{vendor-template}/
├── layouts/**/*.json                  # ← 레이아웃 JSON
├── __tests__/
│   └── layouts/*.test.tsx             # ← 해당 레이아웃 테스트
└── vitest.config.ts
```

### 확장 vitest.config.ts 필수 설정

모듈/템플릿에서 `createLayoutTest()`를 사용하려면 `@core` alias가 필수입니다:

```typescript
resolve: {
    alias: {
        '@core': path.resolve(projectRoot, 'resources/js/core'),
    },
},
```

테스트 파일에서의 import 패턴:

```typescript
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
```

### jsdom 환경 설정

모듈의 vitest.config.ts가 `environment: 'node'`인 경우, 레이아웃 테스트 파일 상단에 per-file 디렉티브를 추가합니다:

```typescript
// @vitest-environment jsdom
import '@testing-library/jest-dom';
```

---

## 기본 사용법

### 1. 테스트 헬퍼 생성

```typescript
import { createLayoutTest, screen } from '../utils/layoutTestUtils';

const layoutJson = {
  version: '1.0.0',
  layout_name: 'test_layout',
  components: [
    {
      id: 'page-header',
      type: 'composite',
      name: 'PageHeader',
      props: { title: '$t:test.title' },
    },
    {
      id: 'filter-button',
      type: 'basic',
      name: 'Button',
      text: '필터',
      props: { 'data-testid': 'filter-button' },
    },
  ],
  data_sources: [
    {
      id: 'products',
      type: 'api',
      endpoint: '/api/admin/products',
      auto_fetch: true,
    },
  ],
};

const testUtils = createLayoutTest(layoutJson, {
  translations: { test: { title: '테스트 페이지' } },
  locale: 'ko',
});
```

### 2. API 모킹 및 렌더링

```typescript
// API 응답 모킹 (필수: render() 전에 호출)
testUtils.mockApi('products', {
  response: { data: [{ id: 1, name: '상품1' }] },
});

// 렌더링
await testUtils.render();

// 요소 검증
expect(screen.getByText('테스트 페이지')).toBeInTheDocument();
expect(screen.getByTestId('filter-button')).toBeInTheDocument();
```

### 3. 정리 (필수)

```typescript
afterEach(() => {
  testUtils.cleanup();
});
```

---

## API 레퍼런스

### createLayoutTest(layoutJson, options)

레이아웃 테스트 헬퍼를 생성합니다.

#### Options

| 옵션 | 타입 | 설명 |
|------|------|------|
| `initialState` | `{ _local?: object, _global?: object }` | 초기 상태 |
| `routeParams` | `Record<string, string>` | URL 파라미터 (route.id 등) |
| `queryParams` | `Record<string, string>` | 쿼리 파라미터 |
| `auth` | `{ isAuthenticated, user?, authType? }` | 인증 상태 |
| `translations` | `Record<string, any>` | 다국어 데이터 |
| `locale` | `'ko' \| 'en'` | 현재 로케일 |
| `initialData` | `Record<string, any>` | 데이터소스 초기 데이터 |
| `componentRegistry` | `ComponentRegistry` | 컴포넌트 레지스트리 |
| `templateId` | `string` | 템플릿 ID |

#### 반환 객체

| 메서드 | 설명 |
|--------|------|
| `render()` | 레이아웃 렌더링 |
| `rerender()` | 리렌더링 |
| `mockApi(id, options)` | API 응답 모킹 |
| `mockApiError(id, status, message)` | API 에러 모킹 |
| `getState()` | 현재 상태 조회 (`_local`, `_global`) |
| `setState(path, value, target)` | 상태 설정 |
| `triggerAction(actionDef)` | 액션 트리거 |
| `openModal(modalId)` | 모달 열기 |
| `closeModal()` | 모달 닫기 |
| `getModalStack()` | 모달 스택 조회 |
| `getToasts()` | 토스트 목록 조회 |
| `getNavigationHistory()` | 네비게이션 이력 |
| `mockNavigate` | navigate mock 함수 |
| `waitForDataSource(id)` | 데이터소스 로딩 대기 |
| `waitForState(path, value)` | 상태 대기 |
| `cleanup()` | 정리 (필수) |
| `user` | userEvent 인스턴스 |
| `screen` | React Testing Library screen |

### mockApi(dataSourceId, options)

데이터소스 API 응답을 모킹합니다.

```typescript
// 성공 응답
testUtils.mockApi('products', {
  response: { data: [{ id: 1, name: '상품' }] },
});

// 지연 시뮬레이션
testUtils.mockApi('products', {
  response: { data: [] },
  delay: 1000, // 1초 지연
});

// 간단한 형태
testUtils.mockApi('products', { data: [] });
```

### mockApiError(dataSourceId, status, message)

API 에러를 시뮬레이션합니다.

```typescript
testUtils.mockApiError('products', 500, '서버 에러');
testUtils.mockApiError('products', 404, '리소스를 찾을 수 없습니다');
```

### setState(path, value, target)

상태를 설정합니다.

```typescript
// local 상태
testUtils.setState('filterVisible', true, 'local');
testUtils.setState('filter.keyword', '검색어', 'local');

// global 상태
testUtils.setState('user', { id: 1, name: 'Admin' }, 'global');
```

### triggerAction(actionDef)

액션을 트리거합니다.

```typescript
await testUtils.triggerAction({
  type: 'click',
  handler: 'navigate',
  params: { path: '/admin/products/1/edit' },
});

await testUtils.triggerAction({
  type: 'click',
  handler: 'setState',
  params: { target: 'local', filterVisible: true },
});
```

---

## 테스트 패턴

### 컴포넌트 레지스트리 설정

테스트에서는 실제 컴포넌트 대신 간단한 테스트 컴포넌트를 사용합니다:

```typescript
import { ComponentRegistry } from '../../ComponentRegistry';

// 테스트용 컴포넌트
const TestButton: React.FC<{ text?: string; onClick?: () => void }> =
  ({ text, onClick }) => <button onClick={onClick}>{text}</button>;

const TestInput: React.FC<{ placeholder?: string }> =
  ({ placeholder }) => <input placeholder={placeholder} />;

// 레지스트리 설정
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Button: {
      component: TestButton,
      metadata: { name: 'Button', type: 'basic' },
    },
    Input: {
      component: TestInput,
      metadata: { name: 'Input', type: 'basic' },
    },
  };

  return registry;
}

// 사용
const registry = setupTestRegistry();
const testUtils = createLayoutTest(layoutJson, {
  componentRegistry: registry,
});
```

### 조건부 렌더링 테스트

```typescript
it('조건부 렌더링이 동작한다', async () => {
  const layoutJson = {
    components: [
      {
        id: 'panel',
        type: 'composite',
        name: 'Panel',
        if: '{{_local.visible}}',
        props: { 'data-testid': 'panel' },
      },
    ],
  };

  const testUtils = createLayoutTest(layoutJson, { componentRegistry: registry });
  testUtils.mockApi('products', { response: { data: [] } });

  await testUtils.render();

  // 초기: 보이지 않음
  expect(screen.queryByTestId('panel')).not.toBeInTheDocument();

  // 상태 변경 후 리렌더링
  testUtils.setState('visible', true, 'local');
  await testUtils.rerender();

  expect(screen.getByTestId('panel')).toBeInTheDocument();

  testUtils.cleanup();
});
```

### iteration 테스트

```typescript
it('iteration에서 각 행이 올바른 데이터를 참조한다', async () => {
  const layoutJson = {
    components: [
      {
        id: 'list',
        type: 'layout',
        name: 'Grid',
        iteration: {
          source: 'products.data',
          item_var: 'product',
          index_var: 'idx',
        },
        children: [
          {
            id: 'item-{{idx}}',
            type: 'basic',
            name: 'Text',
            text: '{{product.name}}',
          },
        ],
      },
    ],
  };

  const testUtils = createLayoutTest(layoutJson, {
    componentRegistry: registry,
    initialData: {
      products: {
        data: [
          { id: 1, name: '첫번째' },
          { id: 2, name: '두번째' },
        ],
      },
    },
  });

  await testUtils.render();

  expect(screen.getByText('첫번째')).toBeInTheDocument();
  expect(screen.getByText('두번째')).toBeInTheDocument();

  testUtils.cleanup();
});
```

### 토스트/모달 테스트

```typescript
it('토스트가 표시된다', async () => {
  testUtils.mockApi('products', { response: { data: [] } });
  await testUtils.render();

  // G7Core.toast 직접 호출
  const G7Core = (window as any).G7Core;
  G7Core.toast.success('저장되었습니다');

  const toasts = testUtils.getToasts();
  expect(toasts).toContainEqual({
    type: 'success',
    message: '저장되었습니다',
  });

  testUtils.cleanup();
});

it('모달이 열린다', async () => {
  testUtils.mockApi('products', { response: { data: [] } });
  await testUtils.render();

  // G7Core.modal 직접 호출
  const G7Core = (window as any).G7Core;
  G7Core.modal.open('confirm-modal');

  expect(testUtils.getModalStack()).toContain('confirm-modal');

  testUtils.cleanup();
});
```

### 인증 상태 테스트

```typescript
it('인증된 상태로 렌더링한다', async () => {
  const testUtils = createLayoutTest(layoutJson, {
    auth: {
      isAuthenticated: true,
      user: { id: 1, name: 'Admin', role: 'super_admin' },
      authType: 'admin',
    },
    componentRegistry: registry,
  });

  testUtils.mockApi('products', { response: { data: [] } });
  await testUtils.render();

  // 인증된 사용자만 볼 수 있는 요소 확인
  expect(screen.getByText('관리자')).toBeInTheDocument();

  testUtils.cleanup();
});
```

---

## 레이아웃 검증 기능

레이아웃 테스트 유틸리티는 런타임에 발생할 수 있는 버그를 사전에 감지하는 검증 기능을 제공합니다.

### 검증 API

| 메서드 | 설명 |
|--------|------|
| `validateSlots()` | 슬롯 시스템 검증 (slot 속성 vs SlotContainer 매칭) |
| `validateDataBindings()` | 데이터 바인딩 타입 검증 (배열/객체 불일치) |
| `getValidationWarnings()` | 모든 검증 경고 조회 |
| `assertNoValidationErrors()` | 검증 오류 시 예외 발생 |

### API 응답 구조

mockApi로 설정한 응답은 자동으로 그누보드7 API 응답 구조로 래핑됩니다:

```
mockApi 설정 → fetch mock → 실제 응답 구조

mockApi('coupons', { response: { data: [], meta: {} } })
                          ↓
fetch().json() = { success: true, data: { data: [], meta: {} } }
                          ↓
레이아웃에서 접근:
- coupons = { success: true, data: { data: [], meta: {} } }
- coupons?.data = { data: [], meta: {} }  ← 객체!
- coupons?.data?.data = []  ← 배열! (올바른 바인딩)
```

### 슬롯 시스템 검증 (validateSlots)

`slot` 속성으로 등록된 컴포넌트에 대응하는 `SlotContainer`가 있는지 검증합니다.

#### 검증 항목

| 타입 | 설명 |
|------|------|
| `missing_slotcontainer` | slot 속성이 있지만 SlotContainer가 없음 (버그) |
| `orphan_slot` | SlotContainer가 있지만 등록된 컴포넌트가 없음 (정보) |

#### 버그 있는 레이아웃 예시

```json
{
  "slots": {
    "content": [
      {
        "id": "filter_row",
        "type": "basic",
        "name": "Div",
        "slot": "basic_filters"
      },
      {
        "id": "filter_display",
        "type": "basic",
        "name": "Div",
        "slot": "basic_filters"
      }
    ]
  }
}
```

위 레이아웃에서 `filter_row`는 `basic_filters` 슬롯에 등록되지만, 해당 슬롯을 렌더링하는 `SlotContainer`가 없어서 화면에 표시되지 않습니다.

#### 올바른 레이아웃

```json
{
  "slots": {
    "content": [
      {
        "id": "filter_row",
        "type": "basic",
        "name": "Div",
        "slot": "basic_filters"
      },
      {
        "id": "filter_container",
        "type": "composite",
        "name": "SlotContainer",
        "props": { "slotId": "basic_filters" }
      }
    ]
  }
}
```

### 데이터 바인딩 타입 검증 (validateDataBindings)

배열이 필요한 컴포넌트 prop에 객체가 바인딩되는지 검증합니다.

#### 검증 대상 컴포넌트

| 컴포넌트 | 배열 필요 props |
|----------|----------------|
| `DataGrid` | `data`, `rows` |
| `Table` | `data`, `rows` |
| `Select` | `options` |
| `RichSelect` | `options` |
| `CheckboxGroup` | `options` |
| `RadioGroup` | `options` |

#### 타입 불일치 예시

```json
{
  "props": {
    "data": "{{coupons?.data || []}}"
  }
}
```

`coupons?.data`는 `{ data: [], meta: {} }` 객체를 반환합니다. DataGrid는 배열을 기대하므로 `data_type_mismatch` 경고가 발생합니다.

#### 올바른 바인딩

```json
{
  "props": {
    "data": "{{coupons?.data?.data || []}}"
  }
}
```

### 검증 사용 예시

```typescript
it('레이아웃 검증 오류가 없어야 함', async () => {
  const { render, assertNoValidationErrors, cleanup } = createLayoutTest(layoutJson);

  mockApi('items', {
    response: {
      data: [{ id: 1 }],
      meta: { total: 1 }
    }
  });

  await render();

  // 모든 검증 통과 확인
  expect(() => assertNoValidationErrors()).not.toThrow();

  cleanup();
});

it('개별 검증 경고 확인', async () => {
  const { render, validateSlots, validateDataBindings, cleanup } = createLayoutTest(layoutJson);

  await render();

  const slotWarnings = validateSlots();
  const bindingWarnings = validateDataBindings();

  // 특정 경고 타입 확인
  expect(slotWarnings.some(w => w.type === 'missing_slotcontainer')).toBe(false);
  expect(bindingWarnings.some(w => w.type === 'data_type_mismatch')).toBe(false);

  cleanup();
});
```

---

## 테스트 실행

### 명령어

```powershell
# 레이아웃 테스트만 실행
powershell -Command "npm run test:run -- layouts/example.test"

# 유틸리티 테스트 실행
powershell -Command "npm run test:run -- __tests__/utils"

# 특정 테스트 파일
powershell -Command "npm run test:run -- layoutTestUtils"

# 전체 템플릿 엔진 테스트
powershell -Command "npm run test:run -- template-engine"
```

### 테스트 파일 네이밍

```
__tests__/layouts/
├── [feature].test.tsx         # 기능별 테스트
├── [screen_name].test.tsx     # 화면별 테스트
└── regression/
    └── [issue_id].test.tsx    # 회귀 테스트 (버그 번호)
```

---

## 체크리스트

### 테스트 작성 전

```
□ 테스트할 레이아웃 JSON 준비
□ 필요한 컴포넌트 목록 확인
□ 테스트용 컴포넌트 레지스트리 설정
□ 다국어 키 준비 ($t: 사용 시)
```

### 테스트 작성 중

```
□ mockApi() 호출 후 render() 호출
□ 비동기 작업 후 waitFor() 또는 findBy* 사용
□ screen.getByTestId() 또는 getByText()로 요소 검증
□ 상태 변경 후 rerender() 호출
```

### 테스트 작성 후

```
□ cleanup() 호출 확인 (afterEach)
□ 테스트 실행 및 통과 확인
□ 테스트 격리 확인 (다른 테스트에 영향 없음)
```

### 금지 사항

```
❌ cleanup() 없이 테스트 종료
❌ render() 전에 screen API 호출
❌ mockApi() 없이 auto_fetch 데이터소스 사용
❌ 비동기 작업 후 즉시 assertion (waitFor 필요)
```

---

## 관련 문서

- [testing-guide.md](../testing-guide.md) - 전체 테스트 가이드
- [layout-json.md](layout-json.md) - 레이아웃 JSON 스키마
- [data-sources.md](data-sources.md) - 데이터소스 정의
- [state-management.md](state-management.md) - 상태 관리
- [actions.md](actions.md) - 액션 핸들러
