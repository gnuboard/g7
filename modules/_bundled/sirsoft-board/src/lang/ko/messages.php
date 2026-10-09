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
        'fetch_failed' => '게시판 목록 조회에 실패했습니다.',
        'create_success' => '게시판이 생성되었습니다.',
        'update_success' => '게시판이 수정되었습니다.',
        'delete_success' => '게시판이 삭제되었습니다.',
        'copy_success' => '게시판이 복사되었습니다.',
        'bulk_members_success' => '게시판 :count개의 명단을 변경했습니다. (제외 :skipped개, 변화 없음 :unchanged개)',
        'bulk_members_failed' => '게시판 관리자·스텝 일괄 지정에 실패했습니다.',
        'config_retrieved' => '게시판 설정을 조회했습니다.',
        'types_retrieved' => '게시판 타입 목록을 조회했습니다.',
        'form_data_retrieved' => '게시판 폼 데이터를 조회했습니다.',
        'form_data_failed' => '게시판 폼 데이터 조회에 실패했습니다.',
        'copy_data_retrieved' => '게시판 복사 데이터를 조회했습니다.',
        'create_failed' => '게시판 생성에 실패했습니다.',
        'update_failed' => '게시판 수정에 실패했습니다.',
        'delete_failed' => '게시판 삭제에 실패했습니다.',
        'table_creation_failed' => '게시판 테이블 생성에 실패했습니다.',
        'validation_failed' => '입력값 검증에 실패했습니다.',
        'menu_added_success' => '관리자 메뉴에 추가되었습니다.',
        'menu_add_failed' => '메뉴 추가에 실패했습니다.',
        'menu_already_exists' => '이미 추가된 메뉴입니다.',
        'menu_name_suffix' => '게시판',
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
        'secret_post_title' => '비밀글',
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
        'blind_deleted_not_allowed' => '삭제된 게시글은 블라인드할 수 없습니다. 먼저 복구한 뒤 블라인드해 주세요.',
        'restore_success' => '게시글이 복원되었습니다.',
        'restore_failed' => '게시글 복원에 실패했습니다.',
        'blinded_post_access_denied' => '블라인드 처리된 게시글입니다.',
        'deleted_post_access_denied' => '삭제된 게시글입니다.',
        'secret_post_access_denied' => '비밀글 열람 권한이 없습니다.',
        'guest_read_not_allowed' => '이 게시판은 비회원 읽기가 허용되지 않습니다.',
        'guest_write_not_allowed' => '이 게시판은 비회원 글쓰기가 허용되지 않습니다.',
        'file_upload_not_allowed' => '이 게시판은 파일 업로드가 허용되지 않습니다.',
        'guest_upload_not_allowed' => '이 게시판은 비회원 파일 업로드가 허용되지 않습니다.',
        'secret_post_not_allowed' => '이 게시판은 비밀글을 사용할 수 없습니다.',
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
        'blind_deleted_not_allowed' => '삭제된 댓글은 블라인드할 수 없습니다. 먼저 복구한 뒤 블라인드해 주세요.',
        'restore_parent_deleted' => '원글이 삭제된 상태라 댓글만 복구할 수 없습니다. 원글을 먼저 복구해 주세요.',
        'restore_success' => '댓글이 복원되었습니다.',
        'restore_failed' => '댓글 복원에 실패했습니다.',
        'blinded_comment_content' => '운영원칙에 따라 숨김 처리된 댓글입니다.',
        'deleted_comment_content' => '삭제된 댓글입니다.',
        // 비회원 댓글 비밀번호 검증
        'not_guest_comment' => '비회원 댓글이 아닙니다.',
        'invalid_password' => '비밀번호가 일치하지 않습니다.',
        'password_verified' => '비밀번호가 확인되었습니다.',
        'verify_password_failed' => '비밀번호 검증에 실패했습니다.',
        // 댓글 작성 가능 여부
        'post_blinded' => '블라인드 처리된 게시글에는 댓글을 작성할 수 없습니다.',
        'post_deleted' => '삭제된 게시글에는 댓글을 작성할 수 없습니다.',
        'post_secret' => '열람 권한이 없는 비밀글에는 댓글을 작성할 수 없습니다.',
    ],

    // 반응(추천/비추천) 관련 메시지
    'reaction' => [
        'list_success' => '반응 유형 목록을 조회했습니다.',
        'add_success' => '반응을 남겼습니다.',
        'change_success' => '반응을 변경했습니다.',
        'remove_success' => '반응을 취소했습니다.',
        'failed' => '반응 처리에 실패했습니다.',
        'disabled' => '이 게시판은 반응 기능이 비활성화되어 있습니다.',
        'inactive_type' => '이 게시판에서 사용할 수 없는 반응 유형입니다.',
        'self_post' => '본인 글에는 반응할 수 없습니다.',
        'secret_denied' => '열람 권한이 없는 비밀글에는 반응할 수 없습니다.',
        'login_required' => '로그인이 필요한 기능입니다.',
        'target_unavailable' => '삭제되었거나 숨김 처리된 대상에는 반응할 수 없습니다.',
        'self_comment' => '본인 댓글에는 반응할 수 없습니다.',
        'retrieved' => '반응 목록을 조회했습니다.',
        'retrieve_failed' => '반응 목록 조회에 실패했습니다.',
        'removed' => '반응을 해제했습니다.',
        'bulk_removed' => ':count개의 반응을 해제했습니다.',
        'not_found' => '반응을 찾을 수 없습니다.',
        'delete_failed' => '반응 해제에 실패했습니다.',
        'admin' => [
            'retrieved' => '반응 목록을 조회했습니다.',
            'retrieve_failed' => '반응 목록 조회에 실패했습니다.',
            'removed' => '반응을 삭제했습니다.',
            'bulk_removed' => ':count개의 반응을 삭제했습니다.',
            'bulk_delete_failed' => '반응 일괄 삭제에 실패했습니다.',
            'permission_denied' => '반응 관리 권한이 없습니다.',
        ],
    ],

    'reaction_type' => [
        'in_use' => '이 유형에 :count건의 반응이 있어 삭제할 수 없습니다 (:name). 비활성화만 가능합니다.',
        'code_taken' => '이미 사용 중인 코드입니다.',
        'icon_upload_success' => '아이콘을 업로드했습니다.',
        'icon_upload_failed' => '아이콘 업로드에 실패했습니다.',
        'icon_deleted' => '아이콘을 삭제했습니다.',
        'icon_delete_failed' => '아이콘 삭제에 실패했습니다.',
        'icon_not_found' => '아이콘을 찾을 수 없습니다.',
    ],

    'report' => [
        'my_retrieved' => '신고 내역을 조회했습니다.',
        'my_retrieve_failed' => '신고 내역 조회에 실패했습니다.',
        'cancelled' => '신고를 취소했습니다.',
        'cancel_failed' => '신고 취소에 실패했습니다.',
        'cancel_not_allowed' => '접수 상태에서만 취소할 수 있습니다 (현재: :status).',
        'log_not_found' => '신고 내역을 찾을 수 없습니다.',
    ],

    // 댓글 관련 추가 메시지
    'comments' => [
        'comments_disabled' => '이 게시판은 댓글 기능이 비활성화되어 있습니다.',
        'index_success' => '댓글 목록을 조회했습니다.',
        'index_failed' => '댓글 목록 조회에 실패했습니다.',
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
        'notice_denied' => '게시글을 공지로 지정할 권한이 없습니다.',

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
        'attachment_limit_exceeded' => '첨부파일은 최대 :limit개까지 등록할 수 있습니다. (요청: :attempted개)',
        'post_not_found' => '게시글을 찾을 수 없습니다.',
        'permission_denied' => '권한이 없습니다.',
        'validation_failed' => '입력값이 올바르지 않습니다.',
        'duplicate_report' => '이미 신고한 내역이 있습니다.',
        'operation_failed' => '작업 처리 중 오류가 발생했습니다.',
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
        'bulk_status_updated_with_count' => ':count개 신고를 「:status」 상태로 변경했습니다.',
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

    // 스크랩(북마크) 작업 메시지
    'scrap' => [
        'added' => '게시글을 스크랩했습니다.',
        'removed' => '스크랩을 해제했습니다.',
        'toggle_failed' => '스크랩 처리에 실패했습니다.',
        'retrieved' => '스크랩 목록을 조회했습니다.',
        'retrieve_failed' => '스크랩 목록 조회에 실패했습니다.',
        'delete_failed' => '스크랩 삭제에 실패했습니다.',
        'not_found' => '스크랩을 찾을 수 없습니다.',
        'scrap_disabled' => '이 게시판은 스크랩 기능이 비활성화되어 있습니다.',
        'post_not_found' => '게시글을 찾을 수 없습니다.',
        'bulk_removed' => ':count개의 스크랩을 해제했습니다.',
        'admin' => [
            'retrieved' => '스크랩 목록을 조회했습니다.',
            'retrieve_failed' => '스크랩 목록 조회에 실패했습니다.',
            'removed' => '스크랩을 삭제했습니다.',
            'bulk_removed' => ':count개의 스크랩을 삭제했습니다.',
            'bulk_delete_failed' => '스크랩 일괄 삭제에 실패했습니다.',
            'permission_denied' => '스크랩 관리 권한이 없습니다.',
        ],
    ],

    // 게시글 일괄 처리 메시지
    'posts_bulk' => [
        'delete_success' => ':count개의 게시글을 삭제했습니다. (건너뜀 :skipped개)',
        'blind_success' => ':count개의 게시글을 블라인드 처리했습니다. (건너뜀 :skipped개)',
        'restore_success' => ':count개의 게시글을 복구했습니다. (건너뜀 :skipped개)',
        'action_failed' => '게시글 일괄 처리에 실패했습니다.',
        // 건너뛴 항목의 사유 — 일괄 처리 결과 창이 글마다 그대로 보인다
        'skip_reasons' => [
            'already_deleted' => '이미 삭제된 글',
            'has_replies' => '답글이 달린 글 (답글 삭제 정책상 삭제할 수 없음)',
            'already_blinded' => '이미 블라인드된 글',
            'already_published' => '이미 게시 상태인 글',
            'not_found' => '이 게시판에서 찾을 수 없는 글',
            'unknown_action' => '알 수 없는 처리',
            'error' => '처리 중 오류',
        ],
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

    // 게시판 환경설정 관련 메시지
    //
    // BoardSettingsController 가 ResponseHelper::moduleSuccess/moduleError 로 참조한다.
    // 프런트 언어팩(resources/lang/partial/*/messages.json)에만 있으면 __() 가 해석하지 못해
    // API 응답 message 에 원문 키가 그대로 나간다 (실패 경로는 레이아웃이 {{error.message}} 를
    // 그대로 노출하므로 사용자가 키를 보게 된다).
    'settings' => [
        'fetch_success' => '설정을 조회했습니다.',
        'fetch_failed' => '설정 조회에 실패했습니다.',
        'save_success' => '설정이 저장되었습니다.',
        'save_failed' => '설정 저장에 실패했습니다.',
        'save_error' => '설정 저장 중 오류가 발생했습니다.',
        'bulk_apply_success' => ':count개 게시판에 설정이 일괄 적용되었습니다.',
        'bulk_apply_aborted' => '일괄 적용 중 오류가 발생하여 모든 변경을 되돌렸습니다.',
        'bulk_apply_failed' => '설정 일괄 적용에 실패했습니다.',
        'clear_cache_success' => '설정 캐시가 초기화되었습니다.',
        'clear_cache_error' => '설정 캐시 초기화 중 오류가 발생했습니다.',
    ],

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
        // 처리자가 시스템(자동 처리)인 경우의 표시명
        'system' => '시스템',
        'status' => [
            'published' => '게시중',
            'blinded' => '블라인드',
            'deleted' => '삭제됨',
        ],
        'blind_type' => [
            'auto' => '자동',
            'manual' => '수동',
        ],
        // FormatsBoardDate 표시 컨벤션 — 게시글/댓글/대시보드 캡션 등에서 공통 사용
        'relative_time' => [
            'just_now' => '방금 전',
            'minutes_ago' => ':count분 전',
            'hours_ago' => ':count시간 전',
            'days_ago' => ':count일 전',
            'months_ago' => ':count개월 전',
            'years_ago' => ':count년 전',
        ],
    ],
    'inquiry' => [
        'default_title' => '상품 문의',
        // 이커머스 문의 연동 훅(sirsoft-ecommerce.inquiry.*)에 답할 때 게시판이 던지는 실패 사유
        'board_changed' => '게시판 설정이 변경되어 해당 게시글을 찾을 수 없습니다. 관리자에게 문의하세요.',
        'update_failed' => '문의 수정에 실패했습니다.',
        'delete_failed' => '문의 삭제에 실패했습니다.',
        'reply_not_found' => '답변을 찾을 수 없습니다.',
        'reply_update_failed' => '답변 수정에 실패했습니다.',
        'reply_delete_failed' => '답변 삭제에 실패했습니다.',
    ],
    // 개인정보 보유기간 처리 (이슈 #636) — 코어 보유기간 커맨드·미리보기가 핸들러 이름으로 쓴다
    'privacy_retention' => [
        'target_columns' => [
            'title' => '제목',
            'author' => '작성자',
            'board' => '게시판 · 구분',
            'created_at' => '작성일',
        ],
        'target_kinds' => [
            'post' => '글',
            'comment' => '댓글',
        ],
        'handlers' => [
            'ip' => '게시판 작성 IP',
            'guest' => '게시판 비회원 작성자 정보',
        ],
    ],
    'dashboard' => [
        'fetch_success' => '대시보드 데이터를 조회했습니다.',
    ],
    // 알림 수신자 규칙 「관련 사용자」의 관계 이름 — 알림 설정 화면이 관계 키 대신 보인다.
    'notification_relations' => [
        'post_author' => '게시글 작성자',
        'parent_comment_author' => '원 댓글 작성자',
        'original_post_author' => '원글 작성자',
        'board_managers' => '게시판 관리자',
    ],
    // 알림 템플릿 편집 창 「사용 가능한 변수」 설명 — common 은 변수 키별 기본, by_type 은 그 알림에서만 뜻이 다른 변수. 없는 키는 선언 문자열을 그대로 쓴다.
    'notification_variables' => [
        'common' => [
            'name' => '수신자 이름',
            'app_name' => '사이트명',
            'board_name' => '게시판 이름',
            'post_title' => '게시글 제목',
            'comment_author' => '댓글 작성자',
            'comment_content' => '댓글 내용 (200자)',
            'post_url' => '게시글 URL',
            'site_url' => '사이트 URL',
            'action_type' => '처리 유형 (블라인드/삭제/복원)',
            'target_type' => '처리 대상 (게시글/댓글)',
            'post_author' => '게시글 작성자',
            'reason_type' => '신고 사유',
            'report_url' => '신고 관리 페이지 URL',
        ],
        'by_type' => [
            'reply_comment' => [
                'comment_author' => '답글 작성자',
                'comment_content' => '답글 내용 (200자)',
            ],
            'new_post_admin' => [
                'name' => '수신자 이름 (관리자)',
            ],
            'report_received_admin' => [
                'post_title' => '신고 대상 게시글 제목',
                'target_type' => '신고 대상 유형 (게시글/댓글)',
            ],
        ],
    ],
];
