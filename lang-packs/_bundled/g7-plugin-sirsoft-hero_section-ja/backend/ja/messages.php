<?php

return [
    'banner' => [
        'fetched' => 'バナーを読み込みました。',
        'created' => 'バナーを登録しました。',
        'updated' => 'バナーを編集しました。',
        'deleted' => 'バナーを削除しました。',
        'reordered' => 'バナーの順序を変更しました。',
        'bulk_status' => 'バナー:count個の表示有無を変更しました。',
        'bulk_deleted' => 'バナー:count個を削除しました。',
    ],
    'image' => [
        'uploaded' => '画像をアップロードしました。',
        'deleted' => '画像を削除しました。',
        'delete_failed' => '画像ファイルを削除できませんでした。しばらく経ってからもう一度お試しください。',
        'pc_not_deletable' => 'PC画像は必須のため削除できません。新しい画像をアップロードして置き換えてください。',
        'not_found' => '画像が見つかりません。',
    ],
    'position' => [
        'fetched' => '表示位置の設定を読み込みました。',
        'updated' => 'スライダー設定を保存しました。',
        'not_found' => '表示位置が見つかりません。',
    ],
    'console' => [
        'prune_temp_images' => [
            'retention_disabled' => '保持期間が1日より短いため、一時画像をクリーンアップしません。',
            'dry_run' => ':days日より前の一時画像:count個がクリーンアップ対象です。',
            'done' => ':days日より前の一時画像:scanned個中:deleted個を削除しました(失敗:failed個)。',
        ],
    ],
];
