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

## 상태 의미 토큰 (`status-*`)

`Icon` 은 Font Awesome 글리프 이름 외에 **아이콘 라이브러리 중립 토큰**을 받는다. 서버(예:
쇼핑몰 모듈의 클레임·주문 상태 Enum)는 특정 아이콘 글꼴을 알지 않고 `status-request` · `status-pickup`
같은 의미 토큰만 내보내며, 그 토큰을 이 템플릿의 글리프로 바꾸는 표는
`templates/_bundled/sirsoft-basic/src/components/basic/IconTypes.ts` 의 `iconNameMap` 한 곳이다. 어휘(21종 — 클레임 15 + 주문 전용 6)와 각 토큰의 뜻은
쇼핑몰 모듈 API 문서(`modules/_bundled/sirsoft-ecommerce/docs/api/admin-claims.md` "아이콘 의미 토큰 어휘")가 소유한다.

표에 없는 토큰은 `fa-status-…` 로 떨어져 오류 없이 빈 네모가 그려진다 — 어휘가 늘면 이 표에
먼저 더한다. 다른 아이콘 세트를 쓰는 템플릿은 같은 토큰을 자기 글리프로 해석하면 된다.

<!-- @intent START -->
세 분류는 "얼마나 조합됐는가" 로 나뉩니다 — `basic` 은 HTML 태그를 그대로 래핑한 최소 단위
(`Div`→`<div>`), `composite` 는 basic 을 조합해 UI 패턴을 캡슐화한 것(`Header` 가 로고·네비·
검색·사용자 메뉴를 감싸는 식), `layout` 은 페이지 구조(Container/Grid/Flex)입니다. 새 컴포넌트는
이 구분에 맞는 디렉토리(`src/components/{basic,composite,layout}/`)에 넣어야 `components.json`
카탈로그와 `editor-spec.json` 팔레트 분류가 어긋나지 않습니다.

**이 템플릿은 방문자 화면 전용**이라 관리자 템플릿(`sirsoft-admin_basic`)과 컴포넌트 구성이
크게 다릅니다. `DataGrid` · `AdminSidebar` · `MultilingualInput` 같은 관리 도구가 없고, 대신
`ProductCard` · `ProductImageViewer` · `QuantitySelector` · `MobileNav` ·
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
| `Select` | 선택 박스. `searchable` 이면 펼침 목록 위에 검색칸이 생기고, 검색칸 안내·화면 읽기 이름·「결과 없음」 문구는 `searchPlaceholder` 가 없으면 화면 언어(`common.search_placeholder`·`common.search`·`common.no_results`)를 따른다 | label, error, options, searchable, searchPlaceholder | - |
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

#### PasswordInput 검증 규칙 (#736)

`enableRules` 는 「규칙:값」 목록입니다(`minLength:N`·`maxLength:N`·`hasUppercase`·`hasLowercase`·`hasNumber`·`hasSpecial`·`noSpaces`·`minTypes:N`).
`hasSpecial` 은 서버(`PasswordPolicy`·`PasswordComposition`)와 같은 유니코드 구두점·기호·공백 범주로 판정하고, `minTypes` 는 **영문(대·소문자 한 종류)·숫자·특수문자**
세 종류 중 N종 이상 섞였는지 봅니다(기본 2 — 서버 「문자 종류 조합」과 같다). 종전에는 특수문자 집합이 좁고 `minTypes` 가 대·소문자를 따로 세어 화면 체크와 서버 판정이
갈렸습니다. 비밀번호 칸에서는 규칙을 리터럴로 두지 말고 `_global.settings.security.*` 로 만든 식에 바인딩합니다(→ [layouts.md](layouts.md) 「비밀번호 규칙 바인딩」).
재사용·아이디 포함·반복·연속처럼 서버만 판정하는 규칙은 이 컴포넌트가 확인하지 않습니다.

### Composite Components (27개)

> **정정(#601)**: 제목의 개수는 이관 시점 문서 값입니다(코드 실측 36종). 목록 자체는 원문 그대로입니다.

기본 컴포넌트를 조합하여 UI 패턴을 캡슐화한 복합 컴포넌트입니다.

#### 사이트 레이아웃

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `Header` | 사이트 헤더 (로고, 네비게이션, 검색, 사용자 메뉴) | siteName |
| `Footer` | 사이트 푸터 (저작권, 링크, 소셜) | siteName |
| `MobileNav` | 모바일 네비게이션 드로어 | - |

- **`Header` 언어 메뉴와 장바구니 단추**: 언어 단추는 `aria-haspopup="listbox"` 와 펼쳤을 때 `aria-controls` 로 목록을 가리키고, 목록은 `role="listbox"`(이름 = 언어), 항목은 `role="option"` + `aria-selected` 다. 단추에서 ↓/↑·Enter·Space 로 열면 현재 언어 항목에 초점이 가고, 목록 안에서 ↓/↑·Home·End 로 옮기며 Enter 로 고른다. Esc 는 닫고 단추로 초점을 돌리고, 고른 뒤에도 초점은 단추로 돌아온다. 아이콘만 있는 장바구니 단추의 이름은 `nav.cart_with_count`(담긴 수 포함), 좁은 폭에서 그리는 메뉴 단추의 이름은 `user.menu` 다. 회귀 테스트: `src/components/composite/__tests__/HeaderLanguageListbox.test.tsx`.
- **모바일 머리글(`_user_base` 레이아웃)**: 아이콘만 있는 단추는 다국어 `aria-label` 을 갖는다 — 장바구니 `nav.cart_with_count`(담긴 수), 사용자 `common.mypage`/`common.login`(로그인 여부는 `_global.currentUser?.uuid` 로 가린다 — 비회원의 `currentUser` 는 빈 값이어도 참으로 읽힌다), 메뉴 `user.menu` + `aria-expanded`(드로어 열림), 드로어 닫기 `nav.close_menu`. 드로어 언어 칩(`role="option"`)을 감싼 목록은 `role="listbox"`(이름 `common.language`)다. 회귀 테스트: `__tests__/layouts/user-base-mobile-header-a11y.test.ts`.

#### 쇼핑몰

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `ProductCard` | 상품 카드 (이미지, 제목, 가격) | product, showDiscount |
| `ProductImageViewer` | 상품 이미지 뷰어 (메인 + 썸네일 + 라이트박스) | images |
| `QuantitySelector` | 수량 선택기 (+/- 버튼). 범위 밖 직접 입력은 min~max 로 고쳐 올리고, 칸에는 늘 고친 값을 보인다(고친 값이 지금 값과 같아도) | value, min, max |

#### 게시판

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `ExpandableContent` | 콘텐츠 펼치기/접기 (높이 초과 시 그라데이션) | maxHeight, expandText, collapseText |

#### 인증

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `SocialLoginButtons` | 소셜 로그인 버튼 그룹 | providers |

#### 사용자

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `Avatar` | 사용자 아바타 (이미지 또는 이름 첫 글자) | name, avatar, size, text |
| `VisitorCounter` | 방문자 카운터 (오늘·어제·최대·전체, 순수 표시) | today, yesterday, max, total, layout(`row`·`column`), labels{title,today,yesterday,max,total}, className, id, editorAttrs |
| `AvatarUploader` | 아바타 이미지 업로드 (원형 UI, 즉시 업로드. 업로드·삭제 주소가 동일 출처일 때만 토큰을 싣는다. 성공 후 액션(`uploadSuccessActions`·`deleteSuccessActions`)이 없으면 코어 `G7Core.dataSource.refetch('user')` 로 회원 정보를 다시 읽는다) | src, fallbackText, size, uploadEndpoint, deleteEndpoint, showDeleteButton, ... |
| `UserInfo` | 사용자 정보 표시 및 드롭다운 | name, userId, subText, subTextTitle, isGuest, isWithdrawn, showDropdown, clickable, profilePath, text, author, layout, stopPropagation, menuItems, hideMenuItems, appendMenuItems, className |

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

- **키보드 초점 자리**: 항목의 「삭제」로 항목이 사라지면 초점은 다음 항목(없으면 이전 항목, 둘 다 없으면 「추가」 타일, 목록이 비면 드롭존)의 같은 단추로 간다. 빈 드롭존이나 「추가」 타일로 파일을 고른 뒤 그 요소가 사라지면 「추가」 타일(한도가 차서 없으면 새로 추가된 첫 항목)로 간다. 삭제 확인 창을 거친 삭제도 같다. 초점이 업로더 밖으로 나간 뒤에는 끌어오지 않는다. 자리 판정은 항목 루트의 `data-uploader-item` 과 단추의 `data-uploader-action`(`pick`·`add`·`primary`·`drag`·`download`·`retry`·`remove`)으로 하므로 이 표식을 지우지 않는다. 회귀 테스트: `src/components/composite/__tests__/FileUploaderFocusRecovery.test.tsx`.
- **언어 전환 인계**: 화면 언어를 바꾸면 엔진이 같은 화면을 새로 마운트한다. 업로더는 언어 전환 중에 내려갈 때만 아직 올리지 않은 대기 파일과 끌어 놓은 순서를 `G7Core.locale.handoff` 에 맡기고(키: 경로 + collection + 업로드 시점), 새 인스턴스가 마운트 때 한 번 꺼내 이어 쓴다. 올리던 항목은 대기 상태로 되돌린다. 일반 화면 이동에서는 맡기지 않는다(engine-v1.79.0). 회귀 테스트: `src/components/composite/__tests__/FileUploaderLocaleHandoff.test.tsx`.
- **생략한 배열 속성의 기본값**: `initialFiles`·`roleIds` 를 생략하면 모듈 상수인 얼린 빈 배열을 쓴다(렌더마다 새 배열을 기본값으로 만들면 `initialFiles` 동기화 effect 가 끝없이 돈다). 훅은 같은 ID 목록이 새 배열로 들어와도 이전 참조를 쓴다. 회귀 테스트: `src/components/composite/__tests__/FileUploaderDefaultInitialFiles.test.tsx`.

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
| `Modal` | 모달 다이얼로그 (ESC, 포커스 트랩) | isOpen, onClose, title, icon, iconClassName, size, width, className, style |
| `ConfirmDialog` | 확인/취소 다이얼로그 | isOpen, onClose, title, message, confirmText, cancelText, ... |
| `Toast` | 토스트 알림 | toasts, position, duration |
| `ThemeToggle` | 테마 전환 토글 (auto/light/dark) | autoText, lightText, darkText |

- `Modal` 의 Esc 는 **창 안에 펼친 팝업이 있으면 팝업만 닫는다**(관리자 템플릿과 같은 규칙). 펼친 팝업은 `aria-haspopup` 과 `aria-expanded="true"` 를 함께 단 요소로 판정하므로, 창 안 드롭다운·메뉴는 이 두 속성을 트리거에 달고 자기 Esc 처리로 닫아야 한다. 키를 누른 순간(캡처 단계)의 펼침 여부로 판정하고, `aria-haspopup` 없는 펼침과 창 밖 팝업은 창 닫기를 막지 않는다. 회귀 테스트: `src/components/composite/__tests__/Modal.popupEscape.test.tsx`.
- **아이콘 단추와 상태 영역의 접근 이름**: `ThemeToggle` 단추는 `common.theme_select`(펼침 여부는 `aria-expanded`), `NotificationCenter` 항목 삭제 단추는 `common.delete_notification`, `PageSkeleton` 상태 영역은 `common.loading` 을 이름으로 단다. 번역이 없거나 엔진 밖(단위 테스트)이면 대체 문구를 쓴다. 이 이름을 영문 문자열로 되돌려 박지 않는다(한국어, 일본어 화면에서 영어로 읽힌다). 회귀 테스트: `src/components/composite/__tests__/AccessibleNameI18n.test.tsx`.
- **꾸밈 아이콘과 아이콘만 있는 단추**: `Icon` 은 `ariaLabel` 을 받으면 `role="img"` 와 그 이름으로 읽히고, 받지 않으면 꾸밈이라 `aria-hidden="true"` 로 숨는다(글리프 이름을 이름으로 만들지 않는다). 아이콘만 담은 단추와 링크는 단추 자신에 이름(`aria-label`)을 문구 키로 단다. 레이아웃 예: 삭제 `common.delete`, 뒤로가기 `common.back`, 더보기 `common.more`, 이전과 다음 `common.prev`/`common.next`, 펼치기와 접기 `common.expand`/`common.collapse`(펼침 여부는 `aria-expanded`), 찜 `shop.product.add_to_wishlist`/`remove_from_wishlist`, 리뷰 별점 `mypage.order_detail.review_modal.rating_star`(`{{count}}`). 기본 문구도 키를 따른다: `FileInput`(`common.file_input.*`), `ThemeToggle` 메뉴(`common.theme.auto`/`light`/`dark`), `Select` 검색칸(`common.search_placeholder`, `common.search`, `common.no_results`), `AvatarUploader` 미리보기 대체 글 `mypage.profile.avatar_preview`. 번역이 없거나 엔진 밖이면 종전 영문을 쓴다. 회귀 테스트: `src/components/basic/__tests__/Icon.a11y.test.tsx`, `src/components/composite/__tests__/IconOnlyControlNames.test.tsx`(번들 레이아웃 정적 대조 포함).

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
| Composite | `SocialLoginButtons` | 소셜 로그인 |
| Composite | `Avatar`, `AvatarUploader` | 아바타 관련 |
| Composite | `VisitorCounter` | 방문자 카운터(홈 하단) |
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
