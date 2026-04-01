<?php

return [
    // 목록 조회
    'fetch_success' => '메일 발송 이력을 조회했습니다.',
    'fetch_failed' => '메일 발송 이력 조회에 실패했습니다.',

    // 통계
    'stats_success' => '메일 발송 통계를 조회했습니다.',
    'stats_failed' => '메일 발송 통계 조회에 실패했습니다.',

    // 삭제
    'delete_success' => '메일 발송 이력이 삭제되었습니다.',
    'bulk_delete_success' => '선택한 메일 발송 이력이 삭제되었습니다.',
    'delete_failed' => '메일 발송 이력 삭제에 실패했습니다.',

    // 검증
    'validation' => [
        'status_invalid' => '유효하지 않은 발송 상태입니다.',
        'date_range_invalid' => '종료일은 시작일 이후여야 합니다.',
        'per_page_min' => '페이지당 항목 수는 1 이상이어야 합니다.',
        'per_page_max' => '페이지당 항목 수는 100 이하여야 합니다.',
        'ids_required' => '삭제할 항목을 선택해주세요.',
        'ids_min' => '삭제할 항목을 1개 이상 선택해주세요.',
        'id_not_found' => '존재하지 않는 발송 이력입니다.',
    ],
];
