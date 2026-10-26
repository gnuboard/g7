<?php

return [
    'block_reason' => [
        'deprovisioned' => '会社アカウントの退職処理',
        'reactivated' => '会社アカウントの再有効化',
    ],
    'role_mapping' => [
        'super_admin_only' => '管理者ロールにつながるマッピングと、管理者が連携している IdP のロール同期方式は、最高管理者のみ変更できます。',
    ],
    'command' => [
        'production_as_of' => '本番環境では --as-of を使用できません。',
        'invalid_as_of' => '--as-of の日時を読み取れません。',
        'deprovision_check' => '定期点検: IdP :idps個、確認 :checked件、退職 :deprovisioned件、再有効化 :reactivated件、失敗 :failed件',
        'withdraw' => '退職後の退会: 退会 :withdrawn件、管理者スキップ :skipped_admin件、スキップ :skipped件、失敗 :failed件',
        'prune' => '保存期間の整理: ログイン試行 :attempts件、SCIM 履歴 :scim件、終了したセッション :sessions件',
        'failed' => ':command 失敗 — ログを確認してください。',
    ],
];
