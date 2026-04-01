# G7Core 헬퍼 API

> **관련 문서**: [data-binding.md](data-binding.md) | [components.md](components.md) | [template-development.md](template-development.md)

---

## 목차

1. [개요](#개요)
2. [renderItemChildren](#renderitemchildren)
3. [헬퍼 모듈 구조](#헬퍼-모듈-구조)
4. [향후 확장](#향후-확장)

---

## 개요

`G7Core`는 템플릿 엔진이 전역으로 노출하는 네임스페이스입니다. 템플릿 개발자가 커스텀 composite 컴포넌트에서 템플릿 엔진의 기능을 활용할 수 있도록 헬퍼 함수들을 제공합니다.

### 핵심 원칙

```text
✅ 템플릿 개발자는 엔진 내부 구조를 이해하지 않아도 됨
✅ 헬퍼 함수를 통해 표현식 평가, 다국어 번역 등 엔진 기능 활용
✅ 반복 렌더링 시 캐싱 문제 자동 처리
```

### 사용 방법

```tsx
// 컴포넌트 내에서 G7Core 접근
const G7Core = (window as any).G7Core;

if (G7Core?.renderItemChildren) {
  // 헬퍼 함수 사용
}
```

---

## renderItemChildren

반복 아이템의 자식 컴포넌트를 렌더링합니다. DataGrid의 `cellChildren`, ul/li 패턴 등 반복 컨텍스트에서 사용합니다.

### 시그니처

```typescript
function renderItemChildren(
  children: ComponentDefinition[],
  itemContext: Record<string, any>,
  componentMap: Record<string, React.ComponentType<any>>,
  keyPrefix?: string
): React.ReactNode[]
```

### 파라미터

| 파라미터 | 타입 | 필수 | 설명 |
|----------|------|------|------|
| `children` | `ComponentDefinition[]` | ✅ | 렌더링할 컴포넌트 정의 배열 |
| `itemContext` | `Record<string, any>` | ✅ | 반복 아이템 컨텍스트 (row, value, index 등) |
| `componentMap` | `Record<string, React.ComponentType>` | ✅ | 컴포넌트 맵 |
| `keyPrefix` | `string` | ❌ | React key 접두사 |

### 반환값

`React.ReactNode[]` - 렌더링된 React 노드 배열

### 지원 기능

| 기능 | 설명 |
|------|------|
| 데이터 바인딩 | `{{row.name}}`, `{{value}}` 등 |
| 복잡한 표현식 | 삼항 연산자, 논리 연산자 |
| 다국어 번역 | `$t:key` 형식 |
| **조건부 렌더링** | `if` 속성 지원 (중첩 자식도 평가) |
| 캐시 비활성화 | 반복 렌더링 시 자동 적용 |
| 반응형 오버라이드 | `responsive` 속성 지원 |

### 사용 예시

#### DataGrid의 cellChildren 렌더링

```tsx
// DataGrid.tsx
const renderCellChildren = (
  cellChildren: DataGridCellChild[],
  row: any,
  value: any,
  keyPrefix: string = ''
): React.ReactNode => {
  const G7Core = (window as any).G7Core;

  if (G7Core?.renderItemChildren) {
    // 조건부 필터링 (condition 처리)
    const filteredChildren = cellChildren.filter((child) => {
      if (!child.condition) return true;
      return evaluateCondition(child.condition, row, value);
    });

    // 컨텍스트 구성
    const context = {
      row,      // 현재 행 데이터
      value,    // 현재 셀 값
      $value: value,  // 별칭
    };

    return G7Core.renderItemChildren(
      filteredChildren,
      context,
      componentMap,
      keyPrefix
    );
  }

  console.warn('[DataGrid] G7Core.renderItemChildren을 사용할 수 없습니다.');
  return null;
};
```

#### 리스트 아이템 렌더링

```tsx
// CustomList.tsx
const renderListItems = (items: any[], itemTemplate: ComponentDefinition[]) => {
  const G7Core = (window as any).G7Core;

  if (!G7Core?.renderItemChildren) return null;

  return items.map((item, index) => (
    <li key={`item-${index}`}>
      {G7Core.renderItemChildren(
        itemTemplate,
        { item, index },
        componentMap,
        `list-${index}`
      )}
    </li>
  ));
};
```

### 레이아웃 JSON 예시

`renderItemChildren`이 처리하는 레이아웃 JSON 구조:

```json
{
  "columns": [
    {
      "key": "status",
      "label": "$t:user.status",
      "cellChildren": [
        {
          "id": "status_badge",
          "type": "basic",
          "name": "Span",
          "props": {
            "className": "{{row.status_variant === 'success' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}} px-2.5 py-0.5 rounded-full text-xs font-medium"
          },
          "text": "{{row.status_label}}"
        }
      ]
    }
  ]
}
```

#### if 조건부 렌더링 예시

상태에 따라 다른 라벨을 표시하는 패턴:

```json
{
  "cellChildren": [
    {
      "id": "active-label",
      "type": "basic",
      "name": "Span",
      "if": "{{row.status == 'active'}}",
      "props": { "className": "text-green-600" },
      "text": "$t:status.active"
    },
    {
      "id": "inactive-label",
      "type": "basic",
      "name": "Span",
      "if": "{{row.status == 'inactive'}}",
      "props": { "className": "text-gray-500" },
      "text": "$t:status.inactive"
    }
  ]
}
```

**중요**: `renderItemChildren`은 중첩된 자식 컴포넌트의 `if` 조건도 평가합니다. DynamicRenderer와 동일한 로직(`evaluateIfCondition`)을 사용하여 일관성을 보장합니다.

### 캐싱 처리

`renderItemChildren`은 내부적으로 `skipCache: true` 옵션을 사용합니다.

**이유**: 반복 렌더링에서 같은 경로(`row.name`)가 다른 값을 가져야 하기 때문입니다.

```typescript
// 내부 동작 (RenderHelpers.ts)
return bindingEngine.resolve(singleBindingPath, context, { skipCache: true });
```

**캐싱을 비활성화하지 않으면**:
- 첫 번째 행의 `row.name` 값이 캐시됨
- 모든 행에서 같은 값이 표시됨 (버그)

---

## 헬퍼 모듈 구조

헬퍼 함수들은 카테고리별로 분리된 모듈 구조로 관리됩니다.

### 디렉토리 구조

```
resources/js/core/template-engine/helpers/
├── index.ts              # 통합 export (진입점)
├── RenderHelpers.ts      # 렌더링 관련 헬퍼
├── FormatHelpers.ts      # 포맷팅 헬퍼 (향후)
├── ValidationHelpers.ts  # 검증 헬퍼 (향후)
└── StyleHelpers.ts       # 스타일 헬퍼 (향후)
```

### 현재 제공되는 헬퍼

| 모듈 | 함수 | 설명 |
|------|------|------|
| RenderHelpers | `renderItemChildren` | 반복 아이템 자식 렌더링 |
| RenderHelpers | `bindComponentActions` | 액션 배열을 이벤트 핸들러로 변환 |
| RenderHelpers | `evaluateIfCondition` | if 조건 평가 (내부용, DynamicRenderer와 공유) |
| RenderHelpers | `resolveIterationSource` | iteration source 표현식 평가 |
| RenderHelpers | `resolveExpressionString` | 바인딩 표현식 문자열 치환 |
| RenderHelpers | `resolveExpressionObject` | 객체 내 바인딩 표현식 치환 |

### 새 헬퍼 추가 방법

1. 카테고리에 맞는 파일 생성 또는 수정

```typescript
// helpers/FormatHelpers.ts
export function formatCurrency(value: number, locale?: string): string {
  return new Intl.NumberFormat(locale || 'ko-KR', {
    style: 'currency',
    currency: 'KRW',
  }).format(value);
}
```

2. `helpers/index.ts`에서 re-export

```typescript
export { formatCurrency } from './FormatHelpers';
```

3. `template-engine.ts`에서 G7Core에 노출

```typescript
import { formatCurrency } from './template-engine/helpers';
(window as any).G7Core.formatCurrency = formatCurrency;
```

---

## bindComponentActions

컴포넌트의 `actions` 배열을 이벤트 핸들러 props로 변환합니다. DynamicRenderer와 renderItemChildren에서 동일한 로직을 공유합니다.

### 시그니처

```typescript
function bindComponentActions(
  props: Record<string, any>,
  actions: ActionDefinition[] | undefined,
  context: Record<string, any>,
  options?: BindComponentActionsOptions
): Record<string, any>
```

### 파라미터

| 파라미터 | 타입 | 필수 | 설명 |
|----------|------|------|------|
| `props` | `Record<string, any>` | ✅ | 컴포넌트의 기존 props |
| `actions` | `ActionDefinition[]` | ❌ | 레이아웃 JSON의 actions 배열 |
| `context` | `Record<string, any>` | ✅ | 데이터 바인딩 컨텍스트 |
| `options` | `BindComponentActionsOptions` | ❌ | 추가 옵션 |

### BindComponentActionsOptions

```typescript
interface BindComponentActionsOptions {
  componentContext?: {
    state?: any;
    setState?: (updates: any) => void;
  };
  actionDispatcher?: ActionDispatcher;
}
```

| 필드 | 설명 |
|------|------|
| `componentContext` | 로컬 상태 관리용 컨텍스트 |
| `actionDispatcher` | ActionDispatcher 인스턴스 (미제공 시 싱글톤 사용) |

### 반환값

`Record<string, any>` - 이벤트 핸들러가 추가된 props 객체

### 작동 원리

1. `actions` 배열이 없거나 비어있으면 원본 props 반환
2. ActionDispatcher의 `bindActionsToProps` 호출
3. 각 액션의 `event` 필드에 따라 해당 이벤트 핸들러 생성
   - `onClick`, `onChange`, `onSubmit`, `onItemClick` 등
4. `actions` prop 제거 (HTML 속성으로 렌더링 방지)

### 사용 예시

```typescript
// DynamicRenderer에서 사용
props = bindComponentActions(
  props,
  effectiveComponentDef.actions,
  extendedDataContext,
  { componentContext, actionDispatcher }
);

// renderItemChildren에서 사용
const finalProps = bindComponentActions(resolvedProps, childDef.actions, itemContext, {
  actionDispatcher: options?.actionDispatcher,
});
```

### 레이아웃 JSON 예시

```json
{
  "name": "Button",
  "props": { "variant": "primary" },
  "text": "설치",
  "actions": [
    {
      "type": "click",
      "handler": "sequence",
      "actions": [
        { "handler": "setState", "params": { "target": "global", "selectedModule": "{{row}}" } },
        { "handler": "openModal", "target": "install_modal" }
      ]
    }
  ]
}
```

위 JSON은 `bindComponentActions`에 의해 다음과 같이 변환됩니다:

```typescript
{
  variant: "primary",
  onClick: async (event) => {
    // sequence 핸들러 실행
    // 1. setState 실행
    // 2. openModal 실행
  }
}
```

---

## ActionDispatcher 싱글톤 패턴

템플릿 엔진에서 ActionDispatcher는 싱글톤 패턴으로 관리됩니다.

### 초기화

`template-engine.ts`에서 ActionDispatcher 생성 후 반드시 싱글톤으로 설정해야 합니다:

```typescript
import { ActionDispatcher, setActionDispatcherInstance } from './template-engine/ActionDispatcher';

// ActionDispatcher 생성
const actionDispatcher = new ActionDispatcher(config, translationEngine, translationContext);

// 싱글톤 인스턴스로 설정 (필수)
setActionDispatcherInstance(actionDispatcher);
```

### 사용

다른 모듈에서는 `getActionDispatcher()`로 싱글톤 인스턴스를 가져옵니다:

```typescript
import { getActionDispatcher } from './ActionDispatcher';

const dispatcher = getActionDispatcher();
dispatcher.dispatch(action, context);
```

### 헬퍼 함수에서의 사용

`bindComponentActions`와 같은 헬퍼 함수에서는 옵션으로 전달받은 인스턴스를 우선 사용하고, 없으면 싱글톤을 사용합니다:

```typescript
const actionDispatcher = options?.actionDispatcher ?? getActionDispatcher();
```

### 주의사항

```text
setActionDispatcherInstance 호출 전에 getActionDispatcher() 사용 시 불완전한 인스턴스 반환
✅ template-engine.ts에서 ActionDispatcher 생성 직후 setActionDispatcherInstance 호출 필수
✅ 헬퍼 함수에서는 가능하면 옵션으로 actionDispatcher를 전달받아 사용
```

---

## 향후 확장

### FormatHelpers (계획)

```typescript
// 숫자 포맷팅
G7Core.formatNumber(1234567);  // "1,234,567"
G7Core.formatCurrency(50000);  // "₩50,000"

// 날짜 포맷팅
G7Core.formatDate(new Date(), 'YYYY-MM-DD');  // "2025-11-30"
G7Core.formatRelativeTime(date);  // "3일 전"
```

### ValidationHelpers (계획)

```typescript
// 필드 검증
G7Core.validateEmail('test@example.com');  // true
G7Core.validateRequired(value);  // boolean
```

### StyleHelpers (계획)

```typescript
// 조건부 클래스 조합
G7Core.classNames('base', { 'active': isActive, 'disabled': isDisabled });
```

---

## 주의사항

```text
✅ G7Core 존재 여부 항상 확인 (G7Core?.renderItemChildren)
✅ 컴포넌트 맵은 템플릿에서 ComponentRegistry를 통해 구성
✅ keyPrefix를 적절히 사용하여 React key 충돌 방지
G7Core는 템플릿 엔진 초기화 후에만 사용 가능
SSR 환경에서는 window 객체 접근 주의
```

---

## 관련 문서

- [data-binding.md](data-binding.md) - 데이터 바인딩 및 표현식
- [components.md](components.md) - 컴포넌트 개발 규칙
- [template-development.md](template-development.md) - 템플릿 개발 가이드
