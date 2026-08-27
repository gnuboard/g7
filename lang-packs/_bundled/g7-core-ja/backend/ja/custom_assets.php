<?php

return [
    'errors' => [
        'not_found' => 'ファイルが見つかりません: :path',
        'not_editable' => 'この形式(:extension)は本文を直接編集できません。ファイルを新しくアップロードして置き換えてください。',
        'too_large_to_edit' => 'ファイルが大きすぎるため、エディターで開くことができません。(最大 :limit バイト)',
        'read_failed' => 'ファイルを読み込めませんでした: :path',
        'write_failed' => 'ファイルを保存できませんでした: :path',
        'delete_failed' => 'ファイルを削除できませんでした: :path',
        'directory_failed' => 'ディレクトリを作成できませんでした: :path',
        'invalid_path' => '許可されていないパスです: :path',
        'invalid_extension_target' => 'ターゲット拡張が見つかりません: :identifier',
        'extension_not_allowed' => '許可されていないファイル形式です: :extension',
        'upload_too_large' => 'アップロードファイルが大きすぎます。(最大 :limit バイト)',
    ],
    'validation' => [
        'path_required' => 'ファイルパスを指定してください。',
        'content_present' => '本文フィールドが必要です。',
        'file_required' => 'アップロードするファイルを選択してください。',
        'file_invalid' => '有効なファイルではありません。',
        'file_mimes' => '許可されていないファイル形式です。(許可: :allowed)',
    ],
    'messages' => [
        'listed' => 'リストを読み込みました。',
        'saved' => '保存しました。',
        'uploaded' => 'ファイルをアップロードしました。',
        'deleted' => 'ファイルを削除しました。',
    ],
];
