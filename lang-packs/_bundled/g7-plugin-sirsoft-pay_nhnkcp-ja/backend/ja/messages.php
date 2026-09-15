<?php

return [
    'refund' => [
        'missing_tid' => '取引番号(tno)がないため返金を進めることができません。',
        'default_reason' => '購入者返金リクエスト',
        'in_progress' => 'NHN KCP 返金はすでに処理中です。',
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
];
