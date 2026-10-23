<?php

return [
    'cache_generated' => '静的フック マッピング キャッシュを生成しました。(コア :core / モジュール :modules / プラグイン :plugins) → :path',
    'cache_cleared' => '静的フック マッピング キャッシュを削除しました。',
    'cache_absent' => '削除する静的フック マッピング キャッシュがありません。',
    'cache_load_failed' => 'フックキャッシュファイルの読み込みに失敗しました — スキャンにフォールバックします',
    'cache_invalid_structure' => 'フックキャッシュファイルの構造が正しくありません — スキャンにフォールバックします',
    'cache_stale' => 'フックキャッシュファイルの形式・コアバージョンが現在と異なります — スキャンにフォールバックします (php artisan hooks:cache で再作成してください)',
    'cache_file_written' => 'フックマッピングキャッシュファイルの生成完了',
    'cache_listener_not_found' => 'フックキャッシュ生成: リスナークラスが見つかりません',
    'cache_listener_hooks_failed' => 'フックキャッシュ生成: getSubscribedHooks() エラー — 項目を除外',
    'cache_listener_interface_missing' => 'フックキャッシュ生成: リスナーが HookListenerInterface を実装していないため除外します',
    'cache_stale_extension_changed' => '有効な拡張のファイルが変更されたためフックキャッシュが古くなりました — スキャンにフォールバックし、このリクエストの最後に再生成します',
];
