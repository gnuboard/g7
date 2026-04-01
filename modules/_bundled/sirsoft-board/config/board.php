<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 첨부파일 저장 설정
    |--------------------------------------------------------------------------
    */
    'attachment' => [
        'disk' => env('SIRSOFT_BOARD_ATTACHMENT_DISK', 'modules'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 게시판별 권한 정의 (name, description)
    |--------------------------------------------------------------------------
    | 게시판별 동적 권한 생성 시 사용되는 권한 메타데이터입니다.
    | BoardPermissionService에서 permissions 테이블에 레코드 생성 시 사용됩니다.
    |
    | 권한 구조:
    | - admin.* : 관리자 권한 (type: admin, identifier: sirsoft-board.{slug}.admin.*)
    | - 일반 키 : 사용자 권한 (type: user, identifier: sirsoft-board.{slug}.*)
    |--------------------------------------------------------------------------
    */
    'board_permission_definitions' => [
        // ========================================
        // 관리자 권한 (type: admin)
        // ========================================
        'admin.posts.read' => [
            'name' => ['ko' => '게시글 조회 (관리자)', 'en' => 'View Posts (Admin)'],
            'description' => ['ko' => '관리자 페이지에서 게시글 조회', 'en' => 'View posts in admin page'],
            'scope' => ['resource_route_key' => 'post', 'owner_key' => 'user_id'],
        ],
        'admin.posts.write' => [
            'name' => ['ko' => '게시글 작성/수정/삭제 (관리자)', 'en' => 'Create/Edit/Delete Posts (Admin)'],
            'description' => ['ko' => '관리자 페이지에서 게시글 작성 및 수정/삭제', 'en' => 'Create and edit/delete posts in admin page'],
        ],
        'admin.posts.read-secret' => [
            'name' => ['ko' => '비밀글 조회 (관리자)', 'en' => 'View Secret Posts (Admin)'],
            'description' => ['ko' => '관리자 페이지에서 비밀글 조회', 'en' => 'View secret posts in admin page'],
            'scope' => ['resource_route_key' => 'post', 'owner_key' => 'user_id'],
        ],
        'admin.comments.read' => [
            'name' => ['ko' => '댓글 조회 (관리자)', 'en' => 'View Comments (Admin)'],
            'description' => ['ko' => '관리자 페이지에서 댓글 조회', 'en' => 'View comments in admin page'],
            'scope' => ['resource_route_key' => 'comment', 'owner_key' => 'user_id'],
        ],
        'admin.comments.write' => [
            'name' => ['ko' => '댓글 작성/수정/삭제 (관리자)', 'en' => 'Create/Edit/Delete Comments (Admin)'],
            'description' => ['ko' => '관리자 페이지에서 댓글 작성 및 수정/삭제', 'en' => 'Create and edit/delete comments in admin page'],
        ],
        'admin.attachments.upload' => [
            'name' => ['ko' => '파일 업로드/삭제 (관리자)', 'en' => 'Upload/Delete Files (Admin)'],
            'description' => ['ko' => '관리자 페이지에서 파일 업로드 및 삭제', 'en' => 'Upload and delete files in admin page'],
            'scope' => ['resource_route_key' => 'attachment', 'owner_key' => 'created_by'],
        ],
        'admin.attachments.download' => [
            'name' => ['ko' => '파일 다운로드 (관리자)', 'en' => 'Download Files (Admin)'],
            'description' => ['ko' => '관리자 페이지에서 파일 다운로드', 'en' => 'Download files in admin page'],
            'scope' => ['resource_route_key' => 'attachment', 'owner_key' => 'created_by'],
        ],
        'admin.manage' => [
            'name' => ['ko' => '게시판 관리 (관리자)', 'en' => 'Manage Board (Admin)'],
            'description' => ['ko' => '타인 게시글/댓글 관리, 블라인드, 복원, 공지 설정', 'en' => 'Manage others posts/comments, blind, restore, set notice'],
            'scope' => ['resource_route_key' => 'board', 'owner_key' => 'created_by'],
        ],

        // ========================================
        // 사용자 권한 (type: user)
        // ========================================
        'posts.read' => [
            'name' => ['ko' => '게시글 조회', 'en' => 'View Posts'],
            'description' => ['ko' => '게시글 목록 및 상세 조회', 'en' => 'View post list and details'],
            'scope' => ['resource_route_key' => 'post', 'owner_key' => 'user_id'],
        ],
        'posts.write' => [
            'name' => ['ko' => '게시글 작성/수정/삭제', 'en' => 'Create/Edit/Delete Posts'],
            'description' => ['ko' => '게시글 작성 및 본인 글 수정/삭제', 'en' => 'Create posts and edit/delete own posts'],
        ],
        'posts.read-secret' => [
            'name' => ['ko' => '비밀글 조회', 'en' => 'View Secret Posts'],
            'description' => ['ko' => '비밀글 조회', 'en' => 'View secret posts'],
            'scope' => ['resource_route_key' => 'post', 'owner_key' => 'user_id'],
        ],
        'comments.read' => [
            'name' => ['ko' => '댓글 조회', 'en' => 'View Comments'],
            'description' => ['ko' => '댓글 목록 조회', 'en' => 'View comment list'],
            'scope' => ['resource_route_key' => 'comment', 'owner_key' => 'user_id'],
        ],
        'comments.write' => [
            'name' => ['ko' => '댓글 작성/수정/삭제', 'en' => 'Create/Edit/Delete Comments'],
            'description' => ['ko' => '댓글 작성 및 본인 댓글 수정/삭제', 'en' => 'Create comments and edit/delete own comments'],
        ],
        'attachments.upload' => [
            'name' => ['ko' => '파일 업로드/삭제', 'en' => 'Upload/Delete Files'],
            'description' => ['ko' => '게시글에 파일 첨부 및 삭제', 'en' => 'Attach and delete files to posts'],
            'scope' => ['resource_route_key' => 'attachment', 'owner_key' => 'created_by'],
        ],
        'attachments.download' => [
            'name' => ['ko' => '파일 다운로드', 'en' => 'Download Files'],
            'description' => ['ko' => '첨부파일 다운로드', 'en' => 'Download attached files'],
            'scope' => ['resource_route_key' => 'attachment', 'owner_key' => 'created_by'],
        ],
        'manager' => [
            'name' => ['ko' => '게시판 관리 (사용자)', 'en' => 'Manage Board (User)'],
            'description' => ['ko' => '사용자 페이지에서 게시판 관리', 'en' => 'Manage board in user page'],
            'scope' => ['resource_route_key' => 'board', 'owner_key' => 'created_by'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 제한값
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'per_page_min' => 5,
        'per_page_max' => 100,

        // 제목 길이 제한 (VARCHAR(200))
        'min_title_length_min' => 0,
        'min_title_length_max' => 200,
        'max_title_length_min' => 1,
        'max_title_length_max' => 200,

        // 내용 길이 제한 (LONGTEXT)
        'min_content_length_min' => 0,
        'min_content_length_max' => 10000,
        'max_content_length_min' => 1,
        'max_content_length_max' => 50000,

        // 댓글 길이 제한 (TEXT)
        'min_comment_length_min' => 0,
        'min_comment_length_max' => 1000,
        'max_comment_length_min' => 1,
        'max_comment_length_max' => 1000,

        // 파일 업로드 제한 (MB 단위)
        'max_file_size_min' => 1,
        'max_file_size_max' => 200,
        'max_file_count_min' => 1,
        'max_file_count_max' => 20,

        'category_max' => 50,

        // 답글 깊이 제한
        'max_reply_depth_min' => 1,
        'max_reply_depth_max' => 10,

        // 대댓글 깊이 제한
        'max_comment_depth_min' => 0,
        'max_comment_depth_max' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | 캐시 설정
    |--------------------------------------------------------------------------
    | 게시판 통계, 최근 게시글, 인기 게시판 등에 적용되는 캐시 설정입니다.
    */
    'cache' => [
        'enabled' => env('BOARD_CACHE_ENABLED', true),
        'ttl' => env('BOARD_CACHE_TTL', 60),  // 통계/최근글/인기게시판 모두 적용 (초 단위)
    ],

    /*
    |--------------------------------------------------------------------------
    | 성능 제한
    |--------------------------------------------------------------------------
    | 대용량 게시판 환경에서의 성능 최적화를 위한 설정입니다.
    */
    'performance' => [
        'max_union_boards' => 50,  // UNION 쿼리 최대 게시판 수
    ],
];
