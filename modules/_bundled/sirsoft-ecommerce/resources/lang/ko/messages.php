<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ecommerce Module Messages (Korean)
    |--------------------------------------------------------------------------
    */

    'order' => [
        // 주문 생성
        'created' => '주문이 완료되었습니다.',
        'create_failed' => '주문 생성에 실패했습니다.',

        // 주문 상태
        'status_changed' => '주문 상태가 변경되었습니다.',
        'cancelled' => '주문이 취소되었습니다.',
        'cancel_failed' => '주문 취소에 실패했습니다.',

        // 자동 취소
        'auto_cancel_expired_reason' => '입금 기한 만료로 인한 자동 취소',

        // 주문 취소
        'cancelled' => '주문이 취소되었습니다.',

        // 결제
        'payment_cancelled' => '결제 취소가 기록되었습니다.',
        'payment_pending' => '입금 대기 중입니다.',
        'payment_completed' => '결제가 완료되었습니다.',
        'payment_failed' => '결제에 실패했습니다.',

        // 재고
        'stock_insufficient' => '재고가 부족합니다.',
        'stock_restored' => '재고가 복원되었습니다.',

        // 검증
        'not_found' => '주문을 찾을 수 없습니다.',
        'invalid_status' => '유효하지 않은 주문 상태입니다.',
        'amount_mismatch' => '결제 금액이 일치하지 않습니다.',
    ],

    'address' => [
        'created' => '배송지가 추가되었습니다.',
        'create_failed' => '배송지 추가에 실패했습니다.',
        'updated' => '배송지가 수정되었습니다.',
        'update_failed' => '배송지 수정에 실패했습니다.',
        'deleted' => '배송지가 삭제되었습니다.',
        'delete_failed' => '배송지 삭제에 실패했습니다.',
        'not_found' => '배송지를 찾을 수 없습니다.',
        'fetched' => '배송지 정보를 조회했습니다.',
        'fetch_failed' => '배송지 조회에 실패했습니다.',
        'list_fetched' => '배송지 목록을 조회했습니다.',
        'list_fetch_failed' => '배송지 목록 조회에 실패했습니다.',
        'set_default' => '기본 배송지로 설정되었습니다.',
        'default_set' => '기본 배송지로 설정되었습니다.',
        'set_default_failed' => '기본 배송지 설정에 실패했습니다.',
        'name_duplicate' => '동일한 이름의 배송지가 이미 존재합니다.',
        'auto_saved_label' => '자동 저장된 배송지',
    ],

    'cart' => [
        'added' => '장바구니에 추가되었습니다.',
        'updated' => '장바구니가 수정되었습니다.',
        'removed' => '장바구니에서 삭제되었습니다.',
        'empty' => '장바구니가 비어있습니다.',
    ],

    'product' => [
        'not_found' => '상품을 찾을 수 없습니다.',
        'not_available' => '현재 구매할 수 없는 상품입니다.',
        'option_not_found' => '상품 옵션을 찾을 수 없습니다.',
    ],

    'coupon' => [
        'applied' => '쿠폰이 적용되었습니다.',
        'removed' => '쿠폰이 해제되었습니다.',
        'invalid' => '유효하지 않은 쿠폰입니다.',
        'expired' => '만료된 쿠폰입니다.',
        'already_used' => '이미 사용된 쿠폰입니다.',
        'min_amount_not_met' => '최소 주문 금액 조건을 충족하지 않습니다.',
    ],

    'payment' => [
        'method_not_supported' => '지원하지 않는 결제 수단입니다.',
        'dbank_account_required' => '무통장입금 계좌를 선택해주세요.',
        'depositor_name_required' => '입금자명을 입력해주세요.',
        'provider_not_found' => '결제 제공자를 찾을 수 없습니다.',
        'client_config_success' => '결제 클라이언트 설정을 조회했습니다.',
    ],
];
