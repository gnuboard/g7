<?php

return [
    'action' => [
        'hero_banner' => [
            'create' => 'ヒーローセクションバナー登録',
            'update' => 'ヒーローセクションバナー編集',
            'delete' => 'ヒーローセクションバナー削除',
            'bulk_status' => 'ヒーローセクションバナー一括表示切り替え',
            'bulk_delete' => 'ヒーローセクションバナー一括削除',
            'reorder' => 'ヒーローセクションバナー順序変更',
            'image_update' => 'ヒーローセクション バナー画像交換',
            'image_remove' => 'ヒーローセクション バナー画像削除',
        ],
        'hero_position' => [
            'update' => 'ヒーローセクション スライダー設定変更',
        ],
    ],
    'description' => [
        'hero_banner_create' => 'ヒーローセクションバナー「:banner_title」を登録しました。',
        'hero_banner_update' => 'ヒーローセクションバナー「:banner_title」を編集しました。',
        'hero_banner_delete' => 'ヒーローセクションバナー「:banner_title」を削除しました。',
        'hero_banner_bulk_show' => 'ヒーローセクションバナー「:banner_title」を表示に変更しました。',
        'hero_banner_bulk_hide' => 'ヒーローセクションバナー「:banner_title」を非表示に変更しました。',
        'hero_banner_reorder' => 'ヒーローセクション :position 位置のバナー :count 個の順序を変更しました。',
        'hero_banner_image_update' => 'ヒーローセクションバナー「:banner_title」の :device 画像を交換しました。',
        'hero_banner_image_remove' => 'ヒーローセクションバナー「:banner_title」の :device 画像を削除しました。',
        'hero_position_update' => 'ヒーローセクション :position 位置のスライダー設定を変更しました。',
    ],
    'fields' => [
        'position' => '表示位置',
        'is_active' => '使用有無',
        'display_period' => '表示期間方式',
        'starts_at' => '表示開始',
        'ends_at' => '表示終了',
        'title' => 'タイトル',
        'link_url' => '移動先URL',
        'autoplay' => '自動再生',
        'interval_seconds' => '切り替え間隔(秒)',
        'effect' => '切り替え効果',
    ],
];
