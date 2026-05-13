<?php

return [
    'types' => [
        'module' => 'モジュール',
        'plugin' => 'プラグイン',
        'template' => 'テンプレート',
    ],
    'errors' => [
        'core_version_mismatch' => ':extension (:type)はグヌボード7 コアバージョン :required 以上が必要です。(現在: :installed)',
        'version_check_failed' => 'バージョン検証に失敗しました。',
        'operation_in_progress' => '":name"に対して実行中の処理(:status)があるため、リクエストを処理できません。',
        'zip_missing_manifest' => 'ZIP内から :file マニフェストが見つかりません: :zip',
        'zip_invalid_manifest' => 'ZIP内の :file マニフェストをJSONとして解析できません。',
        'zip_identifier_mismatch' => 'ZIPマニフェストの識別子がターゲット拡張と一致しません。(期待値: :expected、実際: :actual)',
        'zip_missing_version' => 'ZIP内の :file マニフェストに version フィールドがありません。',
        'not_found' => '拡張(:identifier)が見つかりません。',
        'cascade_dependency_failed' => '付属インストール対象の :type (:identifier)のインストールに失敗しました: :message',
        'invalid_type' => '無効な拡張タイプです。',
        'not_auto_deactivated' => 'この拡張はコアバージョンの互換性の問題により自動無効化された状態ではありません。',
        'hidden_extension' => '内部用(hidden)拡張はユーザーに公開されません。',
    ],
    'warnings' => [
        'auto_deactivated' => ':type ":identifier"はコアバージョン互換性の問題により自動的に無効化されました。',
    ],
    'alerts' => [
        'incompatible_deactivated' => ':type ":name"自動無効化',
        'incompatible_message' => '必須バージョン: :required、現在インストール済み: :installed',
        'recovered_title' => ':type「:name」再び互換性があります',
        'recovered_body' => 'コアアップグレード後に互換性があります(以前の要件: :previously_required)。再度有効化できます。',
        'recovered_success' => '拡張が再び有効化されました。',
        'dismissed' => '通知を閉じました。',
        'auto_deactivated_listed' => '自動無効化された拡張のリストです。',
        'recover_action' => '再度有効化',
        'dismiss_action' => '通知を閉じる',
    ],
    'commands' => [
        'clear_cache_success' => '拡張バージョン検証キャッシュが削除されました。',
    ],
    'badges' => [
        'incompatible' => 'コアアップグレードが必要です',
        'incompatible_tooltip' => 'コア:required以上が必要です(現在: :installed)',
        'incompatible_sr' => ':nameはコア:required以上が必要ですが、現在:installedがインストールされているため更新できません。',
    ],
    'banner' => [
        'title' => 'コア互換性の問題により自動無効化された拡張があります',
        'item_required' => '必須バージョン: :required',
        'guide_link' => 'コアアップグレードガイド',
        'dismiss' => '閉じる',
    ],
    'update_modal' => [
        'compat_warning_title' => 'コアバージョン互換性警告',
        'compat_warning_message' => 'この:typeはコア:required以上が必要です。(現在: :installed)',
        'compat_guide_link' => 'コアアップグレードガイドを表示',
        'force_label' => '警告を無視して強制更新します(推奨されません)',
    ],
];
