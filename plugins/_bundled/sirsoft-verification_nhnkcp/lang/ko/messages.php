<?php

declare(strict_types=1);

return [
    'title' => 'NHN KCP 휴대폰 본인확인',
    'description' => 'NHN KCP 의 휴대폰 본인확인 서비스를 G7 코어 본인인증 인프라에 연결하는 플러그인입니다.',

    'bridge_page_title' => '본인확인 결과',

    'channels' => [
        'phone' => '휴대폰',
    ],

    'settings' => [
        'test_mode' => '테스트 모드',
        'test_site_cd' => '테스트 사이트코드',
        'test_enc_key' => '테스트 암호화 키',
        'live_site_cd' => '운영 사이트코드 (SM 프리픽스)',
        'live_enc_key' => '운영 암호화 키',
        'web_siteid' => '웹사이트 ID',
        // 검증 에러 메시지의 항목 이름용 짧은 라벨 (화면 라벨의 부가 설명 제외)
        'live_site_cd_attribute' => '운영 사이트코드',
        'live_enc_key_attribute' => '운영 암호화 키',
        'duplicate_field' => '중복체크 기준',
        'duplicate_block_enabled' => [
            'label' => '중복 가입 차단',
            'description' => '활성화 시 본인확인을 통과한 사람이 이전에 다른 이메일로 가입한 적이 있으면 가입을 거부합니다. 가족 휴대폰 공유 또는 B2B 시나리오 등에서 한 사람이 여러 계정을 가입해야 한다면 비활성화하세요. 이 설정과 무관하게 동일 이메일 재가입은 항상 차단됩니다 (코어 기본 동작).',
        ],
    ],

    'card' => [
        'title' => '본인확인 정보',
        'method' => '인증 방식',
        'method_value' => 'NHN KCP 휴대폰 본인확인',
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
            'label' => '성인인증 (NHN KCP 본인확인 전용)',
            'description' => '반드시 NHN KCP provider 로만 매핑하세요. 메일/SMS provider 는 생년월일을 반환하지 않아 성인 여부 판정이 불가능합니다. 잘못 매핑 시 비성인 사용자에게 19금 컨텐츠가 노출될 수 있습니다.',
        ],
    ],
];
