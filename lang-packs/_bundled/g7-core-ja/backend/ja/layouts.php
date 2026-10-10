<?php

return [
    'title' => 'レイアウト管理',
    'messages' => [
        'retrieved' => 'レイアウト情報を閲覧しました。',
        'created' => 'レイアウトファイルを作成しました。',
        'renamed' => 'レイアウト名を変更しました。',
        'deleted' => 'レイアウトファイルを削除しました。',
    ],
    'errors' => [
        'not_found' => 'レイアウトが見つかりません。',
        'invalid_name' => '使用できない名前です: :name — 英数字・アンダースコア(_)・ハイフン(-)とフォルダ区切りスラッシュ(/)のみ使用できます。',
        'name_taken' => '同じ名前のレイアウトが既に存在します: :name',
        'delete_blocked_source_type' => ':source(:identifier)が提供するレイアウトは削除できません。拡張を無効化または削除してください。',
        'rename_blocked_source_type' => ':source(:identifier)が提供するレイアウトは名前を変更できません。',
        'delete_blocked_by_reference' => ':count個のレイアウトがこのファイルを参照しているため削除できません。参照を先に整理してください。',
        'rename_blocked_by_reference' => ':count個のレイアウトがこのファイルを参照しているため名前を変更できません。参照を先に整理してください。',
        'delete_requires_confirmation' => 'このレイアウトは :route 画面に接続されています。削除すると、その画面を開くことができなくなります。',
    ],
    'reference_kinds' => [
        'extends' => '継承(extends)',
        'partial' => 'フラグメント参照(include·modal)',
        'route' => 'ルート接続',
    ],
];
