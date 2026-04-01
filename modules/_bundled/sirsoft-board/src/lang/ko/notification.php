<?php

return [
    'new_comment' => [
        'subject' => '[:board_name] ":post_title" 게시글에 새 댓글이 달렸습니다',
        'greeting' => ':name님, 안녕하세요.',
        'line' => '":post_title" 게시글에 :comment_author님이 새 댓글을 작성했습니다.',
    ],
    'reply_comment' => [
        'subject' => '[:board_name] ":post_title" 게시글에 내 댓글의 답글이 달렸습니다',
        'greeting' => ':name님, 안녕하세요.',
        'line' => '":post_title" 게시글에 등록된 내 댓글에 :comment_author님이 답글을 달았습니다.',
    ],
    'post_reply' => [
        'subject' => '[:board_name] ":post_title" 게시글에 답변이 달렸습니다',
        'greeting' => ':name님, 안녕하세요.',
        'line' => '":post_title" 게시글에 새 답변글이 작성되었습니다.',
    ],
    'post_action' => [
        'subject' => '[:board_name] ":post_title" 게시글이 :action_type 처리되었습니다',
        'greeting' => ':name님, 안녕하세요.',
        'line' => '":post_title" 게시글이 관리자에 의해 :action_type 처리되었습니다.',
        'action_types' => [
            'blind' => '블라인드',
            'deleted' => '삭제',
            'restored' => '복원',
        ],
    ],
    'new_post_admin' => [
        'subject' => '[:board_name] 새 게시글 ":post_title"이(가) 등록되었습니다',
        'greeting' => ':name님, 안녕하세요.',
        'line' => '":board_name" 게시판에 새 게시글 ":post_title"이(가) 등록되었습니다.',
    ],
    'report_received_admin' => [
        'reason_types' => [
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
    ],
    'report_action' => [
        'target_types' => [
            'post' => '게시글',
            'comment' => '댓글',
        ],
        'action_types' => [
            'blind' => '블라인드',
            'deleted' => '삭제',
            'restored' => '반려(복원)',
        ],
    ],
    'common' => [
        'view_button' => '게시글 보기',
        'regards' => '감사합니다',
    ],
];
