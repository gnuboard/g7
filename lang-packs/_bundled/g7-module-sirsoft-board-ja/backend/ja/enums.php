<?php

return [
    'secret_mode' => [
        'disabled' => '使用しない',
        'enabled' => '選択使用',
        'always' => '必須使用',
    ],
    'order_direction' => [
        'asc' => '昇順',
        'desc' => '降順',
    ],
    'board_order_by' => [
        'created_at' => '作成日',
        'view_count' => '閲覧数',
        'title' => 'タイトル',
        'author' => '作成者',
    ],
    'report_type' => [
        'post' => '投稿',
        'comment' => 'コメント',
    ],
    'report_reason_type' => [
        'abuse' => '暴言・誹謗',
        'hate_speech' => 'ヘイト発言',
        'spam' => 'スパム・広告',
        'copyright' => '著作権侵害',
        'privacy' => '個人情報流出',
        'misinformation' => '虚偽情報',
        'sexual' => '性的コンテンツ',
        'violence' => '暴力的コンテンツ',
        'other' => 'その他',
    ],
    'report_status' => [
        'pending' => '受付',
        'review' => '審査中',
        'rejected' => '却下',
        'suspended' => '投稿停止',
        'deleted' => '完全削除',
    ],
    'trigger_type' => [
        'report' => '通報処理',
        'admin' => '管理者手動',
        'system' => 'システム',
        'auto_hide' => '自動ブラインド',
        'user' => 'ユーザー直接削除',
        'cascade' => '投稿削除',
    ],
    'post_status' => [
        'published' => '公開中',
        'blinded' => 'ブラインド',
        'deleted' => '削除済み',
    ],
];
