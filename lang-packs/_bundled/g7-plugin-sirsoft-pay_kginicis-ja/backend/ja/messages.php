<?php

return [
    'errors' => [
        'cash_receipt_invalid_issue_type' => '発行タイプが正しくありません。',
        'cash_receipt_missing_issue_number' => '識別番号を入力してください。',
        'cash_receipt_already_issued' => 'すでに現金領収書が発行された決済です。',
        'cash_receipt_issue_failed' => '現金領収書の発行に失敗しました。',
    ],
    'refund' => [
        'missing_tid' => 'トランザクション ID（TID）がないため、返金を進めることができません。',
        'default_reason' => '購入者返金要求',
    ],
    'cbt_reconciliation' => [
        'not_retryable' => '再試行可能な CBT 返金待機件ではありません。',
        'retry_success' => 'CBT 返金再試行が完了しました。',
        'retry_failed' => 'CBT 返金再試行に失敗しました。',
    ],
    'defaults' => [
        'good_name' => '商品',
    ],
    'settings_validation' => [
        'test_japan_sign_key_required' => '日本決済(CBT)を使用するにはテスト日本 CBT ハッシュキーが必要です。',
        'live_japan_mid_required' => 'ライブモードで日本決済(CBT)を使用するにはライブ日本 MID が必要です。',
        'live_japan_sign_key_required' => 'ライブモードで日本決済(CBT)を使用するにはライブ日本 CBT ハッシュキーが必要です。',
        'japan_merchant_name_required' => 'ライブ日本決済画面に表示する加盟店名が必要です。',
        'japan_merchant_name_kana_required' => 'ライブ日本決済画面に表示する加盟店名 Kana が必要です。',
        'japan_merchant_name_alphabet_required' => 'ライブ日本決済画面に表示する英文加盟店名が必要です。',
        'japan_merchant_name_short_required' => 'ライブ日本決済画面に表示する加盟店略称が必要です。',
        'japan_contact_name_required' => 'ライブ日本決済画面に表示するお問い合わせ先名が必要です。',
        'japan_contact_email_required' => 'ライブ日本決済画面に表示するお問い合わせメールが必要です。',
        'japan_contact_phone_required' => 'ライブ日本決済画面に表示するお問い合わせ電話番号が必要です。',
        'japan_contact_opening_hours_required' => 'ライブ日本決済画面に表示するお問い合わせ営業時間が必要です。',
        'replace_sample_value' => 'ライブモードではサンプル値の代わりに実際の契約情報を入力してください。',
    ],
];
