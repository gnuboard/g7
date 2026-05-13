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
        'upload_success' => '첨부파일이 업로드되었습니다.',
        'upload_failed' => '첨부파일 업로드에 실패했습니다.',
        'delete_success' => '첨부파일이 삭제되었습니다.',
        'delete_failed' => '첨부파일 삭제에 실패했습니다.',
        'reorder_success' => '첨부파일 순서가 변경되었습니다.',
        'reorder_failed' => '첨부파일 순서 변경에 실패했습니다.',
    ],

    // 오류 메시지 (Exception)
    'errors' => [
        'not_found' => '페이지를 찾을 수 없습니다.',
        'version_not_found' => '해당 버전을 찾을 수 없습니다.',
        'version_belongs_to_different_page' => '지정된 버전이 해당 페이지에 속하지 않습니다.',
        'permission_denied' => '권한이 없습니다.',
        'validation_failed' => '입력값이 올바르지 않습니다.',
    ],
];
