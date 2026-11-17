<?php

return [
    // 検証状態の名前 (バッジ・コマンド出力)
    'statuses' => [
        'verified' => '署名確認済み',
        'unsigned' => '署名なし',
        'invalid' => '署名不一致',
        'untrusted' => '信頼していない鍵',
        'bundled' => 'バンドル原本',
        'unverifiable' => '署名検証不可',
        'unknown' => '記録なし',
    ],

    // 検証理由 (PackageVerification::$reason)
    'reasons' => [
        'unsigned' => 'このパッケージには署名ファイルがありません。',
        'format_unknown' => '署名ファイルの形式を認識できません(サポートしていない形式・アルゴリズムであるか、必須項目が欠けています)。',
        'type_mismatch' => '署名ファイルのパッケージ種別(:actual)が、インストールしようとしている種別(:expected)と異なります。',
        'identifier_mismatch' => '署名ファイルの識別子(:actual)が、インストールしようとしている識別子(:expected)と異なります。',
        'version_mismatch' => '署名ファイルのバージョン(:actual)が、取得しようとしているバージョン(:expected)と異なります。',
        'size_mismatch' => 'パッケージファイルのサイズが署名ファイルに記載されたサイズと異なります。ファイルが変更されたか、ダウンロードが完了していません。',
        'digest_mismatch' => 'パッケージファイルの SHA-256 値が署名ファイルと異なります。ファイルが変更されています。',
        'key_unknown' => '署名した鍵(:key)が信頼鍵の一覧にありません。',
        'key_revoked' => '署名した鍵は失効した鍵です。',
        'key_id_mismatch' => '署名鍵の識別子が登録済みの公開鍵と一致しません。',
        'core_requires_builtin_key' => 'コアパッケージはコアに内蔵された公式の鍵で署名されている必要があります。制作者の鍵で署名されたコアは受け付けません。',
        'vendor_out_of_scope' => '署名した制作者の鍵は、このパッケージの vendor(:vendor)を信頼するように登録されていません。',
        'sodium_missing' => '署名ファイルはありますが、このサーバーに PHP sodium 拡張がないため署名を確認できません。',
        'signature_mismatch' => '署名値がパッケージの内容と一致しません。ファイルまたは署名が変更されています。',
        'manifest_identifier_mismatch' => 'パッケージ内の manifest の識別子(:actual)が、署名された識別子(:expected)と異なります。',
        'manifest_version_mismatch' => 'パッケージ内の manifest のバージョン(:actual)が、署名されたバージョン(:expected)と異なります。',
    ],

    'errors' => [
        'package_unsigned' => '署名されていないパッケージです。内容を確認した場合は「未署名で続行」を押してインストールしてください。:reason',
        'package_unverifiable' => 'このサーバーではパッケージの署名を確認できません。内容を確認した場合は「未署名で続行」を押してインストールしてください。:reason',
        'package_unsigned_blocked' => '環境設定 > セキュリティの「未署名パッケージのブロック」がオンになっているため、署名されていないパッケージはインストールできません。:reason',
        'package_signature_invalid' => 'パッケージの署名が一致しないため、インストールを中止しました。:reason',
        'package_signer_untrusted' => '信頼していない鍵で署名されたパッケージのため、インストールを中止しました。:reason',
        'signature_url_not_public' => '署名ファイルの URL は公開された http・https アドレスである必要があります。',
        'signature_download_failed' => '署名ファイルをダウンロードできませんでした。(HTTP :status)',
        'signature_too_large' => '署名ファイルが大きすぎます(最大 64KB)。',
        'signature_file_not_found' => '署名ファイルが見つかりません: :path',
        'label_invalid' => '署名者名は英数字・_・- で32文字以内である必要があります。',
    ],

    // 信頼鍵の管理 (環境設定 > セキュリティ > 拡張信頼鍵、API)
    'trust_keys' => [
        'fetch_success' => '拡張信頼鍵の一覧を取得しました。',
        'created' => '信頼鍵を追加しました。',
        'deleted' => '信頼鍵を削除しました。',
        'not_found' => '信頼鍵が見つかりません。',
        'password_invalid' => 'パスワードが正しくありません。',
        'reauth_invalid' => '本人確認の有効期限が切れているか、正しくありません。もう一度確認してください。',
        'create_failed' => '信頼鍵を追加できませんでした。',
        'delete_failed' => '信頼鍵を削除できませんでした。',
        'fetch_failed' => '信頼鍵の一覧を読み込めませんでした。',
    ],

    // 信頼鍵の入力検証
    'validation' => [
        'public_key_required' => '公開鍵を入力してください。',
        'public_key_invalid' => '公開鍵は Ed25519 公開鍵(32バイト)の標準 base64 値である必要があります。',
        'label_required' => '署名者名を入力してください。',
        'label_invalid' => '署名者名は英数字・_・- で32文字以内である必要があります。',
        'label_reserved' => '署名者名 sirsoft はコア内蔵鍵専用のため使用できません。',
        'vendor_scope_required' => 'この鍵を信頼する vendor を1つ以上指定してください。',
        'vendor_invalid' => 'vendor は英小文字・数字で始まる拡張識別子の先頭部分である必要があります。',
        'vendor_reserved' => 'vendor sirsoft はコア内蔵鍵専用のため、制作者の鍵には指定できません。',
        'duplicate_key' => 'すでに登録されている鍵です。',
        'builtin_conflict' => 'コア内蔵鍵と同じ鍵は登録できません。',
        'password_required' => '本人確認のためパスワードを入力してください。',
        'allow_unsigned_boolean' => '未署名で続行するかどうかは true または false の値である必要があります。',
        'integrity_aware_boolean' => 'パッケージ署名確認画面の目印は true または false の値である必要があります。',
        'signature_file' => '署名ファイル(.sig.json)をアップロードしてください。',
        'signature_max' => '署名ファイルは 64KB を超えることはできません。',
        'signature_url_invalid' => '署名ファイルの URL の形式が正しくありません。',
        'block_unsigned_boolean' => '未署名パッケージのブロックは true または false の値である必要があります。',
    ],

    'attributes' => [
        'public_key' => '公開鍵',
        'label' => '署名者名',
        'vendor_scope' => '信頼する vendor',
        'password' => 'パスワード',
        'allow_unsigned' => '未署名で続行',
        'integrity_aware' => 'パッケージ署名確認画面の目印',
        'signature' => '署名ファイル',
        'signature_url' => '署名ファイルの URL',
        'extension_block_unsigned' => '未署名パッケージのブロック',
    ],

    'commands' => [
        'status_line' => 'パッケージ署名: :status',
        'verified_line' => 'パッケージ署名を確認しました — 署名者 :label (:key)',
        'unsigned_confirm' => 'このパッケージは署名されていません(:reason)。それでも続行しますか？',
        'unsigned_noninteractive_hint' => '内容を確認した場合は --allow-unsigned を付けて再実行してください。--force・--yes は未署名の確認の代わりにはなりません。',
        'unsigned_blocked_hint' => '環境設定 > セキュリティの「未署名パッケージのブロック」をオフにするまでは、--allow-unsigned を付けても続行できません。',
        'signature_not_found' => '署名ファイルが見つかりません: :path',
    ],

    // php artisan about のパッケージ整合性セクション
    'about' => [
        'section' => 'パッケージ整合性',
        'builtin_keys' => '内蔵信頼鍵',
        'sodium' => 'PHP sodium 拡張',
        'block_unsigned' => '未署名パッケージのブロック',
        'available' => '利用可能',
        'missing' => 'なし — 署名を検証できないため未署名パッケージとして扱います',
        'on' => 'オン',
        'off' => 'オフ',
        'unknown' => '不明',
    ],
];
