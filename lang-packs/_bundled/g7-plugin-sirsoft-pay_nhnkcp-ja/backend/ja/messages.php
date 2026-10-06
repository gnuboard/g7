<?php

return [
    'refund' => [
        'missing_tid' => '取引番号(tno)がないため返金を進めることができません。',
        'default_reason' => '購入者返金リクエスト',
        'in_progress' => 'NHN KCP 返金はすでに処理中です。',
        'vbank_requires_bank_transfer' => '入金済みのバーチャル口座決済は NHN KCP で自動返金できません。返金方法を口座返金に変更し、お客様の口座へ直接送金してください。',
    ],
    'escrow' => [
        'invoice_required' => '送り状番号を入力してください。',
        'courier_required' => '配送業者を選択してください。',
    ],
    'errors' => [
        'payment_failed' => '決済処理に失敗しました。しばらく後にもう一度お試しください。',
        'wsdl_missing' => 'KCP WSDL ファイルがありません: :file',
        'approval_key_error' => 'KCP 承認キーエラー [:code]: :message',
        'soap_error' => 'KCP SOAP 連携エラー: :message',
        'cli_binary_missing' => 'KCP CLIバイナリ不足: :path',
        'cli_binary_not_executable' => 'KCP CLIバイナリ実行権限不足 — 管理者の対応が必要です (sudo chmod 755 :path)',
    ],
    'claim' => [
        'invalid_callback' => 'クレーム決済承認に必要なコールバックパラメーターがありません。',
        'window_failed' => '決済ウィンドウ認証が完了していません。',
        'currency_not_supported' => 'NHN KCP クレーム決済はKRW決済のみサポートしています。',
        'deposit_not_supported' => 'クレーム追加決済は入金待機方式(仮想口座)をサポートしていません。',
        'compensation_cancel_reason' => 'クレーム決済承認拒否に伴う自動キャンセル',
        'amount_mismatch' => '決済金額が請求額と一致しないか請求額を確認できないため、決済を確定しませんでした(承認済みの金額は取り消されます)。申請書から決済をやり直してください。',
    ],
];
