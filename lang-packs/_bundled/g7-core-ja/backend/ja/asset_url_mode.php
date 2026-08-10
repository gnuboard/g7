<?php

return [
    'current' => '現在のアセット URL 方式: :mode',
    'table' => [
        'in_use' => '使用中',
        'alternative' => '変換時',
    ],
    'diagnose_title' => 'サーバーが動的レスポンスをインターセプトしているかどうかを確認するには、以下の 2 つのリクエストを比較してください:',
    'diagnose_hint' => '最初のリクエストのみ失敗し、2 番目が成功する場合は、静的最適化設定がインターセプトしています → 拡張子なしに変換してください。',
    'switch_hint' => '変換: php artisan g7:asset-url-mode extensionless',
    'invalid' => '不明な方式です: :mode (extension または extensionless)',
    'already' => '既に :mode 方式を使用中です。',
    'switched' => 'アセット URL 方式を :from → :to に変更しました。',
    'save_failed' => '設定の保存に失敗しました。',
    'seo_cache_cleared' => 'SEO キャッシュも一緒にクリアしました (焼き込まれたアセットアドレスが古い方式のため)。',
];
