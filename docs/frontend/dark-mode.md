# 다크 모드 지원 (engine-v1.1.0+)

> **상위 문서**: [프론트엔드 가이드 인덱스](index.md)

그누보드7 템플릿 시스템은 Tailwind CSS dark mode variant를 활용하여 다크 모드를 전면 지원합니다.

---

## TL;DR (5초 요약)

```text
1. Tailwind dark: variant 사용 (예: bg-white dark:bg-gray-800)
2. 배경: bg-white dark:bg-gray-800/900
3. 텍스트: text-gray-900 dark:text-white
4. 테두리: border-gray-200 dark:border-gray-700
5. 항상 light/dark 쌍으로 지정 필수!
```

---

## 템플릿별 지원 현황

| 템플릿 식별자 | features.dark_mode | 설명 |
|--------------|-------------------|------|
| `sirsoft-admin_basic` | 미선언 | ThemeToggle 존재, dark: variant 동작 |
| `sirsoft-basic` | `true` | template.json에 선언, 완전 지원 |

> 상세 컴포넌트 목록: [sirsoft-admin_basic](templates/sirsoft-admin_basic/components.md), [sirsoft-basic](templates/sirsoft-basic/components.md)

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

```json
{
  "type": "Div",
  "props": {
    "className": "bg-white dark:bg-gray-800 p-4 rounded-lg border border-gray-200 dark:border-gray-700"
  },
  "children": [
    {
      "type": "H2",
      "props": {
        "className": "text-gray-900 dark:text-white font-bold mb-2",
        "text": "$t:dashboard.title"
      }
    },
    {
      "type": "P",
      "props": {
        "className": "text-gray-600 dark:text-gray-400",
        "text": "{{stats.description}}"
      }
    }
  ]
}
```

---

## 5. ThemeToggle 컴포넌트

다크 모드 전환은 `ThemeToggle` composite 컴포넌트를 사용:

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

- 다크 모드 전용 색상만 하드코딩 (예: `border-gray-700`만 사용)
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

## 관련 문서

- [컴포넌트 개발 규칙](components.md) - 기본/집합/레이아웃 컴포넌트
- [반응형 레이아웃](responsive-layout.md) - Tailwind breakpoint 활용
- [템플릿 개발 가이드라인](template-development.md) - 빌드 및 테스트
