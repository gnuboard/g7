<?php

return [
    // 응답 메시지
    'messages' => [
        'status_loaded' => '큐 상태를 조회했습니다.',
        'pending_loaded' => '대기 작업 목록을 조회했습니다.',
        'failed_loaded' => '실패 작업 목록을 조회했습니다.',
        'failed_detail_loaded' => '실패 작업 상세를 조회했습니다.',
        'failed_not_found' => '실패 작업을 찾을 수 없습니다. 이미 재시도했거나 삭제됐을 수 있습니다.',
        'logs_loaded' => '처리 기록 목록을 조회했습니다.',
        'log_detail_loaded' => '처리 기록 상세를 조회했습니다.',
        'log_not_found' => '처리 기록을 찾을 수 없습니다.',
        'retry_success' => '작업을 다시 큐에 넣었습니다.',
        'retry_failed' => '작업을 다시 큐에 넣지 못했습니다.',
        'retry_no_worker' => '현재 큐 드라이버(sync 등)는 워커가 없어 작업을 다시 넣을 수 없습니다. 환경설정 > 드라이버에서 큐 드라이버와 큐 워커를 확인해 주세요.',
        'retry_bulk_done' => '재시도를 마쳤습니다. 성공 :succeeded건, 실패 :failed건',
        'delete_success' => '실패 작업을 삭제했습니다.',
        'delete_bulk_done' => '삭제를 마쳤습니다. 성공 :succeeded건, 실패 :failed건',
    ],

    // 검증 메시지
    'validation' => [
        'page_invalid' => '페이지 번호가 올바르지 않습니다.',
        'per_page_invalid' => '페이지당 건수는 1~100 사이의 숫자여야 합니다.',
        'search_field_invalid' => '검색 대상이 올바르지 않습니다.',
        'search_keyword_max' => '검색어는 255자 이하여야 합니다.',
        'queue_invalid' => '큐 이름이 올바르지 않습니다.',
        'connection_invalid' => '연결 이름이 올바르지 않습니다.',
        'status_invalid' => '처리 결과가 올바르지 않습니다.',
        'date_invalid' => '날짜 형식이 올바르지 않습니다.',
        'date_range_invalid' => '종료일은 시작일과 같거나 그 이후여야 합니다.',
        'uuids_required' => '처리할 작업을 1건 이상 선택해 주세요.',
        'uuids_max' => '한 번에 최대 :max건까지 처리할 수 있습니다.',
        'uuid_invalid' => '작업 식별자(UUID)가 올바르지 않습니다.',
        'uuid_duplicated' => '같은 작업이 두 번 선택됐습니다.',
        'sort_by_invalid' => '정렬 기준이 올바르지 않습니다.',
        'sort_order_invalid' => '정렬 순서가 올바르지 않습니다.',
    ],

    // 검증 필드 라벨
    'attributes' => [
        'page' => '페이지',
        'per_page' => '페이지당 건수',
        'search_field' => '검색 대상',
        'search_keyword' => '검색어',
        'queue' => '큐',
        'connection' => '연결',
        'status' => '처리 결과',
        'failed_start_date' => '실패 일시 시작일',
        'failed_end_date' => '실패 일시 종료일',
        'created_start_date' => '기록 일시 시작일',
        'created_end_date' => '기록 일시 종료일',
        'uuids' => '선택한 작업',
        'uuid' => '작업 식별자',
        'sort_by' => '정렬 기준',
        'sort_order' => '정렬 순서',
    ],

    // 처리 기록 결과
    'log_status' => [
        'processed' => '성공',
        'failed' => '실패',
    ],

    // 정리 명령 (queue:prune-records)
    'prune' => [
        'description' => '보관 기간이 지난 큐 처리 기록과 실패 작업을 정리합니다.',
        'option_days' => '보관 기간(일, 1~90). 생략하면 환경설정 값(기본 7일)',
        'invalid_days' => '보관 기간은 :min~:max일 사이의 정수여야 합니다.',
        'done' => ':days일 이전 기록을 정리했습니다 — 처리 기록 :logs건, 실패 작업 :failed건',
    ],
];
