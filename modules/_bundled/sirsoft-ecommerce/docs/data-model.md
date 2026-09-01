# 이커머스 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `Brand` | `ecommerce_brands` | 7 | creator→User, updater→User, products→Product | SoftDeletes, 검색 색인 |
| `Cart` | `ecommerce_carts` | 6 | user→User, product→Product, productOption→ProductOption | - |
| `Category` | `ecommerce_categories` | 10 | parent→self, children→self, descendants→self, images→CategoryImage, products→Product | 검색 색인 |
| `CategoryImage` | `ecommerce_category_images` | 15 | category→Category, creator→User | SoftDeletes |
| `ClaimReason` | `ecommerce_claim_reasons` | 10 | creator→User, updater→User | HasUserOverrides |
| `Coupon` | `ecommerce_promotion_coupons` | 22 | issues→CouponIssue, products→Product, includedProducts→Product, excludedProducts→Product, categories→Category, includedCategories→Category, 외 2개 | SoftDeletes, 검색 색인 |
| `CouponIssue` | `ecommerce_promotion_coupon_issues` | 9 | coupon→Coupon, user→User, order→Order | - |
| `EcommerceStat` | `ecommerce_stats` | 4 | - | - |
| `EcommerceUserProfile` | `ecommerce_user_profiles` | 3 | user→User | - |
| `ExtraFeeTemplate` | `ecommerce_shipping_policy_extra_fee_templates` | 7 | creator→User, updater→User | - |
| `HasDirectAssetUrl` | (규약) | - | - | - |
| `MileageBalance` | `ecommerce_mileage_balances` | 9 | user→User | - |
| `MileageTransaction` | `ecommerce_mileage_transactions` | 16 | user→User, order→Order, orderOption→OrderOption, grantedByUser→User, sourceTransaction→self | - |
| `Order` | `ecommerce_orders` | 65 | user→User, options→OrderOption, firstOption→OrderOption, addresses→OrderAddress, shippingAddress→OrderAddress, billingAddress→OrderAddress, 외 8개 | SoftDeletes |
| `OrderAddress` | `ecommerce_order_addresses` | 23 | order→Order | - |
| `OrderCancel` | `ecommerce_order_cancels` | 10 | order→Order, cancelOptions→OrderCancelOption, refund→OrderRefund, cancelledByUser→User | - |
| `OrderCancelOption` | `ecommerce_order_cancel_options` | 10 | orderCancel→OrderCancel, order→Order, orderOption→OrderOption, processedByUser→User | - |
| `OrderCashReceipt` | `ecommerce_order_cash_receipts` | 16 | order→Order, payment→OrderPayment | - |
| `OrderOption` | `ecommerce_order_options` | 57 | order→Order, parentOption→self, childOptions→self, splitOptions→self, product→Product, productOption→ProductOption, 외 4개 | - |
| `OrderPayment` | `ecommerce_order_payments` | 58 | order→Order, taxInvoices→OrderTaxInvoice, cashReceipts→OrderCashReceipt | - |
| `OrderRefund` | `ecommerce_order_refunds` | 25 | order→Order, orderCancel→OrderCancel, refundOptions→OrderRefundOption, processedByUser→User | - |
| `OrderRefundOption` | `ecommerce_order_refund_options` | 12 | orderRefund→OrderRefund, order→Order, orderOption→OrderOption, processedByUser→User | - |
| `OrderShipping` | `ecommerce_order_shippings` | 32 | order→Order, orderOption→OrderOption, shippingPolicy→ShippingPolicy, carrier→ShippingCarrier | - |
| `OrderTaxInvoice` | `ecommerce_order_tax_invoices` | 21 | order→Order, payment→OrderPayment | - |
| `Product` | `ecommerce_products` | 34 | options→ProductOption, images→ProductImage, categories→Category, brand→Brand, commonInfo→ProductCommonInfo, shippingPolicy→ShippingPolicy, 외 6개 | SoftDeletes, 검색 색인 |
| `ProductAdditionalOption` | `ecommerce_product_additional_options` | 4 | product→Product, values→ProductAdditionalOptionValue | - |
| `ProductAdditionalOptionValue` | `ecommerce_product_additional_option_values` | 8 | additionalOption→ProductAdditionalOption | - |
| `ProductCommonInfo` | `ecommerce_product_common_infos` | 6 | products→Product | 검색 색인 |
| `ProductImage` | `ecommerce_product_images` | 16 | product→Product, creator→User | SoftDeletes |
| `ProductInquiry` | `ecommerce_product_inquiries` | 7 | product→Product, user→User, inquirable→? | SoftDeletes |
| `ProductLabel` | `ecommerce_product_labels` | 4 | assignments→ProductLabelAssignment | - |
| `ProductLabelAssignment` | `ecommerce_product_label_assignments` | 4 | product→Product, label→ProductLabel | - |
| `ProductNotice` | `ecommerce_product_notices` | 2 | product→Product | - |
| `ProductNoticeTemplate` | `ecommerce_product_notice_templates` | 5 | - | - |
| `ProductOption` | `ecommerce_product_options` | 18 | product→Product | - |
| `ProductReview` | `ecommerce_product_reviews` | 13 | product→Product, orderOption→OrderOption, user→User, replyAdmin→User, images→ProductReviewImage | SoftDeletes |
| `ProductReviewImage` | `ecommerce_product_review_images` | 15 | review→ProductReview, creator→User | SoftDeletes |
| `ProductWishlist` | `ecommerce_product_wishlists` | 2 | user→User, product→Product | - |
| `SearchPreset` | `ecommerce_search_presets` | 6 | user→User | - |
| `Sequence` | `ecommerce_sequences` | 12 | - | - |
| `SequenceCode` | `ecommerce_sequence_codes` | 2 | - | - |
| `ShippingCarrier` | `ecommerce_shipping_carriers` | 9 | creator→User, updater→User | HasUserOverrides |
| `ShippingPolicy` | `ecommerce_shipping_policies` | 6 | countrySettings→ShippingPolicyCountrySetting, listCountrySettings→ShippingPolicyCountrySetting | - |
| `ShippingPolicyCountrySetting` | `ecommerce_shipping_policy_country_settings` | 17 | shippingPolicy→ShippingPolicy | - |
| `ShippingType` | `ecommerce_shipping_types` | 8 | creator→User, updater→User | HasUserOverrides |
| `TempOrder` | `ecommerce_temp_orders` | 6 | user→User | - |
| `UserAddress` | `ecommerce_user_addresses` | 16 | user→User | - |
<!-- @generated:models END -->

<!-- @intent START -->
47개 모델은 다섯 계열로 읽습니다.

| 계열 | 모델 | 읽는 요령 |
|---|---|---|
| 카탈로그 | `Product` · `ProductOption` · `ProductAdditionalOption(Value)` · `ProductImage` · `Category` · `CategoryImage` · `Brand` · `ProductLabel(Assignment)` · `ProductCommonInfo` · `ProductNotice(Template)` | 판매 단위는 `Product` 가 아니라 **`ProductOption`** 입니다. 재고·가격·주문 연결이 전부 옵션에 걸립니다 |
| 주문 | `Order` · `OrderOption` · `OrderAddress` · `OrderPayment` · `OrderShipping` · `OrderCashReceipt` · `OrderTaxInvoice` | `Order` fillable 65 · `OrderOption` 57 · `OrderPayment` 58 — 이 셋이 큰 이유는 **주문 시점의 값을 전부 스냅샷으로 복사**하기 때문입니다. 상품명·가격·배송정책·통화 정보가 원본을 참조하지 않고 복제됩니다 |
| 취소·환불 | `OrderCancel(Option)` · `OrderRefund(Option)` · `ClaimReason` | 단위가 주문이 아니라 **옵션**입니다. `*Option` 쪽이 실제 처리 단위이고 상위는 묶음입니다 |
| 프로모션·적립 | `Coupon` · `CouponIssue` · `MileageTransaction` · `MileageBalance` | `MileageTransaction` 이 원장(SSoT), `MileageBalance` 는 단방향 파생 캐시입니다 |
| 배송·회원·기타 | `ShippingPolicy(CountrySetting)` · `ShippingCarrier` · `ShippingType` · `ExtraFeeTemplate` · `UserAddress` · `Cart` · `TempOrder` · `ProductWishlist` · `ProductReview(Image)` · `ProductInquiry` · `SearchPreset` · `Sequence(Code)` · `EcommerceStat` · `EcommerceUserProfile` | `TempOrder` 는 결제창 왕복 동안만 사는 임시 저장소이며 스케줄이 정리합니다 |

`HasDirectAssetUrl` 은 모델이 아니라 **규약(trait)** 입니다 — 이미지 계열 모델이 저장소 종류에
관계없이 같은 방식으로 자산 URL 을 내도록 묶습니다. 표에 모델처럼 잡힌 것은 수집기가 클래스
파일 단위로 세기 때문이며, 테이블이 `(규약)` 인 것이 그 표식입니다.

**`HasUserOverrides` 를 쓰는 셋**(`ClaimReason` · `ShippingCarrier` · `ShippingType`)은 시더가
기본값을 넣지만 운영자가 고칠 수 있는 테이블입니다. 시더를 다시 돌려도 운영자 수정분은
보존되므로, 이 셋의 기본 데이터를 바꿀 때는 시더만 고쳐서는 기설치본에 반영되지 않습니다.

**검색 색인 대상**은 `Product` · `Category` · `Brand` · `Coupon` · `ProductCommonInfo` 다섯이며,
색인 갱신은 서비스가 아니라 `SearchProductsListener` 가 훅으로 받아 처리합니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `ecommerce_brands` | `Brand` |
| `ecommerce_carts` | `Cart` |
| `ecommerce_categories` | `Category` |
| `ecommerce_category_images` | `CategoryImage` |
| `ecommerce_claim_reasons` | `ClaimReason` |
| `ecommerce_mail_templates` | - |
| `ecommerce_mileage_balances` | `MileageBalance` |
| `ecommerce_mileage_transactions` | `MileageTransaction` |
| `ecommerce_order_addresses` | `OrderAddress` |
| `ecommerce_order_cancel_options` | `OrderCancelOption` |
| `ecommerce_order_cancels` | `OrderCancel` |
| `ecommerce_order_cash_receipts` | `OrderCashReceipt` |
| `ecommerce_order_options` | `OrderOption` |
| `ecommerce_order_payments` | `OrderPayment` |
| `ecommerce_order_refund_options` | `OrderRefundOption` |
| `ecommerce_order_refunds` | `OrderRefund` |
| `ecommerce_order_shippings` | `OrderShipping` |
| `ecommerce_order_tax_invoices` | `OrderTaxInvoice` |
| `ecommerce_orders` | `Order` |
| `ecommerce_product_additional_option_values` | `ProductAdditionalOptionValue` |
| `ecommerce_product_additional_options` | `ProductAdditionalOption` |
| `ecommerce_product_categories` | - |
| `ecommerce_product_common_infos` | `ProductCommonInfo` |
| `ecommerce_product_images` | `ProductImage` |
| `ecommerce_product_inquiries` | `ProductInquiry` |
| `ecommerce_product_label_assignments` | `ProductLabelAssignment` |
| `ecommerce_product_labels` | `ProductLabel` |
| `ecommerce_product_logs` | - |
| `ecommerce_product_notice_templates` | `ProductNoticeTemplate` |
| `ecommerce_product_notices` | `ProductNotice` |
| `ecommerce_product_options` | `ProductOption` |
| `ecommerce_product_review_images` | `ProductReviewImage` |
| `ecommerce_product_reviews` | `ProductReview` |
| `ecommerce_product_wishlists` | `ProductWishlist` |
| `ecommerce_products` | `Product` |
| `ecommerce_promotion_coupon_categories` | - |
| `ecommerce_promotion_coupon_issues` | `CouponIssue` |
| `ecommerce_promotion_coupon_products` | - |
| `ecommerce_promotion_coupons` | `Coupon` |
| `ecommerce_search_presets` | `SearchPreset` |
| `ecommerce_sequence_codes` | `SequenceCode` |
| `ecommerce_sequences` | `Sequence` |
| `ecommerce_shipping_carriers` | `ShippingCarrier` |
| `ecommerce_shipping_policies` | `ShippingPolicy` |
| `ecommerce_shipping_policy_country_settings` | `ShippingPolicyCountrySetting` |
| `ecommerce_shipping_policy_extra_fee_templates` | `ExtraFeeTemplate` |
| `ecommerce_shipping_types` | `ShippingType` |
| `ecommerce_stats` | `EcommerceStat` |
| `ecommerce_temp_orders` | `TempOrder` |
| `ecommerce_user_addresses` | `UserAddress` |
| `ecommerce_user_profiles` | `EcommerceUserProfile` |
<!-- @generated:tables END -->

<!-- @intent START -->
51개 테이블은 전부 `ecommerce_` 접두사를 갖습니다. 모델 열이 `-` 인 다섯은 각각 이유가 있습니다:

| 테이블 | 왜 모델이 없는가 |
|---|---|
| `ecommerce_product_categories` | 상품↔카테고리 다대다 피벗 (관계로만 접근) |
| `ecommerce_promotion_coupon_products` · `ecommerce_promotion_coupon_categories` | 쿠폰의 적용/제외 대상 피벗 — 한 쿠폰이 포함·제외 두 방향을 함께 갖습니다 |
| `ecommerce_product_logs` | 상품 변경 이력 적재 전용 (읽기는 집계 쿼리로) |
| `ecommerce_mail_templates` | 알림 본문 템플릿. 알림 정의 10종이 여기서 본문을 찾습니다 |

테이블을 추가·변경할 때 **DB CASCADE 에 삭제를 맡기지 않습니다.** 주문·상품 삭제는 훅 발행·
파일 정리·활동 로그가 함께 일어나야 하므로 Service 가 명시적으로 지웁니다 — CASCADE 로 지우면
그 부가 처리가 통째로 건너뛰어지고 아무 오류도 남지 않습니다.

주문 계열(`ecommerce_orders` · `ecommerce_order_*`)과 상품·리뷰·문의 계열은 SoftDeletes 를
씁니다. 목록 쿼리를 새로 만들 때 `withTrashed()` 를 습관적으로 붙이면 취소·삭제된 행이 매출
집계에 섞이므로 주의합니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 102개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_04_01_000001_create_ecommerce_categories_table.php` | `ecommerce_categories` | - | ✅ |
| `2026_04_01_000002_create_ecommerce_category_images_table.php` | `ecommerce_category_images` | - | ✅ |
| `2026_04_01_000003_create_ecommerce_brands_table.php` | `ecommerce_brands` | - | ✅ |
| `2026_04_01_000004_create_ecommerce_search_presets_table.php` | `ecommerce_search_presets` | - | ✅ |
| `2026_04_01_000005_create_ecommerce_products_table.php` | `ecommerce_products` | `ecommerce_products` | ✅ |
| `2026_04_01_000006_create_ecommerce_product_images_table.php` | `ecommerce_product_images` | - | ✅ |
| `2026_04_01_000007_create_ecommerce_product_options_table.php` | `ecommerce_product_options` | - | ✅ |
| `2026_04_01_000008_create_ecommerce_product_additional_options_table.php` | `ecommerce_product_additional_options` | - | ✅ |
| `2026_04_01_000009_create_ecommerce_product_labels_table.php` | `ecommerce_product_labels` | - | ✅ |
| `2026_04_01_000010_create_ecommerce_product_label_assignments_table.php` | `ecommerce_product_label_assignments` | - | ✅ |
| `2026_04_01_000011_create_ecommerce_product_logs_table.php` | `ecommerce_product_logs` | - | ✅ |
| `2026_04_01_000012_create_ecommerce_product_notice_templates_table.php` | `ecommerce_product_notice_templates` | - | ✅ |
| `2026_04_01_000013_create_ecommerce_product_notices_table.php` | `ecommerce_product_notices` | - | ✅ |
| `2026_04_01_000014_create_ecommerce_product_common_infos_table.php` | `ecommerce_product_common_infos` | - | ✅ |
| `2026_04_01_000015_create_ecommerce_product_categories_table.php` | `ecommerce_product_categories` | - | ✅ |
| `2026_04_01_000016_create_ecommerce_product_wishlists_table.php` | `ecommerce_product_wishlists` | - | ✅ |
| `2026_04_01_000017_create_ecommerce_orders_table.php` | `ecommerce_orders` | - | ✅ |
| `2026_04_01_000018_create_ecommerce_order_options_table.php` | `ecommerce_order_options` | - | ✅ |
| `2026_04_01_000019_create_ecommerce_order_addresses_table.php` | `ecommerce_order_addresses` | - | ✅ |
| `2026_04_01_000020_create_ecommerce_order_payments_table.php` | `ecommerce_order_payments` | - | ✅ |
| `2026_04_01_000021_create_ecommerce_order_shippings_table.php` | `ecommerce_order_shippings` | - | ✅ |
| `2026_04_01_000022_create_ecommerce_order_tax_invoices_table.php` | `ecommerce_order_tax_invoices` | - | ✅ |
| `2026_04_01_000023_create_ecommerce_carts_table.php` | `ecommerce_carts` | - | ✅ |
| `2026_04_01_000024_create_ecommerce_temp_orders_table.php` | `ecommerce_temp_orders` | - | ✅ |
| `2026_04_01_000025_create_ecommerce_shipping_policies_table.php` | `ecommerce_shipping_policies` | - | ✅ |
| `2026_04_01_000026_create_ecommerce_shipping_policy_extra_fee_templates_table.php` | `ecommerce_shipping_policy_extra_fee_templates` | - | ✅ |
| `2026_04_01_000027_create_ecommerce_shipping_policy_country_settings_table.php` | `ecommerce_shipping_policy_country_settings` | - | ✅ |
| `2026_04_01_000028_create_ecommerce_shipping_carriers_table.php` | `ecommerce_shipping_carriers` | - | ✅ |
| `2026_04_01_000029_create_ecommerce_promotion_coupons_table.php` | `ecommerce_promotion_coupons` | - | ✅ |
| `2026_04_01_000030_add_vat_amount_to_ecommerce_orders_table.php` | - | `ecommerce_orders` | ✅ |
| `2026_04_01_000031_create_ecommerce_promotion_coupon_issues_table.php` | `ecommerce_promotion_coupon_issues` | - | ✅ |
| `2026_04_01_000032_create_ecommerce_promotion_coupon_products_table.php` | `ecommerce_promotion_coupon_products` | - | ✅ |
| `2026_04_01_000033_create_ecommerce_promotion_coupon_categories_table.php` | `ecommerce_promotion_coupon_categories` | - | ✅ |
| `2026_04_01_000034_create_ecommerce_sequences_table.php` | `ecommerce_sequences` | - | ✅ |
| `2026_04_01_000035_create_ecommerce_sequence_codes_table.php` | `ecommerce_sequence_codes` | - | ✅ |
| `2026_04_01_000036_create_ecommerce_user_addresses_table.php` | `ecommerce_user_addresses` | - | ✅ |
| `2026_04_01_000037_create_ecommerce_mail_templates_table.php` | `ecommerce_mail_templates` | - | ✅ |
| `2026_04_01_000038_change_ecommerce_user_addresses_name_to_string.php` | - | `ecommerce_user_addresses` | ✅ |
| `2026_04_01_000039_create_ecommerce_product_reviews_table.php` | `ecommerce_product_reviews` | `ecommerce_product_reviews` | ✅ |
| `2026_04_01_000040_create_ecommerce_product_review_images_table.php` | `ecommerce_product_review_images` | `ecommerce_product_review_images` | ✅ |
| `2026_04_01_000041_create_ecommerce_order_cancels_table.php` | `ecommerce_order_cancels` | - | ✅ |
| `2026_04_01_000042_create_ecommerce_order_cancel_options_table.php` | `ecommerce_order_cancel_options` | - | ✅ |
| `2026_04_01_000043_create_ecommerce_order_refunds_table.php` | `ecommerce_order_refunds` | - | ✅ |
| `2026_04_01_000044_create_ecommerce_order_refund_options_table.php` | `ecommerce_order_refund_options` | - | ✅ |
| `2026_04_01_000045_add_cancellation_columns_to_ecommerce_orders_and_options.php` | - | `ecommerce_orders`, `ecommerce_order_options` | ✅ |
| `2026_04_01_000046_add_mc_refund_columns_to_ecommerce_order_refunds_table.php` | - | `ecommerce_order_refunds` | ✅ |
| `2026_04_01_000047_modify_i18n_columns_in_ecommerce_order_options_table.php` | - | `ecommerce_order_options` | ✅ |
| `2026_04_01_000048_create_ecommerce_claim_reasons_table.php` | `ecommerce_claim_reasons` | - | ✅ |
| `2026_04_01_000049_add_confirmed_at_to_ecommerce_order_options_table.php` | - | `ecommerce_order_options` | ✅ |
| `2026_04_01_000050_drop_ecommerce_product_logs_table.php` | `ecommerce_product_logs` | - | ✅ |
| `2026_04_01_000051_remove_url_from_ecommerce_product_images_and_review_images_table.php` | - | `ecommerce_product_images`, `ecommerce_product_review_images` | ✅ |
| `2026_04_01_000052_create_ecommerce_product_inquiries_table.php` | `ecommerce_product_inquiries` | `ecommerce_product_inquiries` | ✅ |
| `2026_04_01_000053_add_indexes_to_ecommerce_products_table.php` | - | `ecommerce_products` | ✅ |
| `2026_04_01_000054_add_indexes_to_ecommerce_orders_table.php` | - | `ecommerce_orders` | ✅ |
| `2026_04_01_000055_add_indexes_to_ecommerce_order_addresses_table.php` | - | `ecommerce_order_addresses` | ✅ |
| `2026_04_01_000056_drop_carrier_name_from_ecommerce_order_shippings_table.php` | - | `ecommerce_order_shippings` | ✅ |
| `2026_04_01_000057_add_fulltext_indexes_to_ecommerce_products_table.php` | - | `ecommerce_products` | ✅ |
| `2026_04_01_000058_add_fulltext_indexes_to_ecommerce_categories_table.php` | - | `ecommerce_categories` | ✅ |
| `2026_04_01_000059_add_fulltext_indexes_to_ecommerce_brands_table.php` | - | `ecommerce_brands` | ✅ |
| `2026_04_01_000060_add_fulltext_indexes_to_ecommerce_promotion_coupons_table.php` | - | `ecommerce_promotion_coupons` | ✅ |
| `2026_04_01_000061_add_fulltext_indexes_to_ecommerce_product_common_infos_table.php` | - | `ecommerce_product_common_infos` | ✅ |
| `2026_04_01_000062_create_ecommerce_shipping_types_table.php` | `ecommerce_shipping_types` | - | ✅ |
| `2026_04_01_000063_add_custom_shipping_name_to_country_settings.php` | - | `ecommerce_shipping_policy_country_settings` | ✅ |
| `2026_04_13_000001_drop_ecommerce_mail_templates_table.php` | `ecommerce_mail_templates` | - | ✅ |
| `2026_04_13_000002_add_user_overrides_to_ecommerce_claim_reasons_table.php` | - | `ecommerce_claim_reasons` | ✅ |
| `2026_04_19_000000_add_user_overrides_to_ecommerce_shipping_types_table.php` | - | `ecommerce_shipping_types` | ✅ |
| `2026_04_20_000001_add_user_overrides_to_ecommerce_shipping_carriers_table.php` | - | `ecommerce_shipping_carriers` | ✅ |
| `2026_05_16_000001_add_unique_index_to_transaction_id_on_ecommerce_order_payments_table.php` | - | - | ✅ |
| `2026_06_02_000001_add_guest_lookup_password_hash_to_ecommerce_orders_table.php` | - | `ecommerce_orders` | ✅ |
| `2026_06_11_000001_create_ecommerce_mileage_transactions_table.php` | `ecommerce_mileage_transactions` | - | ✅ |
| `2026_06_11_000002_add_delivered_at_to_ecommerce_order_options_table.php` | - | `ecommerce_order_options` | ✅ |
| `2026_06_11_000003_add_mc_subtotal_earned_points_amount_to_ecommerce_order_options_table.php` | - | `ecommerce_order_options` | ✅ |
| `2026_06_11_000004_create_ecommerce_mileage_balances_table.php` | `ecommerce_mileage_balances` | - | ✅ |
| `2026_06_16_000001_add_is_mileage_deducted_to_ecommerce_orders_table.php` | - | `ecommerce_orders` | ✅ |
| `2026_06_16_000001_create_ecommerce_stats_table.php` | `ecommerce_stats` | - | ✅ |
| `2026_06_22_000001_add_api_config_to_ecommerce_shipping_policy_country_settings_table.php` | - | `ecommerce_shipping_policy_country_settings` | ✅ |
| `2026_06_22_000001_add_cancelled_at_to_ecommerce_orders_table.php` | - | `ecommerce_orders` | ✅ |
| `2026_06_23_000001_add_seo_sync_flags_to_ecommerce_products_table.php` | - | `ecommerce_products` | ✅ |
| `2026_06_24_000001_convert_seo_meta_to_multilingual_json.php` | - | - | ✅ |
| `2026_06_24_000001_create_ecommerce_user_profiles_table.php` | `ecommerce_user_profiles` | - | ✅ |
| `2026_06_24_000010_create_ecommerce_product_additional_option_values_table.php` | `ecommerce_product_additional_option_values` | - | ✅ |
| `2026_06_24_000011_add_additional_option_selections_to_ecommerce_carts_table.php` | - | `ecommerce_carts` | ✅ |
| `2026_06_24_000012_add_additional_options_columns_to_ecommerce_order_options_table.php` | - | `ecommerce_order_options` | ✅ |
| `2026_06_25_000001_add_preferred_shipping_country_to_ecommerce_user_profiles_table.php` | - | `ecommerce_user_profiles` | ✅ |
| `2026_06_25_000001_change_ecommerce_product_prices_to_decimal.php` | - | `ecommerce_products`, `ecommerce_product_options` | ✅ |
| `2026_06_25_000002_add_shipping_snapshot_to_ecommerce_order_cancels_table.php` | - | `ecommerce_order_cancels` | ✅ |
| `2026_06_25_000013_add_allow_custom_text_to_ecommerce_product_additional_option_values_table.php` | - | `ecommerce_product_additional_option_values` | ✅ |
| `2026_06_26_000001_add_delivery_memo_label_to_ecommerce_order_addresses_table.php` | - | `ecommerce_order_addresses` | ✅ |
| `2026_06_26_000002_add_orderer_locale_to_ecommerce_order_addresses_table.php` | - | `ecommerce_order_addresses` | ✅ |
| `2026_07_09_000001_create_ecommerce_order_cash_receipts_table.php` | `ecommerce_order_cash_receipts` | - | ✅ |
| `2026_07_09_000002_add_cash_equivalent_amount_to_ecommerce_orders_table.php` | - | `ecommerce_orders`, `ecommerce_order_options` | ✅ |
| `2026_07_09_000003_add_cash_receipt_identifier_encrypted_to_ecommerce_order_payments_table.php` | - | `ecommerce_order_payments` | ✅ |
| `2026_07_09_000004_add_cash_receipt_identifier_type_to_ecommerce_order_payments_table.php` | - | `ecommerce_order_payments` | ✅ |
| `2026_07_27_000001_add_mileage_policy_snapshot_to_ecommerce_orders_table.php` | - | `ecommerce_orders` | ✅ |
| `2026_07_30_000001_add_created_at_indexes_to_ecommerce_product_reviews_table.php` | - | `ecommerce_product_reviews` | ✅ |
| `2026_07_31_000001_add_shipped_at_index_to_ecommerce_order_shippings_table.php` | - | `ecommerce_order_shippings` | ✅ |
| `2026_08_01_000001_add_list_sort_indexes_to_ecommerce_tables.php` | - | - | ✅ |
| `2026_08_02_000001_add_storefront_indexes_to_ecommerce_tables.php` | - | - | ✅ |
| `2026_08_11_000001_fix_weight_volume_unit_comments_in_ecommerce_order_tables.php` | - | `ecommerce_orders`, `ecommerce_order_options` | ✅ |
| `2026_08_17_000001_add_soft_deletes_to_ecommerce_product_inquiries_table.php` | - | `ecommerce_product_inquiries` | ✅ |
| `2026_08_21_000001_add_unique_purchase_earn_lot_to_ecommerce_mileage_transactions_table.php` | - | - | ✅ |
| `2026_08_22_000001_add_content_thumbnail_url_to_ecommerce_products_table.php` | - | `ecommerce_products` | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
102개는 초기 스키마 한 벌이 아니라 **누적된 변경 이력**입니다. 새 컬럼을 추가할 때 초기
`create_*` 파일을 고치는 것이 아니라 새 `add_*`/`change_*` 파일을 더합니다 — 이미 설치된
사이트는 초기 마이그레이션을 다시 실행하지 않기 때문입니다.

같은 이유로 **소스만 고쳐서는 기설치본이 낫지 않습니다.** 컬럼 기본값·comment·데이터 형태를
바로잡는 변경은 마이그레이션과 함께 `upgrades/` 의 업그레이드 스텝에 백필을 써야 이미 운영
중인 사이트에 반영됩니다.

작성 규칙 셋(코어 공통이지만 이 모듈에서 특히 자주 걸립니다):

- 모든 컬럼에 한국어 `comment` 와 `down()` 구현
- FK 컬럼의 `->comment()` 는 `->constrained()` **앞**에 둡니다 (뒤에 두면 comment 가 컬럼이
  아니라 FK 정의에 붙어 조용히 사라집니다)
- 데이터를 순회하며 그 행을 갱신·삭제하는 백필은 `chunkById()` — `chunk()` 계열은 OFFSET
  기반이라 처리된 행이 필터에서 이탈한 만큼 커서가 밀려 미처리 행을 조용히 건너뜁니다
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| Enum | backing | case 수 | case |
|---|---|---|---|
| `AdjustmentType` | `string` | 1 | `cancel` |
| `CancelOptionStatusEnum` | `string` | 2 | `requested`, `completed` |
| `CancelStatusEnum` | `string` | 2 | `requested`, `completed` |
| `CancelTypeEnum` | `string` | 2 | `full`, `partial` |
| `CashReceiptIdentifierType` | `string` | 3 | `phone`, `card`, `business` |
| `CashReceiptIssueStatus` | `string` | 3 | `IN_PROGRESS`, `COMPLETED`, `FAILED` |
| `CashReceiptTransactionType` | `string` | 2 | `issue`, `cancel` |
| `CashReceiptType` | `string` | 2 | `income`, `expense` |
| `ChargePolicyEnum` | `string` | 14 | `free`, `fixed`, `conditional_free`, `range_amount`, `range_quantity`, `range_weight`, `range_volume`, `range_volume_weight`, `외 6개` |
| `ClaimReasonFaultTypeEnum` | `string` | 3 | `customer`, `seller`, `carrier` |
| `ClaimReasonTypeEnum` | `string` | 1 | `refund` |
| `CouponDiscountType` | `string` | 2 | `fixed`, `rate` |
| `CouponIssueCondition` | `string` | 4 | `manual`, `signup`, `first_purchase`, `birthday` |
| `CouponIssueMethod` | `string` | 3 | `direct`, `download`, `auto` |
| `CouponIssueRecordStatus` | `string` | 4 | `available`, `used`, `expired`, `cancelled` |
| `CouponIssueStatus` | `string` | 2 | `issuing`, `stopped` |
| `CouponTargetScope` | `string` | 3 | `all`, `products`, `categories` |
| `CouponTargetType` | `string` | 3 | `product_amount`, `order_amount`, `shipping_fee` |
| `DeliveryMemoPresetEnum` | `string` | 4 | `door`, `security`, `parcel_box`, `call` |
| `DeviceTypeEnum` | `string` | 6 | `pc`, `mobile`, `app_ios`, `app_android`, `admin`, `api` |
| `MileageEarnTriggerEnum` | `string` | 2 | `delivered`, `confirmed` |
| `MileageTransactionTypeEnum` | `string` | 8 | `purchase_earn`, `admin_earn`, `order_use`, `admin_deduct`, `expired`, `refund_restore`, `order_cancel_restore`, `earn_cancel` |
| `OrderDateTypeEnum` | `string` | 5 | `ordered_at`, `paid_at`, `confirmed_at`, `delivered_at`, `cancelled_at` |
| `OrderOptionSourceTypeEnum` | `string` | 3 | `order`, `exchange`, `split` |
| `OrderStatusEnum` | `string` | 10 | `pending_order`, `pending_payment`, `payment_complete`, `shipping_hold`, `preparing`, `shipping_ready`, `shipping`, `delivered`, `외 2개` |
| `PaymentMethodEnum` | `string` | 8 | `card`, `vbank`, `dbank`, `bank`, `phone`, `point`, `deposit`, `free` |
| `PaymentStatusEnum` | `string` | 8 | `ready`, `in_progress`, `waiting_deposit`, `paid`, `partial_cancelled`, `cancelled`, `failed`, `expired` |
| `ProductDateType` | `string` | 2 | `created_at`, `updated_at` |
| `ProductDisplayStatus` | `string` | 2 | `visible`, `hidden` |
| `ProductImageCollection` | `string` | 3 | `main`, `detail`, `additional` |
| `ProductPriceType` | `string` | 3 | `selling_price`, `supply_price`, `list_price` |
| `ProductSalesStatus` | `string` | 4 | `on_sale`, `suspended`, `sold_out`, `coming_soon` |
| `ProductTaxStatus` | `string` | 2 | `taxable`, `tax_free` |
| `RefundMethodEnum` | `string` | 3 | `pg`, `bank`, `points` |
| `RefundOptionStatusEnum` | `string` | 6 | `requested`, `approved`, `processing`, `on_hold`, `completed`, `rejected` |
| `RefundPriorityEnum` | `string` | 2 | `pg_first`, `points_first` |
| `RefundStatusEnum` | `string` | 6 | `requested`, `approved`, `processing`, `on_hold`, `completed`, `rejected` |
| `ReviewStatus` | `string` | 2 | `visible`, `hidden` |
| `SearchPresetTargetScreen` | `string` | 3 | `products`, `orders`, `customers` |
| `SequenceAlgorithm` | `string` | 5 | `hybrid`, `sequential`, `daily`, `timestamp`, `nanoid` |
| `SequenceType` | `string` | 5 | `product`, `order`, `shipping`, `cancel`, `refund` |
| `ShippingApiAuthType` | `string` | 3 | `none`, `bearer`, `custom_header` |
| `ShippingApiHttpMethod` | `string` | 2 | `GET`, `POST` |
| `ShippingApiRequestField` | `string` | 5 | `policy_id`, `country_code`, `items`, `group_total`, `total_quantity` |
| `ShippingApiResponseType` | `string` | 2 | `json`, `text` |
| `ShippingCountryEnum` | `string` | 4 | `KR`, `US`, `CN`, `JP` |
| `ShippingFeeTaxPolicy` | `string` | 3 | `proportional`, `taxable`, `follow_main_item` |
| `ShippingStatusEnum` | `string` | 11 | `pending`, `preparing`, `ready`, `shipped`, `in_transit`, `out_for_delivery`, `delivered`, `failed`, `외 3개` |
| `TaxInvoiceStatusEnum` | `string` | 5 | `pending`, `processing`, `issued`, `failed`, `cancelled` |
<!-- @generated:enums END -->

<!-- @intent START -->
Enum 49종이 이 도메인의 **어휘 전체**입니다. 상태·분류를 문자열 리터럴로 비교하는 코드가 있으면
그 자리는 Enum 으로 바꿔야 하는 신호입니다 — 화면 필터 옵션·검증 게이트·실제 기록 값 셋이
같은 Enum 에서 파생되지 않으면, 빠진 값으로 기록된 행이 어떤 필터로도 도달할 수 없게 됩니다.

먼저 읽어야 하는 것들:

| Enum | 왜 중요한가 |
|---|---|
| `OrderStatusEnum` (10) | 주문 상태 전이의 SSoT. 어느 상태까지 취소를 허용할지는 설정(`cancellable_statuses`)이 이 케이스 이름으로 정합니다 |
| `PaymentStatusEnum` (8) · `PaymentMethodEnum` (8) | 결제 상태와 **코어 기본 결제수단**. 플러그인이 추가하는 결제수단(`kginicis_naverpay` 등)은 여기에 없고 카탈로그 선언으로만 존재합니다 — 이 Enum 을 결제수단의 전체 목록으로 오해하지 않습니다 |
| `ChargePolicyEnum` (14) | 배송비 산정 방식. 무료·정액·조건부무료·금액/수량/무게/부피 구간 등 국가별 요금 규칙이 전부 이 하나로 표현됩니다 |
| `MileageTransactionTypeEnum` (8) | 원장 기록의 종류. 적립·사용·소멸·환불복원·취소복원이 모두 별개 케이스라 원장만 보고 잔액을 재구성할 수 있습니다 |
| `RefundMethodEnum` (3) · `RefundPriorityEnum` (2) | 환불을 어디로 돌려줄지와 그 우선순위 |
| `SequenceType` (5) · `SequenceAlgorithm` (5) | 채번 대상과 방식. 주문번호·상품코드 형식을 바꾸는 자리입니다 |
| `ShippingStatusEnum` (11) · `ShippingCountryEnum` (4) | 배송 진행 상태와 기본 제공 국가 |

`ShippingCountryEnum` 이 4개뿐인 것은 **기본 제공 목록**이기 때문입니다. 취급 국가는 환경설정과
배송정책 국가별 설정이 정하므로, 나라를 늘리는 것은 이 Enum 을 고치는 일이 아닙니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `BrandRepository` | 구현 | 브랜드 Repository 구현체 |
| `BrandRepositoryInterface` | 인터페이스 | 브랜드 Repository 인터페이스 |
| `CartRepository` | 구현 | 장바구니 Repository 구현체 |
| `CartRepositoryInterface` | 인터페이스 | 장바구니 Repository 인터페이스 |
| `CategoryImageRepository` | 구현 | 카테고리 이미지 Repository 구현체 |
| `CategoryImageRepositoryInterface` | 인터페이스 | 카테고리 이미지 Repository 인터페이스 |
| `CategoryRepository` | 구현 | 카테고리 Repository 구현체 |
| `CategoryRepositoryInterface` | 인터페이스 | 카테고리 Repository 인터페이스 |
| `ClaimReasonRepository` | 구현 | 클레임 사유 Repository 구현체 |
| `ClaimReasonRepositoryInterface` | 인터페이스 | 클레임 사유 Repository 인터페이스 |
| `CouponIssueRepository` | 구현 | 쿠폰 발급 Repository 구현체 |
| `CouponIssueRepositoryInterface` | 인터페이스 | 쿠폰 발급 Repository 인터페이스 |
| `CouponRepository` | 구현 | 쿠폰 Repository 구현체 |
| `CouponRepositoryInterface` | 인터페이스 | 쿠폰 Repository 인터페이스 |
| `EcommerceStatRepository` | 구현 | 이커머스 일별 판매 집계 Repository |
| `EcommerceStatRepositoryInterface` | 인터페이스 | 이커머스 일별 판매 집계 Repository 계약 |
| `EcommerceUserProfileRepository` | 구현 | 이커머스 사용자 프로필 Repository 구현체 (A3) |
| `EcommerceUserProfileRepositoryInterface` | 인터페이스 | 이커머스 사용자 프로필 Repository 인터페이스 (A3) |
| `ExtraFeeTemplateRepository` | 구현 | 추가배송비 템플릿 Repository 구현체 |
| `ExtraFeeTemplateRepositoryInterface` | 인터페이스 | 추가배송비 템플릿 Repository 인터페이스 |
| `MileageBalanceRepository` | 구현 | 마일리지 잔액 캐시 Repository 구현체 (단방향 파생 — 원장/옵션 → 캐시) |
| `MileageBalanceRepositoryInterface` | 인터페이스 | 마일리지 잔액 캐시 Repository 인터페이스 (파생 캐시) |
| `MileageTransactionRepository` | 구현 | 마일리지 거래(원장) Repository 구현체 |
| `MileageTransactionRepositoryInterface` | 인터페이스 | 마일리지 거래(원장) Repository 인터페이스 |
| `OrderCancelOptionRepository` | 구현 | 주문 취소 옵션 리포지토리 구현체 |
| `OrderCancelOptionRepositoryInterface` | 인터페이스 | 주문 취소 옵션 리포지토리 인터페이스 |
| `OrderCancelRepository` | 구현 | 주문 취소 리포지토리 구현체 |
| `OrderCancelRepositoryInterface` | 인터페이스 | 주문 취소 리포지토리 인터페이스 |
| `OrderCashReceiptRepository` | 구현 | 주문 현금영수증 이력 Repository 구현체 |
| `OrderCashReceiptRepositoryInterface` | 인터페이스 | 주문 현금영수증 이력 Repository 인터페이스 |
| `OrderOptionRepository` | 구현 | 주문 옵션 리포지토리 |
| `OrderOptionRepositoryInterface` | 인터페이스 | 주문 옵션 리포지토리 인터페이스 |
| `OrderPaymentRepository` | 구현 | 주문 결제 Repository 구현체 |
| `OrderPaymentRepositoryInterface` | 인터페이스 | 주문 결제 Repository 인터페이스 |
| `OrderRefundOptionRepository` | 구현 | 주문 환불 옵션 리포지토리 구현체 |
| `OrderRefundOptionRepositoryInterface` | 인터페이스 | 주문 환불 옵션 리포지토리 인터페이스 |
| `OrderRefundRepository` | 구현 | 주문 환불 리포지토리 구현체 |
| `OrderRefundRepositoryInterface` | 인터페이스 | 주문 환불 리포지토리 인터페이스 |
| `OrderRepository` | 구현 | 주문 Repository 구현체 |
| `OrderRepositoryInterface` | 인터페이스 | 주문 Repository 인터페이스 |
| `OrderShippingRepository` | 구현 | 주문 배송 리포지토리 구현체 |
| `OrderShippingRepositoryInterface` | 인터페이스 | 주문 배송 리포지토리 인터페이스 |
| `ProductAdditionalOptionValueRepository` | 구현 | 상품 추가옵션 선택지 Repository 구현체 |
| `ProductAdditionalOptionValueRepositoryInterface` | 인터페이스 | 상품 추가옵션 선택지 Repository 인터페이스 |
| `ProductCommonInfoRepository` | 구현 | 공통정보 Repository 구현체 |
| `ProductCommonInfoRepositoryInterface` | 인터페이스 | 공통정보 Repository 인터페이스 |
| `ProductImageRepository` | 구현 | 상품 이미지 Repository 구현체 |
| `ProductImageRepositoryInterface` | 인터페이스 | 상품 이미지 Repository 인터페이스 |
| `ProductInquiryRepository` | 구현 | 상품 1:1 문의 Repository 구현체 |
| `ProductInquiryRepositoryInterface` | 인터페이스 | 상품 1:1 문의 Repository 인터페이스 |
| `ProductLabelRepository` | 구현 | 상품 라벨 Repository 구현체 |
| `ProductLabelRepositoryInterface` | 인터페이스 | 상품 라벨 Repository 인터페이스 |
| `ProductNoticeTemplateRepository` | 구현 | 상품정보제공고시 템플릿 Repository 구현체 |
| `ProductNoticeTemplateRepositoryInterface` | 인터페이스 | 상품정보제공고시 템플릿 Repository 인터페이스 |
| `ProductOptionRepository` | 구현 | 상품 옵션 Repository 구현체 |
| `ProductOptionRepositoryInterface` | 인터페이스 | 상품 옵션 Repository 인터페이스 |
| `ProductRepository` | 구현 | 상품 Repository 구현체 |
| `ProductRepositoryInterface` | 인터페이스 | 상품 Repository 인터페이스 |
| `ProductReviewImageRepository` | 구현 | 상품 리뷰 이미지 Repository 구현체 |
| `ProductReviewImageRepositoryInterface` | 인터페이스 | 상품 리뷰 이미지 Repository 인터페이스 |
| `ProductReviewRepository` | 구현 | 상품 리뷰 Repository 구현체 |
| `ProductReviewRepositoryInterface` | 인터페이스 | 상품 리뷰 Repository 인터페이스 |
| `ProductWishlistRepository` | 구현 | 상품 찜 Repository 구현체 |
| `ProductWishlistRepositoryInterface` | 인터페이스 | 상품 찜 Repository 인터페이스 |
| `SearchPresetRepository` | 구현 | 검색 프리셋 Repository 구현체 |
| `SearchPresetRepositoryInterface` | 인터페이스 | 검색 프리셋 Repository 인터페이스 |
| `SequenceRepository` | 구현 | 시퀀스 Repository 구현체 |
| `SequenceRepositoryInterface` | 인터페이스 | 시퀀스 Repository 인터페이스 |
| `ShippingCarrierRepository` | 구현 | 배송사 Repository 구현체 |
| `ShippingCarrierRepositoryInterface` | 인터페이스 | 배송사 Repository 인터페이스 |
| `ShippingPolicyRepository` | 구현 | 배송정책 Repository 구현체 |
| `ShippingPolicyRepositoryInterface` | 인터페이스 | 배송정책 Repository 인터페이스 |
| `ShippingTypeRepository` | 구현 | 배송유형 Repository 구현체 |
| `ShippingTypeRepositoryInterface` | 인터페이스 | 배송유형 Repository 인터페이스 |
| `TempOrderRepository` | 구현 | 임시 주문 Repository 구현체 |
| `TempOrderRepositoryInterface` | 인터페이스 | 임시 주문 Repository 인터페이스 |
| `UserAddressRepository` | 구현 | 사용자 배송지 Repository 구현체 |
| `UserAddressRepositoryInterface` | 인터페이스 | 사용자 배송지 Repository 인터페이스 |
<!-- @generated:repositories END -->

<!-- @intent START -->
Repository 는 인터페이스와 구현이 1:1 로 짝을 이루며, 서비스는 **인터페이스만 주입**받습니다
(구체 클래스 타입힌트 금지). 바인딩은 모듈 서비스 프로바이더가 담당합니다.

이 모듈에서 Repository 를 손댈 때 특히 걸리는 것 셋:

- **목록 쿼리의 컬럼 프루닝** — `paginate()` 에 컬럼 목록을 주고, 목록이 실제로 그리는 것만
  싣습니다. 상품 목록에 옵션 전체를 실으면 상품 100건 × 옵션 20건이 한 응답에 나갑니다.
- **정렬 컬럼 화이트리스트** — 요청에서 온 정렬 컬럼을 그대로 `orderBy` 에 넘기지 않습니다.
  화면의 정렬 옵션 ⊆ FormRequest 게이트 ⊆ Repository 화이트리스트 순서로 포함 관계가
  유지되어야 하며, 어긋나면 422 뒤에 직전 목록이 남아 **정렬된 것처럼 보입니다.**
- **마일리지 두 Repository 의 역할 차이** — `MileageTransactionRepository` 는 원장이고
  `MileageBalanceRepository` 는 파생 캐시입니다. 차감 가능 여부 판정은 반드시 원장
  `FOR UPDATE` 로 하고, 캐시는 같은 트랜잭션 마지막에 재계산합니다. 캐시를 근거로 차감하면
  동시 요청에서 잔액이 음수가 됩니다.

`EcommerceStatRepository` 만 성격이 다릅니다 — 대시보드용 일별 집계 테이블을 읽고 쓰며, 원본
주문에서 매번 집계하지 않기 위한 자리입니다. 집계를 채우는 것은 `aggregate-stats` 스케줄입니다.
<!-- @intent END -->
