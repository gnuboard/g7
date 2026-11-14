<?php

return [
    'provider' => [
        'label' => '認証アプリ (OTP)',
    ],
    'enrollment' => [
        'started' => '認証アプリに登録する情報を作成しました。アプリで QR コードを読むか、キーを直接入力してください。',
        'confirmed' => '認証アプリの登録が完了しました。バックアップコードを安全な場所に保管してください。',
        'disabled' => '認証アプリの登録を解除しました。',
        'backup_codes_regenerated' => 'バックアップコードを新しく作成しました。以前のコードは使用できなくなります。',
        'admin_reset' => 'この会員の認証アプリ登録を解除しました。',
        'admin_reset_none' => 'この会員は認証アプリを登録していないため、解除するものはありません。',
    ],
    'errors' => [
        'already_enrolled' => '既に認証アプリが登録されています。まず解除してから再度登録してください。',
        'invalid_code' => 'コードが正しくありません。認証アプリの現在のコードまたはバックアップコードを確認してください。',
        'not_enrolled' => '登録された認証アプリがありません。',
        'password_incorrect' => '現在のパスワードが正しくありません。',
        'too_many_attempts' => 'コードの確認を試行しすぎました。:seconds秒後に再度お試しください。',
        'confirm_conflict' => '別の画面で登録が先に確定されたか、新しいシークレットが発行されました。画面を再読み込みしてからやり直してください。',
    ],
    'validation' => [
        'code_required' => 'コードを入力してください。',
        'code_invalid' => 'コード形式が正しくありません。',
        'code_attribute' => '認証コード',
        'current_password_required' => '現在のパスワードを入力してください。',
        'current_password_max' => '現在のパスワードは255文字以内で入力してください。',
        'current_password_attribute' => '現在のパスワード',
    ],
    'permissions' => [
        'credentials_delete' => '会員認証アプリ登録解除',
    ],
    'notifications' => [
        'actor_self' => '本人',
        'actor_admin' => '管理者',
        'actor_console' => 'サーバー管理コマンド',
    ],
    'notification_variables' => [
        'common' => [
            'name' => '受信者（アカウント所有者）の名前',
            'app_name' => 'サイト名',
            'occurred_at' => '変更日時',
            'ip_address' => 'リクエストIP',
            'actor_label' => '変更した主体（本人・管理者・サーバーシェル）',
            'security_url' => '認証アプリ設定画面のアドレス',
        ],
    ],
    'console' => [
        'seed_totp' => [
            'cli_only' => 'CLI専用のコマンドです。(現在のSAPI: :sapi)',
            'bypass_required' => '環境変数 G7_PLAYWRIGHT_BYPASS=1 が必要です。(例: PowerShell — :example)',
            'mode_required' => '--enroll・--code のいずれかを指定する必要があります。',
            'account_not_found' => 'アカウントが見つかりません: :email',
        ],
        'reset' => [
            'user_not_found' => '会員が見つかりません: :email',
            'not_enrolled' => 'この会員には登録された認証アプリがありません: :email',
            'done' => '認証アプリの登録を解除しました: :email',
            'next_login_notice' => '次回のログインから、この会員には認証アプリのコードが求められません(メールフォールバックが有効な場合はメールで受け取ります)。',
        ],
        'password_reset_notice' => 'この会員は認証アプリを登録しています — 認証アプリを紛失した場合はリセットしてください: php artisan totp:reset :email',
    ],
];
