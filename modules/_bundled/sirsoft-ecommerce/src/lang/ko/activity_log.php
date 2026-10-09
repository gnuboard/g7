<?php

return [
    // 액션 라벨 (마지막 세그먼트 기준).
    // ActivityLog::getActionLabelAttribute 가 모듈 origin 라벨을 자체 lang 에서 우선 조회하므로,
    // 이 모듈 listener 가 발화하는 모든 last segment 를 자기 영역으로 등록 (G7 영역 분리 일관성).
    // 코어 lang 도 동일 키를 일부 보유하지만 본 lang 이 1차 SSoT.
    'action' => [
        'add' => '추가',
        'bulk_create' => '일괄 생성',
        'bulk_delete' => '일괄 삭제',
        'bulk_price_update' => '일괄 가격 수정',
        'bulk_shipping_update' => '운송장 일괄 입력',
        'bulk_status' => '일괄 상태 변경',
        'bulk_status_change' => '일괄 상태 변경',
        'bulk_status_update' => '일괄 상태 변경',
        'bulk_stock_update' => '일괄 재고 수정',
        'bulk_toggle_active' => '일괄 활성 상태 변경',
        'bulk_update' => '일괄 수정',
        'cancel' => '취소',
        'change' => '변경',
        'change_option' => '옵션 변경',
        'confirm' => '구매 확정',
        'copy' => '복사',
        'create' => '생성',
        'delete' => '삭제',
        'delete_all' => '전체 삭제',
        'direct_issue' => '직접 발급',
        'issue_cancel' => '발급 취소',
        'download' => '다운로드',
        'earn' => '적립',
        'partial_cancel' => '부분 취소',
        'payment_complete' => '결제 완료',
        'deposit_record' => '입금 기록',
        'payment_failed' => '결제 실패',
        'remove' => '제거',
        'reorder' => '순서 변경',
        'reset_guest_password' => '비회원 조회 비밀번호 재설정',
        'restore' => '복원',
        'send_email' => '이메일 발송',
        'set_default' => '기본값 설정',
        'status_change' => '상태 변경',
        'sync_status' => '상태 자동 반영',
        'status_sync' => '상태 자동 동기화',
        'manual_paid_effects' => '수동 결제 처리',
        'stock_sync' => '재고 동기화',
        'toggle_active' => '활성 상태 변경',
        'tax_invoice_stale' => '세금계산서 미처리',
        'tracking_update' => '운송장 기록·정정',
        'tracking_deliver' => '송장 도착 처리',
        'mode_change' => '진행 방식 변경',
        'stock_restore' => '재고 복구',
        'toggle_status' => '상태 전환',
        'update' => '수정',
        'update_quantity' => '수량 변경',
        'update_shipping_address' => '배송지 변경',
        'upload' => '업로드',
        'use' => '사용',
        'expire' => '소멸',
        'earn_cancel' => '적립 회수',
        'admin_earn' => '관리자 지급',
        'admin_deduct' => '관리자 차감',
        'extend_expiry' => '유효기간 연장',
        'adjust' => '적립건 수정',
        'refund_credit' => '환불 지급',
        // 클레임 (교환/반품/환불/추가결제/첨부)
        'request' => '신청 접수',
        'option_status_change' => '상품 단위 상태 변경',
        'update_amounts' => '금액 수정',
        'request_update' => '신청 내용 수정',
        'complete' => '완료',
        'refund' => '환급',
        'execute' => '실행',
    ],

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
        'order_deposit_recorded' => '입금 기록 (:order_number)',
        'order_payment_failed' => '결제 실패 (:order_number)',
        'order_cancel' => '주문 전체 취소 (:order_number)',
        'order_partial_cancel' => '주문 부분 취소 (:order_number)',
        'order_coupon_restore' => '주문 취소 쿠폰 복원 (:order_number)',
        'order_mileage_restore' => '주문 취소 마일리지 복원 (:order_number, :amount)',
        'order_bulk_update' => '주문 일괄 변경 (:count건)',
        'order_bulk_status_update' => '주문 일괄 상태 변경 (:count건)',
        'order_bulk_shipping_update' => '운송장 일괄 입력 (:count건)',
        'order_update_shipping_address' => '배송지 변경 (:order_number)',
        'order_send_email' => '주문 이메일 발송 (:order_number)',
        'order_reset_guest_password' => '비회원 조회 비밀번호 재설정 (:order_number)',
        'order_option_status_change' => '주문 옵션 상태 변경 (주문: :order_number)',
        'order_option_bulk_status_change' => '주문 옵션 일괄 상태 변경 (:count건)',
        'order_option_sync_status' => '주문 상태 동기화에 따른 상품 상태 변경 (주문: :order_number · :count건)',
        'order_shipping_tracking_update' => '운송장 기록·정정 (상품 ID: :option_id)',
        'order_tax_invoice_stale' => '취소 후 세금계산서 미처리 (주문: :order_number · :count건)',
        'order_status_sync' => '주문 상태 자동 변경 — 상품 상태에 맞춰 (:order_number)',
        'order_manual_paid_effects' => '수동 결제 처리 (:order_number) — 적용: :applied / 적용 안 함: :skipped',

        // 쿠폰 관리 (Admin)
        'coupon_index' => '쿠폰 목록 조회',
        'coupon_show' => '쿠폰 상세 조회 (:coupon_name)',
        'coupon_create' => '쿠폰 생성 (:coupon_name)',
        'coupon_update' => '쿠폰 수정 (:coupon_name)',
        'coupon_delete' => '쿠폰 삭제 (:coupon_name)',
        'coupon_bulk_status' => '쿠폰 일괄 상태 변경 (:count건)',
        'coupon_direct_issue' => '쿠폰 직접 발급 (:coupon_name → 회원 #:user_id)',
        'coupon_issue_cancel' => '쿠폰 발급 취소 (:coupon_name → 회원 #:user_id)',

        // 배송정책 관리 (Admin)
        'shipping_policy_index' => '배송정책 목록 조회',
        'shipping_policy_create' => '배송정책 생성 (:policy_name)',
        'shipping_policy_update' => '배송정책 수정 (:policy_name)',
        'shipping_policy_delete' => '배송정책 삭제 (:policy_name)',
        'shipping_policy_toggle_active' => '배송정책 활성 상태 변경 (:policy_name)',
        'shipping_policy_set_default' => '기본 배송정책 설정 (:policy_name)',
        'shipping_policy_bulk_delete' => '배송정책 일괄 삭제 (:count건)',
        'wishlist_bulk_delete' => '찜 일괄 삭제 (:count건)',
        'cart_bulk_delete' => '장바구니 일괄 삭제 (:count건)',
        'user_address_bulk_delete' => '회원 배송지 일괄 삭제 (:count건)',
        'product_inquiry_bulk_delete' => '상품문의 일괄 삭제 (:count건)',
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
        'user_currency_change' => '회원 결제 통화 변경',
        'user_shipping_country_change' => '회원 배송국가 변경',
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
        'product_notice_template_toggle_active' => '상품 고시정보 템플릿 활성 상태 변경 (:template_name)',

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
        'ecommerce_settings_update' => '이커머스 설정 저장 (:categories)',

        // 클레임 사유 카탈로그
        'claim_reason_create' => '클레임 사유 생성 (:reason_type · :reason_code)',
        'claim_reason_update' => '클레임 사유 수정 (:reason_type · :reason_code)',
        'claim_reason_delete' => '클레임 사유 삭제 (:reason_type · :reason_code)',
        'claim_reason_toggle_status' => '클레임 사유 활성 전환 (:reason_type · :reason_code)',

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
        'mileage_earn' => '마일리지 적립 (:amount)',
        'mileage_earn_scheduled' => '마일리지 적립 예정 (:amount) — 적립 시점에 지급',
        'mileage_use' => '마일리지 사용 (:amount)',
        'mileage_restore' => '마일리지 복원 (:amount)',
        'mileage_refund_credit' => '환불금 마일리지 지급 (:amount)',
        'mileage_expire' => '마일리지 소멸 (:amount)',
        'mileage_withdrawal_expire' => '회원 탈퇴로 마일리지 소멸 (:amount)',
        'mileage_deletion_expire' => '회원 삭제로 마일리지 소멸 (:amount)',
        'mileage_earn_cancel' => '마일리지 적립 회수 (:amount)',
        'mileage_admin_earn' => '관리자 마일리지 지급 (:amount)',
        'mileage_admin_deduct' => '관리자 마일리지 차감 (:amount)',
        'mileage_extend_expiry' => '마일리지 유효기간 연장 (:days일)',
        'mileage_adjust' => '마일리지 적립건 수정 (:amount)',

        // 클레임 (교환/반품/환불) — 상태 값은 properties 에 남기고 문구에는 식별자만 담는다
        'claim_exchange_request' => '교환 신청 접수 (:claim_number)',
        'claim_return_request' => '반품 신청 접수 (:claim_number)',
        'claim_refund_request' => '환불 신청 접수 (:claim_number)',
        'claim_cancel_request' => '취소신청서 생성 (:claim_number)',
        'claim_exchange_status_change' => '교환 신청서 상태 변경 (:claim_number)',
        'claim_return_status_change' => '반품 신청서 상태 변경 (:claim_number)',
        'claim_refund_status_change' => '환불 신청서 상태 변경 (:claim_number)',
        'claim_cancel_status_change' => '취소신청서 상태 변경 (:claim_number)',
        'claim_exchange_option_status_change' => '교환 신청서 상품 단위 상태 변경 (:claim_number · :count건)',
        'claim_return_option_status_change' => '반품 신청서 상품 단위 상태 변경 (:claim_number · :count건)',
        'claim_refund_option_status_change' => '환불 신청서 상품 단위 상태 변경 (:claim_number · :count건)',
        'claim_cancel_option_status_change' => '취소신청서 상품 단위 상태 변경 (:claim_number · :count건)',
        'claim_exchange_collection_tracking_update' => '교환 신청서 회수 송장 변경 (:claim_number · 상품 :count건)',
        'claim_exchange_redelivery_tracking_update' => '교환 신청서 재발송 송장 변경 (:claim_number · 상품 :count건)',
        'claim_return_collection_tracking_update' => '반품 신청서 회수 송장 변경 (:claim_number · 상품 :count건)',
        'claim_exchange_collection_tracking_deliver' => '교환 신청서 회수 운송장 도착 처리 (:claim_number · :tracking_number · 상품 :count건)',
        'claim_exchange_redelivery_tracking_deliver' => '교환 신청서 재발송 운송장 도착 처리 (:claim_number · :tracking_number · 상품 :count건)',
        'claim_return_collection_tracking_deliver' => '반품 신청서 회수 운송장 도착 처리 (:claim_number · :tracking_number · 상품 :count건)',
        'claim_exchange_mode_change' => '교환 신청서 진행 방식 변경 (:claim_number)',
        'claim_exchange_stock_restore' => '교환 신청서 재고 복구 (:claim_number · 상품 :count건 · :quantity개)',
        'claim_return_stock_restore' => '반품 신청서 재고 복구 (:claim_number · 상품 :count건 · :quantity개)',
        'claim_exchange_update_amounts' => '교환 신청서 금액 수정 (:claim_number)',
        'claim_return_update_amounts' => '반품 신청서 금액 수정 (:claim_number)',
        'claim_refund_update_amounts' => '환불 신청서 금액 수정 (:claim_number)',
        'claim_exchange_request_update' => '교환 신청 내용 수정 (:claim_number)',
        'claim_return_request_update' => '반품 신청 내용 수정 (:claim_number)',
        'claim_refund_request_update' => '환불 신청 내용 수정 (:claim_number)',
        'claim_exchange_request_update_on_behalf' => '교환 신청 내용 수정 — 운영자가 고객 대신 수정 (:claim_number)',
        'claim_return_request_update_on_behalf' => '반품 신청 내용 수정 — 운영자가 고객 대신 수정 (:claim_number)',
        'claim_refund_request_update_on_behalf' => '환불 신청 내용 수정 — 운영자가 고객 대신 수정 (:claim_number)',
        'claim_refund_execute' => '환불 실행 (:claim_number)',
        'claim_payment_complete' => '클레임 추가결제 완료 (:payment_number)',
        'claim_payment_refund' => '클레임 추가결제 환급 (:payment_number)',
        // 금액을 싣는 새 환급 로그 — 옛 키 문구에 자리표시자를 더하면 금액 없이 남은 과거 로그가 「:amount」 를 드러낸다.
        'claim_payment_refund_amount' => '클레임 추가결제 환급 (:payment_number, :amount)',
        'claim_payment_cancel' => '클레임 추가결제 대기 취소 (:payment_number)',
        'claim_image_upload' => '클레임 첨부 업로드 (:original_name)',
        'claim_image_delete' => '클레임 첨부 삭제 (:original_name)',
    ],

    // ChangeDetector 필드 라벨
    // 수동 결제 처리 활동 로그의 항목 이름 (Q13·Q26) — 설명 문구의 적용/적용 안 함 목록을 렌더 시점에 번역한다
    'manual_paid' => [
        'items' => [
            'apply_stock_deduction' => '재고 차감',
            'apply_mileage_deduction' => '사용 마일리지 차감',
            'record_paid_amount' => '결제금액 기록',
            'restoration' => '합계·상품 상태 복원',
        ],
        'none' => '-',
    ],

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

        // Brand
        'website' => '웹사이트',

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
        'collection_tracking' => '회수 송장',
        'redelivery_tracking' => '재발송 송장',
        'exchange_mode' => '진행 방식',
        'unreturned_reason' => '미회수 사유',
        'option_status' => '옵션 상태',
        'carrier_id' => '택배사',
        'tracking_number' => '운송장번호',
        'quantity' => '수량',
        'cancelled_quantity' => '취소 수량',

        // OrderAddress
        'recipient_name' => '수령인',
        'recipient_phone' => '수령인 연락처',
        'address' => '주소',
        'address_detail' => '상세 주소',
        'delivery_memo' => '배송 메모',
        'delivery_memo_label' => '배송 메모 라벨',

        // ProductOption
        'option_name' => '옵션명',
        'sku' => 'SKU',
        'price_adjustment' => '가격 조정',

        // 클레임 금액 수정 (D18)
        'claim_shipping_fee' => '클레임 배송비',
        'total_charge_amount' => '총 청구 금액',
        'charge_method' => '청구 방식',
        'refund_amount' => '환불 금액',
        'refund_points_amount' => '환불 마일리지',
        'refund_method' => '환불 수단',

        // 클레임 신청 내용 수정 (D13 · S15)
        'claim_reason' => '사유',
        'fault_type' => '귀책',
        'reason_detail' => '상세 사유',
        'refund_bank' => '환불 계좌',
        'redelivery_address' => '재발송지',
    ],

    // 변경 값 표시 (값을 싣지 않는 항목)
    'values' => [
        'masked_change' => '변경됨 (개인정보라 값은 남기지 않음)',
    ],

    // 이름을 되찾지 못한 엔티티의 표시 (설명 파라미터 해석 실패 시)
    'entity_id_fallback' => 'ID: :id',
];
