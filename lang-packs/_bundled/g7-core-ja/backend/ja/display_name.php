<?php

return [
    // 表示名の種類
    'kinds' => [
        'name' => '名前',
        'nickname' => 'ニックネーム',
        'masked_name' => 'マスクした名前',
        'masked_id' => 'マスクしたID',
        'name_nickname' => '名前(ニックネーム)',
    ],

    // 表示名の領域
    'areas' => [
        'site' => 'サイト既定',
        'admin' => '管理者画面',
        'notification' => '通知',
    ],

    // 領域の方式
    'modes' => [
        'inherit' => '上位領域に従う',
        'fixed' => '種類を固定',
        'member' => '会員が選んだ値',
    ],

    // プレビューに使うサンプル会員
    'sample' => [
        'name' => '山田太郎',
        'nickname' => 'たろう',
    ],

    // ニックネーム項目のロック理由
    'locks' => [
        'nickname_display' => '表示名の設定がニックネームを使用しているため、変更できません。表示名の設定からニックネームを使う種類を外すと、再び変更できます。',
    ],

    // 追加項目が表示名として使われている場所 (項目の削除を止めるときに表示する)
    'usages' => [
        'selectable_kind' => '会員が選択できる表示名の種類',
        'member_default_kind' => '会員の既定の表示名の種類',
        'area' => ':area 領域の表示名',
        'area_instance' => ':area: :instance',
    ],

    'validation' => [
        'not_permitted' => '表示名の種類を自分で選ぶ権限がありません。',
        'not_selectable' => '選択できない表示名の種類です。種類を選び直してください。',
        'unknown_kind' => '使用できない表示名の種類です: :kind',
        'unknown_area' => '使用できない表示名の領域です: :area',
        'default_not_selectable' => '会員の既定の種類は、会員が選択できる種類のいずれかである必要があります。',
        'site_cannot_inherit' => 'サイト既定の領域には従う上位領域がありません。種類を固定するか、会員が選んだ値に設定してください。',
        'fixed_kind_required' => ':area 領域に固定する表示名の種類を選んでください。',
    ],

    'attributes' => [
        'preview_name' => 'プレビューの名前',
        'preview_nickname' => 'プレビューのニックネーム',
        'preview_email' => 'プレビューのメール',
    ],
];
