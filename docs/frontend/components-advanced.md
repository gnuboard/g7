# 컴포넌트 고급 기능

> **메인 문서**: [components.md](components.md)
> **관련 문서**: [g7core-api.md](g7core-api.md) 

---

## 목차

1. [컴포넌트 간 이벤트 통신 (G7Core.componentEvent)](#컴포넌트-간-이벤트-통신-g7corecomponentevent)
2. [이벤트 생성 헬퍼](#이벤트-생성-헬퍼)
3. [아이콘 사용 규칙 (Font Awesome)](#아이콘-사용-규칙-font-awesome)
4. [Form 자동 바인딩 메타데이터 (bindingType)](#form-자동-바인딩-메타데이터-bindingtype)
5. [컴포넌트 개발 체크리스트](#컴포넌트-개발-체크리스트)

---

## 컴포넌트 간 이벤트 통신 (G7Core.componentEvent)

컴포넌트 간 직접 통신이 필요한 경우 `G7Core.componentEvent`를 사용합니다.

> 전체 API 레퍼런스: [g7core-api.md](g7core-api.md#컴포넌트-이벤트-g7corecomponentevent)

### 핵심 원칙

```text
주의: 가능하면 props/콜백 또는 전역 상태를 통한 통신 우선
✅ 사용 시점: 부모-자식 관계가 아닌 컴포넌트 간 통신
✅ 사용 시점: 특정 컴포넌트의 메서드를 외부에서 트리거해야 할 때
```

### API 목록

| 메서드 | 설명 | 반환값 |
|--------|------|--------|
| `on(eventName, callback)` | 이벤트 구독 | 구독 해제 함수 |
| `emit(eventName, data?)` | 이벤트 발생 | `Promise<any[]>` |
| `off(eventName)` | 특정 이벤트 리스너 제거 | `void` |
| `clear()` | 모든 리스너 제거 | `void` |

### 사용 예시: FileUploader 트리거

```tsx
// FileUploader 컴포넌트 내부
useEffect(() => {
  const unsubscribe = G7Core.componentEvent.on(
    `triggerUpload:${uploaderId}`,
    async () => {
      inputRef.current?.click();
    }
  );
  return unsubscribe;  // cleanup
}, [uploaderId]);

// 다른 컴포넌트에서 업로드 트리거
const handleTriggerUpload = () => {
  G7Core.componentEvent.emit('triggerUpload:logo_uploader');
};
```

### 이벤트 네이밍 규칙

| 패턴 | 설명 | 예시 |
|------|------|------|
| `{action}` | 단순 액션 | `fileSelected`, `dataLoaded` |
| `{action}:{target}` | 특정 대상 지정 | `triggerUpload:logo_uploader` |
| `{component}.{action}` | 컴포넌트별 이벤트 | `sidebar.toggle` |

### 주의사항

```text
cleanup 필수: useEffect에서 반환된 unsubscribe 함수 호출
이벤트명 충돌 방지: 고유한 이벤트명 사용 (컴포넌트 ID 포함 권장)
✅ 비동기 지원: emit은 모든 리스너의 결과를 Promise로 반환
```

---

## 이벤트 생성 헬퍼

ActionDispatcher와 호환되는 이벤트 객체를 생성하는 헬퍼 함수들입니다.

> 전체 API 레퍼런스: [g7core-api.md](g7core-api.md#이벤트-생성-헬퍼)

### 이벤트 헬퍼 함수 목록

| 함수 | 설명 |
|------|------|
| `G7Core.createChangeEvent(value, name?)` | change 이벤트 생성 |
| `G7Core.createClickEvent(data?)` | click 이벤트 생성 |
| `G7Core.createSubmitEvent(formData?)` | submit 이벤트 생성 |
| `G7Core.createKeyboardEvent(key, modifiers?)` | keyboard 이벤트 생성 |

### 이벤트 헬퍼 사용 예시

```typescript
// Change 이벤트 생성
const changeEvent = G7Core.createChangeEvent('newValue', 'fieldName');
// { target: { value: 'newValue', name: 'fieldName' } }

// Click 이벤트 생성
const clickEvent = G7Core.createClickEvent({ itemId: 123 });

// Submit 이벤트 생성
const submitEvent = G7Core.createSubmitEvent({
  name: 'John',
  email: 'john@example.com'
});

// Keyboard 이벤트 생성
const keyEvent = G7Core.createKeyboardEvent('Enter', { ctrlKey: true });
```

### 사용 사례

컴포넌트에서 ActionDispatcher 핸들러를 직접 호출할 때 사용합니다:

```tsx
// 프로그래밍 방식으로 change 이벤트 발생
const handleCustomChange = (newValue: string) => {
  const event = G7Core.createChangeEvent(newValue, 'customField');
  onChange?.(event);  // props로 전달된 onChange 호출
};
```

---

## 아이콘 사용 규칙 (Font Awesome)

컴포넌트에서 아이콘을 사용할 때는 Font Awesome에서 지원하는 아이콘만 사용해야 합니다.


### 핵심 원칙

```text
주의: Font Awesome에서 지원하지 않는 아이콘 사용 금지
주의: Font Awesome Pro 전용 아이콘 사용 금지 (Light, Thin, Duotone 등)
주의: 다른 아이콘 라이브러리 직접 import 금지 (Material Icons, Heroicons 등)
필수: Icon 기본 컴포넌트를 통한 아이콘 사용
필수: Font Awesome 기준 아이콘만 사용
```

### Free vs Pro 아이콘 구분

```text
필수: 그누보드7 프로젝트는 Font Awesome만 사용합니다.
Pro 버전 아이콘은 라이선스가 필요하므로 사용할 수 없습니다.
```

**Free 버전에서 사용 가능한 스타일**:

| 스타일 | 접두사 | 아이콘 수 | 설명 |
|--------|--------|----------|------|
| Solid | `fa-solid` 또는 `fas` | 1,390개 | 채워진 스타일 |
| Regular | `fa-regular` 또는 `far` | 163개 | 윤곽선 스타일 (일부만) |
| Brands | `fa-brands` 또는 `fab` | 472개 | 브랜드 로고 |

**Pro 버전 전용 (사용 금지)**:

- `fa-light` (fal) - Light 스타일
- `fa-thin` (fat) - Thin 스타일
- `fa-duotone` (fad) - Duotone 스타일
- `fa-sharp` (fass, fasr, fasl) - Sharp 스타일

### 아이콘 확인 방법

아이콘이 Free 버전에 포함되어 있는지 확인하는 방법:

2. **Font Awesome 공식 사이트**: <https://fontawesome.com/search?o=r&m=free>
   - "Free" 필터가 적용된 검색 결과만 확인
3. **없는 아이콘 예시**: `layout`, `plate-utensils` 등은 Free에 없음

### 사용 가능한 아이콘 소스

```html
<!-- CDN 버전 -->
https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css
```

### 아이콘 사용 방법

#### Icon 컴포넌트 사용 (권장)

```tsx
import { Icon, IconName } from '../basic/Icon';

// ✅ 올바른 사용
<Icon name={IconName.Check} />
<Icon name={IconName.ChevronRight} />
<Icon name={IconName.Search} />

// 커스텀 아이콘 클래스 직접 지정 (Font Awesome)
<Icon name="fa-solid fa-user" />
<Icon name="fa-regular fa-envelope" />
```

#### IconName enum 활용

```tsx
// templates/sirsoft-admin_basic/src/components/basic/Icon.tsx
export enum IconName {
  Check = 'fa-solid fa-check',
  ChevronRight = 'fa-solid fa-chevron-right',
  Search = 'fa-solid fa-search',
  // ... Font Awesome에 존재하는 아이콘만 정의
}
```

### 아이콘 버전 확인 방법

새로운 아이콘을 추가하기 전, 해당 아이콘이 Font Awesome에서 지원되는지 확인합니다:

1. **공식 문서 확인**: https://fontawesome.com/v6/search (버전 6.4 선택)
2. **CDN 파일 직접 확인**: 아이콘 클래스가 CSS에 정의되어 있는지 확인
3. **브라우저 개발자 도구**: 실제 렌더링 여부 확인

### 잘못된 패턴

```tsx
// ❌ 잘못된 예 1: Font Awesome 6.5+ 전용 아이콘 사용
<Icon name="fa-solid fa-plate-utensils" />  // 6.5.0에서 추가된 아이콘

// ❌ 잘못된 예 2: 다른 아이콘 라이브러리 직접 import
import { HiOutlineUser } from 'react-icons/hi';
<HiOutlineUser />

// ❌ 잘못된 예 3: SVG 직접 사용 (Icon 컴포넌트 우회)
<svg className="w-5 h-5">...</svg>
```

### 아이콘 스타일 종류

Font Awesome에서 지원하는 스타일:

| 스타일 | 접두사 | 예시 |
|--------|--------|------|
| Solid | `fa-solid` 또는 `fas` | `fa-solid fa-user` |
| Regular | `fa-regular` 또는 `far` | `fa-regular fa-envelope` |
| Light | `fa-light` 또는 `fal` | `fa-light fa-bell` (Pro) |
| Brands | `fa-brands` 또는 `fab` | `fa-brands fa-github` |

> **참고**: Light, Thin, Duotone 등은 Pro 라이선스가 필요하며, 기본 CDN에서는 Solid, Regular, Brands만 사용 가능합니다.

### 새 아이콘 추가 시 체크리스트

```text
□ Font Awesome에서 지원되는 아이콘인가?
□ IconName enum에 정의되어 있는가? (없으면 추가 필요)
□ 적절한 스타일(Solid/Regular/Brands)을 사용했는가?
□ Free 버전에서 사용 가능한 아이콘인가?
```

---

## Form 자동 바인딩 메타데이터 (bindingType)

Form 자동 바인딩에서 boolean 값이 `checked` prop으로 바인딩될지 `value` prop으로 바인딩될지를 제어하는 메타데이터입니다.

### 배경

Form 내부의 입력 컴포넌트는 바인딩된 상태 값이 `boolean`일 때 자동으로 바인딩 방식을 결정합니다:
- `checked` 바인딩: Toggle, Checkbox 등 on/off 컴포넌트에 적합
- `value` 바인딩: RadioGroup, Select 등 값 선택 컴포넌트에 적합

`bindingType` 메타데이터가 없으면, boolean 값은 항상 `value` prop으로 바인딩됩니다 (기본 동작).

### bindingType 값

| 값 | 동작 | 대상 컴포넌트 |
|----|------|--------------|
| `"checked"` | boolean 값을 항상 `checked` prop으로 바인딩 | Toggle, Checkbox, ChipCheckbox |
| `"checkable"` | `type`이 `checkbox` 또는 `radio`일 때만 `checked`, 그 외 `value` | Input |
| 미지정 (기본) | boolean 값도 항상 `value` prop으로 바인딩 | RadioGroup, Select 등 |

### components.json 등록 방법

```json
{
  "name": "Toggle",
  "type": "basic",
  "description": "토글 스위치 컴포넌트",
  "bindingType": "checked",
  "path": "src/components/basic/Toggle.tsx",
  "props": { ... }
}
```

```json
{
  "name": "Input",
  "type": "basic",
  "description": "입력 필드 컴포넌트",
  "bindingType": "checkable",
  "path": "src/components/basic/Input.tsx",
  "props": { ... }
}
```

### 등록이 필요한 경우

```text
필수: 아래 조건에 해당하면 bindingType 등록

□ checked/unchecked 상태로 동작하는 컴포넌트 → "checked"
□ type prop에 따라 checked/value 동작이 달라지는 컴포넌트 → "checkable"
□ boolean 값을 선택지(value)로 사용하는 컴포넌트 → 미지정 (기본)
```

### 주의사항

```text
bindingType 미등록 시: boolean 값이 checked로 바인딩되지 않음
  → Toggle/Checkbox가 Form 내에서 정상 동작하지 않을 수 있음

잘못된 bindingType 등록 시: boolean 값이 의도하지 않은 prop으로 바인딩
  → RadioGroup에 "checked" 등록 시 boolean 선택값이 표시되지 않음

새 템플릿에 Toggle/Checkbox 유사 컴포넌트 추가 시 bindingType 등록 필수
기존 템플릿의 등록 현황: sirsoft-admin_basic, sirsoft-basic의 components.json 참조
```

---

## 컴포넌트 개발 체크리스트

새로운 컴포넌트를 개발할 때 다음 항목을 반드시 확인합니다.

### 필수 등록 항목

```text
필수: 새 컴포넌트 생성 시 아래 4개 파일에 등록
□ 컴포넌트 파일 생성: templates/[vendor-template]/src/components/{type}/{Name}.tsx
□ index.ts export 추가: templates/[vendor-template]/src/components/{type}/index.ts
□ components.json 등록: templates/[vendor-template]/components.json
□ 테스트 파일 생성: templates/[vendor-template]/src/components/{type}/__tests__/{Name}.test.tsx
```

**components.json 등록 형식**:

```json
{
  "name": "ComponentName",
  "type": "composite",
  "description": "컴포넌트 설명",
  "path": "src/components/composite/ComponentName.tsx",
  "bindingType": "checked | checkable | 미지정",
  "props": {
    "propName": {
      "type": "string",
      "required": true,
      "description": "prop 설명"
    }
  }
}
```

> **참고**: `bindingType`은 Form 자동 바인딩에서 boolean 값의 바인딩 방식을 결정합니다. [상세 설명](#form-자동-바인딩-메타데이터-bindingtype)

### 필수 검증 항목

#### 구조 및 패턴

```text
□ HTML 태그 직접 사용하지 않았는가? (Div, Button 등 기본 컴포넌트만 사용)
□ 기존 집합 컴포넌트 재사용을 먼저 검토했는가?
□ Props 인터페이스를 명확히 정의했는가?
□ TypeScript 타입이 올바르게 정의되어 있는가?
```

#### 다국어 처리

```text
□ 하드코딩된 텍스트 없이 G7Core.t() 사용했는가?
□ t 함수를 모듈 레벨에서 선언했는가?
□ props 텍스트에 nullish coalescing(??) 기본값 설정했는가?
```

#### 스타일링

```text
□ 다크 모드 light/dark variant 함께 지정했는가?
□ Tailwind CSS 클래스만 사용했는가?
□ 반응형 breakpoint (sm:, md:, lg:, xl:) 적용했는가?
```

#### 아이콘

```text
□ Font Awesome에서 지원하는 아이콘만 사용했는가?
□ Icon 기본 컴포넌트를 통해 아이콘을 사용했는가?
□ IconName enum에 정의된 아이콘을 사용했는가?
```

#### 접근성

```text
□ aria-label 등 접근성 속성이 필요한 곳에 추가했는가?
□ 키보드 네비게이션이 가능한가?
□ 포커스 상태가 시각적으로 표시되는가?
```

### 테스트 필수 항목

```text
□ 컴포넌트 렌더링 테스트 작성했는가?
□ Props 전달 테스트 작성했는가?
□ 이벤트 핸들러 테스트 작성했는가? (onClick, onChange 등)
□ 엣지 케이스 테스트 작성했는가? (빈 값, undefined 등)
□ 스냅샷 테스트가 필요한 경우 작성했는가?
```

### 테스트 코드 작성 규칙

#### 테스트 파일 위치

```text
templates/[vendor-template]/src/components/
├── basic/
│   ├── Button.tsx
│   └── __tests__/
│       └── Button.test.tsx
├── composite/
│   ├── Card.tsx
│   └── __tests__/
│       └── Card.test.tsx
```

#### 필수 테스트 케이스

```tsx
// 예시: Card.test.tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { Card } from '../Card';

describe('Card', () => {
  // 1. 기본 렌더링
  it('renders correctly', () => {
    render(<Card title="Test Title" />);
    expect(screen.getByText('Test Title')).toBeInTheDocument();
  });

  // 2. Props 전달
  it('passes props correctly', () => {
    render(<Card title="Title" content="Content" />);
    expect(screen.getByText('Title')).toBeInTheDocument();
    expect(screen.getByText('Content')).toBeInTheDocument();
  });

  // 3. 이벤트 핸들러
  it('handles click events', () => {
    const handleClick = vi.fn();
    render(<Card title="Title" onClick={handleClick} />);
    fireEvent.click(screen.getByText('Title'));
    expect(handleClick).toHaveBeenCalled();
  });

  // 4. 엣지 케이스
  it('renders without optional props', () => {
    render(<Card />);
    // 빈 상태에서도 에러 없이 렌더링
  });

  // 5. 다크 모드 클래스 (필요시)
  it('includes dark mode classes', () => {
    const { container } = render(<Card title="Title" />);
    expect(container.firstChild).toHaveClass('dark:bg-gray-800');
  });
});
```

---

## 관련 문서

- [컴포넌트 개발 규칙 인덱스](components.md)
- [컴포넌트 타입별 규칙](components-types.md)
- [컴포넌트 패턴](components-patterns.md)
- [G7Core API](g7core-api.md)
