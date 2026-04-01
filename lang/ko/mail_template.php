<?php

return [
    // 목록 조회
    'fetch_success' => '메일 템플릿 목록을 조회했습니다.',
    'fetch_failed' => '메일 템플릿 목록 조회에 실패했습니다.',

    // 수정
    'save_success' => '메일 템플릿이 수정되었습니다.',
    'save_error' => '메일 템플릿 수정에 실패했습니다.',

    // 활성 토글
    'toggle_success' => '메일 템플릿 활성 상태가 변경되었습니다.',
    'toggle_failed' => '메일 템플릿 활성 상태 변경에 실패했습니다.',

    // 미리보기
    'preview_success' => '미리보기를 생성했습니다.',
    'preview_failed' => '미리보기 생성에 실패했습니다.',

    // 기본값 복원
    'reset_success' => '메일 템플릿이 기본값으로 복원되었습니다.',
    'reset_failed' => '메일 템플릿 기본값 복원에 실패했습니다.',
    'reset_no_default' => '기본 템플릿 데이터를 찾을 수 없습니다.',

    // 검증
    'validation' => [
        'subject_required' => '메일 제목은 필수입니다.',
        'subject_ko_required' => '메일 제목(한국어)은 필수입니다.',
        'body_required' => '메일 본문은 필수입니다.',
        'body_ko_required' => '메일 본문(한국어)은 필수입니다.',
        'per_page_min' => '페이지당 표시 건수는 1 이상이어야 합니다.',
        'per_page_max' => '페이지당 표시 건수는 100 이하여야 합니다.',
    ],
];
