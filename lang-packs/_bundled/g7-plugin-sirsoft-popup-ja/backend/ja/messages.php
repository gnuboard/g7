<?php

return [
    'popup' => [
        'conflict' => '別の管理者がすでに編集しました。入力内容は保存されています。最新のポップアップと比較した後、再度保存してください。',
        'fetched' => 'ポップアップを読み込みました。',
        'created' => 'ポップアップを登録しました。',
        'updated' => 'ポップアップを編集しました。',
        'deleted' => 'ポップアップを削除しました。',
        'reordered' => 'ポップアップの順序を変更しました。',
        'bulk_status' => 'ポップアップ :count個の表示状態を変更しました。',
        'bulk_copied' => 'ポップアップ :count個をコピーしました。コピー版は非有効化されて最後に追加されました。',
        'bulk_copied_partial' => 'ポップアップ :count個をコピーしましたが、:failed個はコピーできませんでした。画像ファイルを確認した後、再度実行してください。',
        'bulk_deleted' => 'ポップアップ :count個を削除しました。',
        'copy_suffix' => ' (コピー)',
    ],
    'image' => [
        'uploaded' => '画像をアップロードしました。',
        'deleted' => '画像を削除しました。',
        'delete_failed' => '画像ファイルを削除できませんでした。しばらく経ってからもう一度試してください。',
        'attached_not_deletable' => 'ポップアップに付いた画像はここから削除できません。ポップアップ編集画面で変更して保存してください。',
        'not_found' => '画像が見つかりません。',
    ],
    'settings' => [
        'fetched' => '表示方法を読み込みました。',
    ],
    'console' => [
        'prune_temp_images' => [
            'retention_disabled' => '保存期間が1日より短いため、一時画像をクリーンアップしません。',
            'dry_run' => ':days日以上前の一時画像 :count個がクリーンアップ対象です。',
            'done' => ':days日以上前の一時画像 :scanned個中 :deleted個を削除しました(失敗 :failed個)。',
        ],
    ],
];
