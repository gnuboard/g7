<?php

return [
    'provider' => [
        'label' => '인증 앱 (OTP)',
    ],

    'enrollment' => [
        'started' => '인증 앱에 등록할 정보를 만들었습니다. 앱으로 QR 코드를 읽거나 키를 직접 입력해주세요.',
        'confirmed' => '인증 앱 등록이 완료됐습니다. 백업 코드를 안전한 곳에 보관해주세요.',
        'disabled' => '인증 앱 등록을 해제했습니다.',
        'backup_codes_regenerated' => '백업 코드를 새로 만들었습니다. 이전 코드는 더 이상 쓸 수 없습니다.',
        'admin_reset' => '이 회원의 인증 앱 등록을 해제했습니다.',
        'admin_reset_none' => '이 회원은 등록한 인증 앱이 없어 해제할 것이 없습니다.',
    ],

    'errors' => [
        'already_enrolled' => '이미 인증 앱이 등록되어 있습니다. 먼저 해제한 뒤 다시 등록해주세요.',
        'invalid_code' => '코드가 올바르지 않습니다. 인증 앱의 현재 코드나 백업 코드를 확인해주세요.',
        'not_enrolled' => '등록된 인증 앱이 없습니다.',
        'password_incorrect' => '현재 비밀번호가 올바르지 않습니다.',
        'too_many_attempts' => '코드 확인을 너무 많이 시도했습니다. :seconds초 후 다시 시도해주세요.',
        'confirm_conflict' => '다른 화면에서 등록이 먼저 확정되었거나 새 시크릿이 발급되었습니다. 화면을 새로 고친 뒤 다시 진행해주세요.',
    ],

    'validation' => [
        'code_required' => '코드를 입력해주세요.',
        'code_invalid' => '코드 형식이 올바르지 않습니다.',
        'code_attribute' => '인증 코드',
        'current_password_required' => '현재 비밀번호를 입력해주세요.',
        'current_password_max' => '현재 비밀번호는 255자를 넘을 수 없습니다.',
        'current_password_attribute' => '현재 비밀번호',
    ],

    'permissions' => [
        'credentials_delete' => '회원 인증 앱 등록 해제',
    ],

    // 보안 알림(1.0.3) — 변경한 주체 표기
    'notifications' => [
        'actor_self' => '본인',
        'actor_admin' => '관리자',
        'actor_console' => '서버 관리 명령',
    ],

    // 알림 템플릿 편집기 「사용 가능한 변수」 설명 — common: 변수 키별 기본값
    'notification_variables' => [
        'common' => [
            'name' => '수신자(계정 주인) 이름',
            'app_name' => '사이트 이름',
            'occurred_at' => '변경 시각',
            'ip_address' => '요청 IP',
            'actor_label' => '변경한 주체(본인·관리자·서버 셸)',
            'security_url' => '인증 앱 설정 화면 주소',
        ],
    ],
    // 콘솔 명령 출력
    'console' => [
        'seed_totp' => [
            'cli_only' => 'CLI 전용 커맨드입니다. (현재 SAPI: :sapi)',
            'bypass_required' => 'G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다. (예: PowerShell — :example)',
            'mode_required' => '--enroll · --code 중 하나는 지정해야 합니다.',
            'account_not_found' => '계정을 찾을 수 없습니다: :email',
        ],
        'reset' => [
            'user_not_found' => '회원을 찾을 수 없습니다: :email',
            'not_enrolled' => '이 회원에게는 등록된 인증 앱이 없습니다: :email',
            'done' => '인증 앱 등록을 해제했습니다: :email',
            'next_login_notice' => '다음 로그인부터 이 회원은 인증 앱 코드를 요구받지 않습니다 (메일 폴백이 켜져 있으면 이메일로 받습니다).',
        ],
        'password_reset_notice' => '이 회원은 인증 앱을 등록했습니다 — 인증 앱을 잃었다면 초기화하세요: php artisan totp:reset :email',
    ],
];
