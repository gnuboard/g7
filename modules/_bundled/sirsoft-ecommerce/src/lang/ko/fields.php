<?php

/**
 * 이커머스 모듈이 회원 항목 집합(core.user)에 선언하는 항목의 이름과 안내 문구.
 *
 * 선언(`module.php` 의 `getFields()`)이 이 파일을 직접 읽어 다국어 배열을 만든다.
 */
return [
    'user' => [
        'ecommerce_preferred_currency' => [
            'name' => '결제 통화',
            'description' => '주문과 결제에 사용할 통화입니다.',
        ],
        'ecommerce_preferred_shipping_country' => [
            'name' => '배송국가',
            'description' => '기본으로 사용할 배송국가입니다.',
            // 저장된 배송국가가 지금은 배송 가능 국가가 아닐 때 내 정보 수정의 항목 안내 자리에 싣는 문장
            'saved_not_applied' => '저장된 배송국가 :saved: 지금 배송할 수 없는 국가라 적용되지 않습니다. 지금 적용 중인 배송국가: :applied',
            // 해외배송을 끈 동안 운영자 항목 표의 「필수」 칸에 보이는 잠금 사유
            'required_locked' => '해외배송을 사용하지 않는 동안에는 회원에게 배송국가를 묻지 않아 필수가 적용되지 않습니다.',
        ],
    ],

    // 주문서 항목 집합(sirsoft-ecommerce.order_contact)의 이름과 이 모듈이 선언하는 항목
    'order_contact' => [
        'set_label' => '주문서 항목',
        'locked' => '주문과 배송에 꼭 필요한 항목이라 사용과 필수를 바꿀 수 없습니다.',
        'orderer_name' => ['name' => '주문자 이름'],
        'orderer_phone' => ['name' => '주문자 연락처'],
        'orderer_email' => [
            'name' => '주문자 이메일',
            'description' => '비회원 주문은 늘 필수입니다.',
        ],
        'recipient_name' => ['name' => '수령인 이름'],
        'recipient_phone' => ['name' => '수령인 연락처'],
        'address' => ['name' => '배송 주소'],
        'address_detail' => ['name' => '상세 주소'],
        'shipping_memo' => ['name' => '배송 메모'],
    ],
];
