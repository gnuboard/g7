<?php

return [
    // 소스 타입
    'source_type' => [
        'core' => '코어',
        'module' => '모듈',
        'plugin' => '플러그인',
    ],

    // 권한 타입
    'permission_type' => [
        'read' => '조회/다운로드',
        'update' => '수정',
        'delete' => '삭제',
    ],

    // 기본 정책
    'default_policy' => [
        'public' => '공개 (모든 인증 사용자)',
        'owner_only' => '업로더/관리자만',
        'read_only' => '읽기 전용',
    ],

    // 검증 메시지
    'validation' => [
        'file_required' => '파일을 선택해주세요.',
        'file_invalid' => '유효한 파일이 아닙니다.',
        'file_max' => '파일 크기는 :maxMB를 초과할 수 없습니다.',
        'files_required' => '파일을 선택해주세요.',
        'files_array' => '파일 형식이 올바르지 않습니다.',
        'files_min' => '최소 1개 이상의 파일을 선택해주세요.',
        'type_invalid' => '첨부 대상 타입이 올바르지 않습니다.',
        'id_invalid' => '첨부 대상 ID가 올바르지 않습니다.',
        'role_ids_required' => '역할을 선택해주세요.',
        'role_ids_invalid' => '역할 형식이 올바르지 않습니다.',
        'role_id_integer' => '역할 ID는 정수여야 합니다.',
        'role_id_exists' => '존재하지 않는 역할입니다.',
        'order_required' => '순서 데이터가 필요합니다.',
        'order_array' => '순서 데이터 형식이 올바르지 않습니다.',
        'order_id_required' => '첨부파일 ID가 필요합니다.',
        'order_id_exists' => '존재하지 않는 첨부파일입니다.',
        'order_value_required' => '순서 값이 필요합니다.',
        'order_value_integer' => '순서 값은 정수여야 합니다.',
        'permissions_required' => '권한 정보가 필요합니다.',
        'permissions_invalid' => '권한 형식이 올바르지 않습니다.',
        'permission_roles_invalid' => '권한별 역할 형식이 올바르지 않습니다.',
    ],

    // 응답 메시지
    'upload_success' => '파일이 업로드되었습니다.',
    'upload_batch_success' => '파일이 일괄 업로드되었습니다.',
    'upload_failed' => '파일 업로드에 실패했습니다.',
    'delete_success' => '파일이 삭제되었습니다.',
    'delete_failed' => '파일 삭제에 실패했습니다.',
    'reorder_success' => '파일 순서가 변경되었습니다.',
    'reorder_failed' => '파일 순서 변경에 실패했습니다.',
    'sync_roles_success' => '역할이 동기화되었습니다.',
    'sync_roles_failed' => '역할 동기화에 실패했습니다.',
    'sync_permissions_success' => '권한이 동기화되었습니다.',
    'sync_permissions_failed' => '권한 동기화에 실패했습니다.',
    'not_found' => '첨부파일을 찾을 수 없습니다.',
    'access_denied' => '이 첨부파일에 대한 접근 권한이 없습니다.',
    'update_denied' => '이 첨부파일에 대한 수정 권한이 없습니다.',
    'delete_denied' => '이 첨부파일에 대한 삭제 권한이 없습니다.',
];
