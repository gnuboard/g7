<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 게시판 검증 메시지
    |--------------------------------------------------------------------------
    */

    // 일괄 적용 fields 검증 메시지
    'fields_invalid' => '선택한 :value 필드는 허용되지 않습니다.',

    // slug 검증 메시지
    'slug' => [
        'required' => '게시판 슬러그는 필수입니다.',
        'format' => '게시판 슬러그는 영문 소문자로 시작하고 영문 소문자, 숫자, 하이픈(-)만 사용할 수 있습니다.',
        'unique' => '이미 사용 중인 게시판 슬러그입니다.',
        'reserved' => ':value는 예약된 슬러그입니다. 다른 이름을 사용해주세요.',
        'max' => '게시판 슬러그는 :max자를 초과할 수 없습니다.',
    ],

    // name 검증 메시지
    'name' => [
        'required' => '게시판명은 필수입니다.',
        'string' => '게시판명은 문자열이어야 합니다.',
        'max' => '게시판명은 :max자를 초과할 수 없습니다.',
    ],

    // type 검증 메시지
    'type' => [
        'required' => '게시판 유형은 필수입니다.',
    ],

    // 목록 설정 검증 메시지
    'per_page' => [
        'required' => '페이지당 게시글 수는 필수입니다.',
        'min' => '페이지당 게시글 수는 최소 :min개 이상이어야 합니다.',
        'max' => '페이지당 게시글 수는 최대 :max개를 초과할 수 없습니다.',
    ],
    'per_page_mobile' => [
        'required' => '모바일 페이지당 게시글 수는 필수입니다.',
        'min' => '모바일 페이지당 게시글 수는 최소 :min개 이상이어야 합니다.',
        'max' => '모바일 페이지당 게시글 수는 최대 :max개를 초과할 수 없습니다.',
    ],

    // 정렬 설정 검증 메시지
    'order_by' => [
        'required' => '정렬 기준은 필수입니다.',
        'in' => '정렬 기준은 created_at, view_count, title, author 중 하나여야 합니다.',
    ],
    'order_direction' => [
        'required' => '정렬 방향은 필수입니다.',
        'in' => '정렬 방향은 ASC 또는 DESC만 사용할 수 있습니다.',
    ],

    // 분류 검증 메시지
    'categories' => [
        'array' => '분류는 배열 형식이어야 합니다.',
        'item_max' => '분류명은 :max자를 초과할 수 없습니다.',
    ],

    // 기능 설정 검증 메시지
    'show_view_count' => [
        'required' => '조회수 표시 여부는 필수입니다.',
    ],
    'secret_mode' => [
        'required' => '비밀글 모드는 필수입니다.',
        'in' => '비밀글 모드는 disabled, enabled, always 중 하나여야 합니다.',
    ],
    'use_comment' => [
        'required' => '댓글 사용 여부는 필수입니다.',
    ],
    'use_reply' => [
        'required' => '답글 사용 여부는 필수입니다.',
    ],
    'use_report' => [
        'required' => '신고 기능 사용 여부는 필수입니다.',
    ],

    // 제목 길이 제한 검증 메시지
    'min_title_length' => [
        'min' => '최소 제목 글자 수는 :min자 이상이어야 합니다.',
        'max' => '최소 제목 글자 수는 :max자를 초과할 수 없습니다.',
    ],
    'max_title_length' => [
        'min' => '최대 제목 글자 수는 :min자 이상이어야 합니다.',
        'max' => '최대 제목 글자 수는 :max자를 초과할 수 없습니다.',
    ],

    // 내용 길이 제한 검증 메시지
    'min_content_length' => [
        'min' => '최소 게시글 글자 수는 :min자 이상이어야 합니다.',
        'max' => '최소 게시글 글자 수는 :max자를 초과할 수 없습니다.',
    ],
    'max_content_length' => [
        'min' => '최대 게시글 글자 수는 :min자 이상이어야 합니다.',
        'max' => '최대 게시글 글자 수는 :max자를 초과할 수 없습니다.',
    ],

    // 댓글 길이 제한 검증 메시지
    'min_comment_length' => [
        'min' => '최소 댓글 글자 수는 :min자 이상이어야 합니다.',
        'max' => '최소 댓글 글자 수는 :max자를 초과할 수 없습니다.',
    ],
    'max_comment_length' => [
        'min' => '최대 댓글 글자 수는 :min자 이상이어야 합니다.',
        'max' => '최대 댓글 글자 수는 :max자를 초과할 수 없습니다.',
    ],

    // 파일 업로드 검증 메시지
    'use_file_upload' => [
        'required' => '파일 업로드 사용 여부는 필수입니다.',
    ],
    'max_file_size' => [
        'min' => '최대 파일 크기는 최소 :min MB 이상이어야 합니다.',
        'max' => '최대 파일 크기는 :max MB를 초과할 수 없습니다.',
    ],
    'max_file_count' => [
        'min' => '최대 파일 개수는 최소 :min개 이상이어야 합니다.',
        'max' => '최대 파일 개수는 :max개를 초과할 수 없습니다.',
    ],

    // 권한 설정 검증 메시지
    'permissions' => [
        'required' => '권한 설정은 필수입니다.',
        'roles_required' => '각 권한에 최소 하나 이상의 역할이 필요합니다. 다음 권한에 역할을 설정해주세요: :permissions',
        'roles' => [
            'required' => '권한에 역할을 선택해주세요.',
            'min' => '권한에 최소 하나 이상의 역할을 선택해주세요.',
            'exists' => '존재하지 않는 역할입니다.',
        ],
    ],

    // 답글/대댓글 깊이 검증 메시지
    'max_reply_depth' => [
        'min' => '답변글 최대 깊이는 최소 :min 이상이어야 합니다.',
        'max' => '답변글 최대 깊이는 최대 :max까지 설정 가능합니다.',
    ],
    'max_comment_depth' => [
        'min' => '대댓글 최대 깊이는 최소 :min 이상이어야 합니다.',
        'max' => '대댓글 최대 깊이는 최대 :max까지 설정 가능합니다.',
    ],

    // 알림 설정 검증 메시지
    'notify_admin_on_post' => [
        'required' => '게시글 작성 시 관리자 알림 여부는 필수입니다.',
    ],
    'notify_author' => [
        'required' => '작성자 이메일 알림 여부는 필수입니다.',
    ],
    'notify_author_channels' => [
        'array' => '작성자 알림 채널은 배열이어야 합니다.',
    ],
    'notify_admin_on_post_channels' => [
        'array' => '관리자 알림 채널은 배열이어야 합니다.',
    ],

    // 보안 설정 검증 메시지
    'blocked_keywords' => [
        'string' => '금지어 목록은 문자열이어야 합니다.',
        'max' => '금지어 목록은 :max자를 초과할 수 없습니다.',
    ],

    // 쿨다운 검증 메시지
    'cooldown_required' => ':time 후에 다시 작성할 수 있습니다.',
    'cooldown_required_report' => '신고는 :time 간격으로 가능합니다. 잠시 후 다시 시도해주세요.',
    'cooldown_duration' => [
        'seconds' => ':seconds초',
        'minutes' => ':minutes분',
        'minutes_seconds' => ':minutes분 :seconds초',
        'hours' => ':hours시간',
        'hours_minutes' => ':hours시간 :minutes분',
    ],

    // 게시판 검증 (하위 호환성 유지)
    'board' => [
        'name' => [
            'required' => '게시판명은 필수입니다.',
            'string' => '게시판명은 문자열이어야 합니다.',
            'max' => '게시판명은 :max자를 초과할 수 없습니다.',
        ],
        'slug' => [
            'required' => '게시판 슬러그는 필수입니다.',
            'string' => '게시판 슬러그는 문자열이어야 합니다.',
            'max' => '게시판 슬러그는 :max자를 초과할 수 없습니다.',
            'alpha_dash' => '게시판 슬러그는 영문, 숫자, 대시(-), 언더스코어(_)만 사용할 수 있습니다.',
            'unique' => '이미 사용 중인 게시판 슬러그입니다.',
            'regex' => '게시판 슬러그는 영문으로 시작해야 합니다.',
        ],
        'type' => [
            'required' => '게시판 유형은 필수입니다.',
            'in' => '유효하지 않은 게시판 유형입니다.',
        ],
        'description' => [
            'string' => '게시판 설명은 문자열이어야 합니다.',
            'max' => '게시판 설명은 :max자를 초과할 수 없습니다.',
        ],
        'per_page' => [
            'integer' => '페이지당 게시글 수는 정수여야 합니다.',
            'min' => '페이지당 게시글 수는 최소 :min개 이상이어야 합니다.',
            'max' => '페이지당 게시글 수는 최대 :max개를 초과할 수 없습니다.',
        ],
        'per_page_mobile' => [
            'integer' => '모바일 페이지당 게시글 수는 정수여야 합니다.',
            'min' => '모바일 페이지당 게시글 수는 최소 :min개 이상이어야 합니다.',
            'max' => '모바일 페이지당 게시글 수는 최대 :max개를 초과할 수 없습니다.',
        ],
        'secret_mode' => [
            'in' => '유효하지 않은 비밀글 모드입니다.',
        ],
    ],

    // 게시글 검증
    'post' => [
        'title' => [
            'required' => '제목은 필수입니다.',
            'string' => '제목은 문자열이어야 합니다.',
            'min' => '제목은 최소 :min자 이상이어야 합니다.',
            'max' => '제목은 :max자를 초과할 수 없습니다.',
        ],
        'content' => [
            'required' => '내용은 필수입니다.',
            'string' => '내용은 문자열이어야 합니다.',
            'min' => '내용은 최소 :min자 이상이어야 합니다.',
            'max' => '내용은 :max자를 초과할 수 없습니다.',
        ],
        'category' => [
            'max' => '분류는 :max자를 초과할 수 없습니다.',
        ],
        'category_id' => [
            'exists' => '존재하지 않는 카테고리입니다.',
        ],
        'is_secret' => [
            'boolean' => '비밀글 여부는 참/거짓 값이어야 합니다.',
        ],
        'secret_password' => [
            'required_if' => '비밀글 비밀번호는 필수입니다.',
            'string' => '비밀글 비밀번호는 문자열이어야 합니다.',
            'min' => '비밀글 비밀번호는 최소 :min자 이상이어야 합니다.',
            'max' => '비밀글 비밀번호는 :max자를 초과할 수 없습니다.',
        ],
        'parent_id' => [
            'exists' => '존재하지 않는 원글입니다.',
            'not_found' => '원글을 찾을 수 없습니다.',
            'blinded' => '블라인드 처리된 게시글에는 답글을 작성할 수 없습니다.',
            'deleted' => '삭제된 게시글에는 답글을 작성할 수 없습니다.',
            'depth_exceeded' => '이 게시판은 답글을 :max단계까지만 허용합니다.',
            'notice_not_allowed' => '공지사항에는 답글을 작성할 수 없습니다.',
        ],
        'reply_not_allowed' => '이 게시판은 답글 기능이 비활성화되어 있습니다.',
        'status' => [
            'in' => '유효하지 않은 게시글 상태입니다.',
        ],
        'user_id' => [
            'exists' => '존재하지 않는 사용자입니다.',
        ],
        'author_name' => [
            'required' => '비회원은 작성자명을 입력해야 합니다.',
            'max' => '작성자명은 :max자를 초과할 수 없습니다.',
        ],
        'password' => [
            'required' => '비회원은 비밀번호를 입력해야 합니다.',
            'min' => '비밀번호는 최소 :min자 이상이어야 합니다.',
        ],
        'is_notice' => [
            'guest_not_allowed' => '비회원은 공지사항을 작성할 수 없습니다.',
        ],
        'blocked_keyword' => '금지어 ":keyword"가 포함되어 있습니다.',
        'files' => [
            'array' => '첨부파일은 배열 형식이어야 합니다.',
            'max' => '최대 :max개의 파일만 업로드할 수 있습니다.',
            'file' => '유효한 파일이 아닙니다.',
            'file_max' => '파일 크기가 허용된 크기를 초과했습니다.',
            'mimes' => '허용되지 않은 파일 형식입니다.',
        ],
    ],

    // 필드 속성명 (attributes)
    'attributes' => [
        'settings' => [
            // basic_defaults
            'basic_defaults.type' => '게시판 유형',
            'basic_defaults.per_page' => '페이지당 게시글 수',
            'basic_defaults.per_page_mobile' => '모바일 페이지당 게시글 수',
            'basic_defaults.order_by' => '정렬 기준',
            'basic_defaults.order_direction' => '정렬 방향',
            'basic_defaults.secret_mode' => '비밀글 모드',
            'basic_defaults.use_comment' => '댓글 사용 여부',
            'basic_defaults.use_reply' => '답글 사용 여부',
            'basic_defaults.max_reply_depth' => '최대 답글 깊이',
            'basic_defaults.max_comment_depth' => '최대 댓글 깊이',
            'basic_defaults.comment_order' => '댓글 정렬',
            'basic_defaults.show_view_count' => '조회수 표시',
            'basic_defaults.use_report' => '신고 기능 사용',
            'basic_defaults.min_title_length' => '최소 제목 길이',
            'basic_defaults.max_title_length' => '최대 제목 길이',
            'basic_defaults.min_content_length' => '최소 내용 길이',
            'basic_defaults.max_content_length' => '최대 내용 길이',
            'basic_defaults.min_comment_length' => '최소 댓글 길이',
            'basic_defaults.max_comment_length' => '최대 댓글 길이',
            'basic_defaults.use_file_upload' => '파일 업로드 사용',
            'basic_defaults.max_file_size' => '최대 파일 크기',
            'basic_defaults.max_file_count' => '최대 파일 개수',
            'basic_defaults.allowed_extensions' => '허용 파일 확장자',
            'basic_defaults.notify_admin_on_post' => '게시글 작성 시 관리자 알림',
            'basic_defaults.notify_author' => '작성자 알림',
            'basic_defaults.new_display_hours' => '신규 표시 시간',
            'basic_defaults.default_board_permissions' => '기본 게시판 권한',
            // report_policy
            'report_policy.auto_hide_threshold' => '자동 숨김 신고 수',
            'report_policy.auto_hide_target' => '자동 숨김 대상',
            'report_policy.daily_report_limit' => '일일 신고 한도',
            'report_policy.rejection_limit_count' => '신고 기각 한도',
            'report_policy.rejection_limit_days' => '신고 기각 기간',
            // spam_security
            'spam_security.blocked_keywords' => '금지 키워드',
            'spam_security.post_cooldown_seconds' => '게시글 작성 쿨다운(초)',
            'spam_security.comment_cooldown_seconds' => '댓글 작성 쿨다운(초)',
            'spam_security.report_cooldown_seconds' => '신고 쿨다운(초)',
            'spam_security.view_count_cache_ttl' => '조회수 캐시 유효시간(초)',
            // notification_channels
            'notification_channels.notify_admin_on_post_channels' => '관리자 알림 채널',
            'notification_channels.notify_author_channels' => '작성자 알림 채널',
        ],
        'post' => [
            'title' => '제목',
            'content' => '내용',
            'category' => '분류',
            'is_notice' => '공지사항',
            'is_secret' => '비밀글',
            'content_mode' => '내용 모드',
            'status' => '상태',
            'user_id' => '사용자 ID',
            'author_name' => '작성자명',
            'password' => '비밀번호',
            'parent_id' => '원글',
            'files' => '첨부파일',
            'file' => '첨부파일',
        ],
        'comment' => [
            'content' => '댓글 내용',
            'author_name' => '작성자명',
            'password' => '비밀번호',
            'is_secret' => '비밀 댓글',
            'parent_id' => '상위 댓글',
            'user_id' => '사용자 ID',
            'ip_address' => 'IP 주소',
            'status' => '상태',
        ],
        'report' => [
            'reason_type' => '신고 유형',
            'reason_detail' => '신고 상세 내용',
            'status' => '신고 상태',
            'process_note' => '처리 메모',
            'ids' => '신고 ID',
        ],
        'blind' => [
            'reason' => '블라인드 사유',
        ],
        'restore' => [
            'reason' => '복원 사유',
        ],
    ],

    // 블라인드 검증 메시지
    'blind' => [
        'reason' => [
            'required' => '블라인드 사유는 필수입니다.',
            'min' => '블라인드 사유는 최소 :min자 이상이어야 합니다.',
            'max' => '블라인드 사유는 :max자를 초과할 수 없습니다.',
            'string' => '블라인드 사유는 문자열이어야 합니다.',
        ],
    ],

    // 복원 검증 메시지
    'restore' => [
        'reason' => [
            'required' => '복원 사유는 필수입니다.',
            'min' => '복원 사유는 최소 :min자 이상이어야 합니다.',
            'max' => '복원 사유는 :max자를 초과할 수 없습니다.',
            'string' => '복원 사유는 문자열이어야 합니다.',
        ],
    ],

    // 댓글 검증
    'comment' => [
        'content' => [
            'required' => '댓글 내용은 필수입니다.',
            'string' => '댓글 내용은 문자열이어야 합니다.',
            'min' => '댓글 내용은 최소 :min자 이상이어야 합니다.',
            'max' => '댓글 내용은 :max자를 초과할 수 없습니다.',
        ],
        'post_id' => [
            'not_found' => '게시글을 찾을 수 없습니다.',
            'blinded' => '블라인드 처리된 게시글에는 댓글을 작성할 수 없습니다.',
            'deleted' => '삭제된 게시글에는 댓글을 작성할 수 없습니다.',
        ],
        'parent_id' => [
            'exists' => '존재하지 않는 댓글입니다.',
            'integer' => '상위 댓글 ID는 정수여야 합니다.',
            'not_found' => '부모 댓글을 찾을 수 없습니다.',
            'blinded' => '블라인드 처리된 댓글에는 답글을 작성할 수 없습니다.',
            'deleted' => '삭제된 댓글에는 답글을 작성할 수 없습니다.',
        ],
        'depth' => [
            'integer' => '댓글 깊이는 정수여야 합니다.',
            'min' => '댓글 깊이는 최소 :min 이상이어야 합니다.',
            'max' => '답글은 최대 :max단계까지만 작성할 수 있습니다.',
            'exceeded' => '이 게시판은 답글을 :max단계까지만 허용합니다.',
        ],
        'user_id' => [
            'exists' => '존재하지 않는 사용자입니다.',
        ],
        'author_name' => [
            'required' => '비회원은 작성자명을 입력해야 합니다.',
            'max' => '작성자명은 :max자를 초과할 수 없습니다.',
        ],
        'password' => [
            'required' => '비밀번호를 입력해주세요.',
            'min' => '비밀번호는 최소 :min자 이상이어야 합니다.',
        ],
        'ip_address' => [
            'required' => 'IP 주소는 필수입니다.',
        ],
        'blocked_keyword' => '금지어 ":keyword"가 포함되어 있습니다.',
    ],

    // 첨부파일 검증
    'attachment' => [
        'file' => [
            'required' => '파일은 필수입니다.',
            'file' => '유효한 파일이 아닙니다.',
            'max' => '파일 크기는 :max KB를 초과할 수 없습니다.',
            'mimes' => '허용되지 않은 파일 형식입니다.',
        ],
        // FormRequest messages()에서 사용하는 플랫 키
        'file_required' => '파일은 필수입니다.',
        'file_invalid' => '유효한 파일이 아닙니다.',
        'file_max' => '파일 크기는 :max MB를 초과할 수 없습니다.',
        'file_mimes' => '허용되지 않은 파일 형식입니다.',
        'post_id_required' => '게시글 ID는 필수입니다.',
        'post_id_invalid' => '유효하지 않은 게시글 ID입니다.',
        'max_count_exceeded' => '최대 업로드 파일 수(:max개)를 초과했습니다.',
        'extension_not_allowed' => '허용되지 않은 파일 확장자입니다: :extension',
        // 순서 변경 검증 메시지
        'orders_required' => '순서 정보는 필수입니다.',
        'orders_array' => '순서 정보는 배열 형식이어야 합니다.',
        'order_id_required' => '첨부파일 ID는 필수입니다.',
        'order_id_integer' => '첨부파일 ID는 정수여야 합니다.',
        'order_value_required' => '순서 값은 필수입니다.',
        'order_value_integer' => '순서 값은 정수여야 합니다.',
    ],

    // 카테고리 검증
    'category' => [
        'name' => [
            'required' => '카테고리명은 필수입니다.',
            'string' => '카테고리명은 문자열이어야 합니다.',
            'max' => '카테고리명은 :max자를 초과할 수 없습니다.',
        ],
        'max_count_exceeded' => '최대 카테고리 수(:max개)를 초과했습니다.',
    ],

    // 관리자 설정 검증 메시지
    'board_manager_ids' => [
        'required' => '게시판 관리자 값은 필수입니다.',
        'min' => '게시판 관리자는 최소 :min명 이상 지정해야 합니다.',
    ],

    // Custom Rule 메시지
    'category_in_use' => '":category" 분류는 현재 :count개의 게시글에서 사용 중입니다.',
    'board_type_invalid' => '유효하지 않은 게시판 타입입니다. 사용 가능한 타입: :types',

    // 게시판 유형 관리 검증
    'board_type' => [
        'slug_required' => '슬러그는 필수입니다.',
        'slug_format' => '슬러그는 소문자, 숫자, 하이픈만 사용할 수 있으며 소문자로 시작해야 합니다.',
        'slug_unique' => '이미 사용 중인 슬러그입니다.',
        'name_required' => '유형명은 필수입니다.',
        'name_ko_required' => '한국어 유형명은 필수입니다.',
    ],

    // 다국어 필드 검증
    'multilingual_default_locale_required' => '기본언어(:locale)의 값은 필수입니다.',

    // 권한 검증
    'permission' => [
        'invalid_role' => '유효하지 않은 역할입니다: :role',
    ],

    // 권한 이름 (검증 메시지용)
    'permission_names' => [
        'admin' => [
            'posts' => [
                'read' => '게시글 조회 (관리자)',
                'write' => '게시글 작성/수정/삭제 (관리자)',
                'read-secret' => '비밀글 조회 (관리자)',
            ],
            'comments' => [
                'read' => '댓글 조회 (관리자)',
                'write' => '댓글 작성/수정/삭제 (관리자)',
            ],
            'manage' => '타인 글/댓글 관리 (관리자)',
            'attachments' => [
                'upload' => '파일 업로드 (관리자)',
                'download' => '파일 다운로드 (관리자)',
            ],
        ],
        'posts' => [
            'read' => '게시글 조회',
            'write' => '게시글 작성',
        ],
        'comments' => [
            'read' => '댓글 조회',
            'write' => '댓글 작성',
        ],
        'attachments' => [
            'upload' => '파일 업로드',
            'download' => '파일 다운로드',
        ],
        'manager' => '게시판 관리자',
    ],

    // 권한 필드 속성 접미사
    'role_field_suffix' => '역할',

    // 신고 검증 메시지
    'report' => [
        'status' => [
            'required' => '신고 상태는 필수입니다.',
            'in' => '유효하지 않은 신고 상태입니다.',
        ],
        'process_note' => [
            'max' => '처리 메모는 :max자를 초과할 수 없습니다.',
        ],
        'ids' => [
            'required' => '신고 ID는 필수입니다.',
            'array' => '신고 ID는 배열 형식이어야 합니다.',
            'min' => '최소 1개 이상의 신고를 선택해야 합니다.',
            'integer' => '신고 ID는 정수여야 합니다.',
            'exists' => '존재하지 않는 신고입니다.',
        ],
        'reason_type' => [
            'required' => '신고 사유는 필수입니다.',
            'in' => '유효하지 않은 신고 사유입니다.',
        ],
        'reason_detail' => [
            'required' => '신고 상세 내용은 필수입니다.',
            'min' => '신고 상세 내용은 최소 :min자 이상이어야 합니다.',
            'max' => '신고 상세 내용은 :max자를 초과할 수 없습니다.',
        ],
        'daily_limit_exceeded' => '오늘 신고 가능 횟수(:limit회)를 초과하였습니다.',
        'rejection_limit_exceeded' => '최근 :days일간 신고 반려가 :count회 누적되어 신고가 제한되었습니다.',
    ],
];
