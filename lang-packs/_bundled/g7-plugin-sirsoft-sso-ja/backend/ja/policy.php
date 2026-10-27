<?php

return [
    'ldap_unavailable' => 'このメールアドレスは社内アカウントでログインします。現在、社内アカウントサーバーに接続できません。しばらくしてからもう一度お試しください。',
    'site_enforced' => 'このサイトは会社アカウントでのみログインできます。管理者にお問い合わせください。',
    'password_set_disabled' => '会社アカウントでログインする会員は、このサイトのパスワードを作成できません。',
    'cli' => [
        'item' => '項目',
        'value' => '値',
        'on' => 'オン',
        'off' => 'オフ',
        'enforce_site' => 'サイト全体の強制',
        'enforce_roles' => 'ロール強制',
        'enforce_linked' => 'SSO 連携会員全員に強制',
        'enforced_idps' => 'ドメインのパスワードログインをブロックした IdP',
        'emergency_users' => '緊急アカウントの会員 ID',
        'backup' => '解除前の値を保管',
        'disabled' => 'SSO 強制をすべて解除しました。以前の値は保管しました(--restore で元に戻せます)。',
        'restored' => '保管していた SSO 強制を元に戻しました。',
        'no_backup' => '元に戻す保管値がありません。--disable で解除したことがありません。',
    ],
];
