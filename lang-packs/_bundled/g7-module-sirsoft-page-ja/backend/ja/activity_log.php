<?php

return [
    // アクションラベル (最後のセグメント基準)
    'action' => [
        'create' => '作成',
        'delete' => '削除',
        'publish' => '公開',
        'restore' => '復元',
        'unpublish' => '公開キャンセル',
        'update' => '編集',
        'upload' => 'アップロード',
    ],

    'description' => [
        'page_index' => 'ページ一覧の閲覧',
        'page_show' => 'ページの詳細閲覧 (:title)',
        'page_create' => 'ページの作成 (:title)',
        'page_update' => 'ページの編集 (:title)',
        'page_delete' => 'ページの削除 (:title)',
        'page_publish' => 'ページの公開 (:title)',
        'page_unpublish' => 'ページの公開キャンセル (:title)',
        'page_restore' => 'ページの復元 (:title)',
        'page_attachment_upload' => 'ページ添付ファイルのアップロード (ページ: :title)',
        'page_attachment_delete' => 'ページ添付ファイルの削除 (ページ: :title)',
        'page_attachment_reorder' => 'ページ添付ファイルの並べ替え (ページ: :title)',
    ],
    'fields' => [
        'slug' => 'スラッグ',
        'content_mode' => 'コンテンツモード',
        'published' => '公開状態',
        'published_at' => '公開日',
    ],
];
