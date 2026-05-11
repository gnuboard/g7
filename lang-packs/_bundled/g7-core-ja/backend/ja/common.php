<?php

return [
    'success' => '正常に処理されました。',
    'failed' => '処理に失敗しました。',
    'error_occurred' => 'エラーが発生しました。',
    'not_found' => 'リクエストされたリソースが見つかりません。',
    'unauthorized' => '認証が必要です。',
    'forbidden' => '権限がありません。',
    'validation_failed' => '入力値のバリデーションに失敗しました。',
    'saving' => '保存中...',
    'deleting' => '削除中...',
    'processing' => '処理中...',
    'system' => 'システム',
    'yes' => 'はい',
    'no' => 'いいえ',
    'unknown' => '不明',
    'errors' => [
        'github_url_empty' => 'GitHub URLが空です。',
        'github_url_invalid' => '無効なGitHub URL形式です。',
        'github_api_failed' => 'GitHub APIの呼び出しに失敗しました。',
        'github_download_failed' => 'GitHubからのダウンロードに失敗しました。',
        'github_archive_download_failed' => 'アーカイブのダウンロードに失敗しました。(:url)',
        'zip_file_not_found' => 'ZIPファイルが見つかりません。',
        'zip_open_failed' => 'ZIPファイルを開くことができません。',
    ],
    'validation_error' => '入力値バリデーションエラー',
    'changelog_validation' => [
        'source_in' => 'ソースはactive、bundled、githubのいずれかである必要があります。',
        'version_format' => ':attribute形式が正しくありません。(例: 1.0.0、1.0.0-beta.1)',
        'to_version_required' => '開始バージョンが指定されている場合、終了バージョンも必須です。',
    ],
];
