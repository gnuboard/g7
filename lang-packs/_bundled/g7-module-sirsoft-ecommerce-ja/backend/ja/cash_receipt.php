<?php

return [
    'type' => [
        'income' => '所得控除用',
        'expense' => '支出証明用',
    ],
    'identifier_type' => [
        'phone' => '携帯電話番号',
        'card' => '現金領収書カード番号',
        'business' => '事業者登録番号',
    ],
    'transaction_type' => [
        'issue' => '発行',
        'cancel' => 'キャンセル',
    ],
    'issue_status' => [
        'in_progress' => '処理中',
        'completed' => '発行完了',
        'failed' => '発行失敗',
    ],
    'shipping_fee_tax_policy' => [
        'proportional' => '按分 (課税商品の比率に応じて課税)',
        'taxable' => '全額課税',
        'follow_main_item' => '主要な財を従う',
    ],
    'errors' => [
        'provider_not_configured' => '現金領収書発行プロバイダーが設定されていません。',
        'no_provider_handled' => '現金領収書発行リクエストを処理したプロバイダーがありません。',
        'no_issuable_amount' => '発行可能な現金性金額がありません。',
        'identifier_unavailable' => '再発行に必要な識別番号を復号化できません。管理者が識別番号を再入力して発行する必要があります。',
        'payment_not_found' => '決済情報が見つかりません。',
        'not_cash_payment' => '銀行振込注文のみ現金領収書を発行できます。',
        'payment_not_paid' => '入金が確認された注文のみ現金領収書を発行できます。',
        'already_issued' => '既に現金領収書が発行された注文です。',
        'issue_failed' => '現金領収書の発行に失敗しました。',
        'cancel_failed' => '現金領収書のキャンセルに失敗しました。',
        'no_active_receipt' => 'キャンセルする現金領収書がありません。',
    ],
    'attributes' => [
        'receipt_type' => '発行目的',
        'identifier_type' => '発行手段',
        'identifier' => '識別番号',
    ],
    'validation' => [
        'identifier_invalid' => '識別番号の形式が正しくありません。',
        'identifier_type_not_allowed' => ':type は :identifier_type で発行できません。',
        'self_issue_income_only' => '自進発行指定番号は所得控除用でのみ発行できます。',
        'identifier_format' => [
            'phone' => '携帯電話番号は010、011、016、017、018、019で始まる10～11桁の数字である必要があります。',
            'card' => '現金領収書カード番号は13～19桁の数字である必要があります。',
            'business' => '事業者登録番号が正しくありません。10桁の数字を確認してください。',
        ],
    ],
    'messages' => [
        'issued' => '現金領収書が発行されました。',
        'cancelled' => '現金領収書がキャンセルされました。',
        'reissued' => '現金領収書が再発行されました。',
        'status_retrieved' => '現金領収書情報を照会しました。',
    ],
    'result_status' => [
        'in_progress' => '処理中',
        'completed' => '完了',
        'failed' => '失敗',
    ],
];
