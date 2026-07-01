<?php

return [
    'new_comment' => [
        'subject' => '[:board_name] ":post_title" 投稿に新しいコメントが追加されました',
        'greeting' => ':name様、こんにちは。',
        'line' => '":post_title" 投稿に :comment_author様が新しいコメントを作成しました。',
    ],
    'reply_comment' => [
        'subject' => '[:board_name] ":post_title" 投稿に私のコメントへの返信が追加されました',
        'greeting' => ':name様、こんにちは。',
        'line' => '":post_title" 投稿に登録された私のコメントに :comment_author様が返信しました。',
    ],
    'post_reply' => [
        'subject' => '[:board_name] ":post_title" 投稿に回答が追加されました',
        'greeting' => ':name様、こんにちは。',
        'line' => '":post_title" 投稿に新しい回答が作成されました。',
    ],
    'post_action' => [
        'subject' => '[:board_name] ":post_title" 投稿が :action_type 処理されました',
        'greeting' => ':name様、こんにちは。',
        'line' => '":post_title" 投稿が管理者により :action_type 処理されました。',
        'action_types' => [
            'blind' => '非表示',
            'deleted' => '削除',
            'restored' => '復元',
        ],
    ],
    'new_post_admin' => [
        'subject' => '[:board_name] 新しい投稿 ":post_title"が登録されました',
        'greeting' => ':name様、こんにちは。',
        'line' => '":board_name" 掲示板に新しい投稿 ":post_title"が登録されました。',
    ],
    'report_received_admin' => [
        'reason_types' => [
            'abuse' => '暴言·誹謗',
            'hate_speech' => '差別発言',
            'spam' => 'スパム·広告',
            'copyright' => '著作権侵害',
            'privacy' => '個人情報漏出',
            'misinformation' => '虚偽情報',
            'sexual' => '成人向けコンテンツ',
            'violence' => '暴力的なコンテンツ',
            'other' => 'その他',
        ],
    ],
    'report_action' => [
        'target_types' => [
            'post' => '投稿',
            'comment' => 'コメント',
        ],
        'action_types' => [
            'blind' => '非表示',
            'deleted' => '削除',
            'restored' => '却下(復元)',
        ],
    ],
    'common' => [
        'view_button' => '投稿を表示',
        'regards' => 'ありがとうございます',
    ],
];
