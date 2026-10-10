<?php

return [
    'RunStatus' => [
        'success' => '成功',
        'partial' => '部分的成功',
        'no_data' => 'データなし',
        'skipped' => 'スキップ',
        'dry_run' => '接続テスト',
        'auth_missing' => '認証キーなし',
        'auth_error' => '認証エラー',
        'quota_exceeded' => '1日の制限超過',
        'bad_request' => 'リクエストエラー',
        'bad_response' => 'レスポンス形式エラー',
        'network_error' => 'ネットワークエラー',
        'unsupported_base' => '基本通貨未対応',
    ],
    'RunTrigger' => [
        'scheduled' => '定期',
        'retry' => '再試行',
        'manual' => '手動',
        'dry_run' => '接続テスト',
        'currency_changed' => '通貨変更の再適用',
    ],
    'ItemStatus' => [
        'applied' => '適用',
        'unchanged' => '変更なし',
        'unsupported' => '未対応',
        'invalid_rate' => '無効な為替レート',
        'base_currency' => '基本通貨',
    ],
    'RateType' => [
        'deal_bas_r' => '適用基準レート',
        'ttb' => '外国送金受け取り時',
        'tts' => '外国送金送信時',
    ],
    'no_data_early' => '営業日午前11時以前 — しばらく後に再度お試しください。',
    'no_data_holiday' => '休場日と判定されました — 直前の営業日の為替レートが引き続き有効です。',
    'provider_not_selected' => 'イーコマース通貨設定でこのプロバイダーを選択し、自動管理をオンにしてください。',
    'scheduler_hint' => 'サーバースケジューラーが必要です。本番環境以外では今すぐ更新を使用してください。',
    'execution_failed' => '為替レート更新を実行できませんでした。',
    'cooldown' => '60秒後に再度お試しください。手動実行は1日20回まで可能です。',
    'cooldown_test' => '60秒後に再度お試しください。接続テストは1日5回まで可能です。',
    'busy' => '別の為替レート更新が進行中です。',
    'pruned' => ':count個の履歴をクリーンアップしました。',
    // 通知テンプレート編集ウィンドウ「使用可能な変数」の説明 — common は変数キーごとの既定、by_type はその通知でのみ意味が異なる変数。キーがない場合は宣言文字列をそのまま使う。
    'notification_variables' => [
        'common' => [
            'status_label' => '失敗の種類',
            'message' => '認証キーを除いた詳細な失敗理由',
            'search_date' => '韓国時間基準の照会日',
            'settings_url' => '為替レートプラグイン設定画面のアドレス',
        ],
    ],
];
