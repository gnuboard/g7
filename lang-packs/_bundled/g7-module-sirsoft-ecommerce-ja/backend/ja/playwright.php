<?php

return [
    'seed_stub_unimplemented' => 'PlaywrightSeedEcommerce はスタブです。イーコマースモジュール作業セッションで実際のシードロジック実装が必要です。',
    // Playwrightシードコマンドのコンソール出力
    'guard' => [
        'cli_only' => 'CLI専用のコマンドです。',
        'bypass_required' => '環境変数 G7_PLAYWRIGHT_BYPASS=1 が必要です。',
    ],
    'seed_claim_detail' => [
        'invalid_tag' => '--tag は英数字1〜16文字である必要があります。',
        'tag_required' => 'シードには --tag が必要です (並列ワーカー同士が互いの申請書を削除しないようにするため)。',
    ],
    'seed_site_day_orders' => [
        'no_product_options' => '商品オプションが1つもないため、注文オプションを追加できません。',
    ],
];
