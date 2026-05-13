<?php

return [
    'new_comment' => [
        'subject' => '[:board_name] "投稿「:post_title」に新しいコメントが追加されました',
        'greeting' => ':name様、こんにちは。',
        'line' => '「:post_title」の投稿に:comment_authorさんが新しいコメントを作成しました。',
    ],
    'reply_comment' => [
        'subject' => '[:board_name] "投稿「:post_title」に私のコメントへの返信が追加されました',
        'greeting' => ':name様、こんにちは。',
        'line' => '「:post_title」の投稿に登録された私のコメントに:comment_authorさんが返信を付けました。',
    ],
    'post_reply' => [
        'subject' => '[:board_name] "投稿「:post_title」に回答が追加されました',
        'greeting' => ':name様、こんにちは。',
        'line' => '「:post_title」の投稿に新しい回答が作成されました。',
    ],
    'post_action' => [
        'subject' => '[:board_name] "投稿「:post_title」が:action_type処理されました',
        'greeting' => ':name様、こんにちは。',
        'line' => '「:post_title」の投稿が管理者により:action_type処理されました。',
        'action_types' => [
            'blind' => 'ブラインド',
            'deleted' => '削除',
            'restored' => '復元',
        ],
    ],
    'new_post_admin' => [
        'subject' => '[:board_name] 新しい投稿「:post_title」が登録されました',
        'greeting' => ':name様、こんにちは。',
        'line' => '「:board_name」掲示板に新しい投稿「:post_title」が登録されました。',
    ],
    'report_received_admin' => [
        'reason_types' => [
            'abuse' => '暴言·誹謗',
            'hate_speech' => 'ヘイトスピーチ',
            'spam' => 'スパム·広告',
            'copyright' => '著作権侵害',
            'privacy' => '個人情報の露出',
            'misinformation' => '虚偽情報',
            'sexual' => '性的コンテンツ',
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
            'blind' => 'ブラインド',
            'deleted' => '削除',
            'restored' => '却下(復元)',
        ],
    ],
    'common' => [
        'view_button' => '投稿を見る',
        'regards' => 'ありがとうございます',
    ],
];
