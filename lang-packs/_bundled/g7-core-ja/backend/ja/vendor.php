<?php

return [
    'mode' => [
        'auto' => '自動 (推奨)',
        'composer' => 'Composer 実行',
        'bundled' => 'バンドル Vendor 使用',
    ],
    'installer' => [
        'checking_bundle' => 'Vendor バンドル検証中...',
        'extracting_bundle' => 'Vendor バンドル抽出中 ({current}/{total})',
        'running_composer' => 'Composer install 実行中...',
        'mode_label' => 'Vendor インストール方式',
        'mode_hint' => 'Composer が使用できない環境ではバンドルモードを選択してください。',
    ],
    'build' => [
        'start' => '{target} ビルド中...',
        'success' => '{target} ビルド完了 ({size}, {packages} packages)',
        'skipped_no_deps' => '{target} スキップ (外部 composer 依存関係なし)',
        'skipped_not_installed' => '{target} スキップ (拡張がインストールされていません)',
        'up_to_date' => '{target}: up-to-date',
        'stale' => '{target}: STALE — 再ビルドが必要です',
    ],
];
