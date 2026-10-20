<?php

return [
    'flow' => [
        'expired' => '가입 대기 시간이 지났습니다. 소셜 로그인을 처음부터 다시 해 주세요.',
        'mail_failed' => '인증번호를 보내지 못했습니다. 관리자에게 문의해 주세요.',
        'email_locked' => '이 가입에서는 이메일을 바꿀 수 없습니다.',
        'email_taken' => '이미 가입된 이메일입니다. 다른 이메일을 입력해 주세요.',
        'email_not_verified' => '인증번호로 확인한 이메일만 쓸 수 있습니다.',
        'challenge_mismatch' => '인증 요청이 맞지 않습니다. 인증번호를 다시 받아 주세요.',
        'invalid_code' => '인증번호가 맞지 않거나 시간이 지났습니다.',
        'name_required' => '이름을 입력해 주세요.',
        'existing_account_pending' => '같은 이메일의 기존 계정이 있습니다. 기존 계정에 연결하거나 새 계정으로 가입을 골라 주세요.',
        'not_existing_step' => '기존 계정 연결 단계가 아닙니다.',
        'password_not_available' => '이 계정은 비밀번호가 없습니다. 이메일 인증번호로 확인해 주세요.',
        'password_mismatch' => '비밀번호가 맞지 않습니다.',
        'too_many_attempts' => '확인에 여러 번 실패했습니다. 소셜 로그인을 처음부터 다시 해 주세요.',
        'admin_blocked' => '관리자 계정은 소셜 로그인을 쓸 수 없습니다. 비밀번호로 로그인해 주세요.',
        'already_linked_other_user' => '이 소셜 계정은 다른 회원에게 연결되어 있습니다.',
        'provider_already_linked' => '이 제공자의 다른 계정이 이미 연결되어 있습니다. 먼저 해제한 뒤 연결해 주세요.',
        'provider_disabled' => '사용할 수 없는 제공자입니다.',
        'provider_unknown' => '제공자를 찾을 수 없습니다.',
        'not_linked' => '연결된 소셜 계정이 아닙니다.',
        'last_method' => '비밀번호가 없고 연결이 하나뿐이라 해제할 수 없습니다. 비밀번호를 설정하거나 다른 계정을 연결한 뒤 해제해 주세요.',
        'reauth_not_available' => '소셜 계정으로 다시 확인할 수 없는 계정입니다.',
        'provider_error' => '소셜 계정 제공자에 연결하지 못했습니다. 잠시 뒤 다시 시도해 주세요.',
        'reauth_claim_invalid' => '확인 결과가 없거나 이미 사용했습니다. 다시 확인해 주세요.',
    ],
];
