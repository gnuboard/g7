# 다크 모드 지원 (engine-v1.1.0+)

> **상위 문서**: [프론트엔드 가이드 인덱스](README.md)

그누보드7 템플릿 시스템은 Tailwind CSS dark mode variant를 활용하여 다크 모드를 전면 지원합니다.

---

## TL;DR (5초 요약)

```text
1. Tailwind dark: variant 사용 (예: bg-white dark:bg-gray-800)
2. 배경: bg-white dark:bg-gray-800/900
3. 텍스트: text-gray-900 dark:text-white
4. 테두리: border-gray-200 dark:border-gray-700
5. 항상 light/dark 쌍으로 지정 필수!
6. 확장(모듈·플러그인) CSS 는 .dark 대신 <html data-g7-color-scheme> 중립 표식만 본다 — 템플릿은 features.color_scheme 선언
```

---

## 템플릿별 지원 현황

| 템플릿 식별자 | features.dark_mode | features.color_scheme | 설명 |
|--------------|-------------------|----------------------|------|
| `sirsoft-admin_basic` | 미선언 | `ancestor-class` + `.dark` | ThemeToggle 존재, dark: variant 동작 |
| `sirsoft-basic` | `true` | `ancestor-class` + `.dark` | template.json에 선언, 완전 지원 |
| `gnuboard7-hello_user_template`·`gnuboard7-hello_admin_template` | `false`·미선언 | `none` | 학습용 — 다크 모드 없음 |

> 상세 컴포넌트 목록: [sirsoft-admin_basic](../../templates/_bundled/sirsoft-admin_basic/docs/components.md), [sirsoft-basic](../../templates/_bundled/sirsoft-basic/docs/components.md)

---

## 목차

1. [다크 모드 설정](#1-다크-모드-설정)
2. [컴포넌트 다크 모드 적용 규칙](#2-컴포넌트-다크-모드-적용-규칙)
3. [컴포넌트 개발 예시](#3-컴포넌트-개발-예시)
4. [레이아웃 JSON에서 다크 모드 사용](#4-레이아웃-json에서-다크-모드-사용)
5. [ThemeToggle 컴포넌트](#5-themetoggle-컴포넌트)
6. [기존 컴포넌트 재사용 이점](#6-기존-컴포넌트-재사용-이점)
7. [컴포넌트 개발 체크리스트](#7-컴포넌트-개발-체크리스트)
8. [테스트 요구사항](#8-테스트-요구사항)
9. [주의사항](#9-주의사항)
10. [참고 자료](#10-참고-자료)
11. [확장은 중립 표식을 쓴다](#확장은-중립-표식을-쓴다)

---

## 1. 다크 모드 설정

`main.css`에 다크 모드 variant 정의:

```css
@variant dark (.dark &);
```

**동작 원리**:
- `document.documentElement.classList.add('dark')`: 다크 모드 활성화
- `document.documentElement.classList.remove('dark')`: 라이트 모드 활성화
- Tailwind CSS가 `.dark` 클래스 감지하여 `dark:` variant 적용

---

## 2. 컴포넌트 다크 모드 적용 규칙

모든 composite 및 layout 컴포넌트는 다크 모드를 지원해야 합니다.

### 핵심 규칙

```
주의: 다크 모드 전용 색상만 하드코딩 금지 (예: border-gray-700만 사용)
필수: light와 dark variant 항상 함께 지정
✅ 필수: 기존 색상 매핑 규칙 준수
✅ 필수: Tailwind CSS 유틸리티 클래스 사용
```

### 색상 매핑 규칙

| Light 모드 | Dark 모드 | 용도 |
|-----------|----------|------|
| `bg-white` | `dark:bg-gray-800` | 카드, 모달, 드롭다운 배경 |
| `bg-gray-50` | `dark:bg-gray-700` | 헤더, 서브 배경 |
| `bg-gray-100` | `dark:bg-gray-700` | 호버 배경 |
| `border-gray-200` | `dark:border-gray-700` | 주요 테두리 |
| `border-gray-300` | `dark:border-gray-600` | 입력 필드 테두리 |
| `text-gray-900` | `dark:text-white` | 제목, 중요 텍스트 |
| `text-gray-700` | `dark:text-gray-300` | 본문 텍스트 |
| `text-gray-600` | `dark:text-gray-400` | 보조 텍스트 |
| `text-gray-400` | `dark:text-gray-500` | 비활성화, 플레이스홀더 |

### 투명도 표현

색상 강조가 필요하지만 너무 진하지 않아야 할 경우 `/20` 투명도 사용:

```tsx
// ✅ DO: 투명도로 강조 색상 표현
'bg-green-50 dark:bg-green-900/20'
'text-green-700 dark:text-green-400'
'border-green-200 dark:border-green-800'

// Success 상태
'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400'

// Warning 상태
'bg-yellow-50 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-400'

// Error 상태
'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400'
```

### 액센트 색상

브랜드 색상(blue, red 등)은 다크 모드에서 약간 밝게 조정:

```tsx
// Primary 버튼
'bg-blue-600 dark:bg-blue-500'
'hover:bg-blue-700 dark:hover:bg-blue-600'

// 링크 텍스트
'text-blue-600 dark:text-blue-400'

// 테두리
'border-blue-600 dark:border-blue-400'
```

---

## 3. 컴포넌트 개발 예시

### 올바른 예

```tsx
// ✅ DO: 다크 모드 지원
import { Div } from '../basic/Div';
import { H2 } from '../basic/H2';
import { P } from '../basic/P';

export const Card: React.FC<CardProps> = ({ title, content }) => {
  return (
    <Div className="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4">
      <H2 className="text-gray-900 dark:text-white font-bold mb-2">
        {title}
      </H2>
      <P className="text-gray-600 dark:text-gray-400">
        {content}
      </P>
    </Div>
  );
};
```

### 잘못된 예

```tsx
// ❌ DON'T: 다크 모드 미지원
export const Card: React.FC<CardProps> = ({ title, content }) => {
  return (
    <Div className="bg-white border border-gray-200 rounded-lg p-4">
      <H2 className="text-gray-900 font-bold mb-2">{title}</H2>
      <P className="text-gray-600">{content}</P>
    </Div>
  );
};

// ❌ DON'T: 다크 모드 전용 색상만 하드코딩
export const Card: React.FC<CardProps> = ({ title, content }) => {
  return (
    <Div className="bg-gray-800 border border-gray-700 rounded-lg p-4">
      <H2 className="text-white font-bold mb-2">{title}</H2>
      <P className="text-gray-400">{content}</P>
    </Div>
  );
};
```

---

## 4. 레이아웃 JSON에서 다크 모드 사용

```html
<div class="bg-white dark:bg-gray-800 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
  <h2 class="text-gray-900 dark:text-white font-bold mb-2">$t:dashboard.title</h2>
  <p class="text-gray-600 dark:text-gray-400">{{stats.description}}</p>
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "basic",
  "name": "Div",
  "props": {
    "className": "bg-white dark:bg-gray-800 p-4 rounded-lg border border-gray-200 dark:border-gray-700"
  },
  "children": [
    {
      "type": "basic",
      "name": "H2",
      "props": {
        "className": "text-gray-900 dark:text-white font-bold mb-2"
      },
      "text": "$t:dashboard.title"
    },
    {
      "type": "basic",
      "name": "P",
      "props": {
        "className": "text-gray-600 dark:text-gray-400"
      },
      "text": "{{stats.description}}"
    }
  ]
}
```

---

## 5. ThemeToggle 컴포넌트

다크 모드 전환은 `ThemeToggle` composite 컴포넌트를 사용:

```html
<theme-toggle class="p-2"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "composite",
  "name": "ThemeToggle",
  "props": {
    "className": "p-2"
  }
}
```

**ThemeToggle 동작**:
- 클릭 시 `document.documentElement.classList.toggle('dark')` 실행
- localStorage에 사용자 선호도 저장 (`theme` 키)
- 페이지 로드 시 저장된 테마 자동 적용

---

## 6. 기존 컴포넌트 재사용 이점

모든 그누보드7 기본 composite 컴포넌트는 다크 모드를 지원합니다.

### UI 컴포넌트

- ActionMenu, Alert, AlertDialog, Breadcrumb, Card, CodeEditor
- ConfirmDialog, DataGrid, Dialog, Dropdown, EmptyState
- IconButton, LoadingSpinner, Modal, Pagination, SearchBar
- StatCard, StatusBadge, TabNavigation, Toast
- ProductCard, TemplateCard, FilterGroup

### 관리자 컴포넌트

- AdminHeader, AdminFooter, AdminSidebar, NotificationCenter
- UserProfile, PageHeader, LayoutEditorHeader, LayoutFileList
- LayoutHistoryPanel

### Layout 컴포넌트

- Container, Flex, Grid, Section, ThreeColumnLayout

> **✅ 기존 컴포넌트 재사용 시 자동으로 다크 모드가 지원되므로, 신규 개발보다 재사용을 우선하세요.**

---

## 7. 컴포넌트 개발 체크리스트

새로운 컴포넌트 개발 시 다음 사항을 확인하세요:

```
□ 기본 컴포넌트만 사용 (HTML 태그 직접 사용 금지)
□ 기존 composite 컴포넌트 재사용 검토
□ 모든 배경색에 dark: variant 추가
□ 모든 텍스트 색상에 dark: variant 추가
□ 모든 테두리 색상에 dark: variant 추가
□ 색상 매핑 규칙 준수
□ 라이트 모드에서 정상 표시 확인
□ 다크 모드 전환 시 정상 표시 확인
□ 다국어 처리
□ TypeScript 타입 정의
□ 단위 테스트 작성
```

---

## 8. 테스트 요구사항

다크 모드를 지원하는 컴포넌트 개발 시:

1. **라이트 모드 테스트**: 모든 요소가 적절히 표시되는지 확인
2. **다크 모드 전환 테스트**: ThemeToggle 클릭 시 모든 요소가 변경되는지 확인
3. **색상 대비율 검증**: WCAG 2.1 AA 기준 충족 여부 검토 (선택)

---

## 9. 주의사항

### 주의 사항

- 다크 모드 전용 색상만 하드코딩 (예: `border-gray-700`만 사용, 라이트 바탕 없이 `dark:bg-gray-800` 만)
- 변형 짝 누락 (예: `hover:bg-gray-50` 만 있고 `dark:hover:*` 가 없으면 다크에서 마우스를 올릴 때 밝은 면이 번쩍인다 — `hover:bg-gray-50 dark:hover:bg-gray-700`)
- 인라인 스타일로 색상 지정 (`style={{ color: '#000' }}`)
- JavaScript로 색상 동적 변경 (Tailwind 사용)

### 필수

- 항상 light와 dark variant 함께 지정
- Tailwind CSS 유틸리티 클래스 사용
- 기존 색상 매핑 규칙 준수
- 기존 컴포넌트 재사용 우선

---

## 10. 참고 자료

- Tailwind CSS Dark Mode 공식 문서: https://tailwindcss.com/docs/dark-mode

- 템플릿 예시: `templates/sirsoft-admin_basic/src/components/composite/`

---

## 확장은 중립 표식을 쓴다

이 문서의 `dark:` variant·`.dark` 클래스는 **템플릿 자기 화면**의 방식이다. 확장(모듈·플러그인)은 자기 화면을 자기 CSS 로 그리는데, 템플릿마다 다크를 켜는 방식이 달라(`.dark` 클래스, 시스템 설정, 다크 모드 없음) 그중 하나를 전제할 수 없다. 그래서 코어가 템플릿의 선언을 읽어 **하나의 중립 표식**을 단다(7.2.0+, engine-v1.94.0).

```html
<html data-g7-color-scheme="dark">   <!-- 또는 "light" -->
```

| 템플릿 선언(`template.json` 의 `features.color_scheme`) | 표식이 따라가는 것 |
| --- | --- |
| `{ "strategy": "ancestor-class", "selector": ".dark" }` | `<html>`·`<body>` 의 클래스 — ThemeToggle 이 `.dark` 를 붙이고 떼면 표식도 바로 바뀐다 |
| `{ "strategy": "media-query" }` | 시스템 설정(`prefers-color-scheme`)과 그 변경 |
| `{ "strategy": "none" }` 또는 미선언 | 없음 — 늘 `light` |

- 엔진이 부팅 초반, 확장 자산을 싣기 **전에** 관찰을 시작한다. 확장 CSS 가 실리는 순간 표식은 이미 맞는 값이다.
- 현재 값은 `G7Core.colorScheme.get()`(`'dark'` 또는 `'light'`), 정규화된 선언은 `G7Core.colorScheme.getDeclaration()` 으로 읽는다. 확장 컴포넌트·핸들러가 다크 여부로 분기해야 하면 `.dark` 클래스를 직접 보지 말고 이 값을 쓴다.
- **레이아웃 편집기 프리뷰**: 프리뷰 프레임이 「프리뷰 색상 테마」에 맞춰 `data-g7-color-scheme="dark|light"` 를 함께 단다. 관리자 화면이 다크여도 프리뷰를 라이트로 보면 프리뷰 안 확장 화면은 라이트로, 그 반대도 마찬가지다.
- **검색엔진용 화면**(SEO 렌더)은 라이트로 그리므로 `<html data-g7-color-scheme="light">` 다.
- 템플릿 선언 형식과 검증: [template-basics.md](../extension/template-basics.md#다크-모드-방식-featurescolor_scheme)

확장 CSS 의 다크 규칙은 `.dark` 대신 다음 규격으로 쓴다. 템플릿 `.dark .x` 와 같은 특이도 (0,2,0) 이고, 「다크 안의 라이트」(관리자 다크 + 프리뷰 라이트)만 제외한다.

```css
.ns-x:is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"]):not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"])) { … }
```

Tailwind 로 확장 CSS 를 빌드하면 `@custom-variant dark (&:is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"]):not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"])));` 로 `dark:` 가 이 표식을 따르게 한다. 규약 전문(앞글자·우선순위 세 칸·작성 예시·정적 검사): [module-assets.md 「확장 CSS 자기 공급 규약」](../extension/module-assets.md#확장-css-자기-공급-규약)

---

## 관련 문서

- [컴포넌트 개발 규칙](components.md) - 기본/집합/레이아웃 컴포넌트
- [반응형 레이아웃](responsive-layout.md) - Tailwind breakpoint 활용
- [템플릿 개발 가이드라인](template-development.md) - 빌드 및 테스트
- [확장 CSS 자기 공급 규약](../extension/module-assets.md#확장-css-자기-공급-규약) - 확장 화면의 다크 규칙
