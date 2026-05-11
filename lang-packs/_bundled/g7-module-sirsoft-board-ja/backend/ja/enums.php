<?php

return [
    'secret_mode' => [
        'disabled' => '無効',
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
        'abuse' => '暴言・中傷',
        'hate_speech' => 'ヘイト発言',
        'spam' => 'スパム・広告',
        'copyright' => '著作権侵害',
        'privacy' => '個人情報露出',
        'misinformation' => '虚偽情報',
        'sexual' => '性的コンテンツ',
        'violence' => '暴力的なコンテンツ',
        'other' => 'その他',
    ],
    'report_status' => [
        'pending' => '受理',
        'review' => '検討中',
        'rejected' => '却下',
        'suspended' => '投稿停止',
        'deleted' => '永久削除',
    ],
    'trigger_type' => [
        'report' => '通報処理',
        'admin' => '管理者手動',
        'system' => 'システム',
        'auto_hide' => '自動ブラインド',
        'user' => 'ユーザー直接削除',
    ],
    'post_status' => [
        'published' => '公開中',
        'blinded' => 'ブラインド',
        'deleted' => '削除済み',
    ],
];
