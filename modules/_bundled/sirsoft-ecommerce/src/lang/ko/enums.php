<?php

/**
 * 이커머스 모듈 Enum 라벨
 *
 * Enum 값의 다국어 라벨
 */
return [
    'sales_status' => [
        'on_sale' => '판매중',
        'suspended' => '판매중지',
        'sold_out' => '품절',
        'coming_soon' => '출시예정',
    ],
    'display_status' => [
        'visible' => '전시',
        'hidden' => '숨김',
    ],
    'tax_status' => [
        'taxable' => '과세',
        'tax_free' => '면세',
    ],
    'image_collection' => [
        'main' => '대표 이미지',
        'detail' => '상세 이미지',
        'additional' => '추가 이미지',
    ],
    'target_screen' => [
        'products' => '상품 목록',
        'orders' => '주문 목록',
        'customers' => '고객 목록',
    ],
    'date_type' => [
        'created_at' => '등록일',
        'updated_at' => '수정일',
    ],
    'price_type' => [
        'selling_price' => '판매가',
        'supply_price' => '공급가',
        'list_price' => '정가',
    ],

    // 주문상태 (OrderStatusEnum 값과 일치)
    'order_status' => [
        'pending_order' => '주문대기',
        'pending_payment' => '결제대기',
        'payment_complete' => '결제완료',
        'shipping_hold' => '배송보류',
        'preparing' => '상품준비중',
        'shipping_ready' => '배송준비완료',
        'shipping' => '배송중',
        'delivered' => '배송완료',
        'confirmed' => '구매확정',
        'cancelled' => '주문취소',
        'partial_cancelled' => '부분취소',
    ],

    // 결제상태 (PaymentStatusEnum 값과 일치)
    'payment_status' => [
        'ready' => '결제대기',
        'in_progress' => '결제진행중',
        'waiting_deposit' => '입금대기',
        'paid' => '결제완료',
        'partial_cancelled' => '부분취소',
        'cancelled' => '결제취소',
        'failed' => '결제실패',
        'expired' => '기한만료',
    ],

    // 결제수단 (PaymentMethodEnum 값과 일치)
    'payment_method' => [
        'card' => '신용카드',
        'vbank' => '가상계좌',
        'dbank' => '무통장입금',
        'bank' => '계좌이체',
        'phone' => '휴대폰결제',
        'point' => '포인트결제',
        'deposit' => '예치금결제',
        'free' => '무료',
    ],

    // 배송상태
    'shipping_status' => [
        'pending' => '배송대기',
        'preparing' => '상품준비중',
        'ready' => '배송준비완료',
        'shipped' => '배송중',
        'in_transit' => '이동중',
        'out_for_delivery' => '배송출발',
        'delivered' => '배송완료',
        'failed' => '배송실패',
        'returned' => '반송',
        'pickup_ready' => '방문수령대기',
        'pickup_complete' => '방문수령완료',
    ],

    // 배송유형 (ShippingTypeEnum 값과 일치)
    'shipping_type' => [
        'domestic_parcel' => '국내택배',
        'domestic_express' => '국내특급',
        'domestic_quick' => '퀵서비스',
        'international_ems' => '국제EMS',
        'international_standard' => '국제일반',
        'pickup' => '방문수령',
        'cvs' => '편의점택배',
        'digital' => '디지털상품',
    ],

    // 옵션상태
    'option_status' => [
        'ordered' => '주문완료',
        'confirmed' => '확인완료',
        'preparing' => '준비중',
        'shipped' => '발송완료',
        'delivered' => '배송완료',
        'cancelled' => '취소',
        'refund_requested' => '환불요청',
        'refund_complete' => '환불완료',
        'return_requested' => '반품요청',
        'return_complete' => '반품완료',
        'exchange_requested' => '교환요청',
        'exchange_complete' => '교환완료',
    ],

    // 주문 날짜 유형
    'order_date_type' => [
        'ordered_at' => '주문일',
        'paid_at' => '결제일',
        'confirmed_at' => '확인일',
        'delivered_at' => '배송완료일',
        'cancelled_at' => '취소일',
    ],

    // 디바이스 유형
    'device_type' => [
        'pc' => 'PC',
        'mobile' => '모바일',
        'app_ios' => 'iOS 앱',
        'app_android' => 'Android 앱',
        'admin' => '관리자',
        'api' => 'API',
    ],

    // 쿠폰 적용대상 (CouponTargetType Enum 값과 일치)
    'coupon_target_type' => [
        'product_amount' => '상품금액',
        'order_amount' => '주문금액',
        'shipping_fee' => '배송비',
    ],

    // 쿠폰 적용대상 짧은 라벨 (드롭다운 표시용)
    'coupon_target_type_short' => [
        'product_amount' => '상품',
        'order_amount' => '주문',
        'shipping_fee' => '배송비',
    ],

    // 쿠폰 할인유형 (CouponDiscountType Enum 값과 일치)
    'coupon_discount_type' => [
        'fixed' => '정액할인',
        'rate' => '정률할인',
    ],

    // 쿠폰 발급상태 (CouponIssueStatus Enum 값과 일치)
    'coupon_issue_status' => [
        'issuing' => '발급중',
        'stopped' => '발급중단',
    ],

    // 쿠폰 발급방법 (CouponIssueMethod Enum 값과 일치)
    'coupon_issue_method' => [
        'direct' => '직접발급',
        'download' => '다운로드',
        'auto' => '자동발급',
    ],

    // 쿠폰 발급조건 (CouponIssueCondition Enum 값과 일치)
    'coupon_issue_condition' => [
        'manual' => '수동발급',
        'signup' => '회원가입',
        'first_purchase' => '첫구매',
        'birthday' => '생일',
    ],

    // 쿠폰 적용 범위 (CouponTargetScope Enum 값과 일치)
    'coupon_target_scope' => [
        'all' => '전체상품',
        'products' => '특정상품',
        'categories' => '특정카테고리',
    ],

    // 쿠폰 발급 내역 상태 (CouponIssueRecordStatus Enum 값과 일치)
    'coupon_issue_record_status' => [
        'available' => '사용가능',
        'used' => '사용완료',
        'expired' => '기간만료',
        'cancelled' => '취소됨',
    ],

    // 배송방법
    'shipping_method' => [
        'parcel' => '택배',
        'freight' => '화물',
        'express' => '특급',
        'economy' => '이코노미',
        'ems' => 'EMS',
        'collect' => '착불',
        'quick' => '퀵서비스',
        'direct' => '직접배송',
        'pickup' => '방문수령',
        'other' => '기타',
    ],

    // 과금정책
    'charge_policy' => [
        'free' => '무료',
        'fixed' => '고정',
        'conditional_free' => '조건부 무료',
        'range_amount' => '구간별(금액)',
        'range_quantity' => '구간별(수량)',
        'range_weight' => '구간별(무게)',
        'range_volume' => '구간별(부피)',
        'range_volume_weight' => '구간별(부피+무게)',
        'api' => '계산 API',
        'per_quantity' => '수량당',
        'per_weight' => '무게당',
        'per_volume' => '부피당',
        'per_volume_weight' => '부피무게당',
        'per_amount' => '금액당',
    ],

    // 주문 옵션 상태 — OrderStatusEnum으로 통일 (order_status 키 참조)

    // 주문 옵션 생성 원인 (OrderOptionSourceTypeEnum 값과 일치)
    'order_option_source_type' => [
        'order' => '최초 주문',
        'exchange' => '교환',
        'split' => '수량 분할',
    ],

    // 세금계산서 발급 상태 (TaxInvoiceStatusEnum 값과 일치)
    'tax_invoice_status' => [
        'pending' => '발급대기',
        'processing' => '발급처리중',
        'issued' => '발급완료',
        'failed' => '발급실패',
        'cancelled' => '발급취소',
    ],

    // 배송국가
    'shipping_country' => [
        'KR' => '한국',
        'US' => '미국',
        'CN' => '중국',
        'JP' => '일본',
    ],

    // 취소유형 (CancelTypeEnum 값과 일치)
    'cancel_type' => [
        'full' => '전체취소',
        'partial' => '부분취소',
    ],

    // 취소상태 (CancelStatusEnum 값과 일치)
    'cancel_status' => [
        'requested' => '취소신청',
        'completed' => '취소완료',
    ],

    // 클래임 사유 귀책 유형 (ClaimReasonFaultTypeEnum 값과 일치)
    'claim_reason_fault_type' => [
        'customer' => '고객 귀책',
        'seller' => '판매자 귀책',
        'carrier' => '배송사 귀책',
    ],

    // 클래임 사유 유형 (ClaimReasonTypeEnum 값과 일치)
    'claim_reason_type' => [
        'refund' => '환불/취소',
    ],

    // 취소옵션상태 (CancelOptionStatusEnum 값과 일치)
    'cancel_option_status' => [
        'requested' => '취소신청',
        'completed' => '처리완료',
    ],

    // 환불상태 (RefundStatusEnum 값과 일치)
    'refund_status' => [
        'requested' => '환불신청',
        'approved' => '환불승인',
        'processing' => '환불처리중',
        'on_hold' => '환불보류',
        'completed' => '환불완료',
        'rejected' => '환불반려',
    ],

    // 환불수단 (RefundMethodEnum 값과 일치)
    'refund_method' => [
        'pg' => 'PG사 환불',
        'bank' => '계좌이체 환불',
        'points' => '마일리지 환불',
    ],

    // 환불 우선순위 (RefundPriorityEnum 값과 일치)
    'refund_priority' => [
        'pg_first' => '결제수단(PG) 먼저 환불',
        'points_first' => '포인트 먼저 환불',
    ],

    // 환불옵션상태 (RefundOptionStatusEnum 값과 일치)
    'refund_option_status' => [
        'requested' => '환불신청',
        'approved' => '환불승인',
        'processing' => '환불처리중',
        'on_hold' => '환불보류',
        'completed' => '처리완료',
        'rejected' => '환불반려',
    ],

    // 리뷰 상태 (ReviewStatus 값과 일치)
    'review_status' => [
        'visible' => '전시중',
        'hidden' => '숨김',
    ],

    // 리뷰 답변 여부
    'has_reply' => [
        'replied' => '답변완료',
        'not_replied' => '미답변',
    ],
];
