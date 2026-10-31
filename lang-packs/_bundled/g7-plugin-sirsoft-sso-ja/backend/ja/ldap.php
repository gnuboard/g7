<?php

return [
    'label' => 'LDAP / Active Directory',
    'config' => [
        'directory_type' => [
            'label' => 'ディレクトリの種類',
            'hint' => 'OpenLDAP または Active Directory。選択すると、検索フィルター、固有識別子属性、無効判定ルールの既定値が入力されます。',
        ],
        'host' => [
            'label' => 'サーバーアドレス',
            'hint' => 'ホスト名または IP です。ldap:// のような接頭辞は付けません。',
        ],
        'port' => [
            'label' => 'ポート',
            'hint' => 'StartTLS・暗号化なしは通常 389、LDAPS は 636 です。',
        ],
        'encryption' => [
            'label' => '暗号化',
            'hint' => 'StartTLS または LDAPS を使用してください。「なし」はパスワードが平文で送受信されるため、本番環境では保存できません。',
        ],
        'verify_certificate' => [
            'label' => 'サーバー証明書の検証',
            'hint' => '有効にしておくことを推奨します。社内 CA 証明書は下の CA バンドルに貼り付けてください。',
        ],
        'ca_bundle' => [
            'label' => 'CA 証明書バンドル',
            'hint' => 'サーバー証明書を発行した CA 証明書(PEM)を貼り付けます。空欄にするとサーバーの既定の信頼ストアを使用します。',
        ],
        'bind_dn' => [
            'label' => 'サービスアカウント DN',
            'hint' => 'ユーザーを検索するときに使用するアカウントです。空欄にすると匿名で検索します。',
        ],
        'bind_password' => [
            'label' => 'サービスアカウントのパスワード',
            'hint' => '保存すると再表示されません。変更するときのみ新しく入力してください。',
        ],
        'base_dn' => [
            'label' => '検索ベース DN',
            'hint' => '例: ou=people,dc=example,dc=com',
        ],
        'user_filter' => [
            'label' => 'ユーザー検索フィルター',
            'hint' => '{identifier} の位置にログイン欄の入力値がエスケープされて入ります。結果がちょうど1件のときのみログインします。',
        ],
        'subject_attribute' => [
            'label' => '固有識別子属性',
            'hint' => '変わらない値を選択してください。OpenLDAP は entryUUID、Active Directory は objectGUID です。',
        ],
        'email_attribute' => [
            'label' => 'メールアドレス属性',
            'hint' => '既定は mail。',
        ],
        'name_attribute' => [
            'label' => '名前属性',
            'hint' => '既定は displayName。',
        ],
        'group_attribute' => [
            'label' => 'グループ属性',
            'hint' => '既定は memberOf。値が DN の場合は最初の名前(CN)をグループ名として使用します。',
        ],
        'group_filter' => [
            'label' => 'グループ検索フィルター',
            'hint' => 'memberOf がないディレクトリでは、{dn} の位置にユーザー DN が入るグループ検索フィルターを記入します。例: (&(objectClass=groupOfNames)(member={dn}))',
        ],
        'group_name_from_dn' => [
            'label' => 'グループ DN から名前のみを使用',
            'hint' => '有効にすると CN=人事部,OU=Groups,… を「人事部」として受け取ります。',
        ],
        'accept_username' => [
            'label' => '社内 ID でのログインを受け付ける',
            'hint' => '有効にするとログイン欄が「メールアドレスまたは社内 ID」に変わり、社内 ID でもログインできるようになります。',
        ],
        'disabled_rule' => [
            'label' => '退職(無効)の判定',
            'hint' => 'Active Directory はアカウントの使用停止表示、OpenLDAP は「検索結果なし」または指定した属性値で判定します。',
        ],
        'disabled_attribute' => [
            'label' => '無効表示属性',
            'hint' => '判定方式が「属性値」のときに参照する属性名です。',
        ],
        'disabled_value' => [
            'label' => '無効表示値',
            'hint' => 'その属性がこの値の場合、無効とみなします。',
        ],
        'timeout' => [
            'label' => 'タイムアウト(秒)',
            'hint' => '既定は5秒。サーバーが応答しない場合、この時間が経過すると「社内アカウントサーバーに接続できません」として終了します。',
        ],
    ],
    'validation' => [
        'host' => 'サーバーアドレスにはホスト名または IP のみを入力してください。',
        'port' => 'ポートは1〜65535の範囲で入力してください。',
        'plaintext_refused' => '本番環境では暗号化なしの LDAP 接続は使用できません。StartTLS または LDAPS を選択してください。',
        'user_filter' => 'ユーザー検索フィルターは括弧で始まり、{identifier} を含む必要があります。',
        'group_filter' => 'グループ検索フィルターは {dn} を含み、括弧の対応が正しい必要があります。',
        'attribute' => '属性名の形式が正しくありません。',
        'timeout' => 'タイムアウトは1〜30秒で入力してください。',
    ],
    'sp' => [
        'login_field' => 'ログイン欄',
        'directory' => 'ディレクトリサーバー',
    ],
    'test' => [
        'extension_missing' => 'サーバーに PHP LDAP 拡張がありません。',
        'connect_ok' => 'ディレクトリサーバーに接続しました。',
        'connect_failed' => 'ディレクトリサーバーに接続できませんでした。',
        'plaintext' => '暗号化なしで接続しました。本番環境では StartTLS または LDAPS を使用してください。',
        'bind_ok' => 'サービスアカウントでバインドしました。',
        'bind_failed' => 'サービスアカウントのバインドが拒否されました。DN とパスワードを確認してください。',
        'base_dn_ok' => '検索ベース DN が見つかりました。',
        'base_dn_empty' => '検索ベース DN の配下で何も見つかりませんでした。',
        'search_failed' => '検索中にエラーが発生しました。',
        'account_ok' => 'このアカウントで認証しました。',
    ],
    'command' => [
        'not_ldap' => 'LDAP IdP ではありません。',
        'password_env_missing' => '--password-env で指定した環境変数にパスワードがありません。',
    ],
];
