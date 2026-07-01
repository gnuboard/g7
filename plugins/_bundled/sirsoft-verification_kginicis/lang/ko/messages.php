<?php

declare(strict_types=1);

return [
    'title' => 'KG이니시스 본인확인',
    'description' => 'KG이니시스 통합인증의 본인확인 서비스를 G7 코어 본인인증 인프라에 연결하는 플러그인입니다.',

    'channels' => [
        'ipin' => '아이핀',
    ],

    'settings' => [
        'test_mode' => '테스트 모드',
        'live_mid' => '라이브 MID (SRA 프리픽스)',
        'live_api_key' => '라이브 API 키',
        // 검증 에러 메시지의 필드 이름용 짧은 라벨 (화면 라벨의 부가 설명 "(SRA 프리픽스)" 제외)
        'live_mid_attribute' => '라이브 MID',
        'live_api_key_attribute' => '라이브 API 키',
        'duplicate_field' => '동일인 식별 기준',
        'duplicate_block_enabled' => [
            'label' => '중복 가입 차단',
            'description' => '활성화 시 본인인증을 통과한 사람이 이전에 다른 이메일로 가입한 적이 있으면 가입을 거부합니다. 가족 휴대폰 공유 또는 B2B 시나리오 등에서 한 사람이 여러 계정을 가입해야 한다면 비활성화하세요. 이 설정과 무관하게 동일 이메일 재가입은 항상 차단됩니다 (코어 기본 동작).',
        ],
    ],

    'card' => [
        'title' => '본인인증 정보',
        'method' => '인증 방식',
        'method_value' => 'KG이니시스 본인확인',
        'status' => '인증 상태',
        'status_verified' => '✓ 인증됨',
        'verified_at' => '인증 일시',
        'name' => '실명',
        'birthday' => '생년월일',
        'phone' => '휴대폰',
        'is_adult' => [
            'label' => '성인 여부',
            'true' => '성인',
            'false' => '미성년자',
        ],
    ],

    'purposes' => [
        'adult_verification' => [
            'label' => '성인인증 (KG이니시스 본인확인 전용)',
            'description' => '반드시 KG이니시스 provider 로만 매핑하세요. 메일/SMS provider 는 생년월일을 반환하지 않아 성인 여부 판정이 불가능합니다. 잘못 매핑 시 비성인 사용자에게 19금 컨텐츠가 노출될 수 있습니다.',
        ],
    ],
];
