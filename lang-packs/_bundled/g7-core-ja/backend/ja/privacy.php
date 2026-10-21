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
    // `privacy:*` コマンド出力
    'console' => [
        'process_retention' => [
            'handler_required' => '--handler にハンドラー識別子を指定してください。すべてのハンドラーを実行する場合はオプションを省略してください。',
            'registered_handlers' => '登録済みハンドラー: :handlers',
            'none' => 'なし',
            'unknown_handler' => '登録されていない保有期間ハンドラーです: :handler',
            'no_handlers' => '登録済みの保有期間ハンドラーがありません。',
            'handler_disabled' => ':id (:label): 無効のため実行しませんでした。',
            'handler_locked' => ':id (:label): 管理者の職権実行が処理中のためスキップしました。',
            'handler_failed' => ':id (:label): 失敗 — :error',
            'handler_done' => ':id (:label): :count 件 (:mode)',
            'total' => '合計: :count 件',
        ],
        'process_dormant' => [
            'disabled' => '休眠アカウント機能が無効のため実行しませんでした。(privacy.dormant_enabled = false)',
            'locked' => '管理者の職権実行が休眠段階を処理中のため、今回の周期はスキップしました。',
            'pre_notice' => '休眠転換の事前通知: :count 件',
            'converted' => '休眠転換: :count 件',
            'withdraw_notice' => '退会予定の通知: :count 件',
            'withdrawn' => '退会処理: :count 件',
            'withdraw_disabled' => '休眠後の自動退会が無効のため実行しませんでした。(privacy.dormant_withdraw_enabled = false)',
            'stage_failed' => '段階失敗 — :stage: :error',
        ],
    ],
];
