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
    ],

    'validation' => [
        'code_required' => '코드를 입력해주세요.',
        'code_invalid' => '코드 형식이 올바르지 않습니다.',
        'code_attribute' => '인증 코드',
    ],

    'permissions' => [
        'credentials_delete' => '회원 인증 앱 등록 해제',
    ],
];
