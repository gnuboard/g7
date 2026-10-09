# Basic — 레이아웃

> 레이아웃 목록과 라우트 매핑 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃 목록

회원·비회원 주문상세의 클레임 신청 버튼은 상품 목록 바로 아래에서 환불 빨강·반품 주황·교환 파랑으로 표시한다. 공통 클레임 목록의 탭·페이지는 로컬 상태와 `replaceUrl`을 갱신한 뒤 `claims` 데이터소스만 동기 재조회하여 스크롤을 유지한다. 탭 대기 중에는 스피너와 `aria-disabled`·액션 가드를 함께 사용해 포커스와 중복 클릭 방지를 유지한다. 다른 비동기 신청·저장 버튼은 기존 `Icon` 스피너와 `disabled` 패턴을 따른다.

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 194개 (루트: `layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `(root)` | 2개 |
| `auth` | 6개 |
| `board` | 5개 |
| `errors` | 6개 |
| `mypage` | 15개 |
| `page` | 1개 |
| `partials` | 145개 |
| `search` | 1개 |
| `shop` | 11개 |
| `users` | 2개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `_user_base` | `(root)` | partial | - |
| `forgot_password` | `auth` | 화면 | `_user_base` |
| `identity_challenge` | `auth` | 화면 | `_user_base` |
| `identity_verify` | `auth` | 화면 | `_user_base` |
| `login` | `auth` | 화면 | `_user_base` |
| `register` | `auth` | 화면 | `_user_base` |
| `reset_password` | `auth` | 화면 | `_user_base` |
| `boards` | `board` | 화면 | `_user_base` |
| `form` | `board` | 화면 | `_user_base` |
| `index` | `board` | 화면 | `_user_base` |
| `popular` | `board` | 화면 | `_user_base` |
| `show` | `board` | 화면 | `_user_base` |
| `401` | `errors` | 화면 | `_user_base` |
| `403` | `errors` | 화면 | `_user_base` |
| `404` | `errors` | 화면 | `_user_base` |
| `500` | `errors` | 화면 | `_user_base` |
| `503` | `errors` | 화면 | `_user_base` |
| `maintenance` | `errors` | 화면 | `_user_base` |
| `home` | `(root)` | 화면 | `_user_base` |
| `addresses` | `mypage` | 화면 | `_user_base` |
| `board` | `mypage` | 화면 | `_user_base` |
| `change-password` | `mypage` | 화면 | `_user_base` |
| `claims` | `mypage` | 화면 | `_user_base` |
| `apply` | `mypage` | 화면 | `_user_base` |
| `show` | `mypage` | 화면 | `_user_base` |
| `inquiries` | `mypage` | 화면 | `_user_base` |
| `mileage` | `mypage` | 화면 | `_user_base` |
| `notifications` | `mypage` | 화면 | `_user_base` |
| `orders` | `mypage` | 화면 | `_user_base` |
| `show` | `mypage` | 화면 | `_user_base` |
| `profile-edit` | `mypage` | 화면 | `_user_base` |
| `profile` | `mypage` | 화면 | `_user_base` |
| `scrap` | `mypage` | 화면 | `_user_base` |
| `wishlist` | `mypage` | 화면 | `_user_base` |
| `show` | `page` | 화면 | `_user_base` |
| `_identity_challenge_modal` | `partials` | partial | - |
| `_modal_notification_delete_all_confirm` | `partials` | partial | - |
| `_visitor_counter` | `partials` | partial | - |
| `_login_form` | `partials` | partial | - |
| `_modal_privacy` | `partials` | partial | - |
| `_modal_terms` | `partials` | partial | - |
| `_redirect_if_logged_in` | `partials` | partial | - |
| `_register_form` | `partials` | partial | - |
| `_board_card` | `partials` | partial | - |
| `_parent_post` | `partials` | partial | - |
| `_password_verify_modal` | `partials` | partial | - |
| `_post_form` | `partials` | partial | - |
| `_type_renderer` | `partials` | partial | - |
| `_admin_links` | `partials` | partial | - |
| `_empty_states` | `partials` | partial | - |
| `_loading_error` | `partials` | partial | - |
| `_type_renderer` | `partials` | partial | - |
| `_write_button` | `partials` | partial | - |
| `_popular_item` | `partials` | partial | - |
| `_popular_list` | `partials` | partial | - |
| `_comment_input` | `partials` | partial | - |
| `_comment_item` | `partials` | partial | - |
| `_comment_section` | `partials` | partial | - |
| `_navigation` | `partials` | partial | - |
| `_post_attachments` | `partials` | partial | - |
| `_reply_section` | `partials` | partial | - |
| `_type_renderer` | `partials` | partial | - |
| `_modal_delete` | `partials` | partial | - |
| `_modal_report` | `partials` | partial | - |
| `_password_verify_modal` | `partials` | partial | - |
| `form` | `partials` | 화면 | - |
| `index` | `partials` | 화면 | - |
| `show` | `partials` | 화면 | - |
| `index` | `partials` | 화면 | - |
| `index` | `partials` | 화면 | - |
| `_currency_selector` | `partials` | partial | - |
| `_board_summary` | `partials` | partial | - |
| `_community_guide` | `partials` | partial | - |
| `_popular_boards` | `partials` | partial | - |
| `_recent_posts` | `partials` | partial | - |
| `_shop_promo` | `partials` | partial | - |
| `_stat_card_boards` | `partials` | partial | - |
| `_stat_card_comments` | `partials` | partial | - |
| `_stat_card_posts` | `partials` | partial | - |
| `_stat_card_users` | `partials` | partial | - |
| `_welcome_card` | `partials` | partial | - |
| `_tab_navigation` | `partials` | partial | - |
| `_list` | `partials` | partial | - |
| `_modal_address` | `partials` | partial | - |
| `_modal_confirm_delete` | `partials` | partial | - |
| `_modal_confirm_overwrite` | `partials` | partial | - |
| `_list` | `partials` | partial | - |
| `_modal_reaction_bulk_delete` | `partials` | partial | - |
| `_modal_reaction_delete` | `partials` | partial | - |
| `_modal_report_cancel` | `partials` | partial | - |
| `_my_comments` | `partials` | partial | - |
| `_my_posts` | `partials` | partial | - |
| `_my_reactions` | `partials` | partial | - |
| `_my_reports` | `partials` | partial | - |
| `_apply_comparison` | `partials` | partial | - |
| `_apply_items` | `partials` | partial | - |
| `_apply_reason` | `partials` | partial | - |
| `_apply_redelivery` | `partials` | partial | - |
| `_apply_refund_account` | `partials` | partial | - |
| `_apply_summary` | `partials` | partial | - |
| `_detail_claim_info` | `partials` | partial | - |
| `_detail_items` | `partials` | partial | - |
| `_detail_progress` | `partials` | partial | - |
| `_detail_reason` | `partials` | partial | - |
| `_detail_shipping` | `partials` | partial | - |
| `_list` | `partials` | partial | - |
| `_modal_change_product` | `partials` | partial | - |
| `_modal_image_viewer` | `partials` | partial | - |
| `_modal_withdraw` | `partials` | partial | - |
| `_list` | `partials` | partial | - |
| `_list` | `partials` | partial | - |
| `_list` | `partials` | partial | - |
| `_history` | `partials` | partial | - |
| `_items` | `partials` | partial | - |
| `_list` | `partials` | partial | - |
| `_modal_change_address` | `partials` | partial | - |
| `_modal_confirm_purchase` | `partials` | partial | - |
| `_modal_write_review` | `partials` | partial | - |
| `_orderer` | `partials` | partial | - |
| `_payment` | `partials` | partial | - |
| `_shipping` | `partials` | partial | - |
| `_status_header` | `partials` | partial | - |
| `_edit` | `partials` | partial | - |
| `_modal_withdraw` | `partials` | partial | - |
| `_password_verify_section` | `partials` | partial | - |
| `_view` | `partials` | partial | - |
| `_list` | `partials` | partial | - |
| `_modal_scrap_bulk_delete` | `partials` | partial | - |
| `_modal_scrap_delete` | `partials` | partial | - |
| `_list` | `partials` | partial | - |
| `_search_filters` | `partials` | partial | - |
| `_search_input` | `partials` | partial | - |
| `_search_results` | `partials` | partial | - |
| `_search_states` | `partials` | partial | - |
| `_search_tabs` | `partials` | partial | - |
| `_section` | `partials` | partial | - |
| `_list` | `partials` | partial | - |
| `_section` | `partials` | partial | - |
| `_item_card` | `partials` | partial | - |
| `_section` | `partials` | partial | - |
| `_cart_item` | `partials` | partial | - |
| `_cart_list` | `partials` | partial | - |
| `_cart_summary` | `partials` | partial | - |
| `_checkout_discount` | `partials` | partial | - |
| `_checkout_items` | `partials` | partial | - |
| `_checkout_mileage` | `partials` | partial | - |
| `_checkout_orderer` | `partials` | partial | - |
| `_checkout_payment` | `partials` | partial | - |
| `_checkout_shipping` | `partials` | partial | - |
| `_checkout_summary` | `partials` | partial | - |
| `_modal_address_manage` | `partials` | partial | - |
| `_modal_cart_delete_confirm` | `partials` | partial | - |
| `_modal_cart_option_change` | `partials` | partial | - |
| `_modal_cart_unavailable` | `partials` | partial | - |
| `_modal_coupon_download` | `partials` | partial | - |
| `_modal_exclusive_coupon_confirm` | `partials` | partial | - |
| `_modal_temp_order_not_found` | `partials` | partial | - |
| `_admin_edit_link` | `partials` | partial | - |
| `_header` | `partials` | partial | - |
| `_info_summary` | `partials` | partial | - |
| `_modal_cart_added` | `partials` | partial | - |
| `_modal_coupon_download_confirm` | `partials` | partial | - |
| `_modal_inquiry_delete` | `partials` | partial | - |
| `_modal_login_required` | `partials` | partial | - |
| `_modal_qna_reply` | `partials` | partial | - |
| `_modal_qna_write` | `partials` | partial | - |
| `_modal_review_image` | `partials` | partial | - |
| `_price_mobile` | `partials` | partial | - |
| `_purchase_card` | `partials` | partial | - |
| `_review_avatar` | `partials` | partial | - |
| `_tab_detail` | `partials` | partial | - |
| `_tab_qna` | `partials` | partial | - |
| `_tab_reviews` | `partials` | partial | - |
| `_category_breadcrumb` | `partials` | partial | - |
| `_category_filter` | `partials` | partial | - |
| `_new_products` | `partials` | partial | - |
| `_popular_products` | `partials` | partial | - |
| `_product_grid` | `partials` | partial | - |
| `_recent_products` | `partials` | partial | - |
| `_search_filter_bar` | `partials` | partial | - |
| `index` | `search` | 화면 | `_user_base` |
| `cart` | `shop` | 화면 | `_user_base` |
| `category` | `shop` | 화면 | `_user_base` |
| `checkout` | `shop` | 화면 | `_user_base` |
| `guest_claim_apply` | `shop` | 화면 | `_user_base` |
| `guest_claim_show` | `shop` | 화면 | `_user_base` |
| `guest_order_form` | `shop` | 화면 | `_user_base` |
| `guest_order_show` | `shop` | 화면 | `_user_base` |
| `index` | `shop` | 화면 | `_user_base` |
| `order_complete` | `shop` | 화면 | `_user_base` |
| `reorder` | `shop` | 화면 | `_user_base` |
| `show` | `shop` | 화면 | `_user_base` |
| `posts` | `users` | 화면 | `_user_base` |
| `show` | `users` | 화면 | `_user_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
166개 중 **124개가 partial** 입니다(화면 42). 이 비율이 이 템플릿의 구조를 그대로 보여줍니다 —
화면 하나가 여러 조각으로 나뉘어 있고, 조각은 여러 화면이 공유합니다. 화면 하나를 고칠 때는
그 화면 이름의 partials 디렉토리를 함께 열어야 전체가 보입니다.

그룹은 방문자 여정과 1:1 입니다 — `auth`(로그인·가입·비밀번호·본인인증) · `board`(게시판) ·
`shop`(상점) · `mypage`(마이페이지) · `page`(단일 문서) · `search` · `users` · `errors`.
`(root)` 둘은 `_user_base`(모든 화면의 베이스)와 `home` 입니다.

**모든 화면이 `_user_base` 를 상속합니다.** 헤더·푸터·모바일 네비·토스트·모달 호스트가 거기
있으므로, `extends` 없이 독립 레이아웃을 새로 만들면 그 화면에서는 `toast` 나 `openModal` 이
성공으로 기록되지만 **화면에는 아무것도 나타나지 않습니다**(호스트 컴포넌트가 마운트되지 않아서).
새 화면은 특별한 이유가 없는 한 `_user_base` 를 상속합니다.

`errors/` 6종(401·403·404·500·503·maintenance)은 코어가 오류 상황에서 직접 부릅니다. 401 은
**로그인 리다이렉트를 여기서 구현하지 않습니다** — 코어 `TemplateApp.showRouteError` 가드가
처리하므로, 이 레이아웃에서 다시 이동시키면 이중 리다이렉트가 됩니다.

**휴면 해제 안내(이슈 #636 S4)**: `partials/auth/_login_form` 은 로그인 응답에 `dormancy_restored` 가
실렸을 때만 안내 토스트를 띄웁니다(`auth.dormancy_restored`). 서버는 **해제됐을 때만** 그 키를 보내므로
키의 존재 자체가 조건입니다 — `false` 를 늘 보내는 형태로 바꾸면 매 로그인마다 안내가 뜹니다.
1단계(2FA 없는 사이트)와 2단계 완료 경로 **둘 다**에 같은 토스트를 둡니다. 한쪽만 두면 사이트의 2FA
설정에 따라 해제 사실이 보이기도 하고 안 보이기도 합니다. 비밀번호 변경 단계(#736)의 완료·연기 경로에도 같은 토스트를 둡니다.
엔진이 engine-v1.76.0 전에는 이 키를 핸들러 응답에서 버려 토스트가 한 번도 뜨지 않았습니다 — 이 레이아웃 조건은 그대로이고
엔진이 키를 전달하도록 고쳐졌습니다.

`mypage/claims*` 와 `shop/guest_claim_*` 는 한 클러스터입니다 — 목록·상세·작성/수정 셸이
`partials/mypage/claims/` 의 같은 조각을 공유하고, 비회원 셸은 데이터소스 endpoint 와 헤더만
다릅니다. 서브탭·페이지·필터는 `navigate + mergeQuery` URL 왕복이며, 모달 id 는 `modal_claim_*`
접두사로 고정돼 다른 화면의 모달과 겹치지 않습니다. 신청서 요약 카드(`_apply_summary.json`)는
`_apply_comparison.json`(주문금액 비교 전/후 · 환불 요약 · 환불 불가 안내)을 안에 싣습니다 —
옛 주문 취소 모달(`_modal_cancel.json`)의 비교표를 세 유형 신청서로 옮긴 것이며, 그 모달은
1.2.0 에서 삭제됐습니다(취소는 [취소·환불 신청] 이 받습니다).
신청서 상세의 **추가결제 수단은 서버가 준 선택지(`available_payment_methods`)만 그립니다** —
둘 이상이면 라디오를 세우고 서버가 확정해 둔 수단(`pending_payment.payment_method`)을 기본
선택으로 두며, 하나뿐이면 라벨만 표시합니다. 고른 값은 `payClaimOutstanding` 의 `paymentMethod`
로 실려 나갑니다. 화면이 스스로 목록을 만들지 않는 이유는 **고를 수 있는데 누르면 막히는 칸**을
만들지 않기 위해서입니다 — 서버는 같은 목록으로 결제를 재판정합니다(화면 옵션 ⊆ 게이트 ⊆ 서비스).
접수 때 확정한 수단이 뒤에 낼 수 없게 되는 경우가 둘 있어 이 자리가 필요합니다: 마일리지 잔액
부족, 운영자가 그 수단을 카탈로그에서 내림. 고를 자리가 없으면 출구가 신청서 철회뿐입니다.
라디오 이름은 `card`·`point` 만 자체 문구(「카드·간편결제」·「마일리지」)로 그리고, 그 밖의 수단은 서버가
함께 싣는 표시명(상세 `available_payment_method_labels` · 신청서 `settings.charge_payment_method_labels`)으로
그립니다 — 값만 보고 `point` 가 아니면 카드로 부르면 계좌이체를 고르면서 카드로 낸다고 읽습니다.
빈 값도 **모양**이 계약입니다. 이름표로 값을 꺼내는 자리(`_local.currentSelection?.[그룹명]` 처럼)는 비어도 map(`{}`)이고,
순서가 있는 목록은 빈 배열(`[]`)입니다. 마크업에서는 `:value="{}"` 와 `:value="[]"` 로 갈리며,
화면이 읽기만 할 때는 둘 다 똑같이 비어 보이지만 그 값이 서버로 실리거나 레이아웃을 `.json`
원문으로 설치하면 서버가 대상을 찾지 못해 오류도 로그도 없이 적용되지 않습니다. 이 템플릿에서는 상품 상세(`shop/show`)의 `currentSelection`·`additionalOptions`·`reviewOptionFilters` 가 그 자리입니다 — 반면 `selectedOptionItems` 는 고른 순서가 있는 목록이라 빈 배열입니다.
주문완료(`shop/order_complete`)의 [이 배송지를 저장하기]는 회원의 주소록(`userAddresses` — 비회원·실패는 빈 목록으로 떨어지고 화면을 막지 않음)과
주문 배송지를 대조하는 `_computed.shippingAddressAlreadySaved` 가 참이면 숨긴다. 대조 기준은 수령인·연락처(숫자만)·국가와, 국내는
우편번호·주소·상세주소, 해외는 주소줄·도시·주·우편번호이고 배송지명은 보지 않는다. 저장에 성공하면 주소록을 다시 받아 버튼이 사라지며,
서버 409(같은 이름 「배송지」가 이미 있음)는 질문형 서버 문구 대신 `shop.order_complete.address_name_exists` 안내로 바꾼다.
이 분기는 api-call 의 `<error-handling><on-code code="409">` 로 둔다 — `<fail>` 안에 `<conditions>` 를 두면 엔진이 오류 처리기로
옮길 때 조건 목록을 버려 409·기타 오류 모두 토스트가 뜨지 않는다(실측). 409 외 실패는 `<fail>` 의 오류 토스트가 맡는다.

<!-- @intent END -->

## 라우트 매핑

<!-- @generated:layout-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 레이아웃 | 이름 |
|---|---|---|
| `/` | `home` | - |
| `/login` | `auth/login` | - |
| `/register` | `auth/register` | - |
| `/forgot-password` | `auth/forgot_password` | - |
| `/reset-password` | `auth/reset_password` | - |
| `/identity/challenge` | `auth/identity_challenge` | - |
| `/identity/verify` | `auth/identity_verify` | - |
| `/boards` | `board/boards` | - |
| `/boards/popular` | `board/popular` | - |
| `/board/:slug` | `board/index` | - |
| `/board/:slug/write` | `board/form` | - |
| `/board/:slug/:id` | `board/show` | - |
| `/board/:slug/:id/edit` | `board/form` | - |
| `/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/products` | `shop/index` | - |
| `/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/category/:slug` | `shop/category` | - |
| `/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/products/:product_code` | `shop/show` | - |
| `/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/cart` | `shop/cart` | - |
| `/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/checkout` | `shop/checkout` | - |
| `/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/orders/:id/complete` | `shop/order_complete` | - |
| `/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/guest/orders` | `shop/guest_order_form` | - |
| `/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/guest/orders/:order_number` | `shop/guest_order_show` | - |
| `/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/guest/orders/:order_number/claims/apply/:type` | `shop/guest_claim_apply` | - |
| `/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/guest/claims/:claim_number/edit` | `shop/guest_claim_apply` | - |
| `/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/guest/claims/:claim_number` | `shop/guest_claim_show` | - |
| `/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/reorder/:id` | `shop/reorder` | - |
| `/mypage` | `-` | - |
| `/mypage/profile` | `mypage/profile` | - |
| `/mypage/profile/edit` | `mypage/profile-edit` | - |
| `/mypage/change-password` | `mypage/change-password` | - |
| `/mypage/orders` | `mypage/orders` | - |
| `/mypage/orders/:order_number` | `mypage/orders/show` | - |
| `/mypage/orders/:order_number/claims/apply/:type` | `mypage/claims/apply` | - |
| `/mypage/claims` | `mypage/claims` | - |
| `/mypage/claims/:claim_number/edit` | `mypage/claims/apply` | - |
| `/mypage/claims/:claim_number` | `mypage/claims/show` | - |
| `/mypage/mileage` | `mypage/mileage` | - |
| `/mypage/wishlist` | `mypage/wishlist` | - |
| `/mypage/addresses` | `mypage/addresses` | - |
| `/mypage/notifications` | `mypage/notifications` | - |
| `/mypage/board` | `mypage/board` | - |
| `/mypage/scrap` | `mypage/scrap` | - |
| `/mypage/inquiries` | `mypage/inquiries` | - |
| `/users/:userId` | `users/show` | - |
| `/users/:userId/posts` | `users/posts` | - |
| `/page/:slug` | `page/show` | - |
| `/search` | `search/index` | - |
| `/404` | `errors/404` | - |
| `/403` | `errors/403` | - |
| `/500` | `errors/500` | - |
<!-- @generated:layout-map END -->

<!-- @intent START -->
상점 경로 9개만 **표현식**입니다. 운영자가 이커머스 환경설정에서 상점 경로(`route_path`,
기본 `shop`)를 바꾸거나 아예 루트로 두면(`no_route`), 그 설정이 라우트 문자열에 그대로
반영됩니다 — `routes.json` 이 정적 파일이 아니라 **표현식을 담을 수 있다**는 것이 이 템플릿의
전제입니다.

그래서 상점 라우트를 손볼 때는 세 경우를 함께 생각해야 합니다: 기본(`/shop/...`) · 운영자
지정 경로(`/store/...`) · 루트 배치(`/products`). 문자열을 하드코딩하면 뒤의 둘이 조용히
깨집니다.

`/mypage` 자체는 레이아웃이 `-` 입니다 — 진입하면 하위 탭 중 하나로 넘기는 자리이며 자기
화면을 갖지 않습니다.

게시글 작성·수정(`board/form` → `_post_form`)의 옵션 카드는 폼 메타의 권한 값으로 갈립니다.
공지 옵션은 `user_abilities.can_manage`(게시판 관리) **또는** `can_write_notice`(공지 등록) 가
참일 때만, 답글이 아닐 때만 보입니다 — 서버(게시판 모듈)가 공지 지정을 같은 두 권한으로
판정하므로 조건을 한쪽만 바꾸면 화면과 서버가 갈립니다. 숨김은 UI 은닉일 뿐이고 차단은 서버가
합니다. 수정 모드의 폼은 서버가 준 기존 값(`is_notice` 포함)을 통째로 되돌려 보내며, 서버는
값이 바뀔 때만 공지 권한을 판정합니다.

라우트를 바꾸면 `php artisan template:update sirsoft-basic --force` 로 반영합니다. 빌드는
필요 없습니다.
<!-- @intent END -->

## 확장 오버라이드

<!-- @generated:template-overrides START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `extensions/sirsoft-daum_postcode/user-address-search.g7.html` | 모듈/플러그인 확장 조각을 대체하는 오버라이드 — 확장점 `address_search_slot` |
<!-- @generated:template-overrides END -->

<!-- @intent START -->
오버라이드는 **확장이 제공한 조각을 이 템플릿의 것으로 갈아 끼우는** 장치입니다. 지금은 하나
있습니다 — `sirsoft-daum_postcode` 의 주소 검색 조각.
원본과 같이 SDK 스크립트에 `label="$t:sirsoft-daum_postcode.search_address"` 를 선언해, 스크립트를 불러오지 못했을 때
안내가 스크립트 id 가 아니라 「주소 검색」으로 나오게 합니다 — 원본 조각의 스크립트 선언을 바꾸면 이 오버라이드도 같이 맞춥니다.

플러그인이 제공하는 원본 조각은 이커머스 관리자 화면에 맞춰져 있어, 방문자 화면의 배송지 입력
디자인과 어긋납니다. 조각을 고치는 대신 **템플릿이 자기 버전을 얹는** 것이 이 방향입니다 —
플러그인을 업데이트해도 이 오버라이드는 남고, 다른 템플릿은 원본을 그대로 씁니다.

그 대가로 **원본이 바뀌면 이 사본은 따라가지 않습니다.** 플러그인이 조각의 동작(핸들러 이름·
필드 계약)을 바꾸면 오버라이드만 옛 계약을 붙들고 있게 되고, 증상은 "주소 검색 버튼이
무반응" 으로만 나타납니다. 해당 플러그인을 업그레이드한 뒤에는 이 오버라이드를 함께 확인합니다.
<!-- @intent END -->

### 결제 정보 카드의 id 는 PG 플러그인과의 약속

`partials/mypage/orders/_payment` 의 `id="order_payment_info_panel"` 은 지우거나 바꾸지 않는다. PG 플러그인(KG이니시스·NHN KCP·
나이스페이먼츠)이 영수증 버튼·가상계좌 입금 안내를 붙일 카드를 이 id 로만 찾는다 — 화면 제목 글자는 언어마다 달라 기준이 되지 않는다.
id 가 사라지면 오류 없이 그 안내들만 빠진다. 같은 조각의 결제 방법 행 `id="order_payment_method_row"` 도 같은 약속이다 — PG 플러그인이
간편결제 이름(예: 카카오페이)을 이 행의 값(마지막 `span`)에 덧붙인다. 주문 완료 화면에 결제 방법 행을 두는 템플릿은 같은 id 를 단다.
약속 원문: `modules/_bundled/sirsoft-ecommerce/docs/extension-points.md` 「PG 플러그인이 결제 정보 카드에 붙일 때의 약속」.

### 주문서 결제수단 칸의 id·버튼 표식도 PG 플러그인과의 약속

`partials/shop/_checkout_payment` 의 칸 `id="checkout_payment_method_section"` 과 버튼의 `data-payment-method="{{method.id}}"` 는
지우거나 바꾸지 않는다. KG이니시스 엔화 결제수단 제한이 이미 그려진 버튼을 숨길 때 이것으로만 찾는다 — 사라지면 오류 없이
화면 보정만 빠진다(응답을 거르는 앞단 제한은 그대로 동작). 약속 원문: `modules/_bundled/sirsoft-ecommerce/docs/extension-points.md`
「PG 플러그인이 주문서 결제수단 칸을 볼 때의 약속」.

## 이관 원문 상세

> 아래는 코어 `docs/frontend/templates/sirsoft-basic/layouts.md` 에 있던 원문을 이 문서로 옮긴 것입니다(#601). 이관 시점 그대로
> 보존하되, **코드가 SSoT 인 값과 어긋나는 부분에는 정정 주석**을 달았습니다.

### sirsoft-basic 레이아웃

> **템플릿 식별자**: `sirsoft-basic` (type: user)
> **관련 문서**: [컴포넌트](components.md) | [핸들러](handlers.md) | [레이아웃 JSON 스키마](../../../../docs/frontend/layout-json.md) | [마크업](../../../../docs/frontend/layout-markup.md)
>
> **마크업 형식**: 아래 파일 트리는 소스 SSoT 인 `.g7.html`([레이아웃 마크업](../../../../docs/frontend/layout-markup.md)) 기준입니다. 설치 시 서버가 각 `.g7.html` 을 메모리로 JSON 컴파일해 DB(`template_layouts`)에 등록합니다. 산출 JSON 키 계약은 [layout-json.md](../../../../docs/frontend/layout-json.md) 가 SSoT 이며 불변입니다. 디스크에는 진단·복구용 짝 `.json` 이 병존합니다.

> **정정(#601)**: 아래 페이지 맵·개수는 이관 시점 값입니다. 실측 총계와 그룹별 분포는 위
> 「레이아웃 목록」 블록이 SSoT 이며, 이관 이후 추가·삭제된 레이아웃이 있을 수 있습니다.

---

### TL;DR (5초 요약)

```text
1. 베이스: _user_base.g7.html (헤더 + 푸터 + 모바일 네비 + 콘텐츠 슬롯)
2. 코어 페이지 30개+: 인증, 게시판, 쇼핑몰, 마이페이지, 검색, 프로필
3. Partial 100개+: 폼, 모달, 탭, 카드, 목록, 필터, 타입별 렌더러
4. 에러 페이지 6개: 401, 403, 404, 500, 503, maintenance
5. 패턴: 게시판(타입별 렌더러), 쇼핑몰(장바구니/결제 플로우), 마이페이지(탭 네비게이션)
```

---

### 목차

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

### 페이지 맵 (트리 구조)

```text
_user_base.g7.html (베이스 레이아웃)
│
├── home.g7.html (홈/대시보드)
│   └── partials/home/
│       ├── _welcome_card.g7.html (환영 카드)
│       ├── _stat_card_users.g7.html (통계: 사용자)
│       ├── _stat_card_posts.g7.html (통계: 게시글)
│       ├── _stat_card_comments.g7.html (통계: 댓글)
│       ├── _stat_card_boards.g7.html (통계: 게시판)
│       ├── _recent_posts.g7.html (최근 게시글)
│       ├── _popular_boards.g7.html (인기 게시판)
│       ├── _board_summary.g7.html (게시판 요약)
│       ├── _community_guide.g7.html (커뮤니티 가이드)
│       ├── _shop_promo.g7.html (쇼핑 프로모션)
│       └── _visitor_counter.g7.html (방문자 카운터 — 설정을 켰을 때만, 공개 요약 API #682 S3)
│
├── 인증 (auth/)
│   ├── login.g7.html (로그인)
│   ├── register.g7.html (회원가입)
│   ├── forgot_password.g7.html (비밀번호 찾기)
│   ├── reset_password.g7.html (비밀번호 재설정)
│   ├── identity_challenge.g7.html (2단계 인증 챌린지 — #657)
│   ├── identity_verify.g7.html (본인인증 메일 링크 착지 — 확인만, 결과는 인증을 시작한 화면이 받음)
│   └── partials/auth/
│       ├── _login_form.g7.html (로그인 폼)
│       ├── _register_form.g7.html (회원가입 폼)
│       ├── _redirect_if_logged_in.g7.html (로그인 상태 리다이렉트)
│       ├── _modal_terms.g7.html (이용약관 모달)
│       └── _modal_privacy.g7.html (개인정보 처리방침 모달)
│
├── 게시판 (board/)
│   ├── boards.g7.html (게시판 목록)
│   │   └── partials/board/boards/
│   │       └── _board_card.g7.html (게시판 카드)
│   ├── index.g7.html (게시글 목록)
│   │   └── partials/board/index/
│   │       ├── _type_renderer.g7.html (타입별 렌더러 분기)
│   │       ├── _admin_links.g7.html (관리자 전용 바로가기)
│   │       ├── _empty_states.g7.html (빈 상태)
│   │       ├── _loading_error.g7.html (로딩/에러)
│   │       └── _write_button.g7.html (글쓰기 버튼)
│   ├── show.g7.html (게시글 상세)
│   │   └── partials/board/show/
│   │       ├── _type_renderer.g7.html (타입별 렌더러 분기)
│   │       ├── _comment_section.g7.html (댓글 섹션)
│   │       ├── _comment_item.g7.html (댓글 아이템)
│   │       ├── _comment_input.g7.html (댓글 입력)
│   │       ├── _reply_section.g7.html (답글 섹션)
│   │       ├── _navigation.g7.html (이전글/다음글)
│   │       ├── _post_attachments.g7.html (첨부파일)
│   │       └── modals/
│   │           ├── _modal_delete.g7.html (삭제 확인)
│   │           ├── _modal_report.g7.html (신고)
│   │           └── _password_verify_modal.g7.html (비밀번호 확인)
│   ├── form.g7.html (게시글 작성/수정)
│   │   └── partials/board/form/
│   │       ├── _post_form.g7.html (게시글 폼)
│   │       ├── _parent_post.g7.html (원글 표시)
│   │       ├── _type_renderer.g7.html (타입별 렌더러 분기)
│   │       └── _password_verify_modal.g7.html (비밀번호 확인)
│   ├── popular.g7.html (인기글)
│   │   └── partials/board/popular/
│   │       ├── _popular_list.g7.html (인기글 리스트)
│   │       └── _popular_item.g7.html (인기글 아이템)
│   └── partials/board/types/ (게시판 타입별 부분 레이아웃)
│       ├── basic/
│       │   ├── index.g7.html (기본 목록)
│       │   ├── show.g7.html (기본 상세)
│       │   └── form.g7.html (기본 폼)
│       ├── card/
│       │   └── index.g7.html (카드형 목록)
│       └── gallery/
│           └── index.g7.html (갤러리형 목록)
│
├── 쇼핑몰 (shop/)
│   ├── index.g7.html (상품 목록/메인)
│   │   └── partials/shop/list/
│   │       ├── _product_grid.g7.html (상품 그리드)
│   │       ├── _popular_products.g7.html (인기 상품)
│   │       ├── _new_products.g7.html (신상품)
│   │       ├── _recent_products.g7.html (최근 본 상품)
│   │       ├── _category_filter.g7.html (카테고리 필터)
│   │       ├── _category_breadcrumb.g7.html (카테고리 경로)
│   │       └── _search_filter_bar.g7.html (검색/필터 바)
│   ├── category.g7.html (카테고리 상품)
│   ├── show.g7.html (상품 상세)
│   │   └── partials/shop/detail/
│   │       ├── _header.g7.html (상품 헤더)
│   │       ├── _info_summary.g7.html (상품 정보 요약)
│   │       ├── _purchase_card.g7.html (구매 카드)
│   │       ├── _price_mobile.g7.html (모바일 가격)
│   │       ├── _tab_detail.g7.html (상세 탭)
│   │       ├── _tab_reviews.g7.html (리뷰 탭)
│   │       ├── _tab_qna.g7.html (Q&A 탭)
│   │       ├── _review_avatar.g7.html (리뷰 아바타)
│   │       ├── _modal_cart_added.g7.html (장바구니 추가 확인)
│   │       ├── _modal_coupon_download_confirm.g7.html (쿠폰 다운로드 확인)
│   │       ├── _modal_login_required.g7.html (로그인 필요)
│   │       ├── _modal_review_image.g7.html (리뷰 이미지 확대)
│   │       ├── _admin_edit_link.g7.html (관리자 상품 수정 바로가기)
│   │       ├── _modal_inquiry_delete.g7.html (1:1 문의 삭제 확인)
│   │       ├── _modal_qna_reply.g7.html (Q&A 답글)
│   │       └── _modal_qna_write.g7.html (Q&A 작성)
│   ├── cart.g7.html (장바구니)
│   │   └── partials/shop/
│   │       ├── _cart_list.g7.html (장바구니 목록)
│   │       ├── _cart_item.g7.html (장바구니 아이템)
│   │       ├── _cart_summary.g7.html (장바구니 요약)
│   │       ├── _modal_cart_delete_confirm.g7.html (삭제 확인)
│   │       ├── _modal_cart_option_change.g7.html (옵션 변경)
│   │       ├── _modal_cart_unavailable.g7.html (품절 안내)
│   │       └── _modal_temp_order_not_found.g7.html (임시주문 없음)
│   ├── checkout.g7.html (결제)
│   │   └── partials/shop/
│   │       ├── _checkout_orderer.g7.html (주문자 정보)
│   │       ├── _checkout_shipping.g7.html (배송 정보)
│   │       ├── _checkout_items.g7.html (주문 상품)
│   │       ├── _checkout_discount.g7.html (할인/쿠폰)
│   │       ├── _checkout_mileage.g7.html (마일리지)
│   │       ├── _checkout_payment.g7.html (결제 수단)
│   │       ├── _checkout_summary.g7.html (주문 요약)
│   │       ├── _modal_address_manage.g7.html (배송지 관리)
│   │       ├── _modal_coupon_download.g7.html (쿠폰 다운로드)
│   │       └── _modal_exclusive_coupon_confirm.g7.html (전용 쿠폰 확인)
│   ├── order_complete.g7.html (주문 완료)
│   ├── guest_order_form.g7.html (비회원 주문 조회 폼)
│   ├── guest_order_show.g7.html (비회원 주문 상세)
│   ├── guest_claim_apply.g7.html (비회원 클레임 신청서)
│   ├── guest_claim_show.g7.html (비회원 클레임 상세)
│   └── reorder.g7.html (재주문)
│
├── 마이페이지 (mypage/)
│   ├── mileage.g7.html (마일리지 내역)
│   ├── scrap.g7.html (스크랩 — 일괄 삭제 확인 조각 포함)
│   ├── profile.g7.html (프로필 보기)
│   ├── profile-edit.g7.html (프로필 수정)
│   │   └── partials/mypage/profile/
│   │       ├── _view.g7.html (프로필 보기 섹션 — 국가는 응답의 country_name 으로 이름 표시)
│   │       ├── _edit.g7.html (프로필 수정 섹션 — 국가 선택은 profile-edit 의 countryList define)
│   │       ├── _password_verify_section.g7.html (비밀번호 확인)
│   │       └── _modal_withdraw.g7.html (회원 탈퇴)
│   ├── orders.g7.html (주문 내역)
│   │   └── partials/mypage/orders/
│   │       ├── _list.g7.html (주문 목록)
│   │       ├── _orderer.g7.html (주문자 정보)
│   │       ├── _modal_confirm_purchase.g7.html (구매 확정)
│   │       └── _modal_write_review.g7.html (리뷰 작성)
│   ├── orders/show.g7.html (주문 상세)
│   │   └── partials/mypage/orders/
│   │       ├── _status_header.g7.html (주문 상태 헤더)
│   │       ├── _items.g7.html (주문 상품)
│   │       ├── _shipping.g7.html (배송 정보)
│   │       ├── _payment.g7.html (결제 정보)
│   │       ├── _history.g7.html (주문 이력)
│   │       └── _modal_change_address.g7.html (배송지 변경)
│   ├── claims.g7.html (클레임 신청내역)
│   │   └── partials/mypage/claims/
│   │       ├── _list.g7.html (신청내역 목록)
│   │       └── _modal_withdraw.g7.html (철회 확인)
│   ├── claims/apply.g7.html (클레임 신청서 작성·수정)
│   │   └── partials/mypage/claims/
│   │       ├── _apply_items.g7.html (신청 상품)
│   │       ├── _apply_reason.g7.html (사유)
│   │       ├── _apply_redelivery.g7.html (재배송지)
│   │       ├── _apply_refund_account.g7.html (환불 계좌)
│   │       ├── _apply_comparison.g7.html (주문금액 비교)
│   │       ├── _apply_summary.g7.html (신청 요약)
│   │       └── _modal_change_product.g7.html (교환 상품 변경)
│   ├── claims/show.g7.html (클레임 상세)
│   │   └── partials/mypage/claims/
│   │       ├── _detail_progress.g7.html (진행 상태)
│   │       ├── _detail_claim_info.g7.html (신청 정보)
│   │       ├── _detail_items.g7.html (신청 상품)
│   │       ├── _detail_reason.g7.html (사유)
│   │       ├── _detail_shipping.g7.html (배송)
│   │       └── _modal_image_viewer.g7.html (첨부 이미지 보기)
│   ├── board.g7.html (내 게시글/댓글)
│   │   └── partials/mypage/board/
│   │       ├── _list.g7.html (탭 목록)
│   │       ├── _my_posts.g7.html (내 게시글)
│   │       ├── _my_comments.g7.html (내 댓글)
│   │       ├── _my_reactions.g7.html (내 반응)
│   │       ├── _my_reports.g7.html (내 신고)
│   │       ├── _modal_reaction_delete.g7.html (반응 삭제)
│   │       ├── _modal_reaction_bulk_delete.g7.html (반응 일괄 삭제)
│   │       └── _modal_report_cancel.g7.html (신고 취소)
│   ├── addresses.g7.html (배송지 관리)
│   │   └── partials/mypage/addresses/
│   │       ├── _list.g7.html (배송지 목록)
│   │       ├── _modal_address.g7.html (배송지 추가/수정)
│   │       ├── _modal_confirm_delete.g7.html (삭제 확인)
│   │       └── _modal_confirm_overwrite.g7.html (덮어쓰기 확인)
│   ├── notifications.g7.html (알림)
│   │   └── partials/mypage/notifications/
│   │       └── _list.g7.html (알림 목록)
│   ├── change-password.g7.html (비밀번호 변경)
│   ├── wishlist.g7.html (위시리스트)
│   │   └── partials/mypage/wishlist/
│   │       └── _list.g7.html (위시리스트 목록)
│   ├── inquiries.g7.html (상품 1:1 문의내역)
│   │   └── partials/mypage/inquiries/
│   │       └── _list.g7.html (문의 목록)
│   ├── mileage.g7.html (마일리지 내역)
│   ├── scrap.g7.html (스크랩)
│   │   └── partials/mypage/scrap/
│   │       ├── _modal_scrap_delete.g7.html (스크랩 삭제)
│   │       └── _modal_scrap_bulk_delete.g7.html (스크랩 일괄 삭제)
│   └── partials/mypage/
│       └── _tab_navigation.g7.html (마이페이지 공통 탭)
│           hiddenTabIds: inquiries 탭은 sirsoft-ecommerce inquiry 설정 시에만 노출
│
├── 검색 (search/)
│   └── index.g7.html (통합 검색)
│       └── partials/search/
│           ├── _search_input.g7.html (검색 입력)
│           ├── _search_tabs.g7.html (검색 탭)
│           ├── _search_filters.g7.html (검색 필터)
│           ├── _search_results.g7.html (검색 결과)
│           ├── _search_states.g7.html (검색 상태)
│           ├── posts/
│           │   ├── _section.g7.html (게시글 결과 섹션)
│           │   └── _list.g7.html (게시글 결과 목록)
│           ├── products/
│           │   ├── _section.g7.html (상품 결과 섹션)
│           │   └── _item_card.g7.html (상품 카드)
│           └── pages/
│               └── _section.g7.html (페이지 결과 섹션)
│
├── 사용자 (users/)
│   ├── show.g7.html (사용자 프로필)
│   └── posts.g7.html (사용자 게시글)
│
├── 페이지 (page/)
│   └── show.g7.html (정적 페이지)
│
├── 공통 Partial
│   ├── partials/common/
│   │   └── _currency_selector.g7.html (통화 선택기)
│   ├── partials/_identity_challenge_modal.g7.html (2단계 인증 재확인 모달 — #657)
│   └── partials/_modal_notification_delete_all_confirm.g7.html (알림 전체 삭제 확인)
│
└── 에러 페이지 (errors/)
    ├── 401.g7.html (인증 필요)
    ├── 403.g7.html (접근 거부)
    ├── 404.g7.html (페이지 없음)
    ├── 500.g7.html (서버 오류)
    ├── 503.g7.html (서비스 불가)
    └── maintenance.g7.html (점검 중)
```

---

### 카테고리별 가이드

#### 인증 페이지 패턴

**대표**: `auth/login.g7.html`, `auth/register.g7.html`

**구성**:
```text
extends: _user_base
slots.content:
  └── Flex (중앙 정렬)
      ├── _redirect_if_logged_in.g7.html (로그인 시 리다이렉트)
      └── Div (카드 스타일 래퍼)
          ├── H2 (제목)
          ├── partial: _login_form.g7.html 또는 _register_form.g7.html
          └── 하단 링크 (회원가입/로그인 전환, 비밀번호 찾기)
```

**특수사항**:
- `_redirect_if_logged_in.g7.html`: 이미 로그인한 사용자를 홈으로 리다이렉트하는 partial
- 로그인 폼은 `SocialLoginButtons` 포함 (소셜 로그인 지원)
- 회원가입 폼은 `PasswordInput` 사용 (비밀번호 규칙 검증, 확인 일치)
- 약관 동의 모달: `_modal_terms.g7.html`, `_modal_privacy.g7.html`
- `blur_until_loaded`: 현재 사용자 정보 로드 전까지 폼 블러 처리

**핸들러 패턴**:
- `apiCall` — 로그인 (POST `/api/auth/login`), 회원가입 (POST `/api/auth/register`)
- `navigate` — 성공 후 홈/대시보드로 이동
- `setState` — 폼 에러, 로딩 상태

**2단계 인증(인증번호) 단계**

관리자가 보안 설정에서 2단계 인증을 켠 사이트에서는 로그인 응답이 두 형태로 갈립니다 —
정상 로그인과 인증번호 요구(challenge)입니다. `_login_form.json` 은 그 둘을 **같은 카드 안에서**
단계 전환으로 처리합니다.

- 2단계(인증번호) 블록은 `if: "{{_global.twoFactor?.required}}"` 이고, 1단계(이메일·비밀번호) 블록은 그 부정형에
  비밀번호 변경 단계의 부정까지 더합니다(아래 「비밀번호 변경 단계」 서술). 단계 `if` 는 언제나 상보여야 합니다 — 한쪽만
  고치면 두 단계가 겹쳐 보이거나 둘 다 사라집니다.
- 제출 시퀀스의 `login` 과 `loginTwoFactor` 도 같은 쌍으로 상호배타입니다. `loginTwoFactor` 쪽
  `if` 가 빠지면 인증번호 단계에서 Enter 를 눌렀을 때 새 challenge 가 발급되어 흐름이 끊깁니다.
- 인증번호 입력은 controlled 입니다(`value` + `onChange` 의 `setState` 쌍). `events: {}` 래퍼는
  쓰지 않습니다.
- 화면 진입 시 `auth/login.json` 의 `init_actions` 첫 액션이 `twoFactor` 를 `null` 로 되돌립니다.
  전역 상태라 화면을 떠나도 남기 때문에, 이 리셋이 없으면 다시 들어왔을 때 1단계가 보이지 않습니다.
- 만료 시각은 정적 표기입니다(`| datetime`). 카운트다운을 쓰지 않습니다 — `startInterval` 은 등록
  시점 컨텍스트를 고정하고 정지 호출처가 없어 화면을 떠난 뒤에도 남습니다.
- **인증 수단(`provider_id`)에 따라 화면이 갈립니다.** challenge 응답의 `provider_id` 를
  `_global.twoFactor` 에 실어 두고 그 값으로 분기합니다. `'totp'`(인증 앱)이면 서버가
  아무것도 보내지 않으므로 앱 코드 안내로 바꾸고 **「인증번호 다시 받기」 버튼을 감춥니다** —
  남겨 두면 눌러도 아무 일이 없는 것처럼 보입니다. 백업 코드(`xxxxx-xxxxx`)도 같은 칸에
  받으므로 `maxLength` 를 넓힙니다. 그 외 수단(메일 등)은 종전 화면 그대로입니다.

**비밀번호 변경 단계 (#736, engine-v1.77.0)**

비밀번호가 만료됐거나 관리자가 정해 준 비밀번호면 로그인·인증번호 확인 응답이 토큰 대신 변경 단계를 돌려줍니다
(`response.password_change_required`). `_login_form` 은 이를 2단계 인증과 같은 짜임으로 **같은 카드 안의 3단계**로 처리합니다.

- 단계 정보는 `_global.passwordChange`(`required`·`challenge_id`·`reason`·`can_postpone`·`postpone_days`·`expired_at`·`error`·
  `errors`)이고, `auth/login` 의 `init` 이 `twoFactor` 와 함께 `null` 로 되돌립니다. 새 비밀번호·확인 **값**은 `_global` 에 두지 않고
  `_local.passwordChangeInput`(`password`·`password_confirmation`)에 둡니다 — `_global` 은 민감 정보 금지이고(`docs/frontend/security.md`),
  단계 진입·「처음부터」·식별자 만료 때 비웁니다.
- 세 단계의 블록 `if` 는 상보입니다 — 1단계는 `!twoFactor.required && !passwordChange.required`. 제출 시퀀스의 `login`·
  `loginTwoFactor`·`loginPasswordChange` 도 같은 조건으로 상호배타입니다.
- `login`·`loginTwoFactor` 의 성공 분기는 `response.password_change_required` 를 먼저 봅니다. 「2단계 인증이 아니면 성공」으로
  판정하면 토큰 없는 응답을 성공으로 오판해 이동합니다. 최종 성공 지점(로그인·인증번호·변경·연기)마다 현재 사용자 설정·
  비회원 주문조회 토큰 정리·토스트·휴면 안내·이동을 같은 모양으로 둡니다.
- 「다음에 변경」은 `can_postpone` 일 때만 그립니다(`loginPasswordChangePostpone`). 「처음부터」는 두 단계 상태(`_global`)와 입력하던 새 비밀번호(`_local.passwordChangeInput`)를 한 `<sequence>` 로 함께 비웁니다 — 저장소가 둘이라 액션도 둘이고, 둘 이상이면 `<sequence>` 로 감싸 그 앞에 주석을 단다(`docs/frontend/layout-markup.md` §10.5).
- 실패 422 중 `errors.challenge_id`(시간 초과·무효)는 단계를 비우고 1단계 오류 자리에 「처음부터 다시 로그인」 안내를 싣습니다 —
  인증번호도 이미 소비됐습니다. `errors.password`·`errors.password_confirmation` 은 각 칸 아래에 싣습니다.
- 새 비밀번호 칸은 `PasswordInput` 규칙을 보안 환경설정 값으로 바인딩하고(아래 「비밀번호 규칙 바인딩」), 서버만 확인하는 규칙
  (재사용·아이디·반복·연속·현재 비밀번호와 같은 값)은 작은 글로 알립니다.

**비밀번호 규칙 바인딩 (#736)**

가입(`_register_form`)·재설정(`auth/reset_password`)·마이페이지 변경(`mypage/change-password`)·로그인 변경 단계의 새 비밀번호 칸은
`enableRules` 를 리터럴(`minLength:8`)이 아니라 `_global.settings.security.*`(코어가 공개하는 작성 규칙 10키)로 만든 식에 바인딩합니다 —
`['minLength:' + (password_min_length ?? 8)]` 에 특수문자 필수면 `hasSpecial`, 문자 종류 조합이면 `minTypes:2` 를 더합니다. 자리표시의
글자 수(`|count=`)도 같은 값입니다. 숫자를 박아 두면 운영자가 최소 길이를 올려도 화면이 옛 규칙을 안내합니다. 재설정·마이페이지는 422 를
토스트로 뭉개지 않고 칸 아래(`error` prop)에 싣습니다. 마이페이지 화면은 `/api/me` 의 `password_changed_at`·`password_expires_at`·
`password_expiry_days_left` 로 마지막 변경·만료 예정(D-n)을 보여 줍니다.

**금지 — 같은 시퀀스·onSuccess 안에서 방금 저장한 상태를 다시 읽지 않습니다**

`setState target:global` 직후 형제 액션의 `if` 나 값으로 `_global.*` 를 재독하면 갱신 이전 값을
읽습니다(시퀀스 처리기는 `_local` 만 갱신합니다). 그 자리에서는 `{{response.*}}` 만 씁니다.
오류도 경고도 남지 않고 분기만 조용히 어긋나므로 화면을 보지 않으면 드러나지 않습니다.

---

#### 게시판 패턴

**대표**: `board/index.g7.html`, `board/show.g7.html`, `board/form.g7.html`

**구성**:
```text
extends: _user_base
data_sources: [posts (게시글 목록/상세)]
slots.content:
  └── Container
      ├── 타입 렌더러 (_type_renderer.g7.html)
      │   → 게시판 타입에 따라 분기:
      │   ├── basic/index.g7.html (기본 테이블형)
      │   ├── card/index.g7.html (카드형)
      │   └── gallery/index.g7.html (갤러리형)
      ├── Pagination
      └── 글쓰기 버튼 (_write_button.g7.html)
```

**data_sources**:
```html
<data-source id="posts" endpoint="/api/modules/sirsoft-board/boards/{{route.slug}}/posts" method="GET" :auto_fetch="true" :refetchOnMount="true">
  <params page="{{query.page ?? 1}}" search="{{query.search ?? ''}}" category="{{query.category ?? ''}}"/>
  <error-handling>
    <on-code code="403">
      <show-error-page target="content"/>
    </on-code>
  </error-handling>
</data-source>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

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
_type_renderer.g7.html (조건 분기):
  ├── if: board_type === 'basic' → partial: types/basic/index.g7.html
  ├── if: board_type === 'card' → partial: types/card/index.g7.html
  └── if: board_type === 'gallery' → partial: types/gallery/index.g7.html
```

**게시글 상세 구성**:
```text
show.g7.html:
  └── _type_renderer.g7.html → types/basic/show.g7.html
      ├── 게시글 본문 (HtmlContent)
      ├── _post_attachments.g7.html (첨부파일)
      ├── _comment_section.g7.html
      │   ├── _comment_input.g7.html (댓글 입력)
      │   └── _comment_item.g7.html (댓글 아이템, iteration)
      │       └── _reply_section.g7.html (답글)
      ├── _navigation.g7.html (이전/다음 글)
      └── modals: 삭제, 신고, 비밀번호 확인
```

**게시글 작성/수정 구성**:
```text
form.g7.html:
  ├── _parent_post.g7.html (답글 시 원글 표시)
  └── _post_form.g7.html
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

#### 쇼핑몰 패턴

**대표**: `shop/index.g7.html`, `shop/show.g7.html`, `shop/cart.g7.html`, `shop/checkout.g7.html`

##### 상품 목록 (shop/index.g7.html)

```text
extends: _user_base
data_sources: [products (상품 API), categories (카테고리)]
slots.content:
  └── Container
      ├── _category_breadcrumb.g7.html (경로)
      ├── _search_filter_bar.g7.html (검색/정렬)
      ├── _category_filter.g7.html (카테고리 사이드)
      ├── _product_grid.g7.html (상품 그리드, ProductCard iteration)
      ├── _popular_products.g7.html (인기 상품)
      ├── _new_products.g7.html (신상품)
      ├── _recent_products.g7.html (최근 본 상품)
      └── Pagination
```

##### 상품 상세 (shop/show.g7.html)

```text
extends: _user_base
init_actions: [최근 본 상품 localStorage 저장]
data_sources: [product, reviews, popularProducts, relatedProducts]
computed: [displayPrice, displayListPrice, ...]
slots.content:
  └── ThreeColumnLayout
      ├── left: _header.g7.html (이미지 뷰어 — ProductImageViewer)
      ├── center: _info_summary.g7.html (상품 정보, 옵션 선택)
      └── right: _purchase_card.g7.html (가격, 수량, 구매 버튼)
  └── _price_mobile.g7.html (모바일 가격 — responsive portable)
  └── TabNavigation
      ├── _tab_detail.g7.html (상세 정보 — HtmlContent)
      ├── _tab_reviews.g7.html (리뷰 — iteration)
      └── _tab_qna.g7.html (Q&A — iteration)
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

##### 장바구니 (shop/cart.g7.html)

```text
extends: _user_base
state: { selectedItems, allSelected, isCalculating, optionModal, deleteModal, ... }
data_sources: [cartItems (POST /cart/query)]
slots.content:
  └── Flex
      ├── _cart_list.g7.html (장바구니 목록)
      │   └── _cart_item.g7.html (아이템, iteration)
      │       ├── Checkbox (선택)
      │       ├── 상품 정보/가격
      │       ├── QuantitySelector
      │       └── 삭제/옵션변경 버튼
      └── _cart_summary.g7.html (주문 요약, 결제 버튼)
  └── modals: 삭제 확인, 옵션 변경, 품절 안내
```

**특수사항**:
- `auth_mode: "optional"`: 비회원도 장바구니 사용 가능 (X-Cart-Key 헤더)
- `state` 섹션으로 모달/선택 상태 관리
- 장바구니 핸들러: `toggleCartItemSelection`, `selectAllCartItems`, `recalculateCart`

##### 결제 (shop/checkout.g7.html)

```text
extends: _user_base
data_sources: [tempOrder (임시 주문)]
slots.content:
  └── Container
      ├── _checkout_orderer.g7.html (주문자 정보)
      ├── _checkout_shipping.g7.html (배송 정보)
      ├── _checkout_items.g7.html (주문 상품 목록)
      ├── _checkout_discount.g7.html (할인/쿠폰)
      ├── _checkout_mileage.g7.html (마일리지)
      ├── _checkout_payment.g7.html (결제 수단 선택)
      └── _checkout_summary.g7.html (최종 요약 + 결제 버튼)
  └── modals: 배송지 관리, 쿠폰 다운로드, 전용 쿠폰 확인
```

**핸들러 패턴**:
- `apiCall` — 주문 생성, 배송지 조회/저장, 쿠폰 적용
- `setState` — 배송지 선택, 결제 수단, 쿠폰/마일리지 계산

---

#### 마이페이지 패턴

**대표**: `mypage/profile.g7.html`, `mypage/orders.g7.html`

**구성**:
```text
extends: _user_base
computed: { currentTab: "profile" }
data_sources: [user (인증 사용자 정보)]
transition_overlay: { target: "mypage_tab_content" }
slots.content:
  └── Div
      ├── _tab_navigation.g7.html (공통 마이페이지 탭)
      │   → 프로필, 주문내역, 게시글, 배송지, 알림, 위시리스트
      └── 탭별 콘텐츠 영역
```

**탭 구조**:
각 마이페이지 하위 페이지는 동일한 `_tab_navigation.g7.html`을 공유하되, `computed.currentTab`으로 활성 탭을 구분합니다.

| 탭 | 레이아웃 | Partial |
|---|---------|---------|
| 프로필 | `profile.g7.html` | `_view.g7.html` |
| 프로필 수정 | `profile-edit.g7.html` | `_edit.g7.html`, `_password_verify_section.g7.html`, `_modal_withdraw.g7.html` |
| 주문 내역 | `orders.g7.html` | `_list.g7.html` |
| 주문 상세 | `orders/show.g7.html` | `_status_header.g7.html`, `_items.g7.html`, `_shipping.g7.html`, `_payment.g7.html`, `_history.g7.html` |
| 게시판 활동 | `board.g7.html` | `_my_posts.g7.html`, `_my_comments.g7.html`, `_my_reactions.g7.html`, `_my_reports.g7.html`, `_modal_reaction_delete.g7.html`, `_modal_reaction_bulk_delete.g7.html`, `_modal_report_cancel.g7.html` |
| 스크랩 | `scrap.g7.html` | `_list.g7.html`, `_modal_scrap_delete.g7.html`, `_modal_scrap_bulk_delete.g7.html` |
| 배송지 | `addresses.g7.html` | `_list.g7.html`, `_modal_address.g7.html`, `_modal_confirm_delete.g7.html` |
| 알림 | `notifications.g7.html` | `_list.g7.html` |
| 비밀번호 변경 | `change-password.g7.html` | (인라인) |
| 위시리스트 | `wishlist.g7.html` | `_list.g7.html` |
| 상품 1:1 문의내역 | `inquiries.g7.html` | `_list.g7.html` (sirsoft-ecommerce 모듈 연동 시에만 탭 표시) |

**목록의 빈 상태**: 스크랩·내 글·내 댓글·내 반응·내 신고 목록은 0건일 때 두 문구로 갈립니다. 검색어나
게시판·유형·상태 같은 조건이 걸려 있으면 「검색 결과가 없습니다」(`board.no_search_results`), 아무 조건이
없을 때만 「아직 … 없습니다」입니다. 조건을 건 채 「아직 없음」을 띄우면 있는 항목이 사라진 것으로 읽힙니다.

**좁은 화면의 게시판 활동 탭**: 내가 쓴 글·내가 쓴 댓글·내 반응·내 신고 네 탭은 390px 폭에서 칸을 넘으므로
탭 막대 자체가 가로로 흐르게 둡니다(`overflow-x-auto whitespace-nowrap`). 막대가 넘치면 페이지 전체가 가로로
밀립니다. 같은 이유로 검색 줄의 입력칸은 `min-w-0` 으로 줄어들 수 있어야 합니다 — 입력칸의 고유 최소 폭 때문에
찾을 곳·입력·검색·초기화 줄이 칸 밖으로 나갑니다. 네 탭과 스크랩 탭의 검색 입력칸이 모두 이 규칙을 따릅니다.

**목록 선택을 고치는 확인 창**: 스크랩 해제 창처럼 `modals` 에 둔 창이 목록 화면의 선택 배열(`scrapSelectedIds`)을
비우거나 고칠 때는 `set-state target="$parent._local"` 로 씁니다. `target="local"` 은 창 자신의 지역 상태에 써서,
목록의 「N개 선택」과 일괄 버튼이 그대로 남고 다음 일괄 요청에 빈 배열이나 지운 ID 가 실립니다. 창이 읽는 쪽도
`$parent._local.scrapSelectedIds` 입니다.

**게시판이 지워진 스크랩**: 게시판을 지우면 글도 함께 하드 삭제되므로 그 스크랩은 늘 「삭제된 게시글」 분기
(`item.is_deleted`)로 옵니다. 그 분기에서 `item.board_deleted` 면 게시판 자리에 「게시판 삭제됨」을 답니다.

**내 신고 목록의 머리줄**: 신고 시점 스냅샷의 `title ?? excerpt` 입니다 — 글 신고는 글 제목, 제목이 없는
댓글 신고는 본문 요약이 나옵니다. 취소 확인 창도 같은 값을 보여 어느 신고를 거두는지 알립니다.

**핸들러 패턴**:
- `apiCall` — 프로필 수정, 주문 취소, 배송지 CRUD, 알림 읽음 처리
- `navigate` — 탭 간 이동 (별도 페이지)
- `setState` — 폼 상태, 모달 제어
- `openModal`/`closeModal` — 탈퇴, 주소 편집, 삭제 확인

**프로필 수정 저장**: `_edit.g7.html` 의 [저장]은 `PUT /api/me` **한 번**입니다. 이커머스가 설치된
사이트의 결제 통화·배송국가 카드도 `name` 바인딩으로 같은 폼(`profileEditForm`)에 실려 이 요청에 함께
가고, 이커머스 모듈이 코어 훅으로 같은 요청 안에서 검증·저장합니다(이커머스 1.3.0 이상). 그 필드의 검증
오류는 `_local.profileErrors.{필드}` 로 돌아와 카드 아래에 뜹니다. 저장 성공 뒤에는 모듈 엔드포인트를 다시
부르지 않고 전역 선호값(`preferredCurrency`·`preferredShippingCountry`)만 맞춥니다 — 저장이 두 요청으로
갈라지면 한쪽만 실패한 채 저장 완료가 뜨는 상태가 생깁니다.

**저장 국가가 배송 불가로 내려간 경우**: 운영자가 회원이 저장해 둔 배송국가를 배송 가능 목록에서 내리면 헤더·주문서·
서버는 기본 국가로 계산합니다. 내 정보(`_view.g7.html`)·내 정보 수정(`_edit.g7.html`)의 배송국가 칸은 그때
「{저장 국가}은(는) 지금 배송할 수 없는 국가라 적용되지 않습니다. 지금 적용 중인 배송국가: {국가}」 를 보입니다
(`mypage.shipping_country_settings.saved_unavailable`·`applied_now`, 수정 화면은 `saved_label` 접두 — 다른 국가를 고르면 사라짐).

---

#### 검색 패턴

**대표**: `search/index.g7.html`

**구성**:
```text
extends: _user_base
state: { q: "" }
init_actions: [setState — query 파라미터에서 검색어 추출]
global_state: { searchActiveTab, searchBoardFilter, searchSortBy, searchPage }
data_sources: [searchPosts, searchProducts, searchPages]
slots.content:
  └── Container
      ├── _search_input.g7.html (검색 입력 — SearchBar)
      ├── _search_tabs.g7.html (탭: 전체/게시글/상품/페이지)
      ├── _search_filters.g7.html (정렬, 게시판 필터)
      ├── _search_states.g7.html (검색 전/로딩/결과없음 상태)
      └── _search_results.g7.html (결과 표시)
          ├── posts/_section.g7.html → posts/_list.g7.html
          ├── products/_section.g7.html → products/_item_card.g7.html
          └── pages/_section.g7.html
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

#### 기타 페이지 패턴

##### 사용자 프로필 (users/show.g7.html, users/posts.g7.html)

```text
users/show  extends: _user_base
  data_sources: [profile (공개 프로필), postStats (글·댓글 수, auth_mode optional), recentPosts (최근 글 5건, auth_mode optional)]
users/posts extends: _user_base
  data_sources: [userProfile (프로필 머리말, auth_required), userPosts (작성글 목록, auth_mode optional)]
slots.content:
  └── Container
      ├── Avatar + 사용자 정보
      └── 게시글 목록 (users/posts.g7.html)
```

글 목록·건수 데이터 소스는 `auth_mode="optional"` 이다. 로그인한 방문자는 토큰을 실어 자신이 읽을 수 있는
게시판(회원전용 등)의 글까지 보고, 비로그인 방문자는 토큰 없이 요청해 공개 게시판 기준으로 본다(서버
`optional.sanctum`). `required` 로 두면 비로그인 방문자에게는 요청을 건너뛰어 공개 프로필이 빈다.

##### 정적 페이지 (page/show.g7.html)

```text
extends: _user_base
data_sources: [page (페이지 콘텐츠)]
slots.content:
  └── Container
      └── HtmlContent (본문 렌더링)
```

##### 홈 (home.g7.html)

```text
extends: _user_base
data_sources: [stats, recent_posts, popular_boards]
meta.seo: { structured_data: WebSite }
slots.content:
  └── Container
      ├── _welcome_card.g7.html (환영 배너)
      ├── Grid (통계 카드 4개)
      │   ├── _stat_card_users.g7.html
      │   ├── _stat_card_posts.g7.html
      │   ├── _stat_card_comments.g7.html
      │   └── _stat_card_boards.g7.html
      ├── _recent_posts.g7.html (최근 게시글)
      ├── _popular_boards.g7.html (인기 게시판)
      ├── _board_summary.g7.html (게시판 요약)
      ├── _community_guide.g7.html (가이드)
      └── _shop_promo.g7.html (쇼핑 프로모션)
```

---

#### 에러 페이지 패턴

**대표**: `errors/404.g7.html`

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

### 베이스 레이아웃 구조

#### _user_base.g7.html

모든 사용자 페이지의 공통 구조를 정의합니다.

```text
_user_base.g7.html
├── globalHeaders: [X-Cart-Key (이커머스·인증 API) / X-Guest-Order-Token (비회원 주문 후속 액션) / X-Board-Secret-View-Token (게시판 API)]
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
- `globalHeaders`: 패턴별 공통 헤더 자동 첨부
  - `/api/modules/sirsoft-ecommerce/*` — `X-Cart-Key`, `X-Currency`, `X-Shipping-Country`
  - `/api/modules/sirsoft-ecommerce/guest/orders/*` — `X-Guest-Order-Token` (비회원 주문 후속 액션)
  - `/api/modules/sirsoft-board/*` — `X-Board-Secret-View-Token`
    비밀번호를 입력해 비밀글 원문을 연 사실을 다음 요청으로 넘기는 값입니다. 서버는 이
    사실을 검증 응답 하나에만 담고 있어서, 토큰을 싣지 않으면 원문을 연 사용자도 댓글·답글·
    신고에서 전부 거부됩니다. 토큰은 게시글에 결속되므로 다른 글에는 통하지 않고, 비밀글이
    아닌 요청에서는 서버가 아예 평가하지 않습니다. `templates/_bundled/sirsoft-basic/layouts/partials/board/types/basic/show.g7.html` 의
    비밀번호 검증 `onSuccess` 가 `_global.secretViewToken` 에 넣습니다.
- `transition_overlay.style: "skeleton"`: 페이지 전환 시 PageSkeleton 표시
- `responsive`: 모바일 오버레이/네비게이션은 `portable` breakpoint에서만 표시
- `auth_mode: "optional"`: 비회원도 장바구니 카운트 조회 가능
- `errorHandling.401.handler: "suppress"`: 비회원의 인증 API 401은 에러 전파 방지

---

### 관련 문서

- [sirsoft-basic 컴포넌트](components.md)
- [sirsoft-basic 핸들러](handlers.md)
- [sirsoft-admin_basic 레이아웃](../../sirsoft-admin_basic/docs/layouts.md)
- [레이아웃 JSON 스키마](../../../../docs/frontend/layout-json.md)
- [레이아웃 상속](../../../../docs/frontend/layout-json-inheritance.md)

## 레이아웃 주석

새 이벤트 블록(`<on>`)·모달 참조(`<modal src>`)를 만들면 같은 변경에서 설명 주석을 단다 — 이벤트 블록은 `<on>` 앞이 아니라 **첫 액션 앞**에 쓴다(`<on>` 앞 주석은 컴파일 에러). 주석 주입(S21)이 완료로 선언된 뒤라 빈 자리가 생기면 `CommentAnchorStageLedgerTest` 가 red 가 된다. 주석은 산출 JSON 의 `comment` 키로만 들어가고 공개 서빙에서 제거되므로 화면 동작은 바뀌지 않는다.
