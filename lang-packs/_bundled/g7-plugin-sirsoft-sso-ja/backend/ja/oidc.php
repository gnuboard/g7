<?php

return [
    'label' => 'OpenID Connect',
    'config' => [
        'discovery_url' => [
            'label' => 'ディスカバリーアドレス',
            'hint' => 'IdP の .well-known/openid-configuration アドレスです。',
        ],
        'client_id' => [
            'label' => 'クライアント ID',
            'hint' => 'IdP コンソールでこのサイトをアプリとして登録して受け取った値です。',
        ],
        'client_secret' => [
            'label' => 'クライアントシークレット',
            'hint' => '保存すると再表示されません。変更するときのみ新しく入力してください。',
        ],
        'scopes' => [
            'label' => 'スコープ(scope)',
            'hint' => 'スペースで区切ります。定期点検を有効にする場合は offline_access を追加してください。',
        ],
        'subject_claim' => [
            'label' => '固有識別子クレーム',
            'hint' => '既定は sub。Entra ID はアプリごとに sub が異なるため、oid を選択してください(SCIM externalId と同じ値)。',
        ],
        'subject_claim_custom' => [
            'label' => '指定クレーム名',
            'hint' => '固有識別子クレームが「指定」のときに使用するクレーム名です。',
        ],
        'claim_email' => [
            'label' => 'メールアドレスクレーム',
            'hint' => '既定は email。',
        ],
        'claim_email_verified' => [
            'label' => 'メールアドレス検証クレーム',
            'hint' => '既定は email_verified。所有ドメインでの自動連携は、この値が true のときのみ行います。',
        ],
        'claim_name' => [
            'label' => '名前クレーム',
            'hint' => '既定は name。',
        ],
        'claim_groups' => [
            'label' => 'グループクレーム',
            'hint' => '既定は groups。配列またはスペース区切りの文字列を受け付けます。',
        ],
        'mfa_acr_values' => [
            'label' => '多要素認証とみなす acr 値',
            'hint' => 'IdP が amr の代わりに acr で多要素認証を通知する場合、その値を記入します。',
        ],
        'use_pkce' => [
            'label' => 'PKCE を使用',
            'hint' => '有効にしておくことを推奨します(S256)。',
        ],
        'token_endpoint_auth' => [
            'label' => 'トークンエンドポイントの認証方式',
            'hint' => 'client_secret_post または client_secret_basic。',
        ],
        'fetch_userinfo' => [
            'label' => 'userinfo の取得',
            'hint' => 'id_token にメールアドレスがない場合のみ userinfo を取得します。',
        ],
    ],
    'sp' => [
        'redirect_uri' => 'Redirect URI (ログインコールバック)',
        'post_logout_redirect_uri' => 'ログアウト後の戻り先アドレス',
        'backchannel_logout_uri' => 'Back-channel ログアウトアドレス',
        'scopes' => 'リクエストスコープ',
    ],
    'test' => [
        'discovery_ok' => 'ディスカバリードキュメントを読み込みました。',
        'discovery_failed' => 'ディスカバリードキュメントを読み込めませんでした。',
        'metadata_ok' => '必須のメタキーがすべてそろっています。',
        'jwks_ok' => '署名鍵を読み込みました。',
        'jwks_empty' => '署名鍵の一覧が空です。',
        'jwks_failed' => '署名鍵を読み込めませんでした。',
        'redirect_uri' => 'IdP コンソールに以下の Redirect URI が登録されている必要があります。',
    ],
    'mock' => [
        'notice' => '開発・検収用のモック IdP です。応答のバリエーションを選択して承認してください。',
    ],
];
