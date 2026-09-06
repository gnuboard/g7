# 이커머스 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 206개 (루트: `resources/layouts`).

| 그룹 | 개수 |
|---|---|
| `admin` | 206개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `admin_ecommerce_brand_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_category_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_deposit_index` | `admin` | 화면 | `_admin_base` |
| `admin_ecommerce_excel_download_index` | `admin` | 화면 | `_admin_base` |
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
| `_partial_payment_info` | `admin` | partial | - |
| `_tab_claim_exchange` | `admin` | partial | - |
| `_tab_claim_refund` | `admin` | partial | - |
| `_tab_claim_return` | `admin` | partial | - |
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
| `_partial_preset_section` | `admin` | partial | - |
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
| `_currency_exchange_cards` | `admin` | partial | - |
| `_currency_exchange_table` | `admin` | partial | - |
| `_disable_international_shipping_modal` | `admin` | partial | - |
| `_modal_clear_seo_cache` | `admin` | partial | - |
| `_modal_identity_policy_delete` | `admin` | partial | - |
| `_modal_identity_policy_form` | `admin` | partial | - |
| `_modal_mail_template_edit` | `admin` | partial | - |
| `_modal_notification_definition_reset` | `admin` | partial | - |
| `_modal_notification_template_edit` | `admin` | partial | - |
| `_modal_notification_template_preview` | `admin` | partial | - |
| `_payment_methods_cards` | `admin` | partial | - |
| `_payment_methods_list` | `admin` | partial | - |
| `_refund_reason_cards` | `admin` | partial | - |
| `_refund_reason_section` | `admin` | partial | - |
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
| `_tab_review_settings` | `admin` | partial | - |
| `_tab_seo` | `admin` | partial | - |
| `_tab_shipping` | `admin` | partial | - |
| `_modal_extra_fee_template` | `admin` | partial | - |
| `_partial_basic_info` | `admin` | partial | - |
| `_partial_charge_settings` | `admin` | partial | - |
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

레이아웃 JSON 만 고쳤다면 빌드는 필요 없고 `php artisan module:update sirsoft-ecommerce --force`
로 활성 디렉토리에 반영합니다. 다만 **새로 쓴 Tailwind 클래스가 빌드된 CSS 에 없으면** 그
스타일만 조용히 빠지므로, 기존 레이아웃에 쓰이지 않던 클래스를 도입할 때는 확인이 필요합니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 160개 (정의: `resources/js/handlers/index.ts`).

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
| `initCategoryInfosFromProduct` | `sirsoft-ecommerce.initCategoryInfosFromProduct` |
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
| `uploadImages` | `sirsoft-ecommerce.uploadImages` |
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
| `updateActivityLogSort` | `sirsoft-ecommerce.updateActivityLogSort` |
| `updateActivityLogPerPage` | `sirsoft-ecommerce.updateActivityLogPerPage` |
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
| `toggleVisibleFilter` | `sirsoft-ecommerce.toggleVisibleFilter` |
| `syncOrderSelection` | `sirsoft-ecommerce.syncOrderSelection` |
| `handleOrderRowAction` | `sirsoft-ecommerce.handleOrderRowAction` |
| `processOrderBulkAction` | `sirsoft-ecommerce.processOrderBulkAction` |
| `buildOrderBulkConfirmData` | `sirsoft-ecommerce.buildOrderBulkConfirmData` |
| `executeOrderBulkAction` | `sirsoft-ecommerce.executeOrderBulkAction` |
| `downloadOrderExcel` | `sirsoft-ecommerce.downloadOrderExcel` |
| `saveVisibleColumns` | `sirsoft-ecommerce.saveVisibleColumns` |
| `loadVisibleColumns` | `sirsoft-ecommerce.loadVisibleColumns` |
| `loadVisibleFilters` | `sirsoft-ecommerce.loadVisibleFilters` |
| `handleProductRowAction` | `sirsoft-ecommerce.handleProductRowAction` |
| `initOrderDetailForm` | `sirsoft-ecommerce.initOrderDetailForm` |
| `toggleProductSelection` | `sirsoft-ecommerce.toggleProductSelection` |
| `toggleAllProducts` | `sirsoft-ecommerce.toggleAllProducts` |
| `buildOrderDetailBulkConfirmData` | `sirsoft-ecommerce.buildOrderDetailBulkConfirmData` |
| `processOrderDetailBulkChange` | `sirsoft-ecommerce.processOrderDetailBulkChange` |
| `saveAdminMemo` | `sirsoft-ecommerce.saveAdminMemo` |
| `updateChangeQuantity` | `sirsoft-ecommerce.updateChangeQuantity` |
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
| `toggleItemSelection` | `sirsoft-ecommerce.toggleItemSelection` |
| `toggleSelectAllItems` | `sirsoft-ecommerce.toggleSelectAllItems` |
| `initUserCancelItems` | `sirsoft-ecommerce.initUserCancelItems` |
| `toggleUserCancelItem` | `sirsoft-ecommerce.toggleUserCancelItem` |
| `toggleUserCancelSelectAll` | `sirsoft-ecommerce.toggleUserCancelSelectAll` |
| `updateUserCancelQuantity` | `sirsoft-ecommerce.updateUserCancelQuantity` |
| `estimateUserRefund` | `sirsoft-ecommerce.estimateUserRefund` |
| `changeUserRefundPriority` | `sirsoft-ecommerce.changeUserRefundPriority` |
| `executeUserCancelOrder` | `sirsoft-ecommerce.executeUserCancelOrder` |
| `clearUserCancelOrderTimers` | `sirsoft-ecommerce.clearUserCancelOrderTimers` |
| `confirmOrderOption` | `sirsoft-ecommerce.confirmOrderOption` |
| `changeShippingAddress` | `sirsoft-ecommerce.changeShippingAddress` |
| `submitReview` | `sirsoft-ecommerce.submitReview` |
| `initCategoryFromUrl` | `sirsoft-ecommerce.initCategoryFromUrl` |
| `initBrandFromUrl` | `sirsoft-ecommerce.initBrandFromUrl` |
| `initCommonInfoFromUrl` | `sirsoft-ecommerce.initCommonInfoFromUrl` |
| `initNoticeFromUrl` | `sirsoft-ecommerce.initNoticeFromUrl` |
<!-- @generated:handlers END -->

<!-- @intent START -->
핸들러 160개는 레이아웃 JSON 에서 `sirsoft-ecommerce.{이름}` 으로 부릅니다. 관리자 화면 전용이
아니라 **템플릿의 방문자 화면도 이 핸들러를 씁니다** — `initUserCancelItems` ·
`toggleUserCancelItem` · `estimateUserRefund` · `submitReview` · `changeShippingAddress` ·
`confirmOrderOption` 처럼 `User`/`user` 가 붙은 것들이 그 무리입니다. 그래서 이 핸들러들의
이름·시그니처는 템플릿과의 계약이며, 바꾸면 템플릿 화면이 조용히 무반응이 됩니다.

역할별로 네 무리입니다:

| 무리 | 예 | 하는 일 |
|---|---|---|
| 상품 편집 | `updateProductField` · `toggleOption` · `loadExpandedOptions` | 옵션이 많은 상품 폼의 부분 상태 갱신 |
| 통화 | `calculateCurrencyPrices` · `initPreferredCurrency` | 표시 통화 전환과 통화별 가격 재계산 |
| 취소·환불 | `updateCancelQuantity` · `estimateRefundAmount` · `changeRefundPriority` | 옵션 단위 취소 수량 조정과 예상 환불액 조회 (관리자·구매자 두 벌) |
| 배송정책·주소 | `testShippingApi` · `updateExtraFeeField` · `changeShippingAddress` | 외부 배송비 API 시험 호출과 주소 변경 |

핸들러 TS 를 고치면 **빌드가 필요합니다** — `php artisan module:build` 후
`module:update --force`. 커밋되는 `dist/` 는 배포 산출물이므로 `--production` 으로 굽고
`sourceMappingURL` 이 남지 않아야 합니다.
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
