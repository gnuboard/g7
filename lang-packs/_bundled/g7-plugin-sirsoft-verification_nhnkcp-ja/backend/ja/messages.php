<?php

return [
    'title' => 'NHN KCP 携帯電話本人確認',
    'description' => 'NHN KCP の携帯電話本人確認サービスを G7 コア本人認証インフラストラクチャに接続するプラグインです。',
    'channels' => [
        'phone' => '携帯電話',
    ],
    'settings' => [
        'test_mode' => 'テストモード',
        'test_site_cd' => 'テスト サイトコード',
        'test_enc_key' => 'テスト 暗号化キー',
        'live_site_cd' => '運営 サイトコード (SM プレフィックス)',
        'live_enc_key' => '運営 暗号化キー',
        'web_siteid' => 'ウェブサイト ID',
        'live_site_cd_attribute' => '運営 サイトコード',
        'live_enc_key_attribute' => '運営 暗号化キー',
        'duplicate_field' => '重複チェック基準',
        'duplicate_block_enabled' => [
            'label' => '重複登録ブロック',
            'description' => '有効化した場合、本人確認を完了したユーザーが以前に異なるメールアドレスで登録した履歴がある場合は登録を拒否します。ファミリー携帯電話の共有または B2B シナリオなど、1 人が複数のアカウントを登録する必要がある場合は無効化してください。この設定に関わらず、同一メールアドレスの再登録は常にブロックされます (コア デフォルト動作)。',
        ],
    ],
    'card' => [
        'title' => '本人認証情報',
        'method' => '認証方式',
        'method_value' => 'NHN KCP 携帯電話本人確認',
        'verified_at' => '認証日時',
        'name' => '実名',
        'birthday' => '生年月日',
        'phone' => '携帯電話',
        'is_adult' => [
            'label' => '成人否定状況',
            'true' => '成人',
            'false' => '未成年者',
        ],
    ],
    'purposes' => [
        'adult_verification' => [
            'label' => '成人認証 (NHN KCP 本人確認専用)',
            'description' => '必ず NHN KCP プロバイダーのみにマッピングしてください。メール/SMS プロバイダーは生年月日を返さないため成人判定ができません。誤ったマッピングの場合、成人向けコンテンツが未成年ユーザーに公開される可能性があります。',
        ],
    ],
    'bridge_page_title' => '本人確認結果',
];
