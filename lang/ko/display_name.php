<?php

return [
    // 표시 이름 종류
    'kinds' => [
        'name' => '이름',
        'nickname' => '닉네임',
        'masked_name' => '가린 이름',
        'masked_id' => '아이디 가림',
        'name_nickname' => '이름(닉네임)',
    ],

    // 표시 이름 영역
    'areas' => [
        'site' => '사이트 기본',
        'admin' => '관리자 화면',
        'notification' => '알림',
    ],

    // 영역의 방식
    'modes' => [
        'inherit' => '상위 영역 따름',
        'fixed' => '종류 고정',
        'member' => '회원이 고른 값',
    ],

    // 미리보기에 쓰는 표본 회원
    'sample' => [
        'name' => '홍길동',
        'nickname' => '길동이',
    ],

    // 닉네임 항목 잠금 사유
    'locks' => [
        'nickname_display' => '표시 이름 설정이 닉네임을 쓰고 있어 바꿀 수 없습니다. 표시 이름 설정에서 닉네임을 쓰는 종류를 빼면 다시 바꿀 수 있습니다.',
    ],

    // 추가 항목이 표시 이름으로 쓰이는 곳 (항목 삭제를 막을 때 보인다)
    'usages' => [
        'selectable_kind' => '회원이 고를 수 있는 표시 이름 종류',
        'member_default_kind' => '회원 기본 표시 이름 종류',
        'area' => ':area 영역의 표시 이름',
        'area_instance' => ':area: :instance',
    ],

    'validation' => [
        'not_permitted' => '표시 이름 종류를 직접 고를 권한이 없습니다.',
        'not_selectable' => '고를 수 없는 표시 이름 종류입니다. 종류를 다시 골라 주세요.',
        'unknown_kind' => '사용할 수 없는 표시 이름 종류입니다: :kind',
        'unknown_area' => '사용할 수 없는 표시 이름 영역입니다: :area',
        'default_not_selectable' => '회원 기본 종류는 회원이 고를 수 있는 종류 가운데 하나여야 합니다.',
        'site_cannot_inherit' => '사이트 기본 영역은 따를 상위 영역이 없습니다. 종류를 고정하거나 회원이 고른 값으로 정해 주세요.',
        'fixed_kind_required' => ':area 영역에 고정할 표시 이름 종류를 골라 주세요.',
    ],

    'attributes' => [
        'preview_name' => '미리보기 이름',
        'preview_nickname' => '미리보기 닉네임',
        'preview_email' => '미리보기 이메일',
    ],
];
