<?php

/**
 * イーコマースモジュールが会員項目セット(core.user)に宣言する項目の名前と案内文。
 *
 * 宣言(`module.php` の `getFields()`)がこのファイルを直接読み込んで多言語配列を作る。
 */
return [
    'user' => [
        'ecommerce_preferred_currency' => [
            'name' => '決済通貨',
            'description' => '注文と決済に使用する通貨です。',
        ],
        'ecommerce_preferred_shipping_country' => [
            'name' => '配送国',
            'description' => '既定で使用する配送国です。',
            // 保存された配送国が現在は配送可能な国でないとき、my情報編集の項目案内の位置に載せる文
            'saved_not_applied' => '保存された配送国 :saved: 現在は配送できない国のため適用されません。現在適用中の配送国: :applied',
            // 海外配送をオフにしている間、運営者の項目表の「必須」欄に表示するロック理由
            'required_locked' => '海外配送を使用していない間は会員に配送国を尋ねないため、必須は適用されません。',
        ],
    ],

    // 注文書項目セット(sirsoft-ecommerce.order_contact)の名前と、このモジュールが宣言する項目
    'order_contact' => [
        'set_label' => '注文書項目',
        'locked' => '注文と配送に欠かせない項目のため、使用と必須は変更できません。',
        'orderer_name' => ['name' => '注文者名'],
        'orderer_phone' => ['name' => '注文者連絡先'],
        'orderer_email' => [
            'name' => '注文者メール',
            'description' => '非会員の注文では常に必須です。',
        ],
        'recipient_name' => ['name' => '受取人名'],
        'recipient_phone' => ['name' => '受取人連絡先'],
        'address' => ['name' => '配送先住所'],
        'address_detail' => ['name' => '詳細住所'],
        'shipping_memo' => ['name' => '配送メモ'],
    ],
];
