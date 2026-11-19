<?php

return [
    'disk_label' => 'CloudFront (S3 + CDN)',
    'retry' => [
        'summary' => 'CDN キャッシュ無効化の再試行: :retried 件中 成功 :succeeded 件、失敗 :failed 件',
        'abandoned' => '再試行の上限に達して停止した無効化が :count 件あります。プラグイン設定画面で最後のエラーを確認してください。',
    ],
    'test_connection' => [
        'success' => 'CloudFront ディストリビューションに接続しました。',
        'missing_credentials' => 'ディストリビューション ID と無効化用のアクセスキー、シークレットキーをすべて保存してからテストしてください。',
        'request_failed' => 'CloudFront ディストリビューションの照会に失敗しました: :error',
    ],
];
