<?php

/**
 * 会員項目として宣言する同意項目の名前と案内文 (Japanese)
 *
 * `Fields\MarketingConsentFields` が項目宣言(`getFields()`)とリクエスト時点の補強に使う。
 * チャネル項目の名前はチャネル設定の項目名(`channels.*.label`)をそのまま使う。
 */
return [
    'marketing_consent' => [
        'name' => 'マーケティング同意',
        'description' => 'マーケティング目的の個人情報の収集および利用に同意します。',
    ],
    'third_party_consent' => [
        'name' => '第三者提供同意',
        'description' => 'マーケティング目的の個人情報の第三者提供に同意します。',
    ],
    'info_disclosure' => [
        'name' => '情報公開同意',
        'description' => 'プロフィール情報を他のユーザーに公開します。同意しない場合、一部のコミュニティ機能の利用が制限されることがあります。',
    ],
    'terms_link' => [
        'label' => '表示',
        'title' => ':name 規約',
    ],
    'locks' => [
        'enabled' => 'この項目を使用するかどうかは、マーケティング同意プラグインの設定で決めます。',
    ],
    'consented_on' => ':date 同意',
];
