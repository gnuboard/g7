<?php

return [
    'providers' => [
        'mail' => [
            'label' => 'メール',
            'settings' => [
                'code_length' => '認証コード長',
                'code_length_help' => '送信される数字コードの桁数 (デフォルト 6、最小 4、最大 10)。',
                'from_address' => '差出人アドレス',
                'from_address_help' => '空の場合、システムのデフォルト差出人を使用します。',
            ],
        ],
    ],
    'errors' => [
        'verification_required' => '本人確認が必要です。',
        'challenge_not_found' => '無効な認証リクエストです。',
        'wrong_provider' => 'この認証リクエストは別のプロバイダーで処理する必要があります。',
        'invalid_state' => '既に処理された認証リクエストです。',
        'expired' => '認証時間が期限切れになりました。もう一度お試しください。',
        'max_attempts' => '試行回数を超過しました。もう一度リクエストしてください。',
        'invalid_code' => '認証コードが正しくありません。',
        'invalid_verification_token' => '無効な本人認証トークンです。',
        'missing_target' => '認証対象 (メール·電話番号) が必要です。',
        'target_mismatch' => '認証された対象とリクエストされた対象が一致しません。',
        'purpose_not_supported' => '選択されたプロバイダーはこの目的をサポートしていません。',
        'provider_unavailable' => '本人認証プロバイダーを使用できません。',
        'generic' => '本人認証に失敗しました。',
        'missing_scope_or_target' => 'ポリシー照会には scope と target の両方が必要です。',
        'admin_policy_has_no_default' => '管理者が直接作成したポリシーには宣言デフォルト値がありません。',
        'reset_field_failed' => '宣言デフォルト値の復元に失敗しました。フィールドが有効であることを確認してください。',
        'cannot_delete_system_policy' => 'システムが宣言したポリシーは削除できません。管理者が直接作成したポリシーのみ削除できます。',
    ],
    'messages' => [
        'challenge_requested' => '本人認証コードを送信しました。',
        'challenge_verified' => '本人確認が完了しました。',
        'challenge_cancelled' => '本人認証リクエストがキャンセルされました。',
    ],
    'logs' => [
        'activity' => [
            'requested' => ':email に本人認証コードを送信しました。',
            'verified' => '本人確認が完了しました。',
            'failed' => '本人認証に失敗しました。',
            'expired' => '本人認証時間が期限切れになりました。',
            'cancelled' => '本人認証リクエストをキャンセルしました。',
        ],
    ],
    'purposes' => [
        'signup' => [
            'label' => '会員登録認証',
            'description' => '新規ユーザーのメール/電話番号所有確認。',
        ],
        'password_reset' => [
            'label' => 'パスワード再設定',
            'description' => 'パスワードを忘れたユーザーが本人確認後に再設定。',
        ],
        'self_update' => [
            'label' => '自分の情報を変更',
            'description' => 'ログインユーザーがメール/電話など本人情報を変更する場合。',
        ],
        'sensitive_action' => [
            'label' => '機密操作',
            'description' => 'アカウント削除·管理者操作など再認証が必要な時点。',
        ],
        'login' => [
            'label' => 'ログイン2段階認証',
            'description' => '2段階認証を有効にした場合、パスワード確認後にもう一段階を要求。',
        ],
    ],
    'channels' => [
        'email' => 'メール',
    ],
    'origin_types' => [
        'route' => 'ルート',
        'hook' => 'フック',
        'policy' => 'ポリシー',
        'middleware' => 'ミドルウェア',
        'api' => 'API 直接呼び出し',
        'custom' => 'カスタム',
        'system' => 'システム',
    ],
    'policy' => [
        'scope' => [
            'route' => 'ルート',
            'hook' => 'フック',
            'custom' => 'カスタム',
        ],
        'fail_mode' => [
            'block' => 'ブロック (HTTP 428)',
            'log_only' => 'ログのみ記録',
        ],
        'applies_to' => [
            'self' => '本人',
            'admin' => '管理者',
            'both' => 'すべて',
        ],
        'source_type' => [
            'core' => 'コア',
            'module' => 'モジュール',
            'plugin' => 'プラグイン',
            'admin' => '管理者',
        ],
    ],
    'message' => [
        'scope_type' => [
            'provider_default' => 'Provider デフォルト',
            'purpose' => 'Purpose 別',
            'policy' => 'Policy 別',
        ],
    ],
];
