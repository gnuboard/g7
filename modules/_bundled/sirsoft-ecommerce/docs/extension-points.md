# 이커머스 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 585종 / 호출 지점 683곳. 이 중 583종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다. 훅 이름이 상수·변수로 조립된 호출이 21곳 있어 호출 위치가 표에 다 실리지 않을 수 있습니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `core.module_settings.after_save` | action | — | `src/Http/Controllers/Admin/EcommerceSettingsController.php:169` 외 1곳 |
| `sirsoft-ecommerce.adjustment.filter_restore_promotions` | filter | — | `src/Services/OrderAdjustmentService.php:841` |
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
| `sirsoft-ecommerce.calculation.after_final_result` | filter | — | `src/Services/OrderCalculationService.php:376` |
| `sirsoft-ecommerce.calculation.after_item_subtotals` | filter | — | `src/Services/OrderCalculationService.php:173` |
| `sirsoft-ecommerce.calculation.after_order_discount` | filter | — | `src/Services/OrderCalculationService.php:277` |
| `sirsoft-ecommerce.calculation.after_payment_amount` | filter | — | `src/Services/OrderCalculationService.php:300` |
| `sirsoft-ecommerce.calculation.after_points_earning` | filter | — | `src/Services/OrderCalculationService.php:329` |
| `sirsoft-ecommerce.calculation.after_points_usage` | filter | — | `src/Services/OrderCalculationService.php:314` |
| `sirsoft-ecommerce.calculation.after_product_discount` | filter | — | `src/Services/OrderCalculationService.php:197` |
| `sirsoft-ecommerce.calculation.after_shipping_discount` | filter | — | `src/Services/OrderCalculationService.php:254` |
| `sirsoft-ecommerce.calculation.after_shipping_fee` | filter | — | `src/Services/OrderCalculationService.php:232` |
| `sirsoft-ecommerce.calculation.after_tax_classification` | filter | — | `src/Services/OrderCalculationService.php:207` |
| `sirsoft-ecommerce.calculation.before_final_result` | filter | — | `src/Services/OrderCalculationService.php:340` |
| `sirsoft-ecommerce.calculation.before_item_subtotals` | filter | — | `src/Services/OrderCalculationService.php:166` |
| `sirsoft-ecommerce.calculation.before_order_discount` | filter | — | `src/Services/OrderCalculationService.php:264` |
| `sirsoft-ecommerce.calculation.before_payment_amount` | filter | — | `src/Services/OrderCalculationService.php:287` |
| `sirsoft-ecommerce.calculation.before_product_discount` | filter | — | `src/Services/OrderCalculationService.php:182` |
| `sirsoft-ecommerce.calculation.before_shipping_discount` | filter | — | `src/Services/OrderCalculationService.php:242` |
| `sirsoft-ecommerce.calculation.before_shipping_fee` | filter | — | `src/Services/OrderCalculationService.php:216` |
| `sirsoft-ecommerce.calculation.filter_promotions_snapshot` | filter | — | `src/Services/OrderAdjustmentService.php:1356` 외 1곳 |
| `sirsoft-ecommerce.cart.after_add` | action | — | `src/Services/CartService.php:268` |
| `sirsoft-ecommerce.cart.after_bulk_delete` | action | — | `src/Services/CartService.php:1113` |
| `sirsoft-ecommerce.cart.after_change_option` | action | — | `src/Services/CartService.php:481` |
| `sirsoft-ecommerce.cart.after_delete` | action | — | `src/Services/CartService.php:514` |
| `sirsoft-ecommerce.cart.after_delete_all` | action | — | `src/Services/CartService.php:813` |
| `sirsoft-ecommerce.cart.after_delete_multiple` | action | — | `src/Services/CartService.php:546` |
| `sirsoft-ecommerce.cart.after_list` | action | — | `src/Services/CartService.php:87` |
| `sirsoft-ecommerce.cart.after_merge` | action | — | `src/Services/CartService.php:678` |
| `sirsoft-ecommerce.cart.after_reorder` | action | — | `src/Services/CartService.php:771` |
| `sirsoft-ecommerce.cart.after_update_quantity` | action | — | `src/Services/CartService.php:388` |
| `sirsoft-ecommerce.cart.before_add` | action | — | `src/Services/CartService.php:199` |
| `sirsoft-ecommerce.cart.before_bulk_delete` | action | — | `src/Services/CartService.php:1109` |
| `sirsoft-ecommerce.cart.before_change_option` | action | — | `src/Services/CartService.php:460` |
| `sirsoft-ecommerce.cart.before_delete` | action | — | `src/Services/CartService.php:508` |
| `sirsoft-ecommerce.cart.before_delete_all` | action | — | `src/Services/CartService.php:801` |
| `sirsoft-ecommerce.cart.before_delete_multiple` | action | — | `src/Services/CartService.php:529` |
| `sirsoft-ecommerce.cart.before_list` | action | — | `src/Services/CartService.php:75` |
| `sirsoft-ecommerce.cart.before_merge` | action | — | `src/Services/CartService.php:560` |
| `sirsoft-ecommerce.cart.before_reorder` | action | — | `src/Services/CartService.php:711` |
| `sirsoft-ecommerce.cart.before_update_quantity` | action | — | `src/Services/CartService.php:382` |
| `sirsoft-ecommerce.cart.bulk_add_validation_rules` | filter | — | `src/Http/Requests/Public/BulkAddToCartRequest.php:51` |
| `sirsoft-ecommerce.cart.change_option_validation_rules` | filter | — | `src/Http/Requests/Public/ChangeCartOptionRequest.php:46` |
| `sirsoft-ecommerce.cart.delete_items_validation_rules` | filter | — | `src/Http/Requests/Public/DeleteCartItemsRequest.php:35` |
| `sirsoft-ecommerce.cart.filter_add_data` | filter | — | `src/Services/CartService.php:201` |
| `sirsoft-ecommerce.cart.filter_list_result` | filter | — | `src/Services/CartService.php:85` |
| `sirsoft-ecommerce.cart.get_validation_rules` | filter | — | `src/Http/Requests/Public/GetCartRequest.php:35` |
| `sirsoft-ecommerce.cart.update_quantity_validation_rules` | filter | — | `src/Http/Requests/Public/UpdateCartQuantityRequest.php:37` |
| `sirsoft-ecommerce.cash_receipt.cancel` | filter | — | `src/Services/CashReceiptService.php:176` |
| `sirsoft-ecommerce.cash_receipt.issue` | filter | — | `src/Services/CashReceiptService.php:108` |
| `sirsoft-ecommerce.cash_receipt.registered_providers` | filter | — | `src/Services/EcommerceSettingsService.php:1019` |
| `sirsoft-ecommerce.category-image.after_delete` | action | — | `src/Services/CategoryImageService.php:252` 외 1곳 |
| `sirsoft-ecommerce.category-image.after_reorder` | action | — | `src/Services/CategoryImageService.php:314` |
| `sirsoft-ecommerce.category-image.after_update` | action | — | `src/Services/CategoryImageService.php:390` |
| `sirsoft-ecommerce.category-image.after_upload` | action | — | `src/Services/CategoryImageService.php:129` |
| `sirsoft-ecommerce.category-image.before_delete` | action | — | `src/Services/CategoryImageService.php:228` 외 1곳 |
| `sirsoft-ecommerce.category-image.before_reorder` | action | — | `src/Services/CategoryImageService.php:309` |
| `sirsoft-ecommerce.category-image.before_update` | action | — | `src/Services/CategoryImageService.php:377` |
| `sirsoft-ecommerce.category-image.before_upload` | action | — | `src/Services/CategoryImageService.php:67` |
| `sirsoft-ecommerce.category-image.filter_reorder_validation_rules` | filter | — | `src/Http/Requests/Admin/ReorderCategoryImagesRequest.php:36` |
| `sirsoft-ecommerce.category-image.filter_update_data` | filter | — | `src/Services/CategoryImageService.php:380` |
| `sirsoft-ecommerce.category-image.filter_upload_file` | filter | — | `src/Services/CategoryImageService.php:70` |
| `sirsoft-ecommerce.category-image.filter_upload_validation_rules` | filter | — | `src/Http/Requests/Admin/UploadCategoryImageRequest.php:66` |
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
| `sirsoft-ecommerce.category.create_validation_rules` | filter | — | `src/Http/Requests/Admin/CreateCategoryRequest.php:78` |
| `sirsoft-ecommerce.category.filter_create_data` | filter | — | `src/Services/CategoryService.php:147` |
| `sirsoft-ecommerce.category.filter_list_query` | filter | — | `src/Services/CategoryService.php:37` |
| `sirsoft-ecommerce.category.filter_list_result` | filter | — | `src/Services/CategoryService.php:50` |
| `sirsoft-ecommerce.category.filter_public_list_result` | filter | — | `src/Services/CategoryService.php:71` |
| `sirsoft-ecommerce.category.filter_public_show_result` | filter | — | `src/Services/CategoryService.php:100` |
| `sirsoft-ecommerce.category.filter_show_result` | filter | — | `src/Services/CategoryService.php:126` |
| `sirsoft-ecommerce.category.filter_update_data` | filter | — | `src/Services/CategoryService.php:204` |
| `sirsoft-ecommerce.category.reorder_validation_rules` | filter | — | `src/Http/Requests/Admin/ReorderCategoriesRequest.php:50` |
| `sirsoft-ecommerce.category.update_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateCategoryRequest.php:82` |
| `sirsoft-ecommerce.checkout.before_payment` | action | — | `src/Http/Controllers/Traits/HandlesOrderCreation.php:84` |
| `sirsoft-ecommerce.checkout.filter_response_data` | filter | — | `src/Services/CheckoutDataService.php:96` |
| `sirsoft-ecommerce.checkout.update_validation_rules` | filter | — | `src/Http/Requests/Public/UpdateCheckoutRequest.php:55` |
| `sirsoft-ecommerce.checkout.validation_rules` | filter | — | `src/Http/Requests/Public/CheckoutRequest.php:70` |
| `sirsoft-ecommerce.claim-image.after_delete` | action | — | `src/Services/ClaimAttachmentService.php:338` |
| `sirsoft-ecommerce.claim-image.after_upload` | action | — | `src/Services/ClaimAttachmentService.php:722` |
| `sirsoft-ecommerce.claim-image.before_upload` | action | — | `src/Services/ClaimAttachmentService.php:659` |
| `sirsoft-ecommerce.claim-image.filter_upload_file` | filter | — | `src/Services/ClaimAttachmentService.php:667` |
| `sirsoft-ecommerce.claim.` | action | — | `src/Services/ClaimPaymentService.php:718` 외 1곳 |
| `sirsoft-ecommerce.claim.cancel.option_status_changed` | action | — | `src/Services/OrderCancellationService.php:783` |
| `sirsoft-ecommerce.claim.cancel.requested` | action | — | `src/Services/OrderCancellationService.php:659` |
| `sirsoft-ecommerce.claim.cancel.status_changed` | action | — | `src/Services/OrderCancellationService.php:764` |
| `sirsoft-ecommerce.claim.exchange.after_mode_change` | action | — | `src/Services/OrderExchangeService.php:1134` |
| `sirsoft-ecommerce.claim.exchange.after_tracking_deliver` | action | — | `src/Services/OrderExchangeService.php:1316` |
| `sirsoft-ecommerce.claim.exchange.before_complete` | action | — | `src/Services/OrderExchangeService.php:1805` |
| `sirsoft-ecommerce.claim.exchange.before_mode_change` | action | — | `src/Services/OrderExchangeService.php:1105` |
| `sirsoft-ecommerce.claim.exchange.before_option_status_change` | action | — | `src/Services/OrderExchangeService.php:864` |
| `sirsoft-ecommerce.claim.exchange.before_request` | action | — | `src/Services/OrderExchangeService.php:306` |
| `sirsoft-ecommerce.claim.exchange.before_status_change` | action | — | `src/Services/OrderExchangeService.php:610` 외 1곳 |
| `sirsoft-ecommerce.claim.exchange.before_update_request` | action | — | `src/Services/OrderExchangeService.php:4080` |
| `sirsoft-ecommerce.claim.exchange.before_withdraw` | action | — | `src/Services/OrderExchangeService.php:501` |
| `sirsoft-ecommerce.claim.exchange.collection_overdue` | action | — | `src/Services/OrderExchangeService.php:1190` |
| `sirsoft-ecommerce.claim.exchange.filter_complete_options` | filter | — | `src/Services/OrderExchangeService.php:1807` |
| `sirsoft-ecommerce.claim.exchange.filter_request_data` | filter | — | `src/Services/OrderExchangeService.php:309` |
| `sirsoft-ecommerce.claim.exchange.filter_status_change_data` | filter | — | `src/Services/OrderExchangeService.php:613` 외 1곳 |
| `sirsoft-ecommerce.claim.exchange.filter_update_request_data` | filter | — | `src/Services/OrderExchangeService.php:4083` |
| `sirsoft-ecommerce.claim.exchange.option_status_changed` | action | — | `src/Services/OrderExchangeService.php:997` 외 2곳 |
| `sirsoft-ecommerce.claim.exchange.request_updated` | action | — | `src/Services/OrderExchangeService.php:4208` |
| `sirsoft-ecommerce.claim.exchange.requested` | action | — | `src/Services/OrderExchangeService.php:420` |
| `sirsoft-ecommerce.claim.exchange.status_changed` | action | — | `src/Services/OrderExchangeService.php:3175` |
| `sirsoft-ecommerce.claim.payment_cancelled` | action | — | `src/Services/ClaimPaymentService.php:1025` |
| `sirsoft-ecommerce.claim.refund.before_complete` | action | — | `src/Services/OrderRefundRequestService.php:1111` |
| `sirsoft-ecommerce.claim.refund.before_option_status_change` | action | — | `src/Services/OrderRefundRequestService.php:1883` |
| `sirsoft-ecommerce.claim.refund.before_request` | action | — | `src/Services/OrderRefundRequestService.php:305` |
| `sirsoft-ecommerce.claim.refund.before_status_change` | action | — | `src/Services/OrderRefundRequestService.php:1713` 외 1곳 |
| `sirsoft-ecommerce.claim.refund.before_update_request` | action | — | `src/Services/OrderRefundRequestService.php:856` |
| `sirsoft-ecommerce.claim.refund.before_withdraw` | action | — | `src/Services/OrderRefundRequestService.php:826` |
| `sirsoft-ecommerce.claim.refund.completed` | action | — | `src/Services/OrderRefundRequestService.php:1328` |
| `sirsoft-ecommerce.claim.refund.filter_request_data` | filter | — | `src/Services/OrderRefundRequestService.php:308` |
| `sirsoft-ecommerce.claim.refund.filter_status_change_data` | filter | — | `src/Services/OrderRefundRequestService.php:1716` 외 1곳 |
| `sirsoft-ecommerce.claim.refund.filter_update_request_data` | filter | — | `src/Services/OrderRefundRequestService.php:859` |
| `sirsoft-ecommerce.claim.refund.option_status_changed` | action | — | `src/Services/OrderRefundRequestService.php:1312` 외 3곳 |
| `sirsoft-ecommerce.claim.refund.request_updated` | action | — | `src/Services/OrderRefundRequestService.php:905` |
| `sirsoft-ecommerce.claim.refund.requested` | action | — | `src/Services/OrderExchangeService.php:3832` 외 2곳 |
| `sirsoft-ecommerce.claim.refund.status_changed` | action | — | `src/Services/OrderRefundRequestService.php:1321` 외 2곳 |
| `sirsoft-ecommerce.claim.return.after_tracking_deliver` | action | — | `src/Services/OrderReturnService.php:1098` |
| `sirsoft-ecommerce.claim.return.before_complete` | action | — | `src/Services/OrderReturnService.php:1454` |
| `sirsoft-ecommerce.claim.return.before_option_status_change` | action | — | `src/Services/OrderReturnService.php:823` |
| `sirsoft-ecommerce.claim.return.before_request` | action | — | `src/Services/OrderReturnService.php:331` |
| `sirsoft-ecommerce.claim.return.before_status_change` | action | — | `src/Services/OrderReturnService.php:606` 외 1곳 |
| `sirsoft-ecommerce.claim.return.before_update_request` | action | — | `src/Services/OrderReturnService.php:3128` |
| `sirsoft-ecommerce.claim.return.before_withdraw` | action | — | `src/Services/OrderReturnService.php:508` |
| `sirsoft-ecommerce.claim.return.filter_complete_options` | filter | — | `src/Services/OrderReturnService.php:1456` |
| `sirsoft-ecommerce.claim.return.filter_request_data` | filter | — | `src/Services/OrderReturnService.php:334` |
| `sirsoft-ecommerce.claim.return.filter_status_change_data` | filter | — | `src/Services/OrderReturnService.php:609` 외 1곳 |
| `sirsoft-ecommerce.claim.return.filter_update_request_data` | filter | — | `src/Services/OrderReturnService.php:3131` |
| `sirsoft-ecommerce.claim.return.option_status_changed` | action | — | `src/Services/OrderReturnService.php:953` 외 2곳 |
| `sirsoft-ecommerce.claim.return.request_updated` | action | — | `src/Services/OrderReturnService.php:3235` |
| `sirsoft-ecommerce.claim.return.requested` | action | — | `src/Services/OrderReturnService.php:415` |
| `sirsoft-ecommerce.claim.return.status_changed` | action | — | `src/Services/OrderReturnService.php:2449` |
| `sirsoft-ecommerce.claim_payment.approve` | filter | — | `src/Services/ClaimPaymentService.php:427` |
| `sirsoft-ecommerce.claim_payment.refund` | filter | — | `src/Services/ClaimPaymentService.php:1209` |
| `sirsoft-ecommerce.claim_payment.void_approval` | filter | — | `src/Services/ClaimPaymentService.php:518` |
| `sirsoft-ecommerce.claim_reason.after_create` | action | — | `src/Services/ClaimReasonService.php:184` 외 1곳 |
| `sirsoft-ecommerce.claim_reason.after_delete` | action | — | `src/Services/ClaimReasonService.php:382` 외 1곳 |
| `sirsoft-ecommerce.claim_reason.after_list` | action | — | `src/Services/ClaimReasonService.php:93` |
| `sirsoft-ecommerce.claim_reason.after_show` | action | — | `src/Services/ClaimReasonService.php:112` |
| `sirsoft-ecommerce.claim_reason.after_toggle_status` | action | — | `src/Services/ClaimReasonService.php:274` |
| `sirsoft-ecommerce.claim_reason.after_update` | action | — | `src/Services/ClaimReasonService.php:245` 외 1곳 |
| `sirsoft-ecommerce.claim_reason.before_create` | action | — | `src/Services/ClaimReasonService.php:171` 외 1곳 |
| `sirsoft-ecommerce.claim_reason.before_delete` | action | — | `src/Services/ClaimReasonService.php:380` 외 1곳 |
| `sirsoft-ecommerce.claim_reason.before_list` | action | — | `src/Services/ClaimReasonService.php:85` |
| `sirsoft-ecommerce.claim_reason.before_show` | action | — | `src/Services/ClaimReasonService.php:106` |
| `sirsoft-ecommerce.claim_reason.before_toggle_status` | action | — | `src/Services/ClaimReasonService.php:266` |
| `sirsoft-ecommerce.claim_reason.before_update` | action | — | `src/Services/ClaimReasonService.php:206` 외 1곳 |
| `sirsoft-ecommerce.claim_reason.filter_create_data` | filter | — | `src/Services/ClaimReasonService.php:173` 외 1곳 |
| `sirsoft-ecommerce.claim_reason.filter_list_query` | filter | — | `src/Services/ClaimReasonService.php:87` |
| `sirsoft-ecommerce.claim_reason.filter_list_result` | filter | — | `src/Services/ClaimReasonService.php:91` |
| `sirsoft-ecommerce.claim_reason.filter_show_result` | filter | — | `src/Services/ClaimReasonService.php:111` |
| `sirsoft-ecommerce.claim_reason.filter_update_data` | filter | — | `src/Services/ClaimReasonService.php:208` 외 1곳 |
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
| `sirsoft-ecommerce.coupon.restore` | action | — | `src/Services/RefundExecutionService.php:508` |
| `sirsoft-ecommerce.coupon.update_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateCouponRequest.php:144` |
| `sirsoft-ecommerce.coupon.use` | action | — | `src/Services/OrderProcessingService.php:222` |
| `sirsoft-ecommerce.coupon.user_available_validation_rules` | filter | — | `src/Http/Requests/User/UserCouponAvailableRequest.php:35` |
| `sirsoft-ecommerce.coupon.user_downloadable_validation_rules` | filter | — | `src/Http/Requests/User/UserCouponDownloadableRequest.php:34` |
| `sirsoft-ecommerce.coupon.user_list_validation_rules` | filter | — | `src/Http/Requests/User/UserCouponListRequest.php:35` |
| `sirsoft-ecommerce.currency.after_apply_rates` | action | 환율 적용 후 | `src/Services/EcommerceSettingsService.php:990` |
| `sirsoft-ecommerce.currency.registered_rate_providers` | filter | 환율 제공자 목록 | `src/Services/EcommerceSettingsService.php:921` |
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
| `sirsoft-ecommerce.inquiry.create` | filter | — | `src/Services/ProductInquiryService.php:278` 외 1곳 |
| `sirsoft-ecommerce.inquiry.delete` | filter | — | `src/Services/ProductInquiryService.php:478` 외 2곳 |
| `sirsoft-ecommerce.inquiry.delete_reply` | filter | — | `src/Services/ProductInquiryService.php:560` |
| `sirsoft-ecommerce.inquiry.get_by_ids` | filter | — | `src/Services/ProductInquiryService.php:200` 외 1곳 |
| `sirsoft-ecommerce.inquiry.get_settings` | filter | — | `src/Listeners/ProductInquiryBoardListener.php:270` 외 2곳 |
| `sirsoft-ecommerce.inquiry.store_validation_messages` | filter | — | `src/Http/Requests/Public/StoreInquiryRequest.php:60` |
| `sirsoft-ecommerce.inquiry.store_validation_rules` | filter | — | `src/Http/Requests/Public/StoreInquiryRequest.php:44` |
| `sirsoft-ecommerce.inquiry.update` | filter | — | `src/Services/ProductInquiryService.php:436` |
| `sirsoft-ecommerce.inquiry.update_reply` | filter | — | `src/Services/ProductInquiryService.php:519` |
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
| `sirsoft-ecommerce.mileage.earn` | action | — | `src/Services/OrderProcessingService.php:363` 외 1곳 |
| `sirsoft-ecommerce.mileage.max_usable_validation_rules` | filter | — | `src/Http/Requests/User/UserMileageMaxUsableRequest.php:34` |
| `sirsoft-ecommerce.mileage.notify_expiring` | action | — | `src/Console/Commands/NotifyExpiringMileageCommand.php:88` |
| `sirsoft-ecommerce.mileage.restore` | action | — | `src/Services/RefundExecutionService.php:551` |
| `sirsoft-ecommerce.mileage.use` | action | — | `src/Services/OrderProcessingService.php:2135` |
| `sirsoft-ecommerce.notification.channels` | filter | — | `src/Services/EcommerceSettingsAdminService.php:481` |
| `sirsoft-ecommerce.option.after_bulk_update` | action | — | `src/Services/ProductOptionService.php:297` |
| `sirsoft-ecommerce.option.before_bulk_update` | action | — | `src/Services/ProductOptionService.php:197` |
| `sirsoft-ecommerce.option.bulk_update_validation_rules` | filter | — | `src/Http/Requests/Admin/BulkUpdateOptionsRequest.php:72` |
| `sirsoft-ecommerce.option.filter_bulk_update_data` | filter | — | `src/Services/ProductOptionService.php:200` |
| `sirsoft-ecommerce.order-option.after_confirm` | action | — | `src/Services/OrderOptionService.php:300` 외 1곳 |
| `sirsoft-ecommerce.order-option.before_confirm` | action | — | `src/Services/OrderService.php:953` |
| `sirsoft-ecommerce.order.after_admin_notify` | action | — | `src/Services/OrderProcessingService.php:265` 외 2곳 |
| `sirsoft-ecommerce.order.after_anonymize` | action | — | `src/Services/OrderPrivacyRetentionService.php:92` |
| `sirsoft-ecommerce.order.after_bulk_shipping_update` | action | — | `src/Services/OrderService.php:740` |
| `sirsoft-ecommerce.order.after_bulk_status_update` | action | — | `src/Services/OrderService.php:705` |
| `sirsoft-ecommerce.order.after_bulk_update` | action | — | `src/Services/OrderService.php:628` |
| `sirsoft-ecommerce.order.after_confirm` | action | — | `src/Services/OrderProcessingService.php:371` 외 1곳 |
| `sirsoft-ecommerce.order.after_create` | action | — | `src/Services/OrderProcessingService.php:257` |
| `sirsoft-ecommerce.order.after_delete` | action | — | `src/Services/OrderPrivacyRetentionService.php:153` 외 1곳 |
| `sirsoft-ecommerce.order.after_deposit_recorded` | action | — | `src/Services/OrderProcessingService.php:1985` |
| `sirsoft-ecommerce.order.after_payment_complete` | action | — | `src/Services/OrderProcessingService.php:370` 외 1곳 |
| `sirsoft-ecommerce.order.after_prune` | action | — | `src/Privacy/OrderRetentionHandler.php:256` |
| `sirsoft-ecommerce.order.after_purchase_confirmed` | action | — | `src/Services/OrderOptionService.php:1032` 외 1곳 |
| `sirsoft-ecommerce.order.after_read` | action | — | `src/Services/OrderService.php:181` 외 1곳 |
| `sirsoft-ecommerce.order.after_reset_guest_password` | action | — | `src/Services/OrderService.php:1036` |
| `sirsoft-ecommerce.order.after_send_email` | action | — | `src/Services/OrderService.php:771` |
| `sirsoft-ecommerce.order.after_status_change` | action | — | `src/Services/OrderOptionService.php:1021` 외 3곳 |
| `sirsoft-ecommerce.order.after_status_sync` | action | — | `src/Services/OrderOptionService.php:1026` 외 1곳 |
| `sirsoft-ecommerce.order.after_update` | action | — | `src/Services/OrderService.php:398` |
| `sirsoft-ecommerce.order.after_update_shipping_address` | action | — | `src/Services/OrderService.php:405` 외 1곳 |
| `sirsoft-ecommerce.order.before_bulk_shipping_update` | action | — | `src/Services/OrderService.php:732` |
| `sirsoft-ecommerce.order.before_bulk_status_update` | action | — | `src/Services/OrderService.php:688` |
| `sirsoft-ecommerce.order.before_bulk_update` | action | — | `src/Services/OrderService.php:568` |
| `sirsoft-ecommerce.order.before_cancel` | action | — | `src/Services/OrderCancellationService.php:314` |
| `sirsoft-ecommerce.order.before_create` | action | — | `src/Services/OrderProcessingService.php:131` |
| `sirsoft-ecommerce.order.before_delete` | action | — | `src/Services/OrderService.php:437` |
| `sirsoft-ecommerce.order.before_payment_complete` | action | — | `src/Services/OrderProcessingService.php:359` 외 1곳 |
| `sirsoft-ecommerce.order.before_prune` | action | — | `src/Privacy/OrderRetentionHandler.php:232` |
| `sirsoft-ecommerce.order.before_reset_guest_password` | action | — | `src/Services/OrderService.php:1030` |
| `sirsoft-ecommerce.order.before_update` | action | — | `src/Services/OrderService.php:218` |
| `sirsoft-ecommerce.order.before_update_shipping_address` | action | — | `src/Services/OrderService.php:851` |
| `sirsoft-ecommerce.order.create_validation_rules` | filter | — | `src/Http/Requests/Public/CreateOrderRequest.php:160` |
| `sirsoft-ecommerce.order.filter_create_data` | filter | — | `src/Services/OrderProcessingService.php:680` |
| `sirsoft-ecommerce.order.filter_export_params` | filter | — | `src/Services/OrderService.php:793` |
| `sirsoft-ecommerce.order.filter_list_params` | filter | — | `src/Services/OrderService.php:105` |
| `sirsoft-ecommerce.order.filter_update_data` | filter | — | `src/Services/OrderService.php:224` |
| `sirsoft-ecommerce.order.list_validation_messages` | filter | — | `src/Http/Requests/Admin/OrderListRequest.php:223` |
| `sirsoft-ecommerce.order.list_validation_rules` | filter | — | `src/Http/Requests/Admin/OrderListRequest.php:132` |
| `sirsoft-ecommerce.order.payment_failed` | action | — | `src/Services/OrderProcessingService.php:2117` |
| `sirsoft-ecommerce.order.shipping_address_validation_rules` | filter | — | `src/Http/Requests/User/UpdateOrderShippingAddressRequest.php:71` |
| `sirsoft-ecommerce.order.tax_invoice_stale_after_cancel` | action | — | `src/Services/OrderCancellationService.php:545` |
| `sirsoft-ecommerce.order_option.after_bulk_status_change` | action | — | `src/Services/OrderOptionService.php:568` |
| `sirsoft-ecommerce.order_option.after_status_change` | action | — | `src/Services/OrderOptionService.php:290` |
| `sirsoft-ecommerce.order_option.after_sync_status` | action | — | `src/Services/OrderExchangeService.php:3311` 외 2곳 |
| `sirsoft-ecommerce.order_option.before_bulk_status_change` | action | — | `src/Services/OrderOptionService.php:518` |
| `sirsoft-ecommerce.order_option.before_status_change` | action | — | `src/Services/OrderOptionService.php:127` |
| `sirsoft-ecommerce.order_shipping.tracking_updated` | action | — | `src/Services/OrderOptionService.php:423` |
| `sirsoft-ecommerce.payment.before_approve` | action | — | `src/Services/OrderService.php:574` 외 1곳 |
| `sirsoft-ecommerce.payment.before_cancel` | action | — | `src/Services/OrderCancellationService.php:313` |
| `sirsoft-ecommerce.payment.before_confirm_deposit` | action | — | `src/Services/OrderProcessingService.php:1921` |
| `sirsoft-ecommerce.payment.get_client_config` | filter | — | `src/Http/Controllers/Shop/PaymentConfigController.php:26` |
| `sirsoft-ecommerce.payment.refund` | filter | — | `src/Services/RefundExecutionService.php:451` |
| `sirsoft-ecommerce.payment.registered_pg_providers` | filter | — | `src/Services/EcommerceSettingsService.php:1003` |
| `sirsoft-ecommerce.payment.supports_partial_cancel` | filter | — | `src/Http/Resources/OrderPaymentResource.php:57` 외 1곳 |
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
| `sirsoft-ecommerce.product-image.after_delete` | action | — | `src/Services/ProductImageService.php:305` |
| `sirsoft-ecommerce.product-image.after_reorder` | action | — | `src/Services/ProductImageService.php:324` |
| `sirsoft-ecommerce.product-image.after_upload` | action | — | `src/Services/ProductImageService.php:148` |
| `sirsoft-ecommerce.product-image.before_delete` | action | — | `src/Services/ProductImageService.php:283` |
| `sirsoft-ecommerce.product-image.before_reorder` | action | — | `src/Services/ProductImageService.php:319` |
| `sirsoft-ecommerce.product-image.before_upload` | action | — | `src/Services/ProductImageService.php:80` |
| `sirsoft-ecommerce.product-image.filter_reorder_validation_rules` | filter | — | `src/Http/Requests/Admin/ReorderProductImagesRequest.php:36` |
| `sirsoft-ecommerce.product-image.filter_upload_file` | filter | — | `src/Services/ProductImageService.php:83` |
| `sirsoft-ecommerce.product-image.filter_upload_validation_rules` | filter | — | `src/Http/Requests/Admin/UploadProductImageRequest.php:72` |
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
| `sirsoft-ecommerce.product-review.after_bulk_delete` | action | — | `src/Services/ProductReviewService.php:340` |
| `sirsoft-ecommerce.product-review.after_create` | action | — | `src/Services/ProductReviewService.php:165` |
| `sirsoft-ecommerce.product-review.after_delete` | action | — | `src/Services/ProductReviewService.php:253` |
| `sirsoft-ecommerce.product-review.before_bulk_delete` | action | — | `src/Services/ProductReviewService.php:318` |
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
| `sirsoft-ecommerce.product.filter_content_thumbnail` | filter | — | `src/Models/Product.php:193` |
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
| `sirsoft-ecommerce.product.store_validation_rules` | filter | — | `src/Http/Requests/Admin/StoreProductRequest.php:192` |
| `sirsoft-ecommerce.product.update_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateProductRequest.php:67` |
| `sirsoft-ecommerce.product_inquiry.after_bulk_delete` | action | — | `src/Services/ProductInquiryService.php:827` |
| `sirsoft-ecommerce.product_inquiry.after_create` | action | — | `src/Services/ProductInquiryService.php:314` |
| `sirsoft-ecommerce.product_inquiry.after_reply` | action | — | `src/Services/ProductInquiryService.php:767` |
| `sirsoft-ecommerce.product_inquiry.before_bulk_delete` | action | — | `src/Services/ProductInquiryService.php:815` |
| `sirsoft-ecommerce.product_option.after_bulk_price_update` | action | — | `src/Services/ProductOptionService.php:101` |
| `sirsoft-ecommerce.product_option.after_bulk_stock_update` | action | — | `src/Services/ProductOptionService.php:147` 외 1곳 |
| `sirsoft-ecommerce.product_option.before_bulk_price_update` | action | — | `src/Services/ProductOptionService.php:91` |
| `sirsoft-ecommerce.product_option.before_bulk_stock_update` | action | — | `src/Services/ProductOptionService.php:138` |
| `sirsoft-ecommerce.product_option.bulk_price_validation_rules` | filter | — | `src/Http/Requests/Admin/BulkUpdateOptionPriceRequest.php:49` |
| `sirsoft-ecommerce.product_option.bulk_stock_validation_rules` | filter | — | `src/Http/Requests/Admin/BulkUpdateOptionStockRequest.php:46` |
| `sirsoft-ecommerce.review-image.after_delete` | action | — | `src/Services/ProductReviewImageService.php:142` |
| `sirsoft-ecommerce.review-image.after_upload` | action | — | `src/Services/ProductReviewImageService.php:117` |
| `sirsoft-ecommerce.review-image.before_delete` | action | — | `src/Services/ProductReviewImageService.php:130` |
| `sirsoft-ecommerce.review-image.before_upload` | action | — | `src/Services/ProductReviewImageService.php:67` |
| `sirsoft-ecommerce.review-image.filter_upload_file` | filter | — | `src/Services/ProductReviewImageService.php:70` |
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
| `sirsoft-ecommerce.search.product.index_should_update` | filter | — | `src/Models/Product.php:707` |
| `sirsoft-ecommerce.search.product_common_info.index_should_update` | filter | — | `src/Models/ProductCommonInfo.php:176` |
| `sirsoft-ecommerce.search_preset.list_validation_rules` | filter | — | `src/Http/Requests/Admin/SearchPresetListRequest.php:40` |
| `sirsoft-ecommerce.sequence.after_generate` | action | — | `src/Services/SequenceService.php:57` 외 1곳 |
| `sirsoft-ecommerce.sequence.before_generate` | action | — | `src/Services/SequenceService.php:44` |
| `sirsoft-ecommerce.settings.after_save` | action | — | `src/Http/Controllers/Admin/EcommerceSettingsController.php:166` |
| `sirsoft-ecommerce.settings.filter_available_payment_methods` | filter | — | `src/Services/EcommerceSettingsService.php:908` |
| `sirsoft-ecommerce.shipping.calculate_fee` | filter | — | `src/Services/OrderCalculationService.php:2141` |
| `sirsoft-ecommerce.shipping_carrier.after_create` | action | — | `src/Services/ShippingCarrierService.php:156` |
| `sirsoft-ecommerce.shipping_carrier.after_delete` | action | — | `src/Services/ShippingCarrierService.php:343` |
| `sirsoft-ecommerce.shipping_carrier.after_list` | action | — | `src/Services/ShippingCarrierService.php:73` |
| `sirsoft-ecommerce.shipping_carrier.after_show` | action | — | `src/Services/ShippingCarrierService.php:92` |
| `sirsoft-ecommerce.shipping_carrier.after_toggle_status` | action | — | `src/Services/ShippingCarrierService.php:222` |
| `sirsoft-ecommerce.shipping_carrier.after_update` | action | — | `src/Services/ShippingCarrierService.php:193` |
| `sirsoft-ecommerce.shipping_carrier.before_create` | action | — | `src/Services/ShippingCarrierService.php:143` |
| `sirsoft-ecommerce.shipping_carrier.before_delete` | action | — | `src/Services/ShippingCarrierService.php:337` |
| `sirsoft-ecommerce.shipping_carrier.before_list` | action | — | `src/Services/ShippingCarrierService.php:65` |
| `sirsoft-ecommerce.shipping_carrier.before_show` | action | — | `src/Services/ShippingCarrierService.php:86` |
| `sirsoft-ecommerce.shipping_carrier.before_toggle_status` | action | — | `src/Services/ShippingCarrierService.php:214` |
| `sirsoft-ecommerce.shipping_carrier.before_update` | action | — | `src/Services/ShippingCarrierService.php:178` |
| `sirsoft-ecommerce.shipping_carrier.create_validation_rules` | filter | — | `src/Http/Requests/Admin/StoreShippingCarrierRequest.php:42` |
| `sirsoft-ecommerce.shipping_carrier.filter_create_data` | filter | — | `src/Services/ShippingCarrierService.php:145` |
| `sirsoft-ecommerce.shipping_carrier.filter_list_query` | filter | — | `src/Services/ShippingCarrierService.php:67` |
| `sirsoft-ecommerce.shipping_carrier.filter_list_result` | filter | — | `src/Services/ShippingCarrierService.php:71` |
| `sirsoft-ecommerce.shipping_carrier.filter_show_result` | filter | — | `src/Services/ShippingCarrierService.php:91` |
| `sirsoft-ecommerce.shipping_carrier.filter_update_data` | filter | — | `src/Services/ShippingCarrierService.php:183` |
| `sirsoft-ecommerce.shipping_carrier.update_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateShippingCarrierRequest.php:44` |
| `sirsoft-ecommerce.shipping_policy.after_bulk_delete` | action | — | `src/Services/ShippingPolicyService.php:505` |
| `sirsoft-ecommerce.shipping_policy.after_bulk_toggle_active` | action | — | `src/Services/ShippingPolicyService.php:533` |
| `sirsoft-ecommerce.shipping_policy.after_create` | action | — | `src/Services/ShippingPolicyService.php:126` |
| `sirsoft-ecommerce.shipping_policy.after_delete` | action | — | `src/Services/ShippingPolicyService.php:450` |
| `sirsoft-ecommerce.shipping_policy.after_read` | action | — | `src/Services/ShippingPolicyService.php:72` |
| `sirsoft-ecommerce.shipping_policy.after_set_default` | action | — | `src/Services/ShippingPolicyService.php:573` |
| `sirsoft-ecommerce.shipping_policy.after_toggle_active` | action | — | `src/Services/ShippingPolicyService.php:471` |
| `sirsoft-ecommerce.shipping_policy.after_update` | action | — | `src/Services/ShippingPolicyService.php:192` 외 1곳 |
| `sirsoft-ecommerce.shipping_policy.before_bulk_delete` | action | — | `src/Services/ShippingPolicyService.php:494` |
| `sirsoft-ecommerce.shipping_policy.before_bulk_toggle_active` | action | — | `src/Services/ShippingPolicyService.php:528` |
| `sirsoft-ecommerce.shipping_policy.before_create` | action | — | `src/Services/ShippingPolicyService.php:87` |
| `sirsoft-ecommerce.shipping_policy.before_delete` | action | — | `src/Services/ShippingPolicyService.php:433` |
| `sirsoft-ecommerce.shipping_policy.before_set_default` | action | — | `src/Services/ShippingPolicyService.php:559` |
| `sirsoft-ecommerce.shipping_policy.before_toggle_active` | action | — | `src/Services/ShippingPolicyService.php:466` |
| `sirsoft-ecommerce.shipping_policy.before_update` | action | — | `src/Services/ShippingPolicyService.php:143` 외 1곳 |
| `sirsoft-ecommerce.shipping_policy.bulk_delete_validation_rules` | filter | — | `src/Http/Requests/Admin/ShippingPolicyBulkDeleteRequest.php:38` |
| `sirsoft-ecommerce.shipping_policy.bulk_toggle_active_validation_rules` | filter | — | `src/Http/Requests/Admin/ShippingPolicyBulkToggleActiveRequest.php:39` |
| `sirsoft-ecommerce.shipping_policy.filter_create_data` | filter | — | `src/Services/ShippingPolicyService.php:90` |
| `sirsoft-ecommerce.shipping_policy.filter_list_params` | filter | — | `src/Services/ShippingPolicyService.php:44` |
| `sirsoft-ecommerce.shipping_policy.filter_update_data` | filter | — | `src/Services/ShippingPolicyService.php:149` |
| `sirsoft-ecommerce.shipping_policy.list_validation_messages` | filter | — | `src/Http/Requests/Admin/ShippingPolicyListRequest.php:129` |
| `sirsoft-ecommerce.shipping_policy.list_validation_rules` | filter | — | `src/Http/Requests/Admin/ShippingPolicyListRequest.php:96` |
| `sirsoft-ecommerce.shipping_policy.store_validation_rules` | filter | — | `src/Http/Requests/Admin/StoreShippingPolicyRequest.php:119` |
| `sirsoft-ecommerce.shipping_policy.update_validation_rules` | filter | — | `src/Http/Requests/Admin/UpdateShippingPolicyRequest.php:24` |
| `sirsoft-ecommerce.shipping_type.after_create` | action | — | `src/Services/ShippingTypeService.php:123` 외 1곳 |
| `sirsoft-ecommerce.shipping_type.after_delete` | action | — | `src/Services/ShippingTypeService.php:277` 외 1곳 |
| `sirsoft-ecommerce.shipping_type.after_list` | action | — | `src/Services/ShippingTypeService.php:44` |
| `sirsoft-ecommerce.shipping_type.after_show` | action | — | `src/Services/ShippingTypeService.php:63` |
| `sirsoft-ecommerce.shipping_type.after_update` | action | — | `src/Services/ShippingTypeService.php:162` 외 1곳 |
| `sirsoft-ecommerce.shipping_type.before_create` | action | — | `src/Services/ShippingTypeService.php:107` 외 1곳 |
| `sirsoft-ecommerce.shipping_type.before_delete` | action | — | `src/Services/ShippingTypeService.php:274` 외 1곳 |
| `sirsoft-ecommerce.shipping_type.before_list` | action | — | `src/Services/ShippingTypeService.php:36` |
| `sirsoft-ecommerce.shipping_type.before_show` | action | — | `src/Services/ShippingTypeService.php:57` |
| `sirsoft-ecommerce.shipping_type.before_update` | action | — | `src/Services/ShippingTypeService.php:145` 외 1곳 |
| `sirsoft-ecommerce.shipping_type.filter_create_data` | filter | — | `src/Services/ShippingTypeService.php:109` 외 1곳 |
| `sirsoft-ecommerce.shipping_type.filter_list_query` | filter | — | `src/Services/ShippingTypeService.php:38` |
| `sirsoft-ecommerce.shipping_type.filter_list_result` | filter | — | `src/Services/ShippingTypeService.php:42` |
| `sirsoft-ecommerce.shipping_type.filter_show_result` | filter | — | `src/Services/ShippingTypeService.php:62` |
| `sirsoft-ecommerce.shipping_type.filter_update_data` | filter | — | `src/Services/ShippingTypeService.php:149` 외 1곳 |
| `sirsoft-ecommerce.stock.after_deduct` | action | — | `src/Services/StockService.php:118` |
| `sirsoft-ecommerce.stock.after_restore` | action | — | `src/Services/StockService.php:160` |
| `sirsoft-ecommerce.stock.before_deduct` | action | — | `src/Services/StockService.php:73` |
| `sirsoft-ecommerce.stock.before_restore` | action | — | `src/Services/StockService.php:129` |
| `sirsoft-ecommerce.temp_order.after_cleanup` | action | — | `src/Services/TempOrderService.php:569` |
| `sirsoft-ecommerce.temp_order.after_create` | action | — | `src/Services/TempOrderService.php:126` |
| `sirsoft-ecommerce.temp_order.after_delete` | action | — | `src/Services/TempOrderService.php:527` 외 1곳 |
| `sirsoft-ecommerce.temp_order.after_update` | action | — | `src/Services/TempOrderService.php:414` |
| `sirsoft-ecommerce.temp_order.before_cleanup` | action | — | `src/Services/TempOrderService.php:565` |
| `sirsoft-ecommerce.temp_order.before_create` | action | — | `src/Services/TempOrderService.php:77` |
| `sirsoft-ecommerce.temp_order.before_delete` | action | — | `src/Services/TempOrderService.php:523` 외 1곳 |
| `sirsoft-ecommerce.temp_order.before_update` | action | — | `src/Services/TempOrderService.php:341` |
| `sirsoft-ecommerce.user_address.after_bulk_delete` | action | — | `src/Services/UserAddressService.php:397` |
| `sirsoft-ecommerce.user_address.after_create` | action | — | `src/Services/UserAddressService.php:153` |
| `sirsoft-ecommerce.user_address.after_delete` | action | — | `src/Services/UserAddressService.php:218` |
| `sirsoft-ecommerce.user_address.after_set_default` | action | — | `src/Services/UserAddressService.php:244` |
| `sirsoft-ecommerce.user_address.after_update` | action | — | `src/Services/UserAddressService.php:184` |
| `sirsoft-ecommerce.user_address.before_bulk_delete` | action | — | `src/Services/UserAddressService.php:393` |
| `sirsoft-ecommerce.user_address.before_create` | action | — | `src/Services/UserAddressService.php:121` |
| `sirsoft-ecommerce.user_address.before_delete` | action | — | `src/Services/UserAddressService.php:204` |
| `sirsoft-ecommerce.user_address.before_set_default` | action | — | `src/Services/UserAddressService.php:238` |
| `sirsoft-ecommerce.user_address.before_update` | action | — | `src/Services/UserAddressService.php:174` |
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
| `sirsoft-ecommerce.user_mileage.after_balance` | action | — | `src/Services/UserMileageService.php:151` |
| `sirsoft-ecommerce.user_mileage.before_balance` | action | — | `src/Services/UserMileageService.php:134` |
| `sirsoft-ecommerce.user_mileage.filter_balance` | filter | — | `src/Services/UserMileageService.php:149` |
| `sirsoft-ecommerce.wishlist.after_bulk_delete` | action | — | `src/Services/ProductWishlistService.php:140` |
| `sirsoft-ecommerce.wishlist.after_toggle` | action | — | `src/Services/ProductWishlistService.php:36` |
| `sirsoft-ecommerce.wishlist.before_bulk_delete` | action | — | `src/Services/ProductWishlistService.php:136` |
| `sirsoft-ecommerce.wishlist.before_toggle` | action | — | `src/Services/ProductWishlistService.php:32` |
| `sirsoft-ecommerce.wishlist.toggle_validation_rules` | filter | — | `src/Http/Requests/Public/ToggleWishlistRequest.php:34` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
508종이라는 수에 압도될 필요는 없습니다. 도메인 하나가 같은 4종을 반복하기 때문입니다 —
`before_{동작}`(action) → `filter_{동작}_data`(filter) → 실행 → `after_{동작}`(action), 그리고
FormRequest 쪽 `{동작}_validation_rules`(filter). `brand.*` 19종을 한 번 읽으면 `product` 44 ·
`order` 35 · `coupon` 32 · `category` 28 · `cart` 25 · `shipping_policy` 24 · `brand` 19 ·
`shipping_carrier` 19 · `extra_fee_template` 19 · `label` 17 · `claim_reason` 17 ·
`shipping_type` 15 에 그대로 적용됩니다.

`claim_reason`·`shipping_type` 은 상세 REST 경로와 환경설정 탭의 일괄 저장 경로가 **같은 훅**을
쏩니다. 일괄 저장은 실제로 바뀐 행에만 `before_update` → `filter_update_data` → `after_update` 를
쏘고(아무것도 고치지 않은 저장은 훅도 쓰기도 없다), `after_*` 는 트랜잭션이 커밋된 뒤에 나갑니다.

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
| `claim.exchange.*` · `claim.return.*` · `claim.refund.*` · `claim.cancel.*` | — | 네 유형 신청서의 접수·결제 확정·승인·전이·완료·철회·반려·금액 수정. 전이는 `status_changed` 한 축으로 기록되고, 승인·반려·철회·완료는 구체 훅이 함께 발화합니다 |
| `claim.{type}.before_*` · `claim.{type}.filter_*` | 29 | 신청·수정·전이·철회·상품 단위 전이·완료를 쓰기 **전에** 막거나(action) 입력을 바꾸는(filter) 자리. 아래 「클레임 before·filter 훅」 참조 |
| `claim.{type}.option_status_changed` | 4 | 신청서의 **상품 단위** 전이. 헤더가 따라 움직이지 않는 조합이 있어 층이 다른 사실로 따로 냅니다 — 헤더가 함께 움직인 회차는 두 줄이 남으며 이중 기록이 아닙니다 |
| `order_option.after_sync_status` | 1 | 주문 상태 동기화가 **끌고 간** 상품 전이. 이 경로는 관계 빌더의 raw update 라 `order_option.after_status_change` 를 지나지 않습니다 |
| `order_shipping.tracking_updated` | 1 | 운송장 기록·정정. 전이를 동반하지 않는 정정은 어떤 전이 훅도 지나지 않습니다 |
| `order.tax_invoice_stale_after_cancel` | 1 | 취소가 일어났는데 발급 완료 세금계산서가 남아 있는 상태. 국세청 연동 자동 취소는 코어 범위 밖이라 **누락을 드러내는** 자리입니다 |
| `payment.supports_partial_cancel` | 1 | 이 결제가 부분취소를 지원하는가(filter). 제약을 아는 자리는 PG 플러그인이므로 코어가 수단·PG 조합표를 들지 않습니다 |
| `claim_payment.approve` · `claim_payment.refund` · `claim_payment.void_approval` | 3 | 클레임 추가결제(`CP` 채번)의 PG 승인·환급, 그리고 PG 는 승인했지만 모듈이 금액 불일치로 거절한 승인의 망취소 보상(`void_approval` — 선택 구현, `ClaimPaymentService`) — 결제 플러그인이 구현하는 필터. 계약(인자·반환 키·멱등 규칙)의 단일 출처는 `modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` |
| `claim-image.*` | 3 | 첨부 업로드 파일 검사(filter)와 업로드·삭제 후처리 |

### 취소·환불 축의 훅 계약 (#599 후속)

`claim.refund.status_changed` 는 **네 번째 인수로 컨텍스트 배열**을 받습니다. 자동 전이는 한
번의 취소로 `접수 → 승인 → 완료` 세 단계를 실제로 밟는데, 그때마다 알림을 보내면 고객이 문자
3통을 받습니다. 그래서 중간 전이는 `suppress_notification` 을 실어 알림만 끄고 활동 로그는
그대로 남깁니다 — 알림 리스너만 그 플래그를 읽고, 활동 로그 리스너는 읽지 않습니다.
기존 3인수 구독자는 그대로 두어도 됩니다(PHP 는 유저 함수에 인수가 더 넘어와도 오류를 내지
않습니다 — 모자랄 때만 `ArgumentCountError`).

### 클레임 훅은 커밋 뒤에 발화합니다 (#599 validate)

교환·반품·환불신청 서비스와 관리자 클레임 서비스가 내는 `claim.{type}.*` 훅(접수·상태 전이·
상품 단위 전이·금액 수정·상품 단위 처리 안내)과 교환 재발송의 `order_option.after_sync_status` 는
**트랜잭션 커밋 뒤**에 발화합니다(`DB::afterCommit()` — 열린 트랜잭션이 없으면 즉시). 그래서:

- 구독자는 **커밋된 상태**를 읽습니다. 리스너가 던져도 이미 끝난 전이는 되돌아가지 않습니다 —
  그 예외는 호출자에게 전파되지만 도메인 쓰기는 남습니다.
- 뒤이은 쓰기가 실패해 롤백된 전이의 훅은 **발화하지 않습니다**.
- 한 트랜잭션 안의 훅은 등록 순서대로 나갑니다 — 상품 축(`option_status_changed`)이 헤더 축
  (`status_changed`)보다 먼저입니다.

호출자 트랜잭션과 **함께 되돌아가야 하는** 구독(금전 복원 — `'sync' => true`)은 이 훅들이 아니라
금액·쿠폰·마일리지 축의 훅에 붙습니다. 클레임 훅에 롤백 연동이 필요한 쓰기를 걸지 마세요.

### 클레임 before·filter 훅 — 막고 바꾸는 자리 (#599 validate)

위 after 훅과 반대로, 아래 훅은 **쓰기 전에 동기로** 발화합니다(트랜잭션·잠금 전. 헤더 동기화만
상품 전이 트랜잭션 안). before 리스너가 예외를 던지면 그 동작은 아무것도 쓰지 않고 끝납니다.
막을 때는 `OrderClaimException::blockedByExtension('vendor-name::messages.키', [...])` 를 던지세요 —
도메인 거절이라 화면은 422 와 그 문구를 받고, 일괄 전이는 그 건만 skip 으로 보고하며, 접수 직후의
청구 0원 자동 승인은 접수를 살린 채 신청 상태로 남습니다. 다른 예외는 그 경로들에서 500·일반
실패 문구가 됩니다. `{type}` 은 `exchange` · `return` · `refund` 입니다.

| 훅 | 유형 | 인자 | 발화 지점 / 용도 |
|----|------|------|-----------------|
| `claim.{type}.before_request` | action | `(Order $order, array $payload)` | 신청 접수 직전(`requestExchange`·`requestReturn`·`OrderRefundRequestService::request`). 신청 자격을 확장이 더 좁힌다. 환불 접수는 환불할 돈이 없으면 취소신청서로 끝나지만 입구가 하나라 `claim.refund.*` 한 벌입니다 |
| `claim.{type}.filter_request_data` | filter | `(array $payload, Order $order)` → `array` | 신청 입력 변형. **허용 키**: `items` · `reason_code` · `reason_detail` · `redelivery_address`(교환) |
| `claim.{type}.before_update_request` | action | `($claim, array $payload)` | 승인 전 신청 수정 직전(`updateRequest`) |
| `claim.{type}.filter_update_request_data` | filter | `(array $payload, $claim)` → `array` | 수정 입력 변형. **허용 키**: `reason_code` · `reason_detail` · `redelivery_address`(교환) |
| `claim.{type}.before_status_change` | action | `($claim, ?string $from, string $to, array $context)` | 헤더 전이 직전 — 직접 전이·일괄 전이(건마다)·결제가 잇는 전이·송장 등록이 끄는 전이·**상품이 끌고 간 헤더 동기화**(단계마다, `$context['source'] = 'option_sync'`). `$from` 은 호출 시점 값이고 전이 판정은 잠금 안에서 다시 합니다. 헤더 동기화에서 막으면 그 회차의 상품 전이도 함께 되돌아갑니다 |
| `claim.{type}.filter_status_change_data` | filter | `(array $context, $claim, ?string $from, string $to)` → `array` | 전이 컨텍스트 변형. **허용 키**: `admin_memo` · `reject_reason`. 헤더 동기화 경로에는 없습니다 |
| `claim.{type}.before_withdraw` | action | `($claim)` | 신청자 철회 직전(철회 가능 판정 뒤). 이어지는 전이가 `before_status_change` 를 한 번 더 냅니다 |
| `claim.{type}.before_option_status_change` | action | `($claim, array $items, string $to, array $context)` | 상품 단위 전이 직전. `$items` 는 `[{id, quantity}]`(수량 미지정이면 `null`) |
| `claim.{exchange\|return}.before_complete` | action | `($claim, array $itemIds, ClaimCompletionOptions $options, ?int $processedBy)` | 교환·반품 완료 직전(`completeExchange`·`completeReturn`). `$itemIds` 가 비면 신청서 전체 |
| `claim.{exchange\|return}.filter_complete_options` | filter | `(ClaimCompletionOptions $options, $claim, array $itemIds, ?int $processedBy)` → `ClaimCompletionOptions` | 완료 옵션(상품별 재고 복구 · 환불신청서 접수 여부) 변형. 다른 형을 돌려주면 무시합니다 |
| `claim.refund.before_complete` | action | `(OrderRefund $refund, array $overrides, ?int $processedBy)` | 환불 실행 직전(`executeRefund` — 잠금·PG 호출 전). 막으면 돈이 나가지 않습니다 |

**filter 가 바꿀 수 없는 값.** 필터 결과에서는 허용 키만 가져오고 나머지는 원래 값으로 되돌립니다
(필터가 새로 넣은 키도 버립니다 — `Services/Concerns/GuardsClaimFilterResults`). 되돌리는 값과 이유:

- 신청자(`requested_by`)·처리자(`processed_by`) — 소유권과 행위자 기록. 바뀌면 비회원 신청이 회원
  소유로 넘어가거나 활동 로그의 처리자가 달라집니다.
- 접수 창구 축(`refund_source_type` · `branch` · `gate_statuses` · `user_selectable_reason_only` ·
  `pg_already_settled` · `auto_advance`) — 고객 창구 접수가 운영자 축으로 자격을 넓히거나 PG 왕복을
  건너뜁니다.
- 돈의 경로(`charge_method` · `refund_priority` · `refund_bank`) — 청구·환불 수단과 송금 계좌.
- 목표 상태 — 호출층이 미수납 게이트(승인 → 결제 요청 우회)·종결 정산(반려·철회 → 돈 닫기)을 그
  값으로 이미 판정했습니다. 필터 인자로 넘기지만 바꾸게 두지 않습니다. 알림 억제 플래그도 되돌립니다.
- 금액 — 입력으로 받지 않고 서버가 대상·사유로 계산합니다. 환불 실행 본문(`$overrides`)은 금액
  그 자체라 `claim.refund.before_complete` 에 필터를 두지 않았습니다.

허용 키도 무검증으로 쓰이지 않습니다 — 필터 **뒤에** 고객 입력과 같은 검증(사유 해석, 잠금 안 수량
가드, 재발송지 국가 불변, 배송비·회수비 재산정)을 그대로 지납니다. 그래서 필터가 사유를 판매자
귀책으로 바꾸면 청구액도 그 사유로 다시 산정됩니다.

상품 단위 전이(`before_option_status_change`)·철회·헤더 동기화·환불 실행에 필터가 없는 것은 바꿀 자유
입력이 없거나(대상 행·목표 상태는 운영자 판단이자 게이트가 잰 값) 바꾸면 헤더와 상품이 갈리기
때문입니다. 취소신청서(`claim.cancel.*`)의 막는 자리는 취소 파이프라인의 `order.before_cancel` 과
위 `claim.refund.before_request` 입니다. 교환·반품 완료가 스스로 접수하는 차액·환불 신청서는
`claim.refund.before_request` 를 지나지 않습니다 — 그 자리는 `claim.{exchange|return}.before_complete` 입니다.

### 상품 행 송장·선교환·판매자 접수 훅 (#599 상품 행 송장)

송장은 **상품 행마다** 있습니다(교환 행은 회수·재발송, 반품 행은 회수). 헤더 송장 칸은 없습니다 —
`order_exchanges`·`order_returns` 의 송장 컬럼을 읽던 확장은 행(`order_exchange_options`·
`order_return_options` 의 `{collection|redelivery}_carrier_id`·`_tracking_number`)을 읽어야 합니다.
`{type}` 은 `exchange` · `return` 이고, `$direction` 은 `collection` · `redelivery`(교환만) 입니다.

| 훅 | 유형 | 인자 | 발화 지점 / 용도 |
|----|------|------|-----------------|
| `claim.{type}.before_tracking_update` | action | `($claim, string $direction, array $items, array $tracking)` | 「송장만 저장」·전이 본문 송장 쓰기 직전(잠금 전, 동기). `$items` 는 `[{id, quantity}]`, `$tracking` 은 `{carrier_id, tracking_number}`. 막으면 아무 행도 쓰지 않습니다 |
| `claim.{type}.filter_tracking_update_data` | filter | `(array $input, $claim, string $direction)` → `array` | 송장 값 변형. **허용 키**: `carrier_id` · `tracking_number`. 필터 뒤에 반쪽 송장·길이·회수지 혼합 검증을 그대로 지납니다 |
| `claim.{type}.after_tracking_update` | action | `($claim, string $direction, array $changes, array $context)` | 커밋 뒤. `$changes` 는 송장이 새로 생기거나 바뀐 행. 송장 등록 알림(`{type}_{direction}_tracking_registered`)이 여기서 나가며, 같은 요청에서 헤더가 회수중·발송중으로 바뀌면 상태 알림이 운송장 줄을 싣고 이 알림은 억제됩니다 |
| `claim.{type}.after_tracking_deliver` | action | `($claim, string $direction, int $carrierId, string $trackingNumber, array $result)` | 운송장 단위 [도착 처리] 커밋 뒤. `$result` 는 `{succeeded: int[], skipped: int[]}` |
| `claim.exchange.before_mode_change` | action | `(OrderExchange $exchange, string $from, string $to)` | 일반 교환 ↔ 선교환 전환 직전(동기). 막으면 방식이 바뀌지 않습니다. 바꿀 값이 방식 하나라 filter 는 두지 않았습니다 |
| `claim.exchange.after_mode_change` | action | `(OrderExchange $exchange, string $from, string $to, ?int $by)` | 전환 커밋 뒤 |
| `claim.exchange.collection_overdue` | action | `(OrderExchange $exchange, int $overdueDays)` | 선교환 반송 기한을 넘긴 신청서마다 **한 번**(배치 `sirsoft-ecommerce:claims:notify-overdue-collections`). 관리자 알림이 여기서 나갑니다. 돈 청구는 쇼핑몰 밖 일입니다 |
| `claim.{type}.after_stock_restore` | action | `($claim, array $restored, ?int $processedBy)` | 완료 뒤 뒤늦은 재고 복구(관리자 [재고 복구]) 커밋 뒤. `$restored` 는 `[{id, quantity}]`(복구한 행과 수량) |
| `claim.{exchange\|return\|refund}.request_updated` | action | `($claim, array $changes, array $context)` | 신청 내용 수정(고객 수정 · 운영자 대리 수정 S15) 커밋 뒤, **실제로 바뀐 항목이 있을 때만**. `$changes` 는 `[필드 => {before, after}]` — `reason_code` · `fault_type` · `reason_detail` · `claim_shipping_fee` · `total_charge_amount` · `charge_method`. 환불 계좌(`refund_bank`)·교환 재발송지(`redelivery_address`)는 개인정보라 `{before: null, after: null, masked: true}` 로 바뀐 사실만 싣습니다. `$context` 는 `{operator: bool, processed_by: ?int}`(운영자 대리 수정 여부 · 인증 사용자 ID). 활동 로그(`claim_{type}.request_update`)가 여기서 남습니다 |

**접수 출처 컨텍스트.** `claim.{exchange|return}.requested` 는 세 번째 인수로 `['source' => 'admin'|'customer']`
를 받습니다. 판매자가 주문 상세에서 대신 접수한 신청서(`requested_by_admin`)는 `admin` 입니다 — 이 신청서는
고객이 고치거나 철회할 수 없고, 접수 알림 본문에 `request_origin_notice` 가 실립니다. 관리자 취소·환불
접수는 환불신청서의 `refund_source_type = admin` 으로 구분합니다(`claim.refund.requested` 의 인수는 그대로).
기존 2인수 구독자는 고치지 않아도 됩니다.

### 결제수단 능력 선언 — `settings.filter_available_payment_methods`

이 필터로 결제수단을 등록하는 플러그인은 `defaults` 에 **능력 키**를 함께 선언합니다. 코어 builtin
8종은 `PaymentMethodEnum` 에서 파생하지만 확장 수단은 enum case 가 없어 능력을 물을 곳이 카탈로그
선언뿐입니다. 능력 질의의 단일 출처는 `PaymentMethodResolver` 이며 폴백은
`카탈로그 선언 → enum(builtin) → 안전한 기본값` 순입니다.

| 능력 키 | 뜻 | 미선언 시 기본값 |
|---|---|---|
| `needs_pg` | PG 결제창이 필요한가 | `true` (오판 비용이 반대 방향에서 더 큼 — #475) |
| `pg_locked` | 관리자가 다른 PG 로 바꿀 수 없는가 | `false` |
| `refund_method` | 대응 환불 수단 (`pg` · `bank` · `points`) | PG 필요 여부로 판정 |
| `customer_selectable` | 고객이 주문서에서 직접 고를 수 있는가 | `point`·`deposit`·`free` 외 `true` |
| `cash_equivalent` | 현금영수증 발급 대상인가 | enum 규칙 |
| `auto_approve_refund` | **사람의 개입 없이 환불이 집행되는가** | `false` |

`auto_approve_refund` 의 기본값만 `needs_pg` 와 반대 방향입니다 — 오판의 비용이 비대칭이기
때문입니다. 자동으로 잘못 완료하면 돈이 나가기 전에 "환불 완료" 가 찍혀 이중 환불로 이어지고,
반대로 멈추면 운영자가 승인 버튼을 한 번 더 누르면 됩니다. PG 결제취소 API 가 그 자리에서 환불을
끝내는 수단(카드·간편결제)만 `true` 로 선언하고, 고객 환불계좌를 받아 사람이 송금해야 끝나는
수단(가상계좌·계좌이체·휴대폰·편의점 등)은 선언하지 않거나 `false` 로 둡니다.

선언한 수단은 관리자 환경설정 > 클레임 정책 탭의 '환불 자동 승인 결제수단' 목록에 `PG 즉시 환불`
배지와 함께 나타나고, 운영자가 아직 결정한 적 없으면 목록에 자동 합류합니다(`modules/_bundled/sirsoft-ecommerce/docs/settings.md`
의 `claim.auto_approve_refund_payment_methods` 참조). 선언하지 않으면 그 수단의 환불은 결제완료
상품이라도 `requested` 에서 멈춰 운영자 승인을 기다립니다 — 돈이 새지는 않지만 자동 전이가 없으므로,
즉시 환불이 가능한 수단이라면 반드시 선언합니다.

`claim.cancel.*` 3종은 활동 로그 리스너에만 등록합니다. **알림 리스너에는 등록하지 않습니다** —
같은 사건을 환불 알림이 이미 대표하므로, 등록하면 한 건에 알림이 두 번 갑니다.

`core.module_settings.after_save` 는 이 모듈이 발행하지만 **코어 이름공간**입니다 — 환경설정
저장 후 캐시를 비우는 리스너들이 이 훅으로 붙습니다.

훅 이름이 상수·변수로 조립되는 호출이 5곳 있어 표에 위치가 다 실리지 않습니다. 그 자리를 정확히
알아야 하면 소스에서 `HookManager::` 호출부를 직접 확인합니다.

`sirsoft-ecommerce.order.before_prune`(보존일) / `.after_prune`(보존일, 건수)는 종결 주문 개인정보
보유기간 핸들러(이슈 #636)가 코어 prune 훅 규약(7.0.7)에 따라 발행합니다. **`--dry-run` 에서는
발행하지 않습니다.** 삭제 방식이면 주문을 영구 삭제한 뒤(트랜잭션 커밋 후) 주문마다 관리자 삭제와 같은
`sirsoft-ecommerce.order.after_delete`(주문 모델)를 발행합니다 — 주문 활동 로그가 「주문 삭제」를 남기고,
주문 삭제에 반응하는 확장은 같은 훅으로 자기 자료를 정리합니다. 구독자가 받는 시점에 주문 행은 이미
없으므로 모델은 메모리 값으로만 읽습니다. `order.before_delete` 는 발행하지 않습니다 — 관리자 요청을
전제한 사전 검사를 무인 배치에 걸지 않습니다(게시판 보유기간 삭제와 같은 판단). 익명화 방식은 주문마다
`sirsoft-ecommerce.order.after_anonymize`(주문 ID)를 익명화와 같은 트랜잭션 안에서 발행합니다.
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
| `core.privacy.notice_sections` | filter | `PrivacyRetentionListener` | `addOrderNoticeSection` | 20 |
| `core.privacy.retention_handlers` | filter | `PrivacyRetentionListener` | `addOrderHandler` | 20 |
| `core.search.build_response` | filter | `SearchProductsListener` | `buildProductsResponse` | 20 |
| `core.search.index_validation_rules` | filter | `SearchProductsListener` | `addValidationRules` | 20 |
| `core.search.results` | filter | `SearchProductsListener` | `searchProducts` | 20 |
| `core.user.after_create` | action (미선언) | `AssignDefaultCurrencyOnRegisterListener` | `handleAdminCreate` | 20 |
| `core.user.after_create` | action (미선언) | `AssignDefaultShippingCountryOnRegisterListener` | `handleAdminCreate` | 20 |
| `core.user.before_delete` | action (미선언) | `UserMileageCleanupListener` | `handleUserRemoval` | 10 |
| `core.user.before_withdraw` | action (미선언) | `UserMileageCleanupListener` | `handleUserRemoval` | 10 |
| `core.user.filter_resource_data` | filter | `UserCurrencyInfoListener` | `injectPreferredCurrency` | 25 |
| `core.user.filter_resource_data` | filter | `UserEcommerceDataInfoListener` | `injectEcommerceData` | 21 |
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
| `sirsoft-ecommerce.cart.after_bulk_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleCartAfterBulkDelete` | 20 |
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
| `sirsoft-ecommerce.claim-image.after_delete` | action (미선언) | `ClaimActivityLogListener` | `handleImageDeleted` | 20 |
| `sirsoft-ecommerce.claim-image.after_upload` | action (미선언) | `ClaimActivityLogListener` | `handleImageUploaded` | 20 |
| `sirsoft-ecommerce.claim.cancel.option_status_changed` | action (미선언) | `ClaimActivityLogListener` | `handleOptionStatusChanged` | 20 |
| `sirsoft-ecommerce.claim.cancel.requested` | action (미선언) | `ClaimActivityLogListener` | `handleCancelRequested` | 20 |
| `sirsoft-ecommerce.claim.cancel.status_changed` | action (미선언) | `ClaimActivityLogListener` | `handleStatusChanged` | 20 |
| `sirsoft-ecommerce.claim.exchange.after_mode_change` | action (미선언) | `ClaimActivityLogListener` | `handleExchangeModeChanged` | 20 |
| `sirsoft-ecommerce.claim.exchange.after_stock_restore` | action (미선언) | `ClaimActivityLogListener` | `handleStockRestored` | 20 |
| `sirsoft-ecommerce.claim.exchange.after_tracking_deliver` | action (미선언) | `ClaimActivityLogListener` | `handleTrackingDelivered` | 20 |
| `sirsoft-ecommerce.claim.exchange.after_tracking_update` | action (미선언) | `ClaimActivityLogListener` | `handleTrackingUpdated` | 20 |
| `sirsoft-ecommerce.claim.exchange.after_tracking_update` | action (미선언) | `ClaimStatusNotificationListener` | `handleTrackingUpdated` | 10 |
| `sirsoft-ecommerce.claim.exchange.amounts_updated` | action (미선언) | `ClaimActivityLogListener` | `handleAmountsUpdated` | 20 |
| `sirsoft-ecommerce.claim.exchange.collection_overdue` | action (미선언) | `ClaimStatusNotificationListener` | `handleCollectionOverdue` | 10 |
| `sirsoft-ecommerce.claim.exchange.option_status_changed` | action (미선언) | `ClaimActivityLogListener` | `handleOptionStatusChanged` | 20 |
| `sirsoft-ecommerce.claim.exchange.payment_completed` | action (미선언) | `ClaimActivityLogListener` | `handlePaymentCompleted` | 20 |
| `sirsoft-ecommerce.claim.exchange.payment_refunded` | action (미선언) | `ClaimActivityLogListener` | `handlePaymentRefunded` | 20 |
| `sirsoft-ecommerce.claim.exchange.request_updated` | action (미선언) | `ClaimActivityLogListener` | `handleRequestUpdated` | 20 |
| `sirsoft-ecommerce.claim.exchange.requested` | action (미선언) | `ClaimActivityLogListener` | `handleExchangeRequested` | 20 |
| `sirsoft-ecommerce.claim.exchange.requested` | action (미선언) | `ClaimStatusNotificationListener` | `handleExchangeRequested` | 10 |
| `sirsoft-ecommerce.claim.exchange.status_changed` | action (미선언) | `ClaimActivityLogListener` | `handleStatusChanged` | 20 |
| `sirsoft-ecommerce.claim.exchange.status_changed` | action (미선언) | `ClaimStatusNotificationListener` | `handleStatusChange` | 10 |
| `sirsoft-ecommerce.claim.payment_cancelled` | action (미선언) | `ClaimActivityLogListener` | `handlePaymentCancelled` | 20 |
| `sirsoft-ecommerce.claim.refund.amounts_updated` | action (미선언) | `ClaimActivityLogListener` | `handleAmountsUpdated` | 20 |
| `sirsoft-ecommerce.claim.refund.completed` | action (미선언) | `ClaimActivityLogListener` | `handleRefundExecuted` | 20 |
| `sirsoft-ecommerce.claim.refund.option_status_changed` | action (미선언) | `ClaimActivityLogListener` | `handleOptionStatusChanged` | 20 |
| `sirsoft-ecommerce.claim.refund.request_updated` | action (미선언) | `ClaimActivityLogListener` | `handleRequestUpdated` | 20 |
| `sirsoft-ecommerce.claim.refund.requested` | action (미선언) | `ClaimActivityLogListener` | `handleRefundRequested` | 20 |
| `sirsoft-ecommerce.claim.refund.requested` | action (미선언) | `ClaimStatusNotificationListener` | `handleRefundRequested` | 10 |
| `sirsoft-ecommerce.claim.refund.status_changed` | action (미선언) | `ClaimActivityLogListener` | `handleStatusChanged` | 20 |
| `sirsoft-ecommerce.claim.refund.status_changed` | action (미선언) | `ClaimStatusNotificationListener` | `handleStatusChange` | 10 |
| `sirsoft-ecommerce.claim.return.after_stock_restore` | action (미선언) | `ClaimActivityLogListener` | `handleStockRestored` | 20 |
| `sirsoft-ecommerce.claim.return.after_tracking_deliver` | action (미선언) | `ClaimActivityLogListener` | `handleTrackingDelivered` | 20 |
| `sirsoft-ecommerce.claim.return.after_tracking_update` | action (미선언) | `ClaimActivityLogListener` | `handleTrackingUpdated` | 20 |
| `sirsoft-ecommerce.claim.return.after_tracking_update` | action (미선언) | `ClaimStatusNotificationListener` | `handleTrackingUpdated` | 10 |
| `sirsoft-ecommerce.claim.return.amounts_updated` | action (미선언) | `ClaimActivityLogListener` | `handleAmountsUpdated` | 20 |
| `sirsoft-ecommerce.claim.return.option_status_changed` | action (미선언) | `ClaimActivityLogListener` | `handleOptionStatusChanged` | 20 |
| `sirsoft-ecommerce.claim.return.payment_completed` | action (미선언) | `ClaimActivityLogListener` | `handlePaymentCompleted` | 20 |
| `sirsoft-ecommerce.claim.return.payment_refunded` | action (미선언) | `ClaimActivityLogListener` | `handlePaymentRefunded` | 20 |
| `sirsoft-ecommerce.claim.return.request_updated` | action (미선언) | `ClaimActivityLogListener` | `handleRequestUpdated` | 20 |
| `sirsoft-ecommerce.claim.return.requested` | action (미선언) | `ClaimActivityLogListener` | `handleReturnRequested` | 20 |
| `sirsoft-ecommerce.claim.return.requested` | action (미선언) | `ClaimStatusNotificationListener` | `handleReturnRequested` | 10 |
| `sirsoft-ecommerce.claim.return.status_changed` | action (미선언) | `ClaimActivityLogListener` | `handleStatusChanged` | 20 |
| `sirsoft-ecommerce.claim.return.status_changed` | action (미선언) | `ClaimStatusNotificationListener` | `handleStatusChange` | 10 |
| `sirsoft-ecommerce.claim_reason.after_create` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleClaimReasonAfterCreate` | 20 |
| `sirsoft-ecommerce.claim_reason.after_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleClaimReasonAfterDelete` | 20 |
| `sirsoft-ecommerce.claim_reason.after_toggle_status` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleClaimReasonAfterToggleStatus` | 20 |
| `sirsoft-ecommerce.claim_reason.after_update` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleClaimReasonAfterUpdate` | 20 |
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
| `sirsoft-ecommerce.notification.extract_data` | filter | `ClaimNotificationDataListener` | `extractData` | 25 |
| `sirsoft-ecommerce.notification.extract_data` | filter | `EcommerceNotificationDataListener` | `extractData` | 20 |
| `sirsoft-ecommerce.notification.personalize_data` | filter | `ClaimNotificationDataListener` | `personalizeClaimData` | 25 |
| `sirsoft-ecommerce.notification.personalize_data` | filter | `EcommerceNotificationDataListener` | `personalizeOrderData` | 20 |
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
| `sirsoft-ecommerce.order.after_status_sync` | action (미선언) | `OrderActivityLogListener` | `handleOrderStatusSync` | 20 |
| `sirsoft-ecommerce.order.after_update` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterUpdate` | 20 |
| `sirsoft-ecommerce.order.after_update_shipping_address` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterUpdateShippingAddress` | 20 |
| `sirsoft-ecommerce.order.payment_failed` | action (미선언) | `OrderActivityLogListener` | `handleOrderAfterPaymentFailed` | 20 |
| `sirsoft-ecommerce.order.tax_invoice_stale_after_cancel` | action (미선언) | `OrderActivityLogListener` | `handleTaxInvoiceStaleAfterCancel` | 20 |
| `sirsoft-ecommerce.order_option.after_bulk_status_change` | action (미선언) | `MileageTransactionListener` | `handleAfterBulkStatusChange` | 10 |
| `sirsoft-ecommerce.order_option.after_bulk_status_change` | action (미선언) | `OrderActivityLogListener` | `handleOrderOptionAfterBulkStatusChange` | 20 |
| `sirsoft-ecommerce.order_option.after_status_change` | action (미선언) | `MileageTransactionListener` | `handleAfterStatusChange` | 10 |
| `sirsoft-ecommerce.order_option.after_status_change` | action (미선언) | `OrderActivityLogListener` | `handleOrderOptionAfterStatusChange` | 20 |
| `sirsoft-ecommerce.order_option.after_sync_status` | action (미선언) | `OrderActivityLogListener` | `handleOrderOptionAfterSyncStatus` | 20 |
| `sirsoft-ecommerce.order_shipping.tracking_updated` | action (미선언) | `OrderActivityLogListener` | `handleOrderShippingTrackingUpdated` | 20 |
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
| `sirsoft-ecommerce.product_inquiry.after_bulk_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleProductInquiryAfterBulkDelete` | 20 |
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
| `sirsoft-ecommerce.user_address.after_bulk_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleUserAddressAfterBulkDelete` | 20 |
| `sirsoft-ecommerce.user_coupon.after_download` | action (미선언) | `EcommerceUserActivityLogListener` | `handleUserCouponAfterDownload` | 20 |
| `sirsoft-ecommerce.wishlist.after_bulk_delete` | action (미선언) | `EcommerceAdminActivityLogListener` | `handleWishlistAfterBulkDelete` | 20 |
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

### 회원 응답에 얹는 필드 — `core.user.filter_resource_data`

이 모듈은 코어 회원 응답에 아래 두 가지를 덧붙입니다. **둘은 서로 다른 경로에 실립니다** —
관리자 회원 상세(`/api/admin/users/{uuid}`)와 프로필(`/api/me`) 이 같은 리소스를 쓰지만
얹는 것이 다르므로, 확장이 둘을 같은 모양으로 기대하면 한쪽에서 빈 값을 봅니다.

| 키 | 실리는 경로 | 모양 | 비는 조건 |
|---|---|---|---|
| `ecommerce_data` | 관리자 응답만 | `{ wishlist_count, cart_count, address_count, inquiry_count, inquiry_pending_count, addresses[] }` | 관리자 경로가 아니면 키 자체가 없음 |
| `ecommerce_default_address` | 프로필(`/api/me`)만 | 요약 줄 1건 또는 `null` | 기본 배송지가 없으면 `null` |

`addresses[]` 와 `ecommerce_default_address` 의 요약 줄은 같은 모양입니다 —
`{ id, label, recipient_name, recipient_phone, country_code, address_summary, is_default }`.
`address_summary` 는 서버가 국내/해외 컬럼을 합쳐 만든 완성 문자열이므로 화면에서 다시
조립하지 않습니다(합치는 규칙이 두 벌이 되면 한쪽만 고쳐져 어긋납니다).

건수는 관리자 응답에만 싣습니다 — 프로필은 부팅 경로라 COUNT 를 네 번 돌리면 모든 화면이
그만큼 느려지고, 사용자 화면은 각 탭이 자기 목록 API 로 같은 값을 더 정확하게 냅니다.
기본 배송지 한 줄만 예외인 것은 인덱스 단일 행 조회 한 건이기 때문입니다.

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ActivityLogDescriptionResolver` | 1개 | 명시 등록 | ✅ | `src/Listeners/ActivityLogDescriptionResolver.php` |
| `AssignDefaultCurrencyOnRegisterListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/AssignDefaultCurrencyOnRegisterListener.php` |
| `AssignDefaultShippingCountryOnRegisterListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/AssignDefaultShippingCountryOnRegisterListener.php` |
| `BuildsEnumFieldChange` | 0개 | 자동 발견 | ❌ | `src/Listeners/Concerns/BuildsEnumFieldChange.php` |
| `CategoryActivityLogListener` | 5개 | 명시 등록 | ✅ | `src/Listeners/CategoryActivityLogListener.php` |
| `CategoryTreeCacheListener` | 0개 | 명시 등록 | ✅ | `src/Listeners/CategoryTreeCacheListener.php` |
| `Ckeditor5ReferenceSourcesListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Ckeditor5ReferenceSourcesListener.php` |
| `ClaimActivityLogListener` | 33개 | 명시 등록 | ✅ | `src/Listeners/ClaimActivityLogListener.php` |
| `ClaimNotificationDataListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/ClaimNotificationDataListener.php` |
| `ClaimStatusNotificationListener` | 9개 | 명시 등록 | ✅ | `src/Listeners/ClaimStatusNotificationListener.php` |
| `CouponActivityLogListener` | 6개 | 명시 등록 | ✅ | `src/Listeners/CouponActivityLogListener.php` |
| `CouponRestoreListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/CouponRestoreListener.php` |
| `CouponUseListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/CouponUseListener.php` |
| `EcommerceAdminActivityLogListener` | 49개 | 명시 등록 | ✅ | `src/Listeners/EcommerceAdminActivityLogListener.php` |
| `EcommerceNotificationDataListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/EcommerceNotificationDataListener.php` |
| `EcommerceUserActivityLogListener` | 9개 | 명시 등록 | ✅ | `src/Listeners/EcommerceUserActivityLogListener.php` |
| `InjectAppConfigDeviceListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/InjectAppConfigDeviceListener.php` |
| `IssueCashReceiptOnDepositListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/IssueCashReceiptOnDepositListener.php` |
| `MergeCartOnLoginListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/MergeCartOnLoginListener.php` |
| `MileageTransactionListener` | 5개 | 명시 등록 | ✅ | `src/Listeners/MileageTransactionListener.php` |
| `OrderActivityLogListener` | 25개 | 명시 등록 | ✅ | `src/Listeners/OrderActivityLogListener.php` |
| `OrderStatusNotificationListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/OrderStatusNotificationListener.php` |
| `PrivacyRetentionListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/PrivacyRetentionListener.php` |
| `ProductActivityLogListener` | 7개 | 명시 등록 | ✅ | `src/Listeners/ProductActivityLogListener.php` |
| `ProductInquiryBoardListener` | 5개 | 명시 등록 | ✅ | `src/Listeners/ProductInquiryBoardListener.php` |
| `PurgeCashReceiptIdentifierListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/PurgeCashReceiptIdentifierListener.php` |
| `ResolvesClaimIdentity` | 0개 | 자동 발견 | ❌ | `src/Listeners/Concerns/ResolvesClaimIdentity.php` |
| `SearchProductsListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/SearchProductsListener.php` |
| `SeoCategoryCacheListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/SeoCategoryCacheListener.php` |
| `SeoProductCacheListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/SeoProductCacheListener.php` |
| `SeoSettingsCacheListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/SeoSettingsCacheListener.php` |
| `ShippingPolicyActivityLogListener` | 5개 | 명시 등록 | ✅ | `src/Listeners/ShippingPolicyActivityLogListener.php` |
| `ShippingPolicyCacheListener` | 0개 | 명시 등록 | ✅ | `src/Listeners/ShippingPolicyCacheListener.php` |
| `SyncOptionGroupsListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/SyncOptionGroupsListener.php` |
| `SyncProductFromOptionListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/SyncProductFromOptionListener.php` |
| `UserCurrencyInfoListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/UserCurrencyInfoListener.php` |
| `UserEcommerceDataInfoListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/UserEcommerceDataInfoListener.php` |
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
- **클레임 활동 로그** (`ClaimActivityLogListener`): 교환·반품·환불 신청서와 클레임 추가결제·첨부의
  전 상태 변경을 기록합니다. 행위자(`user_id`)는 코어 `ActivityLogProcessor` 가 자동 주입하므로
  신청서 테이블에 `*_by` 컬럼을 새로 만들지 않습니다. 전이는 `status_changed` 한 축으로만
  기록합니다 — 승인·반려·철회·완료는 `claim.{type}.approved` 같은 구체 훅도 함께 발화하는데,
  양쪽을 구독하면 한 번의 전이가 두 줄로 남습니다. 구체 훅은 알림 리스너
  (`ClaimStatusNotificationListener`)의 몫입니다. 모델에서 값을 읽는 구독(접수 · 상품 단위 전이 ·
  추가결제 수납/환급)과 알림 매핑 리스너 전체는 `'sync' => true` 입니다 — 큐 워커가 신청서를 다시
  읽으면 그 사이 옮겨 간 "지금" 상태가 과거 사건의 기록·알림에 실리기 때문입니다.
- **연동·주입 나머지**: 장바구니 병합 · 가입 시 통화/배송국가 배정 · 문의 피벗 정리 · 옵션↔상품
  대표값 동기화 · 회원 정보 화면에 마일리지/통화/배송국가 주입.

구독 수가 0인 두 리스너(`CategoryTreeCacheListener` · `ShippingPolicyCacheListener`)는 훅
이름을 상수·변수로 조립해 정적 수집에 잡히지 않을 뿐 실제로는 등록되어 있습니다.

리스너에서 `Model::query()` · `DB::table()` · `$row->save()` 를 직접 부르지 않습니다 — 데이터
접근은 Repository 인터페이스 주입으로만 합니다.

`ClaimActivityLogListener` 의 훅 ↔ action 매핑(훅별 action 키·행위자·loggable)은 아래 「활동 로그 훅」 절의
`ClaimActivityLogListener (19훅)` 소절에 있습니다 — 그 절의 표가 리스너의 실제 구독과 일치하는지를 코어
계약 테스트가 고정하므로, 클레임 훅을 더하거나 뺄 때는 그 표를 함께 고칩니다.

**클레임 훅 페이로드 스키마**

같은 훅을 확장이 구독할 수 있으므로 인자 순서와 형이 계약이다. `$previous`/`$target` 은 **전이 시점에 캡처된 스칼라**이며, 큐 지연 중 재전이된 모델의 현재 상태와 다를 수 있다 — 매핑·기록의 기준은 언제나 이 인자다.

| 훅 | 인자 |
|----|------|
| `claim.{exchange\|return}.requested` | `($claim, Order $order)` |
| `claim.refund.requested` | `(OrderRefund $refund, Order $order, array $context = [])` — 환불 접수 경로는 `$context['suppress_notification']`(bool)를 싣는다. 접수 직후 자동 전이가 승인·완료까지 밟는 건이면 true 이고, 알림 리스너는 마지막 상태에서 1건만 보낸다. 반품·교환 완료가 만든 환불 접수는 세 번째 인자 없이 발화한다 |
| `claim.cancel.requested` | `(OrderCancel $cancel, Order $order)` — 취소신청서는 환불이 완료되는 순간에 생긴다 |
| `claim.{exchange\|return\|cancel}.status_changed` | `($claim, ?string $previousStatus, ?string $targetStatus)` |
| `claim.refund.status_changed` | `(OrderRefund $refund, ?string $previousStatus, ?string $targetStatus, array $context = [])` — `$context['suppress_notification']` 는 자동 전이의 중간 단계 알림만 끈다(활동 로그는 끄지 않는다) |
| `claim.{exchange\|return\|refund}.amounts_updated` | `($claim, array $before, array $after, ?int $processedBy)` — 스냅샷 키는 교환·반품 `claim_shipping_fee`/`total_charge_amount`/`charge_method`/`admin_memo`, 환불 `refund_amount`/`refund_points_amount`/`refund_method` |
| `claim.refund.completed` | `(OrderRefund $refund)` — 환불 **실행 확정**(돈이 나간 사건). 같은 순간의 `approved → completed` 전이는 `status_changed` 가 따로 낸다 |
| `claim.{exchange\|return}.payment_completed` | `($claim, ClaimPayment $payment)` — 청구액이 **전액 수납**됐을 때만, 그리고 신청서를 `paid` 로 옮긴 **뒤에** 발화한다 |
| `claim.{exchange\|return}.payment_refunded` | `($claim, ClaimPayment $payment, float $refundAmount)` |
| `claim.{exchange\|return\|refund\|cancel}.option_status_changed` | `($claim, array $changes, ?string $targetStatus, ?int $processedBy)` — `$changes` 는 `{option_id, previous, new}` 목록이며 **실제로 옮겨진 상품만** 담는다(건너뛴 상품은 빠진다). 옮긴 것이 없으면 훅 자체가 발화하지 않는다. 그 회차에 **수량 분할**이 일어난 행은 `quantity`(전이분)와 `remainder_option_id`(잔여 행 ID)가 함께 실린다 — 분할 사실을 계보 컬럼으로 두지 않으므로 이 두 값이 유일한 흔적이다. 발행 측이 `remainder_quantity`(분할 직후 잔여 수량)를 함께 실으면 활동 로그는 그 값을 쓴다 |
| `claim.items_transitioned` | `($claim, array $result, string $target)` — 상품 단위 전이 [일괄 적용] 1회가 끝났다는 **도메인 사실**. `$result` 는 `{succeeded: int[], skipped: array}` 다. 부분 처리 안내를 보낼지는 알림 리스너가 판정한다 |
| `claim.{exchange\|return\|refund}.notify_{key}` · `claim.notify_withdrawn` · `claim.notify_withdrawn_on_behalf` | `($claim, array $snapshot)` — 알림 전용 훅. `$snapshot` 은 **발행 시점에 캡처한 스칼라**다: `status`(그 알림이 알리는 상태 값), 교환·반품은 `outstanding_amount`(미수납 청구액, 기준 통화). 알림 발송은 큐에서 돌므로 본문 값은 이 스냅샷에서 만든다 — 신청서를 다시 읽으면 과거 사건의 알림에 지금 값이 실린다 |
| `claim.notify_partially_processed` | `($claim, array $summary)` — `$summary` 는 이번 회차의 건수·결정 표기·상품 줄(`total_count`·`processed_count`·`remaining_count`·`approved_count`·`rejected_count`·`decision_label`·`item_lines`) 문자열 맵이며, 발행 시점의 헤더 상태 값 `status` 가 함께 실린다(본문 변수로는 쓰지 않고 상태 라벨을 만드는 재료다) |
| `claim.payment_cancelled` | `(ClaimPayment $payment, OrderExchange\|OrderReturn\|null $claim, string $reason)` — `$reason` 은 `ClaimPaymentService::CANCEL_REASON_*`(`claim_finalized` / `charge_settled` / `orphan_reaped`). 대상은 언제나 **미수납 대기 행**이라 돈이 오간 사건이 아니다 |
| `claim-image.before_upload` | `(UploadedFile $file, string $claimableType, int $claimableId, string $uploaderType)` — 저장 전 거절 지점(예외를 던지면 파일도 행도 남지 않는다). `$claimableType` 은 신청서 morph 별칭(`exchange`/`return`/`refund`) 또는 미귀속(`pending`, 이때 `$claimableId` 는 주문 ID), `$uploaderType` 은 `customer`/`admin` |
| `claim-image.filter_upload_file` | `(UploadedFile $file)` — 변형본을 돌려주면 저장 파일명·MIME·크기가 그 파일 기준이 된다 |
| `claim-image.after_upload` / `after_delete` | `(ClaimImage $image)` |
| `order_option.after_sync_status` | `(array $changes, string $newStatus, string $source)` — 주문 상태 동기화가 raw update 로 함께 옮긴 주문상품. `$changes` 는 `{order_option_id, order_id, previous, new}` 목록, `$source` 는 `order_status_sync`(결제완료·결제실패 등 처리 서비스) / `bulk_update`(관리자 주문 수정·일괄 상태변경) |

**클레임 훅의 이름이 표준 패턴(`{entity}.{before|after}_{action}`)을 따르지 않는 이유**

클레임 도메인 훅은 `claim.{type}.requested` · `status_changed` · `payment_completed` 처럼 **과거형 사건 이름**을 쓴다. 표준 패턴의 `after_*` 와 뜻이 같지만(모두 일이 끝난 뒤 발화한다) 이름을 바꾸지 않는 이유는 둘이다.

- 한 동작이 여러 사건을 낸다 — 승인 한 번에 `status_changed` 와 `approved` 가 함께 발화하고, 상품이 갈리면 `option_status_changed` 가 따로 선다. `after_approve` 한 줄로는 「전이」와 「승인」 두 층을 나눌 수 없다.
- 알림 전용 훅(`notify_*`)은 도메인 사건과 **다른 축**이다 — 자동 전이의 중간 단계처럼 사건은 있었으나 알리지 않는 조합이 있어 이름으로 구분한다(`ClaimNotificationHooks`).

첨부 훅(`claim-image.*`)은 다른 업로드 지점(`product-image` · `review-image`)과 같은 하이픈 표기와 업로드 트라이어드(`before_upload` → `filter_upload_file` → `after_upload`)를 따른다. `order_option.after_sync_status` · `order_shipping.tracking_updated` 는 전이 훅을 지나지 않는 경로의 사실을 알리는 보조 훅이다.

**발행되지만 활동 로그가 구독하지 않는 클레임 훅**

확장이 쓸 수 있도록 함께 적는다. 활동 로그가 구독하지 않는 이유는 위 "전이는 한 축으로만" 규칙이거나, 알림 전용 훅이기 때문이다.

| 훅 | 인자 | 발행 지점 / 용도 |
|----|------|-----------------|
| `claim.exchange.{approved,rejected,withdrawn,completed}` | `($exchange)` | `OrderExchangeService::fireStatusHooks` — 특정 전이만 구독하려는 확장용 |
| `claim.return.{approved,rejected,withdrawn,completed}` | `($return)` | `OrderReturnService::fireStatusHooks` |
| `claim.refund.{approved,rejected,withdrawn,on_hold}` | `($refund)` | `OrderRefundRequestService::transitionRefund` |
| `claim.{exchange\|return\|refund}.notify_{상태}` (29종) | `($claim)` | `ClaimStatusNotificationListener` — 알림 정의 전용. 상태마다 고유 훅이어야 그 상태의 알림만 나간다 |
| `claim.notify_withdrawn` | `($claim)` | 철회 관리자 알림(유형 3종 공용) |
| `claim.notify_withdrawn_on_behalf` | `($claim, array $snapshot)` | 대리 철회 안내(고객 수신, 유형 3종 공용 — `claim_withdrawn_on_behalf`). 운영자가 고객 요청으로 대신 철회(`withdrawn_by_admin` 기록)했을 때만 `claim.notify_withdrawn` 과 함께 나간다. 고객 스스로 철회면 나가지 않는다 |
| `claim.items_transitioned` | `($claim, array $result, string $target)` | `AdminClaimService` — 상품 단위 전이가 끝났다는 **도메인 사실**. 「알릴 것인가」를 정하지 않는다 |
| `claim.notify_partially_processed` | `($claim, array $variables)` | 부분 처리 안내(고객 수신). 판정·집계는 `ClaimStatusNotificationListener` 가 위 사실 훅을 받아 단독으로 한다 — 발화 규칙이 두 곳에 생기지 않게 |
| `claim-image.filter_upload_file` (filter) | `(UploadedFile $file)` | 업로드 직전 파일 가공 |
| `claim_payment.{approve,refund,void_approval}` (filter) | 결제 플러그인 연동 계약 | `modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` "결제 플러그인 연동 계약" 이 정본 |

---
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/admin-user-currency-field.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_form` |
| `resources/extensions/admin-user-ecommerce-data-tab.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_detail` |
| `resources/extensions/admin-user-mileage-tab.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_detail` |
| `resources/extensions/admin-user-shipping-country-field.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_form` |
| `resources/extensions/admin_dashboard_commerce.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `admin_dashboard_commerce` |
| `resources/extensions/admin_dashboard_quick_menu.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `admin_dashboard_quick_menu` |
| `resources/extensions/checkout_cash_receipt.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `shop_checkout_cash_receipt_slot` |
| `resources/extensions/header-currency-selector-admin.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `_admin_base` |
| `resources/extensions/header-currency-selector-user.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `_user_base` |
| `resources/extensions/mypage-profile-address-card.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `mypage/profile` |
| `resources/extensions/mypage-profile-mileage-card.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `mypage/profile` |
| `resources/extensions/mypage_order_cash_receipt.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `mypage_order_cash_receipt_slot` |
| `resources/extensions/register-currency-field.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `auth/register` |
| `resources/extensions/register-shipping-country-field.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `auth/register` |
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

### PG 플러그인이 결제 정보 카드에 붙일 때의 약속

주문 상세·신청서 상세의 결제 정보 카드에 영수증·입금 안내를 붙이는 PG 플러그인은 아래를 지킨다.

- **카드는 id 로만 찾는다.** 템플릿은 결제 정보 카드에 `id="order_payment_info_panel"` 을 단다(sirsoft-basic 의
  `partials/mypage/orders/_payment` — 회원·비회원 주문 상세와 신청서 상세가 함께 쓴다). 플러그인은 이 id 로만 카드를 찾고,
  화면 제목 글자(「결제 정보」 등)로 찾지 않는다 — 제목은 언어마다 다르다. id 가 없는 템플릿에서는 붙이지 않는다.
- **결제 방법 행도 id 로만 찾는다.** 간편결제 이름(예: 카카오페이)을 결제 방법 값에 덧붙일 때, 사용자 화면은 템플릿이 단
  `id="order_payment_method_row"`(같은 `_payment` 조각의 결제 방법 행 — 주문 완료 화면에 그 행을 두는 템플릿도 같은 id 를 쓴다),
  관리자 주문 상세는 이 모듈이 단 `id="payment_method_block_{결제 순번}"`(`admin_ecommerce_order_detail/_partial_payment_info` —
  첫 칸이 이름, 다음 칸이 값)로만 찾는다. 행 이름 글자(「결제 방법」「결제수단」)로 찾지 않는다. id 가 없으면 건드리지 않는다.
- **날짜는 표시용 값을 그대로 쓴다.** 결제 정보의 입금 기한은 `vbank_due_at_formatted`(사용자 언어·시간대로 서식을 입힌 값)를
  보여 주고, 원시 값(`vbank_due_at`, ISO8601)에 플러그인이 다시 서식을 입히지 않는다. 날짜 서식은 이 모듈(서버)의 책임이다.

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 미들웨어 | 부착 대상(targets) | 우선순위 |
|---|---|---|
| `DetectDevice` | `/` | - |
| `ResolveShippingCountry` | `api.modules.sirsoft-ecommerce.products.*`, `api.modules.sirsoft-ecommerce.cart.*`, `api.modules.sirsoft-ecommerce.checkout.*`, `api.modules.sirsoft-ecommerce.user.orders.store` | - |
| `VerifyGuestOrderToken` | `api.modules.sirsoft-ecommerce.guest.orders.cancel`, `api.modules.sirsoft-ecommerce.guest.orders.estimate-refund`, `api.modules.sirsoft-ecommerce.guest.orders.update-shipping-address`, `api.modules.sirsoft-ecommerce.guest.orders.confirm-option`, `api.modules.sirsoft-ecommerce.guest.orders.cash-receipt.*`, `api.modules.sirsoft-ecommerce.guest.orders.claimable-options`, `api.modules.sirsoft-ecommerce.guest.orders.claims.*`, `api.modules.sirsoft-ecommerce.guest.orders.exchanges.*`, `api.modules.sirsoft-ecommerce.guest.orders.returns.*`, `api.modules.sirsoft-ecommerce.guest.orders.refund-requests.*`, `api.modules.sirsoft-ecommerce.guest.orders.claim-images.*`, `api.modules.sirsoft-ecommerce.guest.orders.claim-payments.*` | - |
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

비회원 클레임 라우트(`guest/orders/{orderNumber}/claims/…` 계열)도 `VerifyGuestOrderToken`
대상입니다 — 주문 조회 토큰이 곧 신원이므로 비회원은 신청·수정·철회·결제·첨부까지 같은 토큰으로
합니다. 새 비회원 클레임 라우트를 추가하면 그 이름을 이 선언에 함께 넣어야 합니다.
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
| `sirsoft-ecommerce:prune-pending-claim-images` | `daily` | 신청으로 이어지지 않은 클레임 임시 첨부 자동 삭제 |
| `sirsoft-ecommerce:cancel-orphan-claim-payments` | `daily` | 종결된 신청서에 남은 클레임 결제 대기 건 자동 취소 |
| `sirsoft-ecommerce:claims:notify-overdue-collections` | `daily` | 선교환 헌 상품 회수 지연 관리자 알림 (1회) |
<!-- @generated:schedules END -->

<!-- @intent START -->
11개 스케줄이 이 모듈의 **시간 축 동작 전부**입니다. 이 중 6개는 환경설정 토글
(`enabled_config`)에 묶여 있어 그 설정이 꺼져 있으면 실행되지 않습니다 —
`cancel-pending-orders`(주문 설정의 미입금 자동취소) · 마일리지 4종(마일리지 사용/소멸/소멸
알림) · `aggregate-stats`(대시보드 집계). 임시 데이터 정리 3종과 클레임 정리 2종은 토글 없이 상시 동작하며 보존
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
| `prune-pending-claim-images` | 신청서에 붙지 못한 채 남은 첨부 임시 파일이 무한히 쌓입니다 |
| `cancel-orphan-claim-payments` | 결제창에서 돌아오지 않은 클레임 추가결제 건이 결제 대기로 영영 남아 그 신청서가 다음 단계로 못 갑니다 |

이 중 **재고와 금전에 직접 영향을 주는 것은 `cancel-pending-orders` 와 마일리지 3종**입니다.
서버에 스케줄러가 등록되지 않은 설치에서는 이 넷이 침묵하는 것이 유일한 증상이며, 오류가 나지
않으므로 운영자가 알아채기 어렵습니다.

새 스케줄을 추가할 때는 `command` 와 함께 **`schedule` 키를 반드시 선언**합니다 — 이 키가
없으면 코어 스케줄 등록부가 그 항목을 건너뛰는데, 예외도 경고도 남지 않아 "등록했는데 돌지
않는다"가 됩니다.
<!-- @intent END -->

## 알림 정의

### 배포 절차 — 신설 정의가 실제로 발화하려면

알림 정의는 선언형 산출물이라 `module:update` 가 돌 때 동기화된다. 그런데 **정의 목록은
캐시된다** — 새 `type` 을 선언만 하고 배포하면 캐시가 옛 목록을 계속 들고 있어 그 알림이
**아무 오류 없이 안 나간다**. 훅은 발화하는데 받을 정의가 없어 조용히 끝난다.

배포 순서:

1. `php artisan module:update sirsoft-ecommerce --force` — 선언형 산출물(권한·메뉴·알림) 동기화
2. `php artisan optimize:clear` — 정의 캐시 비우기. **이 단계를 빼면 신설 알림이 조용히 안 나간다**
3. 확인: 환경설정 › 알림 설정 목록에 새 `type` 이 보이는가
   (자동 확인은 `modules/_bundled/sirsoft-ecommerce/tests/Feature/Notifications/StaffNotificationDefinitionsSeedTest.php` 가 같은 축을 잰다)

기존 정의의 **수신자·링크를 바꾼 경우**는 한 가지가 더 있다. 운영자가 그 템플릿을 관리
화면에서 한 번이라도 고쳤으면 그 값이 `user_overrides` 로 보존되어 새 기본값이 반영되지
않는다 — 대상 알림을 릴리스 노트에 적고, 운영자가 [기본값 복원]을 실행하도록 안내한다.

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
| `order_confirmed_admin` | `mail`, `database` |
| `order_completed_admin` | `mail`, `database` |
| `inquiry_received` | `mail`, `database` |
| `inquiry_replied` | `mail`, `database` |
| `mileage_expiring_soon` | `mail`, `database` |
| `exchange_requested` | `mail`, `database` |
| `exchange_requested_admin` | `mail`, `database` |
| `exchange_payment_requested` | `mail`, `database` |
| `exchange_payment_completed` | `mail`, `database` |
| `exchange_payment_completed_admin` | `mail`, `database` |
| `exchange_approved` | `mail`, `database` |
| `exchange_on_hold` | `mail`, `database` |
| `exchange_rejected` | `mail`, `database` |
| `exchange_collection_ready` | `mail`, `database` |
| `exchange_collecting` | `mail`, `database` |
| `exchange_collected` | `mail`, `database` |
| `exchange_redelivery_ready` | `mail`, `database` |
| `exchange_redelivering` | `mail`, `database` |
| `exchange_redelivered` | `mail`, `database` |
| `exchange_completed` | `mail`, `database` |
| `return_requested` | `mail`, `database` |
| `return_requested_admin` | `mail`, `database` |
| `return_payment_requested` | `mail`, `database` |
| `return_payment_completed` | `mail`, `database` |
| `return_payment_completed_admin` | `mail`, `database` |
| `return_approved` | `mail`, `database` |
| `return_on_hold` | `mail`, `database` |
| `return_rejected` | `mail`, `database` |
| `return_collection_ready` | `mail`, `database` |
| `return_collecting` | `mail`, `database` |
| `return_collected` | `mail`, `database` |
| `return_completed` | `mail`, `database` |
| `refund_requested` | `mail`, `database` |
| `refund_requested_admin` | `mail`, `database` |
| `refund_approved` | `mail`, `database` |
| `refund_on_hold` | `mail`, `database` |
| `refund_rejected` | `mail`, `database` |
| `refund_completed` | `mail`, `database` |
| `claim_withdrawn` | `mail`, `database` |
| `claim_withdrawn_on_behalf` | `mail`, `database` |
| `claim_partially_processed` | `mail`, `database` |
| `exchange_collection_tracking_registered` | `mail`, `database` |
| `exchange_redelivery_tracking_registered` | `mail`, `database` |
| `return_collection_tracking_registered` | `mail`, `database` |
| `exchange_collection_overdue` | `mail`, `database` |
<!-- @generated:notifications END -->

<!-- @intent START -->
39종 모두 코어 `GenericNotification` 을 쓰며 개별 Notification 클래스를 두지 않습니다. 채널은
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

클레임(교환·반품·환불) 알림은 **고객이 볼 수 있는 상태 전이 전수**에 하나씩 대응합니다 —
접수 · 결제 요청 · 결제 완료 · 승인 · 보류 · 반려 · 회수 단계 · 재발송 단계 · 완료 · 철회. 발송은
`ClaimStatusNotificationListener` 가 `claim.*` 훅을 받아 수행하고, 비회원 신청서는 위와 같은
`guest_recipient` 규약을 씁니다. 상태를 하나 더하면 알림 정의도 하나 더해야 합니다 — 정의가 없는
상태는 전이는 되는데 알림만 조용히 빠집니다.
<!-- @intent END -->

## 활동 로그 훅

> 이 확장이 코어 활동 로그(`activity_logs`)에 기록을 남기기 위해 구독하는 훅 **127종**입니다
> (등록 건수는 129건 — `order.after_create` 와 `order-option.after_confirm` 은 관리자 관점과
> 구매자 관점 두 리스너가 각각 구독합니다).
> 코어 `docs/backend/activity-log-hooks.md` 에 있던 목록을 이 확장 소유로 옮긴 것입니다(#601) —
> 확장이 훅을 더할 때 코어 문서를 고쳐야 하던 역방향 의존을 없애기 위해서입니다. 코어 문서에는
> 총계와 이 문서로의 링크만 남습니다.

> 새 항목을 추가하면 action 라벨과 description 본문을 이 모듈의 `src/lang/{ko,en}/activity_log.php`
> 와 번들 일본어 팩에 함께 정의합니다. description 은 리스너가 `sirsoft-ecommerce::activity_log.description.*`
> 키로 남기므로 모듈 lang 에서 해석되고, action 라벨은 **모듈 lang 을 먼저** 찾은 뒤 없을 때만 코어
> `lang/{ko,en}/activity_log.php` 로 내려갑니다.

### 이커머스 모듈 훅

**모듈**: `sirsoft-ecommerce`
**등록 129건 / 훅 127종** (8개 Listener)

#### OrderActivityLogListener (25훅)

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

##### 상태 동기화 · 송장 · 세금계산서 (4훅)

상품 상태가 바뀌어 주문 상태가 따라 움직였을 때(`status_sync`), 일괄 상태 변경으로 상품 상태가
맞춰졌을 때(`sync_status`), 송장이 바뀌었을 때, 취소로 이미 발행된 세금계산서가 낡았을 때를 남긴다.

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.order.after_status_sync` | `handleOrderStatusSync` | `order.status_sync` | Admin | Order |
| `sirsoft-ecommerce.order_option.after_sync_status` | `handleOrderOptionAfterSyncStatus` | `order_option.sync_status` | Admin | Order |
| `sirsoft-ecommerce.order_shipping.tracking_updated` | `handleOrderShippingTrackingUpdated` | `order_shipping.tracking_update` | Admin | OrderOption |
| `sirsoft-ecommerce.order.tax_invoice_stale_after_cancel` | `handleTaxInvoiceStaleAfterCancel` | `order.tax_invoice_stale` | Admin | Order |

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

#### EcommerceAdminActivityLogListener (45훅)

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
| `sirsoft-ecommerce.wishlist.after_bulk_delete` | `handleWishlistAfterBulkDelete` | `wishlist.bulk_delete` | Admin | ProductWishlist |
| `sirsoft-ecommerce.cart.after_bulk_delete` | `handleCartAfterBulkDelete` | `cart.bulk_delete` | Admin | Cart |
| `sirsoft-ecommerce.user_address.after_bulk_delete` | `handleUserAddressAfterBulkDelete` | `user_address.bulk_delete` | Admin | UserAddress |
| `sirsoft-ecommerce.product_inquiry.after_bulk_delete` | `handleProductInquiryAfterBulkDelete` | `product_inquiry.bulk_delete` | Admin | ProductInquiry |
| `sirsoft-ecommerce.shipping_policy.after_bulk_toggle_active` | `handleShippingPolicyAfterBulkToggleActive` | `shipping_policy.bulk_toggle_active` | Admin | - |

##### ProductOption (3훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.product_option.after_bulk_price_update` | `handleOptionAfterBulkPriceUpdate` | `product_option.bulk_price_update` | Admin | - |
| `sirsoft-ecommerce.product_option.after_bulk_stock_update` | `handleOptionAfterBulkStockUpdate` | `product_option.bulk_stock_update` | Admin | - |
| `sirsoft-ecommerce.option.after_bulk_update` | `handleOptionAfterBulkUpdate` | `product_option.bulk_update` | Admin | - |

##### ClaimReason (4훅)

환경설정 > 클레임 탭의 교환·반품·환불 사유 카탈로그 변경을 남긴다. 사유를 지우면 모델이 사라지므로 삭제 줄은 대상 없이
사유 코드·유형을 `properties` 로 남긴다.

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.claim_reason.after_create` | `handleClaimReasonAfterCreate` | `claim_reason.create` | Admin | ClaimReason |
| `sirsoft-ecommerce.claim_reason.after_update` | `handleClaimReasonAfterUpdate` | `claim_reason.update` | Admin | ClaimReason |
| `sirsoft-ecommerce.claim_reason.after_delete` | `handleClaimReasonAfterDelete` | `claim_reason.delete` | Admin | - |
| `sirsoft-ecommerce.claim_reason.after_toggle_status` | `handleClaimReasonAfterToggleStatus` | `claim_reason.toggle_status` | Admin | ClaimReason |

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

#### ClaimActivityLogListener (23훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/ClaimActivityLogListener.php`

교환·반품·환불 신청서와 클레임 추가결제·첨부의 전 상태 변경을 기록한다. 행위자(`user_id`)는 코어 `ActivityLogProcessor` 가 자동 주입하므로 신청서 테이블에 `*_by` 컬럼을 새로 만들지 않는다.

**전이는 `status_changed` 한 축으로만 기록한다.** 승인·반려·철회·완료는 `claim.{type}.approved` 같은 구체 훅도 함께 발화하는데, 양쪽을 구독하면 한 번의 전이가 두 줄로 남는다. 목표 상태는 `properties.new_status` 로 싣는다.

##### 신청 접수 (4훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.claim.exchange.requested` | `handleExchangeRequested` | `claim_exchange.request` | User | OrderExchange |
| `sirsoft-ecommerce.claim.return.requested` | `handleReturnRequested` | `claim_return.request` | User | OrderReturn |
| `sirsoft-ecommerce.claim.refund.requested` | `handleRefundRequested` | `claim_refund.request` | User | OrderRefund |
| `sirsoft-ecommerce.claim.cancel.requested` | `handleCancelRequested` | `claim_cancel.request` | User | OrderCancel |

**상태 전이 (4훅 — 전 상태 전수)**

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.claim.exchange.status_changed` | `handleStatusChanged` | `claim_exchange.status_change` | Admin | OrderExchange |
| `sirsoft-ecommerce.claim.return.status_changed` | `handleStatusChanged` | `claim_return.status_change` | Admin | OrderReturn |
| `sirsoft-ecommerce.claim.refund.status_changed` | `handleStatusChanged` | `claim_refund.status_change` | Admin | OrderRefund |
| `sirsoft-ecommerce.claim.cancel.status_changed` | `handleStatusChanged` | `claim_cancel.status_change` | Admin | OrderCancel |

**상품 단위 전이 (4훅 — D25)**

헤더 전이와 **층이 다른 사실**이라 따로 남긴다. `syncHeaderFromOptions` 는 살아 있는 상품이 한 상태로 모일 때만 헤더를 옮기므로, 상품 상태가 갈리는 조합에서는 헤더 줄이 아예 생기지 않는다 — 이 훅이 없으면 운영자가 한 상태 변경의 흔적이 전혀 남지 않는다. 헤더가 따라 움직인 회차는 두 줄이 함께 남으며 그것은 이중 기록이 아니다.

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.claim.exchange.option_status_changed` | `handleOptionStatusChanged` | `claim_exchange.option_status_change` | Admin | OrderExchange |
| `sirsoft-ecommerce.claim.return.option_status_changed` | `handleOptionStatusChanged` | `claim_return.option_status_change` | Admin | OrderReturn |
| `sirsoft-ecommerce.claim.refund.option_status_changed` | `handleOptionStatusChanged` | `claim_refund.option_status_change` | Admin | OrderRefund |
| `sirsoft-ecommerce.claim.cancel.option_status_changed` | `handleOptionStatusChanged` | `claim_cancel.option_status_change` | Admin | OrderCancel |

##### 금액 수정 / 환불 실행 (4훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.claim.exchange.amounts_updated` | `handleAmountsUpdated` | `claim_exchange.update_amounts` | Admin | OrderExchange |
| `sirsoft-ecommerce.claim.return.amounts_updated` | `handleAmountsUpdated` | `claim_return.update_amounts` | Admin | OrderReturn |
| `sirsoft-ecommerce.claim.refund.amounts_updated` | `handleAmountsUpdated` | `claim_refund.update_amounts` | Admin | OrderRefund |
| `sirsoft-ecommerce.claim.refund.completed` | `handleRefundExecuted` | `claim_refund.execute` | Admin | OrderRefund |

##### 클레임 추가결제 (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.claim.exchange.payment_completed` | `handlePaymentCompleted` | `claim_payment.complete` | User | ClaimPayment |
| `sirsoft-ecommerce.claim.return.payment_completed` | `handlePaymentCompleted` | `claim_payment.complete` | User | ClaimPayment |
| `sirsoft-ecommerce.claim.exchange.payment_refunded` | `handlePaymentRefunded` | `claim_payment.refund` | Admin | ClaimPayment |
| `sirsoft-ecommerce.claim.return.payment_refunded` | `handlePaymentRefunded` | `claim_payment.refund` | Admin | ClaimPayment |
| `sirsoft-ecommerce.claim.payment_cancelled` | `handlePaymentCancelled` | `claim_payment.cancel` | Admin | ClaimPayment (유형 세그먼트 없음 — 신청서가 사라진 고아 건도 취소 대상이라 유형을 항상 알 수 없다) |

##### 첨부 (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.claim-image.after_upload` | `handleImageUploaded` | `claim_image.upload` | User | ClaimImage |
| `sirsoft-ecommerce.claim-image.after_delete` | `handleImageDeleted` | `claim_image.delete` | User | ClaimImage (`loggable_type`/`loggable_id` 직접 지정 — 행이 이미 삭제됨) |
