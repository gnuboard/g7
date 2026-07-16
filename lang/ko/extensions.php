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
        'not_found' => '확장(:identifier) 을 찾을 수 없습니다.',
        'cascade_dependency_failed' => '동반 설치할 :type (:identifier) 의 설치에 실패했습니다: :message',
        'invalid_type' => '유효하지 않은 확장 타입입니다.',
        'not_auto_deactivated' => '이 확장은 코어 버전 호환성 문제로 자동 비활성화된 상태가 아닙니다.',
        'hidden_extension' => '내부용(hidden) 확장은 사용자 노출 대상이 아닙니다.',
    ],

    'warnings' => [
        'auto_deactivated' => ':type ":identifier"이(가) 코어 버전 호환성 문제로 자동 비활성화되었습니다.',
    ],

    'alerts' => [
        'incompatible_deactivated' => ':type ":name" 자동 비활성화됨',
        'incompatible_message' => '필요 버전: :required, 현재 설치됨: :installed',
        'recovered_title' => ':type ":name" 다시 호환 가능',
        'recovered_body' => '코어 업그레이드 후 호환됩니다 (이전 요구: :previously_required). 다시 활성화할 수 있습니다.',
        'recovered_success' => '확장이 다시 활성화되었습니다.',
        'dismissed' => '알림을 닫았습니다.',
        'auto_deactivated_listed' => '자동 비활성화된 확장 목록입니다.',
        'recover_action' => '다시 활성화',
        'dismiss_action' => '알림 닫기',
    ],

    'badges' => [
        'incompatible' => '코어 업그레이드 필요',
        'incompatible_tooltip' => '코어 :required 이상 필요 (현재: :installed)',
        'incompatible_sr' => ':name 은(는) 코어 :required 이상이 필요하지만 현재 :installed 가 설치되어 있어 업데이트할 수 없습니다.',
    ],

    'banner' => [
        'title' => '코어 호환성 문제로 자동 비활성화된 확장이 있습니다',
        'item_required' => '필요 버전: :required',
        'guide_link' => '코어 업그레이드 가이드',
        'dismiss' => '닫기',
    ],

    'update_modal' => [
        'compat_warning_title' => '코어 버전 호환성 경고',
        'compat_warning_message' => '이 :type 은(는) 코어 :required 이상이 필요합니다. (현재: :installed)',
        'compat_guide_link' => '코어 업그레이드 가이드 보기',
        'force_label' => '경고를 무시하고 강제 업데이트 (권장하지 않음)',
    ],

    'commands' => [
        'clear_cache_success' => '확장 버전 검증 캐시가 삭제되었습니다.',
    ],
];
