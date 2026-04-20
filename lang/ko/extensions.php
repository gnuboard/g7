<?php

return [
    'types' => [
        'module' => '모듈',
        'plugin' => '플러그인',
        'template' => '템플릿',
    ],

    'errors' => [
        'core_version_mismatch' => ':extension (:type)은(는) 그누보드7 코어 버전 :required 이상을 요구합니다. (현재: :installed)',
        'version_check_failed' => '버전 검증에 실패했습니다.',
        'operation_in_progress' => '":name"에 대해 진행 중인 작업(:status)이 있어 요청을 처리할 수 없습니다.',
        'zip_missing_manifest' => 'ZIP 내부에서 :file 매니페스트를 찾을 수 없습니다: :zip',
        'zip_invalid_manifest' => 'ZIP 내부의 :file 매니페스트를 JSON 으로 해석할 수 없습니다.',
        'zip_identifier_mismatch' => 'ZIP 매니페스트의 식별자가 대상 확장과 일치하지 않습니다. (기대: :expected, 실제: :actual)',
        'zip_missing_version' => 'ZIP 내부의 :file 매니페스트에 version 필드가 없습니다.',
    ],

    'warnings' => [
        'auto_deactivated' => ':type ":identifier"이(가) 코어 버전 호환성 문제로 자동 비활성화되었습니다.',
    ],

    'alerts' => [
        'incompatible_deactivated' => ':type ":name" 자동 비활성화됨',
        'incompatible_message' => '필요 버전: :required, 현재 설치됨: :installed',
    ],

    'commands' => [
        'clear_cache_success' => '확장 버전 검증 캐시가 삭제되었습니다.',
    ],
];
