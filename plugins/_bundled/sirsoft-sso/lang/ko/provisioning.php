<?php

return [
    'block_reason' => [
        'deprovisioned' => '회사 계정 퇴사 처리',
        'reactivated' => '회사 계정 재활성',
    ],
    'role_mapping' => [
        'super_admin_only' => '관리자 역할로 이어지는 매핑과 관리자가 연결된 IdP 의 역할 동기화 방식은 최고관리자만 바꿀 수 있습니다.',
    ],
    'command' => [
        'production_as_of' => '운영 환경에서는 --as-of 를 쓸 수 없습니다.',
        'invalid_as_of' => '--as-of 시각을 읽을 수 없습니다.',
        'deprovision_check' => '주기 점검: IdP :idps개, 확인 :checked건, 퇴사 :deprovisioned건, 재활성 :reactivated건, 실패 :failed건',
        'withdraw' => '퇴사 후 탈퇴: 탈퇴 :withdrawn건, 관리자 건너뜀 :skipped_admin건, 건너뜀 :skipped건, 실패 :failed건',
        'prune' => '보존 정리: 로그인 시도 :attempts건, SCIM 이력 :scim건, 끝난 세션 :sessions건',
        'failed' => ':command 실패 — 로그를 확인하세요.',
    ],
];
