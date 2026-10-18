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
    'manual_run' => [
        'queued' => '実行をキューに受け付けました。処理が終わるとカードに結果が表示されます。',
        'unknown_target' => '登録されていない職権実行対象です。',
        'target_missing' => '受け付け後、対象が消えて実行できませんでした (拡張が無効化されている可能性があります)。',
        'stale' => ':minutes分を超えても終わらないため、失敗として処理しました。キューワーカーが動作しているか確認してください。',
        'status' => [
            'queued' => '処理待機中',
            'running' => '処理中',
            'completed' => '完了',
            'failed' => '失敗',
        ],
        'dormant_stages' => [
            'pre_notice' => '休眠転換事前通知',
            'convert' => '休眠転換',
            'withdraw_notice' => '休眠アカウント退会予定通知',
            'withdraw' => '休眠アカウント退会処理',
        ],
        'password_expiry_stages' => [
            'notice' => 'パスワード有効期限の事前通知',
            'expire' => 'パスワード有効期限切れの処理',
        ],
    ],
    // 同意スナップショット公開照会(GET /api/consent)の応答文言。
    'consent' => [
        'fetch_success' => '訪問者の同意状態を確認しました。',
    ],
];
