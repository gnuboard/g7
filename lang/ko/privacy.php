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
];
