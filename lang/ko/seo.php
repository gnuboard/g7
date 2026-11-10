<?php

return [
    // 캐시 삭제
    'cache_cleared_all' => 'SEO 캐시가 모두 삭제되었습니다.',
    'cache_cleared_layout' => ':layout 레이아웃의 SEO 캐시 :count건이 삭제되었습니다.',

    // 캐시 워밍업
    'warmup_started' => 'SEO 캐시 워밍업을 시작합니다.',
    'warmup_started_layout' => ':layout 레이아웃의 SEO 캐시 워밍업을 시작합니다.',
    'warmup_dispatched' => 'SEO 캐시 워밍업이 시작되었습니다.',

    // Sitemap 수동 재생성
    'sitemap_regenerated' => 'Sitemap 재생성이 완료되었습니다.',
    'sitemap_regenerate_dispatched' => 'Sitemap 재생성을 시작했습니다. 완료까지 시간이 걸릴 수 있습니다.',
    'sitemap_regenerate_failed' => 'Sitemap 재생성에 실패했습니다.',
    'sitemap_regenerate_in_progress' => 'Sitemap 을 이미 생성하고 있습니다. 끝난 뒤 다시 시도해 주세요.',
    'sitemap_regenerate_locked' => '다른 Sitemap 생성 작업이 대기 중이거나 이전 작업의 잠금이 남아 있습니다. 잠시 뒤 다시 시도하거나, 서버에서 `php artisan cache:clear` 를 실행한 뒤 다시 시도해 주세요.',
    'sitemap_disabled' => 'Sitemap 생성이 비활성화되어 있습니다.',

    // Sitemap 생성/서빙
    'sitemap_not_ready' => 'Sitemap 을 준비하는 중입니다. 잠시 후 다시 시도해주세요.',
    'sitemap_generate_failed' => 'Sitemap 생성에 실패했습니다: :error',

    // 캐시 통계
    'stats_period' => '최근 :days일간 SEO 캐시 통계',
    'stats_overall' => '전체 통계',
    'stats_metric' => '항목',
    'stats_value' => '값',
    'stats_total_entries' => '총 캐시 항목',
    'stats_hits' => '캐시 히트',
    'stats_misses' => '캐시 미스',
    'stats_hit_rate' => '히트율',
    'stats_avg_response_time' => '평균 응답 시간',
    'stats_by_layout' => '레이아웃별 통계',
    'stats_layout_name' => '레이아웃',
    'stats_total' => '합계',
    'stats_by_module' => '모듈별 통계',
    'stats_module_identifier' => '모듈',
    // `seo:generate-sitemap` 명령 출력
    'console' => [
        'generate_sitemap' => [
            'already_running' => '사이트맵을 이미 생성하고 있어 이번 실행은 건너뜁니다.',
            'lock_unavailable' => '사이트맵 생성 잠금 저장소(캐시)를 쓸 수 없어 실행하지 않았습니다.',
            'invalid_mode' => '잘못된 모드입니다. 허용: :allowed',
            'generated' => 'Sitemap이 생성되었습니다. (모드: :mode)',
            'dispatched' => 'Sitemap 생성이 큐에 디스패치되었습니다. (모드: :mode)',
        ],
    ],

    // 관리자 화면 브라우저 탭 제목 접미사 — `{화면 제목}` 뒤에 붙는다({site_name} 은 사이트 이름)
    'admin_title_suffix' => ' - {site_name} 관리자',
];
