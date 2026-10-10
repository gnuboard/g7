<?php

return [
    'errors' => [
        'not_found' => 'ファイルが見つかりません: :path',
        'not_editable' => 'この形式(:extension)は本文を直接編集できません。ファイルを新しくアップロードして置き換えてください。',
        'too_large_to_edit' => 'ファイルが大きすぎるため、エディターで開くことができません。(最大 :limit バイト)',
        'read_failed' => 'ファイルを読み込めませんでした: :path',
        'write_failed' => 'ファイルを保存できませんでした: :path',
        'delete_failed' => 'ファイルを削除できませんでした: :path',
        'directory_failed' => 'ディレクトリを作成できませんでした: :path — 理由 :reason、所有者 :owner、権限 :perms、実行アカウント :process_user。対処: :hint',
        'invalid_path' => '許可されていないパスです: :path',
        'invalid_extension_target' => 'ターゲット拡張が見つかりません: :identifier',
        'extension_not_allowed' => '許可されていないファイル形式です: :extension',
        'upload_too_large' => 'アップロードファイルが大きすぎます。(最大 :limit バイト)',
        'directory_failed_hint' => 'サーバーで `sudo chown -R {ウェブアカウント}:{ウェブアカウント} :path` を実行してウェブアカウントに所有者を変更するか、グループ書き込み権限(`chmod -R g+w :path`)を付与してから再度お試しください。',
        'reason' => [
            'occupied_by_file' => '同じ名前のファイルが既に存在します',
            'ancestor_not_writable' => '親ディレクトリへの書き込みができません',
            'create_failed' => 'ディレクトリの作成に失敗しました',
            'not_writable' => 'ディレクトリは存在しますが、書き込みができません',
        ],
        'rename_target_exists' => '同じ名前のファイルが既に存在します: :path',
        'rename_extension_changed' => '名前を変更する際に、拡張子を :from から :to に変更することはできません。拡張子が変更されると、そのファイルはもはや同じ方法でサーブされなくなります。',
    ],
    'validation' => [
        'path_required' => 'ファイルパスを指定してください。',
        'content_present' => '本文フィールドが必要です。',
        'file_required' => 'アップロードするファイルを選択してください。',
        'file_invalid' => '有効なファイルではありません。',
        'file_mimes' => '許可されていないファイル形式です。(許可: :allowed)',
        'rename_same_path' => '現在と異なるパスを入力してください。',
    ],
    'messages' => [
        'listed' => 'リストを読み込みました。',
        'saved' => '保存しました。',
        'uploaded' => 'ファイルをアップロードしました。',
        'deleted' => 'ファイルを削除しました。',
        'renamed' => 'ファイル名を変更しました。',
    ],
];
