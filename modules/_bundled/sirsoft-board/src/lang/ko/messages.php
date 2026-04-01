<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 게시판 메시지
    |--------------------------------------------------------------------------
    */

    // 게시판 작업 메시지
    'boards' => [
        'not_found' => '게시판을 찾을 수 없습니다.',
        'already_exists' => '이미 존재하는 게시판 슬러그입니다.',
        'has_posts' => '게시글이 있는 게시판은 삭제할 수 없습니다.',
        'fetch_success' => '게시판 정보를 조회했습니다.',
        'create_success' => '게시판이 생성되었습니다.',
        'update_success' => '게시판이 수정되었습니다.',
        'delete_success' => '게시판이 삭제되었습니다.',
        'copy_success' => '게시판이 복사되었습니다.',
        'config_retrieved' => '게시판 설정을 조회했습니다.',
        'types_retrieved' => '게시판 타입 목록을 조회했습니다.',
        'form_data_retrieved' => '게시판 폼 데이터를 조회했습니다.',
        'copy_data_retrieved' => '게시판 복사 데이터를 조회했습니다.',
        'create_failed' => '게시판 생성에 실패했습니다.',
        'update_failed' => '게시판 수정에 실패했습니다.',
        'delete_failed' => '게시판 삭제에 실패했습니다.',
        'table_creation_failed' => '게시판 테이블 생성에 실패했습니다.',
        'validation_failed' => '입력값 검증에 실패했습니다.',
        'menu_added_success' => '관리자 메뉴에 추가되었습니다.',
        'menu_add_failed' => '메뉴 추가에 실패했습니다.',
        'menu_already_exists' => '이미 추가된 메뉴입니다.',
        'module_not_found' => '게시판 모듈을 찾을 수 없습니다.',
        'error_404' => '게시판을 찾을 수 없습니다: :slug',
    ],

    // 게시글 작업 메시지
    'post' => [
        'not_found' => '게시글을 찾을 수 없습니다.',
        'permission_denied' => '게시글에 대한 권한이 없습니다.',
        'secret_password_required' => '비밀글 비밀번호가 필요합니다.',
        'secret_password_incorrect' => '비밀글 비밀번호가 일치하지 않습니다.',
        // 비밀글 필터링 메시지
        'secret_post_content' => '비밀글입니다. 내용을 보려면 비밀번호를 입력해 주세요.',
        'deleted_post_title' => '삭제된 게시글',
        'deleted_post_content' => '삭제된 게시글입니다.',
        'blinded_post_content' => '관리자에 의해 블라인드 처리된 게시글입니다.',
        // 게시글 타입 라벨
        'notice' => '공지',
        'reply' => '답변',
        // 작업 액션 라벨 (action_logs용)
        'action' => [
            'blind' => '블라인드 처리',
            'delete' => '삭제',
            'restore' => '복원',
        ],
    ],

    // 게시글 관리 메시지 (관리자)
    'posts' => [
        'fetch_success' => '게시글 목록을 조회했습니다.',
        'fetch_failed' => '게시글 목록 조회에 실패했습니다.',
        'form_data_retrieved' => '게시글 폼 데이터를 조회했습니다.',
        'form_data_failed' => '게시글 폼 데이터 조회에 실패했습니다.',
        'form_meta_retrieved' => '게시글 폼 메타 데이터를 조회했습니다.',
        'form_meta_failed' => '게시글 폼 메타 데이터 조회에 실패했습니다.',
        'create_success' => '게시글이 등록되었습니다.',
        'create_failed' => '게시글 등록에 실패했습니다.',
        'update_success' => '게시글이 수정되었습니다.',
        'update_failed' => '게시글 수정에 실패했습니다.',
        'delete_success' => '게시글이 삭제되었습니다.',
        'delete_failed' => '게시글 삭제에 실패했습니다.',
        'blind_success' => '게시글이 블라인드 처리되었습니다.',
        'blind_failed' => '게시글 블라인드 처리에 실패했습니다.',
        'restore_success' => '게시글이 복원되었습니다.',
        'restore_failed' => '게시글 복원에 실패했습니다.',
        'blinded_post_access_denied' => '블라인드 처리된 게시글입니다.',
        'deleted_post_access_denied' => '삭제된 게시글입니다.',
        'secret_post_access_denied' => '비밀글 열람 권한이 없습니다.',
        'guest_read_not_allowed' => '이 게시판은 비회원 읽기가 허용되지 않습니다.',
        'guest_write_not_allowed' => '이 게시판은 비회원 글쓰기가 허용되지 않습니다.',
        'file_upload_not_allowed' => '이 게시판은 파일 업로드가 허용되지 않습니다.',
        'guest_upload_not_allowed' => '이 게시판은 비회원 파일 업로드가 허용되지 않습니다.',
        'modify_permission_denied' => '게시글 수정 권한이 없습니다.',
        'delete_permission_denied' => '게시글 삭제 권한이 없습니다.',
        'error_404' => '존재하지 않는 게시글입니다.',
        // 비밀번호 검증 메시지
        'password_verified' => '비밀번호가 확인되었습니다.',
        'password_verify_failed' => '비밀번호 검증에 실패했습니다.',
        'password_required' => '비밀번호를 입력해 주세요.',
        'password_incorrect' => '비밀번호가 일치하지 않습니다.',
        'password_verify_not_allowed' => '회원 게시글은 비밀번호 검증을 사용할 수 없습니다.',
        'no_password_set' => '비밀번호가 설정되지 않은 게시글입니다.',
    ],

    // 댓글 작업 메시지
    'comment' => [
        'not_found' => '댓글을 찾을 수 없습니다.',
        'permission_denied' => '댓글에 대한 권한이 없습니다.',
        'create_success' => '댓글이 등록되었습니다.',
        'create_failed' => '댓글 등록에 실패했습니다.',
        'update_success' => '댓글이 수정되었습니다.',
        'update_failed' => '댓글 수정에 실패했습니다.',
        'delete_success' => '댓글이 삭제되었습니다.',
        'delete_failed' => '댓글 삭제에 실패했습니다.',
        'update_forbidden' => '댓글 수정 권한이 없습니다.',
        'delete_forbidden' => '댓글 삭제 권한이 없습니다.',
        'blind_success' => '댓글이 블라인드 처리되었습니다.',
        'blind_failed' => '댓글 블라인드 처리에 실패했습니다.',
        'restore_success' => '댓글이 복원되었습니다.',
        'restore_failed' => '댓글 복원에 실패했습니다.',
        'blinded_comment_content' => '운영원칙에 따라 숨김 처리된 댓글입니다.',
    ],

    // 댓글 관련 추가 메시지
    'comments' => [
        'comments_disabled' => '이 게시판은 댓글 기능이 비활성화되어 있습니다.',
    ],

    // 첨부파일 작업 메시지
    'attachment' => [
        'uploaded' => '파일이 업로드되었습니다.',
        'upload_success' => '파일이 업로드되었습니다.',
        'upload_failed' => '파일 업로드에 실패했습니다.',
        'upload_disabled' => '이 게시판에서는 파일 업로드가 비활성화되어 있습니다.',
        'deleted' => '파일이 삭제되었습니다.',
        'delete_success' => '파일이 삭제되었습니다.',
        'delete_failed' => '파일 삭제에 실패했습니다.',
        'delete_forbidden' => '파일 삭제 권한이 없습니다.',
        'not_found' => '파일을 찾을 수 없습니다.',
        'file_not_found' => '파일을 찾을 수 없습니다.',
        'download_started' => '파일 다운로드가 시작되었습니다.',
        'download_failed' => '파일 다운로드에 실패했습니다.',
        'permission_denied' => '파일에 대한 권한이 없습니다.',
        'not_image' => '이미지 파일만 미리보기가 가능합니다.',
        'preview_failed' => '이미지 미리보기에 실패했습니다.',
        'reorder_success' => '첨부파일 순서가 변경되었습니다.',
        'reorder_failed' => '첨부파일 순서 변경에 실패했습니다.',
    ],

    // 권한 메시지 (Role-Permission 시스템)
    'permission' => [
        // 일반 권한 메시지
        'denied' => '접근 권한이 없습니다.',
        'insufficient_permission' => '권한이 부족합니다.',
        'login_required' => '로그인이 필요한 기능입니다.',

        // 게시글 권한
        'posts_read_denied' => '게시글 조회 권한이 없습니다.',
        'posts_write_denied' => '게시글 작성 권한이 없습니다.',
        'posts_update_denied' => '게시글 수정 권한이 없습니다.',
        'posts_delete_denied' => '게시글 삭제 권한이 없습니다.',
        'posts_manage_others_denied' => '타인의 게시글을 관리할 권한이 없습니다.',
        'posts_secret_read_denied' => '비밀글 조회 권한이 없습니다.',

        // 댓글 권한
        'comments_read_denied' => '댓글 조회 권한이 없습니다.',
        'comments_write_denied' => '댓글 작성 권한이 없습니다.',
        'comments_update_denied' => '댓글 수정 권한이 없습니다.',
        'comments_delete_denied' => '댓글 삭제 권한이 없습니다.',
        'comments_manage_others_denied' => '타인의 댓글을 관리할 권한이 없습니다.',

        // 첨부파일 권한
        'attachments_upload_denied' => '파일 업로드 권한이 없습니다.',
        'attachments_download_denied' => '파일 다운로드 권한이 없습니다.',

        // 게시판 관리 권한 (모듈 레벨)
        'boards_read_denied' => '게시판 조회 권한이 없습니다.',
        'boards_create_denied' => '게시판 생성 권한이 없습니다.',
        'boards_update_denied' => '게시판 수정 권한이 없습니다.',
        'boards_delete_denied' => '게시판 삭제 권한이 없습니다.',

        // 신고 관리 권한 (모듈 레벨)
        'reports_view_denied' => '신고 조회 권한이 없습니다.',
        'reports_manage_denied' => '신고 관리 권한이 없습니다.',
    ],

    // 확인 메시지
    'confirm' => [
        'delete_board' => '정말로 이 게시판을 삭제하시겠습니까? 이 작업은 되돌릴 수 없습니다.',
        'delete_post' => '정말로 이 게시글을 삭제하시겠습니까?',
        'delete_comment' => '정말로 이 댓글을 삭제하시겠습니까?',
        'delete_attachment' => '정말로 이 파일을 삭제하시겠습니까?',
    ],

    // 안내 메시지
    'info' => [
        'no_posts' => '게시글이 없습니다.',
        'no_comments' => '댓글이 없습니다.',
        'no_attachments' => '첨부파일이 없습니다.',
        'login_required' => '로그인이 필요합니다.',
        'secret_post' => '비밀글입니다.',
        'deleted_post' => '삭제된 게시글입니다.',
        'deleted_comment' => '삭제된 댓글입니다.',
    ],

    // 오류 메시지
    'error' => [
        'unexpected' => '예기치 못한 오류가 발생했습니다.',
        'table_creation_failed' => '게시판 테이블 생성에 실패했습니다.',
        'table_deletion_failed' => '게시판 테이블 삭제에 실패했습니다.',
    ],

    // 예외 메시지 (Exception)
    'errors' => [
        'table_creation_failed' => '게시판 테이블 생성에 실패했습니다 (:table): :error',
        'category_in_use' => ':category 카테고리는 :count개의 게시글에서 사용 중입니다.',
        'board_not_found' => '게시판을 찾을 수 없습니다.',
        'post_not_found' => '게시글을 찾을 수 없습니다.',
        'permission_denied' => '권한이 없습니다.',
        'validation_failed' => '입력값이 올바르지 않습니다.',
        'duplicate_report' => '이미 신고한 내역이 있습니다.',
    ],

    // 경고 메시지 (Warning)
    'warnings' => [
        'category_removal_attempted' => '사용 중인 카테고리 제거 시도가 감지되었습니다.',
    ],

    // 신고 작업 메시지
    'reports' => [
        'permission_denied' => '신고 조회 권한이 없습니다.',
        'fetch_success' => '신고 목록을 조회했습니다.',
        'fetch_failed' => '신고 목록 조회에 실패했습니다.',
        'show_success' => '신고 정보를 조회했습니다.',
        'show_failed' => '신고 정보 조회에 실패했습니다.',
        'not_found' => '신고를 찾을 수 없습니다.',
        'no_reports_selected' => '선택된 신고가 없습니다.',
        'status_counts_success' => '상태별 건수를 조회했습니다.',
        'status_counts_failed' => '상태별 건수 조회에 실패했습니다.',
        'status_updated' => '신고 상태가 변경되었습니다.',
        'status_update_failed' => '신고 상태 변경에 실패했습니다.',
        'bulk_status_updated' => '선택한 신고의 상태가 변경되었습니다.',
        'bulk_status_updated_with_count' => ':count개 신고를 :status(으)로 변경했습니다.',
        'manual_blind_restored_notice' => '수동 블라인드 처리된 :count건이 함께 복구되었습니다.',
        'bulk_status_update_failed' => '대량 상태 변경에 실패했습니다.',
        'bulk_status_change_blocked' => '일부 신고는 상태 변경이 불가능합니다.',
        'delete_failed' => '신고 삭제에 실패했습니다.',
        'delete_success' => '신고가 삭제되었습니다.',
        'create_failed' => '신고 접수에 실패했습니다.',
        'create_success' => '신고가 접수되었습니다.',
        'duplicate_report' => '이미 신고한 내역이 있습니다.',
        'report_disabled' => '이 게시판은 신고 기능이 비활성화되어 있습니다.',
        'target_not_reportable' => '신고할 수 없는 대상입니다.',
        'cannot_report_own' => '본인이 작성한 글은 신고할 수 없습니다.',
        'post_not_found' => '게시글을 찾을 수 없습니다.',
        'comment_not_found' => '댓글을 찾을 수 없습니다.',
        'error_404' => '신고를 찾을 수 없습니다.',
        'cannot_change_to_same_status' => '이미 해당 상태입니다.',
        'invalid_status_transition_with_labels' => ':from 상태에서 :to 상태로 변경할 수 없습니다.',
        'cannot_change_deleted_status' => '영구삭제된 신고는 상태를 변경할 수 없습니다.',
        // 신고 취소 (Undo) 메시지
        'undo_success' => '상태 변경이 취소되었습니다.',
        'undo_failed' => '상태 변경 취소에 실패했습니다.',
        'no_history_to_undo' => '취소할 처리 이력이 없습니다.',
        'already_undone' => '이미 취소된 처리입니다.',
        'undo_reason' => ':from 상태에서 :to 상태로 복원',
        // 신고 사유 포맷
        'reason_count_format' => '{reason} {count}건',
        'reason_others_format' => '외 {count}건',
        // 신고 처리로 인한 콘텐츠 상태 변경 사유
        'restore_by_report' => '신고 처리로 인한 복구',
        'blind_by_report' => '신고 처리로 인한 게시 중단',
        'blind_by_auto_hide' => '신고 누적으로 자동 블라인드 처리되었습니다.',
        // 모달 안내 메시지
        'blinded_will_be_public' => '블라인드 처리된 게시물 {count}건이 공개로 전환됩니다.',
        'already_reported' => '이미 신고한 게시물입니다.',
    ],

    // 게시판 유형 작업 메시지
    'board_type' => [
        'list' => '게시판 유형 목록을 조회했습니다.',
        'created' => '게시판 유형이 생성되었습니다.',
        'updated' => '게시판 유형이 수정되었습니다.',
        'deleted' => '게시판 유형이 삭제되었습니다.',
        'not_found' => '게시판 유형을 찾을 수 없습니다.',
        'delete_in_use' => ':count개 게시판에서 사용 중인 유형은 삭제할 수 없습니다.',
        'delete_is_default' => '기본값으로 설정된 유형은 삭제할 수 없습니다.',
    ],

    // 사용자 활동 메시지
    'user_activities' => [
        'fetch_success' => '내 활동 게시글을 조회했습니다.',
        'fetch_failed' => '내 활동 게시글 조회에 실패했습니다.',
        'stats_success' => '활동 통계를 조회했습니다.',
        'stats_failed' => '활동 통계 조회에 실패했습니다.',
    ],

    // 메일 템플릿 관리 메시지
    'mail_template_fetch_success' => '메일 템플릿 목록을 조회했습니다.',
    'mail_template_fetch_failed' => '메일 템플릿 목록 조회에 실패했습니다.',
    'mail_template_save_success' => '메일 템플릿이 저장되었습니다.',
    'mail_template_save_error' => '메일 템플릿 저장에 실패했습니다.',
    'mail_template_toggle_success' => '메일 템플릿 상태가 변경되었습니다.',
    'mail_template_toggle_failed' => '메일 템플릿 상태 변경에 실패했습니다.',
    'mail_template_reset_success' => '메일 템플릿이 기본값으로 복원되었습니다.',
    'mail_template_reset_failed' => '메일 템플릿 복원에 실패했습니다.',
    'mail_template_reset_no_default' => '기본 템플릿 데이터를 찾을 수 없습니다.',
    'mail_template_preview_success' => '메일 템플릿 미리보기가 생성되었습니다.',
    'mail_template_preview_failed' => '메일 템플릿 미리보기 생성에 실패했습니다.',

    // 테이블 관련 메시지
    'invalid_slug_format' => '슬러그 형식이 올바르지 않습니다. 소문자로 시작하고 소문자, 숫자, 하이픈만 사용할 수 있습니다. (슬러그: :slug)',
    'invalid_slug_length' => '슬러그 길이가 올바르지 않습니다. 1-50자 이내로 입력해주세요. (슬러그: :slug)',
    'table_already_exists' => '테이블이 이미 존재합니다. (테이블: :table)',
    'posts_table_not_found' => '게시글 테이블을 찾을 수 없습니다. (테이블: :table)',
    'table_creation_failed' => '테이블 생성에 실패했습니다. (테이블: :table, 오류: :error)',
    'table_drop_failed' => '테이블 삭제에 실패했습니다. (슬러그: :slug, 오류: :error)',

    // 공통
    'common' => [
        'guest' => '비회원',
        'status' => [
            'published' => '게시중',
            'blinded' => '블라인드',
            'deleted' => '삭제됨',
        ],
        'blind_type' => [
            'auto' => '자동',
            'manual' => '수동',
        ],
    ],
    'inquiry' => [
        'default_title' => '상품 문의',
    ],
];
