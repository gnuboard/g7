<?php

return [
    // 일반적인 응답 메시지
    'success' => '성공적으로 처리되었습니다.',
    'failed' => '처리에 실패했습니다.',
    'error_occurred' => '오류가 발생했습니다.',
    'not_found' => '요청한 리소스를 찾을 수 없습니다.',
    'unauthorized' => '인증이 필요합니다.',
    'forbidden' => '권한이 없습니다.',
    'validation_failed' => '입력 값 검증에 실패했습니다.',

    // 버튼 상태
    'saving' => '저장 중...',
    'deleting' => '삭제 중...',
    'processing' => '처리 중...',

    // 시스템 라벨
    'system' => '시스템',
    'yes' => '예',
    'no' => '아니오',
    'unknown' => '알 수 없음',

    // 공통 에러 메시지
    'errors' => [
        'github_url_empty' => 'GitHub URL이 비어있습니다.',
        'github_url_invalid' => '유효하지 않은 GitHub URL 형식입니다.',
        'github_api_failed' => 'GitHub API 호출에 실패했습니다.',
        'github_download_failed' => 'GitHub에서 다운로드에 실패했습니다.',
        'github_archive_download_failed' => '아카이브 다운로드에 실패했습니다. (:url)',
        'zip_file_not_found' => 'ZIP 파일을 찾을 수 없습니다.',
        'zip_open_failed' => 'ZIP 파일을 열 수 없습니다.',
    ],

    // 공통 검증 에러 라벨
    'validation_error' => '입력 값 검증 오류',

    // Changelog 검증
    'changelog_validation' => [
        'source_in' => '소스는 active, bundled, github 중 하나여야 합니다.',
        'version_format' => ':attribute 형식이 올바르지 않습니다. (예: 1.0.0, 1.0.0-beta.1)',
        'to_version_required' => '시작 버전이 지정된 경우 종료 버전도 필수입니다.',
    ],
];
