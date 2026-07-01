<?php

return [
    'fields_invalid' => '選択した :value フィールドは許可されていません。',
    'slug' => [
        'required' => '掲示板スラッグは必須です。',
        'format' => '掲示板スラッグは英小文字で始まり、英小文字、数字、ハイフン(-)のみ使用できます。',
        'unique' => '既に使用中の掲示板スラッグです。',
        'reserved' => ':value は予約済みのスラッグです。別の名前をご使用ください。',
        'max' => '掲示板スラッグは :max 文字を超えることはできません。',
    ],
    'name' => [
        'required' => '掲示板名は必須です。',
        'string' => '掲示板名は文字列である必要があります。',
        'max' => '掲示板名は :max 文字を超えることはできません。',
    ],
    'type' => [
        'required' => '掲示板タイプは必須です。',
    ],
    'per_page' => [
        'required' => 'ページあたりの投稿数は必須です。',
        'min' => 'ページあたりの投稿数は最小 :min 件以上である必要があります。',
        'max' => 'ページあたりの投稿数は最大 :max 件を超えることはできません。',
    ],
    'per_page_mobile' => [
        'required' => 'モバイルページあたりの投稿数は必須です。',
        'min' => 'モバイルページあたりの投稿数は最小 :min 件以上である必要があります。',
        'max' => 'モバイルページあたりの投稿数は最大 :max 件を超えることはできません。',
    ],
    'order_by' => [
        'required' => '並べ替え基準は必須です。',
        'in' => '並べ替え基準は created_at、view_count、title、author のいずれかである必要があります。',
    ],
    'order_direction' => [
        'required' => '並べ替え方向は必須です。',
        'in' => '並べ替え方向は ASC または DESC のみ使用できます。',
    ],
    'categories' => [
        'array' => 'カテゴリーは配列形式である必要があります。',
        'max' => 'カテゴリーは最大 :max 個まで追加できます。',
        'item_max' => 'カテゴリー名は :max 文字を超えることはできません。',
        'item_required' => '空のカテゴリー名は使用できません。',
    ],
    'show_view_count' => [
        'required' => '閲覧数表示の有無は必須です。',
    ],
    'secret_mode' => [
        'required' => '秘密投稿モードは必須です。',
        'in' => '秘密投稿モードは disabled、enabled、always のいずれかである必要があります。',
    ],
    'use_comment' => [
        'required' => 'コメント使用の有無は必須です。',
    ],
    'use_reply' => [
        'required' => '返信使用の有無は必須です。',
    ],
    'use_report' => [
        'required' => '通報機能使用の有無は必須です。',
    ],
    'min_title_length' => [
        'min' => '最小タイトル文字数は :min 文字以上である必要があります。',
        'max' => '最小タイトル文字数は :max 文字を超えることはできません。',
    ],
    'max_title_length' => [
        'min' => '最大タイトル文字数は :min 文字以上である必要があります。',
        'max' => '最大タイトル文字数は :max 文字を超えることはできません。',
        'gte_min' => '最大タイトル文字数は最小タイトル文字数より小さくすることはできません。',
    ],
    'min_content_length' => [
        'min' => '最小投稿文字数は :min 文字以上である必要があります。',
        'max' => '最小投稿文字数は :max 文字を超えることはできません。',
    ],
    'max_content_length' => [
        'min' => '最大投稿文字数は :min 文字以上である必要があります。',
        'max' => '最大投稿文字数は :max 文字を超えることはできません。',
        'gte_min' => '最大投稿文字数は最小投稿文字数より小さくすることはできません。',
    ],
    'min_comment_length' => [
        'min' => '最小コメント文字数は :min 文字以上である必要があります。',
        'max' => '最小コメント文字数は :max 文字を超えることはできません。',
    ],
    'max_comment_length' => [
        'min' => '最大コメント文字数は :min 文字以上である必要があります。',
        'max' => '最大コメント文字数は :max 文字を超えることはできません。',
        'gte_min' => '最大コメント文字数は最小コメント文字数より小さくすることはできません。',
    ],
    'use_file_upload' => [
        'required' => 'ファイルアップロード使用の有無は必須です。',
    ],
    'max_file_size' => [
        'min' => '最大ファイルサイズは最小 :min MB 以上である必要があります。',
        'max' => '最大ファイルサイズは :max MB を超えることはできません。',
    ],
    'max_file_count' => [
        'min' => '最大ファイル個数は最小 :min 個以上である必要があります。',
        'max' => '最大ファイル個数は :max 個を超えることはできません。',
    ],
    'allowed_extensions' => [
        'min' => '許可するファイル拡張子を最低1つ以上入力してください。',
    ],
    'permissions' => [
        'required' => '権限設定は必須です。',
        'roles_required' => '各権限に最低1つ以上のロールが必要です。次の権限にロールを設定してください: :permissions',
        'roles' => [
            'required' => '権限にロールを選択してください。',
            'min' => '権限に最低1つ以上のロールを選択してください。',
            'exists' => '存在しないロールです。',
        ],
    ],
    'max_reply_depth' => [
        'min' => '返信の最大階層は最小 :min 以上である必要があります。',
        'max' => '返信の最大階層は最大 :max まで設定可能です。',
    ],
    'max_comment_depth' => [
        'min' => '大コメントの最大階層は最小 :min 以上である必要があります。',
        'max' => '大コメントの最大階層は最大 :max まで設定可能です。',
    ],
    'notify_admin_on_post' => [
        'required' => '投稿作成時の管理者通知の有無は必須です。',
    ],
    'notify_author' => [
        'required' => '作成者メール通知の有無は必須です。',
    ],
    'blocked_keywords' => [
        'string' => '禁止用語一覧は文字列である必要があります。',
        'max' => '禁止用語一覧は :max 文字を超えることはできません。',
    ],
    'cooldown_required' => ':time 後に再度作成できます。',
    'cooldown_required_report' => '通報は :time の間隔で可能です。しばらく後に再度お試しください。',
    'cooldown_duration' => [
        'seconds' => ':seconds 秒',
        'minutes' => ':minutes 分',
        'minutes_seconds' => ':minutes 分 :seconds 秒',
        'hours' => ':hours 時間',
        'hours_minutes' => ':hours 時間 :minutes 分',
    ],
    'board' => [
        'name' => [
            'required' => '掲示板名は必須です。',
            'string' => '掲示板名は文字列である必要があります。',
            'max' => '掲示板名は :max 文字を超えることはできません。',
        ],
        'slug' => [
            'required' => '掲示板スラッグは必須です。',
            'string' => '掲示板スラッグは文字列である必要があります。',
            'max' => '掲示板スラッグは :max 文字を超えることはできません。',
            'alpha_dash' => '掲示板スラッグは英字、数字、ダッシュ(-)、アンダースコア(_)のみ使用できます。',
            'unique' => '既に使用中の掲示板スラッグです。',
            'regex' => '掲示板スラッグは英字で始まる必要があります。',
        ],
        'type' => [
            'required' => '掲示板タイプは必須です。',
            'in' => '無効な掲示板タイプです。',
        ],
        'description' => [
            'string' => '掲示板の説明は文字列である必要があります。',
            'max' => '掲示板の説明は:max文字を超えることはできません。',
        ],
        'per_page' => [
            'integer' => 'ページあたりの投稿数は整数である必要があります。',
            'min' => 'ページあたりの投稿数は最小:min個以上である必要があります。',
            'max' => 'ページあたりの投稿数は最大:max個を超えることはできません。',
        ],
        'per_page_mobile' => [
            'integer' => 'モバイルページあたりの投稿数は整数である必要があります。',
            'min' => 'モバイルページあたりの投稿数は最小:min個以上である必要があります。',
            'max' => 'モバイルページあたりの投稿数は最大:max個を超えることはできません。',
        ],
        'secret_mode' => [
            'in' => '無効な非公開投稿モードです。',
        ],
    ],
    'post' => [
        'title' => [
            'required' => 'タイトルは必須です。',
            'string' => 'タイトルは文字列である必要があります。',
            'min' => 'タイトルは最小:min文字以上である必要があります。',
            'max' => 'タイトルは:max文字を超えることはできません。',
        ],
        'content' => [
            'required' => '本文は必須です。',
            'string' => '本文は文字列である必要があります。',
            'min' => '本文は最小:min文字以上である必要があります。',
            'max' => '本文は:max文字を超えることはできません。',
        ],
        'category' => [
            'max' => '分類は:max文字を超えることはできません。',
        ],
        'category_id' => [
            'exists' => '存在しないカテゴリです。',
        ],
        'is_secret' => [
            'boolean' => '非公開投稿の有無は真/偽の値である必要があります。',
        ],
        'secret_password' => [
            'required_if' => '非公開投稿のパスワードは必須です。',
            'string' => '非公開投稿のパスワードは文字列である必要があります。',
            'min' => '非公開投稿のパスワードは最小:min文字以上である必要があります。',
            'max' => '非公開投稿のパスワードは:max文字を超えることはできません。',
        ],
        'parent_id' => [
            'exists' => '存在しない元の投稿です。',
            'not_found' => '元の投稿が見つかりません。',
            'blinded' => '非表示にされた投稿には返信を作成することはできません。',
            'deleted' => '削除された投稿には返信を作成することはできません。',
            'depth_exceeded' => 'この掲示板は返信を:max段階までのみ許可しています。',
            'notice_not_allowed' => 'お知らせには返信を作成することはできません。',
        ],
        'reply_not_allowed' => 'この掲示板は返信機能が無効化されています。',
        'status' => [
            'in' => '無効な投稿ステータスです。',
        ],
        'user_id' => [
            'exists' => '存在しないユーザーです。',
        ],
        'author_name' => [
            'required' => '非会員は作成者名を入力する必要があります。',
            'max' => '作成者名は:max文字を超えることはできません。',
        ],
        'password' => [
            'required' => '非会員はパスワードを入力する必要があります。',
            'min' => 'パスワードは最小:min文字以上である必要があります。',
        ],
        'is_notice' => [
            'guest_not_allowed' => '非会員はお知らせを作成することはできません。',
        ],
        'blocked_keyword' => '禁止ワード":keyword"が含まれています。',
        'files' => [
            'array' => '添付ファイルは配列形式である必要があります。',
            'max' => '最大:max個のファイルのみアップロードできます。',
            'file' => '無効なファイルです。',
            'file_max' => 'ファイルサイズが許可されたサイズを超えています。',
            'mimes' => '許可されていないファイル形式です。',
        ],
    ],
    'attributes' => [
        'settings' => [
            'basic_defaults.type' => '掲示板タイプ',
            'basic_defaults.per_page' => 'ページあたりの投稿数',
            'basic_defaults.per_page_mobile' => 'モバイルページあたりの投稿数',
            'basic_defaults.order_by' => '並べ替え基準',
            'basic_defaults.order_direction' => '並べ替え方向',
            'basic_defaults.secret_mode' => '非公開投稿モード',
            'basic_defaults.use_comment' => 'コメント使用有無',
            'basic_defaults.use_reply' => '返信使用有無',
            'basic_defaults.max_reply_depth' => '最大返信深さ',
            'basic_defaults.max_comment_depth' => '最大コメント深さ',
            'basic_defaults.comment_order' => 'コメント並べ替え',
            'basic_defaults.show_view_count' => '閲覧数表示',
            'basic_defaults.use_report' => '通報機能使用',
            'basic_defaults.min_title_length' => '最小タイトル長',
            'basic_defaults.max_title_length' => '最大タイトル長',
            'basic_defaults.min_content_length' => '最小本文長',
            'basic_defaults.max_content_length' => '最大本文長',
            'basic_defaults.min_comment_length' => '最小コメント長',
            'basic_defaults.max_comment_length' => '最大コメント長',
            'basic_defaults.use_file_upload' => 'ファイルアップロード使用',
            'basic_defaults.max_file_size' => '最大ファイルサイズ',
            'basic_defaults.max_file_count' => '最大ファイル個数',
            'basic_defaults.allowed_extensions' => '許可ファイル拡張子',
            'basic_defaults.notify_admin_on_post' => '投稿作成時の管理者通知',
            'basic_defaults.notify_author' => '作成者通知',
            'basic_defaults.new_display_hours' => '新規表示時間',
            'basic_defaults.default_board_permissions' => '基本掲示板権限',
            'report_policy.auto_hide_threshold' => '自動非表示通報数',
            'report_policy.auto_hide_target' => '自動非表示対象',
            'report_policy.daily_report_limit' => '1日の通報制限',
            'report_policy.rejection_limit_count' => '通報却下制限',
            'report_policy.rejection_limit_days' => '通報却下期間',
            'report_permissions.view_roles' => '通報閲覧権限ロール',
            'report_permissions.manage_roles' => '通報処理権限ロール',
            'spam_security.blocked_keywords' => '禁止用語',
            'spam_security.post_cooldown_seconds' => '投稿作成クールダウン(秒)',
            'spam_security.comment_cooldown_seconds' => 'コメント作成クールダウン(秒)',
            'spam_security.report_cooldown_seconds' => '通報クールダウン(秒)',
            'spam_security.view_count_cache_ttl' => '閲覧数キャッシュ有効期限(秒)',
        ],
        'post' => [
            'title' => 'タイトル',
            'content' => '内容',
            'category' => 'カテゴリ',
            'is_notice' => 'お知らせ',
            'is_secret' => '非公開',
            'content_mode' => '内容モード',
            'status' => 'ステータス',
            'user_id' => 'ユーザーID',
            'author_name' => '投稿者名',
            'password' => 'パスワード',
            'parent_id' => '元の投稿',
            'files' => '添付ファイル',
            'file' => '添付ファイル',
        ],
        'comment' => [
            'content' => 'コメント内容',
            'author_name' => '投稿者名',
            'password' => 'パスワード',
            'is_secret' => '非公開コメント',
            'parent_id' => '親コメント',
            'user_id' => 'ユーザーID',
            'ip_address' => 'IPアドレス',
            'status' => 'ステータス',
        ],
        'report' => [
            'reason_type' => '通報タイプ',
            'reason_detail' => '通報詳細内容',
            'status' => '通報ステータス',
            'process_note' => '処理メモ',
            'ids' => '通報ID',
        ],
        'blind' => [
            'reason' => 'ブラインド理由',
        ],
        'restore' => [
            'reason' => '復元理由',
        ],
        'board' => [
            'add_to_menu' => '管理者メニューに表示',
        ],
    ],
    'blind' => [
        'reason' => [
            'required' => 'ブラインド理由は必須です。',
            'min' => 'ブラインド理由は最小:min文字以上である必要があります。',
            'max' => 'ブラインド理由は:max文字を超えることはできません。',
            'string' => 'ブラインド理由は文字列である必要があります。',
        ],
    ],
    'restore' => [
        'reason' => [
            'required' => '復元理由は必須です。',
            'min' => '復元理由は最小:min文字以上である必要があります。',
            'max' => '復元理由は:max文字を超えることはできません。',
            'string' => '復元理由は文字列である必要があります。',
        ],
    ],
    'comment' => [
        'content' => [
            'required' => 'コメント内容は必須です。',
            'string' => 'コメント内容は文字列である必要があります。',
            'min' => 'コメント内容は最小:min文字以上である必要があります。',
            'max' => 'コメント内容は:max文字を超えることはできません。',
        ],
        'post_id' => [
            'not_found' => '投稿が見つかりません。',
            'blinded' => 'ブラインド処理された投稿にはコメントを作成できません。',
            'deleted' => '削除された投稿にはコメントを作成できません。',
        ],
        'parent_id' => [
            'exists' => '存在しないコメントです。',
            'integer' => '親コメントIDは整数である必要があります。',
            'not_found' => '親コメントが見つかりません。',
            'blinded' => 'ブラインド処理されたコメントに返信を作成することはできません。',
            'deleted' => '削除されたコメントに返信を作成することはできません。',
        ],
        'depth' => [
            'integer' => 'コメント深度は整数である必要があります。',
            'min' => 'コメント深度は最小:min以上である必要があります。',
            'max' => '返信は最大:max段階までしか作成できません。',
            'exceeded' => 'この掲示板は返信を:max段階までのみ許可しています。',
        ],
        'user_id' => [
            'exists' => '存在しないユーザーです。',
        ],
        'author_name' => [
            'required' => '非会員は投稿者名を入力する必要があります。',
            'max' => '投稿者名は:max文字を超えることはできません。',
        ],
        'password' => [
            'required' => 'パスワードを入力してください。',
            'min' => 'パスワードは最小:min文字以上である必要があります。',
        ],
        'ip_address' => [
            'required' => 'IPアドレスは必須です。',
        ],
        'blocked_keyword' => '禁止用語":keyword"が含まれています。',
    ],
    'attachment' => [
        'file' => [
            'required' => 'ファイルは必須です。',
            'file' => '有効なファイルではありません。',
            'max' => 'ファイルサイズは:maxKBを超えることはできません。',
            'mimes' => '許可されていないファイル形式です。',
        ],
        'file_required' => 'ファイルは必須です。',
        'file_invalid' => '有効なファイルではありません。',
        'file_max' => 'ファイルサイズは:maxMBを超えることはできません。',
        'file_mimes' => '許可されていないファイル形式です。',
        'post_id_required' => '投稿IDは必須です。',
        'post_id_invalid' => '無効な投稿IDです。',
        'max_count_exceeded' => '最大アップロードファイル数(:max個)を超えました。',
        'extension_not_allowed' => '許可されていないファイル拡張子です::extension',
        'orders_required' => '順序情報は必須です。',
        'orders_array' => '順序情報は配列形式である必要があります。',
        'order_id_required' => '添付ファイルIDは必須です。',
        'order_id_integer' => '添付ファイル ID は整数である必要があります。',
        'order_value_required' => '順序値は必須です。',
        'order_value_integer' => '順序値は整数である必要があります。',
    ],
    'category' => [
        'name' => [
            'required' => 'カテゴリ名は必須です。',
            'string' => 'カテゴリ名は文字列である必要があります。',
            'max' => 'カテゴリ名は :max 文字を超えることはできません。',
        ],
        'max_count_exceeded' => '最大カテゴリ数(:max個)を超過しました。',
    ],
    'board_manager_ids' => [
        'required' => '掲示板管理者値は必須です。',
        'min' => '掲示板管理者は最低でも :min 名以上指定する必要があります。',
    ],
    'category_in_use' => '":category" 分類は現在 :count 個の投稿で使用中です。',
    'board_type_invalid' => '無効な掲示板タイプです。使用可能なタイプ: :types',
    'board_type' => [
        'slug_required' => 'スラッグは必須です。',
        'slug_format' => 'スラッグは小文字、数字、ハイフンのみ使用でき、小文字で始まる必要があります。',
        'slug_unique' => '既に使用中のスラッグです。',
        'name_required' => 'タイプ名は必須です。',
        'name_ko_required' => '韓国語のタイプ名は必須です。',
    ],
    'multilingual_default_locale_required' => '基本言語(:locale)の値は必須です。',
    'permission' => [
        'invalid_role' => '無効なロールです: :role',
    ],
    'permission_names' => [
        'admin' => [
            'posts' => [
                'read' => '投稿閲覧 (管理者)',
                'write' => '投稿作成/編集/削除 (管理者)',
                'read-secret' => '非公開投稿閲覧 (管理者)',
            ],
            'comments' => [
                'read' => 'コメント閲覧 (管理者)',
                'write' => 'コメント作成/編集/削除 (管理者)',
            ],
            'manage' => '他人の投稿/コメント管理 (管理者)',
            'attachments' => [
                'upload' => 'ファイルアップロード (管理者)',
                'download' => 'ファイルダウンロード (管理者)',
            ],
        ],
        'posts' => [
            'read' => '投稿閲覧',
            'write' => '投稿作成',
            'read-secret' => '非公開投稿閲覧',
        ],
        'comments' => [
            'read' => 'コメント閲覧',
            'write' => 'コメント作成',
        ],
        'attachments' => [
            'upload' => 'ファイルアップロード',
            'download' => 'ファイルダウンロード',
        ],
        'manager' => '掲示板管理者',
    ],
    'role_field_suffix' => 'ロール',
    'report_permissions' => [
        'view_roles' => [
            'required_with' => '通報閲覧権限ロールを最低でも 1 個以上選択してください。',
            'min' => '通報閲覧権限ロールを最低でも :min 個以上選択してください。',
        ],
        'manage_roles' => [
            'required_with' => '通報処理権限ロールを最低でも 1 個以上選択してください。',
            'min' => '通報処理権限ロールを最低でも :min 個以上選択してください。',
        ],
    ],
    'report' => [
        'status' => [
            'required' => '通報ステータスは必須です。',
            'in' => '無効な通報ステータスです。',
        ],
        'process_note' => [
            'max' => '処理メモは :max 文字を超えることはできません。',
        ],
        'ids' => [
            'required' => '通報 ID は必須です。',
            'array' => '通報 ID は配列形式である必要があります。',
            'min' => '最低でも 1 個以上の通報を選択する必要があります。',
            'integer' => '通報 ID は整数である必要があります。',
            'exists' => '存在しない通報です。',
        ],
        'reason_type' => [
            'required' => '通報理由は必須です。',
            'in' => '無効な通報理由です。',
        ],
        'reason_detail' => [
            'required' => '通報詳細内容は必須です。',
            'min' => '通報詳細内容は最低でも :min 文字以上である必要があります。',
            'max' => '通報詳細内容は :max 文字を超えることはできません。',
        ],
        'daily_limit_exceeded' => '本日の通報可能回数(:limit回)を超過しました。',
        'rejection_limit_exceeded' => '最近 :days 日間の通報却下が :count 回累積され、通報が制限されました。',
    ],
];
