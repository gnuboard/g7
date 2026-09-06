# Basic — 컴포넌트

> 템플릿이 제공하는 컴포넌트 · 진입점: [AGENTS.md](../AGENTS.md)

## 제공 컴포넌트

<!-- @generated:components START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
컴포넌트 79개 (루트: `src/components`).

| 분류 | 개수 |
|---|---|
| `basic` | 38개 |
| `composite` | 36개 |
| `layout` | 5개 |
<!-- @generated:components END -->

<!-- @intent START -->
세 분류는 "얼마나 조합됐는가" 로 나뉩니다 — `basic` 은 HTML 태그를 그대로 래핑한 최소 단위
(`Div`→`<div>`), `composite` 는 basic 을 조합해 UI 패턴을 캡슐화한 것(`Header` 가 로고·네비·
검색·사용자 메뉴를 감싸는 식), `layout` 은 페이지 구조(Container/Grid/Flex)입니다. 새 컴포넌트는
이 구분에 맞는 디렉토리(`src/components/{basic,composite,layout}/`)에 넣어야 `components.json`
카탈로그와 `editor-spec.json` 팔레트 분류가 어긋나지 않습니다.

**이 템플릿은 방문자 화면 전용**이라 관리자 템플릿(`sirsoft-admin_basic`)과 컴포넌트 구성이
크게 다릅니다. `DataGrid` · `AdminSidebar` · `MultilingualInput` 같은 관리 도구가 없고, 대신
`ProductCard` · `ProductImageViewer` · `QuantitySelector` · `PostReactions` · `MobileNav` ·
`SocialLoginButtons` 처럼 상점·게시판·인증 화면에 필요한 것들이 있습니다. **모듈이 방문자
화면을 그리지 않고 API 만 제공하는 구조**(게시판·이커머스 모두 레이아웃이 전부 `admin` 그룹)
이므로, 방문자가 보는 커머스·게시판 UI 는 사실상 이 템플릿의 컴포넌트가 전부입니다.

위 개수는 코드에서 실측되므로 시간이 지나면 달라집니다 — 이 문서에 구체적 개수를 하드코딩하지
않습니다. 정확한 전체 목록·Props 는 코어의 컴포넌트 Props 레퍼런스를 따르며, 이 문서는 "이
템플릿에서만" 유효한 것을 다룹니다.
<!-- @intent END -->

## 이관 원문 상세

> 아래는 코어 `docs/frontend/templates/sirsoft-basic/components.md` 에 있던 원문을 이 문서로
> 옮긴 것입니다(#601). 이관 시점 그대로 보존하되, **코드가 SSoT 인 값과 어긋나는 부분에는
> 정정 주석**을 달았습니다 — 실측 총계는 위 「제공 컴포넌트」 블록이 SSoT 입니다.

### sirsoft-basic 컴포넌트

> **템플릿 식별자**: `sirsoft-basic` (type: user, v0.4.16)
> **관련 문서**: [핸들러](handlers.md) | [레이아웃](layouts.md) | [컴포넌트 Props 레퍼런스](../../../../docs/frontend/component-props.md)

> **정정(#601)**: 위 버전(v0.4.16)은 이관 시점 문서 값입니다. 현재 버전은 `template.json` 이
> SSoT 입니다.

---

### TL;DR (5초 요약)

```text
1. Basic 26개: HTML 래핑 (Div, Button, Input, Select, Form, A, H1~H4, PasswordInput 등)
2. Composite 27개: UI 패턴 캡슐화 (Header, Footer, Modal, ProductCard, Pagination 등)
3. Layout 5개: 페이지 구조 (Container, Grid, Flex, SectionLayout, ThreeColumnLayout)
   (정정(#601): 개수는 이관 시점 값 — 실측은 위 「제공 컴포넌트」 블록이 SSoT)
4. 사용자(User) 템플릿 전용 — 모듈 레이아웃(user/ 하위)에서 사용
5. features: dark_mode, responsive, multi_language, multi_currency 지원
```

---

### 목차

1. [컴포넌트 개요](#컴포넌트-개요)
2. [Basic Components (26개)](#basic-components-26개)
3. [Composite Components (27개)](#composite-components-27개)
4. [Layout Components (5개)](#layout-components-5개)
5. [sirsoft-admin_basic과의 차이](#sirsoft-admin_basic과의-차이)

---

### 컴포넌트 개요

> **정정(#601)**: 아래 개수는 이관 시점 문서 값입니다. 코드 실측은 basic 38 · composite 36 ·
> layout 5 = **79종**이며 위 「제공 컴포넌트」 블록이 SSoT 입니다. 아래 목록에 없는 컴포넌트가
> 있습니다(basic 의 `Code` · `FileInput` · `I` · `Ol` · `Optgroup` · `Section` · `Svg` ·
> `Tbody` · `Td` · `Th` · `Thead` · `Tr`, composite 의 `BrandMark` · `NotificationCenter` ·
> `PageLoading` · `SlotContainer` 등).

| 타입 | 개수 | 설명 |
|------|------|------|
| Basic | 26 | HTML 태그 래핑 — 최소 단위 컴포넌트 |
| Composite | 27 | 기본 컴포넌트 조합 — UI 패턴 캡슐화 |
| Layout | 5 | 페이지 구조 정의 — 컨테이너/그리드/플렉스 |
| **합계** | **58** | |

**소스**: `templates/_bundled/sirsoft-basic/components.json`
**컴포넌트 소스**: `templates/_bundled/sirsoft-basic/src/components/{basic,composite,layout}/*.tsx`

---

### Basic Components (26개)

> **정정(#601)**: 제목의 개수는 이관 시점 문서 값입니다(코드 실측 38종). 목록 자체는 원문 그대로입니다.

HTML 태그를 래핑하는 최소 단위 컴포넌트입니다.

#### 텍스트/링크

| 컴포넌트 | 설명 | 주요 Props | 바인딩 |
|----------|------|-----------|--------|
| `A` | 앵커/링크 | href | - |
| `H1` | 제목 (h1) | - | - |
| `H2` | 제목 (h2) | - | - |
| `H3` | 제목 (h3) | - | - |
| `H4` | 제목 (h4) | - | - |
| `P` | 문단 | - | - |
| `Span` | 인라인 텍스트 | - | - |
| `Label` | 라벨 | - | - |

#### 컨테이너

| 컴포넌트 | 설명 | 주요 Props | 바인딩 |
|----------|------|-----------|--------|
| `Div` | 범용 컨테이너 | - | - |
| `Nav` | 네비게이션 래퍼 | - | - |
| `Form` | 폼 컨테이너 | - | - |
| `Header` | HTML header 래퍼 | - | - |
| `Footer` | HTML footer 래퍼 | - | - |
| `Hr` | 수평선 | - | - |

#### 폼 입력

| 컴포넌트 | 설명 | 주요 Props | 바인딩 |
|----------|------|-----------|--------|
| `Input` | 텍스트 입력 | label, error | checkable |
| `Select` | 선택 박스 | label, error | - |
| `Option` | Select 내부 옵션 | value, disabled | - |
| `Textarea` | 텍스트 영역 | label, error | - |
| `Checkbox` | 체크박스 | label | checked |
| `PasswordInput` | 비밀번호 입력 (보기/숨기기, 조건 검증, 확인 일치) | label, error, showToggle, showValidation, isConfirmField, confirmTarget, showRules, ... | - |
| `Button` | 버튼 | variant, size | - |

#### 미디어

| 컴포넌트 | 설명 | 주요 Props | 바인딩 |
|----------|------|-----------|--------|
| `Icon` | FontAwesome 아이콘 | name | - |
| `Img` | 이미지 | src, alt | - |

#### 기타

| 컴포넌트 | 설명 |
|----------|------|
| `Table` | HTML table wrapper |
| `Ul` | 순서 없는 리스트 |
| `Li` | 리스트 아이템 |

---

### Composite Components (27개)

> **정정(#601)**: 제목의 개수는 이관 시점 문서 값입니다(코드 실측 36종). 목록 자체는 원문 그대로입니다.

기본 컴포넌트를 조합하여 UI 패턴을 캡슐화한 복합 컴포넌트입니다.

#### 사이트 레이아웃

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `Header` | 사이트 헤더 (로고, 네비게이션, 검색, 사용자 메뉴) | siteName |
| `Footer` | 사이트 푸터 (저작권, 링크, 소셜) | siteName |
| `MobileNav` | 모바일 네비게이션 드로어 | - |

#### 쇼핑몰

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `ProductCard` | 상품 카드 (이미지, 제목, 가격) | product, showDiscount |
| `ProductImageViewer` | 상품 이미지 뷰어 (메인 + 썸네일 + 라이트박스) | images |
| `QuantitySelector` | 수량 선택기 (+/- 버튼) | value, min, max |

#### 게시판

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `PostReactions` | 게시글 리액션 버튼 그룹 | postId, reactions |
| `ExpandableContent` | 콘텐츠 펼치기/접기 (높이 초과 시 그라데이션) | maxHeight, expandText, collapseText |

#### 인증

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `SocialLoginButtons` | 소셜 로그인 버튼 그룹 | providers |

#### 사용자

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `Avatar` | 사용자 아바타 (이미지 또는 이름 첫 글자) | name, avatar, size, text |
| `AvatarUploader` | 아바타 이미지 업로드 (원형 UI, 즉시 업로드) | src, fallbackText, size, uploadEndpoint, deleteEndpoint, showDeleteButton, ... |
| `UserInfo` | 사용자 정보 표시 및 드롭다운 | name, userId, subText, isGuest, showDropdown, clickable, ... |

#### 에디터/콘텐츠

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `HtmlContent` | HTML 안전 렌더링 (DOMPurify XSS 방지) | content, text, isHtml, purifyConfig |
| `HtmlEditor` | HTML/텍스트 에디터 (WYSIWYG, 미리보기) | name, value, placeholder, isHtml, showPreview, minHeight |
| `RichTextEditor` | 리치 텍스트 에디터 | value, placeholder |
| `ImageGallery` | 이미지 갤러리 (썸네일 + 메인 이미지) | images |

#### 파일/미디어

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `FileUploader` | 파일 업로드 | accept, maxSize, multiple |

#### 네비게이션/피드백

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `TabNavigation` | 탭 네비게이션 (underline/pills/enclosed) | tabs, activeTabId, variant, onTabChange, hiddenTabIds |
| `Pagination` | 페이지네이션 | currentPage, totalPages, maxVisiblePages, showFirstLast, prevText, nextText |

> **TabNavigation `hiddenTabIds`**: 특정 조건에서 탭을 동적으로 숨길 때 사용합니다. 탭 아이템의 `id` 배열을 전달하면 해당 탭이 렌더링에서 제외됩니다.
> ```json
> {
>   "name": "TabNavigation",
>   "props": {
>     "tabs": [...],
>     "hiddenTabIds": "{{_global.modules?.['sirsoft-ecommerce']?.inquiry?.board_slug ? [] : ['qna']}}"
>   }
> }
> ```
| `SearchBar` | 검색 바 (자동완성 지원) | name, placeholder, value, showButton, suggestions, showSuggestions |
| `Modal` | 모달 다이얼로그 (ESC, 포커스 트랩) | isOpen, onClose, title, width |
| `ConfirmDialog` | 확인/취소 다이얼로그 | isOpen, onClose, title, message, confirmText, cancelText, ... |
| `Toast` | 토스트 알림 | toasts, position, duration |
| `ThemeToggle` | 테마 전환 토글 (auto/light/dark) | autoText, lightText, darkText |

#### 페이지 전환

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `PageTransitionIndicator` | 페이지 전환 로딩 인디케이터 | - |
| `PageTransitionBlur` | 페이지 전환 시 콘텐츠 블러 오버레이 | - |
| `PageSkeleton` | 동적 스켈레톤 UI 렌더러 | components, options |

---

### Layout Components (5개)

페이지 구조를 정의하는 레이아웃 컴포넌트입니다.

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `Container` | 레이아웃 컨테이너 | maxWidth |
| `Flex` | Flexbox 레이아웃 | direction, gap |
| `Grid` | Grid 레이아웃 | cols, gap |
| `SectionLayout` | 섹션 레이아웃 (제목, 부제목, 패딩) | title, subtitle, padding, background |
| `ThreeColumnLayout` | 3단 레이아웃 (좌/중/우) | leftWidth, centerWidth, rightWidth, gap |

---

### sirsoft-admin_basic과의 차이

#### 공통 컴포넌트

두 템플릿 모두에 존재하는 컴포넌트:

| 타입 | 공통 컴포넌트 |
|------|-------------|
| Basic | A, Button, Checkbox, Div, Form, H1~H4, Icon, Img, Input, Label, Li, Nav, P, Select, Span, Table, Textarea, Ul |
| Composite | ConfirmDialog, FileUploader, HtmlContent, HtmlEditor, ImageGallery, Modal, Pagination, ProductCard, SearchBar, TabNavigation, ThemeToggle, Toast |
| Layout | Container, Flex, Grid, SectionLayout, ThreeColumnLayout |

#### sirsoft-basic 전용 컴포넌트

| 타입 | 전용 컴포넌트 | 설명 |
|------|-------------|------|
| Basic | `PasswordInput` | 비밀번호 입력 (보기/숨기기, 검증) |
| Basic | `Header`, `Footer`, `Hr` | HTML5 시맨틱 태그 |
| Composite | `Header`, `Footer` | 사이트 헤더/푸터 (composite) |
| Composite | `MobileNav` | 모바일 드로어 네비게이션 |
| Composite | `ProductImageViewer` | 상품 이미지 뷰어 |
| Composite | `QuantitySelector` | 수량 선택기 |
| Composite | `PostReactions` | 게시글 리액션 |
| Composite | `SocialLoginButtons` | 소셜 로그인 |
| Composite | `Avatar`, `AvatarUploader` | 아바타 관련 |
| Composite | `UserInfo` | 사용자 정보 드롭다운 |
| Composite | `RichTextEditor` | 리치 텍스트 에디터 |
| Composite | `ExpandableContent` | 콘텐츠 접기/펼치기 |
| Composite | `PageTransitionBlur` | 전환 블러 효과 |
| Composite | `PageSkeleton` | 스켈레톤 UI |

#### sirsoft-admin_basic 전용 컴포넌트 (이 템플릿에 없음)

AdminSidebar, AdminHeader, AdminFooter, PageHeader, DataGrid, CodeEditor, DynamicFieldList, MultilingualInput, TagInput, Toggle, RadioGroup, FormField, SlotContainer, FilterGroup 등 관리자 전용 컴포넌트 39개+

> **정정(#601)**: `SlotContainer` 는 이 템플릿에도 있습니다(`src/components/composite/SlotContainer.tsx`).
> 나머지 항목은 이관 시점 그대로입니다.

---

### 관련 문서

- [sirsoft-basic 핸들러](handlers.md)
- [sirsoft-basic 레이아웃](layouts.md)
- [sirsoft-admin_basic 컴포넌트](../../sirsoft-admin_basic/docs/components.md)
- [컴포넌트 개발 규칙](../../../../docs/frontend/components.md)
- [컴포넌트 Props 레퍼런스](../../../../docs/frontend/component-props.md)
