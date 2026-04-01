<?php

return [
    // 역할 관리 메시지
    'fetch_success' => '역할 정보를 성공적으로 가져왔습니다.',
    'fetch_failed' => '역할 정보를 가져오는데 실패했습니다.',
    'create_success' => '역할이 성공적으로 생성되었습니다.',
    'create_failed' => '역할 생성에 실패했습니다.',
    'update_success' => '역할이 성공적으로 수정되었습니다.',
    'update_failed' => '역할 수정에 실패했습니다.',
    'delete_success' => '역할이 성공적으로 삭제되었습니다.',
    'delete_failed' => '역할 삭제에 실패했습니다.',
    'system_role_delete_error' => '시스템 역할은 삭제할 수 없습니다.',

    // 검증 메시지
    'validation' => [
        'name_required' => '역할 이름은 필수입니다.',
        'identifier_required' => '식별자는 필수입니다.',
        'identifier_format' => '식별자는 소문자로 시작하며, 소문자, 숫자, 밑줄(_)만 사용할 수 있습니다.',
        'identifier_unique' => '이미 사용 중인 식별자입니다.',
        'identifier_max' => '식별자는 최대 100자까지 입력할 수 있습니다.',
        'permission_ids_array' => '권한 목록은 배열 형태여야 합니다.',
        'permission_ids_exists' => '선택한 권한 중 유효하지 않은 권한이 있습니다.',
        'permission_ids_integer' => '권한 ID는 정수여야 합니다.',
    ],

    // 에러 메시지
    'errors' => [
        'system_role_delete' => '시스템 역할은 삭제할 수 없습니다.',
        'extension_owned_role_delete' => '확장(모듈/플러그인)이 소유한 역할은 삭제할 수 없습니다. 해당 확장을 제거하면 자동으로 정리됩니다.',
    ],
];
