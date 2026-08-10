<?php

// 주의: 관리자 화면 문구는 여기 두지 않는다 — 템플릿 lang(admin.json)이 SSoT.
// 이 파일은 Artisan 커맨드(g7:asset-url-mode) 출력 전용이다.
return [
    // CLI 조회
    'current' => '현재 자산 URL 방식: :mode',
    'table' => [
        'in_use' => '사용 중',
        'alternative' => '전환 시',
    ],
    'diagnose_title' => '서버가 동적 응답을 가로채는지 확인하려면 아래 두 요청을 비교하세요:',
    'diagnose_hint' => '첫 번째만 실패하고 두 번째가 성공하면 정적 최적화 설정이 가로채는 것입니다 → extensionless 로 전환하세요.',
    'switch_hint' => '전환: php artisan g7:asset-url-mode extensionless',

    // CLI 전환
    'invalid' => '알 수 없는 방식입니다: :mode (extension 또는 extensionless)',
    'already' => '이미 :mode 방식을 사용 중입니다.',
    'switched' => '자산 URL 방식을 :from → :to 로 변경했습니다.',
    'save_failed' => '설정 저장에 실패했습니다.',
    'seo_cache_cleared' => 'SEO 캐시를 함께 비웠습니다 (구운 자산 주소가 옛 방식이므로).',
];
