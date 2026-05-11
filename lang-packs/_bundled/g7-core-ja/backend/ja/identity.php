<?php

return [
    'providers' => [
        'mail' => [
            'label' => 'メール',
            'settings' => [
                'code_length' => '認証コード長',
                'code_length_help' => '送信される数字コードのけた数（デフォルト6、最小4、最大10）。',
                'from_address' => '送信者アドレス',
                'from_address_help' => '空の場合、システムデフォルトの送信者が使用されます。',
            ],
        ],
    ],
    'errors' => [
        'verification_required' => '本人確認が必要です。',
        'challenge_not_found' => '無効な認証リクエストです。',
        'wrong_provider' => 'この認証リクエストは別のプロバイダーで処理する必要があります。',
        'invalid_state' => '既に処理された認証リクエストです。',
        'expired' => '認証時間が期限切れになりました。もう一度お試しください。',
        'max_attempts' => '試行回数を超えました。再度リクエストしてください。',
        'invalid_code' => '認証コードが正しくありません。',
        'invalid_verification_token' => '無効な本人確認トークンです。',
        'missing_target' => '認証対象（メール·電話番号）が必要です。',
        'target_mismatch' => '認証した対象と要求した対象が一致しません。',
        'purpose_not_supported' => '選択されたプロバイダーはこの目的をサポートしていません。',
        'provider_unavailable' => '本人確認プロバイダーを使用できません。',
        'generic' => '本人確認に失敗しました。',
        'missing_scope_or_target' => 'ポリシー照会にはscopeとtargetの両方が必要です。',
        'admin_policy_has_no_default' => '管理者が直接作成したポリシーには宣言デフォルト値がありません。',
        'reset_field_failed' => '宣言デフォルト値の復元に失敗しました。フィールドが有効であることを確認してください。',
    ],
    'messages' => [
        'challenge_requested' => '本人確認コードを送信しました。',
        'challenge_verified' => '本人確認が完了しました。',
        'challenge_cancelled' => '本人確認リクエストがキャンセルされました。',
    ],
    'logs' => [
        'activity' => [
            'requested' => ':emailに本人確認コードを送信しました。',
            'verified' => '本人確認が完了しました。',
            'failed' => '本人確認に失敗しました。',
            'expired' => '本人確認時間が期限切れになりました。',
            'cancelled' => '本人確認リクエストをキャンセルしました。',
        ],
    ],
    'purposes' => [
        'signup' => [
            'label' => '会員登録認証',
            'description' => '新規登録者のメール/電話番号所有確認。',
        ],
        'password_reset' => [
            'label' => 'パスワード再設定',
            'description' => 'パスワードを忘れたユーザーが本人確認後に再設定します。',
        ],
        'self_update' => [
            'label' => '自己情報変更',
            'description' => 'ログインユーザーがメール/電話などの本人情報を変更する場合。',
        ],
        'sensitive_action' => [
            'label' => '機密作業',
            'description' => 'アカウント削除·管理者作業など再認証が必要な時点。',
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
        'api' => 'API直接呼び出し',
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
            'block' => 'ブロック（HTTP 428）',
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
