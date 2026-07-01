<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 기본 채널 설정
    |--------------------------------------------------------------------------
    | 시스템에서 기본으로 사용 가능한 알림 채널 목록입니다.
    | 라벨은 lang key 로 선언되어 활성 언어팩(ko/en/ja 등) 으로 자동 보강됩니다.
    | 플러그인에서 core.notification.filter_available_channels 훅으로 확장 가능합니다.
    |
    | allow_guest: 비회원(게스트) 수신자 발송 허용 여부. 미선언 채널은 기본 차단(false)
    | 이며, 모듈/플러그인이 filter_available_channels 훅으로 추가하는 신규 채널도 동일하게
    | allow_guest 를 명시해야 비회원 발송이 허용됩니다 (비회원 개인정보 보호 opt-in).
    */
    'default_channels' => [
        [
            'id' => 'mail',
            'name_key' => 'notification.channels.mail.name',
            'icon' => 'fas fa-envelope',
            'description_key' => 'notification.channels.mail.description',
            'source' => 'core',
            'source_label_key' => 'notification.channels.core_default',
            'allow_guest' => true,
        ],
        [
            'id' => 'database',
            'name_key' => 'notification.channels.database.name',
            'icon' => 'fas fa-bell',
            'description_key' => 'notification.channels.database.description',
            'source' => 'core',
            'source_label_key' => 'notification.channels.core_default',
            'allow_guest' => false,
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
