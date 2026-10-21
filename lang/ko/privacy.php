<?php

return [
    // 보유기간 처리 핸들러 라벨 — 커맨드 출력과 설정 화면의 dry-run 뱃지가 함께 쓴다.
    'handlers' => [
        'user_withdrawn' => '탈퇴 회원 잔여 개인정보',
    ],

    // 조치 대상 목록 모달 — 열 라벨과 목록 API 오류 문구.
    'targets' => [
        'columns' => [
            'name' => '이름',
            'email' => '이메일',
            'withdrawn_at' => '탈퇴일',
            'last_activity_at' => '마지막 활동일',
            'dormant_at' => '휴면 전환일',
        ],
        'unknown_handler' => '등록되지 않은 개인정보 처리 대상입니다.',
        'unknown_stage' => '알 수 없는 휴면 단계입니다.',
    ],

    // 관리자 직권 실행 — 설정 화면 카드의 「지금 실행」(큐 접수 → 처리 → 카드에 결과).
    'manual_run' => [
        'queued' => '실행을 큐에 접수했습니다. 처리가 끝나면 카드에 결과가 표시됩니다.',
        'unknown_target' => '등록되지 않은 직권 실행 대상입니다.',
        'target_missing' => '접수 후 대상이 사라져 실행하지 못했습니다 (확장이 비활성화되었을 수 있습니다).',
        'stale' => ':minutes분 넘게 끝나지 않아 실패로 처리했습니다. 큐 워커가 동작하는지 확인하세요.',
        'status' => [
            'queued' => '처리 대기',
            'running' => '처리 중',
            'completed' => '완료',
            'failed' => '실패',
        ],
        'dormant_stages' => [
            'pre_notice' => '휴면 전환 사전 안내',
            'convert' => '휴면 전환',
            'withdraw_notice' => '휴면 계정 탈퇴 예정 안내',
            'withdraw' => '휴면 계정 탈퇴 처리',
        ],
        // 비밀번호 만료 (#736) — 보안 탭 「지금 실행」
        'password_expiry_stages' => [
            'notice' => '비밀번호 만료 사전 안내',
            'expire' => '비밀번호 만료 처리',
        ],
    ],
    // 동의 스냅샷 공개 조회(GET /api/consent) 응답 문구.
    'consent' => [
        'fetch_success' => '방문자 동의 상태를 확인했습니다.',
    ],
    // `privacy:*` 명령 출력
    'console' => [
        'process_retention' => [
            'handler_required' => '--handler 에 핸들러 식별자를 지정하세요. 전 핸들러를 실행하려면 옵션을 빼세요.',
            'registered_handlers' => '등록된 핸들러: :handlers',
            'none' => '없음',
            'unknown_handler' => '등록되지 않은 보유기간 핸들러입니다: :handler',
            'no_handlers' => '등록된 보유기간 핸들러가 없습니다.',
            'handler_disabled' => ':id (:label): 꺼져 있어 실행하지 않았습니다.',
            'handler_locked' => ':id (:label): 관리자 직권 실행이 처리 중이라 건너뛰었습니다.',
            'handler_failed' => ':id (:label): 실패 — :error',
            'handler_done' => ':id (:label): :count건 (:mode)',
            'total' => '합계: :count건',
        ],
        'process_dormant' => [
            'disabled' => '휴면 계정 기능이 꺼져 있어 실행하지 않았습니다. (privacy.dormant_enabled = false)',
            'locked' => '관리자 직권 실행이 휴면 단계를 처리 중이라 이번 주기는 건너뛰었습니다.',
            'pre_notice' => '휴면 전환 사전 안내: :count건',
            'converted' => '휴면 전환: :count건',
            'withdraw_notice' => '탈퇴 예정 안내: :count건',
            'withdrawn' => '탈퇴 처리: :count건',
            'withdraw_disabled' => '휴면 후 자동 탈퇴는 꺼져 있어 실행하지 않았습니다. (privacy.dormant_withdraw_enabled = false)',
            'stage_failed' => '단계 실패 — :stage: :error',
        ],
    ],
];
