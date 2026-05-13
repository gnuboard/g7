<?php

return [
    // 액션 라벨 (마지막 세그먼트 기준).
    // ActivityLog::getActionLabelAttribute 가 모듈 origin 라벨을 자체 lang 에서 우선 조회.
    'action' => [
        'add_to_menu' => '메뉴 추가',
        'blind' => '블라인드',
        'blind_content' => '콘텐츠 블라인드',
        'bulk_apply' => '일괄 적용',
        'bulk_update_status' => '일괄 상태 변경',
        'create' => '생성',
        'delete' => '삭제',
        'delete_content' => '콘텐츠 삭제',
        'restore' => '복원',
        'restore_content' => '콘텐츠 복원',
        'update' => '수정',
        'update_status' => '상태 변경',
        'upload' => '업로드',
    ],

    'description' => [
        // 게시판 관리 (Admin)
        'board_index' => '게시판 목록 조회',
        'board_show' => '게시판 상세 조회 (:board_name)',
        'board_create' => '게시판 생성 (:board_name)',
        'board_update' => '게시판 수정 (:board_name)',
        'board_delete' => '게시판 삭제 (:board_name)',
        'board_copy' => '게시판 복사 (:board_name)',
        'board_add_to_menu' => '게시판 메뉴 추가 (:board_name)',

        // 게시판 유형 (Admin)
        'board_type_index' => '게시판 유형 목록 조회',
        'board_type_create' => '게시판 유형 생성 (:type_name)',
        'board_type_update' => '게시판 유형 수정 (:type_name)',
        'board_type_delete' => '게시판 유형 삭제 (:type_name)',

        // 게시물
        'post_create' => '게시물 작성 (게시판: :board_name, 제목: :title)',
        'post_update' => '게시물 수정 (게시판: :board_name, 제목: :title)',
        'post_delete' => '게시물 삭제 (게시판: :board_name, ID: :post_id)',
        'post_blind' => '게시물 블라인드 (게시판: :board_name, ID: :post_id)',
        'post_restore' => '게시물 복원 (게시판: :board_name, ID: :post_id)',

        // 댓글
        'comment_create' => '댓글 작성 (게시판: :board_name, 게시물: :post_id)',
        'comment_update' => '댓글 수정 (ID: :comment_id)',
        'comment_delete' => '댓글 삭제 (ID: :comment_id)',
        'comment_blind' => '댓글 블라인드 (ID: :comment_id)',
        'comment_restore' => '댓글 복원 (ID: :comment_id)',

        // 첨부파일
        'board_attachment_upload' => '첨부파일 업로드 (게시물: :post_id)',
        'board_attachment_delete' => '첨부파일 삭제 (게시물: :post_id)',
        'board_attachment_reorder' => '첨부파일 순서 변경 (게시물: :post_id)',

        // 신고 관리 (Admin)
        'report_create' => '신고 접수 (ID: :report_id)',
        'report_update_status' => '신고 상태 변경 (ID: :report_id)',
        'report_bulk_update_status' => '신고 일괄 상태 변경 (:count건)',
        'report_delete' => '신고 삭제 (ID: :report_id)',
        'report_restore_content' => '신고 콘텐츠 복원 (ID: :report_id)',
        'report_blind_content' => '신고 콘텐츠 블라인드 (ID: :report_id)',
        'report_delete_content' => '신고 콘텐츠 삭제 (ID: :report_id)',

        // 게시판 설정
        'board_settings_index' => '게시판 설정 조회',
        'board_settings_bulk_apply' => '게시판 설정 일괄 적용',
    ],

    // ChangeDetector 필드 라벨
    'fields' => [
        // Board
        'is_active' => '활성 여부',
        'per_page' => '페이지당 게시물 수',
        'per_page_mobile' => '모바일 페이지당 게시물 수',
        'order_by' => '정렬 기준',
        'order_direction' => '정렬 방향',
        'type' => '게시판 유형',
        'show_view_count' => '조회수 표시',
        'secret_mode' => '비밀글 모드',
        'use_comment' => '댓글 사용',
        'use_reply' => '답글 사용',
        'max_reply_depth' => '최대 답글 깊이',
        'use_report' => '신고 사용',
        'use_file_upload' => '파일 업로드 사용',
        'max_file_size' => '최대 파일 크기',
        'max_file_count' => '최대 파일 수',

        // BoardType
        'slug' => '슬러그',

        // Post
        'category' => '카테고리',
        'title' => '제목',
        'content_mode' => '콘텐츠 모드',
        'is_notice' => '공지사항',
        'is_secret' => '비밀글',
        'status' => '상태',

        // Comment
        'content' => '내용',
    ],
];
