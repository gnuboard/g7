<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enum 다국어 (한국어)
    |--------------------------------------------------------------------------
    */

    // 비밀글 모드
    'secret_mode' => [
        'disabled' => '사용 안함',
        'enabled' => '선택 사용',
        'always' => '필수 사용',
    ],

    // 정렬 방향
    'order_direction' => [
        'asc' => '오름차순',
        'desc' => '내림차순',
    ],

    // 정렬 기준
    'board_order_by' => [
        'created_at' => '생성일',
        'view_count' => '조회수',
        'title' => '제목',
        'author' => '작성자',
    ],

    // 신고 대상 타입
    'report_type' => [
        'post' => '게시글',
        'comment' => '댓글',
    ],

    // 신고 사유 타입
    'report_reason_type' => [
        'abuse' => '욕설/비방',
        'hate_speech' => '혐오 발언',
        'spam' => '스팸/광고',
        'copyright' => '저작권 침해',
        'privacy' => '개인정보 노출',
        'misinformation' => '허위정보',
        'sexual' => '성적인 콘텐츠',
        'violence' => '폭력적인 콘텐츠',
        'other' => '기타',
    ],

    // 신고 상태
    'report_status' => [
        'pending' => '접수',
        'review' => '검토',
        'rejected' => '반려',
        'suspended' => '게시중단',
        'deleted' => '영구삭제',
    ],

    // 조치 주체 (트리거 타입)
    'trigger_type' => [
        'report' => '신고 처리',
        'admin' => '관리자 수동',
        'system' => '시스템',
        'auto_hide' => '자동 블라인드',
        'user' => '사용자 직접 삭제',
    ],

    // 게시글 상태
    'post_status' => [
        'published' => '게시됨',
        'blinded' => '블라인드',
        'deleted' => '삭제됨',
    ],
];
