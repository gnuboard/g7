<?php

return [
    'inquiries' => [
        'content' => [
            'required' => 'お問い合わせ内容を入力してください。',
            'min' => 'お問い合わせ内容は最小:min文字以上入力してください。',
            'max' => 'お問い合わせ内容は最大:max文字まで入力可能です。',
        ],
        'reply_content' => [
            'required' => '回答内容を入力してください。',
            'min' => '回答内容は最小1文字以上入力してください。',
            'max' => '回答内容は最大5000文字まで入力可能です。',
        ],
    ],
    'settings' => [
        'key_required' => '設定キーは必須です。',
        'value_present' => '設定値は必須です。',
    ],
    'shipping_carrier' => [
        'code_required' => '配送業者コードは必須です。',
        'code_unique' => '既に使用中の配送業者コードです。',
        'code_format' => '配送業者コードは英文小文字、数字、ハイフンのみ使用可能です。',
        'name_required' => '配送業者名は必須です。',
        'type_required' => '配送業者タイプは必須です。',
        'type_invalid' => '配送業者タイプは国内(domestic)または国際(international)のみ可能です。',
    ],
    'list' => [
        'page' => [
            'integer' => 'ページ番号は数字である必要があります。',
            'min' => 'ページ番号は1以上である必要があります。',
        ],
        'per_page' => [
            'integer' => 'ページあたりの項目数は数字である必要があります。',
            'min' => 'ページあたりの項目数は:min以上である必要があります。',
            'max' => 'ページあたりの項目数は:max以下である必要があります。',
        ],
        'sort' => [
            'string' => '並べ替え基準は文字列である必要があります。',
            'in' => '正しい並べ替え基準を選択してください。',
        ],
        'sort_by' => [
            'string' => '並べ替えフィールドは文字列である必要があります。',
            'in' => '正しい並べ替えフィールドを選択してください。',
        ],
        'sort_order' => [
            'string' => '並べ替え順序は文字列である必要があります。',
            'in' => '並べ替え順序はascまたはdescである必要があります。',
        ],
        'search' => [
            'string' => '検索語は文字列である必要があります。',
            'max' => '検索語は最大:max文字まで入力可能です。',
        ],
        'search_field' => [
            'string' => '検索フィールドは文字列である必要があります。',
            'in' => '正しい検索フィールドを選択してください。',
        ],
        'search_keyword' => [
            'string' => '検索キーワードは文字列である必要があります。',
            'max' => '検索キーワードは最大:max文字まで入力可能です。',
        ],
        'is_active' => [
            'boolean' => '使用有無はtrueまたはfalseである必要があります。',
            'in' => '使用有無の値が正しくありません。',
        ],
        'active_only' => [
            'boolean' => '有効な項目のみフィルターオプションはtrueまたはfalseである必要があります。',
        ],
        'locale' => [
            'string' => '言語コードは文字列である必要があります。',
            'in' => 'サポートされていない言語です。',
        ],
        'region' => [
            'string' => 'ロケールは文字列である必要があります。',
            'max' => 'ロケールは最大:max文字まで入力可能です。',
        ],
        'parent_id' => [
            'exists' => '存在しない親カテゴリです。',
        ],
        'hierarchical' => [
            'boolean' => 'ツリー構造の有無はtrueまたはfalseである必要があります。',
        ],
        'flat' => [
            'boolean' => 'フラットリストの有無はtrueまたはfalseである必要があります。',
        ],
        'max_depth' => [
            'integer' => '最大深度は数字である必要があります。',
            'min' => '最大深度は:min以上である必要があります。',
            'max' => '最大深度は:max以下である必要があります。',
        ],
        'target_type' => [
            'string' => '適用対象タイプは文字列である必要があります。',
            'in' => '正しい適用対象タイプを選択してください。',
        ],
        'discount_type' => [
            'string' => '割引タイプは文字列である必要があります。',
            'in' => '正しい割引タイプを選択してください。',
        ],
        'issue_status' => [
            'string' => '公開ステータスは文字列である必要があります。',
            'in' => '正しい公開ステータスを選択してください。',
        ],
        'issue_method' => [
            'string' => '公開方法は文字列である必要があります。',
            'in' => '正しい公開方法を選択してください。',
        ],
        'issue_condition' => [
            'string' => '公開条件は文字列である必要があります。',
            'in' => '正しい公開条件を選択してください。',
        ],
        'min_benefit_amount' => [
            'numeric' => '最小特典金額は数字である必要があります。',
            'min' => '最小特典金額は0以上である必要があります。',
        ],
        'max_benefit_amount' => [
            'numeric' => '最大特典金額は数字である必要があります。',
            'min' => '最大特典金額は0以上である必要があります。',
        ],
        'min_order_amount' => [
            'numeric' => '最小注文金額は数字である必要があります。',
            'min' => '最小注文金額は0以上である必要があります。',
        ],
        'created_start_date' => [
            'date' => '登録開始日は日付形式である必要があります。',
        ],
        'created_end_date' => [
            'date' => '登録終了日は日付形式である必要があります。',
            'after_or_equal' => '登録終了日は開始日以降である必要があります。',
        ],
        'valid_start_date' => [
            'date' => '有効開始日は日付形式である必要があります。',
        ],
        'valid_end_date' => [
            'date' => '有効終了日は日付形式である必要があります。',
            'after_or_equal' => '有効終了日は開始日以降である必要があります。',
        ],
        'issue_start_date' => [
            'date' => '公開開始日は日付形式である必要があります。',
        ],
        'issue_end_date' => [
            'date' => '公開終了日は日付形式である必要があります。',
            'after_or_equal' => '公開終了日は開始日以降である必要があります。',
        ],
        'shipping_methods' => [
            'array' => '配送方法は配列形式である必要があります。',
            'string' => '配送方法は文字列である必要があります。',
            'in' => '正しい配送方法を選択してください。',
        ],
        'charge_policies' => [
            'array' => '付加政策は配列形式である必要があります。',
            'string' => '付加政策は文字列である必要があります。',
            'in' => '正しい付加政策を選択してください。',
        ],
        'countries' => [
            'array' => '配送国家は配列形式である必要があります。',
            'string' => '配送国家は文字列である必要があります。',
            'in' => '正しい配送国家を選択してください。',
        ],
    ],
    'category_required' => 'カテゴリを選択してください。',
    'category_min' => '最小1個以上のカテゴリを選択してください。',
    'category_max' => 'カテゴリは最大5個まで選択可能です。',
    'options_required' => 'オプションを1個以上追加してください。',
    'options_min' => 'オプションを1個以上追加してください。',
    'selling_price_lte_list' => '販売価格は定価より大きくすることはできません。',
    'option_selling_price_lte_list' => 'オプション販売価格は定価より大きくすることはできません。',
    'product' => [
        'name' => [
            'required' => '商品名を入力してください。',
        ],
        'name_primary' => [
            'required' => '基本言語の商品名は必須です。',
        ],
        'product_code' => [
            'required' => '商品コードを入力してください。',
            'unique' => '既に使用中の商品コードです。',
        ],
        'list_price' => [
            'required' => '定価を入力してください。',
            'min' => '定価は1以上である必要があります。',
        ],
        'selling_price' => [
            'required' => '販売価格を入力してください。',
            'min' => '販売価格は1以上である必要があります。',
            'lte' => '販売価格は定価以下である必要があります。',
        ],
        'stock_quantity' => [
            'required' => '在庫数量は必須です。',
        ],
        'sales_status' => [
            'required' => '販売ステータスは必須です。',
            'in' => '無効な販売ステータスです。',
        ],
        'display_status' => [
            'required' => '表示ステータスは必須です。',
            'in' => '無効な表示ステータスです。',
        ],
        'tax_status' => [
            'required' => '課税ステータスは必須です。',
            'in' => '無効な課税ステータスです。',
        ],
        'category_ids' => [
            'required' => 'カテゴリを1個以上選択してください。',
            'min' => 'カテゴリを1個以上選択してください。',
            'max' => 'カテゴリは最大5個まで選択できます。',
        ],
        'options' => [
            'required' => '商品オプションは必須です。',
            'min' => '商品オプションを1個以上追加してください。',
            'option_code' => [
                'required_with' => 'オプションコードは必須です。',
            ],
            'option_name' => [
                'required_with' => 'オプション名は必須です。',
            ],
            'option_values' => [
                'required_with' => 'オプション値は必須です。',
            ],
            'list_price' => [
                'required_with' => 'オプション定価は必須です。',
            ],
            'selling_price' => [
                'required_with' => 'オプション販売価格は必須です。',
            ],
            'stock_quantity' => [
                'required_with' => 'オプション在庫数量は必須です。',
            ],
        ],
        'label_assignments' => [
            'label_id' => [
                'required' => 'ラベルを選択してください。',
                'exists' => '存在しないラベルです。',
            ],
            'end_date' => [
                'after_or_equal' => '終了日は開始日以降である必要があります。',
            ],
        ],
        'shipping_policy_id' => [
            'exists' => '存在しない配送ポリシーです。',
        ],
        'common_info_id' => [
            'exists' => '存在しない共通情報です。',
        ],
        'use_main_image_for_og' => [
            'boolean' => 'OG画像設定は真/偽値である必要があります。',
        ],
        'invalid_sales_status' => '無効な販売ステータスです。',
        'invalid_display_status' => '無効な表示ステータスです。',
        'bulk' => [
            'ids_required' => '変更する商品を選択してください。',
            'ids_min' => '最小1個以上の商品を選択してください。',
            'product_not_found' => '存在しない商品です。',
            'option_not_found' => '存在しないオプションです。',
        ],
    ],
    'option' => [
        'bulk' => [
            'ids_required' => '変更するオプションを選択してください。',
            'ids_min' => '最小1個以上のオプションを選択してください。',
            'invalid_id_format' => '無効なオプションID形式です。',
        ],
    ],
    'bulk' => [
        'ids_required' => '変更する商品を選択してください。',
        'method_required' => '変更方法を選択してください。',
        'value_required' => '変更値を入力してください。',
    ],
    'bulk_option_price' => [
        'ids_required' => '変更する商品またはオプションを選択してください。',
        'product_ids' => [
            'required' => '変更する商品を選択してください。',
            'min' => '最小1個以上の商品を選択してください。',
        ],
        'method' => [
            'required' => '変更方法を選択してください。',
            'in' => '正しい変更方法を選択してください。',
        ],
        'value' => [
            'required' => '変更値を入力してください。',
            'integer' => '変更値は数字である必要があります。',
            'min' => '変更値は0以上である必要があります。',
        ],
        'unit' => [
            'required' => '単位を選択してください。',
            'in' => '正しい単位を選択してください。',
        ],
    ],
    'bulk_option_stock' => [
        'ids_required' => '変更する商品またはオプションを選択してください。',
        'product_ids' => [
            'required' => '変更する商品を選択してください。',
            'min' => '最小1個以上の商品を選択してください。',
        ],
        'method' => [
            'required' => '変更方法を選択してください。',
            'in' => '正しい変更方法を選択してください。',
        ],
        'value' => [
            'required' => '変更値を入力してください。',
            'integer' => '変更値は数字である必要があります。',
            'min' => '変更値は0以上である必要があります。',
        ],
    ],
    'preset' => [
        'name_required' => 'プリセット名を入力してください。',
        'name_exists' => '同じ名前のプリセットが既に存在します。',
        'conditions_required' => '検索条件を入力してください。',
    ],
    'brand' => [
        'name_required' => 'ブランド名を入力してください。',
        'slug_required' => 'スラッグを入力してください。',
        'slug_unique' => '既に使用中のスラッグです。',
        'slug_format' => 'スラッグは英小文字で開始する必要があり、英小文字·数字·ハイフン(-)のみ使用可能です。',
        'website_invalid_url' => '正しいURL形式ではありません。',
    ],
    'label' => [
        'name_required' => 'ラベル名を入力してください。',
        'color_required' => 'ラベルカラーを入力してください。',
        'color_invalid' => 'ラベルカラーは#RRGGBB形式である必要があります。',
    ],
    'category' => [
        'name_required' => 'カテゴリ名を入力してください。',
        'slug_required' => 'スラッグを入力してください。',
        'slug_unique' => '既に使用中のスラッグです。',
        'slug_format' => 'スラッグは英文小文字で始まる必要があり、英文小文字/数字/ハイフン(-)のみ使用可能です。',
        'parent_not_found' => '親カテゴリが見つかりません。',
    ],
    'product_images' => [
        'file' => [
            'required' => '画像ファイルを選択してください。',
            'file' => '有効なファイルではありません。',
            'image' => '画像ファイルのみアップロード可能です。',
            'mimes' => 'サポートされている画像形式: jpeg、png、jpg、gif、webp',
            'max' => '画像ファイルサイズは10MB以下である必要があります。',
        ],
        'temp_key' => [
            'string' => '一時キーは文字列である必要があります。',
            'max' => '一時キーは最大64文字まで入力可能です。',
        ],
        'collection' => [
            'in' => '正しいコレクションを選択してください。',
            'enum' => '正しいコレクションを選択してください。(main、detail、additional)',
        ],
        'alt_text' => [
            'array' => '代替テキストは配列形式である必要があります。',
        ],
        'orders' => [
            'required' => '並べ替え順序を入力してください。',
            'array' => '並べ替え順序は配列形式である必要があります。',
            'min' => '最小1つ以上の並べ替え順序を入力してください。',
            'item' => [
                'required' => '並べ替え順序の値を入力してください。',
                'integer' => '並べ替え順序は数字である必要があります。',
                'min' => '並べ替え順序は0以上である必要があります。',
            ],
        ],
    ],
    'category_images' => [
        'file' => [
            'required' => '画像ファイルを選択してください。',
            'file' => '有効なファイルではありません。',
            'image' => '画像ファイルのみアップロード可能です。',
            'mimes' => 'サポートされている画像形式: jpeg、png、jpg、gif、svg、webp',
            'max' => '画像ファイルサイズは10MB以下である必要があります。',
        ],
        'temp_key' => [
            'string' => '一時キーは文字列である必要があります。',
            'max' => '一時キーは最大64文字まで入力可能です。',
        ],
        'collection' => [
            'in' => '正しいコレクションを選択してください。',
        ],
        'alt_text' => [
            'array' => '代替テキストは配列形式である必要があります。',
        ],
        'orders' => [
            'required' => '並べ替え順序を入力してください。',
            'array' => '並べ替え順序は配列形式である必要があります。',
            'min' => '最小1つ以上の並べ替え順序を入力してください。',
            'item' => [
                'required' => '並べ替え順序の値を入力してください。',
                'integer' => '並べ替え順序は数字である必要があります。',
                'min' => '並べ替え順序は0以上である必要があります。',
            ],
        ],
    ],
    'product_notice_template' => [
        'name_required' => '商品グループ名を入力してください。',
        'name_max' => '商品グループ名は最大100文字まで入力可能です。',
        'fields_required' => '項目を1つ以上追加してください。',
        'fields_min' => '項目を1つ以上追加してください。',
        'field_name_required' => '項目名を入力してください。',
        'field_name_min' => '項目名を入力してください。',
        'field_name_max' => '項目名は最大200文字まで入力可能です。',
        'field_content_required' => '内容を入力してください。',
        'field_content_min' => '内容を入力してください。',
        'field_content_max' => '内容は最大2000文字まで入力可能です。',
    ],
    'coupon' => [
        'name' => [
            'required' => 'クーポン名を入力してください。',
        ],
        'name_ko' => [
            'required' => '韓国語クーポン名を入力してください。',
        ],
        'coupon_code' => [
            'required' => 'クーポンコードを入力してください。',
            'unique' => '既に使用中のクーポンコードです。',
        ],
        'target_type' => [
            'required' => '適用対象を選択してください。',
            'in' => '正しい適用対象を選択してください。',
        ],
        'discount_type' => [
            'required' => '割引タイプを選択してください。',
            'in' => '正しい割引タイプを選択してください。',
        ],
        'discount_value' => [
            'required' => '割引値を入力してください。',
            'numeric' => '割引値は数字である必要があります。',
            'min' => '割引値は0以上である必要があります。',
        ],
        'max_discount_amount' => [
            'numeric' => '最大割引金額は数字である必要があります。',
            'min' => '最大割引金額は0以上である必要があります。',
        ],
        'min_order_amount' => [
            'numeric' => '最小注文金額は数字である必要があります。',
            'min' => '最小注文金額は0以上である必要があります。',
        ],
        'max_issue_count' => [
            'integer' => '最大発行数は整数である必要があります。',
            'min' => '最大発行数は0以上である必要があります。',
        ],
        'max_use_count_per_user' => [
            'integer' => '1人当たりの最大使用回数は整数である必要があります。',
            'min' => '1人当たりの最大使用回数は0以上である必要があります。',
        ],
        'valid_from' => [
            'date' => '有効開始日は日付形式である必要があります。',
        ],
        'valid_until' => [
            'date' => '有効終了日は日付形式である必要があります。',
            'after_or_equal' => '有効終了日は有効開始日以降である必要があります。',
        ],
        'issue_start_at' => [
            'date' => '発行開始日は日付形式である必要があります。',
        ],
        'issue_end_at' => [
            'date' => '発行終了日は日付形式である必要があります。',
            'after_or_equal' => '発行終了日は発行開始日以降である必要があります。',
        ],
        'issue_status' => [
            'required' => '発行ステータスを選択してください。',
            'in' => '正しい発行ステータスを選択してください。',
        ],
        'issue_method' => [
            'in' => '正しい発行方法を選択してください。',
        ],
        'issue_condition' => [
            'in' => '正しい発行条件を選択してください。',
        ],
        'combinable' => [
            'boolean' => '重複使用の可否はtrueまたはfalseである必要があります。',
        ],
        'products' => [
            'array' => '適用商品は配列形式である必要があります。',
        ],
        'categories' => [
            'array' => '適用カテゴリは配列形式である必要があります。',
        ],
        'ids' => [
            'required' => '変更するクーポンを選択してください。',
            'array' => 'クーポンIDは配列形式である必要があります。',
            'min' => '最小1つ以上のクーポンを選択してください。',
        ],
        'name_required' => 'クーポン名を入力してください。',
        'target_type_required' => '適用対象を選択してください。',
        'discount_type_required' => '割引タイプを選択してください。',
        'discount_value_required' => '割引値を入力してください。',
        'discount_value_rate_min' => '割引率は1%以上である必要があります。',
        'discount_value_rate_max' => '割引率は100%以下である必要があります。',
        'issue_method_required' => '発行方法を選択してください。',
        'issue_condition_required' => '発行条件を選択してください。',
        'valid_type_required' => '有効期間タイプを選択してください。',
        'valid_days_required' => '有効日数を入力してください。',
        'valid_from_required' => '有効開始日を入力してください。',
        'valid_to_required' => '有効終了日を入力してください。',
        'valid_to_after_from' => '有効終了日は有効開始日以降である必要があります。',
        'ids_required' => '変更するクーポンを選択してください。',
        'ids_min' => '最少1つ以上のクーポンを選択してください。',
        'issue_status_required' => '発行ステータスを選択してください。',
        'issue_status_invalid' => '正しい発行ステータスを選択してください。',
        'id_required' => 'クーポンIDは必須です。',
        'id_integer' => 'クーポンIDは整数である必要があります。',
        'id_not_found' => '存在しないクーポンです。',
    ],
    'orders' => [
        'ids' => [
            'required' => '変更する注文を選択してください。',
            'array' => '注文IDは配列形式である必要があります。',
            'min' => '最少1つ以上の注文を選択してください。',
            'exists' => '存在しない注文です。',
        ],
        'order_status' => [
            'required' => '注文ステータスを選択してください。',
            'array' => '注文ステータスは配列形式である必要があります。',
            'string' => '注文ステータスは文字列である必要があります。',
            'in' => '正しい注文ステータスを選択してください。',
        ],
        'carrier_id' => [
            'required' => '配送業者を選択してください。',
            'exists' => '存在しない配送業者です。',
        ],
        'tracking_number' => [
            'required' => '送り状番号を入力してください。',
            'string' => '送り状番号は文字列である必要があります。',
            'max' => '送り状番号は最大50文字まで入力可能です。',
            'requires_status' => '送り状番号を入力する場合は、配送ステータスも一緒に変更してください。',
        ],
        'admin_memo' => [
            'max' => '管理者メモは最大1000文字まで入力可能です。',
        ],
        'recipient_name' => [
            'required' => '受取人名を入力してください。',
            'max' => '受取人名は最大50文字まで入力可能です。',
        ],
        'recipient_phone' => [
            'required_without' => '携帯電話番号または電話番号のいずれかは必須です。',
            'max' => '受取人連絡先は最大20文字まで入力可能です。',
        ],
        'recipient_tel' => [
            'required_without' => '電話番号または携帯電話番号のいずれかは必須です。',
            'max' => '受取人電話番号は最大20文字まで入力可能です。',
        ],
        'recipient_zipcode' => [
            'required' => '郵便番号を入力してください。',
            'max' => '郵便番号は最大10文字まで入力可能です。',
        ],
        'recipient_address' => [
            'required' => '基本住所を入力してください。郵便番号検索を利用してください。',
            'max' => '基本住所は最大255文字まで入力可能です。',
        ],
        'recipient_detail_address' => [
            'required' => '詳細住所を入力してください。',
            'max' => '詳細住所は最大255文字まで入力可能です。',
        ],
        'delivery_memo' => [
            'max' => '配送メモは最大500文字まで入力可能です。',
        ],
        'recipient_country_code' => [
            'size' => '国コードは2文字である必要があります。',
        ],
        'email' => [
            'required' => 'メールアドレスを入力してください。',
            'email' => '正しいメールアドレスを入力してください。',
            'max' => 'メールアドレスは最大255文字まで入力可能です。',
        ],
        'email_message' => [
            'required' => 'メール内容を入力してください。',
            'max' => 'メール内容は最大5000文字まで入力可能です。',
        ],
        'not_found' => '注文が見つかりません。',
        'cannot_update' => 'この注文は変更できません。',
        'cannot_cancel' => 'この注文はキャンセルできません。',
        'cannot_refund' => 'この注文は返金できません。',
        'carrier_required' => '該当するステータスに変更するには、配送業者を選択してください。',
        'tracking_number_required' => '該当するステータスに変更するには、送り状番号を入力してください。',
        'bulk_update' => [
            'at_least_one' => '注文ステータス、配送業者、送り状番号のいずれか以上を入力してください。',
        ],
        'search_field' => [
            'in' => '正しい検索フィールドを選択してください。',
        ],
        'search_keyword' => [
            'string' => '検索語は文字列である必要があります。',
            'max' => '検索語は最大200文字まで入力可能です。',
        ],
        'date_type' => [
            'in' => '正しい日付タイプを選択してください。',
        ],
        'start_date' => [
            'date' => '開始日は日付形式である必要があります。',
        ],
        'end_date' => [
            'date' => '終了日は日付形式である必要があります。',
            'after_or_equal' => '終了日は開始日以降である必要があります。',
        ],
        'option_status' => [
            'array' => 'オプションステータスは配列形式である必要があります。',
            'string' => 'オプションステータスは文字列である必要があります。',
            'in' => '正しいオプションステータスを選択してください。',
        ],
        'shipping_type' => [
            'array' => '配送タイプは配列形式である必要があります。',
            'string' => '配送タイプは文字列である必要があります。',
            'in' => '正しい配送タイプを選択してください。',
        ],
        'payment_method' => [
            'array' => '決済方法は配列形式である必要があります。',
            'string' => '決済方法は文字列である必要があります。',
            'in' => '正しい決済方法を選択してください。',
        ],
        'category_id' => [
            'integer' => 'カテゴリIDは数字である必要があります。',
        ],
        'min_amount' => [
            'integer' => '最小金額は数字である必要があります。',
            'min' => '最小金額は0以上である必要があります。',
        ],
        'max_amount' => [
            'integer' => '最大金額は数字である必要があります。',
            'min' => '最大金額は0以上である必要があります。',
        ],
        'country_codes' => [
            'array' => '国別コードは配列形式である必要があります。',
            'string' => '国別コードは文字列である必要があります。',
            'size' => '国別コードは2文字である必要があります。',
        ],
        'order_device' => [
            'array' => '注文デバイスは配列形式である必要があります。',
            'string' => '注文デバイスは文字列である必要があります。',
            'in' => '正しい注文デバイスを選択してください。',
        ],
        'user_id' => [
            'integer' => '会員IDは数字である必要があります。',
        ],
        'orderer_uuid' => [
            'uuid' => '注文者UUID形式が正しくありません。',
        ],
        'sort_by' => [
            'in' => '正しいソートフィールドを選択してください。',
        ],
        'sort_order' => [
            'in' => 'ソート順序はascまたはdescである必要があります。',
        ],
        'per_page' => [
            'integer' => 'ページあたりの項目数は数字である必要があります。',
            'min' => 'ページあたりの項目数は10以上である必要があります。',
            'max' => 'ページあたりの項目数は100以下である必要があります。',
        ],
        'page' => [
            'integer' => 'ページ番号は数字である必要があります。',
            'min' => 'ページ番号は1以上である必要があります。',
        ],
    ],
    'quantity_exceeds_available' => '変更数量が保有数量を超えています。',
    'quantity_min_one' => '変更数量は1個以上である必要があります。',
    'order_options' => [
        'items' => [
            'required' => '変更するオプションを選択してください。',
            'min' => '最小1個以上のオプションを選択してください。',
        ],
        'option_id' => [
            'required' => 'オプションIDは必須です。',
            'exists' => '存在しないオプションです。',
        ],
        'quantity' => [
            'required' => '変更数量を入力してください。',
            'min' => '変更数量は1個以上である必要があります。',
        ],
        'status' => [
            'required' => '変更するステータスを選択してください。',
            'in' => '正しいオプションステータスを選択してください。',
        ],
    ],
    'order' => [
        'ids' => [
            'required' => '変更する注文を選択してください。',
            'array' => '注文IDは配列形式である必要があります。',
            'min' => '最小1個以上の注文を選択してください。',
        ],
        'order_status' => [
            'required' => '注文ステータスを選択してください。',
            'in' => '正しい注文ステータスを選択してください。',
        ],
        'carrier_id' => [
            'required' => '配送業者を選択してください。',
            'exists' => '存在しない配送業者です。',
        ],
        'tracking_number' => [
            'required' => '送り状番号を入力してください。',
            'string' => '送り状番号は文字列である必要があります。',
            'max' => '送り状番号は最大100文字まで入力可能です。',
        ],
        'not_found' => '注文が見つかりません。',
        'cannot_update' => 'この注文は編集できません。',
        'cannot_cancel' => 'この注文はキャンセルできません。',
        'cannot_refund' => 'この注文は返金できません。',
        'orderer_name_required' => '注文者名を入力してください。',
        'orderer_phone_required' => '注文者の連絡先を入力してください。',
        'orderer_email_invalid' => '正しいメール形式ではありません。',
        'recipient_name_required' => '受取人名を入力してください。',
        'recipient_phone_required' => '受取人の連絡先を入力してください。',
        'recipient_phone_required_without' => '携帯電話または電話番号のいずれかを入力してください。',
        'recipient_tel_required_without' => '携帯電話または電話番号のいずれかを入力してください。',
        'zipcode_required' => '郵便番号を入力してください。',
        'address_required' => '住所を入力してください。',
        'address_detail_required' => '詳細住所を入力してください。',
        'address_line_1_required' => '住所を入力してください。',
        'intl_city_required' => '都市を入力してください。',
        'intl_postal_code_required' => '郵便番号を入力してください。',
        'payment_method_required' => '決済方法を選択してください。',
        'payment_method_invalid' => '正しい決済方法を選択してください。',
        'expected_total_amount_required' => '決済予定金額が必要です。',
        'expected_total_amount_numeric' => '決済予定金額は数字である必要があります。',
        'depositor_name_required' => '入金者名を入力してください。',
        'dbank_bank_code_required' => '入金銀行を選択してください。',
        'dbank_bank_name_required' => '入金銀行名が必要です。',
        'dbank_account_number_required' => '入金口座番号が必要です。',
        'dbank_account_holder_required' => '預金者名が必要です。',
    ],
    'order_bulk' => [
        'ids_required' => '変更する注文を選択してください。',
        'status_or_shipping_required' => '注文ステータスまたは配送情報のいずれかは入力する必要があります。',
        'invalid_status' => '無効な注文ステータスです。',
        'invalid_carrier' => '無効な配送業者です。',
    ],
    'order_export' => [
        'format' => [
            'required' => 'エクスポート形式を選択してください。',
            'in' => '正しいエクスポート形式を選択してください。',
        ],
        'columns' => [
            'required' => 'エクスポートするカラムを選択してください。',
            'array' => 'カラムは配列形式である必要があります。',
            'min' => '最小1個以上のカラムを選択してください。',
        ],
    ],
    'cart' => [
        'product_id_required' => '商品を選択してください。',
        'product_not_found' => '存在しない商品です。',
        'option_id_required' => 'オプションを選択してください。',
        'option_not_found' => '存在しないオプションです。',
        'quantity_required' => '数量を入力してください。',
        'quantity_min' => '数量は1個以上である必要があります。',
        'quantity_max' => '数量は最大9,999個まで可能です。',
        'ids_required' => '削除する商品を選択してください。',
        'ids_array' => '商品IDは配列形式である必要があります。',
        'ids_min' => '最低1つ以上の商品を選択してください。',
        'item_not_found' => 'カート商品が見つかりません。',
        'items_required' => 'カートに追加する商品を選択してください。',
        'items_min' => '最低1つ以上の商品を選択してください。',
        'option_values_not_found' => '該当するオプション組み合わせが見つかりません。',
    ],
    'wishlist' => [
        'product_id_required' => '商品を選択してください。',
        'product_not_found' => '商品が見つかりません。',
        'selected_ids_array' => '選択された商品IDは配列形式である必要があります。',
        'selected_ids_integer' => '選択された商品IDは数字である必要があります。',
        'selected_ids_min' => '選択された商品IDは1以上である必要があります。',
        'cart_key_required' => '非会員カートキーが必要です。',
        'invalid_cart_key' => '無効なカートキー形式です。',
        'login_required' => 'ログインが必要です。',
    ],
    'checkout' => [
        'item_ids_required' => '注文する商品を選択してください。',
        'item_ids_array' => '商品IDは配列形式である必要があります。',
        'item_ids_min' => '最低1つ以上の商品を選択してください。',
        'use_points_integer' => 'ポイントは数字である必要があります。',
        'use_points_min' => 'ポイントは0以上である必要があります。',
        'coupon_issue_ids_array' => 'クーポンIDは配列形式である必要があります。',
        'coupon_issue_id_integer' => 'クーポンIDは数字である必要があります。',
        'country_code_size' => '国コードは2文字である必要があります。',
        'zipcode_max' => '郵便番号は最大20文字まで入力可能です。',
        'region_max' => '地域は最大100文字まで入力可能です。',
        'city_max' => '都市は最大100文字まで入力可能です。',
        'address_max' => '住所は最大255文字まで入力可能です。',
    ],
    'shipping_policy' => [
        'ids_required' => '変更する配送ポリシーを選択してください。',
        'ids_array' => '配送ポリシーIDは配列形式である必要があります。',
        'ids_min' => '最低1つ以上の配送ポリシーを選択してください。',
        'id_integer' => '配送ポリシーIDは数字である必要があります。',
        'id_exists' => '存在しない配送ポリシーです。',
        'is_active_required' => '使用有無を選択してください。',
        'is_active_boolean' => '使用有無はtrueまたはfalseである必要があります。',
        'base_fee_zero_not_allowed' => '送料無料以外のポリシーは送料を0円に設定できません。',
        'custom_shipping_name_required' => '直接入力配送方法を選択した場合は配送方法名を入力してください。',
        'ranges' => [
            'first_min_zero' => '最初の区間の開始値は0である必要があります。',
            'last_max_unlimited' => '最後の区間の終了値は空にする必要があります。',
            'continuity' => '区間が連続していません。',
            'min_less_than_max' => '開始値が終了値より小さい必要があります。',
            'fee_non_negative' => '送料は0以上である必要があります。',
        ],
    ],
    'extra_fee_template' => [
        'zipcode_required' => '郵便番号を入力してください。',
        'zipcode_unique' => '既に登録されている郵便番号です。',
        'zipcode_max' => '郵便番号は最大10文字まで入力可能です。',
        'zipcode_format' => '郵便番号は数字とハイフン(-)のみ入力可能です。（例：12345または12345-12399）',
        'fee_required' => '追加送料を入力してください。',
        'fee_numeric' => '追加送料は数字である必要があります。',
        'fee_min' => '追加送料は0以上である必要があります。',
        'ids_required' => '変更する項目を選択してください。',
        'ids_array' => '項目IDは配列形式である必要があります。',
        'ids_min' => '最低1つ以上の項目を選択してください。',
        'id_not_found' => '存在しない項目です。',
        'is_active_required' => '使用有無を選択してください。',
        'is_active_boolean' => '使用有無はtrueまたはfalseである必要があります。',
        'items_required' => '登録する項目を入力してください。',
        'items_array' => '項目は配列形式である必要があります。',
        'items_min' => '最低1つ以上の項目を入力してください。',
        'items_max' => '一度に最大100個まで登録可能です。',
        'item_zipcode_required' => '郵便番号を入力してください。',
        'item_fee_required' => '追加送料を入力してください。',
    ],
    'product_common_info' => [
        'name_required' => '共通情報名を入力してください。',
        'name_max' => '共通情報名は最大100文字まで入力可能です。',
        'content_mode_invalid' => '正しいコンテンツモードを選択してください（textまたはhtml）。',
    ],
    'coupon_issues' => [
        'user_id_integer' => 'ユーザーIDは整数である必要があります。',
        'user_id_exists' => '存在しないユーザーです。',
        'status_in' => '無効なステータス値です。',
        'per_page_integer' => 'ページあたりの件数は整数である必要があります。',
        'per_page_min' => 'ページあたりの件数は最低1個以上である必要があります。',
        'per_page_max' => 'ページあたりの件数は最大100個まで可能です。',
    ],
    'search_preset' => [
        'target_screen_in' => '無効な対象画面です。',
    ],
    'category_reorder' => [
        'parent_menus_required' => '親メニューまたは子メニューのデータが必要です。',
        'parent_menus_array' => '親メニューは配列である必要があります。',
        'id_required' => 'カテゴリIDは必須です。',
        'id_integer' => 'カテゴリIDは整数である必要があります。',
        'id_exists' => '存在しないカテゴリです。',
        'order_required' => '順序値は必須です。',
        'order_integer' => '順序値は整数である必要があります。',
        'order_min' => '順序値は0以上である必要があります。',
    ],
    'reviews' => [
        'search_field' => [
            'in' => '正しい検索フィールドを選択してください。',
        ],
        'search_keyword' => [
            'string' => '検索キーワードは文字列である必要があります。',
            'max' => '検索キーワードは最大:max文字まで入力可能です。',
        ],
        'rating' => [
            'in' => '正しい評価を選択してください。',
        ],
        'reply_status' => [
            'in' => '正しい返信ステータスを選択してください。',
        ],
        'has_photo' => [
            'boolean' => 'フォトレビュー フィルターは true または false である必要があります。',
        ],
        'status' => [
            'in' => '正しいレビュー ステータスを選択してください。',
        ],
        'start_date' => [
            'date' => '開始日は日付形式である必要があります。',
        ],
        'end_date' => [
            'date' => '終了日は日付形式である必要があります。',
            'after_or_equal' => '終了日は開始日以降である必要があります。',
        ],
        'sort_by' => [
            'in' => '正しいソート フィールドを選択してください。',
        ],
        'sort_order' => [
            'in' => 'ソート順序は asc または desc である必要があります。',
        ],
        'per_page' => [
            'integer' => 'ページあたりの項目数は数値である必要があります。',
            'min' => 'ページあたりの項目数は :min 以上である必要があります。',
            'max' => 'ページあたりの項目数は :max 以下である必要があります。',
        ],
        'page' => [
            'integer' => 'ページ番号は数値である必要があります。',
            'min' => 'ページ番号は 1 以上である必要があります。',
        ],
    ],
    'public_product' => [
        'category_id' => [
            'integer' => 'カテゴリ ID は数値である必要があります。',
        ],
        'category_slug' => [
            'string' => 'カテゴリ スラッグは文字列である必要があります。',
            'max' => 'カテゴリ スラッグは最大 :max 文字まで入力できます。',
        ],
        'brand_id' => [
            'integer' => 'ブランド ID は数値である必要があります。',
        ],
        'search' => [
            'string' => '検索用語は文字列である必要があります。',
            'max' => '検索用語は最大 :max 文字まで入力できます。',
        ],
        'sort' => [
            'in' => '正しいソート基準を選択してください。',
        ],
        'min_price' => [
            'integer' => '最小価格は数値である必要があります。',
            'min' => '最小価格は 0 以上である必要があります。',
        ],
        'max_price' => [
            'integer' => '最大価格は数値である必要があります。',
            'min' => '最大価格は 0 以上である必要があります。',
        ],
        'per_page' => [
            'integer' => 'ページあたりの項目数は数値である必要があります。',
            'min' => 'ページあたりの項目数は :min 以上である必要があります。',
            'max' => 'ページあたりの項目数は :max 以下である必要があります。',
        ],
        'limit' => [
            'integer' => '閲覧数は数値である必要があります。',
            'min' => '閲覧数は :min 以上である必要があります。',
            'max' => '閲覧数は :max 以下である必要があります。',
        ],
        'ids' => [
            'string' => '商品 ID リストは文字列である必要があります。',
            'max' => '商品 ID リストは最大 :max 文字まで入力できます。',
        ],
    ],
    'public_review' => [
        'sort' => [
            'in' => '正しいソート基準を選択してください。',
        ],
        'photo_only' => [
            'boolean' => 'フォトレビュー フィルターは true または false である必要があります。',
        ],
        'page' => [
            'integer' => 'ページ番号は数値である必要があります。',
            'min' => 'ページ番号は 1 以上である必要があります。',
        ],
        'per_page' => [
            'integer' => 'ページあたりの項目数は数値である必要があります。',
            'min' => 'ページあたりの項目数は :min 以上である必要があります。',
            'max' => 'ページあたりの項目数は :max 以下である必要があります。',
        ],
        'rating' => [
            'in' => '正しい評価を選択してください。',
        ],
    ],
    'user_coupon' => [
        'status' => [
            'in' => '正しいクーポン ステータスを選択してください。',
        ],
        'per_page' => [
            'integer' => 'ページあたりの項目数は数値である必要があります。',
            'min' => 'ページあたりの項目数は :min 以上である必要があります。',
            'max' => 'ページあたりの項目数は :max 以下である必要があります。',
        ],
        'product_ids' => [
            'array' => '商品 ID リストは配列形式である必要があります。',
        ],
        'product_ids_item' => [
            'integer' => '商品 ID は数値である必要があります。',
        ],
    ],
    'user_mileage' => [
        'order_amount' => [
            'required' => '注文金額は必須です。',
            'integer' => '注文金額は数値である必要があります。',
            'min' => '注文金額は 0 以上である必要があります。',
        ],
    ],
    'attributes' => [
        'basic_info' => '基本情報',
        'basic_info.shop_name' => 'ショップ名',
        'basic_info.route_path' => 'ルートパス',
        'basic_info.company_name' => '会社名',
        'basic_info.business_number_1' => '事業者登録番号',
        'basic_info.business_number_2' => '事業者登録番号',
        'basic_info.business_number_3' => '事業者登録番号',
        'basic_info.ceo_name' => '代表者名',
        'basic_info.business_type' => '業態',
        'basic_info.business_category' => '業種',
        'basic_info.zipcode' => '郵便番号',
        'basic_info.base_address' => '住所',
        'basic_info.detail_address' => '住所（詳細）',
        'basic_info.phone_1' => '電話番号',
        'basic_info.phone_2' => '電話番号',
        'basic_info.phone_3' => '電話番号',
        'basic_info.fax_1' => 'ファックス番号',
        'basic_info.fax_2' => 'ファックス番号',
        'basic_info.fax_3' => 'ファックス番号',
        'basic_info.email_id' => 'メール',
        'basic_info.email_domain' => 'メール',
        'basic_info.privacy_officer' => '個人情報保護責任者',
        'basic_info.privacy_officer_email' => '個人情報保護責任者メール',
        'basic_info.mail_order_number' => '通信販売業通知番号',
        'basic_info.telecom_number' => '付加通信事業者番号',
        'language_currency' => '言語·通貨設定',
        'language_currency.default_language' => 'デフォルト言語',
        'language_currency.default_currency' => 'デフォルト通貨',
        'language_currency.currencies' => '通貨リスト',
        'language_currency.currencies.*.code' => '通貨コード',
        'language_currency.currencies.*.name' => '通貨名',
        'language_currency.currencies.*.name.*' => '通貨名',
        'language_currency.currencies.*.exchange_rate' => '為替レート',
        'language_currency.currencies.*.rounding_unit' => '四捨五入単位',
        'language_currency.currencies.*.rounding_method' => '四捨五入方式',
        'language_currency.currencies.*.decimal_places' => '小数点以下の桁数',
        'seo' => 'SEO設定',
        'seo.meta_main_title' => 'メインページタイトル',
        'seo.meta_main_description' => 'メインページ説明',
        'seo.meta_category_title' => 'カテゴリーページタイトル',
        'seo.meta_category_description' => 'カテゴリーページ説明',
        'seo.meta_search_title' => '検索ページタイトル',
        'seo.meta_search_description' => '検索ページ説明',
        'seo.meta_product_title' => '商品ページタイトル',
        'seo.meta_product_description' => '商品ページ説明',
        'seo.meta_shop_index_title' => 'ショッピングモールメインページタイトル',
        'seo.meta_shop_index_description' => 'ショッピングモールメインページ説明',
        'seo.seo_shop_index' => 'ショッピングモールメインページSEO使用',
        'seo.seo_user_agents' => 'SEOユーザーエージェント',
        'order_settings.payment_methods' => '決済方法',
        'order_settings.payment_methods.*.id' => '決済方法ID',
        'order_settings.payment_methods.*.sort_order' => '決済方法並べ替え順序',
        'order_settings.payment_methods.*.is_active' => '決済方法使用有無',
        'order_settings.payment_methods.*.min_order_amount' => '最小注文金額',
        'order_settings.payment_methods.*.stock_deduction_timing' => '在庫差し引きのタイミング',
        'order_settings.banks' => '銀行リスト',
        'order_settings.bank_accounts' => '口座リスト',
        'order_settings.bank_accounts.*.bank_code' => '銀行コード',
        'order_settings.bank_accounts.*.account_number' => '口座番号',
        'order_settings.bank_accounts.*.account_holder' => '口座名義人',
        'order_settings.bank_accounts.*.is_active' => '口座使用有無',
        'order_settings.bank_accounts.*.is_default' => 'デフォルト口座',
        'order_settings.auto_cancel_expired' => '未決済自動キャンセル',
        'order_settings.auto_cancel_days' => '自動キャンセル期限（日）',
        'order_settings.vbank_due_days' => '仮想口座入金期限（日）',
        'order_settings.dbank_due_days' => '銀行振込入金期限（日）',
        'order_settings.cart_expiry_days' => 'カート保管期間（日）',
        'order_settings.default_pg_provider' => 'デフォルトPG会社',
        'order_settings.payment_methods.*.pg_provider' => 'PG会社',
        'order_settings.stock_restore_on_cancel' => 'キャンセル時の在庫復元',
        'order_number' => '注文番号',
        'order_status' => '注文ステータス',
        'payment_status' => '決済ステータス',
        'payment_method' => '決済方法',
        'total_amount' => '総注文金額',
        'total_paid_amount' => '総決済金額',
        'ordered_at' => '注文日時',
        'paid_at' => '決済日時',
        'carrier_id' => '配送業者',
        'tracking_number' => '送り状番号',
        'shipping_status' => '配送ステータス',
        'shipping_type' => '配送タイプ',
        'orderer_name' => '注文者名',
        'orderer_phone' => '注文者連絡先',
        'orderer_email' => '注文者メールアドレス',
        'recipient_name' => '受取人',
        'recipient_phone' => '受取人連絡先',
        'recipient_zipcode' => '郵便番号',
        'recipient_address' => '配送先住所',
        'recipient_detail_address' => '詳細住所',
        'recipient_country_code' => '配送国',
        'delivery_memo' => '配送メモ',
        'address_id' => '配送先',
        'label_name' => 'ラベル名',
        'label_color' => 'ラベル色',
        'is_active' => '使用有無',
        'sort_order' => '並べ替え順序',
    ],
    'custom' => [
        'basic_info' => [
            'shop_name' => [
                'required' => 'ショッピングモール名は必須項目です。',
                'string' => 'ショッピングモール名は文字列である必要があります。',
                'max' => 'ショッピングモール名は最大255文字まで入力できます。',
            ],
            'route_path' => [
                'required' => 'ルートパスは必須項目です。',
                'string' => 'ルートパスは文字列である必要があります。',
                'max' => 'ルートパスは最大100文字まで入力できます。',
            ],
            'no_route' => [
                'boolean' => 'ルート未使用フラグはtrue/false値である必要があります。',
            ],
            'company_name' => [
                'string' => '会社名は文字列である必要があります。',
                'max' => '会社名は最大255文字まで入力できます。',
            ],
            'business_number' => [
                'string' => '事業者登録番号は文字列である必要があります。',
                'max' => '事業者登録番号の形式が正しくありません。',
            ],
            'ceo_name' => [
                'string' => '代表者名は文字列である必要があります。',
                'max' => '代表者名は最大100文字まで入力できます。',
            ],
            'business_type' => [
                'string' => '業態は文字列である必要があります。',
                'max' => '業態は最大100字まで入力可能です。',
            ],
            'business_category' => [
                'string' => '業種は文字列である必要があります。',
                'max' => '業種は最大255字まで入力可能です。',
            ],
            'zipcode' => [
                'string' => '郵便番号は文字列である必要があります。',
                'max' => '郵便番号は最大10字まで入力可能です。',
            ],
            'base_address' => [
                'string' => '基本住所は文字列である必要があります。',
                'max' => '基本住所は最大500字まで入力可能です。',
            ],
            'detail_address' => [
                'string' => '詳細住所は文字列である必要があります。',
                'max' => '詳細住所は最大255字まで入力可能です。',
            ],
            'phone' => [
                'string' => '電話番号は文字列である必要があります。',
                'max' => '電話番号の形式が正しくありません。',
            ],
            'fax' => [
                'string' => 'ファックス番号は文字列である必要があります。',
                'max' => 'ファックス番号の形式が正しくありません。',
            ],
            'email_id' => [
                'string' => 'メールIDは文字列である必要があります。',
                'max' => 'メールIDは最大100字まで入力可能です。',
            ],
            'email_domain' => [
                'string' => 'メールドメインは文字列である必要があります。',
                'max' => 'メールドメインは最大100字まで入力可能です。',
            ],
            'privacy_officer' => [
                'string' => '個人情報責任者は文字列である必要があります。',
                'max' => '個人情報責任者は最大100字まで入力可能です。',
            ],
            'privacy_officer_email' => [
                'email' => '正しいメール形式ではありません。',
                'max' => '個人情報責任者メールは最大255字まで入力可能です。',
            ],
            'mail_order_number' => [
                'string' => '通信販売業届出番号は文字列である必要があります。',
                'max' => '通信販売業届出番号は最大100字まで入力可能です。',
            ],
            'telecom_number' => [
                'string' => '付加通信事業者番号は文字列である必要があります。',
                'max' => '付加通信事業者番号は最大100字まで入力可能です。',
            ],
        ],
        'language_currency' => [
            'default_language' => [
                'string' => '基本言語は文字列である必要があります。',
                'max' => '基本言語は最大10字まで入力可能です。',
            ],
            'default_currency' => [
                'string' => '基本通貨は文字列である必要があります。',
                'max' => '基本通貨は最大10字まで入力可能です。',
            ],
            'currencies' => [
                'duplicate_code' => '重複した通貨コードがあります。',
                'name_required' => '通貨名は最低1つの言語で入力する必要があります。',
                'code' => [
                    'required_with' => '通貨コードは必須です。',
                    'string' => '通貨コードは文字列である必要があります。',
                    'max' => '通貨コードは最大10字まで入力可能です。',
                ],
                'name' => [
                    'required_with' => '通貨名は必須です。',
                    'array' => '通貨名はアレイ形式である必要があります。',
                    'string' => '通貨名は文字列である必要があります。',
                    'max' => '通貨名は最大100字まで入力可能です。',
                ],
                'exchange_rate' => [
                    'numeric' => '為替レートは数値である必要があります。',
                    'min' => '為替レートは0以上である必要があります。',
                ],
                'rounding_unit' => [
                    'string' => '四捨五入単位は文字列である必要があります。',
                ],
                'rounding_method' => [
                    'string' => '四捨五入方式は文字列である必要があります。',
                    'in' => '四捨五入方式はfloor、round、ceilのいずれかである必要があります。',
                ],
                'decimal_places' => [
                    'integer' => '小数点以下の桁数は整数である必要があります。',
                    'min' => '小数点以下の桁数は0以上である必要があります。',
                    'max' => '小数点以下の桁数は最大8桁まで可能です。',
                ],
                'is_default' => [
                    'boolean' => '基本通貨の有無は真偽値である必要があります。',
                ],
            ],
        ],
        'seo' => [
            'meta_main_title' => [
                'string' => 'メインページタイトルは文字列である必要があります。',
                'max' => 'メインページタイトルは最大500字まで入力可能です。',
            ],
            'meta_main_description' => [
                'string' => 'メインページ説明は文字列である必要があります。',
                'max' => 'メインページ説明は最大1000字まで入力可能です。',
            ],
            'meta_category_title' => [
                'string' => 'カテゴリページタイトルは文字列である必要があります。',
                'max' => 'カテゴリページタイトルは最大500字まで入力可能です。',
            ],
            'meta_category_description' => [
                'string' => 'カテゴリページ説明は文字列である必要があります。',
                'max' => 'カテゴリページ説明は最大1000字まで入力可能です。',
            ],
            'meta_search_title' => [
                'string' => '検索ページタイトルは文字列である必要があります。',
                'max' => '検索ページタイトルは最大500字まで入力可能です。',
            ],
            'meta_search_description' => [
                'string' => '検索ページ説明は文字列である必要があります。',
                'max' => '検索ページ説明は最大1000字まで入力可能です。',
            ],
            'meta_product_title' => [
                'string' => '商品ページタイトルは文字列である必要があります。',
                'max' => '商品ページタイトルは最大500字まで入力可能です。',
            ],
            'meta_product_description' => [
                'string' => '商品ページ説明は文字列である必要があります。',
                'max' => '商品ページ説明は最大1000字まで入力可能です。',
            ],
            'seo_site_main' => [
                'boolean' => 'メインページSEO使用有無は真偽値である必要があります。',
            ],
            'seo_category' => [
                'boolean' => 'カテゴリページSEO使用有無は真偽値である必要があります。',
            ],
            'seo_search_result' => [
                'boolean' => '検索結果ページSEO使用有無は真偽値である必要があります。',
            ],
            'seo_product_detail' => [
                'boolean' => '商品詳細ページSEO使用有無は真偽値である必要があります。',
            ],
            'meta_shop_index_title' => [
                'string' => 'ショップメインページタイトルは文字列である必要があります。',
                'max' => 'ショップメインページタイトルは最大500字まで入力可能です。',
            ],
            'meta_shop_index_description' => [
                'string' => 'ショップメインページ説明は文字列である必要があります。',
                'max' => 'ショップメインページ説明は最大1000字まで入力可能です。',
            ],
            'seo_shop_index' => [
                'boolean' => 'ショップメインページSEO使用有無は真偽値である必要があります。',
            ],
            'seo_user_agents' => [
                'string' => 'SEOユーザーエージェントは文字列である必要があります。',
                'max' => 'SEOユーザーエージェントは最大100字まで入力可能です。',
            ],
        ],
        'banks' => [
            'code' => [
                'required_with' => '銀行コードは必須項目です。',
                'string' => '銀行コードは文字列である必要があります。',
                'max' => '銀行コードは最大10字まで入力可能です。',
            ],
            'name' => [
                'required_with' => '銀行名は必須項目です。',
                'array' => '銀行名は多言語アレイ形式である必要があります。',
                'string' => '銀行名は文字列である必要があります。',
                'max' => '銀行名は最大100文字まで入力可能です。',
            ],
        ],
        'order_settings' => [
            'payment_methods' => [
                'at_least_one_active' => '決済方法の中から1つ以上は有効化されている必要があります。',
                'id' => [
                    'required_with' => '決済方法IDは必須項目です。',
                    'string' => '決済方法IDは文字列である必要があります。',
                ],
                'sort_order' => [
                    'integer' => '決済方法の並べ替え順序は整数である必要があります。',
                    'min' => '決済方法の並べ替え順序は1以上である必要があります。',
                ],
                'is_active' => [
                    'boolean' => '決済方法の使用の可否は真偽値である必要があります。',
                ],
                'min_order_amount' => [
                    'integer' => '最小注文金額は整数である必要があります。',
                    'min' => '最小注文金額は0以上である必要があります。',
                ],
                'stock_deduction_timing' => [
                    'string' => '在庫の差し引き時点は文字列である必要があります。',
                    'in' => '在庫の差し引き時点は、注文受け取り時、決済完了時、在庫差し引きなしのいずれかである必要があります。',
                ],
                'pg_required_for_activation' => 'この決済方法を有効化するには、まずPG会社を選択してください。',
            ],
            'bank_accounts' => [
                'at_least_one_active_default' => '無通帳口座の中から1つ以上は既定の選択および使用の選択がされている必要があります。',
                'bank_code' => [
                    'required_with' => '銀行は必須項目です。',
                    'string' => '銀行コードは文字列である必要があります。',
                ],
                'account_number' => [
                    'required_with' => '口座番号は必須項目です。',
                    'string' => '口座番号は文字列である必要があります。',
                    'max' => '口座番号は最大50文字まで入力可能です。',
                ],
                'account_holder' => [
                    'required_with' => '預金者名は必須項目です。',
                    'string' => '預金者名は文字列である必要があります。',
                    'max' => '預金者名は最大100文字まで入力可能です。',
                ],
                'is_active' => [
                    'boolean' => '口座の使用の可否は真偽値である必要があります。',
                ],
                'is_default' => [
                    'boolean' => '既定の口座であるかどうかは真偽値である必要があります。',
                ],
            ],
            'auto_cancel_expired' => [
                'boolean' => '未決済の自動キャンセルの可否は真偽値である必要があります。',
            ],
            'auto_cancel_days' => [
                'integer' => '自動キャンセルの期限は整数である必要があります。',
                'min' => '自動キャンセルの期限は0日以上である必要があります。',
                'max' => '自動キャンセルの期限は最大30日まで設定可能です。',
            ],
            'vbank_due_days' => [
                'integer' => '仮想口座の入金期限は整数である必要があります。',
                'min' => '仮想口座の入金期限は1日以上である必要があります。',
                'max' => '仮想口座の入金期限は最大30日まで設定可能です。',
            ],
            'dbank_due_days' => [
                'integer' => '無通帳入金の入金期限は整数である必要があります。',
                'min' => '無通帳入金の入金期限は1日以上である必要があります。',
                'max' => '無通帳入金の入金期限は最大30日まで設定可能です。',
            ],
            'cart_expiry_days' => [
                'integer' => 'カートの保管期間は整数である必要があります。',
                'min' => 'カートの保管期間は1日以上である必要があります。',
                'max' => 'カートの保管期間は最大365日まで設定可能です。',
            ],
            'stock_restore_on_cancel' => [
                'boolean' => 'キャンセル時の在庫復旧の可否は真偽値である必要があります。',
            ],
        ],
        'shipping' => [
            'default_country' => [
                'must_exist_in_countries' => '既定の配送国は配送可能国の一覧に存在している必要があります。',
            ],
            'available_countries' => [
                'array' => '配送可能国は配列形式である必要があります。',
                'duplicate_code' => '重複した国コードが存在します。',
                'name_required' => '国名は最低1つの言語で入力される必要があります。',
                'code' => [
                    'required_with' => '国コードは必須です。',
                    'string' => '国コードは文字列である必要があります。',
                    'max' => '国コードは最大10文字まで入力可能です。',
                ],
                'name' => [
                    'required_with' => '国名は必須です。',
                    'array' => '国名は配列形式である必要があります。',
                    'string' => '国名は文字列である必要があります。',
                    'max' => '国名は最大100文字まで入力可能です。',
                ],
                'is_active' => [
                    'boolean' => '国の使用の可否は真偽値である必要があります。',
                ],
            ],
            'international_shipping_enabled' => [
                'boolean' => '海外配送の使用の可否は真偽値である必要があります。',
            ],
            'remote_area_enabled' => [
                'boolean' => '遠隔地の使用の可否は真偽値である必要があります。',
            ],
            'remote_area_extra_fee' => [
                'integer' => '山間地域の追加配送料は整数である必要があります。',
                'min' => '山間地域の追加配送料は0円以上である必要があります。',
            ],
            'island_extra_fee' => [
                'integer' => '島嶼地域の追加配送料は整数である必要があります。',
                'min' => '島嶼地域の追加配送料は0円以上である必要があります。',
            ],
            'free_shipping_threshold' => [
                'integer' => '送料無料の基準金額は整数である必要があります。',
                'min' => '送料無料の基準金額は0円以上である必要があります。',
            ],
            'free_shipping_enabled' => [
                'boolean' => '送料無料の使用の可否は真偽値である必要があります。',
            ],
            'address_validation_enabled' => [
                'boolean' => '住所検証の使用の可否は真偽値である必要があります。',
            ],
            'address_api_provider' => [
                'string' => '住所検証APIプロバイダーは文字列である必要があります。',
                'max' => '住所検証APIプロバイダーは最大50文字まで入力可能です。',
            ],
            'types' => [
                'duplicate_code' => '重複した配送タイプのコードが存在します。',
                'name_required' => '配送タイプ名は必須項目です。',
                'code' => [
                    'required_with' => '配送タイプコードは必須です。',
                    'string' => '配送タイプコードは文字列である必要があります。',
                    'max' => '配送タイプコードは最大50文字まで入力可能です。',
                    'regex' => '配送タイプコードは英小文字、数字、ハイフン、アンダースコアのみ使用可能です。',
                ],
                'name' => [
                    'required_with' => '配送タイプ名は必須です。',
                    'array' => '配送タイプ名は多言語配列形式である必要があります。',
                ],
                'category' => [
                    'required_with' => '配送タイプカテゴリーは必須です。',
                    'in' => '配送タイプカテゴリーは国内配送、海外配送、その他のいずれかである必要があります。',
                ],
                'is_active' => [
                    'boolean' => '配送タイプの使用の可否は真偽値である必要があります。',
                ],
            ],
            'carriers' => [
                'duplicate_code' => '重複した配送業者のコードが存在します。',
                'name_required' => '配送業者名は必須項目です。',
                'code' => [
                    'required_with' => '配送業者コードは必須です。',
                    'string' => '配送業者コードは文字列である必要があります。',
                    'max' => '配送業者コードは最大50文字まで入力可能です。',
                    'regex' => '配送業者コードは英小文字、数字、ハイフン、アンダースコアのみ使用可能です。',
                ],
                'name' => [
                    'required_with' => '配送業者名は必須です。',
                    'array' => '配送業者名は多言語配列形式である必要があります。',
                    'string' => '配送業者名は文字列である必要があります。',
                    'max' => '配送業者名は最大100字まで入力可能です。',
                ],
                'name_ko' => [
                    'required_with' => '配送業者名(韓国語)は必須です。',
                    'string' => '配送業者名(韓国語)は文字列である必要があります。',
                    'max' => '配送業者名(韓国語)は最大100字まで入力可能です。',
                ],
                'type' => [
                    'required_with' => '配送業者タイプは必須です。',
                    'in' => '配送業者タイプは国内配送または国際配送のいずれかである必要があります。',
                ],
                'tracking_url' => [
                    'string' => '配送追跡URLは文字列である必要があります。',
                    'max' => '配送追跡URLは最大500字まで入力可能です。',
                ],
                'is_active' => [
                    'boolean' => '配送業者使用有無は真偽値である必要があります。',
                ],
            ],
        ],
    ],
    'user_address' => [
        'name_required' => '配送先名は必須です。',
        'name_string' => '配送先名は文字列である必要があります。',
        'recipient_name_required' => '受取人名は必須です。',
        'recipient_name_string' => '受取人名は文字列である必要があります。',
        'recipient_phone_required' => '受取人連絡先は必須です。',
        'recipient_phone_string' => '受取人連絡先は文字列である必要があります。',
        'zipcode_required' => '郵便番号は必須です。',
        'address_required' => '住所は必須です。',
        'address_line_1_required' => '海外住所(Address Line 1)は必須です。',
        'intl_city_required' => '海外都市名は必須です。',
        'intl_postal_code_required' => '海外郵便番号は必須です。',
    ],
    'review_image' => [
        'image_required' => '画像を選択してください。',
        'image_file' => '有効なファイル形式ではありません。',
        'image_image' => '画像ファイルのみアップロード可能です。',
        'image_max' => '画像サイズは10MBを超えることはできません。',
    ],
];
