<?php

return [
    'title' => '레이아웃 관리',

    'messages' => [
        'retrieved' => '레이아웃 정보를 조회했습니다.',
        'created' => '레이아웃 파일을 만들었습니다.',
        'renamed' => '레이아웃 이름을 바꿨습니다.',
        'deleted' => '레이아웃 파일을 삭제했습니다.',
    ],

    'errors' => [
        'not_found' => '레이아웃을 찾을 수 없습니다.',

        // 이름 규칙 — 세그먼트는 영숫자·밑줄·붙임표만, 선행·연속 슬래시와 `.`·`..` 은 불가.
        'invalid_name' => '사용할 수 없는 이름입니다: :name — 영문·숫자·밑줄(_)·붙임표(-)와 폴더 구분 슬래시(/)만 쓸 수 있습니다.',
        'name_taken' => '같은 이름의 레이아웃이 이미 있습니다: :name',

        // 모듈·플러그인이 제공한 원본은 지우거나 이름을 바꿀 수 없다 — 그 확장이 깨진다.
        'delete_blocked_source_type' => ':source(:identifier)에서 제공한 레이아웃은 삭제할 수 없습니다. 확장을 비활성화하거나 제거해 주세요.',
        'rename_blocked_source_type' => ':source(:identifier)에서 제공한 레이아웃은 이름을 바꿀 수 없습니다.',

        // 참조가 걸린 파일 — 무엇이 붙들고 있는지 목록을 함께 돌려준다.
        'delete_blocked_by_reference' => ':count개 레이아웃이 이 파일을 참조하고 있어 삭제할 수 없습니다. 참조를 먼저 정리해 주세요.',
        'rename_blocked_by_reference' => ':count개 레이아웃이 이 파일을 참조하고 있어 이름을 바꿀 수 없습니다. 참조를 먼저 정리해 주세요.',

        // 라우트가 걸린 레이아웃 — 차단이 아니라 확인 한 단계.
        'delete_requires_confirmation' => '이 레이아웃은 :route 화면에 연결돼 있습니다. 삭제하면 그 화면을 열 수 없게 됩니다.',
    ],

    // 참조 목록의 종류 표기 — 화면이 "왜 붙들려 있는지" 를 사람 말로 보여준다.
    'reference_kinds' => [
        'extends' => '상속(extends)',
        'partial' => '조각 참조(include·modal)',
        'route' => '라우트 연결',
    ],

    // `layout:repair-empty-objects` — 이미 `[]` 로 굳은 빈 객체를 원본 기준으로 되돌리는 명령 출력
    'repair_empty_objects' => [
        'description' => '편집기 저장·버전 복원 등으로 [] 로 굳은 레이아웃 빈 객체({})를 원본 기준으로 찾아 되돌립니다',
        'no_templates' => '대상 템플릿이 없습니다.',
        'header_table' => '테이블',
        'header_id' => 'id',
        'header_name' => '이름',
        'header_count' => '고칠 자리 수',
        'header_paths' => '경로 예시',
        'nothing' => '고칠 자리가 없습니다.',
        'summary' => '합계 — 행 :rows · 자리 :positions',
        'skipped' => '기준 없음(원본이 없거나 소속 행이 삭제됨)으로 건너뛴 행: :count',
        'preview_hint' => '미리보기입니다. --apply 를 붙이면 고칩니다.',
        'applied' => ':rows개 행을 고쳤습니다.',
    ],
];
