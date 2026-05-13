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
        'per_page' => 12,
        'max_images' => 10,
        'max_image_size' => 5 * 1024 * 1024, // 5MB
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    ],

    // 주문 설정
    'order' => [
        'per_page' => 20,
        'order_number_prefix' => 'ORD',
    ],

    // 결제 설정
    'payment' => [
        // PG 가상계좌 입금 기한 (일)
        'vbank_due_days' => 3,

        // 수동 무통장입금 입금 기한 (일)
        'dbank_due_days' => 7,

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

    // 카테고리 설정
    'category' => [
        'image_disk' => 'local',
    ],

    // 브랜드 설정
    'brand' => [
        'image_disk' => 'local',
    ],

    // 리뷰 설정
    'review' => [
        'write_deadline_days' => 90,   // 구매확정 후 리뷰 작성 가능 기간 (일)
        'max_images' => 5,             // 리뷰 이미지 최대 업로드 수
        'max_image_size_mb' => 10,     // 리뷰 이미지 최대 크기 (MB)
    ],
];
