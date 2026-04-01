# 컴포넌트 패턴 및 다국어

> **메인 문서**: [components.md](components.md)
> **관련 문서**: [g7core-api.md](g7core-api.md) | [data-binding-i18n.md](data-binding-i18n.md)

---

## 목차

1. [순환 의존성 해결 패턴](#순환-의존성-해결-패턴)
2. [다국어 번역 (G7Core.t)](#다국어-번역-g7coret)
3. [skipBindingKeys (바인딩 지연 처리)](#skipbindingkeys-바인딩-지연-처리)

---

## 순환 의존성 해결 패턴

```text
중요: 템플릿 엔진 내부 모듈 간 순환 의존성 발생 시
✅ 해결: 의존성 주입(Dependency Injection) 패턴 사용
✅ 대안: import type으로 타입만 가져오기
```

### 문제 상황

템플릿 엔진 내부에서 모듈 간 순환 참조가 발생하면 빌드 오류가 발생합니다:

```typescript
// ❌ 순환 의존성 발생
// ErrorPageHandler.ts
import { renderTemplate } from './index';  // index.ts가 ErrorPageHandler를 import

export class ErrorPageHandler {
    async renderError() {
        await renderTemplate({ ... });  // 순환 참조!
    }
}
```

### 해결 패턴 1: 의존성 주입 (권장)

생성자에서 함수나 객체를 주입받아 순환 참조를 제거합니다:

```typescript
// ✅ 의존성 주입으로 해결
// ErrorPageHandler.ts
export interface RenderOptions {
    containerId: string;
    layoutJson: unknown;
    dataContext?: Record<string, unknown>;
}

export type RenderFunction = (options: RenderOptions) => Promise<void>;

export interface ErrorPageHandlerOptions {
    templateId: string;
    layoutLoader: LayoutLoader;
    renderFunction: RenderFunction;  // 함수를 주입받음
    dataSourceManager: DataSourceManager;  // 객체를 주입받음
}

export class ErrorPageHandler {
    private renderFunction: RenderFunction;
    private dataSourceManager: DataSourceManager;

    constructor(options: ErrorPageHandlerOptions) {
        this.renderFunction = options.renderFunction;
        this.dataSourceManager = options.dataSourceManager;
    }

    async renderError() {
        // 주입받은 함수 사용 (순환 참조 없음)
        await this.renderFunction({ ... });
    }
}
```

```typescript
// TemplateApp.ts - 의존성 주입
import { renderTemplate } from './template-engine';
import { DataSourceManager } from './template-engine/DataSourceManager';
import { ErrorPageHandler } from './template-engine/ErrorPageHandler';

this.errorPageHandler = new ErrorPageHandler({
    templateId: this.config.templateId,
    layoutLoader: this.layoutLoader,
    renderFunction: renderTemplate,  // 함수 주입
    dataSourceManager: new DataSourceManager(),  // 객체 주입
});
```

### 해결 패턴 2: import type 사용

타입만 필요한 경우 `import type`을 사용하여 런타임 의존성을 제거합니다:

```typescript
// ✅ 타입만 import (런타임 의존성 없음)
import type { DataSourceManager, DataSource } from './DataSourceManager';
import type { LayoutLoader } from './LayoutLoader';

export class ErrorPageHandler {
    private dataSourceManager: DataSourceManager;  // 타입으로만 사용

    constructor(options: { dataSourceManager: DataSourceManager }) {
        this.dataSourceManager = options.dataSourceManager;
    }
}
```

### 적용 대상

| 상황 | 해결 방법 |
|------|----------|
| 함수를 직접 호출해야 할 때 | 의존성 주입 (생성자 파라미터) |
| 타입 정의만 필요할 때 | `import type` 사용 |
| 런타임에 인스턴스가 필요할 때 | 생성자에서 객체 주입받기 |

### 이점

- 빌드 오류 해결
- 테스트 용이성 향상 (mock 주입 가능)
- 모듈 간 결합도 감소
- 단일 책임 원칙 준수

---

## 다국어 번역 (G7Core.t)

컴포넌트에서 다국어 텍스트를 사용할 때는 `G7Core.t()` 함수를 사용합니다.

### 다국어 핵심 원칙

```text
필수: G7Core.t() 사용 (텍스트 하드코딩 금지)
필수: G7Core.t() 함수를 통한 다국어 키 사용
✅ 필수: props로 텍스트를 받을 경우 nullish coalescing(??)으로 기본값 설정
```

### t 함수 선언 패턴

컴포넌트 파일 상단에 다음과 같이 `t` 함수를 선언합니다:

```tsx
// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;
```

**특징**:

- 모듈 레벨에서 한 번만 선언 (컴포넌트 내부 X)
- 템플릿 엔진 미초기화 시 키 자체 반환 (폴백)
- 파라미터 치환 지원 (`{{변수}}` 형식)

### 사용 예시

#### 기본 사용

```tsx
// 간단한 번역
<Button>{t('common.confirm')}</Button>
<Span>{t('common.cancel')}</Span>

// aria-label 등 접근성 속성
<Button aria-label={t('common.close_dialog')}>×</Button>
```

#### 파라미터 치환

```tsx
// 파라미터 포함 번역
// ko.json: "pagination_info": "{{from}}-{{to}} / 총 {{total}}건"
<Span>{t('admin.users.pagination_info', { from: 1, to: 10, total: 100 })}</Span>

// 결과: "1-10 / 총 100건"
```

#### props 기본값 설정

```tsx
// props로 전달된 값이 없으면 다국어 키 사용
const resolvedPrevText = prevText ?? t('common.prev');
const resolvedNextText = nextText ?? t('common.next');
const resolvedEmptyMessage = emptyMessage ?? t('common.no_data');
```

### 다국어 올바른 패턴

```tsx
// ✅ 올바른 예: G7Core.t() 사용
import React from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

export const MyComponent: React.FC<Props> = ({ confirmText, cancelText }) => {
  // props 기본값 설정
  const resolvedConfirmText = confirmText ?? t('common.confirm');
  const resolvedCancelText = cancelText ?? t('common.cancel');

  return (
    <Div>
      <Button>{resolvedConfirmText}</Button>
      <Button>{resolvedCancelText}</Button>
    </Div>
  );
};
```

### 다국어 잘못된 패턴

```tsx
// ❌ 잘못된 예 1: 하드코딩된 텍스트
export const MyComponent: React.FC = () => (
  <Div>
    <Button>확인</Button>  {/* 하드코딩 금지! */}
    <Button>취소</Button>
  </Div>
);

// ❌ 잘못된 예 2: useTranslation 훅 사용 (보일러플레이트 발생)
const useTranslation = (window as any).G7Core?.useTranslation;

export const MyComponent: React.FC = () => {
  const { t } = useTranslation?.() || { t: (key: string) => key };  // 불필요한 패턴
  // ...
};
```

### 다국어 파일 위치

```text
templates/[vendor-template]/lang/
├── ko.json    # 한국어
└── en.json    # 영어
```

### 번역 키 네이밍 규칙

| 범주 | 키 패턴 | 예시 |
|------|--------|------|
| 공통 | `common.{key}` | `common.confirm`, `common.cancel` |
| 관리자 | `admin.{module}.{key}` | `admin.users.page_title` |
| 인증 | `auth.{action}.{key}` | `auth.login.submit` |
| 에러 | `errors.{code}.{key}` | `errors.404.title` |

---

## skipBindingKeys (바인딩 지연 처리)

컴포넌트가 내부적으로 row 컨텍스트나 iteration 컨텍스트를 사용하여 자체적으로 바인딩을 처리해야 하는 경우, `skipBindingKeys`를 설정할 수 있습니다.

### 문제 상황

DynamicRenderer가 props를 처리할 때 `{{...}}` 바인딩 표현식을 즉시 평가합니다. 그러나 DataGrid의 `cellChildren`이나 `expandChildren`처럼 row 컨텍스트가 필요한 경우, 조기 평가하면 `undefined`가 반환됩니다.

```json
{
  "type": "composite",
  "name": "DataGrid",
  "props": {
    "expandContext": {
      "optionCurrencyColumns": "{{_computed.optionCurrencyColumns || []}}"
    }
  }
}
```

위 예시에서 `expandContext`가 조기 평가되면 row 컨텍스트 없이 평가되어 의도한 대로 동작하지 않습니다.

### 해결 방법

`components.json`에서 컴포넌트별로 `skipBindingKeys`를 정의합니다:

```json
{
  "name": "DataGrid",
  "type": "composite",
  "description": "Data grid with sorting, filtering, and pagination",
  "path": "src/components/composite/DataGrid.tsx",
  "skipBindingKeys": ["cellChildren", "expandChildren", "expandContext", "render"],
  "props": {
    // ...
  }
}
```

### skipBindingKeys 동작 방식

1. **기본값**: `['cellChildren', 'expandChildren', 'expandContext', 'render']`
2. **병합**: 기본값 + 컴포넌트별 설정 (중복 제거)
3. **하위 호환성**: `skipBindingKeys` 없는 컴포넌트는 기본값만 사용

### 적용 예시

| 컴포넌트 | skipBindingKeys | 이유 |
|----------|-----------------|------|
| DataGrid | `cellChildren`, `expandChildren`, `expandContext`, `render` | row 컨텍스트로 평가 필요 |
| CardGrid | `cardChildren` | item 컨텍스트로 평가 필요 |

### 관련 파일

- `resources/js/core/template-engine/ComponentRegistry.ts`: `ComponentMetadata` 인터페이스에 `skipBindingKeys` 정의
- `resources/js/core/template-engine/DynamicRenderer.tsx`: 메타데이터 기반 skipBindingKeys 조회 및 적용
- `resources/js/core/template-engine/DataBindingEngine.ts`: `resolveObject` 메서드의 옵션 파라미터
- `templates/sirsoft-admin_basic/components.json`: 컴포넌트별 `skipBindingKeys` 설정

---

## 관련 문서

- [컴포넌트 개발 규칙 인덱스](components.md)
- [컴포넌트 타입별 규칙](components-types.md)
- [컴포넌트 고급 기능](components-advanced.md)
- [G7Core API](g7core-api.md)
- [다국어 바인딩](data-binding-i18n.md)
