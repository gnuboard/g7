<?php

return [
    'fetch_success' => 'ロール情報を正常に取得しました。',
    'fetch_failed' => 'ロール情報の取得に失敗しました。',
    'create_success' => 'ロールが正常に作成されました。',
    'create_failed' => 'ロール作成に失敗しました。',
    'update_success' => 'ロールが正常に編集されました。',
    'update_failed' => 'ロール編集に失敗しました。',
    'delete_success' => 'ロールが正常に削除されました。',
    'delete_failed' => 'ロール削除に失敗しました。',
    'system_role_delete_error' => 'システムロールは削除できません。',
    'validation' => [
        'name_required' => 'ロール名は必須です。',
        'identifier_required' => '識別子は必須です。',
        'identifier_format' => '識別子は小文字で始まり、小文字、数字、アンダースコア(_)のみ使用できます。',
        'identifier_unique' => '既に使用中の識別子です。',
        'identifier_max' => '識別子は最大100文字まで入力できます。',
        'permission_ids_array' => '権限リストは配列形式である必要があります。',
        'permission_ids_exists' => '選択した権限の中に無効な権限があります。',
        'permission_ids_integer' => '権限IDは整数である必要があります。',
    ],
    'errors' => [
        'system_role_delete' => 'システムロールは削除できません。',
        'extension_owned_role_delete' => '拡張(モジュール/プラグイン)が所有するロールは削除できません。該当する拡張を削除すると自動的にクリーンアップされます。',
    ],
];
