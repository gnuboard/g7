<?php

return [
    'action' => [
        'account_linked' => '소셜 계정 연결',
        'account_auto_linked' => '소셜 계정 자동 연결',
        'account_unlinked' => '소셜 계정 연결 해제',
        'account_unlinked_by_admin' => '소셜 계정 연결 강제 해제',
        'account_unlinked_by_webhook' => '제공자 알림으로 연결 해제',
        'signup' => '소셜 가입',
        'provider_created' => '소셜 로그인 제공자 추가',
        'provider_updated' => '소셜 로그인 제공자 설정 변경',
        'provider_deleted' => '소셜 로그인 제공자 삭제',
        'settings_updated' => '소셜 로그인 정책 변경',
    ],
    'description' => [
        'account_linked' => '회원 :user_name 이(가) :provider 계정을 연결했습니다.',
        'account_auto_linked' => '회원 :user_name 에게 제공자가 검증한 같은 이메일의 :provider 계정을 자동으로 연결했습니다.',
        'account_unlinked' => '회원 :user_name 의 :provider 계정 연결을 해제했습니다.',
        'account_unlinked_by_admin' => '관리자가 회원 :user_name 의 :provider 계정 연결을 해제했습니다.',
        'account_unlinked_by_webhook' => ':provider 의 연결 해제 알림으로 회원 :user_name 의 연결을 해제했습니다.',
        'signup' => '회원 :user_name 이(가) :provider 계정으로 가입했습니다.',
        'provider_created' => '소셜 로그인 제공자 :provider 을(를) 추가했습니다.',
        'provider_updated' => '소셜 로그인 제공자 :provider 의 설정을 바꿨습니다.',
        'provider_deleted' => '소셜 로그인 제공자 :provider 을(를) 삭제했습니다.',
        'settings_updated' => '소셜 로그인 정책을 저장했습니다.',
    ],
];
