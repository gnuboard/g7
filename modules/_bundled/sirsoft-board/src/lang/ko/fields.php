<?php

/**
 * 게시판 모듈이 회원 항목 집합(core.user)에 선언하는 항목의 이름과 안내 문구.
 *
 * 선언(`module.php` 의 `getFields()`)이 이 파일을 직접 읽어 다국어 배열을 만든다.
 */
return [
    'user' => [
        'notify_post_complete' => [
            'name' => '게시글 완료 알림',
            'description' => '내가 쓴 게시글의 처리가 완료되면 알림을 받습니다.',
        ],
        'notify_post_reply' => [
            'name' => '게시글 답변 알림',
            'description' => '내가 쓴 게시글에 답변글이 달리면 알림을 받습니다.',
        ],
        'notify_comment' => [
            'name' => '댓글 알림',
            'description' => '내가 쓴 게시글에 댓글이 달리면 알림을 받습니다.',
        ],
        'notify_reply_comment' => [
            'name' => '답변 알림',
            'description' => '내가 쓴 댓글에 답글이 달리면 알림을 받습니다.',
        ],
    ],
    // 비회원 작성자 항목 집합(sirsoft-board.guest_author)에 선언하는 항목
    'guest_author' => [
        'email' => [
            'name' => '이메일',
            'description' => '답변을 받을 이메일 주소입니다. 다른 사람에게 보이지 않습니다.',
        ],
    ],
];
