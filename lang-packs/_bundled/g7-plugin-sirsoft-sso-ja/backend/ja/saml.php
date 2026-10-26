<?php

return [
    'label' => 'SAML 2.0',
    'config' => [
        'idp_metadata_url' => [
            'label' => 'IdP メタデータアドレス',
            'hint' => 'IdP が公開しているメタデータ XML のアドレスです。毎日再取得して証明書の更新に追従します。',
        ],
        'idp_metadata_xml' => [
            'label' => 'IdP メタデータ XML',
            'hint' => 'アドレスがない IdP の場合はメタデータ XML を貼り付けてください。アドレスとどちらか一方があれば十分です。',
        ],
        'sp_entity_id' => [
            'label' => 'SP Entity ID',
            'hint' => '空欄にするとこのサイトの SP メタデータアドレスを使用します。',
        ],
        'name_id_format' => [
            'label' => 'NameID 形式',
            'hint' => '既定は persistent。変わらない値を返す形式を選択してください(transient は使用できません)。',
        ],
        'subject_source' => [
            'label' => '固有識別子の取得元',
            'hint' => '既定は NameID。IdP が NameID として変わる値を返す場合は、属性を選択してください。',
        ],
        'subject_attribute' => [
            'label' => '固有識別子の属性名',
            'hint' => '固有識別子の取得元が「属性」のときに使用する属性名です。',
        ],
        'attr_email' => [
            'label' => 'メールアドレス属性',
            'hint' => '既定は email。',
        ],
        'attr_name' => [
            'label' => '名前属性',
            'hint' => '既定は displayName。',
        ],
        'attr_groups' => [
            'label' => 'グループ属性',
            'hint' => '既定は groups。値が複数ある場合はすべてグループとして受け取ります。',
        ],
        'authn_request_binding' => [
            'label' => 'ログインリクエスト方式',
            'hint' => '既定は Redirect。IdP が POST バインディングのみ受け付ける場合は POST を選択してください。',
        ],
        'want_assertions_signed' => [
            'label' => 'アサーション署名を要求',
            'hint' => '常に有効です。署名のないアサーションは受け付けません。',
        ],
        'want_messages_signed' => [
            'label' => '応答全体の署名を要求',
            'hint' => 'IdP が応答全体にも署名する場合は有効にしてください。',
        ],
        'sign_authn_requests' => [
            'label' => 'ログインリクエストの署名',
            'hint' => 'IdP が署名付きリクエストのみ受け付ける場合は有効にしてください。SP キーが必要です。',
        ],
        'want_assertions_encrypted' => [
            'label' => 'アサーション暗号化を要求',
            'hint' => '有効にすると、暗号化されていないアサーションは拒否します。SP キーが必要です。',
        ],
        'requested_authn_context' => [
            'label' => '要求する認証レベル',
            'hint' => '空欄にすると IdP の既定値に従います。AuthnContextClassRef の値を記入します。',
        ],
        'mfa_authn_contexts' => [
            'label' => '多要素認証とみなす認証レベル',
            'hint' => '応答の AuthnContextClassRef がこの一覧に含まれる場合、IdP が多要素認証を経たものとみなします。',
        ],
        'lookup_directory_idp_id' => [
            'label' => '在籍照会用ディレクトリ',
            'hint' => '同じ会社の LDAP/AD IdP を選択すると、定期点検をそのディレクトリで行います。選択しない場合、SAML の定期点検は無効です — SCIM またはセッション有効期間の上限を使用してください。',
        ],
        'lookup_match' => [
            'label' => 'ディレクトリで検索する値',
            'hint' => '既定はメールアドレス。NameID が社内 ID(sAMAccountName、UPN)の場合は「固有識別子」を選択してください。',
        ],
        'sp_certificate' => [
            'label' => 'SP 証明書',
            'hint' => '「SP キー作成」で作成するか、PEM を貼り付けてください。',
        ],
        'sp_private_key' => [
            'label' => 'SP 秘密鍵',
            'hint' => '保存すると再表示されません。変更するときのみ新しく入力してください。',
        ],
    ],
    'validation' => [
        'metadata_required' => 'IdP メタデータアドレスまたは XML のどちらかが必要です。',
        'metadata_too_large' => 'メタデータ XML が大きすぎます(1MB 以下)。',
        'metadata_invalid' => 'IdP メタデータ XML を読み取れませんでした。entityID、IdP ログインアドレス(SingleSignOnService)、署名証明書を含むメタデータ XML か確認してください。',
        'entity_id_too_long' => 'SP Entity ID は255文字以下で入力してください。',
        'assertions_must_be_signed' => 'アサーション署名の要求は無効にできません。',
        'sp_key_required' => 'ログインリクエストの署名またはアサーション暗号化を使用するには、SP 証明書と秘密鍵が必要です。',
        'sp_key_mismatch' => 'SP 秘密鍵と証明書のペアが一致しません。',
    ],
    'sp' => [
        'entity_id' => 'SP Entity ID',
        'acs_url' => 'ACS アドレス (ログイン応答の受信先)',
        'sls_url' => 'SLS アドレス (ログアウトの受信先)',
        'metadata_url' => 'SP メタデータアドレス',
        'name_id_format' => 'NameID 形式',
    ],
    'test' => [
        'site_url_invalid' => 'サイトアドレスが SAML ライブラリの URL 検査を通過しません(ホスト名にアンダースコア _ など)。SAML を使用するには、アンダースコアのないホストでサイトアドレスを設定する必要があります。',
        'metadata_ok' => 'IdP メタデータを読み込みました。',
        'metadata_failed' => 'IdP メタデータを読み込めませんでした。',
        'certificate_ok' => 'IdP 署名証明書は有効です。',
        'certificate_expired' => 'IdP 署名証明書の有効期限が切れているか、読み取れません。',
        'sso_url' => 'IdP のログインアドレスです。',
        'sp_key_ok' => 'SP キーがあります。',
        'sp_key_missing' => 'リクエスト署名またはアサーション暗号化を有効にしましたが、SP キーがありません。「SP キー作成」を押してください。',
        'sp_metadata' => 'IdP コンソールに以下の SP メタデータアドレスを登録してください。',
    ],
    'autosubmit' => [
        'title' => '会社アカウントへ移動',
        'message' => '会社アカウントのログイン画面へ移動しています。',
        'continue' => '続行',
    ],
    'metadata_refresh' => [
        'refreshed' => ':key のメタデータを更新しました (署名証明書の有効期限 :expires)。',
        'failed' => ':key のメタデータを更新できませんでした: :reason',
        'none' => '更新する SAML IdP がありません。',
    ],
    'mock' => [
        'key_invalid' => '--key は mock_ で始まる IdP キーである必要があります。',
        'logout_sent' => ':key の SLS にログアウトリクエストを送信しました (HTTP :status)。',
        'notice' => '開発・検収用のモック SAML IdP です。応答のバリエーションを選択して承認してください。',
        'idp_seeded' => 'モック SAML IdP の行(:key)を整えました。',
        'gate_off' => 'モック IdP が無効になっています。先に php artisan sirsoft-sso:mock --enable を実行してください。',
    ],
];
