# sirsoft-admin_basic 컴포넌트

> **템플릿 식별자**: `sirsoft-admin_basic` (type: admin, v0.2.14)
> **관련 문서**: [핸들러](./handlers.md) | [레이아웃](./layouts.md) | [컴포넌트 Props 레퍼런스](../../component-props.md)

---

## TL;DR (5초 요약)

```text
1. Basic 37개: HTML 래핑 (Div, Button, Input, Select, Form, A, H1~H4, Table 등)
2. Composite 66개: UI 패턴 캡슐화 (DataGrid, Modal, PageHeader, Card, FileUploader 등)
3. Layout 8개: 페이지 구조 (Container, Grid, Flex, SectionLayout, ThreeColumnLayout 등)
4. 모듈은 필수 컴포넌트(27 basic + 15 composite)만 사용 → 모든 Admin 템플릿 호환 보장
5. components.json에 등록 필수, Props는 이 문서 + component-props.md 참조
```

---

## 목차

1. [컴포넌트 개요](#컴포넌트-개요)
2. [Basic Components (37개)](#basic-components-37개)
3. [Composite Components (66개)](#composite-components-66개)
4. [Layout Components (8개)](#layout-components-8개)
5. [필수 컴포넌트 (모듈 호환성)](#필수-컴포넌트-모듈-호환성)
6. [모듈 개발자 가이드](#모듈-개발자-가이드)
7. [템플릿 개발자 가이드](#템플릿-개발자-가이드)

---

## 컴포넌트 개요

| 타입 | 개수 | 설명 |
|------|------|------|
| Basic | 37 | HTML 태그 래핑 — 최소 단위 컴포넌트 |
| Composite | 66 | 기본 컴포넌트 조합 — UI 패턴 캡슐화 |
| Layout | 8 | 페이지 구조 정의 — 컨테이너/그리드/플렉스 |
| **합계** | **111** | |

**소스**: `templates/_bundled/sirsoft-admin_basic/components.json`
**컴포넌트 소스**: `templates/_bundled/sirsoft-admin_basic/src/components/{basic,composite,layout}/*.tsx`

---

## Basic Components (37개)

HTML 태그를 래핑하는 최소 단위 컴포넌트입니다. 모든 Basic 컴포넌트는 `className`, `children` props를 공통으로 지원합니다.

### 텍스트/링크

| 컴포넌트 | 설명 | 주요 Props | 바인딩 |
|----------|------|-----------|--------|
| `A` | HTML anchor element wrapper | href, target | - |
| `H1` | HTML h1 heading element wrapper | - | - |
| `H2` | HTML h2 heading element wrapper | - | - |
| `H3` | HTML h3 heading element wrapper | - | - |
| `H4` | HTML h4 heading element wrapper | - | - |
| `P` | HTML paragraph element wrapper | - | - |
| `Span` | HTML span element wrapper | - | - |
| `Label` | HTML label element wrapper | - | - |
| `Pre` | 서식 유지 텍스트 래퍼 | - | - |
| `Code` | 인라인 코드 래퍼 | - | - |

### 컨테이너

| 컴포넌트 | 설명 | 주요 Props | 바인딩 |
|----------|------|-----------|--------|
| `Div` | 범용 컨테이너 | - | - |
| `Section` | 시맨틱 섹션 | - | - |
| `Nav` | 네비게이션 래퍼 | - | - |
| `Form` | 폼 컨테이너 (자동 바인딩 지원) | - | - |
| `Fragment` | React.Fragment — iterator에서 DOM 래퍼 없이 사용 | - | - |

### 폼 입력

| 컴포넌트 | 설명 | 주요 Props | 바인딩 |
|----------|------|-----------|--------|
| `Input` | 텍스트 입력 | label, error | checkable |
| `Select` | 선택 박스 | label, error | - |
| `Option` | Select 내부 옵션 | value | - |
| `Optgroup` | Select 옵션 그룹화 | label | - |
| `Textarea` | 텍스트 영역 | label, error | - |
| `Checkbox` | 체크박스 | label | checked |
| `FileInput` | 파일 입력 (검증 포함) | accept, maxSize, onChange, onError, buttonText, placeholder, disabled | - |
| `Button` | 버튼 | variant (`primary`\|`secondary`\|`danger`\|`success`), size (`sm`\|`md`\|`lg`) | - |

### 테이블

| 컴포넌트 | 설명 |
|----------|------|
| `Table` | HTML table wrapper |
| `Thead` | 테이블 헤더 그룹 |
| `Tbody` | 테이블 본문 그룹 |
| `Tfoot` | 테이블 푸터 그룹 |
| `Tr` | 테이블 행 |
| `Th` | 테이블 헤더 셀 |
| `Td` | 테이블 데이터 셀 |

### 리스트

| 컴포넌트 | 설명 |
|----------|------|
| `Ul` | 순서 없는 리스트 |
| `Ol` | 순서 있는 리스트 |
| `Li` | 리스트 아이템 |

### 미디어/아이콘

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `Img` | 이미지 | src, alt |
| `Icon` | FontAwesome 아이콘 | name, iconStyle, size, color, spin, pulse, fixedWidth, ariaLabel |
| `Svg` | SVG 컨테이너 | - |
| `I` | HTML i 태그 (FontAwesome 클래스 직접 사용) | style |

---

## Composite Components (66개)

기본 컴포넌트를 조합하여 UI 패턴을 캡슐화한 복합 컴포넌트입니다.

### 데이터 표시

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `DataGrid` | 정렬/필터링/페이지네이션 데이터 그리드 | columns, data, sortable, pagination, pageSize, ... |
| `CardGrid` | 카드 그리드 레이아웃 (스켈레톤 로딩, 페이지네이션) | data, cardChildren, columns, gap, responsiveColumns, ... |
| `Pagination` | 페이지네이션 | currentPage, totalPages, onPageChange, maxVisiblePages, showFirstLast, ... |
| `Badge` | 색상 기반 라벨 뱃지 | color, text, size, style |
| `StatusBadge` | 상태 뱃지 (아이콘 포함) | status, label, showIcon, iconName, style |
| `StatCard` | 통계 카드 (값, 라벨, 추이 표시) | value, label, change, changeLabel, iconName, ... |
| `EmptyState` | 데이터 없음 상태 표시 | title, description, iconName, illustrationSrc, ... |
| `HtmlContent` | HTML 안전 렌더링 (DOMPurify XSS 방지) | content, isHtml, purifyConfig, text |

### 폼/입력

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `FormField` | 폼 필드 래퍼 (라벨, 에러, 헬퍼 텍스트) | label, required, error, helperText, labelClassName, ... |
| `Toggle` | 토글 스위치 (Flowbite 스타일) | checked, value, onChange, disabled, label, ... |
| `TagInput` | 태그 입력 (다중 선택, 생성 가능) | value, options, onChange, creatable, placeholder, ... |
| `TagSelect` | 태그 기반 선택 표시 | options, value, onChange, placeholder, disabled |
| `RadioGroup` | 라디오 버튼 그룹 | name, value, options, onChange, disabled, ... |
| `SearchBar` | 검색 바 (자동완성 제안) | placeholder, value, onChange, onSearch, suggestions, ... |
| `SearchableDropdown` | 검색 가능 드롭다운 (단일/다중) | options, value, onChange, multiple, searchPlaceholder, ... |
| `ChipCheckbox` | 칩 스타일 체크박스 (필터 UI) | value, checked, icon, label, style, ... |
| `MultilingualInput` | 탭 방식 다국어 텍스트 입력 | value, onChange, inputType, availableLocales, defaultLocale, ... |
| `MultilingualTagInput` | 다국어 태그 입력 (모달 편집) | value, onChange, placeholder, disabled, creatable, ... |
| `MultilingualTabPanel` | 다국어 탭 패널 (로케일 제어) | style, variant, defaultLocale, onLocaleChange |
| `DynamicFieldList` | 동적 필드 목록 (드래그 정렬, 추가/삭제) | items, columns, onChange, onAddItem, onRemoveItem, ... |
| `FileUploader` | 파일 업로드 (드래그앤드롭, 이미지 압축) | attachmentableType, attachmentableId, collection, maxFiles, maxSize, ... |
| `IconSelect` | 아이콘 선택 드롭다운 | value, onChange, options, placeholder, searchPlaceholder, ... |

### 에디터

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `HtmlEditor` | HTML/텍스트 편집기 (편집/미리보기 토글) | content, onChange, isHtml, onHtmlModeChange, previewMode, ... |
| `CodeEditor` | JSON 코드 편집기 (Monaco Editor) | value, onChange, language, height, readOnly, ... |

### 네비게이션

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `TabNavigation` | 탭 네비게이션 | tabs, activeTabId, onTabChange, variant, style |
| `TabNavigationScroll` | 탭 네비게이션 + 스크롤 (setState + scrollToSection 내장) | tabs, activeTabId, actions, style, activeClassName, ... |
| `Breadcrumb` | 브레드크럼 | items, separator, showHome, homeHref, maxItems |
| `ActionMenu` | 드롭다운 액션 메뉴 | items, triggerLabel, triggerIconName, position, style |
| `Dropdown` | 드롭다운 메뉴 | label, items, onItemClick, position, style |

### 모달/다이얼로그

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `Modal` | 모달 다이얼로그 (오버레이, 포커스 트랩) | isOpen, onClose, title, width, style |
| `Dialog` | 다이얼로그 (Modal 별칭) | isOpen, onClose, title, content, actions, ... |
| `ConfirmDialog` | 확인/취소 다이얼로그 | isOpen, onClose, title, message, confirmText, ... |
| `AlertDialog` | 알림 다이얼로그 (확인 버튼만) | isOpen, onClose, title, message, confirmText, ... |
| `Toast` | 토스트 알림 | toasts, position, onRemove |
| `Alert` | 알림 메시지 | type, message, dismissible, onDismiss |

### 관리자 UI

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `AdminSidebar` | 관리자 사이드바 (계층형 메뉴) | logo, logoAlt, menu, collapsed, onToggleCollapse |
| `AdminHeader` | 관리자 헤더 | user, notifications, onNotificationClick, onProfileClick, onLogoutClick |
| `AdminFooter` | 관리자 푸터 | copyright, version, quickLinks |
| `PageHeader` | 페이지 헤더 (제목, 브레드크럼, 액션) | title, subtitle, breadcrumbItems, tabs, onTabChange, ... |
| `LoginForm` | 로그인 폼 (이메일/비밀번호 검증) | submitButtonText, emailPlaceholder, passwordPlaceholder, forgotPasswordText, forgotPasswordUrl, ... |
| `UserProfile` | 사용자 프로필 드롭다운 | user, profileText, logoutText, onProfileClick, onLogoutClick |
| `NotificationCenter` | 알림 센터 | notifications, titleText, emptyText, onNotificationClick |
| `ThemeToggle` | 테마 모드 전환 (다크/라이트/자동) | onThemeChange, autoText, lightText, darkText |
| `LanguageSelector` | 언어 선택 드롭다운 | availableLocales, languageText, apiEndpoint, onLanguageChange, inline |
| `PageTransitionIndicator` | 페이지 전환 로딩 표시 | style |

### 레이아웃 편집

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `LayoutEditorHeader` | 레이아웃 편집기 헤더 | layoutName, onBack, onPreview, onSave, isSaving |
| `LayoutFileList` | 레이아웃 파일 목록 | files, selectedId, onSelect |
| `LayoutHistoryPanel` | 레이아웃 히스토리 패널 | layoutId, versions, onRestore |
| `LayoutWarnings` | 레이아웃 경고 표시 | warnings |
| `VersionList` | 버전 목록 아이템 | versions, selectedId, onSelect |

### 확장 관리

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `TemplateCard` | 템플릿 카드 (설치/활성화) | image, imageAlt, vendor, name, version, ... |
| `ExtensionBadge` | 확장 섹션 뱃지 (identifier로 이름 자동 조회) | type, identifier, name, installedModules, installedPlugins, ... |
| `ProductCard` | 상품 카드 (이미지, 제목, 가격, 액션) | imageUrl, imageAlt, title, subtitle, description, ... |

### 고급 기능

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `SlotContainer` | 동적 슬롯 렌더링 컨테이너 | slotId, emptyContent, style, id |
| `FilterGroup` | 다중 필터 그룹 | title, filters, onChange, onReset, showResetButton, ... |
| `FilterVisibilitySelector` | 필터 가시성 상태 관리 (UI 없음, localStorage 저장) | id, visibleFilters, defaultFilters, onFilterVisibilityChange |
| `ColumnSelector` | 테이블 컬럼 표시/숨김 선택 드롭다운 | columns, visibleColumns, onColumnVisibilityChange, triggerLabel, triggerIconName, ... |
| `PermissionTree` | 계층형 권한 트리 (체크박스 선택) | data, value, onChange, disabled, desktopColumns |
| `CategoryTree` | 계층형 카테고리 트리 (체크박스 선택) | data, expandedIds, selectedIds, searchKeyword, showProductCount, ... |
| `SortableMenuList` | 드래그앤드롭 계층형 메뉴 목록 | items, selectedId, onSelect, onOrderChange, onToggleStatus, ... |
| `SortableMenuItem` | 개별 드래그 가능 메뉴 아이템 | item, isSelected, isExpanded, level, onClick, ... |
| `IconButton` | 아이콘 버튼 | iconName, label, onClick, variant, size, ... |
| `Accordion` | 아코디언 (접기/펼치기) | defaultOpen, isOpen, onToggle, style, disabled |
| `Card` | 카드 컨테이너 (헤더, 본문, 푸터) | title, content, imageUrl, imageAlt, onClick, ... |
| `LoadingSpinner` | 로딩 스피너 | size, color, fullscreen, text |
| `ImageGallery` | 라이트박스 이미지 갤러리 (줌, 다운로드, 썸네일) | images, initialIndex, onClose |

---

## Layout Components (8개)

페이지 구조를 정의하는 레이아웃 컴포넌트입니다.

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `Container` | Flex/Grid 컨테이너 | mode, direction, justify, align, wrap, gap, cols, responsive, padding, maxWidth, centered, style |
| `Grid` | CSS Grid 반응형 그리드 | cols, responsive, gap, rowGap, colGap, autoRows, autoCols, flow, style |
| `Flex` | Flexbox 레이아웃 | direction, justify, align, wrap, gap, grow, shrink, style |
| `SectionLayout` | 섹션 레이아웃 (스타일 옵션) | title, subtitle, padding, background, maxWidth, centered, border, shadow, rounded, style |
| `ThreeColumnLayout` | 3열 레이아웃 (좌/중/우 슬롯) | leftWidth, rightWidth, leftSlot, centerSlot, rightSlot, style |
| `RichSelect` | 커스텀 항목 렌더링 셀렉트 | options, value, onChange, placeholder, disabled, maxHeight, selectedChildren |
| `DropdownButton` | 포탈 기반 드롭다운 버튼 | label, icon, iconPosition, position |
| `DropdownMenuItem` | DropdownButton 내부 메뉴 아이템 | label, icon, variant, disabled, divider |

---

## 필수 컴포넌트 (모듈 호환성)

모듈 개발자가 이 컴포넌트들만 사용하면 **모든 Admin 템플릿에서 동작이 보장**됩니다.

### 필수 Basic (27개)

| 카테고리 | 컴포넌트 |
|----------|----------|
| 텍스트/링크 | `A`, `H1`, `H2`, `H3`, `P`, `Span`, `Label` |
| 컨테이너 | `Div`, `Section`, `Nav` |
| 폼 | `Form`, `Input`, `Select`, `Checkbox`, `Textarea`, `Button` |
| 테이블 | `Table`, `Thead`, `Tbody`, `Tr`, `Th`, `Td` |
| 리스트 | `Ul`, `Li` |
| 미디어 | `Icon`, `Img`, `Svg` |

### 필수 Composite (15개)

| 카테고리 | 컴포넌트 | 설명 |
|----------|----------|------|
| 데이터 표시 | `DataGrid` | 목록 페이지 필수 (테이블 형식) |
| | `CardGrid` | 목록 페이지 필수 (카드 형식) |
| | `Pagination` | 페이지네이션 |
| | `Badge` | 상태 표시 |
| 폼 | `FormField` | 폼 필드 래퍼 |
| | `Toggle` | 토글 스위치 |
| | `TagInput` | 태그 입력 |
| 에디터 | `HtmlEditor` | HTML/텍스트 에디터 |
| | `CodeEditor` | 코드 에디터 (Monaco) |
| 피드백 | `Modal` | 모달 다이얼로그 |
| | `Alert` | 알림 메시지 |
| 레이아웃 | `PageHeader` | 페이지 헤더 |
| | `Card` | 카드 컨테이너 |
| | `AdminSidebar` | 관리자 사이드바 |
| | `SlotContainer` | 동적 슬롯 렌더링 |

### 설정 파일

필수 컴포넌트 목록은 `config/template.php`에 정의:

```php
'required_admin_components' => [
    'DataTable', 'Pagination', 'Badge',
    'Form', 'FormField', 'Input', 'Select', 'Checkbox',
    'Button', 'Modal', 'Alert',
    'PageHeader', 'Card',
],
```

---

## 모듈 개발자 가이드

### 핵심 원칙

```text
✅ 필수 컴포넌트 목록에 있는 컴포넌트만 사용
✅ 템플릿의 베이스 레이아웃 (_admin_base)을 extends
❌ 특정 템플릿에만 존재하는 커스텀 컴포넌트 사용 금지
```

### 레이아웃 작성 예시

```json
{
  "version": "1.0.0",
  "layout_name": "sirsoft-ecommerce_admin_products_index",
  "extends": "_admin_base",
  "slots": {
    "content": [
      {
        "id": "products-table",
        "type": "composite",
        "name": "DataGrid",
        "props": {
          "columns": [],
          "data": "{{products?.data?.data}}"
        }
      }
    ]
  }
}
```

---

## 템플릿 개발자 가이드

Admin 타입 템플릿 개발 시 필수 컴포넌트를 반드시 구현해야 합니다.

### 구현 체크리스트

```text
□ DataGrid - 정렬, 필터링, 페이지네이션 지원
□ Pagination - 페이지 이동, 페이지 크기 변경
□ Badge - 다양한 상태 색상 지원
□ Form - 유효성 검사, 제출 처리
□ FormField - 라벨, 에러 메시지, 필수 표시
□ Input - 텍스트, 이메일, 비밀번호 등 타입 지원
□ Select - 단일/다중 선택, 검색 기능
□ Checkbox - 단일/그룹 체크박스
□ Button - 다양한 variant와 size
□ Modal - 열기/닫기, 확인/취소 액션
□ Alert - success, warning, error, info 타입
□ PageHeader - 제목, 브레드크럼, 액션 버튼
□ Card - 헤더, 본문, 푸터 영역
□ AdminSidebar - 계층형 메뉴, 다국어 지원
```

### Props 인터페이스 일관성

모든 Admin 템플릿은 동일한 Props 인터페이스를 구현해야 합니다. 상세 Props는 다음 문서를 참조:

- [컴포넌트 Props 레퍼런스 (Basic)](../../component-props.md)
- [컴포넌트 Props 레퍼런스 (Composite)](../../component-props-composite.md)

---

## AdminSidebar 상세

### MenuItem 인터페이스

```typescript
interface MenuItem {
  id: string | number;
  name: string | { ko: string; en: string };
  slug: string;
  url?: string | null;
  icon?: string;           // FontAwesome 클래스 (예: "fas fa-home")
  children?: MenuItem[];
  is_active?: boolean;
}
```

### AdminSidebarProps

```typescript
interface AdminSidebarProps {
  logo?: string;
  logoAlt?: string;
  menu: MenuItem[];          // 필수
  collapsed?: boolean;
  onToggleCollapse?: () => void;
  className?: string;
  currentLocale?: string;    // 기본값: 'ko'
}
```

### 아이콘 처리

```text
✅ I 컴포넌트 + FontAwesome 클래스 (<I className="fas fa-home w-5 h-5" />)
❌ Icon 컴포넌트 + IconName enum (금지 — API가 FontAwesome 클래스 문자열 직접 제공)
```

### 레이아웃 JSON 사용 예시

```json
{
  "data_sources": [
    {
      "id": "admin_menu",
      "type": "api",
      "endpoint": "/api/admin/menus",
      "method": "GET",
      "auto_fetch": true,
      "auth_required": true
    }
  ],
  "components": [
    {
      "id": "admin_sidebar",
      "type": "composite",
      "name": "AdminSidebar",
      "props": {
        "menu": "{{admin_menu.data}}"
      }
    }
  ]
}
```

---

## SlotContainer 상세

### SlotContainerProps

```typescript
interface SlotContainerProps {
  slotId: string;           // 필수 — 렌더링할 슬롯 ID
  className?: string;
}
```

### 슬롯 시스템 동작

```text
1. slot 속성 컴포넌트 → SlotContext에 등록
2. SlotContainer가 해당 slotId의 컴포넌트 렌더링
3. 상태 변화 시 slot 표현식 재평가로 동적 이동
```

### 사용 예시

```json
{
  "id": "category_filter",
  "type": "basic",
  "name": "Div",
  "slot": "{{_local.isVisible ? 'basic_filters' : 'detail_filters'}}",
  "slotOrder": 1,
  "children": []
}
```

```json
{
  "id": "basic_filters_container",
  "type": "composite",
  "name": "SlotContainer",
  "props": { "slotId": "basic_filters" }
}
```

---

## 관련 문서

- [sirsoft-admin_basic 핸들러](./handlers.md)
- [sirsoft-admin_basic 레이아웃](./layouts.md)
- [sirsoft-basic 컴포넌트](../sirsoft-basic/components.md)
- [컴포넌트 개발 규칙](../../components.md)
- [컴포넌트 Props 레퍼런스](../../component-props.md)
- [컴포넌트 Props 레퍼런스 - Composite](../../component-props-composite.md)
