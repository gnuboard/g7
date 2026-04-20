<?php

/**
 * Ecommerce Module Exception Messages (Korean)
 *
 * Custom exception messages for the ecommerce module
 */
return [
    'brand_not_found' => '브랜드를 찾을 수 없습니다.',
    'brand_has_products' => '연결된 상품이 :count개 있어 삭제할 수 없습니다. 먼저 상품의 브랜드를 변경해주세요.',
    'category_not_found' => '카테고리(ID: :category_id)를 찾을 수 없습니다.',
    'category_has_children' => '카테고리(ID: :category_id)에 하위 카테고리가 존재하여 삭제할 수 없습니다.',
    'category_has_products' => '연결된 상품이 :count개 있어 삭제할 수 없습니다. 먼저 상품의 카테고리를 변경해주세요.',
    'stock_mismatch' => '상품(ID: :product_id)의 재고가 일치하지 않습니다. 예상: :expected, 실제: :actual',
    'currency_setting_locked' => ':setting_type 설정은 상품이 :product_count개 존재하여 변경할 수 없습니다.',
    'unauthorized_preset_access' => '프리셋(ID: :preset_id)에 대한 접근 권한이 없습니다.',
    'sequence_not_found' => ':type 타입의 시퀀스를 찾을 수 없습니다.',
    'sequence_overflow' => ':type 시퀀스가 최대값(:max_value)에 도달했습니다.',
    'sequence_code_duplicate' => ':type 타입의 코드 :code가 이미 존재합니다.',
    'coupon_not_found' => '쿠폰을 찾을 수 없습니다.',
    'coupon_has_issues' => '발급된 쿠폰이 :count건 있어 삭제할 수 없습니다.',
    'label_not_found' => '라벨을 찾을 수 없습니다.',
    'label_has_products' => '연결된 상품이 :count개 있어 삭제할 수 없습니다. 먼저 상품의 라벨을 변경해주세요.',
    'operation_failed' => '작업 처리 중 오류가 발생했습니다.',
    'cart_item_not_found' => '장바구니 항목을 찾을 수 없습니다.',
    'cart_access_denied' => '장바구니 항목에 대한 접근 권한이 없습니다.',
    'cart_empty' => '장바구니가 비어있습니다.',
    'temp_order_not_found' => '임시 주문 정보를 찾을 수 없습니다.',
    'option_not_found' => '상품 옵션을 찾을 수 없습니다.',
    'out_of_stock' => '품절된 상품입니다.',
    'stock_exceeded' => '재고가 부족합니다. (요청: :requested개, 가용: :available개)',
    'invalid_option_for_product' => '해당 상품의 옵션이 아닙니다.',
    'order_not_found' => '주문을 찾을 수 없습니다.',
    'order_not_cancellable' => '취소할 수 없는 주문 상태입니다.',
    'order_not_cancellable_detail' => '현재 주문 상태(:current_status)에서는 취소할 수 없습니다. (취소 가능: :allowed_statuses)',
    'order_already_cancelled' => '이미 취소된 주문입니다.',
    'order_already_paid' => '이미 결제가 완료된 주문입니다.',
    'order_option_not_found' => '주문 옵션을 찾을 수 없습니다.',
    'order_option_already_cancelled' => '이미 취소된 주문 옵션입니다.',
    'order_option_already_confirmed' => '이미 구매확정된 주문 옵션입니다.',
    'order_option_cannot_confirm' => '현재 상태에서는 구매확정할 수 없습니다.',
    'cancel_quantity_exceeds' => '취소 수량이 현재 수량(:max개)을 초과합니다.',

    // 주문 결제 관련
    'insufficient_stock' => '재고가 부족한 상품이 :count개 있습니다.',
    'payment_amount_mismatch' => '결제 금액이 일치하지 않습니다. (예상: :expected원, 실제: :actual원)',
    'cart_unavailable' => '구매할 수 없는 상품이 있습니다.',
    'order_amount_changed' => '주문 금액이 변동되었습니다. 체크아웃 페이지를 새로고침 후 다시 시도해주세요. (이전: :stored원, 현재: :recalculated원)',
    'order_calculation_validation_failed' => '주문 계산 검증에 실패했습니다. 쿠폰 만료 또는 재고 변동이 발생했을 수 있습니다.',

    // 주문 취소/환불 관련
    'cancel_option_not_found' => '취소 대상 주문 옵션을 찾을 수 없습니다.',
    'cancel_option_already_cancelled' => '이미 취소된 주문 옵션입니다.',
    'cancel_quantity_invalid' => '취소 수량이 유효하지 않습니다.',
    'cancel_refund_negative' => '해당 상품을 취소하면 쿠폰 할인 조건이 충족되지 않아 결제금액이 증가합니다. 취소할 수 없습니다.',
    'pg_refund_failed' => 'PG 환불 처리에 실패했습니다. (:error)',

    // 통화 관련
    'unknown_currency' => '지원하지 않는 통화입니다: :currency',
    'invalid_exchange_rate' => '유효하지 않은 환율입니다: :currency',

    // 클래임 사유 관련
    'claim_reason_not_found' => '클래임 사유를 찾을 수 없습니다.',
    'claim_reason_in_use' => '주문 취소에서 사용 중인 사유는 삭제할 수 없습니다. (사용 횟수: :count건)',

    // 배송유형 관련
    'shipping_type_not_found' => '배송유형을 찾을 수 없습니다.',
    'shipping_type_in_use' => '주문에서 사용 중인 배송유형(:name)은 삭제할 수 없습니다. (사용 횟수: :count건)',
];
