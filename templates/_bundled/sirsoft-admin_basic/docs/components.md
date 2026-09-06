# Admin Basic — 컴포넌트

> 템플릿이 제공하는 컴포넌트 · 진입점: [AGENTS.md](../AGENTS.md)

## 제공 컴포넌트

<!-- @generated:components START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
컴포넌트 125개 (루트: `src/components`).

| 분류 | 개수 |
|---|---|
| `basic` | 39개 |
| `composite` | 80개 |
| `layout` | 5개 |
| `modals` | 1개 |
<!-- @generated:components END -->

<!-- @intent START -->
세 분류(basic/composite/layout)는 "얼마나 많이 조합됐는가"로 나뉩니다 — basic 은 HTML 태그를
그대로 래핑(`Div`→`<div>`)한 최소 단위, composite 는 basic 을 여러 개 조합해 UI 패턴을 캡슐화한
것(`DataGrid`가 `Table`+`Pagination`+정렬 로직을 감싸는 식), layout 은 페이지 구조 자체(Grid/
Flex/Container)를 정의합니다. 레이아웃 JSON 저작자는 이 구분을 몰라도 되지만("HTML 태그
직접 사용 금지" 규정만 지키면 됨), 컴포넌트를 새로 추가하는 쪽은 이 구분에 맞는 디렉토리
(`src/components/{basic,composite,layout}/`)에 넣어야 `components.json` 카탈로그와
`editor-spec.json` 팔레트 분류가 어긋나지 않습니다.

위 개수는 코드에서 실측되므로 시간이 지나면 달라집니다 — 이 문서에 구체적인 개수를 하드코딩해
적지 않습니다(과거 버전 문서가 37/66/8 로 적었다가 실제로는 39/80/5+modals 1 이 되어 있었던
사례가 있습니다). 정확한 전체 목록·Props 는 [component-props.md](../../../../docs/frontend/component-props.md)
· [component-props-composite.md](../../../../docs/frontend/component-props-composite.md) 를
따라갑니다 — 이 문서는 "이 템플릿에서만" 유효한 계약(필수 컴포넌트·AdminSidebar·SlotContainer)
만 다룹니다.
<!-- @intent END -->

## 필수 컴포넌트 (모듈 호환성)

모듈 개발자가 아래 컴포넌트만 사용하면 **다른 Admin 템플릿으로 교체해도 화면이 깨지지
않습니다**. 목록의 SSoT 는 `config/template.php` 의 `required_admin_components` 이며, 아래
표는 그 값을 그대로 옮긴 것입니다(코드가 SSoT — 표와 설정이 어긋나면 설정이 맞습니다).

| 분류 | 컴포넌트 |
|---|---|
| Basic (27개) | `A`, `Button`, `Checkbox`, `Div`, `Form`, `H1`, `H2`, `H3`, `Icon`, `Img`, `Input`, `Label`, `Li`, `Nav`, `P`, `Section`, `Select`, `Span`, `Svg`, `Table`, `Tbody`, `Td`, `Textarea`, `Th`, `Thead`, `Tr`, `Ul` |
| Composite (8개) | `Alert`, `Badge`, `Card`, `DataTable`, `FormField`, `Modal`, `PageHeader`, `Pagination` |

```text
✅ 필수 컴포넌트 목록에 있는 것만 사용 (다른 Admin 템플릿 호환 보장)
✅ 템플릿의 베이스 레이아웃(`_admin_base`)을 extends
❌ 이 템플릿에만 있는 커스텀 컴포넌트 사용 금지
```

기본은 `validate_on_install: false` — 설치 시 필수 컴포넌트 검증은 기본적으로 수행되지 않고
경고에 그칩니다(`block_on_failure: false`). 검증을 강제하려면 두 설정을 함께 켭니다.

## AdminSidebar 상세

계층형 관리자 메뉴를 그리는 컴포넌트로, 데이터 소스로 받은 메뉴 트리를 그대로 넘기면 됩니다.

```typescript
interface MenuItem {
  id: string | number;
  name: string | { ko: string; en: string };
  slug: string;
  url?: string | null;
  icon?: string;              // FontAwesome 클래스 (예: "fas fa-home")
  children?: MenuItem[];
  is_active?: boolean;
  module_id?: number | null;  // 모듈이 등록한 메뉴인지 식별
}

interface AdminSidebarProps {
  logo?: string;
  logoAlt?: string;
  menu: MenuItem[];           // 필수
  collapsed?: boolean;
  onToggleCollapse?: () => void;
  className?: string;
  currentLocale?: string;     // 미지정 시 G7Core.locale.current() 자동 사용
  id?: string;                // 레이아웃 편집기 코어 일괄 ID
}
```

아이콘은 `Icon` 컴포넌트(IconName enum)가 아니라 `I` 컴포넌트 + FontAwesome 클래스 문자열로
렌더링됩니다 — 메뉴 API 가 `icon` 필드를 이미 `"fas fa-home"` 형태 클래스 문자열로 내려주기
때문입니다. 새 메뉴 아이콘을 추가할 때 `Icon name="home"` 식으로 쓰면 렌더되지 않습니다.

## SlotContainer 상세

동적 슬롯 렌더링 컨테이너입니다 — 다른 컴포넌트가 `slot` prop 으로 자신이 속할 슬롯 ID 를
표현식으로 지정하면(예: `"{{_local.isVisible ? 'basic_filters' : 'detail_filters'}}"`), 그
표현식이 상태 변화에 따라 재평가되어 컴포넌트가 슬롯 사이를 동적으로 이동합니다.

```typescript
interface SlotContainerProps {
  slotId: string;    // 필수 — 렌더링할 슬롯 ID
  className?: string;
}
```

```json
{ "id": "category_filter", "type": "basic", "name": "Div", "slot": "{{_local.isVisible ? 'basic_filters' : 'detail_filters'}}", "slotOrder": 1, "children": [] }
```

```json
{ "id": "basic_filters_container", "type": "composite", "name": "SlotContainer", "props": { "slotId": "basic_filters" } }
```

## 이관 원문 상세

> 아래는 코어 `docs/frontend/templates/sirsoft-admin_basic/components.md` 에 있던 원문을
> 이 문서로 옮긴 것입니다(#601). 이관 시점 그대로 보존하되, **코드가 SSoT 인 값과 어긋나는
> 부분에는 정정 주석**을 달았습니다 — 실측 총계는 위 「제공 컴포넌트」 블록이, 필수 컴포넌트
> 목록은 `config/template.php` 의 `required_admin_components` 가 SSoT 입니다.

### 컴포넌트 개요

> **정정(#601)**: 아래 개수는 이관 시점(2026-03) 문서 값입니다. 코드 실측은 basic 39 · composite 80 ·
> layout 5 · modals 1 = **125종**이며 위 「제공 컴포넌트」 블록이 SSoT 입니다. 아래 목록에 없는
> 컴포넌트가 있을 수 있습니다.

| 타입 | 개수 | 설명 |
|------|------|------|
| Basic | 37 | HTML 태그 래핑 — 최소 단위 컴포넌트 |
| Composite | 66 | 기본 컴포넌트 조합 — UI 패턴 캡슐화 |
| Layout | 8 | 페이지 구조 정의 — 컨테이너/그리드/플렉스 |
| **합계** | **111** | |

**소스**: `templates/_bundled/sirsoft-admin_basic/components.json`
**컴포넌트 소스**: `templates/_bundled/sirsoft-admin_basic/src/components/{basic,composite,layout}/*.tsx`

---

### Basic Components (37개)

> **정정(#601)**: 제목의 개수는 이관 시점 문서 값입니다(코드 실측 39종). 목록 자체는 원문 그대로입니다.

HTML 태그를 래핑하는 최소 단위 컴포넌트입니다. 모든 Basic 컴포넌트는 `className`, `children` props를 공통으로 지원합니다.

#### 텍스트/링크

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

#### 컨테이너

| 컴포넌트 | 설명 | 주요 Props | 바인딩 |
|----------|------|-----------|--------|
| `Div` | 범용 컨테이너 | - | - |
| `Section` | 시맨틱 섹션 | - | - |
| `Nav` | 네비게이션 래퍼 | - | - |
| `Form` | 폼 컨테이너 (자동 바인딩 지원) | - | - |
| `Fragment` | React.Fragment — iterator에서 DOM 래퍼 없이 사용 | - | - |

#### 폼 입력

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

#### 테이블

| 컴포넌트 | 설명 |
|----------|------|
| `Table` | HTML table wrapper |
| `Thead` | 테이블 헤더 그룹 |
| `Tbody` | 테이블 본문 그룹 |
| `Tfoot` | 테이블 푸터 그룹 |
| `Tr` | 테이블 행 |
| `Th` | 테이블 헤더 셀 |
| `Td` | 테이블 데이터 셀 |

#### 리스트

| 컴포넌트 | 설명 |
|----------|------|
| `Ul` | 순서 없는 리스트 |
| `Ol` | 순서 있는 리스트 |
| `Li` | 리스트 아이템 |

#### 미디어/아이콘

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `Img` | 이미지 | src, alt |
| `Icon` | FontAwesome 아이콘 | name, iconStyle, size, color, spin, pulse, fixedWidth, ariaLabel |
| `Svg` | SVG 컨테이너 | - |
| `I` | HTML i 태그 (FontAwesome 클래스 직접 사용) | style |

---

### Composite Components (66개)

> **정정(#601)**: 제목의 개수는 이관 시점 문서 값입니다(코드 실측 80종). 목록 자체는 원문 그대로입니다.

기본 컴포넌트를 조합하여 UI 패턴을 캡슐화한 복합 컴포넌트입니다.

#### 데이터 표시

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `DataGrid` | 정렬/필터링/페이지네이션 데이터 그리드 | columns, data, sortable, pagination, pageSize, ... |
| `CardGrid` | 카드 그리드 레이아웃 (스켈레톤 로딩, 페이지네이션) | data, cardColumns, columns, gap, responsiveColumns, ... |
| `Pagination` | 페이지네이션 | currentPage, totalPages, onPageChange, maxVisiblePages, showFirstLast, ... |
| `Badge` | 색상 기반 라벨 뱃지 | color, text, size, style |
| `StatusBadge` | 상태 뱃지 (아이콘 포함) | status, label, showIcon, iconName, style |
| `StatCard` | 통계 카드 (값, 라벨, 추이 표시) | value, label, change, changeLabel, iconName, ... |
| `EmptyState` | 데이터 없음 상태 표시 | title, description, iconName, illustrationSrc, ... |
| `HtmlContent` | HTML 안전 렌더링 (DOMPurify XSS 방지) | content, isHtml, purifyConfig, text |

#### 폼/입력

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

#### 에디터

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `HtmlEditor` | HTML/텍스트 편집기 (편집/미리보기 토글) | content, onChange, isHtml, onHtmlModeChange, previewMode, ... |
| `CodeEditor` | JSON 코드 편집기 (Monaco Editor) | value, onChange, language, height, readOnly, ... |

#### 네비게이션

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `TabNavigation` | 탭 네비게이션 | tabs, activeTabId, onTabChange, variant, style |
| `TabNavigationScroll` | 탭 네비게이션 + 스크롤 (setState + scrollToSection 내장) | tabs, activeTabId, actions, style, activeClassName, ... |
| `Breadcrumb` | 브레드크럼 | items, separator, showHome, homeHref, maxItems |
| `ActionMenu` | 드롭다운 액션 메뉴 | items, triggerLabel, triggerIconName, position, style |
| `Dropdown` | 드롭다운 메뉴 | label, items, onItemClick, position, style |

#### 모달/다이얼로그

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `Modal` | 모달 다이얼로그 (오버레이, 포커스 트랩) | isOpen, onClose, title, width, style |
| `Dialog` | 다이얼로그 (Modal 별칭) | isOpen, onClose, title, content, actions, ... |
| `ConfirmDialog` | 확인/취소 다이얼로그 | isOpen, onClose, title, message, confirmText, ... |
| `AlertDialog` | 알림 다이얼로그 (확인 버튼만) | isOpen, onClose, title, message, confirmText, ... |
| `Toast` | 토스트 알림 | toasts, position, onRemove |
| `Alert` | 알림 메시지 | type, message, dismissible, onDismiss |

#### 관리자 UI

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

#### 레이아웃 편집

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `LayoutEditorHeader` | 레이아웃 편집기 헤더 | layoutName, onBack, onPreview, onSave, isSaving |
| `LayoutFileList` | 레이아웃 파일 목록 | files, selectedId, onSelect |
| `LayoutHistoryPanel` | 레이아웃 히스토리 패널 | layoutId, versions, onRestore |
| `LayoutWarnings` | 레이아웃 경고 표시 | warnings |
| `VersionList` | 버전 목록 아이템 | versions, selectedId, onSelect |

#### 확장 관리

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `TemplateCard` | 템플릿 카드 (설치/활성화) | image, imageAlt, vendor, name, version, ... |
| `ExtensionBadge` | 확장 섹션 뱃지 (identifier로 이름 자동 조회) | type, identifier, name, installedModules, installedPlugins, ... |
| `ProductCard` | 상품 카드 (이미지, 제목, 가격, 액션) | imageUrl, imageAlt, title, subtitle, description, ... |

#### 고급 기능

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

### Layout Components (8개)

> **정정(#601)**: 제목의 개수는 이관 시점 문서 값입니다(코드 실측 5종). 목록 자체는 원문 그대로입니다.

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

### 필수 컴포넌트 (모듈 호환성)

모듈 개발자가 이 컴포넌트들만 사용하면 **모든 Admin 템플릿에서 동작이 보장**됩니다.

#### 필수 Basic (27개)

| 카테고리 | 컴포넌트 |
|----------|----------|
| 텍스트/링크 | `A`, `H1`, `H2`, `H3`, `P`, `Span`, `Label` |
| 컨테이너 | `Div`, `Section`, `Nav` |
| 폼 | `Form`, `Input`, `Select`, `Checkbox`, `Textarea`, `Button` |
| 테이블 | `Table`, `Thead`, `Tbody`, `Tr`, `Th`, `Td` |
| 리스트 | `Ul`, `Li` |
| 미디어 | `Icon`, `Img`, `Svg` |

#### 필수 Composite (15개)

> **정정(#601)**: 아래 표는 `config/template.php` 의 `required_admin_components` 와 일치하지 않습니다 —
> 실제 필수 Composite 는 위 「필수 컴포넌트 (모듈 호환성)」 절의 8종(`Alert` `Badge` `Card`
> `DataTable` `FormField` `Modal` `PageHeader` `Pagination`)이며 설정 파일이 SSoT 입니다.
> 아래 목록은 이관 시점 문서를 보존한 것이므로 필수 여부의 근거로 쓰지 않습니다.

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

#### 설정 파일

> **정정(#601)**: 아래 PHP 스니펫은 이관 시점 값입니다. 현재 `config/template.php` 의 실제 배열은 위
> 「필수 컴포넌트 (모듈 호환성)」 절의 표(Basic 27 + Composite 8)와 일치합니다.

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

### 모듈 개발자 가이드

#### 핵심 원칙

```text
✅ 필수 컴포넌트 목록에 있는 컴포넌트만 사용
✅ 템플릿의 베이스 레이아웃 (_admin_base)을 extends
❌ 특정 템플릿에만 존재하는 커스텀 컴포넌트 사용 금지
```

#### 레이아웃 작성 예시

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

### 템플릿 개발자 가이드

Admin 타입 템플릿 개발 시 필수 컴포넌트를 반드시 구현해야 합니다.

#### 구현 체크리스트

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

#### Props 인터페이스 일관성

모든 Admin 템플릿은 동일한 Props 인터페이스를 구현해야 합니다. 상세 Props는 다음 문서를 참조:

- [컴포넌트 Props 레퍼런스 (Basic)](../../../../docs/frontend/component-props.md)
- [컴포넌트 Props 레퍼런스 (Composite)](../../../../docs/frontend/component-props-composite.md)

---

### AdminSidebar 상세

#### MenuItem 인터페이스

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

#### AdminSidebarProps

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

#### 아이콘 처리

```text
✅ I 컴포넌트 + FontAwesome 클래스 (<I className="fas fa-home w-5 h-5" />)
❌ Icon 컴포넌트 + IconName enum (금지 — API가 FontAwesome 클래스 문자열 직접 제공)
```

#### 레이아웃 JSON 사용 예시

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

### SlotContainer 상세

#### SlotContainerProps

```typescript
interface SlotContainerProps {
  slotId: string;           // 필수 — 렌더링할 슬롯 ID
  className?: string;
}
```

#### 슬롯 시스템 동작

```text
1. slot 속성 컴포넌트 → SlotContext에 등록
2. SlotContainer가 해당 slotId의 컴포넌트 렌더링
3. 상태 변화 시 slot 표현식 재평가로 동적 이동
```

#### 사용 예시

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

### 관련 문서

- [sirsoft-admin_basic 핸들러](handlers.md)
- [sirsoft-admin_basic 레이아웃](layouts.md)
- [sirsoft-basic 컴포넌트](../../sirsoft-basic/docs/components.md)
- [컴포넌트 개발 규칙](../../../../docs/frontend/components.md)
- [컴포넌트 Props 레퍼런스](../../../../docs/frontend/component-props.md)
- [컴포넌트 Props 레퍼런스 - Composite](../../../../docs/frontend/component-props-composite.md)
