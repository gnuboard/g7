<?php

return [
    'refund' => [
        'missing_payment_key' => 'Toss Payments の決済キーが存在しないため、返金処理ができません。',
        'default_reason' => '顧客リクエストによるキャンセル',
        'pg_failed' => '決済代行会社との通信中にエラーが発生しました。しばらくしてからもう一度お試しください。',
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
        'pg_failed' => '決済代行会社との通信中にエラーが発生したため、現金領収書を処理できませんでした。しばらくしてからもう一度お試しください。',
    ],
    'errors' => [
        'payment_failed' => '決済処理に失敗しました。しばらく後に再度お試しください。',
        'approval_verification_cancel_reason' => '決済承認検証失敗による自動キャンセル',
    ],
    'claim' => [
        'invalid_callback' => 'クレーム決済承認に必要なコールバックパラメータ(paymentKey, orderId, amount)がありません。',
        'order_id_mismatch' => 'コールバックの注文番号が承認対象クレーム決済番号と一致しません。',
        'deposit_not_supported' => 'クレーム追加決済は入金待機方式(仮想口座)をサポートしていません。',
        'amount_mismatch' => '決済画面を開いた後に請求額が変更されました。申請書から決済をやり直してください。',
        'amount_mismatch_cancel_reason' => 'クレーム決済承認金額不一致による自動キャンセル',
    ],
    'settings' => [
        'redirect_url_format' => '決済後の移動先アドレスは {shopBase}、/ または http(s):// で始まり、空白を含めることはできません。他サイトへ送る //アドレス と javascript: は使用できません。',
    ],
    // 決済画面・決済代行会社連携 API のエラー応答メッセージ
    'responses' => [
        'amount_mismatch' => '決済金額が注文金額と一致しません。',
        'currency_not_chargeable' => '決済通貨で請求額を計算できません。',
        'order_not_found' => '注文が見つかりません。',
        'ownership_verification_failed' => '注文の所有者確認に失敗しました。',
        'too_many_requests' => 'リクエストが多すぎます。しばらくしてから再度お試しください。',
    ],
];
