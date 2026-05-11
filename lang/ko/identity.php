<?php

return [
    'providers' => [
        'mail' => [
            'label' => '이메일',
            'settings' => [
                'code_length' => '인증 코드 길이',
                'code_length_help' => '발송되는 숫자 코드의 자릿수 (기본 6, 최소 4, 최대 10).',
                'from_address' => '발신자 주소',
                'from_address_help' => '비어 있으면 시스템 기본 발신자를 사용합니다.',
            ],
        ],
    ],

    'errors' => [
        'verification_required' => '본인 확인이 필요합니다.',
        'challenge_not_found' => '유효하지 않은 인증 요청입니다.',
        'wrong_provider' => '이 인증 요청은 다른 프로바이더에서 처리해야 합니다.',
        'invalid_state' => '이미 처리된 인증 요청입니다.',
        'expired' => '인증 시간이 만료되었습니다. 다시 시도해주세요.',
        'max_attempts' => '시도 횟수를 초과했습니다. 다시 요청해주세요.',
        'invalid_code' => '인증 코드가 올바르지 않습니다.',
        'invalid_verification_token' => '유효하지 않은 본인인증 토큰입니다.',
        'missing_target' => '인증 대상(이메일·전화번호)이 필요합니다.',
        'target_mismatch' => '인증한 대상과 요청한 대상이 일치하지 않습니다.',
        'purpose_not_supported' => '선택된 프로바이더는 이 목적을 지원하지 않습니다.',
        'provider_unavailable' => '본인인증 프로바이더를 사용할 수 없습니다.',
        'generic' => '본인인증에 실패했습니다.',
        'missing_scope_or_target' => '정책 조회를 위해 scope 와 target 이 모두 필요합니다.',
        'admin_policy_has_no_default' => '관리자가 직접 생성한 정책에는 선언 기본값이 없습니다.',
        'reset_field_failed' => '선언 기본값 복원에 실패했습니다. 필드가 유효한지 확인하세요.',
    ],

    'messages' => [
        'challenge_requested' => '본인인증 코드를 발송했습니다.',
        'challenge_verified' => '본인 확인이 완료되었습니다.',
        'challenge_cancelled' => '본인인증 요청이 취소되었습니다.',
    ],

    'logs' => [
        'activity' => [
            'requested' => ':email 로 본인인증 코드를 발송했습니다.',
            'verified' => '본인 확인이 완료되었습니다.',
            'failed' => '본인인증에 실패했습니다.',
            'expired' => '본인인증 시간이 만료되었습니다.',
            'cancelled' => '본인인증 요청을 취소했습니다.',
        ],
    ],

    'purposes' => [
        'signup' => [
            'label' => '회원가입 인증',
            'description' => '신규 가입자의 이메일/전화번호 소유 확인.',
        ],
        'password_reset' => [
            'label' => '비밀번호 재설정',
            'description' => '비밀번호를 잊은 사용자가 본인 확인 후 재설정.',
        ],
        'self_update' => [
            'label' => '자기 정보 변경',
            'description' => '로그인 사용자가 이메일/전화 등 본인 정보를 변경할 때.',
        ],
        'sensitive_action' => [
            'label' => '민감 작업',
            'description' => '계정 탈퇴·관리자 작업 등 재인증이 필요한 시점.',
        ],
    ],

    'channels' => [
        'email' => '이메일',
    ],

    'origin_types' => [
        'route' => '라우트',
        'hook' => '훅',
        'policy' => '정책',
        'middleware' => '미들웨어',
        'api' => 'API 직접 호출',
        'custom' => '커스텀',
        'system' => '시스템',
    ],

    'policy' => [
        'scope' => [
            'route' => '라우트',
            'hook' => '훅',
            'custom' => '커스텀',
        ],
        'fail_mode' => [
            'block' => '차단(HTTP 428)',
            'log_only' => '로그만 기록',
        ],
        'applies_to' => [
            'self' => '본인',
            'admin' => '관리자',
            'both' => '모두',
        ],
        'source_type' => [
            'core' => '코어',
            'module' => '모듈',
            'plugin' => '플러그인',
            'admin' => '관리자',
        ],
    ],

    'message' => [
        'scope_type' => [
            'provider_default' => 'Provider 기본',
            'purpose' => 'Purpose 별',
            'policy' => 'Policy 별',
        ],
    ],
];
