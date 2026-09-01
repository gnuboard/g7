<?php

return [
    // VendorMode UI 라벨 (VendorMode::label() 에서 호출)
    'mode' => [
        'auto' => '자동 (권장)',
        'composer' => 'Composer 실행',
        'bundled' => '번들 Vendor 사용',
    ],

    // 인스톨러/빌드 UI 메시지
    'installer' => [
        'checking_bundle' => 'Vendor 번들 검증 중...',
        'extracting_bundle' => 'Vendor 번들 추출 중 ({current}/{total})',
        'running_composer' => 'Composer install 실행 중...',
        'mode_label' => 'Vendor 설치 방식',
        'mode_hint' => 'Composer 사용 불가 환경에서는 번들 모드를 선택하세요.',
    ],

    // 실행 환경 제약 (하드닝 프로필이 실행 함수를 차단한 호스팅)
    'composer_unavailable' => '이 서버에서는 Composer 를 실행할 수 없습니다 (프로세스 실행 함수가 차단되어 있습니다). 확장이 제공하는 vendor 묶음을 사용하거나, SSH 가 가능한 환경에서 실행하세요.',

    // 빌드/검증 진행 메시지
    'build' => [
        'start' => '{target} 빌드 중...',
        'success' => '{target} 빌드 완료 ({size}, {packages} packages)',
        'skipped_no_deps' => '{target} 스킵 (외부 composer 의존성 없음)',
        'skipped_not_installed' => '{target} 스킵 (확장이 설치되지 않음)',
        'up_to_date' => '{target}: up-to-date',
        'stale' => '{target}: STALE — 재빌드 필요',
    ],
];
