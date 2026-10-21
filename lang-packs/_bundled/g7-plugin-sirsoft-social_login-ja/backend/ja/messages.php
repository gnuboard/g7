<?php

return [
    'test_login' => [
        'started' => '接続テストを開始します。開いたウィンドウでプロバイダーの同意を完了してください。',
    ],
    'errors' => [
        'provider_unknown' => 'プロバイダーが見つからないか、使用できません。',
    ],
    'redirect_notes' => [
        'exact_match' => 'プロバイダーのコンソールに登録するコールバックアドレスは、上のアドレスと一文字まで同じである必要があります。',
    ],
    'test_result' => [
        'title' => 'ソーシャルログイン接続テスト結果',
        'expired' => '結果がないか、すでに確認済みです。結果は一度だけ表示できます。管理者画面からテストを再度開始してください。',
        'success' => '接続に成功しました。プロバイダーから以下の情報が送られました。',
        'failure' => '接続に失敗しました。エラーコード: :code',
        'provider' => 'プロバイダー',
        'provider_user_id' => 'プロバイダーのユーザー ID',
        'email' => 'メール',
        'email_verified' => 'メール検証',
        'verified_yes' => '検証済み',
        'verified_no' => '未検証',
        'verified_unknown' => 'プロバイダーから提供されていません',
        'name' => '名前',
        'nickname' => 'ニックネーム',
        'received_fields' => '受け取った項目',
        'missing_required' => '登録に不足している項目',
        'missing_none' => 'なし',
        'raw_keys' => 'プロバイダーの元のキー',
        'tested_at' => 'テスト日時',
        'note' => 'テストではログインしません。このウィンドウは閉じてもかまいません。',
    ],
    'mock' => [
        'title' => 'モックプロバイダー同意画面',
        'notice' => '開発・検収用のモックプロバイダーです(応答形式: :style)。実際のプロバイダーには何もリクエストを送りません。',
        'variant' => '応答バリエーション',
        'email' => 'メール',
        'subject' => 'プロバイダーのユーザー ID',
        'approve' => '同意して続ける',
        'deny' => '拒否',
        'label' => 'モック :provider',
    ],
    'mock_cli' => [
        'choose_one' => '--enable、--disable、--status のうち 1 つだけを指定してください。',
        'minutes_range' => '--minutes は 1~:max の範囲で指定してください。',
        'enabled' => 'モックプロバイダーを有効にしました。:until に自動で無効になります。',
        'disabled' => 'モックプロバイダーを無効にしました。',
        'status_on' => 'モックプロバイダーは有効です。:until に無効になります。',
        'status_off' => 'モックプロバイダーは無効です。',
    ],
    'providers' => [
        'listed' => 'ソーシャルログインプロバイダー一覧です。',
    ],
    'signup' => [
        'pending' => '登録待機情報です。',
        'code_sent' => '認証番号を送信しました。',
        'email_verified' => 'メールアドレスを確認しました。',
        'completed' => '登録が完了しました。',
    ],
    'link_existing' => [
        'linked' => '既存アカウントにソーシャルアカウントを連携しました。',
    ],
    'accounts' => [
        'listed' => '連携されたソーシャルアカウント一覧です。',
        'link_started' => 'プロバイダーの画面に移動します。',
        'unlinked' => 'ソーシャルアカウントの連携を解除しました。',
    ],
    'prune' => [
        'dry_run' => '整理対象の件数です(削除していません)。',
        'done' => '保存期間を過ぎた記録を整理しました。',
        'target' => '対象',
        'count' => '件数',
        'targets' => [
            'profile_raw' => '元のプロフィールのコピー',
            'attempts' => 'ログイン試行記録',
            'webhook_events' => 'Webhook 受信履歴',
            'mock_flag' => '有効期限切れのモックプロバイダー標識',
        ],
    ],
    'data_deletion' => [
        'title' => 'Facebook データ削除リクエスト',
        'not_found' => '確認コードに該当する削除リクエストがありません。',
        'pending' => '削除リクエストを受け付け、処理しています。',
        'done' => 'このサイトに保存された Facebook 連携情報を削除しました。',
        'failed' => '処理できませんでした。サイト管理者にお問い合わせください。',
        'code' => '確認コード',
        'received_at' => 'リクエスト受付日時',
        'processed_at' => '処理日時',
        'note' => '会員アカウント自体の削除(退会)はサイトのポリシーに従います。退会を希望する場合は、サイトにログインして退会してください。',
    ],
    'admin' => [
        'providers' => [
            'listed' => 'ソーシャルログインプロバイダー設定です。',
            'updated' => 'プロバイダー設定を保存しました。',
            'created' => '一般 OIDC プロバイダーを追加しました。接続テストを終えてから有効にしてください。',
            'deleted' => 'プロバイダーを削除しました。',
            'reordered' => '表示順を保存しました。',
            'discovered' => 'discovery ドキュメントからアドレスを読み込みました。確認してから保存してください。',
            'impact' => 'プロバイダー変更の影響です。',
            'tested' => '接続テストが完了しました。',
        ],
        'diagnostics' => [
            'loaded' => '診断情報です。',
            'attempts' => 'ソーシャルログイン試行記録です。',
            'webhook_events' => 'プロバイダー通知の受信履歴です。',
        ],
        'stats' => [
            'loaded' => 'ソーシャルログイン統計です。',
        ],
        'user_accounts' => [
            'listed' => '会員のソーシャルアカウント連携一覧です。',
            'options' => '連携があるプロバイダー一覧です。',
            'unlinked' => '会員のソーシャルアカウント連携を解除しました。',
        ],
    ],
    'reauth' => [
        'provider_label' => 'ソーシャルアカウントで再確認',
        'channel_label' => 'ソーシャルログイン',
        'started' => 'プロバイダーの画面に移動します。',
        'verified' => '本人確認が完了しました。',
    ],
    // 알림 편집 화면 「사용 가능한 변수」 설명 — ko 는 plugin.php 선언 문자열과 글자 단위로 같게 둔다
    'notification_variables' => [
        'common' => [
            'app_name' => 'サイト名',
            'name' => '会員名',
            'provider_label' => 'プロバイダー名',
            'site_url' => 'サイトのアドレス',
            'reset_url' => 'パスワード再設定画面のアドレス',
            'failure_count' => '連続失敗回数',
            'last_error_code' => '最後のエラーコード',
            'diagnostics_url' => '診断画面のアドレス',
            'count' => 'この1時間の登録数',
            'threshold' => '通知の基準',
            'stats_url' => '統計画面のアドレス',
            'event_type' => '通知の種類',
            'error' => 'エラーの要約(秘密情報なし)',
        ],
        'by_type' => [
            'social_login_provider_failing' => [
                'name' => '受信する管理者の名前',
            ],
            'social_login_signup_surge' => [
                'name' => '受信する管理者の名前',
            ],
            'social_login_webhook_failed' => [
                'name' => '受信する管理者の名前',
            ],
        ],
    ],
];
