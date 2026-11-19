<?php

/**
 * 이미지 썸네일 — 관리 API 응답·명령 출력·원본 제공자 라벨
 */
return [
    'messages' => [
        'stats_loaded' => 'サムネイルの使用量を読み込みました。',
        'purged' => 'サムネイルを削除しました。',
        'pregenerate_progressed' => '既存画像のサムネイルを作成しました。',
    ],

    // 원본 제공자 라벨 (ThumbnailSourceProvider::label)
    'sources' => [
        'core_attachment' => '添付ファイルの画像',
        'layout_attachment' => 'レイアウトエディターの画像',
    ],

    // 썸네일 전용 디스크 라벨 (공개 자산 디스크는 드라이버 카탈로그 라벨을 쓴다)
    'disks' => [
        'thumbnails' => 'サーバー非公開ストレージ',
    ],

    'attributes' => [
        'location' => '保存先',
        'preset' => 'サムネイル規格',
        'cursor' => '再開位置',
        'sources' => '元画像の種類',
        'presets' => 'サムネイル規格',
    ],

    'console' => [
        'prune' => [
            'disabled' => 'サムネイルの自動整理がオフのため実行しませんでした。(upload.thumbnail_prune_enabled = false)',
            'no_engine' => '使用できる画像処理エンジンがないため、規格の変更は確認しませんでした。',
            'disk_failed' => ':disk ディスクを読み取れなかったためスキップしました: :error',
            'dry_run' => '整理対象のサムネイル :total 件 (規格の変更 :stale、存在しない規格 :unknown、元画像なし :orphan、:days 日以上そのまま :expired)',
            'done' => 'サムネイル :total 件を削除しました。(規格の変更 :stale、存在しない規格 :unknown、元画像なし :orphan、:days 日以上そのまま :expired、失敗 :failed)',
            'days_unset' => '-',
        ],
        'pregenerate' => [
            'unknown_source' => '不明な元画像の種類です: :value (使用できる値: :allowed)',
            'unknown_preset' => '不明なサムネイル規格です: :value (使用できる値: :allowed)',
            'invalid_limit' => '--limit の値は 1 以上の整数である必要があります (入力: :value)',
            'no_engine' => '使用できる画像処理エンジンがないため、サムネイルを作成できません。',
            'nothing' => 'サムネイルを作成する元画像がありません。',
            'dry_run' => 'サムネイルを作成する元画像 :processed 件 (全体 :total 件)',
            'done' => '元画像 :processed 件を確認し、サムネイル :generated 件を新しく作成しました。(全体 :total 件)',
        ],
    ],
];
