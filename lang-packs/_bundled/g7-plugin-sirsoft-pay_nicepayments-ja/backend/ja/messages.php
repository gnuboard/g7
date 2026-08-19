<?php

return [
    'errors' => [
        'tid_required' => 'TIDを入力してください。',
        'order_not_found' => '注文が見つかりません。',
        'invalid_request' => '無効なリクエストです。',
        'invalid_amount' => 'リクエスト金額が有効ではありません。',
        'vbank_completed_requires_bank_info' => '仮想口座入金完了の場合は返金口座情報が必要です。管理者APIを通じて返金を進めてください。',
        'invalid_refund_amount' => '返金金額が有効ではありません。(リクエスト::amount)',
        'vbank_refund_order_not_found' => '返金する注文が見つかりません。',
        'vbank_refund_invalid_payment' => '返金可能なNICEペイメンツ仮想口座入金完了決済ではありません。',
        'vbank_refund_amount_mismatch' => '要求された返金金額がDBのキャンセル可能金額と一致していません。',
        'vbank_refund_already_processing' => '既に仮想口座返金処理が進行中です。',
    ],
    'refund' => [
        'missing_tid' => '取引ID(TID)がないため返金を進めることができません。',
        'default_reason' => '購入者返金リクエスト',
    ],
    'defaults' => [
        'vbank_refund_msg' => '仮想口座返金',
    ],
    'fields' => [
        'tid' => '取引ID(TID)',
        'moid' => '注文番号',
        'cancel_amt' => 'キャンセル金額',
        'cancel_msg' => 'キャンセル事由',
        'refund_acct_no' => '返金口座番号',
        'refund_bank_cd' => '返金銀行コード',
        'refund_acct_nm' => '返金口座名義人',
    ],
];
