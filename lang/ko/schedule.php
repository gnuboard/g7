<?php

return [
    // 메시지
    'fetch_success' => '스케줄 목록을 조회했습니다.',
    'fetch_failed' => '스케줄 목록 조회에 실패했습니다.',
    'create_success' => '스케줄이 생성되었습니다.',
    'create_failed' => '스케줄 생성에 실패했습니다.',
    'update_success' => '스케줄이 수정되었습니다.',
    'update_failed' => '스케줄 수정에 실패했습니다.',
    'delete_success' => '스케줄이 삭제되었습니다.',
    'delete_failed' => '스케줄 삭제에 실패했습니다.',
    'run_success' => '스케줄이 실행되었습니다.',
    'run_failed' => '스케줄 실행에 실패했습니다.',
    'duplicate_success' => '스케줄이 복제되었습니다.',
    'duplicate_failed' => '스케줄 복제에 실패했습니다.',
    'bulk_status_updated' => '선택한 스케줄의 상태가 변경되었습니다.',
    'bulk_update_status_failed' => '스케줄 상태 일괄 변경에 실패했습니다.',
    'bulk_delete_success' => '선택한 스케줄이 삭제되었습니다.',
    'bulk_delete_failed' => '스케줄 일괄 삭제에 실패했습니다.',
    'statistics_success' => '스케줄 통계를 조회했습니다.',
    'statistics_failed' => '스케줄 통계 조회에 실패했습니다.',
    'history_fetch_success' => '실행 이력을 조회했습니다.',
    'history_fetch_failed' => '실행 이력 조회에 실패했습니다.',
    'history_delete_success' => '실행 이력이 삭제되었습니다.',
    'history_delete_failed' => '실행 이력 삭제에 실패했습니다.',
    'history_not_found' => '실행 이력을 찾을 수 없습니다.',
    'copy' => '복사본',
    'shell_command_failed' => '쉘 명령 실행에 실패했습니다.',
    'http_request_failed' => 'HTTP 요청 실패 (상태: :status)',
    'url_not_public' => '내부 네트워크 주소(사설 IP·localhost 등)는 호출할 수 없습니다. 사내 주소를 호출해야 한다면 환경설정 > 보안에서 "내부 네트워크 주소 호출 허용"을 켜주세요.',
    'shell_not_allowed' => '허용되지 않은 쉘 명령입니다. 서버에 등록된 실행 파일만 스케줄로 실행할 수 있습니다.',
    'artisan_not_allowed' => '이 Artisan 명령은 보안상 스케줄로 실행할 수 없습니다.',

    // 작업 유형
    'type' => [
        'artisan' => 'Artisan 커맨드',
        'shell' => '쉘 명령',
        'url' => 'URL 호출',
    ],

    // 실행 주기
    'frequency' => [
        'everyMinute' => '매분',
        'hourly' => '매시간',
        'daily' => '매일',
        'weekly' => '매주',
        'monthly' => '매월',
        'custom' => '사용자 정의',
    ],

    // 실행 결과
    'result' => [
        'success' => '성공',
        'failed' => '실패',
        'running' => '실행 중',
        'never' => '미실행',
    ],

    // 트리거 유형
    'trigger_type' => [
        'scheduled' => '예약 실행',
        'manual' => '수동 실행',
    ],

    // 소요 시간
    'duration' => [
        'seconds' => '초',
        'minutes' => '분',
    ],

    // 검증 메시지
    'validation' => [
        'page_integer' => '페이지 번호는 정수여야 합니다.',
        'page_min' => '페이지 번호는 1 이상이어야 합니다.',
        'per_page_integer' => '페이지당 항목 수는 정수여야 합니다.',
        'per_page_min' => '페이지당 항목 수는 1 이상이어야 합니다.',
        'per_page_max' => '페이지당 항목 수는 100 이하여야 합니다.',
        'type_invalid' => '유효하지 않은 작업 유형입니다.',
        'frequency_invalid' => '유효하지 않은 실행 주기입니다.',
        'status_invalid' => '유효하지 않은 상태입니다.',
        'last_result_invalid' => '유효하지 않은 실행 결과입니다.',
        'history_status_invalid' => '유효하지 않은 이력 상태입니다.',
        'trigger_type_invalid' => '유효하지 않은 트리거 유형입니다.',
        'created_from_invalid' => '시작일 형식이 올바르지 않습니다.',
        'created_to_invalid' => '종료일 형식이 올바르지 않습니다.',
        'created_to_after_from' => '종료일은 시작일 이후여야 합니다.',
        'started_from_invalid' => '시작일 형식이 올바르지 않습니다.',
        'started_to_invalid' => '종료일 형식이 올바르지 않습니다.',
        'started_to_after_from' => '종료일은 시작일 이후여야 합니다.',
        'sort_by_invalid' => '유효하지 않은 정렬 기준입니다.',
        'sort_order_invalid' => '유효하지 않은 정렬 순서입니다.',
        'name_required' => '작업명은 필수입니다.',
        'name_max' => '작업명은 255자 이하여야 합니다.',
        'type_required' => '작업 유형은 필수입니다.',
        'command_required' => '명령어는 필수입니다.',
        'command_max' => '명령어는 2000자 이하여야 합니다.',
        'expression_required' => 'Cron 표현식은 필수입니다.',
        'expression_max' => 'Cron 표현식은 100자 이하여야 합니다.',
        'frequency_required' => '실행 주기는 필수입니다.',
        'timeout_integer' => '제한 시간은 정수여야 합니다.',
        'timeout_min' => '제한 시간은 1초 이상이어야 합니다.',
        'timeout_max' => '제한 시간은 86400초(24시간) 이하여야 합니다.',
        'ids_required' => 'ID 목록은 필수입니다.',
        'ids_array' => 'ID 목록은 배열이어야 합니다.',
        'ids_min' => '최소 1개 이상의 ID가 필요합니다.',
        'id_integer' => 'ID는 정수여야 합니다.',
        'id_exists' => '존재하지 않는 스케줄입니다.',
        'is_active_required' => '활성화 여부는 필수입니다.',
        'is_active_boolean' => '활성화 여부는 참/거짓이어야 합니다.',
    ],

    // 웹 크론 (예약 작업 주소 호출 — A-5)
    'cron' => [
        'invalid_token' => '호출 번호가 올바르지 않습니다.',
        'ip_not_allowed' => '허용되지 않은 주소에서의 호출입니다.',
        'disabled' => '예약 작업이 서버 크론 전용으로 설정되어 있어 주소 호출로는 실행하지 않습니다.',
        'throttled' => '최소 호출 간격이 지나지 않아 이번 호출은 실행하지 않았습니다. 밀린 작업은 다음 호출에서 함께 처리됩니다.',
        'completed' => '예약 작업 실행을 마쳤습니다.',
        'run_failed' => '예약 작업 실행에 실패했습니다.',
        'token_fetch_success' => '호출 주소를 조회했습니다.',
        'token_fetch_failed' => '호출 주소 조회에 실패했습니다.',
        'token_rotated' => '호출 번호를 재발급했습니다. 기존 주소는 즉시 무효가 됩니다.',
        'token_rotate_failed' => '호출 번호 재발급에 실패했습니다.',
        'console_summary' => '예약 작업 :due건 중 :executed건 실행, :failed건 실패, :skipped건 건너뜀 (:duration ms)',
        'console_truncated' => '실행 시간 상한에 걸려 남은 작업은 다음 호출로 넘겼습니다.',
    ],

    // 대시보드 경고 (예약 작업 미실행 — D19 · D30)
    'alerts' => [
        'not_running_title' => '예약 작업이 :days일째 실행되지 않았습니다',
        'not_running_guidance_web' => '환경설정 > 시스템에서 호출 주소를 복사해 호스팅 관리자 페이지의 예약 실행에 등록해 주세요.',
        'not_running_guidance_cron' => '서버에 등록한 예약 실행(crontab)이 정상 동작하는지 확인해 주세요.',
        'not_running_action' => '예약 작업 설정 열기',
    ],

    // 권한
    'permissions' => [
        'read' => '스케줄 조회',
        'create' => '스케줄 생성',
        'update' => '스케줄 수정',
        'delete' => '스케줄 삭제',
        'run' => '스케줄 실행',
    ],
];
