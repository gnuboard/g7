<?php

/**
 * 掲示板モジュールが会員項目セット(core.user)に宣言する項目の名前と案内文。
 *
 * 宣言(`module.php` の `getFields()`)がこのファイルを直接読み込んで多言語配列を作る。
 */
return [
    'user' => [
        'notify_post_complete' => [
            'name' => '投稿完了通知',
            'description' => '自分が書いた投稿の処理が完了すると通知を受け取ります。',
        ],
        'notify_post_reply' => [
            'name' => '投稿回答通知',
            'description' => '自分が書いた投稿に回答が付くと通知を受け取ります。',
        ],
        'notify_comment' => [
            'name' => 'コメント通知',
            'description' => '自分が書いた投稿にコメントが付くと通知を受け取ります。',
        ],
        'notify_reply_comment' => [
            'name' => '回答通知',
            'description' => '自分が書いたコメントに返信が付くと通知を受け取ります。',
        ],
    ],
    // 非会員投稿者項目セット(sirsoft-board.guest_author)に宣言する項目
    'guest_author' => [
        'email' => [
            'name' => 'メール',
            'description' => '回答を受け取るメールアドレスです。他の人には表示されません。',
        ],
    ],
];
