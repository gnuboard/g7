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

    /*
    |--------------------------------------------------------------------------
    | .g7.html 저작 포맷 컴파일러 (S6 검증기) 정책
    |--------------------------------------------------------------------------
    |
    | layout:compile 이 산출 JSON 을 검증할 때 참조하는 화이트리스트. 코드가 아닌 설정으로
    | 두어 새 CDN·특수 화면이 생겨도 PO 가 코드 수정 없이 정책을 조정할 수 있게 한다.
    */
    'layout_markup' => [
        // <script src> 외부 JS 로더가 실을 수 있는 호스트. 전 번들 실측(2026-07-18): 우편번호
        // 검색(daum) + 리치 에디터(ckeditor) 두 곳뿐이다. 결제·본인인증 SDK 는 레이아웃이 아니라
        // 핸들러(JS)에서 로드하므로 여기 없다. https 와 protocol-relative(//) 두 스킴만 허용하며
        // (http 는 거부), 같은 origin 상대경로(/, /api/…)는 외부가 아니므로 검사에서 제외된다.
        'script_src_allowlist' => [
            't1.daumcdn.net',   // Daum 우편번호 API (주소 검색)
            'cdn.ckeditor.com', // CKEditor 5 UMD (리치 텍스트 에디터)
        ],

        // 플러그인이 완전 레이아웃을 두는 것은 관례상 금지(layout_extensions·설정만 허용)이나,
        // 이 layout_name 은 예외로 경고하지 않는다. gdpr_consent_log = 동의 기록 특수 화면
        // (PO 확정 07-16), plugin_settings = 코어 플러그인 설정 페이지 표준 이름.
        'plugin_complete_layout_allowlist' => [
            'gdpr_consent_log',
            'plugin_settings',
        ],
    ],
];
