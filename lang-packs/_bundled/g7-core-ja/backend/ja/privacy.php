<?php

return [
    'handlers' => [
        'user_withdrawn' => '退会会員の残存個人情報',
    ],
    'targets' => [
        'columns' => [
            'name' => '名前',
            'email' => 'メール',
            'withdrawn_at' => '退会日',
            'last_activity_at' => '最後の活動日',
            'dormant_at' => '休眠転換日',
        ],
        'unknown_handler' => '登録されていない個人情報処理対象です。',
        'unknown_stage' => '不明な休眠ステージです。',
    ],
];
