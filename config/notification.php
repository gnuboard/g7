<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 기본 채널 설정
    |--------------------------------------------------------------------------
    | 시스템에서 기본으로 사용 가능한 알림 채널 목록입니다.
    | 플러그인에서 core.notification.filter_available_channels 훅으로 확장 가능합니다.
    */
    'default_channels' => [
        [
            'id' => 'mail',
            'name' => ['ko' => '메일', 'en' => 'Email'],
            'icon' => 'fas fa-envelope',
            'description' => ['ko' => '이메일로 알림 발송', 'en' => 'Send notification via email'],
            'source' => 'core',
            'source_label' => ['ko' => '코어 기본 채널', 'en' => 'Core default channel'],
        ],
        [
            'id' => 'database',
            'name' => ['ko' => '사이트내 알림', 'en' => 'Site Notification'],
            'icon' => 'fas fa-bell',
            'description' => ['ko' => '사이트내 알림 센터에 표시', 'en' => 'Show in site notification center'],
            'source' => 'core',
            'source_label' => ['ko' => '코어 기본 채널', 'en' => 'Core default channel'],
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
