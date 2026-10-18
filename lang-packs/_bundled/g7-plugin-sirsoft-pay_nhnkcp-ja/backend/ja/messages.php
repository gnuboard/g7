<?php

return [
    'refund' => [
        'missing_tid' => '取引番号(tno)がないため返金を進めることができません。',
        'default_reason' => '購入者返金リクエスト',
        'in_progress' => 'NHN KCP 返金はすでに処理中です。',
        'vbank_requires_bank_transfer' => '入金済みの仮想口座決済は NHN KCP で自動返金できません。返金方法を口座返金に変更し、お客様の口座へ直接送金してください。',
    ],
    'escrow' => [
        'invoice_required' => '送り状番号を入力してください。',
        'courier_required' => '配送業者を選択してください。',
    ],
    'errors' => [
        'payment_failed' => '決済処理に失敗しました。しばらく後にもう一度お試しください。',
        'vbank_issuance_incomplete' => '仮想口座の発行情報がないため、発行を確認できませんでした。もう一度お支払いを行ってください。',
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
    'health' => [
        'checked' => 'システム点検が完了しました。',
        'source' => [
            'public_outbound' => '公開送信IP',
            'server_variable' => 'サーバーIP候補',
            'unavailable' => '確認が必要',
        ],
        'exec' => [
            'label' => 'PHP exec() 関数',
            'ok' => 'CLI 呼び出し可能',
            'disabled' => 'php.ini の disable_functions で exec がブロックされています。',
            'disabled_remediation' => "php.ini の disable_functions の一覧から 'exec' を削除し、PHP-FPM(または Apache)を再起動してください。\n例: sudo sed -i 's/disable_functions = .*exec.*/disable_functions =/' /etc/php/8.x/fpm/php.ini && sudo systemctl restart php8.x-fpm",
        ],
        'bin_dir' => [
            'label' => 'CLI バイナリディレクトリ',
            'ok' => 'ディレクトリあり',
            'missing' => 'bin ディレクトリがありません: :path',
            'missing_remediation' => 'プラグインを再インストールするか、KCP が提供する CLI バイナリを bin/ にコピーしてください。',
        ],
        'cli' => [
            'label' => 'CLI バイナリ (:arch) — :filename',
            'missing' => 'ファイルがありません: :path',
            'missing_remediation' => '現在の OS(:arch)に対応する KCP CLI バイナリを bin/ にコピーしてください。(テスト環境はプラグインに同梱)',
            'windows' => 'Windows バイナリ — 実行権限チェックを省略',
            'executable' => '実行権限 OK (:mode)',
            'fixed' => '実行権限を自動復旧しました: :before → 0755',
            'not_executable' => '実行権限なし (:before) — 自動復旧に失敗',
            'not_executable_no_permission' => '実行権限なし (:before) — プラグイン設定を変更する権限がないため、自動復旧を行いませんでした',
            'not_executable_remediation' => "このファイルに実行権限を付与してください:\n  chmod 755 :path\nファイルの所有者でない場合は先頭に sudo を付けてください。",
        ],
        'pub_key' => [
            'label' => 'KCP 公開鍵 (pub.key)',
            'ok' => 'ファイルあり',
            'missing' => 'pub.key ファイルがありません: :path',
            'missing_remediation' => 'KCP から提供された pub.key ファイルを bin/ にコピーしてください。(テスト環境はプラグインに同梱)',
        ],
        'soap' => [
            'label' => 'PHP SOAP 拡張',
            'ok' => 'SoapClient 使用可能',
            'missing' => 'モバイル決済 (SmartPhone Pay) が動作しません。',
            'missing_remediation' => "PHP SOAP 拡張をインストールし、PHP-FPM を再起動してください:\nUbuntu/Debian: sudo apt install php8.x-soap && sudo systemctl restart php8.x-fpm\nRHEL/CentOS:   sudo yum install php-soap && sudo systemctl restart php-fpm\n確認: php -m | grep soap",
        ],
        'wsdl' => [
            'label' => 'WSDL ファイル',
            'ok' => 'テスト/ライブ WSDL がすべてあり',
            'missing' => '不足: :files',
            'missing_remediation' => 'KCP から提供された WSDL ファイルを bin/ にコピーしてください。(テスト/ライブ用に各1つ)',
        ],
    ],
    'settings' => [
        'redirect_url_format' => '決済後の移動先アドレスは {shopBase}、/ または http(s):// で始まり、空白を含めることはできません。他サイトへ送る //アドレス と javascript: は使用できません。',
    ],
];
