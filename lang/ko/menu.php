<?php

return [
    // 메뉴 관련 메시지
    'fetch_success' => '메뉴를 성공적으로 가져왔습니다.',
    'fetch_failed' => '메뉴를 가져오는데 실패했습니다.',
    'active_fetch_failed' => '활성화된 메뉴를 가져오는데 실패했습니다.',
    'create_success' => '메뉴가 성공적으로 생성되었습니다.',
    'create_failed' => '메뉴 생성에 실패했습니다.',
    'create_error' => '메뉴 생성 중 오류가 발생했습니다.',
    'update_success' => '메뉴가 성공적으로 업데이트되었습니다.',
    'update_failed' => '메뉴 업데이트에 실패했습니다.',
    'update_error' => '메뉴 업데이트 중 오류가 발생했습니다.',
    'delete_success' => '메뉴가 성공적으로 삭제되었습니다.',
    'delete_failed' => '메뉴 삭제에 실패했습니다.',
    'delete_error' => '메뉴 삭제 중 오류가 발생했습니다.',
    'order_update_success' => '메뉴 순서가 성공적으로 업데이트되었습니다.',
    'order_update_failed' => '메뉴 순서 업데이트에 실패했습니다.',
    'slug_already_exists' => '이미 사용 중인 슬러그입니다.',
    'parent_menu_not_found' => '존재하지 않는 부모 메뉴입니다.',
    'cannot_set_self_as_parent' => '자기 자신을 부모로 설정할 수 없습니다.',
    'cannot_delete_menu_with_children' => '자식 메뉴가 있는 메뉴는 삭제할 수 없습니다.',

    // 검증 메시지
    'validation' => [
        'is_active_boolean' => '활성화 상태는 참/거짓 값이어야 합니다.',
        'sort_by_invalid' => '정렬 기준이 유효하지 않습니다.',
        'sort_order_invalid' => '정렬 순서가 유효하지 않습니다.',
        'filters_array' => '필터는 배열 형식이어야 합니다.',
        'filters_max' => '필터는 최대 10개까지 지정할 수 있습니다.',
        'filter_field_required' => '필터 필드는 필수입니다.',
        'filter_field_invalid' => '유효하지 않은 필터 필드입니다.',
        'filter_value_required' => '필터 값은 필수입니다.',
        'filter_value_max' => '필터 값은 최대 255자까지 입력할 수 있습니다.',
        'filter_operator_invalid' => '유효하지 않은 필터 연산자입니다.',
    ],
];
