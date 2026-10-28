<?php

return [
    'action' => [
        'popup' => [
            'create' => 'ポップアップ登録',
            'update' => 'ポップアップ編集',
            'delete' => 'ポップアップ削除',
            'bulk_status' => 'ポップアップ一括表示切り替え',
            'bulk_copy' => 'ポップアップ一括コピー',
            'bulk_delete' => 'ポップアップ一括削除',
            'reorder' => 'ポップアップ順序変更',
            'image_update' => 'ポップアップ画像置換',
            'image_remove' => 'ポップアップ画像削除',
        ],
    ],
    'description' => [
        'popup_create' => 'ポップアップ「:popup_title」を登録しました。',
        'popup_update' => 'ポップアップ「:popup_title」を編集しました。',
        'popup_delete' => 'ポップアップ「:popup_title」を削除しました。',
        'popup_bulk_show' => 'ポップアップ「:popup_title」を表示に変更しました。',
        'popup_bulk_hide' => 'ポップアップ「:popup_title」を非表示に変更しました。',
        'popup_bulk_copy' => 'ポップアップをコピーして「:popup_title」を作成しました(元のID: :source_id)。',
        'popup_reorder' => 'ポップアップ :count個の順序を変更しました。',
        'popup_image_update' => 'ポップアップ「:popup_title」の :device 画像を置換しました。',
        'popup_image_remove' => 'ポップアップ「:popup_title」の :device 画像を削除しました。',
    ],
    'fields' => [
        'title' => '管理用タイトル',
        'is_active' => '使用有無',
        'content_type' => 'コンテンツタイプ',
        'display_period' => '表示期間方式',
        'starts_at' => '表示開始',
        'ends_at' => '表示終了',
        'page_scope' => '表示ページ',
        'audience' => '表示対象',
        'device' => '表示デバイス',
        'link_url' => 'リンクURL',
    ],
];
