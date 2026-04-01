<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 모듈 설정 (Module Settings)
    |--------------------------------------------------------------------------
    |
    | 모듈 시스템 관련 설정입니다.
    |
    */

    // ZIP 파일 업로드 최대 크기 (MB)
    'upload_max_size' => env('MODULE_UPLOAD_MAX_SIZE', 50),

    // 모듈 설치 경로
    'install_path' => base_path('modules'),

    // GitHub 다운로드 타임아웃 (초)
    'github_timeout' => env('MODULE_GITHUB_TIMEOUT', 60),
];