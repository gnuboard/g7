<?php

return [
    'description' => [
        // 페이지 관리
        'page_index' => '페이지 목록 조회',
        'page_show' => '페이지 상세 조회 (:title)',
        'page_create' => '페이지 생성 (:title)',
        'page_update' => '페이지 수정 (:title)',
        'page_delete' => '페이지 삭제 (:title)',
        'page_publish' => '페이지 발행 (:title)',
        'page_unpublish' => '페이지 발행 취소 (:title)',
        'page_restore' => '페이지 복원 (:title)',

        // 페이지 첨부파일
        'page_attachment_upload' => '페이지 첨부파일 업로드 (페이지: :title)',
        'page_attachment_delete' => '페이지 첨부파일 삭제 (페이지: :title)',
        'page_attachment_reorder' => '페이지 첨부파일 순서 변경 (페이지: :title)',
    ],

    // ChangeDetector 필드 라벨
    'fields' => [
        'slug' => '슬러그',
        'content_mode' => '콘텐츠 모드',
        'published' => '발행 여부',
        'published_at' => '발행일',
    ],
];
