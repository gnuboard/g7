<?php

return [
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
        'board_type_show' => '게시판 유형 상세 조회 (:type_name)',
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
];
