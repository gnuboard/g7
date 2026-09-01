# 이커머스 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 508종 / 호출 지점 548곳. 이 중 508종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다. 훅 이름이 상수·변수로 조립된 호출이 5곳 있어 호출 위치가 표에 다 실리지 않을 수 있습니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `core.module_settings.after_save` | action | — | `src/Http/Controllers/Admin/EcommerceSettingsController.php:178` 외 2곳 |
| `sirsoft-ecommerce.adjustment.filter_restore_promotions` | filter | — | `src/Services/OrderAdjustmentService.php:375` |
| `sirsoft-ecommerce.admin.user_currency.changed` | action | — | `src/Services/UserCurrencyService.php:72` |
| `sirsoft-ecommerce.admin.user_shipping_country.changed` | action | — | `src/Services/UserShippingCountryService.php:74` |
| `sirsoft-ecommerce.brand.after_create` | action | — | `src/Services/BrandService.php:100` |
| `sirsoft-ecommerce.brand.after_delete` | action | — | `src/Services/BrandService.php:216` |
| `sirsoft-ecommerce.brand.after_list` | action | — | `src/Services/BrandService.php:45` |
| `sirsoft-ecommerce.brand.after_show` | action | — | `src/Services/BrandService.php:68` |
| `sirsoft-ecommerce.brand.after_toggle_status` | action | — | `src/Services/BrandService.php:175` |
| `sirsoft-ecommerce.brand.after_update` | action | — | `src/Services/BrandService.php:142` |
| `sirsoft-ecommerce.brand.before_create` | action | — | `src/Services/BrandService.php:83` |
| `sirsoft-ecommerce.brand.before_delete` | action | — | `src/Services/BrandService.php:209` |
| `sirsoft-ecommerce.brand.before_list` | action | — | `src/Services/BrandService.php:34` |
| `sirsoft-ecommerce.brand.before_show` | action | — | `src/Services/BrandService.php:59` |
| `sirsoft-ecommerce.brand.before_toggle_status` | action | — | `src/Services/BrandService.php:166` |
| `sirsoft-ecommerce.brand.before_update` | action | — | `src/Services/BrandService.php:123` |
| `sirsoft-ecommerce.brand.create_validation_rules` | filter | — | `src/Http/Requests/Admin/StoreBrandRequest.php:42` |
| `sirsoft-ecommerce.brand.filter_create_data` | filter | — | `src/Services/BrandService.php:86` |
| `sirsoft-ecommerce.brand.filter_list_query` | filter | — | `src/Services/BrandService.php:37` |
| `sirsoft-ecommerce.brand.filter_list_result` | filter | — | `src/Services/BrandService.php:42` |
| `sirsoft-ecommerce.brand.filter_show_result` | filter | — | `src/Services/BrandService.php:65` |
| `sirsoft-ecommerce.brand.filter_update_data` | filter | — | `src/Services/BrandService.php:129` |
| `sirsoft-ecommerce.brand.update_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateBrandRequest.php:40` |
| `sirsoft-ecommerce.calculation.after_final_result` | filter | — | `src/Services/OrderCalculationService.php:371` |
| `sirsoft-ecommerce.calculation.after_item_subtotals` | filter | — | `src/Services/OrderCalculationService.php:169` |
| `sirsoft-ecommerce.calculation.after_order_discount` | filter | — | `src/Services/OrderCalculationService.php:272` |
| `sirsoft-ecommerce.calculation.after_payment_amount` | filter | — | `src/Services/OrderCalculationService.php:295` |
| `sirsoft-ecommerce.calculation.after_points_earning` | filter | — | `src/Services/OrderCalculationService.php:324` |
| `sirsoft-ecommerce.calculation.after_points_usage` | filter | — | `src/Services/OrderCalculationService.php:309` |
| `sirsoft-ecommerce.calculation.after_product_discount` | filter | — | `src/Services/OrderCalculationService.php:193` |
| `sirsoft-ecommerce.calculation.after_shipping_discount` | filter | — | `src/Services/OrderCalculationService.php:249` |
| `sirsoft-ecommerce.calculation.after_shipping_fee` | filter | — | `src/Services/OrderCalculationService.php:228` |
| `sirsoft-ecommerce.calculation.after_tax_classification` | filter | — | `src/Services/OrderCalculationService.php:203` |
| `sirsoft-ecommerce.calculation.before_final_result` | filter | — | `src/Services/OrderCalculationService.php:335` |
| `sirsoft-ecommerce.calculation.before_item_subtotals` | filter | — | `src/Services/OrderCalculationService.php:162` |
| `sirsoft-ecommerce.calculation.before_order_discount` | filter | — | `src/Services/OrderCalculationService.php:259` |
| `sirsoft-ecommerce.calculation.before_payment_amount` | filter | — | `src/Services/OrderCalculationService.php:282` |
| `sirsoft-ecommerce.calculation.before_product_discount` | filter | — | `src/Services/OrderCalculationService.php:178` |
| `sirsoft-ecommerce.calculation.before_shipping_discount` | filter | — | `src/Services/OrderCalculationService.php:238` |
| `sirsoft-ecommerce.calculation.before_shipping_fee` | filter | — | `src/Services/OrderCalculationService.php:212` |
| `sirsoft-ecommerce.calculation.filter_promotions_snapshot` | filter | — | `src/Services/OrderAdjustmentService.php:778` 외 1곳 |
| `sirsoft-ecommerce.cart.after_add` | action | — | `src/Services/CartService.php:257` |
| `sirsoft-ecommerce.cart.after_change_option` | action | — | `src/Services/CartService.php:470` |
| `sirsoft-ecommerce.cart.after_delete` | action | — | `src/Services/CartService.php:503` |
| `sirsoft-ecommerce.cart.after_delete_all` | action | — | `src/Services/CartService.php:788` |
| `sirsoft-ecommerce.cart.after_delete_multiple` | action | — | `src/Services/CartService.php:535` |
| `sirsoft-ecommerce.cart.after_list` | action | — | `src/Services/CartService.php:76` |
| `sirsoft-ecommerce.cart.after_merge` | action | — | `src/Services/CartService.php:667` |
| `sirsoft-ecommerce.cart.after_reorder` | action | — | `src/Services/CartService.php:746` |
| `sirsoft-ecommerce.cart.after_update_quantity` | action | — | `src/Services/CartService.php:377` |
| `sirsoft-ecommerce.cart.before_add` | action | — | `src/Services/CartService.php:188` |
| `sirsoft-ecommerce.cart.before_change_option` | action | — | `src/Services/CartService.php:449` |
| `sirsoft-ecommerce.cart.before_delete` | action | — | `src/Services/CartService.php:497` |
| `sirsoft-ecommerce.cart.before_delete_all` | action | — | `src/Services/CartService.php:776` |
| `sirsoft-ecommerce.cart.before_delete_multiple` | action | — | `src/Services/CartService.php:518` |
| `sirsoft-ecommerce.cart.before_list` | action | — | `src/Services/CartService.php:64` |
| `sirsoft-ecommerce.cart.before_merge` | action | — | `src/Services/CartService.php:549` |
| `sirsoft-ecommerce.cart.before_reorder` | action | — | `src/Services/CartService.php:700` |
| `sirsoft-ecommerce.cart.before_update_quantity` | action | — | `src/Services/CartService.php:371` |
| `sirsoft-ecommerce.cart.bulk_add_validation_rules` | filter | — | `src/Http/Requests/Public/BulkAddToCartRequest.php:51` |
| `sirsoft-ecommerce.cart.change_option_validation_rules` | filter | — | `src/Http/Requests/Public/ChangeCartOptionRequest.php:46` |
| `sirsoft-ecommerce.cart.delete_items_validation_rules` | filter | — | `src/Http/Requests/Public/DeleteCartItemsRequest.php:35` |
| `sirsoft-ecommerce.cart.filter_add_data` | filter | — | `src/Services/CartService.php:190` |
| `sirsoft-ecommerce.cart.filter_list_result` | filter | — | `src/Services/CartService.php:74` |
| `sirsoft-ecommerce.cart.get_validation_rules` | filter | — | `src/Http/Requests/Public/GetCartRequest.php:35` |
| `sirsoft-ecommerce.cart.update_quantity_validation_rules` | filter | — | `src/Http/Requests/Public/UpdateCartQuantityRequest.php:37` |
| `sirsoft-ecommerce.cash_receipt.cancel` | filter | — | `src/Services/CashReceiptService.php:194` |
| `sirsoft-ecommerce.cash_receipt.issue` | filter | — | `src/Services/CashReceiptService.php:126` |
| `sirsoft-ecommerce.cash_receipt.registered_providers` | filter | — | `src/Services/EcommerceSettingsService.php:878` |
| `sirsoft-ecommerce.category-image.after_delete` | action | — | `src/Services/CategoryImageService.php:246` 외 1곳 |
| `sirsoft-ecommerce.category-image.after_reorder` | action | — | `src/Services/CategoryImageService.php:308` |
| `sirsoft-ecommerce.category-image.after_update` | action | — | `src/Services/CategoryImageService.php:382` |
| `sirsoft-ecommerce.category-image.after_upload` | action | — | `src/Services/CategoryImageService.php:126` |
| `sirsoft-ecommerce.category-image.before_delete` | action | — | `src/Services/CategoryImageService.php:222` 외 1곳 |
| `sirsoft-ecommerce.category-image.before_reorder` | action | — | `src/Services/CategoryImageService.php:303` |
| `sirsoft-ecommerce.category-image.before_update` | action | — | `src/Services/CategoryImageService.php:369` |
| `sirsoft-ecommerce.category-image.before_upload` | action | — | `src/Services/CategoryImageService.php:64` |
| `sirsoft-ecommerce.category-image.filter_reorder_validation_rules` | filter | — | `src/Http/Requests/Admin/ReorderCategoryImagesRequest.php:36` |
| `sirsoft-ecommerce.category-image.filter_update_data` | filter | — | `src/Services/CategoryImageService.php:372` |
| `sirsoft-ecommerce.category-image.filter_upload_file` | filter | — | `src/Services/CategoryImageService.php:67` |
| `sirsoft-ecommerce.category-image.filter_upload_validation_rules` | filter | — | `src/Http/Requests/Admin/UploadCategoryImageRequest.php:64` |
| `sirsoft-ecommerce.category.after_create` | action | — | `src/Services/CategoryService.php:176` |
| `sirsoft-ecommerce.category.after_delete` | action | — | `src/Services/CategoryService.php:287` |
| `sirsoft-ecommerce.category.after_list` | action | — | `src/Services/CategoryService.php:53` |
| `sirsoft-ecommerce.category.after_public_list` | action | — | `src/Services/CategoryService.php:73` |
| `sirsoft-ecommerce.category.after_public_show` | action | — | `src/Services/CategoryService.php:101` |
| `sirsoft-ecommerce.category.after_reorder` | action | — | `src/Services/CategoryService.php:438` |
| `sirsoft-ecommerce.category.after_show` | action | — | `src/Services/CategoryService.php:129` |
| `sirsoft-ecommerce.category.after_toggle_status` | action | — | `src/Services/CategoryService.php:386` |
| `sirsoft-ecommerce.category.after_update` | action | — | `src/Services/CategoryService.php:235` |
| `sirsoft-ecommerce.category.before_create` | action | — | `src/Services/CategoryService.php:144` |
| `sirsoft-ecommerce.category.before_delete` | action | — | `src/Services/CategoryService.php:274` |
| `sirsoft-ecommerce.category.before_list` | action | — | `src/Services/CategoryService.php:34` |
| `sirsoft-ecommerce.category.before_public_list` | action | — | `src/Services/CategoryService.php:67` |
| `sirsoft-ecommerce.category.before_public_show` | action | — | `src/Services/CategoryService.php:86` |
| `sirsoft-ecommerce.category.before_reorder` | action | — | `src/Services/CategoryService.php:399` |
| `sirsoft-ecommerce.category.before_show` | action | — | `src/Services/CategoryService.php:116` |
| `sirsoft-ecommerce.category.before_toggle_status` | action | — | `src/Services/CategoryService.php:379` |
| `sirsoft-ecommerce.category.before_update` | action | — | `src/Services/CategoryService.php:198` |
| `sirsoft-ecommerce.category.create_validation_rules` | filter | — | `src/Http/Requests/Admin/CreateCategoryRequest.php:73` |
| `sirsoft-ecommerce.category.filter_create_data` | filter | — | `src/Services/CategoryService.php:147` |
| `sirsoft-ecommerce.category.filter_list_query` | filter | — | `src/Services/CategoryService.php:37` |
| `sirsoft-ecommerce.category.filter_list_result` | filter | — | `src/Services/CategoryService.php:50` |
| `sirsoft-ecommerce.category.filter_public_list_result` | filter | — | `src/Services/CategoryService.php:71` |
| `sirsoft-ecommerce.category.filter_public_show_result` | filter | — | `src/Services/CategoryService.php:100` |
| `sirsoft-ecommerce.category.filter_show_result` | filter | — | `src/Services/CategoryService.php:126` |
| `sirsoft-ecommerce.category.filter_update_data` | filter | — | `src/Services/CategoryService.php:204` |
| `sirsoft-ecommerce.category.reorder_validation_rules` | filter | — | `src/Http/Requests/Admin/ReorderCategoriesRequest.php:50` |
| `sirsoft-ecommerce.category.update_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateCategoryRequest.php:77` |
| `sirsoft-ecommerce.checkout.before_payment` | action | — | `src/Http/Controllers/Traits/HandlesOrderCreation.php:84` |
| `sirsoft-ecommerce.checkout.filter_response_data` | filter | — | `src/Services/CheckoutDataService.php:96` |
| `sirsoft-ecommerce.checkout.update_validation_rules` | filter | — | `src/Http/Requests/Public/UpdateCheckoutRequest.php:55` |
| `sirsoft-ecommerce.checkout.validation_rules` | filter | — | `src/Http/Requests/Public/CheckoutRequest.php:56` |
| `sirsoft-ecommerce.claim_reason.after_create` | action | — | `src/Services/ClaimReasonService.php:135` |
| `sirsoft-ecommerce.claim_reason.after_delete` | action | — | `src/Services/ClaimReasonService.php:288` |
| `sirsoft-ecommerce.claim_reason.after_list` | action | — | `src/Services/ClaimReasonService.php:44` |
| `sirsoft-ecommerce.claim_reason.after_show` | action | — | `src/Services/ClaimReasonService.php:63` |
| `sirsoft-ecommerce.claim_reason.after_toggle_status` | action | — | `src/Services/ClaimReasonService.php:198` |
| `sirsoft-ecommerce.claim_reason.after_update` | action | — | `src/Services/ClaimReasonService.php:169` |
| `sirsoft-ecommerce.claim_reason.before_create` | action | — | `src/Services/ClaimReasonService.php:122` |
| `sirsoft-ecommerce.claim_reason.before_delete` | action | — | `src/Services/ClaimReasonService.php:282` |
| `sirsoft-ecommerce.claim_reason.before_list` | action | — | `src/Services/ClaimReasonService.php:36` |
| `sirsoft-ecommerce.claim_reason.before_show` | action | — | `src/Services/ClaimReasonService.php:57` |
| `sirsoft-ecommerce.claim_reason.before_toggle_status` | action | — | `src/Services/ClaimReasonService.php:190` |
| `sirsoft-ecommerce.claim_reason.before_update` | action | — | `src/Services/ClaimReasonService.php:157` |
| `sirsoft-ecommerce.claim_reason.filter_create_data` | filter | — | `src/Services/ClaimReasonService.php:124` |
| `sirsoft-ecommerce.claim_reason.filter_list_query` | filter | — | `src/Services/ClaimReasonService.php:38` |
| `sirsoft-ecommerce.claim_reason.filter_list_result` | filter | — | `src/Services/ClaimReasonService.php:42` |
| `sirsoft-ecommerce.claim_reason.filter_show_result` | filter | — | `src/Services/ClaimReasonService.php:62` |
| `sirsoft-ecommerce.claim_reason.filter_update_data` | filter | — | `src/Services/ClaimReasonService.php:159` |
| `sirsoft-ecommerce.coupon.after_bulk_status` | action | — | `src/Services/CouponService.php:258` |
| `sirsoft-ecommerce.coupon.after_create` | action | — | `src/Services/CouponService.php:132` |
| `sirsoft-ecommerce.coupon.after_delete` | action | — | `src/Services/CouponService.php:226` |
| `sirsoft-ecommerce.coupon.after_direct_issue` | action | — | `src/Services/CouponService.php:308` |
| `sirsoft-ecommerce.coupon.after_direct_issue_batch` | action | — | `src/Services/CouponService.php:317` |
| `sirsoft-ecommerce.coupon.after_issue_cancel` | action | — | `src/Services/CouponService.php:360` |
| `sirsoft-ecommerce.coupon.after_issues_list` | action | — | `src/Services/CouponService.php:388` |
| `sirsoft-ecommerce.coupon.after_list` | action | — | `src/Services/CouponService.php:51` |
| `sirsoft-ecommerce.coupon.after_show` | action | — | `src/Services/CouponService.php:80` |
| `sirsoft-ecommerce.coupon.after_update` | action | — | `src/Services/CouponService.php:192` |
| `sirsoft-ecommerce.coupon.before_bulk_status` | action | — | `src/Services/CouponService.php:248` |
| `sirsoft-ecommerce.coupon.before_create` | action | — | `src/Services/CouponService.php:95` |
| `sirsoft-ecommerce.coupon.before_delete` | action | — | `src/Services/CouponService.php:214` |
| `sirsoft-ecommerce.coupon.before_direct_issue` | action | — | `src/Services/CouponService.php:285` |
| `sirsoft-ecommerce.coupon.before_issue_cancel` | action | — | `src/Services/CouponService.php:348` |
| `sirsoft-ecommerce.coupon.before_issues_list` | action | — | `src/Services/CouponService.php:383` |
| `sirsoft-ecommerce.coupon.before_list` | action | — | `src/Services/CouponService.php:40` |
| `sirsoft-ecommerce.coupon.before_show` | action | — | `src/Services/CouponService.php:65` |
| `sirsoft-ecommerce.coupon.before_update` | action | — | `src/Services/CouponService.php:155` |
| `sirsoft-ecommerce.coupon.create_validation_rules` | filter | — | `src/Http/Requests/Admin/StoreCouponRequest.php:142` |
| `sirsoft-ecommerce.coupon.filter_create_data` | filter | — | `src/Services/CouponService.php:98` |
| `sirsoft-ecommerce.coupon.filter_list_query` | filter | — | `src/Services/CouponService.php:43` |
| `sirsoft-ecommerce.coupon.filter_list_result` | filter | — | `src/Services/CouponService.php:48` |
| `sirsoft-ecommerce.coupon.filter_show_result` | filter | — | `src/Services/CouponService.php:77` |
| `sirsoft-ecommerce.coupon.filter_update_data` | filter | — | `src/Services/CouponService.php:161` |
| `sirsoft-ecommerce.coupon.issues_list_validation_rules` | filter | — | `src/Http/Requests/Admin/CouponIssuesListRequest.php:37` |
| `sirsoft-ecommerce.coupon.restore` | action | — | `src/Services/OrderCancellationService.php:1072` |
| `sirsoft-ecommerce.coupon.update_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateCouponRequest.php:144` |
| `sirsoft-ecommerce.coupon.use` | action | — | `src/Services/OrderProcessingService.php:199` |
| `sirsoft-ecommerce.coupon.user_available_validation_rules` | filter | — | `src/Http/Requests/User/UserCouponAvailableRequest.php:35` |
| `sirsoft-ecommerce.coupon.user_downloadable_validation_rules` | filter | — | `src/Http/Requests/User/UserCouponDownloadableRequest.php:34` |
| `sirsoft-ecommerce.coupon.user_list_validation_rules` | filter | — | `src/Http/Requests/User/UserCouponListRequest.php:35` |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_create` | action | — | `src/Services/ExtraFeeTemplateService.php:273` |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_delete` | action | — | `src/Services/ExtraFeeTemplateService.php:199` |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_toggle_active` | action | — | `src/Services/ExtraFeeTemplateService.php:227` |
| `sirsoft-ecommerce.extra_fee_template.after_create` | action | — | `src/Services/ExtraFeeTemplateService.php:99` |
| `sirsoft-ecommerce.extra_fee_template.after_delete` | action | — | `src/Services/ExtraFeeTemplateService.php:151` |
| `sirsoft-ecommerce.extra_fee_template.after_read` | action | — | `src/Services/ExtraFeeTemplateService.php:61` |
| `sirsoft-ecommerce.extra_fee_template.after_toggle_active` | action | — | `src/Services/ExtraFeeTemplateService.php:172` |
| `sirsoft-ecommerce.extra_fee_template.after_update` | action | — | `src/Services/ExtraFeeTemplateService.php:130` |
| `sirsoft-ecommerce.extra_fee_template.before_bulk_create` | action | — | `src/Services/ExtraFeeTemplateService.php:261` |
| `sirsoft-ecommerce.extra_fee_template.before_bulk_delete` | action | — | `src/Services/ExtraFeeTemplateService.php:194` |
| `sirsoft-ecommerce.extra_fee_template.before_bulk_toggle_active` | action | — | `src/Services/ExtraFeeTemplateService.php:222` |
| `sirsoft-ecommerce.extra_fee_template.before_create` | action | — | `src/Services/ExtraFeeTemplateService.php:87` |
| `sirsoft-ecommerce.extra_fee_template.before_delete` | action | — | `src/Services/ExtraFeeTemplateService.php:146` |
| `sirsoft-ecommerce.extra_fee_template.before_toggle_active` | action | — | `src/Services/ExtraFeeTemplateService.php:167` |
| `sirsoft-ecommerce.extra_fee_template.before_update` | action | — | `src/Services/ExtraFeeTemplateService.php:116` |
| `sirsoft-ecommerce.extra_fee_template.filter_bulk_create_data` | filter | — | `src/Services/ExtraFeeTemplateService.php:264` |
| `sirsoft-ecommerce.extra_fee_template.filter_create_data` | filter | — | `src/Services/ExtraFeeTemplateService.php:90` |
| `sirsoft-ecommerce.extra_fee_template.filter_list_params` | filter | — | `src/Services/ExtraFeeTemplateService.php:33` |
| `sirsoft-ecommerce.extra_fee_template.filter_update_data` | filter | — | `src/Services/ExtraFeeTemplateService.php:122` |
| `sirsoft-ecommerce.inquiry.count_replies` | filter | — | `src/Listeners/ProductInquiryBoardListener.php:120` 외 1곳 |
| `sirsoft-ecommerce.inquiry.create` | filter | — | `src/Services/ProductInquiryService.php:277` 외 1곳 |
| `sirsoft-ecommerce.inquiry.delete` | filter | — | `src/Services/ProductInquiryService.php:477` 외 1곳 |
| `sirsoft-ecommerce.inquiry.delete_reply` | filter | — | `src/Services/ProductInquiryService.php:559` |
| `sirsoft-ecommerce.inquiry.get_by_ids` | filter | — | `src/Services/ProductInquiryService.php:199` 외 1곳 |
| `sirsoft-ecommerce.inquiry.get_settings` | filter | — | `src/Listeners/ProductInquiryBoardListener.php:270` 외 2곳 |
| `sirsoft-ecommerce.inquiry.store_validation_messages` | filter | — | `src/Http/Requests/Public/StoreInquiryRequest.php:57` |
| `sirsoft-ecommerce.inquiry.store_validation_rules` | filter | — | `src/Http/Requests/Public/StoreInquiryRequest.php:41` |
| `sirsoft-ecommerce.inquiry.update` | filter | — | `src/Services/ProductInquiryService.php:435` |
| `sirsoft-ecommerce.inquiry.update_reply` | filter | — | `src/Services/ProductInquiryService.php:518` |
| `sirsoft-ecommerce.inquiry.update_validation_rules` | filter | — | `src/Http/Requests/User/UpdateInquiryRequest.php:40` |
| `sirsoft-ecommerce.label.after_create` | action | — | `src/Services/ProductLabelService.php:103` |
| `sirsoft-ecommerce.label.after_delete` | action | — | `src/Services/ProductLabelService.php:212` |
| `sirsoft-ecommerce.label.after_list` | action | — | `src/Services/ProductLabelService.php:42` |
| `sirsoft-ecommerce.label.after_show` | action | — | `src/Services/ProductLabelService.php:65` |
| `sirsoft-ecommerce.label.after_toggle_status` | action | — | `src/Services/ProductLabelService.php:173` |
| `sirsoft-ecommerce.label.after_update` | action | — | `src/Services/ProductLabelService.php:142` |
| `sirsoft-ecommerce.label.before_create` | action | — | `src/Services/ProductLabelService.php:90` |
| `sirsoft-ecommerce.label.before_delete` | action | — | `src/Services/ProductLabelService.php:205` |
| `sirsoft-ecommerce.label.before_list` | action | — | `src/Services/ProductLabelService.php:31` |
| `sirsoft-ecommerce.label.before_show` | action | — | `src/Services/ProductLabelService.php:56` |
| `sirsoft-ecommerce.label.before_toggle_status` | action | — | `src/Services/ProductLabelService.php:164` |
| `sirsoft-ecommerce.label.before_update` | action | — | `src/Services/ProductLabelService.php:126` |
| `sirsoft-ecommerce.label.filter_create_data` | filter | — | `src/Services/ProductLabelService.php:93` |
| `sirsoft-ecommerce.label.filter_list_query` | filter | — | `src/Services/ProductLabelService.php:34` |
| `sirsoft-ecommerce.label.filter_list_result` | filter | — | `src/Services/ProductLabelService.php:39` |
| `sirsoft-ecommerce.label.filter_show_result` | filter | — | `src/Services/ProductLabelService.php:62` |
| `sirsoft-ecommerce.label.filter_update_data` | filter | — | `src/Services/ProductLabelService.php:132` |
| `sirsoft-ecommerce.mileage.earn` | action | — | `src/Services/OrderProcessingService.php:310` 외 1곳 |
| `sirsoft-ecommerce.mileage.max_usable_validation_rules` | filter | — | `src/Http/Requests/User/UserMileageMaxUsableRequest.php:34` |
| `sirsoft-ecommerce.mileage.notify_expiring` | action | — | `src/Console/Commands/NotifyExpiringMileageCommand.php:88` |
| `sirsoft-ecommerce.mileage.restore` | action | — | `src/Services/OrderCancellationService.php:1105` |
| `sirsoft-ecommerce.mileage.use` | action | — | `src/Services/OrderProcessingService.php:2020` |
| `sirsoft-ecommerce.notification.channels` | filter | — | `src/Http/Controllers/Admin/EcommerceSettingsController.php:525` |
| `sirsoft-ecommerce.option.after_bulk_update` | action | — | `src/Services/ProductOptionService.php:297` |
| `sirsoft-ecommerce.option.before_bulk_update` | action | — | `src/Services/ProductOptionService.php:197` |
| `sirsoft-ecommerce.option.bulk_update_validation_rules` | filter | — | `src/Http/Requests/Admin/BulkUpdateOptionsRequest.php:72` |
| `sirsoft-ecommerce.option.filter_bulk_update_data` | filter | — | `src/Services/ProductOptionService.php:200` |
| `sirsoft-ecommerce.order-option.after_confirm` | action | — | `src/Services/OrderOptionService.php:258` 외 1곳 |
| `sirsoft-ecommerce.order-option.before_confirm` | action | — | `src/Services/OrderService.php:786` |
| `sirsoft-ecommerce.order.after_admin_notify` | action | — | `src/Services/OrderProcessingService.php:240` 외 2곳 |
| `sirsoft-ecommerce.order.after_bulk_shipping_update` | action | — | `src/Services/OrderService.php:577` |
| `sirsoft-ecommerce.order.after_bulk_status_update` | action | — | `src/Services/OrderService.php:542` |
| `sirsoft-ecommerce.order.after_bulk_update` | action | — | `src/Services/OrderService.php:466` |
| `sirsoft-ecommerce.order.after_confirm` | action | — | `src/Services/OrderProcessingService.php:314` 외 1곳 |
| `sirsoft-ecommerce.order.after_create` | action | — | `src/Services/OrderProcessingService.php:232` |
| `sirsoft-ecommerce.order.after_delete` | action | — | `src/Services/OrderService.php:376` |
| `sirsoft-ecommerce.order.after_deposit_recorded` | action | — | `src/Services/OrderProcessingService.php:1878` |
| `sirsoft-ecommerce.order.after_payment_complete` | action | — | `src/Services/OrderProcessingService.php:313` 외 1곳 |
| `sirsoft-ecommerce.order.after_purchase_confirmed` | action | — | `src/Services/OrderOptionService.php:776` 외 1곳 |
| `sirsoft-ecommerce.order.after_read` | action | — | `src/Services/OrderService.php:136` 외 1곳 |
| `sirsoft-ecommerce.order.after_reset_guest_password` | action | — | `src/Services/OrderService.php:859` |
| `sirsoft-ecommerce.order.after_send_email` | action | — | `src/Services/OrderService.php:607` |
| `sirsoft-ecommerce.order.after_status_change` | action | — | `src/Services/OrderOptionService.php:770` 외 3곳 |
| `sirsoft-ecommerce.order.after_update` | action | — | `src/Services/OrderService.php:336` |
| `sirsoft-ecommerce.order.after_update_shipping_address` | action | — | `src/Services/OrderService.php:748` |
| `sirsoft-ecommerce.order.before_bulk_shipping_update` | action | — | `src/Services/OrderService.php:569` |
| `sirsoft-ecommerce.order.before_bulk_status_update` | action | — | `src/Services/OrderService.php:525` |
| `sirsoft-ecommerce.order.before_bulk_update` | action | — | `src/Services/OrderService.php:412` |
| `sirsoft-ecommerce.order.before_cancel` | action | — | `src/Services/OrderCancellationService.php:295` |
| `sirsoft-ecommerce.order.before_create` | action | — | `src/Services/OrderProcessingService.php:112` |
| `sirsoft-ecommerce.order.before_delete` | action | — | `src/Services/OrderService.php:362` |
| `sirsoft-ecommerce.order.before_payment_complete` | action | — | `src/Services/OrderProcessingService.php:306` 외 1곳 |
| `sirsoft-ecommerce.order.before_reset_guest_password` | action | — | `src/Services/OrderService.php:853` |
| `sirsoft-ecommerce.order.before_update` | action | — | `src/Services/OrderService.php:173` |
| `sirsoft-ecommerce.order.before_update_shipping_address` | action | — | `src/Services/OrderService.php:687` |
| `sirsoft-ecommerce.order.create_validation_rules` | filter | — | `src/Http/Requests/Public/CreateOrderRequest.php:133` |
| `sirsoft-ecommerce.order.filter_create_data` | filter | — | `src/Services/OrderProcessingService.php:619` |
| `sirsoft-ecommerce.order.filter_export_params` | filter | — | `src/Services/OrderService.php:629` |
| `sirsoft-ecommerce.order.filter_list_params` | filter | — | `src/Services/OrderService.php:97` |
| `sirsoft-ecommerce.order.filter_update_data` | filter | — | `src/Services/OrderService.php:179` |
| `sirsoft-ecommerce.order.list_validation_messages` | filter | — | `src/Http/Requests/Admin/OrderListRequest.php:182` |
| `sirsoft-ecommerce.order.list_validation_rules` | filter | — | `src/Http/Requests/Admin/OrderListRequest.php:108` |
| `sirsoft-ecommerce.order.payment_failed` | action | — | `src/Services/OrderProcessingService.php:2003` |
| `sirsoft-ecommerce.order.shipping_address_validation_rules` | filter | — | `src/Http/Requests/User/UpdateOrderShippingAddressRequest.php:71` |
| `sirsoft-ecommerce.order_option.after_bulk_status_change` | action | — | `src/Services/OrderOptionService.php:389` |
| `sirsoft-ecommerce.order_option.after_status_change` | action | — | `src/Services/OrderOptionService.php:251` |
| `sirsoft-ecommerce.order_option.before_bulk_status_change` | action | — | `src/Services/OrderOptionService.php:339` |
| `sirsoft-ecommerce.order_option.before_status_change` | action | — | `src/Services/OrderOptionService.php:112` |
| `sirsoft-ecommerce.payment.before_approve` | action | — | `src/Services/OrderService.php:418` 외 1곳 |
| `sirsoft-ecommerce.payment.before_cancel` | action | — | `src/Services/OrderCancellationService.php:292` |
| `sirsoft-ecommerce.payment.before_confirm_deposit` | action | — | `src/Services/OrderProcessingService.php:1815` |
| `sirsoft-ecommerce.payment.get_client_config` | filter | — | `src/Http/Controllers/Shop/PaymentConfigController.php:26` |
| `sirsoft-ecommerce.payment.refund` | filter | — | `src/Services/OrderCancellationService.php:1027` |
| `sirsoft-ecommerce.payment.registered_pg_providers` | filter | — | `src/Services/EcommerceSettingsService.php:862` |
| `sirsoft-ecommerce.preset.after_create` | action | — | `src/Services/SearchPresetService.php:60` |
| `sirsoft-ecommerce.preset.after_delete` | action | — | `src/Services/SearchPresetService.php:118` |
| `sirsoft-ecommerce.preset.after_update` | action | — | `src/Services/SearchPresetService.php:93` |
| `sirsoft-ecommerce.preset.before_create` | action | — | `src/Services/SearchPresetService.php:52` |
| `sirsoft-ecommerce.preset.before_delete` | action | — | `src/Services/SearchPresetService.php:113` |
| `sirsoft-ecommerce.preset.before_update` | action | — | `src/Services/SearchPresetService.php:85` |
| `sirsoft-ecommerce.preset.filter_create_data` | filter | — | `src/Services/SearchPresetService.php:55` |
| `sirsoft-ecommerce.preset.filter_update_data` | filter | — | `src/Services/SearchPresetService.php:88` |
| `sirsoft-ecommerce.preset.store_validation_rules` | filter | — | `src/Http/Requests/Admin/StoreSearchPresetRequest.php:51` |
| `sirsoft-ecommerce.preset.update_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateSearchPresetRequest.php:54` |
| `sirsoft-ecommerce.product-common-info.after_create` | action | — | `src/Services/ProductCommonInfoService.php:125` |
| `sirsoft-ecommerce.product-common-info.after_delete` | action | — | `src/Services/ProductCommonInfoService.php:204` |
| `sirsoft-ecommerce.product-common-info.after_list` | action | — | `src/Services/ProductCommonInfoService.php:42` |
| `sirsoft-ecommerce.product-common-info.after_list_paginated` | action | — | `src/Services/ProductCommonInfoService.php:65` |
| `sirsoft-ecommerce.product-common-info.after_show` | action | — | `src/Services/ProductCommonInfoService.php:88` |
| `sirsoft-ecommerce.product-common-info.after_update` | action | — | `src/Services/ProductCommonInfoService.php:168` |
| `sirsoft-ecommerce.product-common-info.before_create` | action | — | `src/Services/ProductCommonInfoService.php:103` |
| `sirsoft-ecommerce.product-common-info.before_delete` | action | — | `src/Services/ProductCommonInfoService.php:193` |
| `sirsoft-ecommerce.product-common-info.before_list` | action | — | `src/Services/ProductCommonInfoService.php:31` 외 1곳 |
| `sirsoft-ecommerce.product-common-info.before_show` | action | — | `src/Services/ProductCommonInfoService.php:79` |
| `sirsoft-ecommerce.product-common-info.before_update` | action | — | `src/Services/ProductCommonInfoService.php:148` |
| `sirsoft-ecommerce.product-common-info.create_validation_rules` | filter | — | `src/Http/Requests/Admin/StoreProductCommonInfoRequest.php:42` |
| `sirsoft-ecommerce.product-common-info.filter_create_data` | filter | — | `src/Services/ProductCommonInfoService.php:106` |
| `sirsoft-ecommerce.product-common-info.filter_list_query` | filter | — | `src/Services/ProductCommonInfoService.php:34` 외 1곳 |
| `sirsoft-ecommerce.product-common-info.filter_list_result` | filter | — | `src/Services/ProductCommonInfoService.php:39` |
| `sirsoft-ecommerce.product-common-info.filter_show_result` | filter | — | `src/Services/ProductCommonInfoService.php:85` |
| `sirsoft-ecommerce.product-common-info.filter_update_data` | filter | — | `src/Services/ProductCommonInfoService.php:154` |
| `sirsoft-ecommerce.product-common-info.update_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateProductCommonInfoRequest.php:42` |
| `sirsoft-ecommerce.product-image.after_delete` | action | — | `src/Services/ProductImageService.php:293` |
| `sirsoft-ecommerce.product-image.after_reorder` | action | — | `src/Services/ProductImageService.php:312` |
| `sirsoft-ecommerce.product-image.after_upload` | action | — | `src/Services/ProductImageService.php:144` |
| `sirsoft-ecommerce.product-image.before_delete` | action | — | `src/Services/ProductImageService.php:271` |
| `sirsoft-ecommerce.product-image.before_reorder` | action | — | `src/Services/ProductImageService.php:307` |
| `sirsoft-ecommerce.product-image.before_upload` | action | — | `src/Services/ProductImageService.php:76` |
| `sirsoft-ecommerce.product-image.filter_reorder_validation_rules` | filter | — | `src/Http/Requests/Admin/ReorderProductImagesRequest.php:36` |
| `sirsoft-ecommerce.product-image.filter_upload_file` | filter | — | `src/Services/ProductImageService.php:79` |
| `sirsoft-ecommerce.product-image.filter_upload_validation_rules` | filter | — | `src/Http/Requests/Admin/UploadProductImageRequest.php:68` |
| `sirsoft-ecommerce.product-notice-template.after_copy` | action | — | `src/Services/ProductNoticeTemplateService.php:247` |
| `sirsoft-ecommerce.product-notice-template.after_create` | action | — | `src/Services/ProductNoticeTemplateService.php:120` |
| `sirsoft-ecommerce.product-notice-template.after_delete` | action | — | `src/Services/ProductNoticeTemplateService.php:216` |
| `sirsoft-ecommerce.product-notice-template.after_list` | action | — | `src/Services/ProductNoticeTemplateService.php:42` |
| `sirsoft-ecommerce.product-notice-template.after_list_paginated` | action | — | `src/Services/ProductNoticeTemplateService.php:65` |
| `sirsoft-ecommerce.product-notice-template.after_show` | action | — | `src/Services/ProductNoticeTemplateService.php:88` |
| `sirsoft-ecommerce.product-notice-template.after_toggle_active` | action | — | `src/Services/ProductNoticeTemplateService.php:185` |
| `sirsoft-ecommerce.product-notice-template.after_update` | action | — | `src/Services/ProductNoticeTemplateService.php:158` |
| `sirsoft-ecommerce.product-notice-template.before_copy` | action | — | `src/Services/ProductNoticeTemplateService.php:240` |
| `sirsoft-ecommerce.product-notice-template.before_create` | action | — | `src/Services/ProductNoticeTemplateService.php:103` |
| `sirsoft-ecommerce.product-notice-template.before_delete` | action | — | `src/Services/ProductNoticeTemplateService.php:209` |
| `sirsoft-ecommerce.product-notice-template.before_list` | action | — | `src/Services/ProductNoticeTemplateService.php:31` 외 1곳 |
| `sirsoft-ecommerce.product-notice-template.before_show` | action | — | `src/Services/ProductNoticeTemplateService.php:79` |
| `sirsoft-ecommerce.product-notice-template.before_update` | action | — | `src/Services/ProductNoticeTemplateService.php:143` |
| `sirsoft-ecommerce.product-notice-template.create_validation_rules` | filter | — | `src/Http/Requests/Admin/StoreProductNoticeTemplateRequest.php:43` |
| `sirsoft-ecommerce.product-notice-template.filter_create_data` | filter | — | `src/Services/ProductNoticeTemplateService.php:106` |
| `sirsoft-ecommerce.product-notice-template.filter_list_query` | filter | — | `src/Services/ProductNoticeTemplateService.php:34` 외 1곳 |
| `sirsoft-ecommerce.product-notice-template.filter_list_result` | filter | — | `src/Services/ProductNoticeTemplateService.php:39` |
| `sirsoft-ecommerce.product-notice-template.filter_show_result` | filter | — | `src/Services/ProductNoticeTemplateService.php:85` |
| `sirsoft-ecommerce.product-notice-template.filter_update_data` | filter | — | `src/Services/ProductNoticeTemplateService.php:149` |
| `sirsoft-ecommerce.product-notice-template.update_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateProductNoticeTemplateRequest.php:43` |
| `sirsoft-ecommerce.product-review.after_bulk_delete` | action | — | `src/Services/ProductReviewService.php:310` |
| `sirsoft-ecommerce.product-review.after_create` | action | — | `src/Services/ProductReviewService.php:165` |
| `sirsoft-ecommerce.product-review.after_delete` | action | — | `src/Services/ProductReviewService.php:253` |
| `sirsoft-ecommerce.product-review.before_bulk_delete` | action | — | `src/Services/ProductReviewService.php:288` |
| `sirsoft-ecommerce.product-review.before_create` | action | — | `src/Services/ProductReviewService.php:146` |
| `sirsoft-ecommerce.product-review.before_delete` | action | — | `src/Services/ProductReviewService.php:234` |
| `sirsoft-ecommerce.product.after_bulk_price_update` | action | — | `src/Services/ProductService.php:573` |
| `sirsoft-ecommerce.product.after_bulk_stock_update` | action | — | `src/Services/ProductService.php:606` |
| `sirsoft-ecommerce.product.after_bulk_update` | action | — | `src/Services/ProductService.php:538` 외 1곳 |
| `sirsoft-ecommerce.product.after_create` | action | — | `src/Services/ProductService.php:314` |
| `sirsoft-ecommerce.product.after_delete` | action | — | `src/Services/ProductService.php:494` |
| `sirsoft-ecommerce.product.after_new_list` | action | — | `src/Services/ProductService.php:189` |
| `sirsoft-ecommerce.product.after_options_sync` | action | — | `src/Services/ProductService.php:865` |
| `sirsoft-ecommerce.product.after_popular_list` | action | — | `src/Services/ProductService.php:168` |
| `sirsoft-ecommerce.product.after_public_list` | action | — | `src/Services/ProductService.php:147` |
| `sirsoft-ecommerce.product.after_read` | action | — | `src/Services/ProductService.php:231` |
| `sirsoft-ecommerce.product.after_stock_sync` | action | — | `src/Listeners/SyncProductFromOptionListener.php:107` 외 1곳 |
| `sirsoft-ecommerce.product.after_update` | action | — | `src/Services/ProductService.php:391` |
| `sirsoft-ecommerce.product.before_bulk_price_update` | action | — | `src/Services/ProductService.php:564` |
| `sirsoft-ecommerce.product.before_bulk_stock_update` | action | — | `src/Services/ProductService.php:598` |
| `sirsoft-ecommerce.product.before_bulk_update` | action | — | `src/Services/ProductService.php:530` 외 1곳 |
| `sirsoft-ecommerce.product.before_create` | action | — | `src/Services/ProductService.php:248` |
| `sirsoft-ecommerce.product.before_delete` | action | — | `src/Services/ProductService.php:446` |
| `sirsoft-ecommerce.product.before_new_list` | action | — | `src/Services/ProductService.php:183` |
| `sirsoft-ecommerce.product.before_popular_list` | action | — | `src/Services/ProductService.php:162` |
| `sirsoft-ecommerce.product.before_public_list` | action | — | `src/Services/ProductService.php:141` |
| `sirsoft-ecommerce.product.before_update` | action | — | `src/Services/ProductService.php:333` |
| `sirsoft-ecommerce.product.bulk_price_validation_rules` | filter | — | `src/Http/Requests/Admin/BulkUpdatePriceRequest.php:42` |
| `sirsoft-ecommerce.product.bulk_status_validation_rules` | filter | — | `src/Http/Requests/Admin/BulkUpdateStatusRequest.php:52` |
| `sirsoft-ecommerce.product.bulk_stock_validation_rules` | filter | — | `src/Http/Requests/Admin/BulkUpdateStockRequest.php:40` |
| `sirsoft-ecommerce.product.bulk_update_validation_rules` | filter | — | `src/Http/Requests/Admin/BulkUpdateProductsRequest.php:105` |
| `sirsoft-ecommerce.product.filter_bulk_update_data` | filter | — | `src/Services/ProductService.php:635` |
| `sirsoft-ecommerce.product.filter_content_thumbnail` | filter | — | `src/Models/Product.php:185` |
| `sirsoft-ecommerce.product.filter_create_data` | filter | — | `src/Services/ProductService.php:251` |
| `sirsoft-ecommerce.product.filter_list_params` | filter | — | `src/Services/ProductService.php:108` |
| `sirsoft-ecommerce.product.filter_new_list_result` | filter | — | `src/Services/ProductService.php:187` |
| `sirsoft-ecommerce.product.filter_popular_list_result` | filter | — | `src/Services/ProductService.php:166` |
| `sirsoft-ecommerce.product.filter_public_list_params` | filter | — | `src/Services/ProductService.php:143` |
| `sirsoft-ecommerce.product.filter_update_data` | filter | — | `src/Services/ProductService.php:339` |
| `sirsoft-ecommerce.product.list_validation_messages` | filter | — | `src/Http/Requests/Admin/ProductListRequest.php:166` |
| `sirsoft-ecommerce.product.list_validation_rules` | filter | — | `src/Http/Requests/Admin/ProductListRequest.php:122` |
| `sirsoft-ecommerce.product.logs_validation_rules` | filter | — | `src/Http/Requests/Admin/ProductLogsRequest.php:39` |
| `sirsoft-ecommerce.product.public_list_validation_messages` | filter | — | `src/Http/Requests/Public/PublicProductListRequest.php:71` |
| `sirsoft-ecommerce.product.public_list_validation_rules` | filter | — | `src/Http/Requests/Public/PublicProductListRequest.php:44` |
| `sirsoft-ecommerce.product.public_new_validation_rules` | filter | — | `src/Http/Requests/Public/PublicProductNewRequest.php:37` |
| `sirsoft-ecommerce.product.public_popular_validation_rules` | filter | — | `src/Http/Requests/Public/PublicProductPopularRequest.php:37` |
| `sirsoft-ecommerce.product.public_recent_validation_rules` | filter | — | `src/Http/Requests/Public/PublicProductRecentRequest.php:37` |
| `sirsoft-ecommerce.product.show_for_copy_validation_rules` | filter | — | `src/Http/Requests/Admin/ProductShowForCopyRequest.php:58` |
| `sirsoft-ecommerce.product.store_validation_rules` | filter | — | `src/Http/Requests/Admin/StoreProductRequest.php:180` |
| `sirsoft-ecommerce.product.update_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateProductRequest.php:61` |
| `sirsoft-ecommerce.product_inquiry.after_create` | action | — | `src/Services/ProductInquiryService.php:313` |
| `sirsoft-ecommerce.product_inquiry.after_reply` | action | — | `src/Services/ProductInquiryService.php:766` |
| `sirsoft-ecommerce.product_option.after_bulk_price_update` | action | — | `src/Services/ProductOptionService.php:101` |
| `sirsoft-ecommerce.product_option.after_bulk_stock_update` | action | — | `src/Services/ProductOptionService.php:147` 외 1곳 |
| `sirsoft-ecommerce.product_option.before_bulk_price_update` | action | — | `src/Services/ProductOptionService.php:91` |
| `sirsoft-ecommerce.product_option.before_bulk_stock_update` | action | — | `src/Services/ProductOptionService.php:138` |
| `sirsoft-ecommerce.product_option.bulk_price_validation_rules` | filter | — | `src/Http/Requests/Admin/BulkUpdateOptionPriceRequest.php:49` |
| `sirsoft-ecommerce.product_option.bulk_stock_validation_rules` | filter | — | `src/Http/Requests/Admin/BulkUpdateOptionStockRequest.php:46` |
| `sirsoft-ecommerce.review-image.after_delete` | action | — | `src/Services/ProductReviewImageService.php:139` |
| `sirsoft-ecommerce.review-image.after_upload` | action | — | `src/Services/ProductReviewImageService.php:114` |
| `sirsoft-ecommerce.review-image.before_delete` | action | — | `src/Services/ProductReviewImageService.php:127` |
| `sirsoft-ecommerce.review-image.before_upload` | action | — | `src/Services/ProductReviewImageService.php:64` |
| `sirsoft-ecommerce.review-image.filter_upload_file` | filter | — | `src/Services/ProductReviewImageService.php:67` |
| `sirsoft-ecommerce.review.bulk_validation_messages` | filter | — | `src/Http/Requests/Admin/BulkReviewRequest.php:62` |
| `sirsoft-ecommerce.review.bulk_validation_rules` | filter | — | `src/Http/Requests/Admin/BulkReviewRequest.php:40` |
| `sirsoft-ecommerce.review.list_validation_messages` | filter | — | `src/Http/Requests/Admin/AdminReviewListRequest.php:101` |
| `sirsoft-ecommerce.review.list_validation_rules` | filter | — | `src/Http/Requests/Admin/AdminReviewListRequest.php:63` |
| `sirsoft-ecommerce.review.public_list_validation_messages` | filter | — | `src/Http/Requests/Public/PublicReviewListRequest.php:63` |
| `sirsoft-ecommerce.review.public_list_validation_rules` | filter | — | `src/Http/Requests/Public/PublicReviewListRequest.php:42` |
| `sirsoft-ecommerce.review.store_reply_validation_messages` | filter | — | `src/Http/Requests/Admin/StoreReviewReplyRequest.php:52` |
| `sirsoft-ecommerce.review.store_reply_validation_rules` | filter | — | `src/Http/Requests/Admin/StoreReviewReplyRequest.php:35` |
| `sirsoft-ecommerce.review.store_validation_messages` | filter | — | `src/Http/Requests/User/StoreReviewRequest.php:66` |
| `sirsoft-ecommerce.review.store_validation_rules` | filter | — | `src/Http/Requests/User/StoreReviewRequest.php:41` |
| `sirsoft-ecommerce.review.update_status_validation_messages` | filter | — | `src/Http/Requests/Admin/UpdateReviewStatusRequest.php:51` |
| `sirsoft-ecommerce.review.update_status_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateReviewStatusRequest.php:36` |
| `sirsoft-ecommerce.search.brand.index_should_update` | filter | — | `src/Models/Brand.php:148` |
| `sirsoft-ecommerce.search.category.index_should_update` | filter | — | `src/Models/Category.php:588` |
| `sirsoft-ecommerce.search.coupon.index_should_update` | filter | — | `src/Models/Coupon.php:514` |
| `sirsoft-ecommerce.search.product.index_should_update` | filter | — | `src/Models/Product.php:683` |
| `sirsoft-ecommerce.search.product_common_info.index_should_update` | filter | — | `src/Models/ProductCommonInfo.php:176` |
| `sirsoft-ecommerce.search_preset.list_validation_rules` | filter | — | `src/Http/Requests/Admin/SearchPresetListRequest.php:32` |
| `sirsoft-ecommerce.sequence.after_generate` | action | — | `src/Services/SequenceService.php:57` 외 1곳 |
| `sirsoft-ecommerce.sequence.before_generate` | action | — | `src/Services/SequenceService.php:44` |
| `sirsoft-ecommerce.settings.after_save` | action | — | `src/Http/Controllers/Admin/EcommerceSettingsController.php:172` |
| `sirsoft-ecommerce.settings.filter_available_payment_methods` | filter | — | `src/Services/EcommerceSettingsService.php:847` |
| `sirsoft-ecommerce.shipping.calculate_fee` | filter | — | `src/Services/OrderCalculationService.php:2127` |
| `sirsoft-ecommerce.shipping_carrier.after_create` | action | — | `src/Services/ShippingCarrierService.php:101` |
| `sirsoft-ecommerce.shipping_carrier.after_delete` | action | — | `src/Services/ShippingCarrierService.php:256` |
| `sirsoft-ecommerce.shipping_carrier.after_list` | action | — | `src/Services/ShippingCarrierService.php:44` |
| `sirsoft-ecommerce.shipping_carrier.after_show` | action | — | `src/Services/ShippingCarrierService.php:63` |
| `sirsoft-ecommerce.shipping_carrier.after_toggle_status` | action | — | `src/Services/ShippingCarrierService.php:167` |
| `sirsoft-ecommerce.shipping_carrier.after_update` | action | — | `src/Services/ShippingCarrierService.php:138` |
| `sirsoft-ecommerce.shipping_carrier.before_create` | action | — | `src/Services/ShippingCarrierService.php:88` |
| `sirsoft-ecommerce.shipping_carrier.before_delete` | action | — | `src/Services/ShippingCarrierService.php:250` |
| `sirsoft-ecommerce.shipping_carrier.before_list` | action | — | `src/Services/ShippingCarrierService.php:36` |
| `sirsoft-ecommerce.shipping_carrier.before_show` | action | — | `src/Services/ShippingCarrierService.php:57` |
| `sirsoft-ecommerce.shipping_carrier.before_toggle_status` | action | — | `src/Services/ShippingCarrierService.php:159` |
| `sirsoft-ecommerce.shipping_carrier.before_update` | action | — | `src/Services/ShippingCarrierService.php:123` |
| `sirsoft-ecommerce.shipping_carrier.create_validation_rules` | filter | — | `src/Http/Requests/Admin/StoreShippingCarrierRequest.php:42` |
| `sirsoft-ecommerce.shipping_carrier.filter_create_data` | filter | — | `src/Services/ShippingCarrierService.php:90` |
| `sirsoft-ecommerce.shipping_carrier.filter_list_query` | filter | — | `src/Services/ShippingCarrierService.php:38` |
| `sirsoft-ecommerce.shipping_carrier.filter_list_result` | filter | — | `src/Services/ShippingCarrierService.php:42` |
| `sirsoft-ecommerce.shipping_carrier.filter_show_result` | filter | — | `src/Services/ShippingCarrierService.php:62` |
| `sirsoft-ecommerce.shipping_carrier.filter_update_data` | filter | — | `src/Services/ShippingCarrierService.php:128` |
| `sirsoft-ecommerce.shipping_carrier.update_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateShippingCarrierRequest.php:44` |
| `sirsoft-ecommerce.shipping_policy.after_bulk_delete` | action | — | `src/Services/ShippingPolicyService.php:314` |
| `sirsoft-ecommerce.shipping_policy.after_bulk_toggle_active` | action | — | `src/Services/ShippingPolicyService.php:342` |
| `sirsoft-ecommerce.shipping_policy.after_create` | action | — | `src/Services/ShippingPolicyService.php:110` |
| `sirsoft-ecommerce.shipping_policy.after_delete` | action | — | `src/Services/ShippingPolicyService.php:262` |
| `sirsoft-ecommerce.shipping_policy.after_read` | action | — | `src/Services/ShippingPolicyService.php:62` |
| `sirsoft-ecommerce.shipping_policy.after_set_default` | action | — | `src/Services/ShippingPolicyService.php:382` |
| `sirsoft-ecommerce.shipping_policy.after_toggle_active` | action | — | `src/Services/ShippingPolicyService.php:283` |
| `sirsoft-ecommerce.shipping_policy.after_update` | action | — | `src/Services/ShippingPolicyService.php:167` |
| `sirsoft-ecommerce.shipping_policy.before_bulk_delete` | action | — | `src/Services/ShippingPolicyService.php:306` |
| `sirsoft-ecommerce.shipping_policy.before_bulk_toggle_active` | action | — | `src/Services/ShippingPolicyService.php:337` |
| `sirsoft-ecommerce.shipping_policy.before_create` | action | — | `src/Services/ShippingPolicyService.php:77` |
| `sirsoft-ecommerce.shipping_policy.before_delete` | action | — | `src/Services/ShippingPolicyService.php:251` |
| `sirsoft-ecommerce.shipping_policy.before_set_default` | action | — | `src/Services/ShippingPolicyService.php:368` |
| `sirsoft-ecommerce.shipping_policy.before_toggle_active` | action | — | `src/Services/ShippingPolicyService.php:278` |
| `sirsoft-ecommerce.shipping_policy.before_update` | action | — | `src/Services/ShippingPolicyService.php:127` |
| `sirsoft-ecommerce.shipping_policy.bulk_delete_validation_rules` | filter | — | `src/Http/Requests/Admin/ShippingPolicyBulkDeleteRequest.php:38` |
| `sirsoft-ecommerce.shipping_policy.bulk_toggle_active_validation_rules` | filter | — | `src/Http/Requests/Admin/ShippingPolicyBulkToggleActiveRequest.php:39` |
| `sirsoft-ecommerce.shipping_policy.filter_create_data` | filter | — | `src/Services/ShippingPolicyService.php:80` |
| `sirsoft-ecommerce.shipping_policy.filter_list_params` | filter | — | `src/Services/ShippingPolicyService.php:34` |
| `sirsoft-ecommerce.shipping_policy.filter_update_data` | filter | — | `src/Services/ShippingPolicyService.php:133` |
| `sirsoft-ecommerce.shipping_policy.list_validation_messages` | filter | — | `src/Http/Requests/Admin/ShippingPolicyListRequest.php:129` |
| `sirsoft-ecommerce.shipping_policy.list_validation_rules` | filter | — | `src/Http/Requests/Admin/ShippingPolicyListRequest.php:96` |
| `sirsoft-ecommerce.shipping_policy.store_validation_rules` | filter | — | `src/Http/Requests/Admin/StoreShippingPolicyRequest.php:109` |
| `sirsoft-ecommerce.shipping_policy.update_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateShippingPolicyRequest.php:24` |
| `sirsoft-ecommerce.shipping_type.after_create` | action | — | `src/Services/ShippingTypeService.php:123` |
| `sirsoft-ecommerce.shipping_type.after_delete` | action | — | `src/Services/ShippingTypeService.php:261` |
| `sirsoft-ecommerce.shipping_type.after_list` | action | — | `src/Services/ShippingTypeService.php:44` |
| `sirsoft-ecommerce.shipping_type.after_show` | action | — | `src/Services/ShippingTypeService.php:63` |
| `sirsoft-ecommerce.shipping_type.after_update` | action | — | `src/Services/ShippingTypeService.php:162` |
| `sirsoft-ecommerce.shipping_type.before_create` | action | — | `src/Services/ShippingTypeService.php:107` |
| `sirsoft-ecommerce.shipping_type.before_delete` | action | — | `src/Services/ShippingTypeService.php:252` |
| `sirsoft-ecommerce.shipping_type.before_list` | action | — | `src/Services/ShippingTypeService.php:36` |
| `sirsoft-ecommerce.shipping_type.before_show` | action | — | `src/Services/ShippingTypeService.php:57` |
| `sirsoft-ecommerce.shipping_type.before_update` | action | — | `src/Services/ShippingTypeService.php:145` |
| `sirsoft-ecommerce.shipping_type.filter_create_data` | filter | — | `src/Services/ShippingTypeService.php:109` |
| `sirsoft-ecommerce.shipping_type.filter_list_query` | filter | — | `src/Services/ShippingTypeService.php:38` |
| `sirsoft-ecommerce.shipping_type.filter_list_result` | filter | — | `src/Services/ShippingTypeService.php:42` |
| `sirsoft-ecommerce.shipping_type.filter_show_result` | filter | — | `src/Services/ShippingTypeService.php:62` |
| `sirsoft-ecommerce.shipping_type.filter_update_data` | filter | — | `src/Services/ShippingTypeService.php:149` |
| `sirsoft-ecommerce.stock.after_deduct` | action | — | `src/Services/StockService.php:115` |
| `sirsoft-ecommerce.stock.after_restore` | action | — | `src/Services/StockService.php:157` |
| `sirsoft-ecommerce.stock.before_deduct` | action | — | `src/Services/StockService.php:70` |
| `sirsoft-ecommerce.stock.before_restore` | action | — | `src/Services/StockService.php:126` |
| `sirsoft-ecommerce.temp_order.after_cleanup` | action | — | `src/Services/TempOrderService.php:569` |
| `sirsoft-ecommerce.temp_order.after_create` | action | — | `src/Services/TempOrderService.php:126` |
| `sirsoft-ecommerce.temp_order.after_delete` | action | — | `src/Services/TempOrderService.php:527` 외 1곳 |
| `sirsoft-ecommerce.temp_order.after_update` | action | — | `src/Services/TempOrderService.php:414` |
| `sirsoft-ecommerce.temp_order.before_cleanup` | action | — | `src/Services/TempOrderService.php:565` |
| `sirsoft-ecommerce.temp_order.before_create` | action | — | `src/Services/TempOrderService.php:77` |
| `sirsoft-ecommerce.temp_order.before_delete` | action | — | `src/Services/TempOrderService.php:523` 외 1곳 |
| `sirsoft-ecommerce.temp_order.before_update` | action | — | `src/Services/TempOrderService.php:341` |
| `sirsoft-ecommerce.user_address.after_create` | action | — | `src/Services/UserAddressService.php:149` |
| `sirsoft-ecommerce.user_address.after_delete` | action | — | `src/Services/UserAddressService.php:214` |
| `sirsoft-ecommerce.user_address.after_set_default` | action | — | `src/Services/UserAddressService.php:240` |
| `sirsoft-ecommerce.user_address.after_update` | action | — | `src/Services/UserAddressService.php:180` |
| `sirsoft-ecommerce.user_address.before_create` | action | — | `src/Services/UserAddressService.php:117` |
| `sirsoft-ecommerce.user_address.before_delete` | action | — | `src/Services/UserAddressService.php:200` |
| `sirsoft-ecommerce.user_address.before_set_default` | action | — | `src/Services/UserAddressService.php:234` |
| `sirsoft-ecommerce.user_address.before_update` | action | — | `src/Services/UserAddressService.php:170` |
| `sirsoft-ecommerce.user_address.store_validation_rules` | filter | — | `src/Http/Requests/User/StoreUserAddressRequest.php:77` |
| `sirsoft-ecommerce.user_address.update_validation_rules` | filter | — | `src/Http/Requests/User/UpdateUserAddressRequest.php:93` |
| `sirsoft-ecommerce.user_coupon.after_available` | action | — | `src/Services/UserCouponService.php:69` |
| `sirsoft-ecommerce.user_coupon.after_download` | action | — | `src/Services/UserCouponService.php:338` |
| `sirsoft-ecommerce.user_coupon.after_downloadable_list` | action | — | `src/Services/UserCouponService.php:302` |
| `sirsoft-ecommerce.user_coupon.after_list` | action | — | `src/Services/UserCouponService.php:49` |
| `sirsoft-ecommerce.user_coupon.before_available` | action | — | `src/Services/UserCouponService.php:63` |
| `sirsoft-ecommerce.user_coupon.before_download` | action | — | `src/Services/UserCouponService.php:318` |
| `sirsoft-ecommerce.user_coupon.before_downloadable_list` | action | — | `src/Services/UserCouponService.php:279` |
| `sirsoft-ecommerce.user_coupon.before_list` | action | — | `src/Services/UserCouponService.php:43` |
| `sirsoft-ecommerce.user_coupon.filter_available_result` | filter | — | `src/Services/UserCouponService.php:67` |
| `sirsoft-ecommerce.user_coupon.filter_downloadable_result` | filter | — | `src/Services/UserCouponService.php:300` |
| `sirsoft-ecommerce.user_coupon.filter_list_result` | filter | — | `src/Services/UserCouponService.php:47` |
| `sirsoft-ecommerce.user_coupon.filter_product_downloadable_result` | filter | — | `src/Services/UserCouponService.php:526` |
| `sirsoft-ecommerce.user_mileage.after_balance` | action | — | `src/Services/UserMileageService.php:147` |
| `sirsoft-ecommerce.user_mileage.before_balance` | action | — | `src/Services/UserMileageService.php:130` |
| `sirsoft-ecommerce.user_mileage.filter_balance` | filter | — | `src/Services/UserMileageService.php:145` |
| `sirsoft-ecommerce.wishlist.after_toggle` | action | — | `src/Services/ProductWishlistService.php:30` |
| `sirsoft-ecommerce.wishlist.before_toggle` | action | — | `src/Services/ProductWishlistService.php:26` |
| `sirsoft-ecommerce.wishlist.toggle_validation_rules` | filter | — | `src/Http/Requests/Public/ToggleWishlistRequest.php:34` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
508종이라는 수에 압도될 필요는 없습니다. 도메인 하나가 같은 4종을 반복하기 때문입니다 —
`before_{동작}`(action) → `filter_{동작}_data`(filter) → 실행 → `after_{동작}`(action), 그리고
FormRequest 쪽 `{동작}_validation_rules`(filter). `brand.*` 19종을 한 번 읽으면 `product` 44 ·
`order` 35 · `coupon` 32 · `category` 28 · `cart` 25 · `shipping_policy` 24 · `brand` 19 ·
`shipping_carrier` 19 · `extra_fee_template` 19 · `label` 17 · `claim_reason` 17 ·
`shipping_type` 15 에 그대로 적용됩니다.

그 패턴에서 벗어나는 것이 이 모듈 고유의 확장점입니다:

| 훅 무리 | 수 | 무엇을 열어 주는가 |
|---|---|---|
| `calculation.*` | 18 | 9단계 금액 계산의 단계 사이. 옵션 소계·배송비·쿠폰 적용·최종 결과에 개입할 수 있습니다 |
| `adjustment.filter_restore_promotions` | 1 | 취소 시 이미 적용된 쿠폰·마일리지를 되돌리는 안분 규칙 |
| `payment.*` | 6 | 결제 승인·취소·입금 확인 전후. 본인인증 정책 3종이 이 훅을 target 으로 삼습니다 |
| `checkout.*` | 4 | 주문서 조립과 결제 직전. `checkout.before_payment` 이 구매자측 본인인증 게이트 지점입니다 |
| `stock.*` | 4 | 재고 차감·복원 |
| `mileage.*` · `user_mileage.*` | 8 | 마일리지 적립·차감·소멸 |
| `search.*` · `preset.*` · `search_preset.*` | 12 | 상품 검색 술어와 관리자 검색 프리셋 |

`core.module_settings.after_save` 는 이 모듈이 발행하지만 **코어 이름공간**입니다 — 환경설정
저장 후 캐시를 비우는 리스너들이 이 훅으로 붙습니다.

훅 이름이 상수·변수로 조립되는 호출이 5곳 있어 표에 위치가 다 실리지 않습니다. 그 자리를 정확히
알아야 하면 소스에서 `HookManager::` 호출부를 직접 확인합니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.activity_log.filter_description_params` | filter | `ActivityLogDescriptionResolver` | `resolveDescriptionParams` | 10 |
| `core.auth.after_login` | action (미선언) | `MergeCartOnLoginListener` | `handle` | 20 |
| `core.auth.after_register` | action (미선언) | `AssignDefaultCurrencyOnRegisterListener` | `handleRegister` | 20 |
| `core.auth.after_register` | action (미선언) | `AssignDefaultShippingCountryOnRegisterListener` | `handleRegister` | 20 |
| `core.auth.register_validation_rules` | filter | `AssignDefaultCurrencyOnRegisterListener` | `addCurrencyRule` | 20 |
| `core.auth.register_validation_rules` | filter | `AssignDefaultShippingCountryOnRegisterListener` | `addShippingCountryRule` | 20 |
| `core.frontend.filter_app_config` | filter | `InjectAppConfigDeviceListener` | `injectDeviceFlags` | 20 |
| `core.module_settings.after_save` | action (미선언) | `SeoSettingsCacheListener` | `onModuleSettingsSave` | 20 |
| `core.notification.filter_default_definitions` | filter | `EcommerceNotificationDataListener` | `contributeDefaultDefinitions` | 20 |
| `core.search.build_response` | filter | `SearchProductsListener` | `buildProductsResponse` | 20 |
| `core.search.index_validation_rules` | filter | `SearchProductsListener` | `addValidationRules` | 20 |
| `core.search.results` | filter | `SearchProductsListener` | `searchProducts` | 20 |
| `core.user.after_create` | action (미선언) | `AssignDefaultCurrencyOnRegisterListener` | `handleAdminCreate` | 20 |
| `core.user.after_create` | action (미선언) | `AssignDefaultShippingCountryOnRegisterListener` | `handleAdminCreate` | 20 |
| `core.user.before_delete` | action (미선언) | `UserMileageCleanupListener` | `handleUserRemoval` | 10 |
| `core.user.before_withdraw` | action (미선언) | `UserMileageCleanupListener` | `handleUserRemoval` | 10 |
| `core.user.filter_resource_data` | filter | `UserCurrencyInfoListener` | `injectPreferredCurrency` | 25 |
| `core.user.filter_resource_data` | filter | `UserMileageInfoListener` | `injectMileageTotal` | 20 |
| `core.user.filter_resource_data` | filter | `UserShippingCountryInfoListener` | `injectPreferredShippingCountry` | 26 |
| `sirsoft-board.board.posts.before_force_delete` | action (미선언) | `ProductInquiryBoardListener` | `handleBoardPostsForceDeleting` | 20 |
| `sirsoft-board.post.after_delete` | action (미선언) | `ProductInquiryBoardListener` | `handlePostDeleted` | 20 |
| `sirsoft-board.post.after_restore` | action (미선언) | `ProductInquiryBoardListener` | `handlePostRestored` | 20 |
| `sirsoft-ckeditor5.image.filter_reference_sources` | filter | `Ckeditor5ReferenceSourcesListener` | `addEcommerceSources` | 10 |
| `sirsoft-ecommerce.admin.user_currency.changed` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleUserCurrencyChanged` | 20 |
| `sirsoft-ecommerce.admin.user_shipping_country.changed` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleUserShippingCountryChanged` | 20 |
| `sirsoft-ecommerce.brand.after_create` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleBrandAfterCreate` | 20 |
| `sirsoft-ecommerce.brand.after_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleBrandAfterDelete` | 20 |
| `sirsoft-ecommerce.brand.after_toggle_status` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleBrandAfterToggleStatus` | 20 |
| `sirsoft-ecommerce.brand.after_update` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleBrandAfterUpdate` | 20 |
| `sirsoft-ecommerce.cart.after_add` | action (미선언) | `EcommerceUserActivityLogListener` | `handleCartAfterAdd` | 20 |
| `sirsoft-ecommerce.cart.after_change_option` | action (미선언) | `EcommerceUserActivityLogListener` | `handleCartAfterChangeOption` | 20 |
| `sirsoft-ecommerce.cart.after_delete` | action (미선언) | `EcommerceUserActivityLogListener` | `handleCartAfterDelete` | 20 |
| `sirsoft-ecommerce.cart.after_delete_all` | action (미선언) | `EcommerceUserActivityLogListener` | `handleCartAfterDeleteAll` | 20 |
| `sirsoft-ecommerce.cart.after_update_quantity` | action (미선언) | `EcommerceUserActivityLogListener` | `handleCartAfterUpdateQuantity` | 20 |
| `sirsoft-ecommerce.category.after_create` | action (미선언) | `CategoryActivityLogListener` | `handleAfterCreate` | 20 |
| `sirsoft-ecommerce.category.after_create` | action (미선언) | `SeoCategoryCacheListener` | `onCategoryChange` | 20 |
| `sirsoft-ecommerce.category.after_delete` | action (미선언) | `CategoryActivityLogListener` | `handleAfterDelete` | 20 |
| `sirsoft-ecommerce.category.after_delete` | action (미선언) | `SeoCategoryCacheListener` | `onCategoryDelete` | 20 |
| `sirsoft-ecommerce.category.after_reorder` | action (미선언) | `CategoryActivityLogListener` | `handleAfterReorder` | 20 |
| `sirsoft-ecommerce.category.after_toggle_status` | action (미선언) | `CategoryActivityLogListener` | `handleAfterToggleStatus` | 20 |
| `sirsoft-ecommerce.category.after_update` | action (미선언) | `CategoryActivityLogListener` | `handleAfterUpdate` | 20 |
| `sirsoft-ecommerce.category.after_update` | action (미선언) | `SeoCategoryCacheListener` | `onCategoryChange` | 20 |
| `sirsoft-ecommerce.coupon.after_bulk_status` | action (미선언) | `CouponActivityLogListener` | `handleAfterBulkStatus` | 20 |
| `sirsoft-ecommerce.coupon.after_create` | action (미선언) | `CouponActivityLogListener` | `handleAfterCreate` | 20 |
| `sirsoft-ecommerce.coupon.after_delete` | action (미선언) | `CouponActivityLogListener` | `handleAfterDelete` | 20 |
| `sirsoft-ecommerce.coupon.after_direct_issue` | action (미선언) | `CouponActivityLogListener` | `handleAfterDirectIssue` | 20 |
| `sirsoft-ecommerce.coupon.after_issue_cancel` | action (미선언) | `CouponActivityLogListener` | `handleAfterIssueCancel` | 20 |
| `sirsoft-ecommerce.coupon.after_update` | action (미선언) | `CouponActivityLogListener` | `handleAfterUpdate` | 20 |
| `sirsoft-ecommerce.coupon.restore` | action (미선언) | `CouponRestoreListener` | `restoreCouponsByIds` | 10 |
| `sirsoft-ecommerce.coupon.restore` | action (미선언) | `OrderActivityLogListener` | `handleCouponRestore` | 20 |
| `sirsoft-ecommerce.coupon.use` | action (미선언) | `CouponUseListener` | `markCouponsUsed` | 10 |
| `sirsoft-ecommerce.coupon.use` | action (미선언) | `OrderActivityLogListener` | `handleCouponUse` | 20 |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_create` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleExtraFeeAfterBulkCreate` | 20 |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleExtraFeeAfterBulkDelete` | 20 |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_toggle_active` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleExtraFeeAfterBulkToggleActive` | 20 |
| `sirsoft-ecommerce.extra_fee_template.after_create` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleExtraFeeAfterCreate` | 20 |
| `sirsoft-ecommerce.extra_fee_template.after_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleExtraFeeAfterDelete` | 20 |
| `sirsoft-ecommerce.extra_fee_template.after_toggle_active` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleExtraFeeAfterToggleActive` | 20 |
| `sirsoft-ecommerce.extra_fee_template.after_update` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleExtraFeeAfterUpdate` | 20 |
| `sirsoft-ecommerce.inquiry.store_validation_rules` | filter | `ProductInquiryBoardListener` | `injectBoardValidationRules` | 10 |
| `sirsoft-ecommerce.inquiry.update_validation_rules` | filter | `ProductInquiryBoardListener` | `injectBoardValidationRules` | 10 |
| `sirsoft-ecommerce.label.after_create` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleLabelAfterCreate` | 20 |
| `sirsoft-ecommerce.label.after_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleLabelAfterDelete` | 20 |
| `sirsoft-ecommerce.label.after_toggle_status` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleLabelAfterToggleStatus` | 20 |
| `sirsoft-ecommerce.label.after_update` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleLabelAfterUpdate` | 20 |
| `sirsoft-ecommerce.mileage.earn` | action (미선언) | `OrderActivityLogListener` | `handleMileageEarn` | 20 |
| `sirsoft-ecommerce.mileage.restore` | action (미선언) | `MileageTransactionListener` | `handleRestore` | 10 |
| `sirsoft-ecommerce.mileage.restore` | action (미선언) | `OrderActivityLogListener` | `handleMileageRestore` | 20 |
| `sirsoft-ecommerce.mileage.use` | action (미선언) | `MileageTransactionListener` | `handleUse` | 10 |
| `sirsoft-ecommerce.mileage.use` | action (미선언) | `OrderActivityLogListener` | `handleMileageUse` | 20 |
| `sirsoft-ecommerce.notification.extract_data` | filter | `EcommerceNotificationDataListener` | `extractData` | 20 |
| `sirsoft-ecommerce.option.after_bulk_update` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleOptionAfterBulkUpdate` | 20 |
| `sirsoft-ecommerce.option.after_bulk_update` | action (미선언) | `SyncOptionGroupsListener` | `syncOptionGroupsFromBulkUpdate` | 10 |
| `sirsoft-ecommerce.option.after_bulk_update` | action (미선언) | `SyncProductFromOptionListener` | `syncProductStockFromBulkUpdate` | 10 |
| `sirsoft-ecommerce.order-option.after_confirm` | action (미선언) | `EcommerceUserActivityLogListener` | `handleOrderOptionAfterConfirm` | 20 |
| `sirsoft-ecommerce.order-option.after_confirm` | action (미선언) | `MileageTransactionListener` | `handleAfterConfirm` | 10 |
| `sirsoft-ecommerce.order-option.after_confirm` | action (미선언) | `OrderActivityLogListener` | `handleOrderOptionAfterConfirm` | 20 |
| `sirsoft-ecommerce.order.after_bulk_shipping_update` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterBulkShippingUpdate` | 20 |
| `sirsoft-ecommerce.order.after_bulk_status_update` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterBulkStatusUpdate` | 20 |
| `sirsoft-ecommerce.order.after_bulk_update` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterBulkUpdate` | 20 |
| `sirsoft-ecommerce.order.after_cancel` | action (미선언) | `CouponRestoreListener` | `restoreCoupons` | 10 |
| `sirsoft-ecommerce.order.after_cancel` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterCancel` | 20 |
| `sirsoft-ecommerce.order.after_create` | action (미선언) | `EcommerceUserActivityLogListener` | `handleOrderAfterCreate` | 20 |
| `sirsoft-ecommerce.order.after_create` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterCreate` | 20 |
| `sirsoft-ecommerce.order.after_delete` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterDelete` | 20 |
| `sirsoft-ecommerce.order.after_deposit_recorded` | action (미선언) | `IssueCashReceiptOnDepositListener` | `handleDeposit` | 50 |
| `sirsoft-ecommerce.order.after_partial_cancel` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterPartialCancel` | 20 |
| `sirsoft-ecommerce.order.after_payment_complete` | action (미선언) | `IssueCashReceiptOnDepositListener` | `handleDeposit` | 50 |
| `sirsoft-ecommerce.order.after_payment_complete` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterPaymentComplete` | 20 |
| `sirsoft-ecommerce.order.after_purchase_confirmed` | action (미선언) | `PurgeCashReceiptIdentifierListener` | `purgeIdentifier` | 50 |
| `sirsoft-ecommerce.order.after_reset_guest_password` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterResetGuestPassword` | 20 |
| `sirsoft-ecommerce.order.after_send_email` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterSendEmail` | 20 |
| `sirsoft-ecommerce.order.after_status_change` | action (미선언) | `OrderStatusNotificationListener` | `handleStatusChange` | 10 |
| `sirsoft-ecommerce.order.after_update` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterUpdate` | 20 |
| `sirsoft-ecommerce.order.after_update_shipping_address` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterUpdateShippingAddress` | 20 |
| `sirsoft-ecommerce.order.payment_failed` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterPaymentFailed` | 20 |
| `sirsoft-ecommerce.order_option.after_bulk_status_change` | action (미선언) | `MileageTransactionListener` | `handleAfterBulkStatusChange` | 10 |
| `sirsoft-ecommerce.order_option.after_bulk_status_change` | action (미선언) | `OrderActivityLogListener` | `handleOrderOptionAfterBulkStatusChange` | 20 |
| `sirsoft-ecommerce.order_option.after_status_change` | action (미선언) | `MileageTransactionListener` | `handleAfterStatusChange` | 10 |
| `sirsoft-ecommerce.order_option.after_status_change` | action (미선언) | `OrderActivityLogListener` | `handleOrderOptionAfterStatusChange` | 20 |
| `sirsoft-ecommerce.product-common-info.after_create` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleCommonInfoAfterCreate` | 20 |
| `sirsoft-ecommerce.product-common-info.after_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleCommonInfoAfterDelete` | 20 |
| `sirsoft-ecommerce.product-common-info.after_update` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleCommonInfoAfterUpdate` | 20 |
| `sirsoft-ecommerce.product-image.after_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleImageAfterDelete` | 20 |
| `sirsoft-ecommerce.product-image.after_reorder` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleImageAfterReorder` | 20 |
| `sirsoft-ecommerce.product-image.after_upload` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleImageAfterUpload` | 20 |
| `sirsoft-ecommerce.product-notice-template.after_copy` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleNoticeTemplateAfterCopy` | 20 |
| `sirsoft-ecommerce.product-notice-template.after_create` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleNoticeTemplateAfterCreate` | 20 |
| `sirsoft-ecommerce.product-notice-template.after_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleNoticeTemplateAfterDelete` | 20 |
| `sirsoft-ecommerce.product-notice-template.after_toggle_active` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleNoticeTemplateAfterToggleActive` | 20 |
| `sirsoft-ecommerce.product-notice-template.after_update` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleNoticeTemplateAfterUpdate` | 20 |
| `sirsoft-ecommerce.product-review.after_bulk_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleReviewAfterBulkDelete` | 20 |
| `sirsoft-ecommerce.product-review.after_create` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleReviewAfterCreate` | 20 |
| `sirsoft-ecommerce.product-review.after_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleReviewAfterDelete` | 20 |
| `sirsoft-ecommerce.product.after_bulk_price_update` | action (미선언) | `ProductActivityLogListener` | `handleProductAfterBulkPriceUpdate` | 20 |
| `sirsoft-ecommerce.product.after_bulk_stock_update` | action (미선언) | `ProductActivityLogListener` | `handleProductAfterBulkStockUpdate` | 20 |
| `sirsoft-ecommerce.product.after_bulk_update` | action (미선언) | `ProductActivityLogListener` | `handleProductAfterBulkUpdate` | 20 |
| `sirsoft-ecommerce.product.after_create` | action (미선언) | `ProductActivityLogListener` | `handleProductAfterCreate` | 20 |
| `sirsoft-ecommerce.product.after_create` | action (미선언) | `SeoProductCacheListener` | `onProductCreate` | 20 |
| `sirsoft-ecommerce.product.after_delete` | action (미선언) | `ProductActivityLogListener` | `handleProductAfterDelete` | 20 |
| `sirsoft-ecommerce.product.after_delete` | action (미선언) | `SeoProductCacheListener` | `onProductDelete` | 20 |
| `sirsoft-ecommerce.product.after_options_sync` | action (미선언) | `SyncOptionGroupsListener` | `syncOptionGroupsFromOptions` | 10 |
| `sirsoft-ecommerce.product.after_stock_sync` | action (미선언) | `ProductActivityLogListener` | `handleProductAfterStockSync` | 20 |
| `sirsoft-ecommerce.product.after_update` | action (미선언) | `ProductActivityLogListener` | `handleProductAfterUpdate` | 20 |
| `sirsoft-ecommerce.product.after_update` | action (미선언) | `SeoProductCacheListener` | `onProductUpdate` | 20 |
| `sirsoft-ecommerce.product_option.after_bulk_price_update` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleOptionAfterBulkPriceUpdate` | 20 |
| `sirsoft-ecommerce.product_option.after_bulk_stock_update` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleOptionAfterBulkStockUpdate` | 20 |
| `sirsoft-ecommerce.product_option.after_bulk_stock_update` | action (미선언) | `SyncProductFromOptionListener` | `syncProductStockFromOptions` | 10 |
| `sirsoft-ecommerce.settings.after_save` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleSettingsAfterSave` | 20 |
| `sirsoft-ecommerce.shipping_carrier.after_create` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleCarrierAfterCreate` | 20 |
| `sirsoft-ecommerce.shipping_carrier.after_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleCarrierAfterDelete` | 20 |
| `sirsoft-ecommerce.shipping_carrier.after_toggle_status` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleCarrierAfterToggleStatus` | 20 |
| `sirsoft-ecommerce.shipping_carrier.after_update` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleCarrierAfterUpdate` | 20 |
| `sirsoft-ecommerce.shipping_policy.after_bulk_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleShippingPolicyAfterBulkDelete` | 20 |
| `sirsoft-ecommerce.shipping_policy.after_bulk_toggle_active` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleShippingPolicyAfterBulkToggleActive` | 20 |
| `sirsoft-ecommerce.shipping_policy.after_create` | action (미선언) | `ShippingPolicyActivityLogListener` | `handleAfterCreate` | 20 |
| `sirsoft-ecommerce.shipping_policy.after_delete` | action (미선언) | `ShippingPolicyActivityLogListener` | `handleAfterDelete` | 20 |
| `sirsoft-ecommerce.shipping_policy.after_set_default` | action (미선언) | `ShippingPolicyActivityLogListener` | `handleAfterSetDefault` | 20 |
| `sirsoft-ecommerce.shipping_policy.after_toggle_active` | action (미선언) | `ShippingPolicyActivityLogListener` | `handleAfterToggleActive` | 20 |
| `sirsoft-ecommerce.shipping_policy.after_update` | action (미선언) | `ShippingPolicyActivityLogListener` | `handleAfterUpdate` | 20 |
| `sirsoft-ecommerce.user_coupon.after_download` | action (미선언) | `EcommerceUserActivityLogListener` | `handleUserCouponAfterDownload` | 20 |
| `sirsoft-ecommerce.wishlist.after_toggle` | action (미선언) | `EcommerceUserActivityLogListener` | `handleWishlistAfterToggle` | 20 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
142개 구독 중 119개는 **자기 자신이 발행한 훅**입니다 — Service 가 발행하고 리스너가 받는
내부 레인이며, 이것이 이 모듈의 부가효과(활동 로그·검색 색인·SEO 캐시·카운트 동기화)를 Service
바깥에 두는 방식입니다.

바깥을 향한 구독은 23개뿐이고, 그 셋이 이 모듈이 다른 확장과 맞물리는 전부입니다:

| 상대 | 수 | 무엇을 위해 |
|---|---|---|
| `core.*` | 19 | 로그인 시 비회원 장바구니 병합 · 회원가입 시 기본 통화/배송국가 배정과 그 검증 규칙 주입 · 활동 로그 설명 변수 해석 · 앱 설정에 기기 유형 주입 · 회원 탈퇴 시 마일리지 정리 |
| `sirsoft-board.*` | 3 | 문의 글이 삭제·복원·일괄 삭제될 때 상품↔글 피벗 정리 (`'sync' => true` — 큐 워커가 없는 환경에서도 누락되지 않도록) |
| `sirsoft-ckeditor5.*` | 1 | 편집기가 참조할 수 있는 이커머스 리소스 목록 제공 |

`sirsoft-board` · `sirsoft-ckeditor5` 는 **manifest 의존에 없습니다.** 훅 구독은 상대가 없으면
발화하지 않을 뿐이라, 게시판이나 편집기 플러그인이 없어도 이 모듈은 정상 동작하고 그 기능만
비어 있습니다. 반대로 말하면 이 세 훅 이름이 상대 확장에서 바뀌면 **예외 없이 조용히** 연동이
끊기므로, 상대 확장의 `docs/extension-points.md` 와 함께 확인해야 합니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ActivityLogDescriptionResolver` | 1개 | 명시 등록 | ✅ | `src/Listeners/ActivityLogDescriptionResolver.php` |
| `AssignDefaultCurrencyOnRegisterListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/AssignDefaultCurrencyOnRegisterListener.php` |
| `AssignDefaultShippingCountryOnRegisterListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/AssignDefaultShippingCountryOnRegisterListener.php` |
| `CategoryActivityLogListener` | 5개 | 명시 등록 | ✅ | `src/Listeners/CategoryActivityLogListener.php` |
| `CategoryTreeCacheListener` | 0개 | 명시 등록 | ✅ | `src/Listeners/CategoryTreeCacheListener.php` |
| `Ckeditor5ReferenceSourcesListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Ckeditor5ReferenceSourcesListener.php` |
| `CouponActivityLogListener` | 6개 | 명시 등록 | ✅ | `src/Listeners/CouponActivityLogListener.php` |
| `CouponRestoreListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/CouponRestoreListener.php` |
| `CouponUseListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/CouponUseListener.php` |
| `EcommerceAdminActivityLogListener` | 41개 | 명시 등록 | ✅ | `src/Listeners/EcommerceAdminActivityLogListener.php` |
| `EcommerceNotificationDataListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/EcommerceNotificationDataListener.php` |
| `EcommerceUserActivityLogListener` | 9개 | 명시 등록 | ✅ | `src/Listeners/EcommerceUserActivityLogListener.php` |
| `InjectAppConfigDeviceListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/InjectAppConfigDeviceListener.php` |
| `IssueCashReceiptOnDepositListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/IssueCashReceiptOnDepositListener.php` |
| `MergeCartOnLoginListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/MergeCartOnLoginListener.php` |
| `MileageTransactionListener` | 5개 | 명시 등록 | ✅ | `src/Listeners/MileageTransactionListener.php` |
| `OrderActivityLogListener` | 21개 | 명시 등록 | ✅ | `src/Listeners/OrderActivityLogListener.php` |
| `OrderStatusNotificationListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/OrderStatusNotificationListener.php` |
| `ProductActivityLogListener` | 7개 | 명시 등록 | ✅ | `src/Listeners/ProductActivityLogListener.php` |
| `ProductInquiryBoardListener` | 5개 | 명시 등록 | ✅ | `src/Listeners/ProductInquiryBoardListener.php` |
| `PurgeCashReceiptIdentifierListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/PurgeCashReceiptIdentifierListener.php` |
| `SearchProductsListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/SearchProductsListener.php` |
| `SeoCategoryCacheListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/SeoCategoryCacheListener.php` |
| `SeoProductCacheListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/SeoProductCacheListener.php` |
| `SeoSettingsCacheListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/SeoSettingsCacheListener.php` |
| `ShippingPolicyActivityLogListener` | 5개 | 명시 등록 | ✅ | `src/Listeners/ShippingPolicyActivityLogListener.php` |
| `ShippingPolicyCacheListener` | 0개 | 명시 등록 | ✅ | `src/Listeners/ShippingPolicyCacheListener.php` |
| `SyncOptionGroupsListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/SyncOptionGroupsListener.php` |
| `SyncProductFromOptionListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/SyncProductFromOptionListener.php` |
| `UserCurrencyInfoListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/UserCurrencyInfoListener.php` |
| `UserMileageCleanupListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/UserMileageCleanupListener.php` |
| `UserMileageInfoListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/UserMileageInfoListener.php` |
| `UserShippingCountryInfoListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/UserShippingCountryInfoListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
33개 리스너는 전부 `HookListenerInterface` 를 구현하고 `getSubscribedHooks()` 로 자기 구독을
선언합니다(명시 등록). 역할별로 네 무리입니다:

- **활동 로그 6종** (`EcommerceAdminActivityLogListener` 41훅 · `OrderActivityLogListener` 21 ·
  `EcommerceUserActivityLogListener` 9 · `Product`/`Coupon`/`Category`/`ShippingPolicy` 각 5~7):
  관리자·구매자 행위를 코어 `activity_logs` 단일 테이블에 기록합니다. 신규 `logActivity()` 를
  추가하면 코어 `lang/{ko,en}/activity_log.php` 의 action 라벨과 description 본문, 그리고
  번들 ja 팩까지 함께 정의해야 합니다.
- **캐시·색인 5종** (`SearchProductsListener` · `SeoProductCacheListener` ·
  `SeoCategoryCacheListener` · `SeoSettingsCacheListener` · `CategoryTreeCacheListener` ·
  `ShippingPolicyCacheListener`): 도메인 변경 시 검색 색인과 봇 화면 캐시를 무효화합니다.
- **금전·정합 5종** (`MileageTransactionListener` · `CouponUseListener` · `CouponRestoreListener` ·
  `UserMileageCleanupListener` · `IssueCashReceiptOnDepositListener`): 호출자 트랜잭션과 함께
  되돌아가야 하므로 `'sync' => true` 로 구독합니다.
- **연동·주입 나머지**: 장바구니 병합 · 가입 시 통화/배송국가 배정 · 문의 피벗 정리 · 옵션↔상품
  대표값 동기화 · 회원 정보 화면에 마일리지/통화/배송국가 주입.

구독 수가 0인 두 리스너(`CategoryTreeCacheListener` · `ShippingPolicyCacheListener`)는 훅
이름을 상수·변수로 조립해 정적 수집에 잡히지 않을 뿐 실제로는 등록되어 있습니다.

리스너에서 `Model::query()` · `DB::table()` · `$row->save()` 를 직접 부르지 않습니다 — 데이터
접근은 Repository 인터페이스 주입으로만 합니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/admin-user-currency-field.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/admin-user-mileage-tab.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/admin-user-shipping-country-field.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/admin_dashboard_commerce.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/admin_dashboard_quick_menu.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/checkout_cash_receipt.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/header-currency-selector-admin.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/header-currency-selector-user.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/mypage-profile-mileage-card.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/mypage_order_cash_receipt.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/register-currency-field.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/register-shipping-country-field.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
12개 조각은 전부 **다른 확장·템플릿이 소유한 화면에 끼워 넣는** 것입니다. 이 모듈이 코어나
템플릿 레이아웃을 직접 고치지 않기 위한 장치이며, 대상별로 세 무리입니다:

- **관리자 회원 화면**: 통화·배송국가 필드와 마일리지 탭 (`admin-user-*`)
- **관리자 대시보드**: 커머스 요약 위젯과 빠른 메뉴 (`admin_dashboard_*`)
- **템플릿 사용자 화면**: 헤더 통화 선택기 · 회원가입 폼의 통화/배송국가 필드 · 마이페이지
  마일리지 카드 · 주문서와 마이페이지의 현금영수증 영역

끼워 넣는 대상 화면을 소유한 쪽이 그 자리(슬롯)를 없애면 조각은 **오류 없이 사라집니다.**
그래서 대상 확장을 업그레이드한 뒤에는 이 조각들이 여전히 화면에 나타나는지 눈으로 확인해야
합니다. 반대로 새 조각을 추가할 때는 대상 화면이 그 자리를 제공하는지 먼저 확인합니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 미들웨어 | 부착 대상(targets) | 우선순위 |
|---|---|---|
| `DetectDevice` | `/` | - |
| `ResolveShippingCountry` | `api.modules.sirsoft-ecommerce.products.*`, `api.modules.sirsoft-ecommerce.cart.*`, `api.modules.sirsoft-ecommerce.checkout.*`, `api.modules.sirsoft-ecommerce.user.orders.store` | - |
| `VerifyGuestOrderToken` | `api.modules.sirsoft-ecommerce.guest.orders.cancel`, `api.modules.sirsoft-ecommerce.guest.orders.estimate-refund`, `api.modules.sirsoft-ecommerce.guest.orders.update-shipping-address`, `api.modules.sirsoft-ecommerce.guest.orders.confirm-option`, `api.modules.sirsoft-ecommerce.guest.orders.cash-receipt.*` | - |
<!-- @generated:middleware END -->

<!-- @intent START -->
세 미들웨어 모두 `getMiddleware()` 로 **부착 대상을 스스로 선언**합니다(self-gate). 커널
미들웨어 그룹을 직접 조작하거나 라우트 파일에 FQCN 을 붙이지 않습니다.

| 미들웨어 | 왜 필요한가 | 대상 |
|---|---|---|
| `DetectDevice` | 주문에 기기 유형(`DeviceTypeEnum`)을 남겨 매출을 채널별로 볼 수 있게 합니다 | 전역(`/`) — 유일한 광역 타게팅이며, 판정 결과를 요청에 실을 뿐 흐름을 바꾸지 않습니다 |
| `ResolveShippingCountry` | 배송 국가가 정해져야 배송비와 취급 통화가 결정됩니다. 상품·장바구니·체크아웃 응답이 국가에 따라 달라지는 이유입니다 | 상품·장바구니·체크아웃·주문 생성 라우트 |
| `VerifyGuestOrderToken` | 비회원 주문 조회·취소는 로그인 대신 발급된 토큰으로 신원을 증명합니다 | 비회원 주문 라우트 5종 |

`VerifyGuestOrderToken` 이 붙은 라우트를 새로 추가할 때는 **그 라우트 이름을 이 선언에 함께
추가**해야 합니다. 빠뜨리면 인증 없이 남의 주문에 접근할 수 있는 경로가 생기는데, 정상 응답이
나가므로 오류도 로그도 남지 않습니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
0개입니다. 이 모듈은 WebSocket 실시간 반영을 제공하지 않습니다 — 주문 알림은 전부 코어
`GenericNotification`(mail/database) 경유이고, 관리자 화면의 새 주문 표시는 화면 재조회로
갱신됩니다.

실시간이 필요하면 이 모듈이 발행하는 `order.*` action 훅을 구독해 소비하는 쪽에서
`HookManager::broadcast()` 로 자기 채널에 내보냅니다. 이 모듈에 채널을 추가하는 것이 아니라
그 방향이 맞습니다 — 상점마다 실시간이 필요한 화면이 다르기 때문입니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 스케줄 | 주기 | 설명 |
|---|---|---|
| `sirsoft-ecommerce:cancel-pending-orders` | `daily` | 입금 기한 만료 주문 자동 취소 |
| `sirsoft-ecommerce:prune-expired-carts` | `daily` | 보관기간 만료 장바구니 자동 삭제 |
| `sirsoft-ecommerce:prune-temp-product-images` | `daily` | 미연결 임시 상품 이미지 자동 삭제 |
| `sirsoft-ecommerce:prune-temp-orders` | `hourly` | 만료 임시 주문 자동 삭제 |
| `sirsoft-ecommerce:earn-mileage` | `hourly` | 지연 마일리지 적립 |
| `sirsoft-ecommerce:expire-mileage` | `daily` | 마일리지 자동 소멸 |
| `sirsoft-ecommerce:notify-expiring-mileage` | `daily` | 소멸 예정 마일리지 알림 |
| `sirsoft-ecommerce:reconcile-mileage-balance` | `daily` | 마일리지 잔액 캐시 정합 교정 |
| `sirsoft-ecommerce:aggregate-stats` | `hourly` | 대시보드 판매 현황 집계 |
<!-- @generated:schedules END -->

<!-- @intent START -->
9개 스케줄이 이 모듈의 **시간 축 동작 전부**입니다. 이 중 6개는 환경설정 토글
(`enabled_config`)에 묶여 있어 그 설정이 꺼져 있으면 실행되지 않습니다 —
`cancel-pending-orders`(주문 설정의 미입금 자동취소) · 마일리지 4종(마일리지 사용/소멸/소멸
알림) · `aggregate-stats`(대시보드 집계). 임시 데이터 정리 3종은 토글 없이 상시 동작하며 보존
기간을 커맨드 안에서 판정합니다. `enabled_config` 가 가리키는 설정 키가 없으면 **켜진 것으로
간주**되므로, 새 토글을 도입할 때는 설정 기본값을 먼저 넣어야 의도한 초기 상태가 됩니다.

| 커맨드 | 없으면 생기는 일 |
|---|---|
| `cancel-pending-orders` | 입금하지 않은 주문이 재고를 계속 점유합니다 |
| `prune-expired-carts` · `prune-temp-orders` · `prune-temp-product-images` | 임시 데이터가 무한히 쌓입니다 |
| `earn-mileage` | "배송완료 N일 후 적립" 같은 지연 적립이 영영 이루어지지 않습니다 |
| `expire-mileage` · `notify-expiring-mileage` | 소멸 기한이 지난 마일리지가 계속 사용 가능하고, 사전 안내가 나가지 않습니다 |
| `reconcile-mileage-balance` | 표시용 잔액 캐시가 원장과 어긋난 채 남습니다 (원장이 SSoT 이므로 금전 판정 자체는 안전합니다) |
| `aggregate-stats` | 대시보드 판매 현황이 갱신되지 않습니다 |

이 중 **재고와 금전에 직접 영향을 주는 것은 `cancel-pending-orders` 와 마일리지 3종**입니다.
서버에 스케줄러가 등록되지 않은 설치에서는 이 넷이 침묵하는 것이 유일한 증상이며, 오류가 나지
않으므로 운영자가 알아채기 어렵습니다.

새 스케줄을 추가할 때는 `command` 와 함께 **`schedule` 키를 반드시 선언**합니다 — 이 키가
없으면 코어 스케줄 등록부가 그 항목을 건너뛰는데, 예외도 경고도 남지 않아 "등록했는데 돌지
않는다"가 됩니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 알림 키 | 채널 |
|---|---|
| `order_confirmed` | `mail`, `database` |
| `order_pending_deposit` | `mail`, `database` |
| `order_shipped` | `mail`, `database` |
| `order_delivered` | `mail`, `database` |
| `order_completed` | `mail`, `database` |
| `order_cancelled` | `mail`, `database` |
| `new_order_admin` | `mail`, `database` |
| `inquiry_received` | `mail`, `database` |
| `inquiry_replied` | `mail`, `database` |
| `mileage_expiring_soon` | `mail`, `database` |
<!-- @generated:notifications END -->

<!-- @intent START -->
10종 모두 코어 `GenericNotification` 을 쓰며 개별 Notification 클래스를 두지 않습니다. 채널은
전부 `mail` + `database` 이고, 어느 채널로 보낼지는 환경설정 "알림" 탭에서 운영자가 정합니다.

수신자는 셋으로 갈립니다 — 구매자에게 가는 것(`order_confirmed` · `order_pending_deposit` ·
`order_shipped` · `order_delivered` · `order_completed` · `order_cancelled` ·
`mileage_expiring_soon` · `inquiry_replied`), 운영자에게 가는 것(`new_order_admin` ·
`inquiry_received`)입니다.

**비회원 주문도 알림을 받습니다.** 이때 리스너는 `trigger_user_id` 대신 context 표준 키
`guest_recipient: {email, name, locale}` 만 채우고, 수신자 해석·채널 게이트·발송은 전적으로
코어가 담당합니다. 채널별 비회원 발송 허용은 코어 `config/notification.php` 의 채널 메타
`allow_guest` 가 정하므로, 새 채널을 쓰려면 그 선언을 먼저 확인해야 합니다.

새 알림을 추가할 때는 이 선언과 함께 메일 템플릿(`ecommerce_mail_templates`)과 다국어 키가
필요합니다 — 선언만 추가하면 발송은 되지만 본문이 비어 나갑니다.
<!-- @intent END -->

## 활동 로그 훅

> 이 확장이 코어 활동 로그(`activity_logs`)에 기록을 남기기 위해 구독하는 훅 **92종**입니다
> (등록 건수는 94건 — `order.after_create` 와 `order-option.after_confirm` 은 관리자 관점과
> 구매자 관점 두 리스너가 각각 구독합니다).
> 코어 `docs/backend/activity-log-hooks.md` 에 있던 목록을 이 확장 소유로 옮긴 것입니다(#601) —
> 확장이 훅을 더할 때 코어 문서를 고쳐야 하던 역방향 의존을 없애기 위해서입니다. 코어 문서에는
> 총계와 이 문서로의 링크만 남습니다.

> 새 항목을 추가하면 코어 `lang/{ko,en}/activity_log.php` 의 action 라벨과 description 본문,
> 그리고 번들 일본어 팩까지 함께 정의해야 합니다 — **모듈 lang 파일에 넣으면 해석되지
> 않습니다.**

### 이커머스 모듈 훅

**모듈**: `sirsoft-ecommerce`
**등록 94건 / 훅 92종** (7개 Listener)

#### OrderActivityLogListener (21훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/OrderActivityLogListener.php`

##### OrderService (8훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.order.after_update` | `handleOrderAfterUpdate` | `order.update` | Admin | Order |
| `sirsoft-ecommerce.order.after_delete` | `handleOrderAfterDelete` | `order.delete` | Admin | Order |
| `sirsoft-ecommerce.order.after_bulk_update` | `handleOrderAfterBulkUpdate` | `order.bulk_update` | Admin | - |
| `sirsoft-ecommerce.order.after_bulk_status_update` | `handleOrderAfterBulkStatusUpdate` | `order.bulk_status_update` | Admin | - |
| `sirsoft-ecommerce.order.after_bulk_shipping_update` | `handleOrderAfterBulkShippingUpdate` | `order.bulk_shipping_update` | Admin | - |
| `sirsoft-ecommerce.order.after_update_shipping_address` | `handleOrderAfterUpdateShippingAddress` | `order.update_shipping_address` | Admin | Order |
| `sirsoft-ecommerce.order.after_send_email` | `handleOrderAfterSendEmail` | `order.send_email` | Admin | - |
| `sirsoft-ecommerce.order.after_reset_guest_password` | `handleOrderAfterResetGuestPassword` | `order.reset_guest_password` | Admin | Order |

##### OrderOptionService (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.order_option.after_status_change` | `handleOrderOptionAfterStatusChange` | `order_option.status_change` | Admin | OrderOption |
| `sirsoft-ecommerce.order_option.after_bulk_status_change` | `handleOrderOptionAfterBulkStatusChange` | `order_option.bulk_status_change` | Admin | - |

##### OrderCancellationService (4훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.order.after_cancel` | `handleOrderAfterCancel` | `order.cancel` | Admin | Order |
| `sirsoft-ecommerce.order.after_partial_cancel` | `handleOrderAfterPartialCancel` | `order.partial_cancel` | Admin | Order |
| `sirsoft-ecommerce.coupon.restore` | `handleCouponRestore` | `coupon.restore` | Admin | Order |
| `sirsoft-ecommerce.mileage.restore` | `handleMileageRestore` | `mileage.restore` | Admin | Order |

##### OrderService (구매확인) (1훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.order-option.after_confirm` | `handleOrderOptionAfterConfirm` | `order_option.confirm` | Admin | OrderOption |

##### OrderProcessingService (6훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.order.after_create` | `handleOrderAfterCreate` | `order.create` | **User** | Order |
| `sirsoft-ecommerce.order.after_payment_complete` | `handleOrderAfterPaymentComplete` | `order.payment_complete` | **User** | Order |
| `sirsoft-ecommerce.order.payment_failed` | `handleOrderAfterPaymentFailed` | `order.payment_failed` | **User** | Order |
| `sirsoft-ecommerce.coupon.use` | `handleCouponUse` | `coupon.use` | **User** | Order |
| `sirsoft-ecommerce.mileage.use` | `handleMileageUse` | `mileage.use` | **User** | Order |
| `sirsoft-ecommerce.mileage.earn` | `handleMileageEarn` | `mileage.earn` | **User** | Order |

#### ProductActivityLogListener (7훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/ProductActivityLogListener.php`

> 이 리스너는 코어 표준 패턴(`ResolvesActivityLogType` + `ChangeDetector`)을 씁니다.
> 수정 전 스냅샷은 이 리스너가 `before_*` 훅으로 직접 잡지 않고, **Service 가 잡아
> `after_*` 훅의 인자로 넘깁니다**(`ProductService::update()` → `product.after_update`).
> 그래서 이 표에 `before_*` 훅이 없습니다 — `before_*` 는 발행되지만 이 리스너의
> 구독 대상이 아니며, 그 목록은 위 「발행 훅」 절에 있습니다.

| 훅 이름 | Listener 메서드 | Priority | 비고 |
|---------|----------------|----------|------|
| `sirsoft-ecommerce.product.after_create` | `handleProductAfterCreate` | 50 | 상품 생성 로그 |
| `sirsoft-ecommerce.product.after_update` | `handleProductAfterUpdate` | 50 | 변경사항 비교 후 로그 |
| `sirsoft-ecommerce.product.after_delete` | `handleProductAfterDelete` | 20 | 삭제 후 로그 |
| `sirsoft-ecommerce.product.after_bulk_update` | `handleProductAfterBulkUpdate` | 20 | 일괄 수정 로그 |
| `sirsoft-ecommerce.product.after_bulk_price_update` | `handleProductAfterBulkPriceUpdate` | 20 | 일괄 가격 수정 로그 |
| `sirsoft-ecommerce.product.after_bulk_stock_update` | `handleProductAfterBulkStockUpdate` | 20 | 일괄 재고 수정 로그 |
| `sirsoft-ecommerce.product.after_stock_sync` | `handleProductAfterStockSync` | 20 | 재고 동기화 로그 |

#### CouponActivityLogListener (6훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/CouponActivityLogListener.php`

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.coupon.after_create` | `handleAfterCreate` | `coupon.create` | Admin | Coupon |
| `sirsoft-ecommerce.coupon.after_update` | `handleAfterUpdate` | `coupon.update` | Admin | Coupon |
| `sirsoft-ecommerce.coupon.after_delete` | `handleAfterDelete` | `coupon.delete` | Admin | - |
| `sirsoft-ecommerce.coupon.after_bulk_status` | `handleAfterBulkStatus` | `coupon.bulk_status` | Admin | - |
| `sirsoft-ecommerce.coupon.after_direct_issue` | `handleAfterDirectIssue` | `coupon.direct_issue` | Admin | CouponIssue |
| `sirsoft-ecommerce.coupon.after_issue_cancel` | `handleAfterIssueCancel` | `coupon.issue_cancel` | Admin | CouponIssue |

#### ShippingPolicyActivityLogListener (5훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/ShippingPolicyActivityLogListener.php`

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.shipping_policy.after_create` | `handleAfterCreate` | `shipping_policy.create` | Admin | ShippingPolicy |
| `sirsoft-ecommerce.shipping_policy.after_update` | `handleAfterUpdate` | `shipping_policy.update` | Admin | ShippingPolicy |
| `sirsoft-ecommerce.shipping_policy.after_delete` | `handleAfterDelete` | `shipping_policy.delete` | Admin | - |
| `sirsoft-ecommerce.shipping_policy.after_toggle_active` | `handleAfterToggleActive` | `shipping_policy.toggle_active` | Admin | ShippingPolicy |
| `sirsoft-ecommerce.shipping_policy.after_set_default` | `handleAfterSetDefault` | `shipping_policy.set_default` | Admin | ShippingPolicy |

#### CategoryActivityLogListener (5훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/CategoryActivityLogListener.php`

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.category.after_create` | `handleAfterCreate` | `category.create` | Admin | Category |
| `sirsoft-ecommerce.category.after_update` | `handleAfterUpdate` | `category.update` | Admin | Category |
| `sirsoft-ecommerce.category.after_delete` | `handleAfterDelete` | `category.delete` | Admin | - |
| `sirsoft-ecommerce.category.after_toggle_status` | `handleAfterToggleStatus` | `category.toggle_status` | Admin | Category |
| `sirsoft-ecommerce.category.after_reorder` | `handleAfterReorder` | `category.reorder` | Admin | - |

#### EcommerceAdminActivityLogListener (41훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/EcommerceAdminActivityLogListener.php`

##### Brand (4훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.brand.after_create` | `handleBrandAfterCreate` | `brand.create` | Admin | Brand |
| `sirsoft-ecommerce.brand.after_update` | `handleBrandAfterUpdate` | `brand.update` | Admin | Brand |
| `sirsoft-ecommerce.brand.after_delete` | `handleBrandAfterDelete` | `brand.delete` | Admin | Brand |
| `sirsoft-ecommerce.brand.after_toggle_status` | `handleBrandAfterToggleStatus` | `brand.toggle_status` | Admin | Brand |

##### ProductLabel (4훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.label.after_create` | `handleLabelAfterCreate` | `label.create` | Admin | ProductLabel |
| `sirsoft-ecommerce.label.after_update` | `handleLabelAfterUpdate` | `label.update` | Admin | ProductLabel |
| `sirsoft-ecommerce.label.after_delete` | `handleLabelAfterDelete` | `label.delete` | Admin | ProductLabel |
| `sirsoft-ecommerce.label.after_toggle_status` | `handleLabelAfterToggleStatus` | `label.toggle_status` | Admin | ProductLabel |

##### ProductCommonInfo (3훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.product-common-info.after_create` | `handleCommonInfoAfterCreate` | `common_info.create` | Admin | ProductCommonInfo |
| `sirsoft-ecommerce.product-common-info.after_update` | `handleCommonInfoAfterUpdate` | `common_info.update` | Admin | ProductCommonInfo |
| `sirsoft-ecommerce.product-common-info.after_delete` | `handleCommonInfoAfterDelete` | `common_info.delete` | Admin | ProductCommonInfo |

##### ProductNoticeTemplate (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.product-notice-template.after_create` | `handleNoticeTemplateAfterCreate` | `notice_template.create` | Admin | ProductNoticeTemplate |
| `sirsoft-ecommerce.product-notice-template.after_update` | `handleNoticeTemplateAfterUpdate` | `notice_template.update` | Admin | ProductNoticeTemplate |
| `sirsoft-ecommerce.product-notice-template.after_delete` | `handleNoticeTemplateAfterDelete` | `notice_template.delete` | Admin | ProductNoticeTemplate |
| `sirsoft-ecommerce.product-notice-template.after_copy` | `handleNoticeTemplateAfterCopy` | `notice_template.copy` | Admin | ProductNoticeTemplate |
| `sirsoft-ecommerce.product-notice-template.after_toggle_active` | `handleNoticeTemplateAfterToggleActive` | `product_notice_template.toggle_active` | Admin | ProductNoticeTemplate |

##### ExtraFeeTemplate (7훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.extra_fee_template.after_create` | `handleExtraFeeAfterCreate` | `extra_fee_template.create` | Admin | ExtraFeeTemplate |
| `sirsoft-ecommerce.extra_fee_template.after_update` | `handleExtraFeeAfterUpdate` | `extra_fee_template.update` | Admin | ExtraFeeTemplate |
| `sirsoft-ecommerce.extra_fee_template.after_delete` | `handleExtraFeeAfterDelete` | `extra_fee_template.delete` | Admin | ExtraFeeTemplate |
| `sirsoft-ecommerce.extra_fee_template.after_toggle_active` | `handleExtraFeeAfterToggleActive` | `extra_fee_template.toggle_active` | Admin | ExtraFeeTemplate |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_delete` | `handleExtraFeeAfterBulkDelete` | `extra_fee_template.bulk_delete` | Admin | - |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_toggle_active` | `handleExtraFeeAfterBulkToggleActive` | `extra_fee_template.bulk_toggle_active` | Admin | - |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_create` | `handleExtraFeeAfterBulkCreate` | `extra_fee_template.bulk_create` | Admin | - |

##### ShippingCarrier (4훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.shipping_carrier.after_create` | `handleCarrierAfterCreate` | `shipping_carrier.create` | Admin | ShippingCarrier |
| `sirsoft-ecommerce.shipping_carrier.after_update` | `handleCarrierAfterUpdate` | `shipping_carrier.update` | Admin | ShippingCarrier |
| `sirsoft-ecommerce.shipping_carrier.after_delete` | `handleCarrierAfterDelete` | `shipping_carrier.delete` | Admin | ShippingCarrier |
| `sirsoft-ecommerce.shipping_carrier.after_toggle_status` | `handleCarrierAfterToggleStatus` | `shipping_carrier.toggle_status` | Admin | ShippingCarrier |

##### ProductImage (3훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.product-image.after_upload` | `handleImageAfterUpload` | `product_image.upload` | Admin | ProductImage |
| `sirsoft-ecommerce.product-image.after_delete` | `handleImageAfterDelete` | `product_image.delete` | Admin | ProductImage |
| `sirsoft-ecommerce.product-image.after_reorder` | `handleImageAfterReorder` | `product_image.reorder` | Admin | - |

##### ShippingPolicy Bulk (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.shipping_policy.after_bulk_delete` | `handleShippingPolicyAfterBulkDelete` | `shipping_policy.bulk_delete` | Admin | - |
| `sirsoft-ecommerce.shipping_policy.after_bulk_toggle_active` | `handleShippingPolicyAfterBulkToggleActive` | `shipping_policy.bulk_toggle_active` | Admin | - |

##### ProductOption (3훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.product_option.after_bulk_price_update` | `handleOptionAfterBulkPriceUpdate` | `product_option.bulk_price_update` | Admin | - |
| `sirsoft-ecommerce.product_option.after_bulk_stock_update` | `handleOptionAfterBulkStockUpdate` | `product_option.bulk_stock_update` | Admin | - |
| `sirsoft-ecommerce.option.after_bulk_update` | `handleOptionAfterBulkUpdate` | `product_option.bulk_update` | Admin | - |

##### ProductReview (3훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.product-review.after_create` | `handleReviewAfterCreate` | `review.create` | Admin | ProductReview |
| `sirsoft-ecommerce.product-review.after_delete` | `handleReviewAfterDelete` | `review.delete` | Admin | ProductReview |
| `sirsoft-ecommerce.product-review.after_bulk_delete` | `handleReviewAfterBulkDelete` | `product_review.bulk_delete` | Admin | - |

##### 설정 · 회원 기준값 (3훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.settings.after_save` | `handleSettingsAfterSave` | `ecommerce_settings.update` | Admin | - |
| `sirsoft-ecommerce.admin.user_currency.changed` | `handleUserCurrencyChanged` | `user_currency.change` | Admin | User |
| `sirsoft-ecommerce.admin.user_shipping_country.changed` | `handleUserShippingCountryChanged` | `user_shipping_country.change` | Admin | User |

#### EcommerceUserActivityLogListener (9훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/EcommerceUserActivityLogListener.php`

##### Cart (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.cart.after_add` | `handleCartAfterAdd` | `cart.add` | **User** | Cart |
| `sirsoft-ecommerce.cart.after_update_quantity` | `handleCartAfterUpdateQuantity` | `cart.update_quantity` | **User** | Cart |
| `sirsoft-ecommerce.cart.after_change_option` | `handleCartAfterChangeOption` | `cart.change_option` | **User** | Cart |
| `sirsoft-ecommerce.cart.after_delete` | `handleCartAfterDelete` | `cart.delete` | **User** | - |
| `sirsoft-ecommerce.cart.after_delete_all` | `handleCartAfterDeleteAll` | `cart.delete_all` | **User** | - |

##### Wishlist (1훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.wishlist.after_toggle` | `handleWishlistAfterToggle` | `wishlist.add` / `wishlist.remove` | **User** | Product |

##### User Coupon (1훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.user_coupon.after_download` | `handleUserCouponAfterDownload` | `user_coupon.download` | **User** | CouponIssue |

##### 주문 (구매자 관점) (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.order.after_create` | `handleOrderAfterCreate` | `order.create` | **User** | Order |
| `sirsoft-ecommerce.order-option.after_confirm` | `handleOrderOptionAfterConfirm` | `order_option.confirm` | **User** | OrderOption |
