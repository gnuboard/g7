# 이커머스 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

클레임의 비동기 저장 버튼은 로컬 pending 상태에 따라 `Icon` 스피너와 `disabled`를 함께 표시한다. 핸들러에서도 중복 실행을 막고 `finally`에서 상태를 해제한다. 완료 요약 재계산 중에는 `claimCompletePreviewLoading`으로 확정을 막으며 최신 계산 요청만 대기 상태를 해제한다.

완료 요약의 선택 변경은 debounce 예약 시점부터 이전 응답을 무효화한다. 다음 요청이 아직 전송되지 않았더라도 이전 성공/실패 응답이 최신 선택이나 실행 잠금을 바꾸면 안 된다. 반응형 버튼 라벨은 내부 `Span.responsive`에 둔다. Button 자체의 `responsive.text`는 `children`보다 우선하므로 함께 둔 스피너를 가린다. 회귀 검증은 예약 사이 응답 경합과 desktop/portable의 대기 표시·기존 라벨을 포함한다.

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 247개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 247개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `admin_ecommerce_brand_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_cart_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_category_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_claim_detail` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_claim_list` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_deposit_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_excel_download_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_inquiry_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_main_banner_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_mileage_transaction_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_order_detail` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_order_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_order_list` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_payment_failure_history` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_personal_payment` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_personal_payment_create` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_personal_payment_detail` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_product_common_info_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_product_form` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_product_list` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_product_notice_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_product_review_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_promotion_coupon_form` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_promotion_coupon_list` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_promotion_discount_code_create` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_promotion_discount_code_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_settings` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_shipping_policy_form` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_shipping_policy_list` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_user_address_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_wishlist_index` | `admin` | 화면 | `_admin_base` |
| `_modal_delete_confirm` | `admin` | partial | - |
| `_modal_editing_confirm` | `admin` | partial | - |
| `_panel_brand_list` | `admin` | partial | - |
| `_panel_detail` | `admin` | partial | - |
| `_panel_form` | `admin` | partial | - |
| `_panel_view` | `admin` | partial | - |
| `_modal_delete_confirm` | `admin` | partial | - |
| `_modal_image_preview` | `admin` | partial | - |
| `_panel_category_list` | `admin` | partial | - |
| `_panel_detail` | `admin` | partial | - |
| `_panel_form` | `admin` | partial | - |
| `_panel_view` | `admin` | partial | - |
| `_modal_claim_exchange_mode_confirm` | `admin` | partial | - |
| `_modal_claim_images_clear` | `admin` | partial | - |
| `_modal_claim_item_quantity_confirm` | `admin` | partial | - |
| `_modal_claim_refund_execute` | `admin` | partial | - |
| `_modal_claim_request_edit` | `admin` | partial | - |
| `_modal_claim_withdraw_on_behalf` | `admin` | partial | - |
| `_partial_claim_activity_log` | `admin` | partial | - |
| `_partial_claim_comparison` | `admin` | partial | - |
| `_partial_claim_items` | `admin` | partial | - |
| `_partial_claim_payment` | `admin` | partial | - |
| `_partial_claim_reason` | `admin` | partial | - |
| `_partial_claim_shipping` | `admin` | partial | - |
| `_modal_bulk_confirm` | `admin` | partial | - |
| `_modal_claim_complete` | `admin` | partial | - |
| `_modal_preset_delete_confirm` | `admin` | partial | - |
| `_modal_preset_edit` | `admin` | partial | - |
| `_modal_preset_manage` | `admin` | partial | - |
| `_modal_preset_save` | `admin` | partial | - |
| `_partial_bulk_action_section` | `admin` | partial | - |
| `_partial_claim_datagrid` | `admin` | partial | - |
| `_partial_filter_section` | `admin` | partial | - |
| `_modal_bulk_match` | `admin` | partial | - |
| `_modal_manual_match` | `admin` | partial | - |
| `_modal_process_history` | `admin` | partial | - |
| `_modal_download_history` | `admin` | partial | - |
| `_modal_delete_confirm` | `admin` | partial | - |
| `_modal_edit_cancel` | `admin` | partial | - |
| `_modal_status_change` | `admin` | partial | - |
| `_partial_banner_detail` | `admin` | partial | - |
| `_partial_banner_form` | `admin` | partial | - |
| `_partial_banner_list` | `admin` | partial | - |
| `_partial_preview_slider` | `admin` | partial | - |
| `_filters` | `admin` | partial | - |
| `_modal_edit_transaction` | `admin` | partial | - |
| `_modal_extend_expiry` | `admin` | partial | - |
| `_modal_manual_transaction` | `admin` | partial | - |
| `_transactions_table` | `admin` | partial | - |
| `_modal_admin_claim_request` | `admin` | partial | - |
| `_modal_batch_change_confirm` | `admin` | partial | - |
| `_modal_cancel_order` | `admin` | partial | - |
| `_modal_confirm_deposit` | `admin` | partial | - |
| `_modal_issue_cash_receipt` | `admin` | partial | - |
| `_modal_reset_guest_password` | `admin` | partial | - |
| `_modal_send_email` | `admin` | partial | - |
| `_modal_send_sms` | `admin` | partial | - |
| `_partial_activity_log` | `admin` | partial | - |
| `_partial_claim_history` | `admin` | partial | - |
| `_partial_order_info` | `admin` | partial | - |
| `_partial_order_progress` | `admin` | partial | - |
| `_partial_payment_info` | `admin` | partial | - |
| `_modal_excel_download` | `admin` | partial | - |
| `_modal_preset_manage` | `admin` | partial | - |
| `_modal_preset_save` | `admin` | partial | - |
| `_modal_bulk_confirm` | `admin` | partial | - |
| `_modal_excel_download` | `admin` | partial | - |
| `_modal_preset_delete_confirm` | `admin` | partial | - |
| `_modal_preset_edit` | `admin` | partial | - |
| `_modal_preset_manage` | `admin` | partial | - |
| `_modal_preset_save` | `admin` | partial | - |
| `_partial_bulk_action_section` | `admin` | partial | - |
| `_partial_filter_section` | `admin` | partial | - |
| `_partial_order_datagrid` | `admin` | partial | - |
| `_modal_member_search` | `admin` | partial | - |
| `_modal_order_search` | `admin` | partial | - |
| `_modal_content_mode_change` | `admin` | partial | - |
| `_modal_copy_confirm` | `admin` | partial | - |
| `_modal_default_confirm` | `admin` | partial | - |
| `_modal_delete_confirm` | `admin` | partial | - |
| `_modal_editing_confirm` | `admin` | partial | - |
| `_modal_set_default_confirm` | `admin` | partial | - |
| `_panel_detail` | `admin` | partial | - |
| `_panel_form` | `admin` | partial | - |
| `_panel_list` | `admin` | partial | - |
| `_panel_view` | `admin` | partial | - |
| `_partial_common_info_detail` | `admin` | partial | - |
| `_partial_common_info_form` | `admin` | partial | - |
| `_modal_add_language` | `admin` | partial | - |
| `_modal_additional_options_clear` | `admin` | partial | - |
| `_modal_confirm_regenerate` | `admin` | partial | - |
| `_modal_copy_product` | `admin` | partial | - |
| `_modal_delete_confirm` | `admin` | partial | - |
| `_modal_label_delete_confirm` | `admin` | partial | - |
| `_modal_label_form` | `admin` | partial | - |
| `_modal_label_uncheck_confirm` | `admin` | partial | - |
| `_modal_multilingual_tag_edit` | `admin` | partial | - |
| `_modal_notice_bulk_change` | `admin` | partial | - |
| `_modal_notice_template_confirm` | `admin` | partial | - |
| `_modal_save_template` | `admin` | partial | - |
| `_partial_activity_log` | `admin` | partial | - |
| `_partial_basic_info` | `admin` | partial | - |
| `_partial_common_info` | `admin` | partial | - |
| `_partial_description` | `admin` | partial | - |
| `_partial_identification_codes` | `admin` | partial | - |
| `_partial_image_upload` | `admin` | partial | - |
| `_partial_other_info` | `admin` | partial | - |
| `_partial_product_notice` | `admin` | partial | - |
| `_partial_product_options` | `admin` | partial | - |
| `_partial_sales_info` | `admin` | partial | - |
| `_partial_seo_settings` | `admin` | partial | - |
| `_partial_shipping` | `admin` | partial | - |
| `_partial_shopping_integration` | `admin` | partial | - |
| `_modal_bulk_confirm` | `admin` | partial | - |
| `_modal_bulk_price` | `admin` | partial | - |
| `_modal_bulk_stock` | `admin` | partial | - |
| `_modal_copy_product` | `admin` | partial | - |
| `_modal_delete_confirm` | `admin` | partial | - |
| `_modal_excel_download` | `admin` | partial | - |
| `_modal_preset_delete_confirm` | `admin` | partial | - |
| `_modal_preset_edit` | `admin` | partial | - |
| `_modal_preset_manage` | `admin` | partial | - |
| `_modal_preset_save` | `admin` | partial | - |
| `_partial_filter_section` | `admin` | partial | - |
| `_partial_product_datagrid` | `admin` | partial | - |
| `_modal_bulk_change` | `admin` | partial | - |
| `_modal_delete_confirm` | `admin` | partial | - |
| `_modal_editing_confirm` | `admin` | partial | - |
| `_panel_detail` | `admin` | partial | - |
| `_panel_form` | `admin` | partial | - |
| `_panel_list` | `admin` | partial | - |
| `_panel_view` | `admin` | partial | - |
| `_modal_image_preview` | `admin` | partial | - |
| `_modal_reply_delete` | `admin` | partial | - |
| `_modal_status_change` | `admin` | partial | - |
| `_partial_basic_info` | `admin` | partial | - |
| `_partial_benefit_settings` | `admin` | partial | - |
| `_partial_issue_settings` | `admin` | partial | - |
| `_partial_usage_conditions` | `admin` | partial | - |
| `_modal_cancel_issue_confirm` | `admin` | partial | - |
| `_modal_delete_confirm` | `admin` | partial | - |
| `_modal_direct_issue` | `admin` | partial | - |
| `_modal_issue_history` | `admin` | partial | - |
| `_modal_status_change_confirm` | `admin` | partial | - |
| `_partial_coupon_datagrid` | `admin` | partial | - |
| `_partial_filter_section` | `admin` | partial | - |
| `_modal_delete_confirm` | `admin` | partial | - |
| `_modal_status_change` | `admin` | partial | - |
| `_bank_accounts_cards` | `admin` | partial | - |
| `_bank_accounts_table` | `admin` | partial | - |
| `_bank_management_modal` | `admin` | partial | - |
| `_claim_policy_section` | `admin` | partial | - |
| `_currency_exchange_cards` | `admin` | partial | - |
| `_currency_exchange_table` | `admin` | partial | - |
| `_disable_international_shipping_modal` | `admin` | partial | - |
| `_exchange_reason_cards` | `admin` | partial | - |
| `_exchange_reason_section` | `admin` | partial | - |
| `_modal_clear_seo_cache` | `admin` | partial | - |
| `_modal_identity_policy_delete` | `admin` | partial | - |
| `_modal_identity_policy_form` | `admin` | partial | - |
| `_modal_mail_template_edit` | `admin` | partial | - |
| `_modal_notification_definition_reset` | `admin` | partial | - |
| `_modal_notification_template_edit` | `admin` | partial | - |
| `_modal_notification_template_preview` | `admin` | partial | - |
| `_modal_privacy_run` | `admin` | partial | - |
| `_modal_privacy_targets` | `admin` | partial | - |
| `_modal_unsaved_tab_switch` | `admin` | partial | - |
| `_payment_methods_cards` | `admin` | partial | - |
| `_payment_methods_list` | `admin` | partial | - |
| `_refund_reason_cards` | `admin` | partial | - |
| `_refund_reason_section` | `admin` | partial | - |
| `_return_reason_cards` | `admin` | partial | - |
| `_return_reason_section` | `admin` | partial | - |
| `_shipping_carrier_cards` | `admin` | partial | - |
| `_shipping_carrier_section` | `admin` | partial | - |
| `_shipping_country_cards` | `admin` | partial | - |
| `_shipping_country_table` | `admin` | partial | - |
| `_shipping_type_cards` | `admin` | partial | - |
| `_shipping_type_section` | `admin` | partial | - |
| `_tab_basic_info` | `admin` | partial | - |
| `_tab_claim` | `admin` | partial | - |
| `_tab_identity_policies` | `admin` | partial | - |
| `_tab_language_currency` | `admin` | partial | - |
| `_tab_mileage` | `admin` | partial | - |
| `_tab_mileage_basic_card` | `admin` | partial | - |
| `_tab_mileage_currency_cards` | `admin` | partial | - |
| `_tab_mileage_currency_table` | `admin` | partial | - |
| `_tab_mileage_expiry_card` | `admin` | partial | - |
| `_tab_mileage_notification_card` | `admin` | partial | - |
| `_tab_notification_definitions` | `admin` | partial | - |
| `_tab_order_settings` | `admin` | partial | - |
| `_tab_privacy_retention` | `admin` | partial | - |
| `_tab_review_settings` | `admin` | partial | - |
| `_tab_seo` | `admin` | partial | - |
| `_tab_shipping` | `admin` | partial | - |
| `_modal_extra_fee_template` | `admin` | partial | - |
| `_partial_basic_info` | `admin` | partial | - |
| `_partial_charge_settings` | `admin` | partial | - |
| `_partial_claim_addresses` | `admin` | partial | - |
| `_partial_claim_country_fee` | `admin` | partial | - |
| `_partial_country_basic_fields` | `admin` | partial | - |
| `_partial_country_tabs` | `admin` | partial | - |
| `_partial_extra_fee` | `admin` | partial | - |
| `_modal_bulk_delete` | `admin` | partial | - |
| `_modal_bulk_toggle` | `admin` | partial | - |
| `_modal_copy` | `admin` | partial | - |
| `_modal_delete` | `admin` | partial | - |
| `_modal_set_default` | `admin` | partial | - |
| `_partial_bulk_actions` | `admin` | partial | - |
| `_partial_datagrid` | `admin` | partial | - |
| `_partial_filter` | `admin` | partial | - |
| `_modal_bulk_delete_confirm` | `admin` | partial | - |
| `_modal_delete_confirm` | `admin` | partial | - |
| `_modal_error` | `admin` | partial | - |
| `_partial_address_datagrid` | `admin` | partial | - |
| `_partial_filter_section` | `admin` | partial | - |
<!-- @generated:layouts END -->

<!-- @intent START -->
206개가 **전부 `admin` 그룹**입니다(화면 25 + 부분 레이아웃 181). `resources/layouts/user/`
디렉토리는 있지만 비어 있습니다 — 이 모듈이 방문자 쇼핑 화면을 소유하지 않는다는 설계가
디렉토리 구조에 그대로 드러난 자리입니다. 상품 목록·상세·장바구니·주문서·마이페이지는
템플릿(`sirsoft-basic`)의 레이아웃이며, 그 화면들은 이 모듈의 공개 API 와 아래 액션 핸들러를
씁니다.

화면 25개에 부분 레이아웃 181개가 붙는 비율(1:7)은 화면이 크기 때문입니다. 상품 등록 폼·주문
상세·환경설정처럼 탭이 여러 개인 화면은 탭마다 파일을 나눠 두었습니다
(`partials/{화면이름}/_tab_*.json`). 화면 하나를 고칠 때는 그 화면 이름의 partials 디렉토리를
함께 열어야 전체가 보입니다.

부분 레이아웃에서는 `{{props.*}}` 를 쓰지 않고 데이터소스 ID 를 직접 참조합니다. 그리고
부모–자식 레이아웃 사이에 데이터소스 ID 가 겹치면 안 됩니다 — 겹치면 한쪽이 조용히 다른 쪽의
응답을 덮어씁니다.

화면 권한보다 좁은 권한이 걸린 **선택지 데이터소스**는 403 을 화면 전체의 오류로 키우지 않습니다.
상품 등록·수정 폼의 분류·브랜드·고시 템플릿·공통정보·배송정책·상품 라벨·회원 등급 7개는 403 을
`suppress` 로 끊고 `fallback` 으로 빈 목록 + `unavailable: true` 를 실은 뒤, 그 칸 아래에서
「… 조회 권한이 없습니다」 를 말합니다(`admin.product.selector_forbidden.*`). 「등록된 것이 없다」
빈 상태와는 갈라 그립니다 — 실재하는 정책·라벨을 없다고 읽게 하지 않기 위해서입니다. 403 이 아닌
실패는 종전대로 본문 오류 화면입니다. 배송정책은 비워 저장해도 거절되지 않고 주문 계산이 기본
배송정책으로 폴백하므로(`ShippingPolicyResolver`), 그 칸의 안내는 「고르지 않으면 기존·기본 정책이
적용된다」고 말합니다. 주문 상세의 클레임 내역(`orderClaims`)도 같은 형태입니다.

레이아웃 JSON 만 고쳤다면 빌드는 필요 없고 `php artisan module:update sirsoft-ecommerce --force`
로 활성 디렉토리에 반영합니다. 다만 **새로 쓴 Tailwind 클래스가 빌드된 CSS 에 없으면** 그
스타일만 조용히 빠지므로, 기존 레이아웃에 쓰이지 않던 클래스를 도입할 때는 확인이 필요합니다.
이 모듈은 자체 Tailwind 빌드가 없고 관리자 템플릿(`sirsoft-admin_basic`)의 `templates/_bundled/sirsoft-admin_basic/dist/css/components.css`
에 의존하며, 그 safelist 는 템플릿 자신의 레이아웃만 스캔합니다. 임의값(`min-w-[9rem]`)만이 아니라
정수 단계 유틸리티도 빠져 있을 수 있습니다 — 예컨대 폭은 `w-36`·`w-40`·`w-48`·`w-52`·`w-56`·`w-64`
는 있지만 `w-44` 는 없습니다(2026-09-13, 상태 변경 Select 가 `w-44` 를 써서 글자폭으로 수축했던
사례). 쓰기 전에 `grep -c ".w-44{" templates/_bundled/sirsoft-admin_basic/dist/css/components.css`
처럼 컴파일 여부를 확인하고, 없으면 있는 단계로 바꾸거나 템플릿 safelist 절차를 밟습니다.

클레임 화면은 관리자 목록·상세(`admin_ecommerce_claim_list` · `admin_ecommerce_claim_detail`)와
주문상세의 '클레임 내역' 섹션, 환경설정 '클레임' 탭, 배송정책의 회수지·클레임 배송비 항목입니다.
상세는 주문상세와 같은 셸(`TabNavigationScroll` + `section_*`)이며, 완료 처리 모달
(`_modal_claim_complete`)은 목록·상세·완료 버튼 세 진입점이 파일 하나를 공유합니다 — 모달이
보여 주는 요약은 서버 `completion-preview` 응답이 단일 출처이고 화면은 표시만 합니다.
반품 완료 미리보기의 환불 예고는 실제 접수와 같은 입력으로 계산합니다 — 수량을 나눠 처리해 같은 주문옵션을
가리키는 행이 둘 이상이면 수량을 합쳐 넘깁니다(재계산 입력이 주문옵션 단위라 합치지 않으면 한 행 몫이 빠집니다).
목록의 유형 서브탭 전환은 유형 종속 필터(상태·귀책·청구·사유·접수 경로 등)만 버리고, 서브탭 뱃지를 센
유형 공통 필터(`ClaimListRequest::countFilters()` — 기간·검색·회원구분·금액·통화)는 URL 로 이어 갑니다 —
다른 탭 뱃지가 "그 탭을 누르면 보이는 건수" 라는 약속이 이 승계에 기댑니다.
상세 「신청상품」 칸의 송장 미리 채움(고른 행이 모두 같은 송장일 때만)은 선택이 바뀌어도 다시 계산합니다 —
칸 값이 직전 선택의 자동 값 그대로면 새 선택 기준으로 바꾸고(섞이면 비움), 운영자가 고쳐 적은 값은 둡니다.

환경설정의 탭 전환은 같은 화면으로의 재진입(`navigate replace`)이라 설정 소스가 `refetchOnMount` 로 다시 받아 폼을
덮습니다. 그래서 탭 줄은 `_local.hasChanges` 가 참이고 지금 탭이 아닌 탭을 누르면 옮기지 않고 확인 창(`_modal_unsaved_tab_switch`, 코어·게시판 환경설정과 같은 공통 창)을 엽니다 —
가려던 탭은 `_global.pendingEcommerceSettingsTab` 에 둡니다. 창의 [이동] 은 창이 자기 지역 상태를 따로 가지므로
전역 `_local`(저장 가드가 읽는 자리)과 `$parent._local`(화면 폼이 읽는 자리)에 탭 줄 「변경 없음」 갈래와 **같은 정리 목록**(인라인 추가 폼 플래그·버퍼·오류)과 `hasChanges=false` 를
쓰고 옮깁니다. 인라인 폼이 새로 생기면 두 목록을 함께 고칩니다(회귀 테스트가 키 일치를 단언). 창에서 사건(`emit-event`)을
보내 부모의 `<on listen>` 하나로 모으는 방식은 쓰지 않습니다 — 엔진의 컴포넌트 사건 구독이 `handler`·`params` 만 넘겨
`sequence` 를 받는 쪽이 아무것도 하지 않습니다.

다국어 이름의 **필수 언어는 사이트 기본 언어**(`_global.appConfig.siteLocale`, 서버 `app.site_locale`)입니다 —
보는 사람의 언어가 아닙니다. 상품 옵션 처리기(`optionHandlers.ts` 의 `getDefaultLocale`)가 옵션 조합 이름을 넣는 칸,
환경설정 인라인 추가 폼(클레임 사유 3종·택배사·배송유형)의 확정 게이트·오류 플래그·행 오류 조회 키
(`'….name.' + (_global.appConfig?.siteLocale ?? $locale ?? 'ko')`), 서버 요청의 이름 필수 검사
(`Http/Requests/Concerns/ResolvesSiteLocale`)가 모두 같은 값을 봅니다. 한쪽만 보는 언어로 두면 다른 언어 관리자에게
「사이트 언어 + 보는 언어」 둘 다 필수가 되거나, 서버 422 키(`name.{사이트 기본 언어}`)와 화면 오류 키가 어긋납니다.
카드에 이름을 **보여 주는** 자리는 보는 언어 그대로입니다.

화면이 새로 계산한 금액은 고정 「원」을 붙이지 않습니다. 처리기는 `calculateCurrencyPrices.ts` 의
`formatCurrency`/`formatAmountInCurrency`(설정 기호·자릿수, 원화는 보는 언어 접두·접미)를 쓰고 — 상품 목록 인라인 옵션
편집의 추가금(`updateOptionField` 의 `price_adjustment_formatted`)도 기본 통화로 이것을 씁니다 — 레이아웃 표현식에서
원화를 이어 붙일 때는 `$t('sirsoft-ecommerce.currency.krw_prefix') + 금액 + $t('sirsoft-ecommerce.currency.krw_suffix')` 로 적습니다
(배송정책 도서산간 템플릿 창의 대체 표기). 서버 `*_formatted` 와 같은 규칙이라 한 화면에서 「₩5,000」과 「5,000원」이 섞이지 않습니다.
`currency.krw_prefix`·`currency.krw_suffix` 는 다른 확장도 읽는 **공유 키**입니다 — 번들 결제 플러그인(나이스페이먼츠)의 관리자 주문
상세 조각이 원화 금액을 같은 방식으로 이어 붙입니다. 키 이름이나 「접두 + 금액 + 접미」 구성을 바꾸면 그 조각의 금액 표기가 깨지므로
함께 고칩니다.

환경설정 알림 탭의 템플릿 편집 창(`_modal_notification_template_edit`)은 코어 창의 사본입니다(게시판·수출입은행
플러그인과 함께 네 벌). 수신자 규칙 종류를 바꾸면 새 종류의 빈 규칙으로 갈아 끼우고, 관련 사용자면 정의가 선언한
첫 관계를 채웁니다 — 앞 종류의 값을 남기면 회원 UUID 가 역할로 저장돼 수신자가 0명이 됩니다. 규칙을 바꾸면 네 벌을
함께 바꿉니다(코어 `sirsoft-admin_basic` AGENTS.md 「알림 템플릿 편집 창 사본」). 관계 선택지와 목록 카드는 관계 키 대신 정의
응답의 `relation_labels` 이름을 보이며, 이름은 `src/lang/{ko,en}/messages.php` 의 `notification_relations` 에 둡니다(「문의 답변 완료」의
`author` → 「문의 작성자」).
<!-- @intent END -->


### 환경설정 본인인증 정책 탭의 목록 조작

환경설정 「본인인증 정책」 탭(`_tab_identity_policies`)의 검색·필터·초기화·쪽 넘김은 `navigate`(replace)에 `refetch_data_sources="ecommerceIdentityPolicies"` 를 붙여 목록 소스만 다시 받습니다(7.1.0~). 붙이지 않으면 설정 본체(`settings`, initLocal)까지 다시 받아 다른 구획의 설정 폼을 서버 값으로 되돌립니다. 목록만 다시 받을 때도 목록 영역 덮개가 뜨도록 `ecommerceIdentityPolicies` 를 `transition_overlay` 의 `wait_for` 에 둡니다. 잠금: `admin-ecommerce-settings-unsaved-tab-confirm.test.ts`. 필터 칸과 검색칸은 설정 폼 밖이라 자동 바인딩을 끄고(`:autoBinding="false"`), 입력 중인 검색어는 `_local.identityPolicySearch` 에 받아 두었다가 Enter 나 검색 단추가 쿼리로 싣습니다. 자동 바인딩되면 `form.filter.*` 에 남아 「변경 있음」을 켜고 탭 전환 확인 창을 잘못 띄웠습니다(7.1.0 G2 실측).

이 탭의 목록·켜고 끄기·추가·수정·삭제는 모듈 경로(`/api/modules/sirsoft-ecommerce/admin/identity-policies`)를 쓰고, 모듈 권한 `sirsoft-ecommerce.identity.policies.read`/`.update` 로 동작합니다(7.1.0~). 목록이 403 이면 데이터소스 `error_handling` 이 `_local.identityPoliciesLoadError='forbidden'` 을 남기고, computed `identityPoliciesNoReadPermission` 이 탭 내용 대신 「권한 없음」 안내(`identity_policies_no_permission`)를 띄웁니다. 수정 권한은 목록 응답 `abilities.can_update`·`can_delete` 로 읽어 읽기 전용 안내(`identity_policies_read_only_notice`)와 추가·토글·편집·삭제, 정책 창 [저장]·삭제 창 [삭제]를 잠급니다. 인증 제공자 선택지(`identityProviders`)는 권한이 필요 없는 공개 목록 `/api/identity/providers` 에서 받습니다. 잠금: `admin-ecommerce-identity-policy-gate.test.ts`.

### 레이아웃 주석과 클레임 모달의 입력 전달

새 이벤트 블록(`<on>`)·모달 참조(`<modal src>`)를 만들면 같은 변경에서 설명 주석을 단다 — 이벤트 블록은 `<on>` 앞이 아니라 **첫 액션 앞**에 쓴다(`<on>` 앞 주석은 컴파일 에러). 주석 주입(S21)이 완료로 선언된 뒤라 빈 자리가 생기면 `CommentAnchorStageLedgerTest` 가 red 가 된다. 주석은 산출 JSON 의 `comment` 키로만 들어가고 공개 서빙에서 제거되므로 화면 동작은 바뀌지 않는다.

관리자 클레임 상세의 창(진행 방식 전환·대리 철회·신청 내용 수정·환불 실행)은 입력값을 `_global` 에 모은다 — 모달은 부모 `_local` 을 읽지 못하므로, 여는 핸들러가 대상 신청서를 `_global` 문맥에 두고 실행 핸들러가 거기서 읽는다.

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 218개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `updateProductField` | `sirsoft-ecommerce.updateProductField` |
| `updateOptionField` | `sirsoft-ecommerce.updateOptionField` |
| `calculateCurrencyPrices` | `sirsoft-ecommerce.calculateCurrencyPrices` |
| `initPreferredCurrency` | `sirsoft-ecommerce.initPreferredCurrency` |
| `initPreferredShippingCountry` | `sirsoft-ecommerce.initPreferredShippingCountry` |
| `setDateRange` | `sirsoft-ecommerce.setDateRange` |
| `setDefaultOption` | `sirsoft-ecommerce.setDefaultOption` |
| `toggleOption` | `sirsoft-ecommerce.toggleOption` |
| `toggleProductOptions` | `sirsoft-ecommerce.toggleProductOptions` |
| `toggleAllOptionsInRow` | `sirsoft-ecommerce.toggleAllOptionsInRow` |
| `getProductOptionStates` | `sirsoft-ecommerce.getProductOptionStates` |
| `syncProductSelection` | `sirsoft-ecommerce.syncProductSelection` |
| `loadExpandedOptions` | `sirsoft-ecommerce.loadExpandedOptions` |
| `retryExpandedOptions` | `sirsoft-ecommerce.retryExpandedOptions` |
| `generateCopyProductCode` | `sirsoft-ecommerce.generateCopyProductCode` |
| `copyProduct` | `sirsoft-ecommerce.copyProduct` |
| `selectCategory` | `sirsoft-ecommerce.selectCategory` |
| `selectCategoryMobile` | `sirsoft-ecommerce.selectCategoryMobile` |
| `addCategoryToSelection` | `sirsoft-ecommerce.addCategoryToSelection` |
| `removeCategoryFromSelection` | `sirsoft-ecommerce.removeCategoryFromSelection` |
| `getCategoryBreadcrumb` | `sirsoft-ecommerce.getCategoryBreadcrumb` |
| `validateCategoryPath` | `sirsoft-ecommerce.validateCategoryPath` |
| `getBrandName` | `sirsoft-ecommerce.getBrandName` |
| `getBrandDescription` | `sirsoft-ecommerce.getBrandDescription` |
| `updatePrice` | `sirsoft-ecommerce.updatePrice` |
| `calculateTotalOptionStock` | `sirsoft-ecommerce.calculateTotalOptionStock` |
| `validatePriceRelation` | `sirsoft-ecommerce.validatePriceRelation` |
| `addOptionInput` | `sirsoft-ecommerce.addOptionInput` |
| `removeOptionInput` | `sirsoft-ecommerce.removeOptionInput` |
| `updateOptionInput` | `sirsoft-ecommerce.updateOptionInput` |
| `generateOptions` | `sirsoft-ecommerce.generateOptions` |
| `deleteOption` | `sirsoft-ecommerce.deleteOption` |
| `applyOptionAddTool` | `sirsoft-ecommerce.applyOptionAddTool` |
| `addRequiredItem` | `sirsoft-ecommerce.addRequiredItem` |
| `updateRequiredItem` | `sirsoft-ecommerce.updateRequiredItem` |
| `removeRequiredItem` | `sirsoft-ecommerce.removeRequiredItem` |
| `reorderRequiredItems` | `sirsoft-ecommerce.reorderRequiredItems` |
| `addAdditionalOption` | `sirsoft-ecommerce.addAdditionalOption` |
| `updateAdditionalOption` | `sirsoft-ecommerce.updateAdditionalOption` |
| `removeAdditionalOption` | `sirsoft-ecommerce.removeAdditionalOption` |
| `reorderAdditionalOptions` | `sirsoft-ecommerce.reorderAdditionalOptions` |
| `clearAdditionalOptions` | `sirsoft-ecommerce.clearAdditionalOptions` |
| `addAdditionalOptionValue` | `sirsoft-ecommerce.addAdditionalOptionValue` |
| `updateAdditionalOptionValue` | `sirsoft-ecommerce.updateAdditionalOptionValue` |
| `removeAdditionalOptionValue` | `sirsoft-ecommerce.removeAdditionalOptionValue` |
| `updateMultilingualTagValue` | `sirsoft-ecommerce.updateMultilingualTagValue` |
| `cancelMultilingualTag` | `sirsoft-ecommerce.cancelMultilingualTag` |
| `saveMultilingualTag` | `sirsoft-ecommerce.saveMultilingualTag` |
| `setThumbnail` | `sirsoft-ecommerce.setThumbnail` |
| `reorderImages` | `sirsoft-ecommerce.reorderImages` |
| `updateDescription` | `sirsoft-ecommerce.updateDescription` |
| `confirmSelectNoticeTemplate` | `sirsoft-ecommerce.confirmSelectNoticeTemplate` |
| `selectNoticeTemplate` | `sirsoft-ecommerce.selectNoticeTemplate` |
| `updateNoticeItem` | `sirsoft-ecommerce.updateNoticeItem` |
| `removeNoticeItem` | `sirsoft-ecommerce.removeNoticeItem` |
| `reorderNoticeItems` | `sirsoft-ecommerce.reorderNoticeItems` |
| `fillNoticeWithValue` | `sirsoft-ecommerce.fillNoticeWithValue` |
| `switchNoticeMode` | `sirsoft-ecommerce.switchNoticeMode` |
| `updateNewTemplateName` | `sirsoft-ecommerce.updateNewTemplateName` |
| `addNoticeItem` | `sirsoft-ecommerce.addNoticeItem` |
| `updateNoticeItemName` | `sirsoft-ecommerce.updateNoticeItemName` |
| `saveAsNoticeTemplate` | `sirsoft-ecommerce.saveAsNoticeTemplate` |
| `confirmSaveNoticeTemplate` | `sirsoft-ecommerce.confirmSaveNoticeTemplate` |
| `fillTemplateFieldsWithDetailReference` | `sirsoft-ecommerce.fillTemplateFieldsWithDetailReference` |
| `fillNoticeItemsWithDetailReference` | `sirsoft-ecommerce.fillNoticeItemsWithDetailReference` |
| `toggleLabel` | `sirsoft-ecommerce.toggleLabel` |
| `generateProductCode` | `sirsoft-ecommerce.generateProductCode` |
| `getShippingPolicyInfo` | `sirsoft-ecommerce.getShippingPolicyInfo` |
| `getCommonInfoContent` | `sirsoft-ecommerce.getCommonInfoContent` |
| `updateShoppingIntegration` | `sirsoft-ecommerce.updateShoppingIntegration` |
| `updateShippingType` | `sirsoft-ecommerce.updateShippingType` |
| `updateIdentificationCode` | `sirsoft-ecommerce.updateIdentificationCode` |
| `openLabelPeriodModal` | `sirsoft-ecommerce.openLabelPeriodModal` |
| `saveLabelPeriod` | `sirsoft-ecommerce.saveLabelPeriod` |
| `removeLabelPeriod` | `sirsoft-ecommerce.removeLabelPeriod` |
| `setDefaultShippingPolicy` | `sirsoft-ecommerce.setDefaultShippingPolicy` |
| `setLabelDatePreset` | `sirsoft-ecommerce.setLabelDatePreset` |
| `toggleDefaultShippingPolicy` | `sirsoft-ecommerce.toggleDefaultShippingPolicy` |
| `toggleLabelAssignment` | `sirsoft-ecommerce.toggleLabelAssignment` |
| `saveLabelSettings` | `sirsoft-ecommerce.saveLabelSettings` |
| `deleteLabel` | `sirsoft-ecommerce.deleteLabel` |
| `updateLabelPeriodInline` | `sirsoft-ecommerce.updateLabelPeriodInline` |
| `setLabelDatePresetInline` | `sirsoft-ecommerce.setLabelDatePresetInline` |
| `confirmUncheckLabel` | `sirsoft-ecommerce.confirmUncheckLabel` |
| `removeDescriptionLocale` | `sirsoft-ecommerce.removeDescriptionLocale` |
| `showAddLocaleModal` | `sirsoft-ecommerce.showAddLocaleModal` |
| `addDescriptionLocale` | `sirsoft-ecommerce.addDescriptionLocale` |
| `setDefaultOptionFromGrid` | `sirsoft-ecommerce.setDefaultOptionFromGrid` |
| `addOptionRow` | `sirsoft-ecommerce.addOptionRow` |
| `updateFormOptionField` | `sirsoft-ecommerce.updateFormOptionField` |
| `recalculateOptionPriceAdjustments` | `sirsoft-ecommerce.recalculateOptionPriceAdjustments` |
| `bulkUpdate` | `sirsoft-ecommerce.bulkUpdate` |
| `buildConfirmData` | `sirsoft-ecommerce.buildConfirmData` |
| `buildOrderColumns` | `sirsoft-ecommerce.buildOrderColumns` |
| `toggleArrayValue` | `sirsoft-ecommerce.toggleArrayValue` |
| `syncOrderSelection` | `sirsoft-ecommerce.syncOrderSelection` |
| `buildOrderBulkConfirmData` | `sirsoft-ecommerce.buildOrderBulkConfirmData` |
| `executeOrderBulkAction` | `sirsoft-ecommerce.executeOrderBulkAction` |
| `downloadFile` | `sirsoft-ecommerce.downloadFile` |
| `saveVisibleColumns` | `sirsoft-ecommerce.saveVisibleColumns` |
| `loadVisibleColumns` | `sirsoft-ecommerce.loadVisibleColumns` |
| `loadVisibleFilters` | `sirsoft-ecommerce.loadVisibleFilters` |
| `buildClaimBulkConfirmData` | `sirsoft-ecommerce.buildClaimBulkConfirmData` |
| `executeClaimBulkAction` | `sirsoft-ecommerce.executeClaimBulkAction` |
| `openClaimCompleteModal` | `sirsoft-ecommerce.openClaimCompleteModal` |
| `toggleClaimCompleteStock` | `sirsoft-ecommerce.toggleClaimCompleteStock` |
| `toggleClaimCompleteStockAll` | `sirsoft-ecommerce.toggleClaimCompleteStockAll` |
| `setClaimCompleteRefundRequest` | `sirsoft-ecommerce.setClaimCompleteRefundRequest` |
| `executeClaimCompletion` | `sirsoft-ecommerce.executeClaimCompletion` |
| `clearClaimCompleteTimers` | `sirsoft-ecommerce.clearClaimCompleteTimers` |
| `openClaimDetailComplete` | `sirsoft-ecommerce.openClaimDetailComplete` |
| `syncClaimItemSelection` | `sirsoft-ecommerce.syncClaimItemSelection` |
| `openClaimItemQuantityConfirm` | `sirsoft-ecommerce.openClaimItemQuantityConfirm` |
| `updateClaimItemQuantity` | `sirsoft-ecommerce.updateClaimItemQuantity` |
| `updateClaimBulkTracking` | `sirsoft-ecommerce.updateClaimBulkTracking` |
| `executeClaimItemBulkStatus` | `sirsoft-ecommerce.executeClaimItemBulkStatus` |
| `executeClaimStatusTransition` | `sirsoft-ecommerce.executeClaimStatusTransition` |
| `storeClaimRefundRequest` | `sirsoft-ecommerce.storeClaimRefundRequest` |
| `refreshClaimAttachments` | `sirsoft-ecommerce.refreshClaimAttachments` |
| `clearClaimImages` | `sirsoft-ecommerce.clearClaimImages` |
| `selectClaimItemBulkStatus` | `sirsoft-ecommerce.selectClaimItemBulkStatus` |
| `selectClaimItemTrackingDirection` | `sirsoft-ecommerce.selectClaimItemTrackingDirection` |
| `openClaimItemTrackingOnly` | `sirsoft-ecommerce.openClaimItemTrackingOnly` |
| `deliverClaimTracking` | `sirsoft-ecommerce.deliverClaimTracking` |
| `openClaimExchangeModeConfirm` | `sirsoft-ecommerce.openClaimExchangeModeConfirm` |
| `setClaimExchangeMode` | `sirsoft-ecommerce.setClaimExchangeMode` |
| `openClaimWithdrawOnBehalf` | `sirsoft-ecommerce.openClaimWithdrawOnBehalf` |
| `withdrawClaimOnBehalf` | `sirsoft-ecommerce.withdrawClaimOnBehalf` |
| `openClaimRequestEdit` | `sirsoft-ecommerce.openClaimRequestEdit` |
| `updateClaimRequestOnBehalf` | `sirsoft-ecommerce.updateClaimRequestOnBehalf` |
| `restoreClaimStock` | `sirsoft-ecommerce.restoreClaimStock` |
| `toggleClaimCompleteUnreturned` | `sirsoft-ecommerce.toggleClaimCompleteUnreturned` |
| `setClaimCompleteUnreturnedReason` | `sirsoft-ecommerce.setClaimCompleteUnreturnedReason` |
| `saveClaimAdminMemo` | `sirsoft-ecommerce.saveClaimAdminMemo` |
| `saveClaimAmounts` | `sirsoft-ecommerce.saveClaimAmounts` |
| `openClaimRefundExecute` | `sirsoft-ecommerce.openClaimRefundExecute` |
| `executeClaimRefund` | `sirsoft-ecommerce.executeClaimRefund` |
| `formatRefundInputTotal` | `sirsoft-ecommerce.formatRefundInputTotal` |
| `initOrderDetailForm` | `sirsoft-ecommerce.initOrderDetailForm` |
| `toggleProductSelection` | `sirsoft-ecommerce.toggleProductSelection` |
| `toggleAllProducts` | `sirsoft-ecommerce.toggleAllProducts` |
| `buildOrderDetailBulkConfirmData` | `sirsoft-ecommerce.buildOrderDetailBulkConfirmData` |
| `processOrderDetailBulkChange` | `sirsoft-ecommerce.processOrderDetailBulkChange` |
| `saveAdminMemo` | `sirsoft-ecommerce.saveAdminMemo` |
| `updateChangeQuantity` | `sirsoft-ecommerce.updateChangeQuantity` |
| `setManualPaidOption` | `sirsoft-ecommerce.setManualPaidOption` |
| `openConfirmDepositModal` | `sirsoft-ecommerce.openConfirmDepositModal` |
| `confirmDeposit` | `sirsoft-ecommerce.confirmDeposit` |
| `initShippingPolicyForm` | `sirsoft-ecommerce.initShippingPolicyForm` |
| `addCountrySetting` | `sirsoft-ecommerce.addCountrySetting` |
| `removeCountrySetting` | `sirsoft-ecommerce.removeCountrySetting` |
| `switchCountryTab` | `sirsoft-ecommerce.switchCountryTab` |
| `updateCountryField` | `sirsoft-ecommerce.updateCountryField` |
| `onChargePolicyChange` | `sirsoft-ecommerce.onChargePolicyChange` |
| `addRangeTier` | `sirsoft-ecommerce.addRangeTier` |
| `removeRangeTier` | `sirsoft-ecommerce.removeRangeTier` |
| `updateRangeTierField` | `sirsoft-ecommerce.updateRangeTierField` |
| `validateRangeTiers` | `sirsoft-ecommerce.validateRangeTiers` |
| `addExtraFeeRow` | `sirsoft-ecommerce.addExtraFeeRow` |
| `removeExtraFeeRow` | `sirsoft-ecommerce.removeExtraFeeRow` |
| `applyExtraFeeTemplate` | `sirsoft-ecommerce.applyExtraFeeTemplate` |
| `updateUnitValue` | `sirsoft-ecommerce.updateUnitValue` |
| `addApiRequestField` | `sirsoft-ecommerce.addApiRequestField` |
| `updateApiRequestField` | `sirsoft-ecommerce.updateApiRequestField` |
| `removeApiRequestField` | `sirsoft-ecommerce.removeApiRequestField` |
| `toggleApiRequestField` | `sirsoft-ecommerce.toggleApiRequestField` |
| `updateApiConfigField` | `sirsoft-ecommerce.updateApiConfigField` |
| `updateApiFieldMap` | `sirsoft-ecommerce.updateApiFieldMap` |
| `testShippingApi` | `sirsoft-ecommerce.testShippingApi` |
| `updateExtraFeeField` | `sirsoft-ecommerce.updateExtraFeeField` |
| `updateCancelQuantity` | `sirsoft-ecommerce.updateCancelQuantity` |
| `estimateRefundAmount` | `sirsoft-ecommerce.estimateRefundAmount` |
| `changeRefundPriority` | `sirsoft-ecommerce.changeRefundPriority` |
| `executeCancelOrder` | `sirsoft-ecommerce.executeCancelOrder` |
| `clearCancelOrderTimers` | `sirsoft-ecommerce.clearCancelOrderTimers` |
| `clearAdminClaimRequestTimers` | `sirsoft-ecommerce.clearAdminClaimRequestTimers` |
| `openAdminClaimRequest` | `sirsoft-ecommerce.openAdminClaimRequest` |
| `setAdminClaimRequestField` | `sirsoft-ecommerce.setAdminClaimRequestField` |
| `setAdminClaimRequestRow` | `sirsoft-ecommerce.setAdminClaimRequestRow` |
| `submitAdminClaimRequest` | `sirsoft-ecommerce.submitAdminClaimRequest` |
| `toggleItemSelection` | `sirsoft-ecommerce.toggleItemSelection` |
| `toggleSelectAllItems` | `sirsoft-ecommerce.toggleSelectAllItems` |
| `confirmOrderOption` | `sirsoft-ecommerce.confirmOrderOption` |
| `changeShippingAddress` | `sirsoft-ecommerce.changeShippingAddress` |
| `submitReview` | `sirsoft-ecommerce.submitReview` |
| `initClaimApply` | `sirsoft-ecommerce.initClaimApply` |
| `toggleClaimItem` | `sirsoft-ecommerce.toggleClaimItem` |
| `toggleClaimSelectAll` | `sirsoft-ecommerce.toggleClaimSelectAll` |
| `updateClaimQuantity` | `sirsoft-ecommerce.updateClaimQuantity` |
| `setClaimReason` | `sirsoft-ecommerce.setClaimReason` |
| `setClaimChargeMethod` | `sirsoft-ecommerce.setClaimChargeMethod` |
| `setClaimPaymentMethod` | `sirsoft-ecommerce.setClaimPaymentMethod` |
| `startClaimRedeliveryEdit` | `sirsoft-ecommerce.startClaimRedeliveryEdit` |
| `applyClaimRedelivery` | `sirsoft-ecommerce.applyClaimRedelivery` |
| `resetClaimRedelivery` | `sirsoft-ecommerce.resetClaimRedelivery` |
| `openClaimChangeProduct` | `sirsoft-ecommerce.openClaimChangeProduct` |
| `setClaimChangeProductMode` | `sirsoft-ecommerce.setClaimChangeProductMode` |
| `applyClaimChangeProduct` | `sirsoft-ecommerce.applyClaimChangeProduct` |
| `estimateClaim` | `sirsoft-ecommerce.estimateClaim` |
| `submitClaim` | `sirsoft-ecommerce.submitClaim` |
| `clearClaimApplyTimers` | `sirsoft-ecommerce.clearClaimApplyTimers` |
| `changeClaimRefundPriority` | `sirsoft-ecommerce.changeClaimRefundPriority` |
| `setClaimRefundAccountField` | `sirsoft-ecommerce.setClaimRefundAccountField` |
| `toggleClaimComparison` | `sirsoft-ecommerce.toggleClaimComparison` |
| `claimApplyOrderMissing` | `sirsoft-ecommerce.claimApplyOrderMissing` |
| `initClaimEdit` | `sirsoft-ecommerce.initClaimEdit` |
| `saveClaimEdit` | `sirsoft-ecommerce.saveClaimEdit` |
| `setClaimImageFiles` | `sirsoft-ecommerce.setClaimImageFiles` |
| `removeClaimImage` | `sirsoft-ecommerce.removeClaimImage` |
| `openClaimWithdraw` | `sirsoft-ecommerce.openClaimWithdraw` |
| `closeClaimWithdraw` | `sirsoft-ecommerce.closeClaimWithdraw` |
| `withdrawClaim` | `sirsoft-ecommerce.withdrawClaim` |
| `payClaimOutstanding` | `sirsoft-ecommerce.payClaimOutstanding` |
| `setUserFilterExt` | `sirsoft-ecommerce.setUserFilterExt` |
| `initCategoryFromUrl` | `sirsoft-ecommerce.initCategoryFromUrl` |
| `initBrandFromUrl` | `sirsoft-ecommerce.initBrandFromUrl` |
| `initCommonInfoFromUrl` | `sirsoft-ecommerce.initCommonInfoFromUrl` |
| `initNoticeFromUrl` | `sirsoft-ecommerce.initNoticeFromUrl` |
<!-- @generated:handlers END -->

<!-- @intent START -->
핸들러 201개는 레이아웃 JSON 에서 `sirsoft-ecommerce.{이름}` 으로 부릅니다. 관리자 화면 전용이
아니라 **템플릿의 방문자 화면도 이 핸들러를 씁니다** — `toggleItemSelection` ·
`toggleSelectAllItems` · `initClaimApply` · `estimateClaim` · `submitClaim` · `submitReview` ·
`changeShippingAddress` · `confirmOrderOption` 처럼 마이페이지·비회원 화면이 부르는 것들이 그
무리입니다(옛 주문 취소 모달 핸들러 8종 — `initUserCancelItems` · `estimateUserRefund` 등 — 은
1.3.0 에서 모달과 함께 삭제됐고, 상품 선택 2종은 `orderItemSelectionHandlers.ts` 로 옮겨 그대로
제공합니다). 그래서 이 핸들러들의 이름·시그니처는 템플릿과의 계약이며, 바꾸면 템플릿 화면이
조용히 무반응이 됩니다.

상품 선택 2종은 `claimableOptions`(레이아웃이 넘기는 `claimable.data.options`)를 받아, 행 체크박스
잠금과 **같은 술어**(취소되지 않음 + 열린 클레임 유형 1개 이상)로 고릅니다 — 넘기지 않으면 데이터소스
store 를, 그것도 없으면 취소 여부만 봅니다. 구매자 신청서 핸들러(`claimApplyHandlers.ts`)의 안내 문구는
모듈 키 `sirsoft-ecommerce.user.claim_apply.*` 로 찾고, 번역이 없어 키가 그대로 돌아오면 폴백 문구로
떨어집니다(템플릿 키에 기대지 않습니다).
환불 계좌 세 칸은 `setClaimRefundAccountField` 를 거칩니다 — 등록 계좌 프리필이 가린 모양(`masked: true`)이면
서버가 세 칸 모두 가린 모양 그대로일 때만 원문으로 되돌리므로, 한 칸을 고치는 순간 나머지 가린 칸을 비우고
별표가 섞인 계좌는 제출하지 않습니다. 첨부·철회·추가결제 핸들러(`claimImageHandlers.ts` · `claimListHandlers.ts`)도
같은 규칙으로 `sirsoft-ecommerce.user.claim.*` 를 `claimText()` 로 찾습니다 — `G7Core.t` 는 번역이
없으면 키 문자열을 돌려주므로 `t(...) ?? 폴백` 은 폴백까지 가지 않습니다.
관리자 직권 접수 창(`adminClaimRequestHandlers.ts`)은 5xx 응답의 본문 문구를 사유로 쓰지 않습니다 — 운영 환경의
500 본문은 `Server Error` 같은 프레임워크 원문이라, 미리보기는 「청구액을 계산하지 못했습니다.」, 접수는
「접수하지 못했습니다.」 폴백 문구로 떨어집니다. 4xx 의 서버 사유는 그대로 띄웁니다. 주문 목록 일괄 변경
(`orderHandlers.ts` `executeOrderBulkAction`)도 같은 규칙으로, 5xx·전송 실패는 axios 원문(`Request failed with status code 500`·
`Network Error`) 대신 「일괄 처리에 실패했습니다.」 로 떨어집니다.

역할별로 네 무리입니다:

| 무리 | 예 | 하는 일 |
|---|---|---|
| 상품 편집 | `updateProductField` · `toggleOption` · `loadExpandedOptions` | 옵션이 많은 상품 폼의 부분 상태 갱신 |
| 통화 | `calculateCurrencyPrices` · `initPreferredCurrency` | 표시 통화 전환과 통화별 가격 재계산. 원화 표기(`formatCurrency`)는 서버 `messages.currency.prefix/suffix.KRW` 와 같은 구성의 `currency.krw_prefix`·`krw_suffix` 번역을 따른다 — 문구에 금액과 단위를 붙인 문형을 두지 않는다 |
| 취소·환불 | `updateCancelQuantity` · `estimateRefundAmount` · `changeRefundPriority` | 옵션 단위 취소 수량 조정과 예상 환불액 조회 (관리자·구매자 두 벌) |
| 배송정책·주소 | `testShippingApi` · `updateExtraFeeField` · `changeShippingAddress` | 외부 배송비 API 시험 호출과 주소 변경 |
| 클레임 | `claimHandlers.ts` · `claimListHandlers.ts` · `claimApplyHandlers.ts` · `claimImageHandlers.ts` 의 핸들러 | 관리자 일괄 전이·완료 모달, **신청서 단위 전이**(`executeClaimStatusTransition`), 환불신청서 수동 접수(`storeClaimRefundRequest`), 운영자 대리 첨부(업로드는 `composite/FileUploader` 가 직접 하고 핸들러는 `refreshClaimAttachments` · `clearClaimImages` 로 목록만 되읽는다), 구매자 신청서 작성(교환 상품 선택·실시간 요약)·첨부 업로드 — 템플릿 화면도 이 핸들러를 쓴다 |

취소·환불 축에서 유의할 계약 둘이 있습니다(#599 후속).

- **접수 후 이동 주소는 서버가 정합니다.** `submitClaim` 은 응답에 실린 신청서의 `claim_type`
  으로 `successPath` 의 `type` 을 덮어씁니다(`resolveClaimSuccessPath`). 화면이 주문 전체 기준
  값으로 추측하면 분기가 섞인 주문에서 **접수한 탭과 이동한 탭이 갈립니다** — 접수는 됐는데
  간 탭에는 아무것도 없는 화면이 그것이었습니다.
- **관리자 취소 모달은 `auto_advance_refund` 를 보냅니다**(기본 꺼짐). 종전의 `cancelPg` 는
  없앴습니다 — 끈 채로 카드 취소를 하면 PG 를 부르지 않고도 환불이 완료로 찍혀 고객 카드는
  그대로인데 장부만 환불완료가 됐습니다. 이 선택은 모달을 열 때와 성공한 뒤 **꺼진 값으로 되돌립니다** — 남기면 같은
  화면의 다음 취소가 의식하지 못한 자동 환불이 됩니다. `executeCancelOrder` 는 셸이 넘긴
  `canViewClaims`(orderClaims 가 403 으로 unavailable 이면 false)로 방금 만든 신청서 상세로 갈지
  정합니다 — 클레임 권한이 없는 운영자를 보내면 403 화면에 떨어집니다.
- **관리자 첨부 일괄 정리(`clearClaimImages`)는 확인 모달에서만 부릅니다.** 사유 카드 버튼은
  `_global.claimImagesClearContext` 에 대상 식별 축과 장수를 담아 `modal_claim_images_clear` 를 열
  뿐이고, 모달의 확인 버튼이 핸들러를 부릅니다(고객 증빙까지 되돌릴 수 없게 지워진다).
- **수정 화면의 첨부는 `add_image_ids` 로 되돌려 보내지 않습니다.** `add_image_ids` 가 받는
  것은 **아직 신청서에 귀속되지 않은**(주문에 임시로 묶인) 첨부뿐입니다. 수정 화면은 이미
  귀속된 첨부로 목록을 채워 열리므로(`buildClaimEditImages()` 가 `attached: true` 를 세웁니다),
  `uploadPendingClaimFiles()` 는 그 표식이 선 것을 빼고 돌려줍니다. 빼지 않으면 서버가 귀속분을
  "이 주문의 미귀속 첨부" 로 찾다가 실패해 저장 전체가 422 로 떨어지고, 첨부가 한 장이라도 있는
  신청서는 **사유조차 고칠 수 없게** 됩니다. 귀속분의 삭제는 `remove_image_ids` 가 맡습니다.

관리자 화면이 쓰는 범용에 가까운 동작도 이 모듈이 직접 둡니다 — 옵션값 다국어 태그 편집 창의
`updateMultilingualTagValue` · `cancelMultilingualTag` · `saveMultilingualTag`(편집 중 값은
`_global.multilingualTagEdit`, 저장은 창을 연 상품 폼 `_local` 의 `statePath` 경로)와 파일 응답을 토큰을
실어 받아 저장하는 `downloadFile`(주문 목록 엑셀 내려받기 창) 입니다. 같은 이름을 관리자 템플릿이
등록해 두었거나 어디에도 없었는데, 이 모듈 화면은 **어느 관리자 템플릿으로 그려지든** 같게 동작해야
하므로 템플릿 핸들러에 기대지 않습니다. 코어 `apiCall` 은 응답을 JSON 으로만 읽어 파일을 넘겨주지
못하므로 파일 내려받기는 요청과 저장을 `downloadFile` 한 액션이 합니다.

핸들러 TS 를 고치면 **빌드가 필요합니다** — `php artisan module:build` 후
`module:update --force`. 커밋되는 `dist/` 는 배포 산출물이므로 `--production` 으로 굽고
`sourceMappingURL` 이 남지 않아야 합니다.

관리자 화면이 「오늘」을 만드는 핸들러(`setDateRange`·`setLabelDatePreset`·`setLabelDatePresetInline`)는 **계정
시간대**(로그인한 회원의 시간대 → 없으면 사이트 기본 시간대)의 날짜를 씁니다(`resources/js/utils/timezoneDate.ts`).
`toISOString()`(UTC)이나 브라우저 날짜로 만들면 서버가 해석하는 하루와 어긋납니다. 대시보드 「오늘」 배지의 주문 목록
링크만은 배지가 센 하루와 같은 **사이트 기본 시간대**의 날짜를 싣습니다. 배지 숫자는 서버가 그 링크와 같은 목록 조건
(`EcommerceDashboardService::BADGE_LIST_FILTERS` — 상태 조건, 반품은 `claim_return_statuses[]=completed`)으로 센 주문
수라, 링크의 상태 조건을 바꾸면 서버 표도 함께 바꿉니다.

그 링크는 `date_basis=site` 도 함께 실어, 주문 목록이 그 날짜를 사이트 기본 시간대의 하루로 거르게 합니다(목록의
기본은 보는 사람의 하루라, 빼면 계정 시간대가 사이트와 다른 운영자에게 배지 1건 → 목록 0건이 됩니다). 주문 목록은
이 인자를 필터 상태 `dateBasis` 로 들고 있다가 검색할 때 다시 싣고, 날짜 종류·시작일·종료일·빠른 기간·프리셋·초기화가
그 상태를 비워 보는 사람의 하루로 돌아갑니다. 날짜와 무관한 조건만 바꿔 검색하면 사이트 하루가 유지됩니다. 인자가
걸린 동안 날짜 필터 줄에 사이트 시간대 기준이라는 안내(`admin.order.filter.site_day_notice`)를 보입니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftEcommerce` |
| 재등록 진입점 | `initModule()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`window.__SirsoftEcommerce.initModule()` 이 재등록 진입점입니다. 로케일을 전환하면 코어가 이
함수를 다시 불러 핸들러를 재등록하는데, **이 함수가 없거나 이름이 다르면 로케일 전환 직후
이 모듈의 액션 160개가 전부 무반응이 됩니다** — 오류도 토스트도 없이 버튼만 동작하지 않습니다.

그래서 이 진입점은 **핸들러 재등록만** 수행합니다. 1회성 부팅 작업(초기 상태 시드·전역 이벤트
구독 등)을 여기 넣으면 로케일을 바꿀 때마다 다시 실행되어 상태가 초기화되거나 리스너가
중복 등록됩니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/css/module.css` | 빌드 산출물 (커밋 대상) |
| `dist/js/module.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
로딩 전략이 `global` 이라 이 모듈의 JS·CSS 는 **모든 페이지에서 로드**됩니다. 관리자 화면
전용이 아니라 템플릿의 방문자 화면도 이 모듈의 핸들러를 쓰기 때문입니다. `priority: 100` 은
확장 번들 안에서의 실행 순서로, 다른 확장이 이보다 먼저 나가야 한다면 그쪽이 더 작은 값을
선언합니다 — 특정 확장 이름을 지목하는 분기를 두지 않는 것이 규칙입니다.

`dist/` 는 **커밋되는 배포 산출물**입니다. 소스(`resources/js/**`)를 고치면 `--production`
으로 다시 굽고 그 결과를 함께 커밋합니다. 새 소스 리터럴이 `dist/` 에 없으면 stale 빌드이며,
브라우저가 받는 것은 커밋된 `dist/` 이므로 소스만 고친 변경은 사이트에 반영되지 않습니다.

구동에 필요한 제3자 자산은 외부 CDN 에서 받지 않고 확장이 동봉합니다. CDN 도달 실패는 예외도
서버 로그도 남기지 않고 화면 기능만 조용히 사라지기 때문입니다. 자산 URL 을 문자열로 조립하지
않고 `G7Core.asset.module` 을 쓰는 것도 같은 이유입니다 — 확장자를 정적 location 이 가로채는
서버에서는 조립한 URL 만 404 가 됩니다.
<!-- @intent END -->
