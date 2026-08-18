<?php

return [
    'channel' => [
        'sms' => 'SMS',
        'lms' => 'LMS',
        'alimtalk' => 'アラートトーク',
    ],
    'status' => [
        'pending' => '待機中',
        'sent' => '送信中',
        'success' => '成功',
        'failed' => '失敗',
    ],
    'source' => [
        'auto' => '自動',
        'manual' => '手動',
        'bulk' => '一括',
    ],
    'result_category' => [
        'success' => '成功',
        'retry' => '再試行',
        'permanent_failure' => '永久失敗',
        'balance_low' => '残高不足',
    ],
    'channels' => [
        'source_label' => 'ビズプリオ',
        'sms' => [
            'name' => 'SMS/LMSテキスト',
            'description' => 'ビズプリオを通じてテキスト(SMS/LMS)で通知を送信します。',
        ],
        'alimtalk' => [
            'name' => 'カカオアラートトーク',
            'description' => 'ビズプリオを通じてカカオアラートトークで通知を送信します。',
        ],
    ],
    'readiness' => [
        'sms_credentials_missing' => 'ビズプリオのアイディとパスワードを設定してください。',
        'sms_sender_number_missing' => '発信番号を設定してください。',
        'alimtalk_api_key_missing' => 'カカオ管理API キーを設定してください。',
        'alimtalk_sender_key_missing' => 'アラートトーク発信プロフィールキーを設定してください。',
    ],
    'settings' => [
        'bizppurio_id_attribute' => 'ビズプリオアイディ',
        'password_attribute' => 'パスワード',
        'sender_number_attribute' => '発信番号',
    ],
    'webhook' => [
        'received' => 'レポートを受け取りました。',
    ],
    'error' => [
        'credentials_missing' => 'ビズプリオのアイディとパスワードを先に設定してください。',
        'token_issue_failed' => 'ビズプリオ認証トークン発行に失敗しました。',
        'send_failed' => 'メッセージ送信リクエストに失敗しました。',
        'send_retryable' => 'メッセージ送信が一時的に失敗しました。(コード: :code)',
        'invalid_response' => 'ビズプリオレスポンスを解析できません。',
        'kakao_credentials_missing' => 'カカオ管理API 使用のためにアイディとAPI キーを先に設定してください。',
        'kakao_request_failed' => 'カカオ管理API リクエストに失敗しました。',
        'sender_key_missing' => 'アラートトーク発信プロフィールキーを先に設定してください。',
        'template_not_sendable' => '送信可能(承認)ステータスではないテンプレートです。(コード: :code)',
        'token_issue_failed_with_reason' => 'ビズプリオ認証トークンの発行に失敗しました。(:reason)',
        'connection_failed' => 'ビズプリオ サーバーに接続できません。しばらく後にもう一度お試しください。',
    ],
    'send_skipped' => [
        'alimtalk_binding_missing' => 'アラートトークテンプレートが接続されていないため送信をスキップしました。(通知タイプ: :type)',
        'alimtalk_kakao_content_unavailable' => 'カカオ承認テンプレート内容を照会できないため送信をスキップしました。(通知タイプ: :type)',
        'sms_template_missing' => 'SMSテンプレートがないため送信をスキップしました。(通知タイプ: :type)',
        'recipient_phone_missing' => '受信者の電話番号がないため送信をスキップしました。(通知タイプ: :type)',
        'message_body_empty' => '送信本文が空いているため送信をスキップしました。(通知タイプ: :type)',
    ],
    'binding' => [
        'saved' => 'アラートトーク連携を保存しました。',
        'removed' => 'アラートトーク連携を解除しました。',
    ],
    'cache' => [
        'cleared' => 'アラートトークテンプレート内容キャッシュを初期化しました。次回送信から最新内容が反映されます。',
    ],
    'channel_group' => [
        'text' => '文字',
        'alimtalk' => '通知トーク',
    ],
    'token_check' => [
        'success' => '認証が正常に確認されました。ユーザーIDとパスワードが正しいです。',
        'failed' => '認証の確認に失敗しました。詳細な理由をご確認ください。',
    ],
];
