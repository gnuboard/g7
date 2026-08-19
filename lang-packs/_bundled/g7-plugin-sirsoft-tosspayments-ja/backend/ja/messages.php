<?php

return [
    'refund' => [
        'missing_payment_key' => 'Toss Payments の決済キーが存在しないため、返金処理ができません。',
        'default_reason' => '顧客リクエストによるキャンセル',
        'escrow_partial_not_allowed' => 'エスクロー決済は部分キャンセルに対応していません。全額キャンセルのみ可能です。',
        'missing_refund_account' => '仮想口座決済をキャンセルするには、返金を受け取る口座情報（銀行、口座番号、預金者名）が必要です。',
    ],
    'settings_validation' => [
        'vbank_valid_hours_range' => '仮想口座の入金期限は :min～:max時間(最大90日)の間である必要があります。',
        'use_escrow_invalid' => 'エスクロー使用設定値が正しくありません。',
    ],
    'cash_receipt' => [
        'provider_name' => 'Toss Payments',
        'invalid_order_id' => '現金領収書発行識別子がToss Payments形式（英数字·ハイフン·アンダースコア 6～64文字）に一致していません。',
        'cancel_reason' => '注文金額変更に伴う再発行',
    ],
    'errors' => [
        'payment_failed' => '決済処理に失敗しました。しばらく後に再度お試しください。',
    ],
];
