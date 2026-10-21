<?php

return [
    'page' => [
        'not_found' => 'ページが見つかりません。',
        'fetch_success' => 'ページ情報を閲覧しました。',
        'fetch_failed' => 'ページ情報の閲覧に失敗しました。',
        'create_success' => 'ページが作成されました。',
        'create_failed' => 'ページの作成に失敗しました。',
        'update_success' => 'ページが編集されました。',
        'update_failed' => 'ページの編集に失敗しました。',
        'delete_success' => 'ページが削除されました。',
        'delete_failed' => 'ページの削除に失敗しました。',
        'publish_success' => 'ページの公開状態が変更されました。',
        'publish_failed' => 'ページの公開状態の変更に失敗しました。',
        'bulk_publish_success' => ':count個のページの公開状態が変更されました。',
        'bulk_publish_failed' => '一括公開状態の変更に失敗しました。',
        'restore_success' => '以前のバージョンに復元されました。',
        'restore_failed' => 'バージョン復元に失敗しました。',
        'slug_check_success' => 'スラッグの重複確認が完了しました。',
    ],
    'attachment' => [
        'not_found' => '添付ファイルが見つかりません。',
        'file_not_found' => '添付ファイルがストレージに存在しません。',
        'preview_not_supported' => 'プレビューに対応していないファイル形式です。',
        'upload_success' => '添付ファイルがアップロードされました。',
        'upload_failed' => '添付ファイルのアップロードに失敗しました。',
        'delete_success' => '添付ファイルが削除されました。',
        'delete_failed' => '添付ファイルの削除に失敗しました。',
        'reorder_success' => '添付ファイルの順序が変更されました。',
        'reorder_failed' => '添付ファイルの順序の変更に失敗しました。',
        'settings_success' => '添付ファイル設定を閲覧しました。',
    ],
    'errors' => [
        'not_found' => 'ページが見つかりません。',
        'version_not_found' => '該当するバージョンが見つかりません。',
        'version_belongs_to_different_page' => '指定されたバージョンは該当ページに属していません。',
        'permission_denied' => '権限がありません。',
        'validation_failed' => '入力値が正しくありません。',
        'attachment_limit_exceeded' => '添付ファイルは最大:limit個まで登録できます。(リクエスト: :attempted個)',
    ],
    // コンソールコマンド出力
    'console' => [
        'playwright_seed' => [
            'cli_only' => 'CLI専用のコマンドです。',
            'bypass_required' => '環境変数 G7_PLAYWRIGHT_BYPASS=1 が必要です。',
        ],
        'seed_page' => [
            'done' => 'ページのシードが完了しました: 未公開=:unpublished / 公開=:published',
        ],
        'prune_temp_attachments' => [
            'retention_disabled' => '一時添付ファイルの保持期間が1日未満のため、クリーンアップを実行しませんでした。',
            'dry_run' => '保持期間(:days日)を過ぎた未連結の一時ページ添付ファイル: :count件',
            'done' => '保持期間(:days日)を過ぎた一時ページ添付ファイル:scanned件のうち:deleted件を削除しました。(失敗:failed件)',
        ],
    ],
    // ページモジュールシーダーのコンソール出力
    'seeders' => [
        'activity_log_sample' => [
            'skip_no_admin' => '管理者ユーザーがいないため、ページアクティビティログシーダーをスキップします。',
            'unmarked_kept' => 'マーカーのないページアクティビティログ :count 件は運用ログとみなして残します(以前のバージョンのシーダーが作成したサンプルにもマーカーがないため、整理されません)。',
            'skip_no_pages' => '今回の実行で新たに作成されたページがないため、ページアクティビティログをスキップします。',
            'done' => 'ページアクティビティログ :count 件の作成完了。',
        ],
        'database' => [
            'start' => 'ページモジュールシーダーの実行開始',
            'sample_start' => 'ページサンプルシーダーの実行',
            'done' => 'ページモジュールシーダーの実行完了',
        ],
        'page_sample' => [
            'start' => 'ページデモサンプルを作成中...',
            'done' => 'ページデモサンプルの作成完了: :count 個',
            'no_gd' => 'GD 拡張がないため、添付画像を作成しませんでした。',
            'created' => '[:slug] 作成完了',
            'exists_skip' => '[:slug] は既に存在します。スキップします。',
        ],
        'page' => [
            'start' => 'ページデータを作成中...',
            'exists_skip' => '[:slug] は既に存在します。スキップします。',
            'status' => [
                'published' => '公開',
                'unpublished' => '非公開',
            ],
            'created' => '[:slug] 作成完了(:status)',
            'done' => 'ページデータの作成完了: :count 個',
        ],
    ],
];
