# 컴포넌트 타입별 개발 규칙

> **메인 문서**: [components.md](components.md)
> **관련 문서**: [layout-json-components.md](layout-json-components.md) | [sirsoft-admin_basic 컴포넌트](templates/sirsoft-admin_basic/components.md)

---

## 목차

1. [핵심 원칙](#핵심-원칙)
2. [기본 컴포넌트 (Basic Component)](#1-기본-컴포넌트-basic-component)
3. [집합 컴포넌트 (Composite Component)](#2-집합-컴포넌트-composite-component)
4. [집합 컴포넌트 재사용 가이드라인](#3-집합-컴포넌트-재사용-가이드라인)
5. [레이아웃 컴포넌트 (Layout Component)](#4-레이아웃-컴포넌트-layout-component)

---

## 핵심 원칙

```
필수: 기본 컴포넌트 사용 (HTML 태그 직접 사용 금지)
필수: 기본 컴포넌트만 사용 (Div, Button, H2 등)
필수: 집합 컴포넌트 재사용 우선
```

---

## 1. 기본 컴포넌트 (Basic Component)

**정의**: HTML 기본 태그에 대응하는 최소 래핑 컴포넌트

**타입**: `basic`

**예시**: Button, Input, Div, Icon, H1, H2, P, Span, Form, Table, Ul, Li, Nav 등

**특징**:
- DOM 요소에 직접 매핑
- 최소한의 래핑만 수행
- props를 HTML 속성으로 전달
- 스타일링은 className으로 처리

### 패턴

```tsx
// templates/[vendor-template]/src/components/basic/Button.tsx

import React from 'react';

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'danger' | 'success';
  size?: 'sm' | 'md' | 'lg';
}

/**
 * 기본 버튼 컴포넌트
 */
export const Button: React.FC<ButtonProps> = ({
  children,
  variant = 'primary',
  size = 'md',
  className = '',
  ...props
}) => {
  return (
    <button
      className={className}
      {...props}
    >
      {children}
    </button>
  );
};
```

### Input 컴포넌트 IME 처리 (중요)

Input 컴포넌트는 한글 등 IME(Input Method Editor) 조합 입력을 올바르게 처리합니다.

**핵심 동작**:
- IME 조합 중(`compositionStart` ~ `compositionEnd`)에는 외부 `onChange`를 호출하지 않음
- 조합 완료 후 `compositionEnd` 이벤트에서 최종 값으로 `onChange` 호출
- IME 조합 중에는 `onKeyPress` 이벤트도 발생하지 않음 (Enter 키 등)
- 내부 로컬 상태로 화면 표시를 유지하여 조합 중에도 입력이 보임

**사용 시 주의사항**:
```
✅ keypress 이벤트: IME 조합 완료 후에만 발생
✅ change 이벤트: IME 조합 완료 후에만 외부에 전달
한글 입력 후 Enter 검색: keypress + key: "Enter" 조합 사용 권장
```

**검색 입력 필드 예시** (keypress 사용):
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
      "type": "keypress",
      "key": "Enter",
      "handler": "navigate",
      "params": {
        "path": "/search?q={{_global.searchQuery}}"
      }
    }
  ]
}
```

---

## 2. 집합 컴포넌트 (Composite Component) ⭐ 핵심

**정의**: 기본 컴포넌트를 조합하여 특정 UI 패턴을 캡슐화한 복합 컴포넌트

**타입**: `composite`

**예시**:
- **UI 컴포넌트**: Card, DataGrid, Modal, Dropdown, Pagination, SearchBar, StatusBadge, Toast, TagInput
- **관리자 컴포넌트**: AdminSidebar, AdminHeader, AdminFooter, PageHeader, TemplateCard

### 핵심 원칙 (필수 준수)

```
필수: 기본 컴포넌트 사용 (HTML 태그 직접 사용 금지)
필수: 기본 컴포넌트만 사용 (Div, Button, H2 등)
필수: 집합 컴포넌트 재사용 우선
필수: props 기본값은 undefined로 설정 (배열/객체 리터럴 금지)
필수: 모듈 레벨 상수 사용 (const EMPTY: T[] = [])
```

1. **UI 패턴 캡슐화**: 특정 UI 패턴(카드, 테이블, 모달 등)을 완성된 형태로 제공
2. **간단한 Props 전달**: 레이아웃 JSON에서 최소한의 props만 전달
3. **기본 컴포넌트 조합**: 내부적으로 Div, Button, Table 등 기본 컴포넌트만 사용
4. **HTML 태그 직접 사용 금지**: `<div>`, `<button>` 등 HTML 태그 직접 사용 불가
5. **집합 컴포넌트 재사용 우선**: 새로운 집합 컴포넌트 개발 시 기존 집합 컴포넌트를 재사용할 수 있는지 우선 검토
6. **Props 기본값 참조 안정성 필수**: 배열/객체 기본값은 모듈 레벨 상수 사용 (무한 렌더 루프 방지)

### 올바른 패턴

```tsx
// ✅ 올바른 예: 기본 컴포넌트 재사용
import { Div } from '../basic/Div';
import { H2 } from '../basic/H2';
import { P } from '../basic/P';
import { Img } from '../basic/Img';

export interface CardProps {
  title?: string;
  content?: string;
  imageUrl?: string;
  onClick?: () => void;
}

/**
 * Card 집합 컴포넌트
 */
export const Card: React.FC<CardProps> = ({
  title,
  content,
  imageUrl,
  onClick,
}) => {
  return (
    <Div className="card" onClick={onClick}>
      {imageUrl && <Img src={imageUrl} />}
      <Div className="card-body">
        {title && <H2>{title}</H2>}
        {content && <P>{content}</P>}
      </Div>
    </Div>
  );
};
```

### 잘못된 패턴

```tsx
// ❌ 잘못된 예: HTML 태그 직접 사용 (금지)
export const Card: React.FC<CardProps> = ({
  title,
  content,
  imageUrl,
  onClick,
}) => {
  return (
    <div className="card" onClick={onClick}>
      {imageUrl && <img src={imageUrl} />}
      <div className="card-body">
        {title && <h2>{title}</h2>}
        {content && <p>{content}</p>}
      </div>
    </div>
  );
};
```

### Props 기본값 참조 안정성

배열/객체 기본값을 destructuring에서 직접 사용하면 매 렌더마다 새 참조가 생성되어 `useEffect` 무한 루프를 유발합니다. 모달 내에서 사용 시 `startTransition`으로 래핑된 모달 닫기 렌더를 영구 차단하여 모달 전체가 작동 불능이 됩니다.

```tsx
// ❌ 잘못된 예: 매 렌더마다 새 [] 참조 생성 → useEffect 무한 루프
export const FileUploader = forwardRef((
  { initialFiles = [], roleIds = [], ... },
  ref
) => { ... });

// ✅ 올바른 예: 모듈 레벨 상수로 안정적인 참조
const EMPTY_FILES: Attachment[] = [];
const EMPTY_ROLE_IDS: number[] = [];

export const FileUploader = forwardRef((
  { initialFiles = EMPTY_FILES, roleIds = EMPTY_ROLE_IDS, ... },
  ref
) => { ... });
```

> **트러블슈팅**: [사례 상세](../../.claude/docs/frontend/troubleshooting-state-advanced.md#사례-1-composite-컴포넌트의-불안정한-기본값으로-모달-닫기버튼-클릭-불가)

### 객체 값 이벤트의 `_changedKeys` 규칙 (engine-v1.28.0+)

객체 형태의 `value`를 emit하는 composite 컴포넌트는
`onChange` 이벤트에 `_changedKeys` 메타데이터를 포함해야 합니다.

이는 엔진의 debounce와 상호작용 시 stale closure로 인한 키 유실을 방지합니다.

**규칙**:

- `onChange` 이벤트에서 `target.value`가 객체(`Record<string, any>`)인 경우,
  실제 사용자가 변경한 키 목록을 `_changedKeys: string[]`로 포함
- `_changedKeys` 미포함 시 엔진은 기존 동작 유지 (마지막 값 사용)

**예시**:

```typescript
// Composite 컴포넌트 내부
onChange?.({
    target: { name, value: { ko: "빨강", en: "blue" } },
    _changedKeys: ["ko"],  // 실제 변경된 키만 명시
});
```

**대상 컴포넌트**: MultilingualInput, MultilingualTagInput 등
객체 value를 emit하면서 debounce와 함께 사용될 수 있는 모든 composite 컴포넌트

---

## 3. 집합 컴포넌트 재사용 가이드라인 ⭐ 매우 중요

새로운 집합 컴포넌트 개발 전 다음 순서로 검토:

### 1. 기존 집합 컴포넌트 재사용 (최우선)

- 동일하거나 유사한 UI 패턴을 제공하는 기존 컴포넌트가 있는가?
- 기존 컴포넌트를 props로 커스터마이징하여 요구사항을 충족할 수 있는가?
- 예시: 퀵 액션 버튼 → ActionMenu 재사용, 브레드크럼 → Breadcrumb 재사용

### 2. 기존 컴포넌트 조합 (차선책)

- 여러 기존 컴포넌트를 조합하여 새로운 패턴을 만들 수 있는가?
- 예시: PageHeader = Breadcrumb + 제목 + 탭 + 액션 버튼 조합

### 3. 새 컴포넌트 개발 (최후의 수단)

- 위 두 방법으로 해결 불가능한 경우에만 신규 개발
- 개발 시 향후 재사용 가능성을 고려한 범용적 설계 필수

### 재사용 시 얻는 이점

- 코드 중복 제거 및 유지보수성 향상
- 일관된 UX 제공
- 기존 컴포넌트의 개선사항 자동 반영
- 인터페이스 통일로 학습 비용 감소

### 실제 재사용 사례

**ActionMenu 재사용 예시**:

```tsx
// ❌ 잘못된 예: 커스텀 액션 메뉴 구현
export const TemplateCard = () => (
  <Div>
    {quickActions.map((action) => (
      <Button onClick={action.onClick}>{action.label}</Button>
    ))}
  </Div>
);

// ✅ 올바른 예: ActionMenu 컴포넌트 재사용
import { ActionMenu } from './ActionMenu';
import { IconName } from '../basic/Icon';

export const TemplateCard = () => (
  <Div>
    <ActionMenu
      items={actions}
      triggerIconName={IconName.EllipsisVertical}
      position="left"
    />
  </Div>
);
```

**Breadcrumb 재사용 예시**:

```tsx
// ❌ 잘못된 예: 커스텀 브레드크럼 구현
export const PageHeader = ({ breadcrumbs }) => (
  <Div>
    {breadcrumbs.map((item, index) => (
      <React.Fragment key={index}>
        {item.url ? <A href={item.url}>{item.label}</A> : <Span>{item.label}</Span>}
        {index < breadcrumbs.length - 1 && <Icon name={IconName.ChevronRight} />}
      </React.Fragment>
    ))}
  </Div>
);

// ✅ 올바른 예: Breadcrumb 컴포넌트 재사용
import { Breadcrumb, BreadcrumbItem } from './Breadcrumb';

export const PageHeader = ({ breadcrumbItems }) => (
  <Div>
    <Breadcrumb items={breadcrumbItems} />
  </Div>
);
```

---

## 4. 레이아웃 컴포넌트 (Layout Component)

**정의**: 자식 요소를 배치하는 컨테이너 컴포넌트

**타입**: `layout`

**예시**: Container (flex, grid, stack), Grid, Flex, SectionLayout

**특징**:
- 자식 요소의 배치 및 정렬을 담당
- 레이아웃 관련 props 제공 (layout, direction, gap, justify, align 등)
- UI 로직 없이 순수하게 구조화만 담당

**주의**: `Section`은 basic 컴포넌트이며, `SectionLayout`은 layout 컴포넌트입니다.
- `Section` (basic): HTML `<section>` 태그를 래핑하는 최소 컴포넌트
- `SectionLayout` (layout): Section 컴포넌트를 활용하여 제목, 패딩, 배경색 등을 제공하는 레이아웃 컴포넌트

### 핵심 원칙 (필수 준수)

1. **기본 컴포넌트 재사용**: 내부적으로 Div, Section 등 기본 컴포넌트만 사용
2. **HTML 태그 직접 사용 금지**: `<div>`, `<section>` 등 HTML 태그 직접 사용 불가
3. **집합 컴포넌트와 동일한 패턴**: 집합 컴포넌트와 동일한 개발 패턴 적용

### 개발 가이드라인

```tsx
// ❌ 잘못된 예: HTML 태그 직접 사용
export const Container: React.FC<ContainerProps> = ({ children }) => (
  <div className="container">
    {children}
  </div>
);

// ✅ 올바른 예: 기본 컴포넌트 재사용
import { Div } from '../basic/Div';

export const Container: React.FC<ContainerProps> = ({ children }) => (
  <Div className="container">
    {children}
  </Div>
);
```

### 재사용 시 얻는 이점

- 집합 컴포넌트와 동일한 아키텍처 패턴 유지
- 일관된 코드베이스 구조
- 기본 컴포넌트 수정 시 자동 반영

---

## 관련 문서

- [컴포넌트 개발 규칙 인덱스](components.md)
- [컴포넌트 패턴](components-patterns.md)
- [컴포넌트 고급 기능](components-advanced.md)
- [sirsoft-admin_basic 컴포넌트](templates/sirsoft-admin_basic/components.md)
- [sirsoft-basic 컴포넌트](templates/sirsoft-basic/components.md)
