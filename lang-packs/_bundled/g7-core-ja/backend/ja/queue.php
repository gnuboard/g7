<?php

return [
    // 応答メッセージ
    'messages' => [
        'status_loaded' => 'キューの状態を照会しました。',
        'pending_loaded' => '待機中のタスク一覧を照会しました。',
        'failed_loaded' => '失敗したタスク一覧を照会しました。',
        'failed_detail_loaded' => '失敗したタスクの詳細を照会しました。',
        'failed_not_found' => '失敗したタスクが見つかりません。すでに再試行または削除された可能性があります。',
        'logs_loaded' => '処理記録一覧を照会しました。',
        'log_detail_loaded' => '処理記録の詳細を照会しました。',
        'log_not_found' => '処理記録が見つかりません。',
        'retry_success' => 'タスクをキューに再投入しました。',
        'retry_failed' => 'タスクをキューに再投入できませんでした。',
        'retry_no_worker' => '現在のキュードライバー(sync など)にはワーカーがないため、タスクを再投入できません。環境設定 > ドライバーでキュードライバーとキューワーカーを確認してください。',
        'retry_bulk_done' => '再試行が完了しました。成功 :succeeded件、失敗 :failed件',
        'delete_success' => '失敗したタスクを削除しました。',
        'delete_bulk_done' => '削除が完了しました。成功 :succeeded件、失敗 :failed件',
    ],

    // 検証メッセージ
    'validation' => [
        'page_invalid' => 'ページ番号が正しくありません。',
        'per_page_invalid' => '1ページあたりの件数は1〜100の数値で指定してください。',
        'search_field_invalid' => '検索対象が正しくありません。',
        'search_keyword_max' => '検索語は255文字以内で入力してください。',
        'queue_invalid' => 'キュー名が正しくありません。',
        'connection_invalid' => '接続名が正しくありません。',
        'status_invalid' => '処理結果が正しくありません。',
        'date_invalid' => '日付の形式が正しくありません。',
        'date_range_invalid' => '終了日は開始日と同じかそれ以降の日付を指定してください。',
        'uuids_required' => '処理するタスクを1件以上選択してください。',
        'uuids_max' => '一度に処理できるのは最大 :max件までです。',
        'uuid_invalid' => 'タスク識別子(UUID)が正しくありません。',
        'uuid_duplicated' => '同じタスクが重複して選択されています。',
        'sort_by_invalid' => '並べ替え基準が正しくありません。',
        'sort_order_invalid' => '並べ替え順が正しくありません。',
    ],

    // 検証フィールドラベル
    'attributes' => [
        'page' => 'ページ',
        'per_page' => '1ページあたりの件数',
        'search_field' => '検索対象',
        'search_keyword' => '検索語',
        'queue' => 'キュー',
        'connection' => '接続',
        'status' => '処理結果',
        'failed_start_date' => '失敗日時の開始日',
        'failed_end_date' => '失敗日時の終了日',
        'created_start_date' => '記録日時の開始日',
        'created_end_date' => '記録日時の終了日',
        'uuids' => '選択したタスク',
        'uuid' => 'タスク識別子',
        'sort_by' => '並べ替え基準',
        'sort_order' => '並べ替え順',
    ],

    // 処理記録の結果
    'log_status' => [
        'processed' => '成功',
        'failed' => '失敗',
    ],

    // 整理コマンド (queue:prune-records)
    'prune' => [
        'description' => '保管期間を過ぎたキューの処理記録と失敗したタスクを整理します。',
        'option_days' => '保管期間(日、1〜90)。省略すると環境設定の値(既定 7日)',
        'invalid_days' => '保管期間は :min〜:max日の整数で指定してください。',
        'done' => ':days日より前の記録を整理しました — 処理記録 :logs件、失敗したタスク :failed件',
    ],
];
