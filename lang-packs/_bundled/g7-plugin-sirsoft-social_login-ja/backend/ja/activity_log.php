<?php

return [
    'action' => [
        'account_linked' => 'ソーシャルアカウント連携',
        'account_auto_linked' => 'ソーシャルアカウント自動連携',
        'account_unlinked' => 'ソーシャルアカウント連携解除',
        'account_unlinked_by_admin' => 'ソーシャルアカウント連携の強制解除',
        'account_unlinked_by_webhook' => 'プロバイダー通知による連携解除',
        'signup' => 'ソーシャル登録',
        'provider_created' => 'ソーシャルログインプロバイダー追加',
        'provider_updated' => 'ソーシャルログインプロバイダー設定変更',
        'provider_deleted' => 'ソーシャルログインプロバイダー削除',
        'settings_updated' => 'ソーシャルログインポリシー変更',
    ],
    'description' => [
        'account_linked' => '会員 :user_name が :provider アカウントを連携しました。',
        'account_auto_linked' => '会員 :user_name に、プロバイダーが検証した同じメールの :provider アカウントを自動で連携しました。',
        'account_unlinked' => '会員 :user_name の :provider アカウント連携を解除しました。',
        'account_unlinked_by_admin' => '管理者が会員 :user_name の :provider アカウント連携を解除しました。',
        'account_unlinked_by_webhook' => ':provider の連携解除通知により、会員 :user_name の連携を解除しました。',
        'signup' => '会員 :user_name が :provider アカウントで登録しました。',
        'provider_created' => 'ソーシャルログインプロバイダー :provider を追加しました。',
        'provider_updated' => 'ソーシャルログインプロバイダー :provider の設定を変更しました。',
        'provider_deleted' => 'ソーシャルログインプロバイダー :provider を削除しました。',
        'settings_updated' => 'ソーシャルログインポリシーを保存しました。',
    ],
];
