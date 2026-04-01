<?php

return [
    'description' => [
        // 상품 관리 (Admin)
        'product_index' => '상품 목록 조회',
        'product_show' => '상품 상세 조회 (ID: :product_id)',
        'product_create' => '상품 생성 (:product_name)',
        'product_update' => '상품 수정 (:product_name)',
        'product_delete' => '상품 삭제 (:product_name)',
        'product_bulk_update' => '상품 일괄 수정 (:count건)',
        'product_bulk_price_update' => '상품 일괄 가격 수정 (:count건)',
        'product_bulk_stock_update' => '상품 일괄 재고 수정 (:count건)',

        // 주문 관리 (Admin)
        'order_index' => '주문 목록 조회',
        'order_show' => '주문 상세 조회 (:order_number)',
        'order_create' => '주문 생성 (:order_number)',
        'order_update' => '주문 수정 (:order_number)',
        'order_delete' => '주문 삭제 (:order_number)',
        'order_payment_complete' => '결제 완료 (:order_number)',
        'order_payment_failed' => '결제 실패 (:order_number)',
        'order_cancel' => '주문 전체 취소 (:order_number)',
        'order_partial_cancel' => '주문 부분 취소 (:order_number)',
        'order_coupon_restore' => '주문 취소 쿠폰 복원 (:order_number)',
        'order_mileage_restore' => '주문 취소 마일리지 복원 (:order_number, :amount원)',
        'order_bulk_update' => '주문 일괄 변경 (:count건)',
        'order_bulk_status_update' => '주문 일괄 상태 변경 (:count건)',
        'order_bulk_shipping_update' => '운송장 일괄 입력 (:count건)',
        'order_update_shipping_address' => '배송지 변경 (:order_number)',
        'order_send_email' => '주문 이메일 발송 (:order_number)',
        'order_option_status_change' => '주문 옵션 상태 변경 (주문: :order_number)',
        'order_option_bulk_status_change' => '주문 옵션 일괄 상태 변경 (:count건)',

        // 쿠폰 관리 (Admin)
        'coupon_index' => '쿠폰 목록 조회',
        'coupon_show' => '쿠폰 상세 조회 (:coupon_name)',
        'coupon_create' => '쿠폰 생성 (:coupon_name)',
        'coupon_update' => '쿠폰 수정 (:coupon_name)',
        'coupon_delete' => '쿠폰 삭제 (:coupon_name)',
        'coupon_bulk_status' => '쿠폰 일괄 상태 변경 (:count건)',

        // 배송정책 관리 (Admin)
        'shipping_policy_index' => '배송정책 목록 조회',
        'shipping_policy_create' => '배송정책 생성 (:policy_name)',
        'shipping_policy_update' => '배송정책 수정 (:policy_name)',
        'shipping_policy_delete' => '배송정책 삭제 (:policy_name)',
        'shipping_policy_toggle_active' => '배송정책 활성 상태 변경 (:policy_name)',
        'shipping_policy_set_default' => '기본 배송정책 설정 (:policy_name)',
        'shipping_policy_bulk_delete' => '배송정책 일괄 삭제 (:count건)',
        'shipping_policy_bulk_toggle_active' => '배송정책 일괄 활성 상태 변경 (:count건)',

        // 배송업체 관리 (Admin)
        'shipping_carrier_index' => '배송업체 목록 조회',
        'shipping_carrier_show' => '배송업체 상세 조회 (:carrier_name)',
        'shipping_carrier_create' => '배송업체 생성 (:carrier_name)',
        'shipping_carrier_update' => '배송업체 수정 (:carrier_name)',
        'shipping_carrier_delete' => '배송업체 삭제 (:carrier_name)',
        'shipping_carrier_toggle_status' => '배송업체 상태 전환 (:carrier_name)',

        // 카테고리 관리 (Admin)
        'category_index' => '카테고리 목록 조회',
        'category_show' => '카테고리 상세 조회 (:category_name)',
        'category_create' => '카테고리 생성 (:category_name)',
        'category_update' => '카테고리 수정 (:category_name)',
        'category_delete' => '카테고리 삭제 (:category_name)',
        'category_reorder' => '카테고리 순서 변경',
        'category_toggle_status' => '카테고리 상태 전환 (:category_name)',

        // 브랜드 관리 (Admin)
        'brand_index' => '브랜드 목록 조회',
        'brand_show' => '브랜드 상세 조회 (:brand_name)',
        'brand_create' => '브랜드 생성 (:brand_name)',
        'brand_update' => '브랜드 수정 (:brand_name)',
        'brand_delete' => '브랜드 삭제 (:brand_name)',
        'brand_toggle_status' => '브랜드 상태 전환 (:brand_name)',

        // 라벨 관리 (Admin)
        'label_index' => '상품 라벨 목록 조회',
        'label_create' => '상품 라벨 생성 (:label_name)',
        'label_update' => '상품 라벨 수정 (:label_name)',
        'label_delete' => '상품 라벨 삭제 (:label_name)',
        'label_toggle_status' => '상품 라벨 상태 전환 (:label_name)',

        // 상품 공통정보 (Admin)
        'product_common_info_index' => '상품 공통정보 목록 조회',
        'product_common_info_create' => '상품 공통정보 생성 (:info_name)',
        'product_common_info_update' => '상품 공통정보 수정 (:info_name)',
        'product_common_info_delete' => '상품 공통정보 삭제 (:info_name)',

        // 상품 고시정보 템플릿 (Admin)
        'product_notice_template_index' => '상품 고시정보 템플릿 목록 조회',
        'product_notice_template_create' => '상품 고시정보 템플릿 생성 (:template_name)',
        'product_notice_template_update' => '상품 고시정보 템플릿 수정 (:template_name)',
        'product_notice_template_delete' => '상품 고시정보 템플릿 삭제 (:template_name)',
        'product_notice_template_copy' => '상품 고시정보 템플릿 복제 (:template_name)',

        // 추가비용 템플릿 (Admin)
        'extra_fee_template_index' => '추가비용 템플릿 목록 조회',
        'extra_fee_template_create' => '추가비용 템플릿 생성 (:template_name)',
        'extra_fee_template_update' => '추가비용 템플릿 수정 (:template_name)',
        'extra_fee_template_delete' => '추가비용 템플릿 삭제 (:template_name)',
        'extra_fee_template_toggle_active' => '추가비용 템플릿 활성 상태 변경 (:template_name)',
        'extra_fee_template_bulk_delete' => '추가비용 템플릿 일괄 삭제 (:count건)',
        'extra_fee_template_bulk_toggle_active' => '추가비용 템플릿 일괄 활성 상태 변경 (:count건)',
        'extra_fee_template_bulk_create' => '추가비용 템플릿 일괄 등록 (:count건)',

        // 상품옵션 (Admin)
        'product_option_bulk_price_update' => '상품옵션 가격 수정 (옵션 ID: :option_id)',
        'product_option_bulk_stock_update' => '상품옵션 재고 수정 (옵션 ID: :option_id)',
        'product_option_bulk_update' => '상품옵션 수정 (옵션 ID: :option_id)',
        'product_stock_sync' => '옵션 재고 변경으로 상품 재고 동기화 (:product_name)',

        // 상품 이미지 (Admin)
        'product_image_upload' => '상품 이미지 업로드 (상품: :product_name)',
        'product_image_delete' => '상품 이미지 삭제 (상품: :product_name)',
        'product_image_reorder' => '상품 이미지 순서 변경 (상품: :product_name)',

        // 리뷰 관리 (Admin)
        'review_index' => '리뷰 목록 조회',
        'review_show' => '리뷰 상세 조회 (ID: :review_id)',
        'review_create' => '리뷰 작성 (상품: :product_name)',
        'review_delete' => '리뷰 삭제 (ID: :review_id)',
        'review_bulk_delete' => '리뷰 일괄 삭제 (:count건)',
        'review_reply' => '리뷰 답변 작성 (ID: :review_id)',
        'product_review_create' => '상품 리뷰 생성 (리뷰 ID: :review_id)',
        'product_review_delete' => '상품 리뷰 삭제 (리뷰 ID: :review_id)',

        // 구매확인/부분취소 (per-item)
        'order_option_confirm' => '구매확인 (옵션 ID: :option_id)',
        'order_option_partial_cancel' => '주문 옵션 부분 취소 (옵션 ID: :option_id)',

        // 이커머스 설정
        'ecommerce_settings_index' => '이커머스 설정 조회',

        // 결제 (Admin)
        'payment_refund' => '결제 환불 (주문: :order_number)',

        // 사용자 행위 (ActivityLogType::User)
        'cart_add' => '장바구니 추가 (:product_name)',
        'cart_update_quantity' => '장바구니 수량 변경 (:product_name)',
        'cart_change_option' => '장바구니 옵션 변경 (:product_name)',
        'cart_delete' => '장바구니 삭제',
        'cart_delete_all' => '장바구니 전체 삭제',
        'wishlist_add' => '위시리스트 추가 (:product_name)',
        'wishlist_remove' => '위시리스트 제거 (:product_name)',
        'coupon_use' => '쿠폰 사용 (:coupon_name)',
        'coupon_restore' => '쿠폰 복원 (:coupon_name)',
        'user_coupon_download' => '쿠폰 다운로드 (:coupon_name)',
        'user_order_create' => '주문 완료 (#:order_id)',
        'user_order_option_confirm' => '구매확인 (옵션 #:option_id)',
        'mileage_earn' => '마일리지 적립 (:amount원)',
        'mileage_use' => '마일리지 사용 (:amount원)',
        'mileage_restore' => '마일리지 복원 (:amount원)',
    ],

    // ChangeDetector 필드 라벨
    'fields' => [
        // 공통
        'is_active' => '활성 여부',
        'is_default' => '기본값',
        'sort_order' => '정렬 순서',
        'status' => '상태',
        'description' => '설명',
        'category' => '카테고리',
        'slug' => '슬러그',
        'code' => '코드',
        'type' => '유형',
        'color' => '색상',
        'content_mode' => '콘텐츠 모드',
        'admin_memo' => '관리자 메모',

        // Order
        'order_status' => '주문 상태',
        'total_amount' => '총 금액',
        'total_paid_amount' => '총 결제 금액',
        'total_discount_amount' => '총 할인 금액',
        'total_shipping_amount' => '총 배송비',
        'total_cancelled_amount' => '총 취소 금액',
        'total_refunded_amount' => '총 환불 금액',
        'paid_at' => '결제일',
        'confirmed_at' => '확정일',

        // Product
        'sales_status' => '판매 상태',
        'display_status' => '전시 상태',
        'tax_status' => '과세 여부',
        'tax_rate' => '세율',
        'list_price' => '정가',
        'selling_price' => '판매가',
        'stock_quantity' => '재고 수량',
        'safe_stock_quantity' => '안전 재고 수량',
        'has_options' => '옵션 사용',
        'brand_id' => '브랜드',
        'shipping_policy_id' => '배송정책',
        'common_info_id' => '공통정보',
        'min_purchase_qty' => '최소 구매 수량',
        'max_purchase_qty' => '최대 구매 수량',

        // ShippingCarrier
        'tracking_url' => '배송추적 URL',

        // ExtraFeeTemplate
        'zipcode' => '우편번호',
        'fee' => '추가비용',
        'region' => '지역',

        // Coupon
        'target_type' => '대상 유형',
        'discount_type' => '할인 유형',
        'discount_value' => '할인 금액/비율',
        'discount_max_amount' => '최대 할인 금액',
        'min_order_amount' => '최소 주문 금액',
        'issue_method' => '발급 방법',
        'issue_condition' => '발급 조건',
        'issue_status' => '발급 상태',
        'total_quantity' => '총 수량',
        'per_user_limit' => '1인당 제한',
        'valid_type' => '유효기간 유형',
        'valid_days' => '유효 일수',
        'valid_from' => '유효기간 시작',
        'valid_to' => '유효기간 종료',
        'issue_from' => '발급기간 시작',
        'issue_to' => '발급기간 종료',
        'is_combinable' => '중복 사용 가능',

        // Category
        'parent_id' => '상위 카테고리',
        'meta_title' => 'META 제목',
        'meta_description' => 'META 설명',

        // OrderOption
        'option_status' => '옵션 상태',
        'quantity' => '수량',
        'cancelled_quantity' => '취소 수량',

        // OrderAddress
        'recipient_name' => '수령인',
        'recipient_phone' => '수령인 연락처',
        'address' => '주소',
        'address_detail' => '상세 주소',
        'delivery_memo' => '배송 메모',

        // ProductOption
        'option_name' => '옵션명',
        'sku' => 'SKU',
        'price_adjustment' => '가격 조정',
    ],
];
