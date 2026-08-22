<?php

return [
    'errors' => [
        'cash_receipt_issue_failed' => '現金領収書の発行に失敗しました。',
        'order_not_found' => '注文が見つかりません。',
        'cbt_failed' => '海外決済の処理に失敗しました。',
        'payment_failed' => '決済処理に失敗しました。しばらく後にもう一度お試しください。',
    ],
    'refund' => [
        'missing_tid' => 'トランザクション ID（TID）がないため、返金を進めることができません。',
        'default_reason' => '購入者返金要求',
    ],
    'cbt_connectivity' => [
        'checked' => '接続診断が完了しました。',
        'check_failed' => '接続診断に失敗しました。',
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
    'cash_receipt' => [
        'provider_name' => 'KGイニシス',
    ],
    'escrow' => [
        'invoice_required' => '送り状番号を入力してください。',
        'courier_required' => '配送業者を選択してください。',
        'default_confirmer' => '管理者',
    ],
    'cbt_cvs' => [
        'simulate_success' => '入金シミュレーションが完了しました。',
        'simulate_failed' => '入金シミュレーションに失敗しました。',
        'expire_success' => '仮想口座の入金期限が満了処理されました。',
        'recheck_success' => '入金状態を再確認しました。',
        'not_test_mode' => 'テストモードでのみ利用できます。',
        'not_waiting_deposit' => '入金待ちの決済のみ処理できます。',
        'not_expirable' => '満了処理できない決済です。',
        'not_cvs' => 'コンビニ・仮想口座決済ではありません。',
    ],
];
