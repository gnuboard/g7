<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 페이지 메시지
    |--------------------------------------------------------------------------
    */

    // 페이지 작업 메시지
    'page' => [
        'not_found' => '페이지를 찾을 수 없습니다.',
        'fetch_success' => '페이지 정보를 조회했습니다.',
        'fetch_failed' => '페이지 정보 조회에 실패했습니다.',
        'create_success' => '페이지가 생성되었습니다.',
        'create_failed' => '페이지 생성에 실패했습니다.',
        'update_success' => '페이지가 수정되었습니다.',
        'update_failed' => '페이지 수정에 실패했습니다.',
        'delete_success' => '페이지가 삭제되었습니다.',
        'delete_failed' => '페이지 삭제에 실패했습니다.',
        'publish_success' => '페이지 발행 상태가 변경되었습니다.',
        'publish_failed' => '페이지 발행 상태 변경에 실패했습니다.',
        'bulk_publish_success' => ':count개 페이지의 발행 상태가 변경되었습니다.',
        'bulk_publish_failed' => '일괄 발행 상태 변경에 실패했습니다.',
        'restore_success' => '이전 버전으로 복원되었습니다.',
        'restore_failed' => '버전 복원에 실패했습니다.',
        'slug_check_success' => '슬러그 중복 확인이 완료되었습니다.',
    ],

    // 첨부파일 작업 메시지
    'attachment' => [
        'not_found' => '첨부파일을 찾을 수 없습니다.',
        'file_not_found' => '첨부파일이 스토리지에 존재하지 않습니다.',
        'preview_not_supported' => '미리보기를 제공하지 않는 파일 형식입니다.',
        'upload_success' => '첨부파일이 업로드되었습니다.',
        'upload_failed' => '첨부파일 업로드에 실패했습니다.',
        'delete_success' => '첨부파일이 삭제되었습니다.',
        'delete_failed' => '첨부파일 삭제에 실패했습니다.',
        'reorder_success' => '첨부파일 순서가 변경되었습니다.',
        'settings_success' => '첨부 설정을 조회했습니다.',
        'reorder_failed' => '첨부파일 순서 변경에 실패했습니다.',
    ],

    // 오류 메시지 (Exception)
    'errors' => [
        'not_found' => '페이지를 찾을 수 없습니다.',
        'version_not_found' => '해당 버전을 찾을 수 없습니다.',
        'version_belongs_to_different_page' => '지정된 버전이 해당 페이지에 속하지 않습니다.',
        'permission_denied' => '권한이 없습니다.',
        'validation_failed' => '입력값이 올바르지 않습니다.',
        'attachment_limit_exceeded' => '첨부파일은 최대 :limit개까지 등록할 수 있습니다. (요청: :attempted개)',
    ],
    // 콘솔 명령 출력
    'console' => [
        'playwright_seed' => [
            'cli_only' => 'CLI 전용 커맨드입니다.',
            'bypass_required' => 'G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다.',
        ],
        'seed_page' => [
            'done' => '페이지 시드 완료: 미발행=:unpublished / 발행=:published',
        ],
        'prune_temp_attachments' => [
            'retention_disabled' => '임시 첨부 보존기간이 1일 미만이어서 정리를 수행하지 않았습니다.',
            'dry_run' => '보존기간(:days일) 경과 미연결 임시 페이지 첨부: :count건',
            'done' => '보존기간(:days일) 경과 임시 페이지 첨부 :scanned건 중 :deleted건을 삭제했습니다. (실패 :failed건)',
        ],
    ],
    // 페이지 모듈 시더 콘솔 출력
    'seeders' => [
        'activity_log_sample' => [
            'skip_no_admin' => '관리자 사용자가 없어 페이지 활동 로그 시더를 건너뜁니다.',
            'unmarked_kept' => '표식 없는 페이지 활동 로그 :count건은 운영 로그로 보고 남깁니다 (이전 버전 시더가 만든 샘플도 표식이 없어 정리되지 않습니다).',
            'skip_no_pages' => '이번 실행에서 새로 만든 페이지가 없어 페이지 활동 로그를 건너뜁니다.',
            'done' => '페이지 활동 로그 :count건 생성 완료.',
        ],
        'database' => [
            'start' => '페이지 모듈 시더 실행 시작',
            'sample_start' => '페이지 샘플 시더 실행',
            'done' => '페이지 모듈 시더 실행 완료',
        ],
        'page_sample' => [
            'start' => '페이지 시연 샘플 생성 중...',
            'done' => '페이지 시연 샘플 생성 완료: :count개',
            'no_gd' => 'GD 확장이 없어 첨부 이미지를 만들지 않았습니다.',
            'created' => '[:slug] 생성 완료',
            'exists_skip' => '[:slug] 이미 존재합니다. 스킵합니다.',
        ],
        'page' => [
            'start' => '페이지 데이터 생성 중...',
            'exists_skip' => '[:slug] 이미 존재합니다. 스킵합니다.',
            'status' => [
                'published' => '발행',
                'unpublished' => '미발행',
            ],
            'created' => '[:slug] 생성 완료 (:status)',
            'done' => '페이지 데이터 생성 완료: :count개',
        ],
    ],
];
