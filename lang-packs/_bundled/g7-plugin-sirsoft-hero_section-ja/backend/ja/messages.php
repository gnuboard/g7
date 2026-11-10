<?php

return [
    'banner' => [
        'conflict' => '別の管理者が先に編集しました。入力内容は保存されています。最新のバナーを別のタブで確認し、変更内容を比較してください。',
        'fetched' => 'バナーを読み込みました。',
        'created' => 'バナーを登録しました。',
        'updated' => 'バナーを編集しました。',
        'deleted' => 'バナーを削除しました。',
        'reordered' => 'バナーの順序を変更しました。',
        'bulk_status' => 'バナー :count個の表示状態を変更しました。',
        'bulk_deleted' => 'バナー :count個を削除しました。',
    ],
    'image' => [
        'uploaded' => '画像をアップロードしました。',
        'deleted' => '画像を削除しました。',
        'delete_failed' => '画像ファイルを削除できませんでした。しばらく後に再度お試しください。',
        'attached_not_deletable' => 'バナーに添付された画像はここで削除できません。バナー編集画面で変更して保存してください。',
        'not_found' => '画像が見つかりません。',
    ],
    'thumbnail' => [
        'source_label' => 'ヒーローセクションのバナー画像',
        'list_preset_label' => 'バナー一覧',
    ],
    'position' => [
        'fetched' => '表示位置設定を読み込みました。',
        'updated' => 'スライダー設定を保存しました。',
        'not_found' => '表示位置が見つかりません。',
        'targets_fetched' => '対象一覧を読み込みました。',
    ],
    'console' => [
        'prune_temp_images' => [
            'retention_disabled' => '保持期間が1日より短いため、一時画像をクリーンアップしません。',
            'dry_run' => ':days日より古い一時画像 :count個がクリーンアップ対象です。',
            'done' => ':days日より古い一時画像 :scanned個中 :deleted個を削除しました(失敗 :failed個)。',
        ],
    ],
];
