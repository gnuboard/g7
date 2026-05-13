# sirsoft-basic 레이아웃

> **템플릿 식별자**: `sirsoft-basic` (type: user)
> **관련 문서**: [컴포넌트](./components.md) | [핸들러](./handlers.md) | [레이아웃 JSON 스키마](../../layout-json.md)

---

## TL;DR (5초 요약)

```text
1. 베이스: _user_base.json (헤더 + 푸터 + 모바일 네비 + 콘텐츠 슬롯)
2. 코어 페이지 30개+: 인증, 게시판, 쇼핑몰, 마이페이지, 검색, 프로필
3. Partial 100개+: 폼, 모달, 탭, 카드, 목록, 필터, 타입별 렌더러
4. 에러 페이지 6개: 401, 403, 404, 500, 503, maintenance
5. 패턴: 게시판(타입별 렌더러), 쇼핑몰(장바구니/결제 플로우), 마이페이지(탭 네비게이션)
```

---

## 목차

1. [페이지 맵 (트리 구조)](#페이지-맵-트리-구조)
2. [카테고리별 가이드](#카테고리별-가이드)
   - [인증 페이지 패턴](#인증-페이지-패턴)
   - [게시판 패턴](#게시판-패턴)
   - [쇼핑몰 패턴](#쇼핑몰-패턴)
   - [마이페이지 패턴](#마이페이지-패턴)
   - [검색 패턴](#검색-패턴)
   - [기타 페이지 패턴](#기타-페이지-패턴)
   - [에러 페이지 패턴](#에러-페이지-패턴)

---

## 페이지 맵 (트리 구조)

```text
_user_base.json (베이스 레이아웃)
│
├── home.json (홈/대시보드)
│   └── partials/home/
│       ├── _welcome_card.json (환영 카드)
│       ├── _stat_card_users.json (통계: 사용자)
│       ├── _stat_card_posts.json (통계: 게시글)
│       ├── _stat_card_comments.json (통계: 댓글)
│       ├── _stat_card_boards.json (통계: 게시판)
│       ├── _recent_posts.json (최근 게시글)
│       ├── _popular_boards.json (인기 게시판)
│       ├── _board_summary.json (게시판 요약)
│       ├── _community_guide.json (커뮤니티 가이드)
│       └── _shop_promo.json (쇼핑 프로모션)
│
├── 인증 (auth/)
│   ├── login.json (로그인)
│   ├── register.json (회원가입)
│   ├── forgot_password.json (비밀번호 찾기)
│   ├── reset_password.json (비밀번호 재설정)
│   └── partials/auth/
│       ├── _login_form.json (로그인 폼)
│       ├── _register_form.json (회원가입 폼)
│       ├── _redirect_if_logged_in.json (로그인 상태 리다이렉트)
│       ├── _modal_terms.json (이용약관 모달)
│       └── _modal_privacy.json (개인정보 처리방침 모달)
│
├── 게시판 (board/)
│   ├── boards.json (게시판 목록)
│   │   └── partials/board/boards/
│   │       └── _board_card.json (게시판 카드)
│   ├── index.json (게시글 목록)
│   │   └── partials/board/index/
│   │       ├── _type_renderer.json (타입별 렌더러 분기)
│   │       ├── _empty_states.json (빈 상태)
│   │       ├── _loading_error.json (로딩/에러)
│   │       └── _write_button.json (글쓰기 버튼)
│   ├── show.json (게시글 상세)
│   │   └── partials/board/show/
│   │       ├── _type_renderer.json (타입별 렌더러 분기)
│   │       ├── _comment_section.json (댓글 섹션)
│   │       ├── _comment_item.json (댓글 아이템)
│   │       ├── _comment_input.json (댓글 입력)
│   │       ├── _reply_section.json (답글 섹션)
│   │       ├── _navigation.json (이전글/다음글)
│   │       ├── _post_attachments.json (첨부파일)
│   │       └── modals/
│   │           ├── _modal_delete.json (삭제 확인)
│   │           ├── _modal_report.json (신고)
│   │           └── _password_verify_modal.json (비밀번호 확인)
│   ├── form.json (게시글 작성/수정)
│   │   └── partials/board/form/
│   │       ├── _post_form.json (게시글 폼)
│   │       ├── _parent_post.json (원글 표시)
│   │       ├── _type_renderer.json (타입별 렌더러 분기)
│   │       └── _password_verify_modal.json (비밀번호 확인)
│   ├── popular.json (인기글)
│   │   └── partials/board/popular/
│   │       ├── _popular_list.json (인기글 리스트)
│   │       └── _popular_item.json (인기글 아이템)
│   └── partials/board/types/ (게시판 타입별 부분 레이아웃)
│       ├── basic/
│       │   ├── index.json (기본 목록)
│       │   ├── show.json (기본 상세)
│       │   └── form.json (기본 폼)
│       ├── card/
│       │   └── index.json (카드형 목록)
│       └── gallery/
│           └── index.json (갤러리형 목록)
│
├── 쇼핑몰 (shop/)
│   ├── index.json (상품 목록/메인)
│   │   └── partials/shop/list/
│   │       ├── _product_grid.json (상품 그리드)
│   │       ├── _popular_products.json (인기 상품)
│   │       ├── _new_products.json (신상품)
│   │       ├── _recent_products.json (최근 본 상품)
│   │       ├── _category_filter.json (카테고리 필터)
│   │       ├── _category_breadcrumb.json (카테고리 경로)
│   │       └── _search_filter_bar.json (검색/필터 바)
│   ├── category.json (카테고리 상품)
│   ├── show.json (상품 상세)
│   │   └── partials/shop/detail/
│   │       ├── _header.json (상품 헤더)
│   │       ├── _info_summary.json (상품 정보 요약)
│   │       ├── _purchase_card.json (구매 카드)
│   │       ├── _price_mobile.json (모바일 가격)
│   │       ├── _tab_detail.json (상세 탭)
│   │       ├── _tab_reviews.json (리뷰 탭)
│   │       ├── _tab_qna.json (Q&A 탭)
│   │       ├── _review_avatar.json (리뷰 아바타)
│   │       ├── _modal_cart_added.json (장바구니 추가 확인)
│   │       ├── _modal_coupon_download_confirm.json (쿠폰 다운로드 확인)
│   │       ├── _modal_login_required.json (로그인 필요)
│   │       └── _modal_review_image.json (리뷰 이미지 확대)
│   ├── cart.json (장바구니)
│   │   └── partials/shop/
│   │       ├── _cart_list.json (장바구니 목록)
│   │       ├── _cart_item.json (장바구니 아이템)
│   │       ├── _cart_summary.json (장바구니 요약)
│   │       ├── _product_purchase_card.json (구매 카드)
│   │       ├── _modal_cart_delete_confirm.json (삭제 확인)
│   │       ├── _modal_cart_option_change.json (옵션 변경)
│   │       ├── _modal_cart_unavailable.json (품절 안내)
│   │       └── _modal_temp_order_not_found.json (임시주문 없음)
│   ├── checkout.json (결제)
│   │   └── partials/shop/
│   │       ├── _checkout_orderer.json (주문자 정보)
│   │       ├── _checkout_shipping.json (배송 정보)
│   │       ├── _checkout_items.json (주문 상품)
│   │       ├── _checkout_discount.json (할인/쿠폰)
│   │       ├── _checkout_mileage.json (마일리지)
│   │       ├── _checkout_payment.json (결제 수단)
│   │       ├── _checkout_summary.json (주문 요약)
│   │       ├── _modal_address_manage.json (배송지 관리)
│   │       ├── _modal_coupon_download.json (쿠폰 다운로드)
│   │       └── _modal_exclusive_coupon_confirm.json (전용 쿠폰 확인)
│   └── order_complete.json (주문 완료)
│
├── 마이페이지 (mypage/)
│   ├── profile.json (프로필 보기)
│   ├── profile-edit.json (프로필 수정)
│   │   └── partials/mypage/profile/
│   │       ├── _view.json (프로필 보기 섹션)
│   │       ├── _edit.json (프로필 수정 섹션)
│   │       ├── _password_verify_section.json (비밀번호 확인)
│   │       └── _modal_withdraw.json (회원 탈퇴)
│   ├── orders.json (주문 내역)
│   │   └── partials/mypage/orders/
│   │       └── _list.json (주문 목록)
│   ├── orders/show.json (주문 상세)
│   │   └── partials/mypage/orders/
│   │       ├── _status_header.json (주문 상태 헤더)
│   │       ├── _items.json (주문 상품)
│   │       ├── _shipping.json (배송 정보)
│   │       ├── _payment.json (결제 정보)
│   │       ├── _history.json (주문 이력)
│   │       ├── _modal_cancel.json (주문 취소)
│   │       └── _modal_change_address.json (배송지 변경)
│   ├── board.json (내 게시글/댓글)
│   │   └── partials/mypage/board/
│   │       ├── _list.json (탭 목록)
│   │       ├── _my_posts.json (내 게시글)
│   │       └── _my_comments.json (내 댓글)
│   ├── addresses.json (배송지 관리)
│   │   └── partials/mypage/addresses/
│   │       ├── _list.json (배송지 목록)
│   │       ├── _modal_address.json (배송지 추가/수정)
│   │       ├── _modal_confirm_delete.json (삭제 확인)
│   │       └── _modal_confirm_overwrite.json (덮어쓰기 확인)
│   ├── notifications.json (알림)
│   │   └── partials/mypage/notifications/
│   │       └── _list.json (알림 목록)
│   ├── change-password.json (비밀번호 변경)
│   ├── wishlist.json (위시리스트)
│   │   └── partials/mypage/wishlist/
│   │       └── _list.json (위시리스트 목록)
│   ├── inquiries.json (상품 1:1 문의내역)
│   │   └── partials/mypage/inquiries/
│   │       └── _list.json (문의 목록)
│   └── partials/mypage/
│       └── _tab_navigation.json (마이페이지 공통 탭)
│           hiddenTabIds: inquiries 탭은 sirsoft-ecommerce inquiry 설정 시에만 노출
│
├── 검색 (search/)
│   └── index.json (통합 검색)
│       └── partials/search/
│           ├── _search_input.json (검색 입력)
│           ├── _search_tabs.json (검색 탭)
│           ├── _search_filters.json (검색 필터)
│           ├── _search_results.json (검색 결과)
│           ├── _search_states.json (검색 상태)
│           ├── posts/
│           │   ├── _section.json (게시글 결과 섹션)
│           │   └── _list.json (게시글 결과 목록)
│           ├── products/
│           │   ├── _section.json (상품 결과 섹션)
│           │   ├── _item_card.json (상품 카드)
│           │   └── _item_list.json (상품 리스트)
│           └── pages/
│               └── _section.json (페이지 결과 섹션)
│
├── 사용자 (users/)
│   ├── show.json (사용자 프로필)
│   └── posts.json (사용자 게시글)
│
├── 페이지 (page/)
│   └── show.json (정적 페이지)
│
├── 공통 Partial
│   └── partials/common/
│       └── _currency_selector.json (통화 선택기)
│
└── 에러 페이지 (errors/)
    ├── 401.json (인증 필요)
    ├── 403.json (접근 거부)
    ├── 404.json (페이지 없음)
    ├── 500.json (서버 오류)
    ├── 503.json (서비스 불가)
    └── maintenance.json (점검 중)
```

---

## 카테고리별 가이드

### 인증 페이지 패턴

**대표**: `auth/login.json`, `auth/register.json`

**구성**:
```text
extends: _user_base
slots.content:
  └── Flex (중앙 정렬)
      ├── _redirect_if_logged_in.json (로그인 시 리다이렉트)
      └── Div (카드 스타일 래퍼)
          ├── H2 (제목)
          ├── partial: _login_form.json 또는 _register_form.json
          └── 하단 링크 (회원가입/로그인 전환, 비밀번호 찾기)
```

**특수사항**:
- `_redirect_if_logged_in.json`: 이미 로그인한 사용자를 홈으로 리다이렉트하는 partial
- 로그인 폼은 `SocialLoginButtons` 포함 (소셜 로그인 지원)
- 회원가입 폼은 `PasswordInput` 사용 (비밀번호 규칙 검증, 확인 일치)
- 약관 동의 모달: `_modal_terms.json`, `_modal_privacy.json`
- `blur_until_loaded`: 현재 사용자 정보 로드 전까지 폼 블러 처리

**핸들러 패턴**:
- `apiCall` — 로그인 (POST `/api/auth/login`), 회원가입 (POST `/api/auth/register`)
- `navigate` — 성공 후 홈/대시보드로 이동
- `setState` — 폼 에러, 로딩 상태

---

### 게시판 패턴

**대표**: `board/index.json`, `board/show.json`, `board/form.json`

**구성**:
```text
extends: _user_base
data_sources: [posts (게시글 목록/상세)]
slots.content:
  └── Container
      ├── 타입 렌더러 (_type_renderer.json)
      │   → 게시판 타입에 따라 분기:
      │   ├── basic/index.json (기본 테이블형)
      │   ├── card/index.json (카드형)
      │   └── gallery/index.json (갤러리형)
      ├── Pagination
      └── 글쓰기 버튼 (_write_button.json)
```

**data_sources**:
```json
{
  "id": "posts",
  "endpoint": "/api/modules/sirsoft-board/boards/{{route.slug}}/posts",
  "method": "GET",
  "params": {
    "page": "{{query.page ?? 1}}",
    "search": "{{query.search ?? ''}}",
    "category": "{{query.category ?? ''}}"
  },
  "auto_fetch": true,
  "refetchOnMount": true,
  "errorHandling": {
    "403": { "handler": "showErrorPage", "params": { "target": "content" } }
  }
}
```

**타입별 렌더러 시스템**:
게시판은 `board_type` 필드에 따라 다른 레이아웃을 렌더링합니다:
- `basic` — 테이블형 게시글 목록 (기본)
- `card` — 카드 그리드형 목록
- `gallery` — 이미지 갤러리형 목록

```text
_type_renderer.json (조건 분기):
  ├── if: board_type === 'basic' → partial: types/basic/index.json
  ├── if: board_type === 'card' → partial: types/card/index.json
  └── if: board_type === 'gallery' → partial: types/gallery/index.json
```

**게시글 상세 구성**:
```text
show.json:
  └── _type_renderer.json → types/basic/show.json
      ├── 게시글 본문 (HtmlContent)
      ├── _post_attachments.json (첨부파일)
      ├── PostReactions (리액션)
      ├── _comment_section.json
      │   ├── _comment_input.json (댓글 입력)
      │   └── _comment_item.json (댓글 아이템, iteration)
      │       └── _reply_section.json (답글)
      ├── _navigation.json (이전/다음 글)
      └── modals: 삭제, 신고, 비밀번호 확인
```

**게시글 작성/수정 구성**:
```text
form.json:
  ├── _parent_post.json (답글 시 원글 표시)
  └── _post_form.json
      ├── Input (제목)
      ├── Select (카테고리)
      ├── HtmlEditor (본문)
      ├── FileUploader (첨부파일)
      ├── Checkbox (공지, 비밀글 등)
      └── Button (저장/취소)
```

**핸들러 패턴**:
- `apiCall` — CRUD, 댓글 작성/삭제, 리액션, 신고
- `navigate` — 게시판 이동, 글 상세/목록 이동
- `setState` — 검색, 필터, 페이지네이션, 댓글 접기/펼치기
- `replaceUrl` — 페이지네이션 시 URL만 변경

---

### 쇼핑몰 패턴

**대표**: `shop/index.json`, `shop/show.json`, `shop/cart.json`, `shop/checkout.json`

#### 상품 목록 (shop/index.json)

```text
extends: _user_base
data_sources: [products (상품 API), categories (카테고리)]
slots.content:
  └── Container
      ├── _category_breadcrumb.json (경로)
      ├── _search_filter_bar.json (검색/정렬)
      ├── _category_filter.json (카테고리 사이드)
      ├── _product_grid.json (상품 그리드, ProductCard iteration)
      ├── _popular_products.json (인기 상품)
      ├── _new_products.json (신상품)
      ├── _recent_products.json (최근 본 상품)
      └── Pagination
```

#### 상품 상세 (shop/show.json)

```text
extends: _user_base
init_actions: [최근 본 상품 localStorage 저장]
data_sources: [product, reviews, popularProducts, relatedProducts]
computed: [displayPrice, displayListPrice, ...]
slots.content:
  └── ThreeColumnLayout
      ├── left: _header.json (이미지 뷰어 — ProductImageViewer)
      ├── center: _info_summary.json (상품 정보, 옵션 선택)
      └── right: _purchase_card.json (가격, 수량, 구매 버튼)
  └── _price_mobile.json (모바일 가격 — responsive portable)
  └── TabNavigation
      ├── _tab_detail.json (상세 정보 — HtmlContent)
      ├── _tab_reviews.json (리뷰 — iteration)
      └── _tab_qna.json (Q&A — iteration)
  └── modals: 장바구니 추가, 쿠폰 다운로드, 로그인 필요, 리뷰 이미지
```

**SEO 특수사항**:
- `meta.seo.structured_data`: Product, AggregateRating 스키마
- `meta.seo.og`: 상품 이미지, 가격 정보 포함

**핸들러 패턴**:
- `sirsoft-basic.addSelectedItemIfComplete` — 옵션 선택 완료 시 자동 추가
- `sirsoft-basic.updateSelectedItemQuantity` — 수량 변경
- `sirsoft-basic.getDisplayPrice` — 다중 통화 가격 표시
- `apiCall` — 장바구니 추가, 리뷰 작성, 위시리스트 토글
- `saveToLocalStorage` — 최근 본 상품 저장

#### 장바구니 (shop/cart.json)

```text
extends: _user_base
state: { selectedItems, allSelected, isCalculating, optionModal, deleteModal, ... }
data_sources: [cartItems (POST /cart/query)]
slots.content:
  └── Flex
      ├── _cart_list.json (장바구니 목록)
      │   └── _cart_item.json (아이템, iteration)
      │       ├── Checkbox (선택)
      │       ├── 상품 정보/가격
      │       ├── QuantitySelector
      │       └── 삭제/옵션변경 버튼
      └── _cart_summary.json (주문 요약, 결제 버튼)
  └── modals: 삭제 확인, 옵션 변경, 품절 안내
```

**특수사항**:
- `auth_mode: "optional"`: 비회원도 장바구니 사용 가능 (X-Cart-Key 헤더)
- `state` 섹션으로 모달/선택 상태 관리
- 장바구니 핸들러: `toggleCartItemSelection`, `selectAllCartItems`, `recalculateCart`

#### 결제 (shop/checkout.json)

```text
extends: _user_base
data_sources: [tempOrder (임시 주문)]
slots.content:
  └── Container
      ├── _checkout_orderer.json (주문자 정보)
      ├── _checkout_shipping.json (배송 정보)
      ├── _checkout_items.json (주문 상품 목록)
      ├── _checkout_discount.json (할인/쿠폰)
      ├── _checkout_mileage.json (마일리지)
      ├── _checkout_payment.json (결제 수단 선택)
      └── _checkout_summary.json (최종 요약 + 결제 버튼)
  └── modals: 배송지 관리, 쿠폰 다운로드, 전용 쿠폰 확인
```

**핸들러 패턴**:
- `apiCall` — 주문 생성, 배송지 조회/저장, 쿠폰 적용
- `setState` — 배송지 선택, 결제 수단, 쿠폰/마일리지 계산

---

### 마이페이지 패턴

**대표**: `mypage/profile.json`, `mypage/orders.json`

**구성**:
```text
extends: _user_base
computed: { currentTab: "profile" }
data_sources: [user (인증 사용자 정보)]
transition_overlay: { target: "mypage_tab_content" }
slots.content:
  └── Div
      ├── _tab_navigation.json (공통 마이페이지 탭)
      │   → 프로필, 주문내역, 게시글, 배송지, 알림, 위시리스트
      └── 탭별 콘텐츠 영역
```

**탭 구조**:
각 마이페이지 하위 페이지는 동일한 `_tab_navigation.json`을 공유하되, `computed.currentTab`으로 활성 탭을 구분합니다.

| 탭 | 레이아웃 | Partial |
|---|---------|---------|
| 프로필 | `profile.json` | `_view.json` |
| 프로필 수정 | `profile-edit.json` | `_edit.json`, `_password_verify_section.json`, `_modal_withdraw.json` |
| 주문 내역 | `orders.json` | `_list.json` |
| 주문 상세 | `orders/show.json` | `_status_header.json`, `_items.json`, `_shipping.json`, `_payment.json`, `_history.json` |
| 내 게시글 | `board.json` | `_my_posts.json`, `_my_comments.json` |
| 배송지 | `addresses.json` | `_list.json`, `_modal_address.json`, `_modal_confirm_delete.json` |
| 알림 | `notifications.json` | `_list.json` |
| 비밀번호 변경 | `change-password.json` | (인라인) |
| 위시리스트 | `wishlist.json` | `_list.json` |
| 상품 1:1 문의내역 | `inquiries.json` | `_list.json` (sirsoft-ecommerce 모듈 연동 시에만 탭 표시) |

**핸들러 패턴**:
- `apiCall` — 프로필 수정, 주문 취소, 배송지 CRUD, 알림 읽음 처리
- `navigate` — 탭 간 이동 (별도 페이지)
- `setState` — 폼 상태, 모달 제어
- `openModal`/`closeModal` — 탈퇴, 주소 편집, 삭제 확인

---

### 검색 패턴

**대표**: `search/index.json`

**구성**:
```text
extends: _user_base
state: { q: "" }
init_actions: [setState — query 파라미터에서 검색어 추출]
global_state: { searchActiveTab, searchBoardFilter, searchSortBy, searchPage }
data_sources: [searchPosts, searchProducts, searchPages]
slots.content:
  └── Container
      ├── _search_input.json (검색 입력 — SearchBar)
      ├── _search_tabs.json (탭: 전체/게시글/상품/페이지)
      ├── _search_filters.json (정렬, 게시판 필터)
      ├── _search_states.json (검색 전/로딩/결과없음 상태)
      └── _search_results.json (결과 표시)
          ├── posts/_section.json → posts/_list.json
          ├── products/_section.json → products/_item_card.json / _item_list.json
          └── pages/_section.json
```

**특수사항**:
- 통합 검색: 게시글 + 상품 + 페이지 동시 검색
- `searchActiveTab`으로 탭 전환 시 해당 카테고리만 표시
- 상품 결과는 카드/리스트 뷰 전환 지원
- `global_state`로 검색 상태 유지 (탭, 필터, 정렬, 페이지)

**핸들러 패턴**:
- `apiCall` — 검색 API (게시글, 상품, 페이지 별도)
- `setState` — 탭 전환, 필터, 정렬
- `replaceUrl` — 검색어/필터 변경 시 URL 갱신

---

### 기타 페이지 패턴

#### 사용자 프로필 (users/show.json, users/posts.json)

```text
extends: _user_base
data_sources: [user (공개 프로필)]
slots.content:
  └── Container
      ├── Avatar + 사용자 정보
      └── 게시글 목록 (users/posts.json)
```

#### 정적 페이지 (page/show.json)

```text
extends: _user_base
data_sources: [page (페이지 콘텐츠)]
slots.content:
  └── Container
      └── HtmlContent (본문 렌더링)
```

#### 홈 (home.json)

```text
extends: _user_base
data_sources: [stats, recent_posts, popular_boards]
meta.seo: { structured_data: WebSite }
slots.content:
  └── Container
      ├── _welcome_card.json (환영 배너)
      ├── Grid (통계 카드 4개)
      │   ├── _stat_card_users.json
      │   ├── _stat_card_posts.json
      │   ├── _stat_card_comments.json
      │   └── _stat_card_boards.json
      ├── _recent_posts.json (최근 게시글)
      ├── _popular_boards.json (인기 게시판)
      ├── _board_summary.json (게시판 요약)
      ├── _community_guide.json (가이드)
      └── _shop_promo.json (쇼핑 프로모션)
```

---

### 에러 페이지 패턴

**대표**: `errors/404.json`

**구성**:
```text
(extends 없음 — 독립 레이아웃)
components:
  └── Div (전체 화면 중앙 정렬)
      ├── Icon (에러 아이콘)
      ├── H1 (에러 코드)
      ├── P (에러 메시지)
      └── Button (홈으로 이동)
```

**핸들러 패턴**:
- `navigate` — 홈으로 이동

---

## 베이스 레이아웃 구조

### _user_base.json

모든 사용자 페이지의 공통 구조를 정의합니다.

```text
_user_base.json
├── globalHeaders: [X-Cart-Key 헤더 (이커머스, 인증 API)]
├── transition_overlay: { style: "skeleton", target: "main_content_area" }
├── init_actions: [initTheme, initCartKey, loadPreferredCurrency, setState(shopBase)]
├── data_sources:
│   ├── boards (게시판 메뉴 — progressive, fallback)
│   ├── current_user (인증 사용자 — auth_required, suppress 401)
│   └── cart (장바구니 카운트 — optional auth)
├── components:
│   ├── Toast (전역 알림)
│   ├── PageTransitionIndicator
│   ├── Div (min-h-screen flex flex-col)
│   │   ├── mobile_overlay (모바일 메뉴 오버레이 — responsive portable)
│   │   ├── MobileNav (모바일 네비게이션 드로어)
│   │   ├── Header (사이트 헤더 — 로고, 메뉴, 검색, 사용자)
│   │   ├── Div#main_content_area (콘텐츠 영역)
│   │   │   └── slot: "content" (← 하위 레이아웃이 채움)
│   │   └── Footer (사이트 푸터)
│   └── PageTransitionBlur (전환 블러)
```

**슬롯**:
- `content` — 각 페이지의 메인 콘텐츠가 삽입되는 위치

**특수사항**:
- `globalHeaders`: 이커머스 API에 `X-Cart-Key` 자동 첨부
- `transition_overlay.style: "skeleton"`: 페이지 전환 시 PageSkeleton 표시
- `responsive`: 모바일 오버레이/네비게이션은 `portable` breakpoint에서만 표시
- `auth_mode: "optional"`: 비회원도 장바구니 카운트 조회 가능
- `errorHandling.401.handler: "suppress"`: 비회원의 인증 API 401은 에러 전파 방지

---

## 관련 문서

- [sirsoft-basic 컴포넌트](./components.md)
- [sirsoft-basic 핸들러](./handlers.md)
- [sirsoft-admin_basic 레이아웃](../sirsoft-admin_basic/layouts.md)
- [레이아웃 JSON 스키마](../../layout-json.md)
- [레이아웃 상속](../../layout-json-inheritance.md)
