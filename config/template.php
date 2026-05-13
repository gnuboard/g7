<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 필수 Admin 컴포넌트 (Required Admin Components)
    |--------------------------------------------------------------------------
    |
    | 모든 admin 타입 템플릿이 반드시 구현해야 하는 컴포넌트 목록입니다.
    | 모듈 개발자는 이 컴포넌트들만 사용하면 모든 admin 템플릿에서 동작이 보장됩니다.
    |
    | All admin templates must implement these components.
    | Module developers can use these components with guaranteed compatibility
    | across all admin templates.
    |
    */
    'required_admin_components' => [
        // 기본 컴포넌트 (Basic Components) - HTML 태그 래핑
        'A',               // 앵커/링크
        'Button',          // 버튼
        'Checkbox',        // 체크박스
        'Div',             // 범용 컨테이너
        'Form',            // 폼 컨테이너
        'H1',              // 제목 1
        'H2',              // 제목 2
        'H3',              // 제목 3
        'Icon',            // 아이콘
        'Img',             // 이미지
        'Input',           // 텍스트 입력
        'Label',           // 라벨
        'Li',              // 리스트 아이템
        'Nav',             // 네비게이션
        'P',               // 문단
        'Section',         // 섹션
        'Select',          // 선택 박스
        'Span',            // 인라인 텍스트
        'Svg',             // SVG 컨테이너
        'Table',           // 테이블
        'Tbody',           // 테이블 바디
        'Td',              // 테이블 셀
        'Textarea',        // 텍스트 영역
        'Th',              // 테이블 헤더 셀
        'Thead',           // 테이블 헤더
        'Tr',              // 테이블 행
        'Ul',              // 순서 없는 리스트

        // 집합 컴포넌트 (Composite Components) - UI 패턴 캡슐화
        'Alert',           // 알림 메시지
        'Badge',           // 뱃지 - 상태 표시
        'Card',            // 카드 컨테이너
        'DataTable',       // 데이터 테이블 - 목록 페이지 필수
        'FormField',       // 폼 필드 래퍼 (라벨, 에러 메시지 포함)
        'Modal',           // 모달 다이얼로그
        'PageHeader',      // 페이지 헤더 (제목, 브레드크럼, 액션)
        'Pagination',      // 페이지네이션 - 목록 페이지 페이징
    ],

    /*
    |--------------------------------------------------------------------------
    | 템플릿 검증 설정 (Template Validation Settings)
    |--------------------------------------------------------------------------
    |
    | 템플릿 설치 시 필수 컴포넌트 검증 관련 설정입니다.
    |
    */
    'validation' => [
        // 템플릿 설치 시 필수 컴포넌트 검증 활성화 여부
        'validate_on_install' => false,

        // 검증 실패 시 설치 차단 여부 (false면 경고만 표시)
        'block_on_failure' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | 레이아웃 설정 (Layout Settings)
    |--------------------------------------------------------------------------
    |
    | 레이아웃 시스템 관련 설정입니다.
    |
    */
    'layout' => [
        // 최대 상속 깊이
        'max_inheritance_depth' => 10,

        // 캐시 TTL (초)
        'cache_ttl' => 3600,
    ],
];
