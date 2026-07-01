<?php

return [
    'title' => 'KGイニシス本人確認',
    'description' => 'KGイニシス統合認証の本人確認サービスをG7コア本人認証インフラに接続するプラグインです。',
    'channels' => [
        'ipin' => 'アイピン',
    ],
    'settings' => [
        'test_mode' => 'テストモード',
        'live_mid' => 'ライブMID（SRAプレフィックス）',
        'live_api_key' => 'ライブAPIキー',
        'duplicate_field' => '同一人識別基準',
        'duplicate_block_enabled' => [
            'label' => '重複登録ブロック',
            'description' => '有効化時に本人認証を通過した人が以前に別のメールアドレスで登録した場合、登録を拒否します。家族携帯電話共有またはB2Bシナリオなど、1人が複数のアカウントを登録する必要がある場合は無効化してください。この設定とは関係なく、同一メールアドレスの再登録は常にブロックされます（コア基本動作）。',
        ],
        'live_mid_attribute' => 'ライブ MID',
        'live_api_key_attribute' => 'ライブ API キー',
    ],
    'card' => [
        'title' => '本人認証情報',
        'method' => '認証方式',
        'method_value' => 'KGイニシス本人確認',
        'status' => '認証ステータス',
        'status_verified' => '✓認証済み',
        'verified_at' => '認証日時',
        'name' => '実名',
        'birthday' => '生年月日',
        'phone' => '携帯電話',
        'is_adult' => [
            'label' => '成人かどうか',
            'true' => '成人',
            'false' => '未成年者',
        ],
    ],
    'purposes' => [
        'adult_verification' => [
            'label' => '成人認証（KGイニシス本人確認専用）',
            'description' => '必ずKGイニシスプロバイダーのみにマッピングしてください。メール/SMSプロバイダーは生年月日を返さないため、成人かどうかの判定ができません。誤りマッピング時に未成年ユーザーに成人向けコンテンツが露出する可能性があります。',
        ],
    ],
];
