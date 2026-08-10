<?php

/**
 * 통합 검색 관련 다국어 메시지 (한국어)
 */

return [
    'empty_keyword' => '검색어를 입력해주세요.',
    'results_found' => ':count건의 검색 결과를 찾았습니다.',
    'results_found_at_least' => ':count건 이상의 검색 결과를 찾았습니다.',
    'result_cap_notice' => '일치하는 항목이 :cap건을 넘어 총 건수를 정확히 세지 않았습니다. 다음 페이지로 계속 이동할 수 있습니다.',
    'refine_query_hint' => '검색어를 더 구체적으로 입력하면 정확한 건수와 마지막 페이지를 볼 수 있습니다.',
    'no_results' => '검색 결과가 없습니다.',
    'view_more' => '더보기',

    // 검증 메시지
    'validation' => [
        'q_min' => '검색어는 2글자 이상 입력해주세요.',
        'q_max' => '검색어는 최대 200자까지 입력 가능합니다.',
        'page_integer' => '페이지 번호는 숫자여야 합니다.',
        'page_min' => '페이지 번호는 1 이상이어야 합니다.',
        'page_max' => '페이지 번호는 :max 이하여야 합니다. 검색어를 더 구체적으로 입력해 주세요.',
        'per_page_integer' => '페이지당 항목 수는 숫자여야 합니다.',
        'per_page_min' => '페이지당 항목 수는 1 이상이어야 합니다.',
        'per_page_max' => '페이지당 항목 수는 최대 100개까지 가능합니다.',
    ],

    // 검색 인덱스 점검·재생성 (search:index) — 엔진 중립
    'index' => [
        'status' => [
            'healthy' => '정상',
            'degraded' => '부분',
            'stale' => '색인 누락',
            'skipped' => '판정 불가',
        ],

        'no_maintainer' => '현재 검색 엔진(:driver)은 인덱스 점검을 제공하지 않습니다. 엔진 제공자가 점검 기능을 등록하면 이 화면에서 함께 다룹니다.',
        'unavailable' => '현재 검색 엔진(:driver)의 인덱스를 점검할 수 없습니다.',
        'no_targets' => '검색 엔진(:driver)에 점검할 인덱스가 없습니다.',
        'driver_label' => '검색 엔진: :driver',

        'col' => [
            'index' => '인덱스',
            'status' => '판정',
            'measurement' => '측정',
        ],

        'counts' => '정상 :healthy · 부분 :degraded · 색인누락 :stale · 판정불가 :skipped  (총 :total)',
        'rebuild_targets' => '재생성 대상 :count개',
        'rebuild_cost_warning' => '재생성 중에는 대상 인덱스가 잠기거나 재색인됩니다. 운영 중인 사이트에서는 유지보수 시간에 수행하세요.',
        'rebuild_confirm' => '위 인덱스를 재생성할까요?',
        'rebuild_after_bulk_confirm' => '일괄 업데이트 후 검색 인덱스를 재생성할까요? (기본: 아니오 — 운영 중이면 유지보수 시간에 별도로 수행하세요)',
        'rebuild_skipped' => '재생성을 건너뜁니다.',
        'stale_hint' => '색인이 누락된 인덱스가 있습니다. `php artisan search:index --repair` 로 재생성하세요.',
        'stale_after_update' => '색인이 누락된 검색 인덱스 :count개가 있어 해당 검색이 결과를 돌려주지 않습니다.',
        'rebuilt_item' => '재생성: :index',
        'rebuild_failed_item' => '재생성 실패: :index — :error',
        'still_stale_item' => '재생성 후에도 색인 누락: :index',
        'degraded_hint' => '「부분」 은 엔진의 토큰 처리 특성일 수 있어 자동 재생성 대상이 아닙니다. 필요하면 개별 확인하세요.',

        'report' => [
            'nothing_to_do' => '점검 :count개 · 재생성 대상 없음',
            'rebuilt' => '점검 :inspected개 · 재생성 :repaired개 · 실패 :failed개 · 잔존 :remaining개',
        ],

        // mysql-fulltext 드라이버 전용 문구
        'fulltext' => [
            'unsupported_driver' => '현재 DB 드라이버는 FULLTEXT 를 지원하지 않습니다 (LIKE 검색으로 동작하며 점검 대상이 없습니다).',
            'self_match' => ':found/:probed 행이 자기 자신을 찾음',
            'skip' => [
                'no_single_pk' => '단일 컬럼 기본키가 없어 행 단위 판정을 할 수 없습니다',
                'no_rows' => '색인 컬럼에 내용이 있는 행이 없습니다',
                'no_tokens' => '표본 행에서 검색 토큰을 만들 수 없습니다 (내용이 너무 짧음)',
            ],
        ],
    ],
];
