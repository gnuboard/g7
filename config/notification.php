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
    |
    | template_editor: 관리자 템플릿 편집 화면이 본문을 어떤 편집기로 그릴지.
    |   - 'rich'  리치 편집기(확장 슬롯 `html_editor`). CKEditor 가 없으면 기존 입력으로 폴백
    |   - 'plain' 평문 입력 (미선언 채널의 기본값)
    |   - 'none'  기본 편집기를 감춤 — 채널 소유 확장이 폼 슬롯에 자기 UI 를 주입한다
    | 메일 본문은 HTML 로 나가고 사이트내 알림은 평문으로 그려지므로 둘의 기본값이 다릅니다.
    | 종전 플래그 `hidden_template_editor: true` 는 'none' 과 같은 뜻으로 계속 동작합니다.
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
            'template_editor' => 'rich',
        ],
        [
            'id' => 'database',
            'name_key' => 'notification.channels.database.name',
            'icon' => 'fas fa-bell',
            'description_key' => 'notification.channels.database.description',
            'source' => 'core',
            'source_label_key' => 'notification.channels.core_default',
            'allow_guest' => false,
            // 사이트내 알림은 알림센터가 **평문으로** 그린다 — 여기서 리치 편집기를 주면
            // 관리자 미리보기와 사용자 화면이 서로 다른 문자열을 보여 준다.
            'template_editor' => 'plain',
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
