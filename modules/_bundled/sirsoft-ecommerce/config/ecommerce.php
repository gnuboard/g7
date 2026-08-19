<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 이커머스 기본 설정
    |--------------------------------------------------------------------------
    */

    // 통화 설정
    'currency' => [
        'code' => 'KRW',
        'symbol' => '₩',
        'position' => 'before', // before, after
        'decimal_places' => 0,
    ],

    // 상품 설정
    'product' => [
        'max_images' => 10,
        'max_image_size' => 5 * 1024 * 1024, // 5MB
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    ],

    // 주문 설정
    'order' => [
        'order_number_prefix' => 'ORD',
    ],

    // 결제 설정
    'payment' => [
        // 미입금 주문 자동취소 기한은 관리자 환경설정 order_settings.auto_cancel_days 가 SSoT 다.
        // 여기에 같은 이름의 값을 두면 두 번째 SSoT 가 되어 어느 쪽이 적용되는지 알 수 없어진다.

        // 입금 기한 만료 시 자동 취소 여부
        'auto_cancel_expired' => true,

        // 기본 은행 목록 (수동 무통장입금용)
        'banks' => [
            ['code' => '004', 'name' => '국민은행'],
            ['code' => '011', 'name' => '농협은행'],
            ['code' => '020', 'name' => '우리은행'],
            ['code' => '081', 'name' => '하나은행'],
            ['code' => '088', 'name' => '신한은행'],
            ['code' => '003', 'name' => '기업은행'],
            ['code' => '023', 'name' => 'SC제일은행'],
            ['code' => '027', 'name' => '씨티은행'],
            ['code' => '031', 'name' => '대구은행'],
            ['code' => '032', 'name' => '부산은행'],
            ['code' => '034', 'name' => '광주은행'],
            ['code' => '035', 'name' => '제주은행'],
            ['code' => '037', 'name' => '전북은행'],
            ['code' => '039', 'name' => '경남은행'],
            ['code' => '045', 'name' => '새마을금고'],
            ['code' => '048', 'name' => '신협'],
            ['code' => '071', 'name' => '우체국'],
            ['code' => '089', 'name' => '케이뱅크'],
            ['code' => '090', 'name' => '카카오뱅크'],
            ['code' => '092', 'name' => '토스뱅크'],
        ],
    ],

    // 장바구니 설정
    'cart' => [
        'max_quantity' => 99,
        'guest_cart_lifetime' => 7 * 24 * 60, // 7일 (분 단위)
    ],

    // 리뷰 설정
    'review' => [
        'write_deadline_days' => 90,   // 구매확정 후 리뷰 작성 가능 기간 (일)
        'max_images' => 5,             // 리뷰 이미지 최대 업로드 수
        'max_image_size_mb' => 10,     // 리뷰 이미지 최대 크기 (MB)
    ],

    /*
    |--------------------------------------------------------------------------
    | 환경설정 입력 한계값
    |--------------------------------------------------------------------------
    | 관리자 환경설정 화면의 숫자 입력 경계값(min/max)과 저장 검증 규칙이 공유하는 단일 출처.
    | 규칙(StoreEcommerceSettingsRequest)이 이 값을 읽어 규칙 문자열을 만들고,
    | 화면은 설정 응답의 `_meta.limits` 로 같은 값을 받아 바인딩한다.
    */
    'limits' => [
        // 주문
        'auto_cancel_days_min' => 1,
        'auto_cancel_days_max' => 30,
        'cart_expiry_days_min' => 1,
        'cart_expiry_days_max' => 365,

        // 마일리지 (전역)
        'default_earn_rate_min' => 0,
        'default_earn_rate_max' => 100,
        'earn_delay_days_min' => 0,
        'earn_delay_days_max' => 365,
        'expiry_days_min' => 1,
        'expiry_days_max' => 3650,
        'expiry_notification_days_before_min' => 1,
        'expiry_notification_days_before_max' => 365,

        // 마일리지 (통화별 규칙 행)
        'point_value_min' => 0.001,
        'min_use_amount_min' => 0,
        'use_unit_min' => 1,
        'max_use_percent_min' => 0,
        'max_use_percent_max' => 100,
        'max_use_value_min' => 0,
        'max_use_value_max' => 1000000000,

        // 통화 행
        'decimal_places_min' => 0,
        'decimal_places_max' => 8,

        // 리뷰
        'write_deadline_days_min' => 1,
        'write_deadline_days_max' => 365,
        'max_images_min' => 0,
        'max_images_max' => 20,
        'max_image_size_mb_min' => 1,
        'max_image_size_mb_max' => 50,
    ],

    // 관리자 대시보드 이커머스 영역 설정
    'dashboard' => [
        'scheduler_enabled' => true,   // 판매 현황 집계 스케줄러(hourly) 활성화 여부
        'recent_limit' => 5,           // 최신 리뷰/미답변 문의 카드 표시 건수
        'graph_days' => 7,             // 판매 추세 차트 표시 일수
    ],
];
