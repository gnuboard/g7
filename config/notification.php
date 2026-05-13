<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 기본 채널 설정
    |--------------------------------------------------------------------------
    | 시스템에서 기본으로 사용 가능한 알림 채널 목록입니다.
    | 라벨은 lang key 로 선언되어 활성 언어팩(ko/en/ja 등) 으로 자동 보강됩니다.
    | 플러그인에서 core.notification.filter_available_channels 훅으로 확장 가능합니다.
    */
    'default_channels' => [
        [
            'id' => 'mail',
            'name_key' => 'notification.channels.mail.name',
            'icon' => 'fas fa-envelope',
            'description_key' => 'notification.channels.mail.description',
            'source' => 'core',
            'source_label_key' => 'notification.channels.core_default',
        ],
        [
            'id' => 'database',
            'name_key' => 'notification.channels.database.name',
            'icon' => 'fas fa-bell',
            'description_key' => 'notification.channels.database.description',
            'source' => 'core',
            'source_label_key' => 'notification.channels.core_default',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 사이트내 알림 설정
    |--------------------------------------------------------------------------
    */
    'database_channel' => [
        // 미읽음 알림 최대 보관 일수 (0 = 무제한)
        'unread_retention_days' => 90,

        // 읽음 알림 최대 보관 일수 (0 = 무제한)
        'read_retention_days' => 30,

        // 사용자별 최대 알림 수 (0 = 무제한)
        'max_per_user' => 500,
    ],

];
