<?php

/**
 * 회원 항목으로 선언하는 동의 항목의 이름과 안내 문구 (Korean)
 *
 * `Fields\MarketingConsentFields` 가 항목 선언(`getFields()`)과 요청 시점 보강에 쓴다.
 * 채널 항목의 이름은 채널 설정의 항목명(`channels.*.label`)을 그대로 쓴다.
 */
return [
    'marketing_consent' => [
        'name' => '마케팅 동의',
        'description' => '마케팅 목적의 개인정보 수집 및 이용에 동의합니다.',
    ],
    'third_party_consent' => [
        'name' => '제3자 제공 동의',
        'description' => '마케팅 목적의 제3자 개인정보 제공에 동의합니다.',
    ],
    'info_disclosure' => [
        'name' => '정보 공개 동의',
        'description' => '프로필 정보를 다른 사용자에게 공개합니다. 미동의 시 일부 커뮤니티 기능 이용이 제한될 수 있습니다.',
    ],
    'terms_link' => [
        'label' => '보기',
        'title' => ':name 약관',
    ],
    'locks' => [
        'enabled' => '이 항목을 쓸지는 마케팅 동의 플러그인 설정에서 정합니다.',
    ],
    'consented_on' => ':date 동의',
];
