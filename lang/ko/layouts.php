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

    // `layout:compile`·`layout:decompile`·`layout:check-installed` 명령 출력
    'console' => [
        'path_or_all' => '경로를 지정하거나 --all 을 사용하세요.',
        'compile_no_targets' => '대상 .json 레이아웃이 없습니다.',
        'budget_none_layouts' => '<json> 탈출구 방출 0건 — 전 레이아웃이 첫 급부 문법으로 왕복.',
        'budget_none_rows' => '<json> 탈출구 방출 0건 — 전 행이 첫 급부 문법으로 왕복.',
        'budget_header_inventory' => '=== &lt;json&gt; 탈출구 예산 (결함 인벤토리 대조) ===',
        'budget_header' => '=== &lt;json&gt; 탈출구 예산 ===',
        'budget_count' => ':count건',
        'budget_total_files' => '총 :count건 / 파일 :files개',
        'budget_total' => '총 :count건',
        'compile_error' => '컴파일 오류',
        'roundtrip_summary' => '왕복 — 대상 :total개, 불일치 :failed개, 스킵 :skipped개.',
        'not_json' => 'JSON 이 아닙니다: :file',
        'self_check_failed' => '자기검사 실패 — 산출을 기록하지 않습니다: :error',
        'compile_done' => '완료 — 파일 :files개, 실패 :failed개.',
        'sync_check_exclusive' => '--sync 와 --check 는 함께 쓸 수 없습니다 (--check 는 쓰지 않고 대조만 합니다).',
        'decompile_no_targets' => '역변환할 .g7.html 파일이 없습니다.',
        'decompile_done' => '완료 — 파일 :files개, 실패 :failed개, 경고 있는 파일 :warned개.',
        'bake_summary' => '굽기 — 생성 :created개, 갱신 :updated개, 무변경 :unchanged개.',
        'bake_stale' => '짝 .json 이 컴파일 산출과 다릅니다 (:count개) — `layout:decompile --all --sync` 로 다시 구우세요:',
        'bake_up_to_date' => '굽기 대조 — 짝 :count개 전건 최신.',
        'file_not_found' => '파일을 찾을 수 없습니다: :file',
        'sibling_created' => '(짝 .json 생성)',
        'sibling_updated' => '(짝 .json 갱신)',
        'warning_count' => '(경고 :count건)',
        'check_no_templates' => '점검할 템플릿이 없습니다.',
        'check_headers' => [
            'kind' => '종류',
            'template' => '템플릿',
            'name' => '이름',
            'verdict' => '판정',
            'escape' => 'escape',
            'warnings' => '경고',
            'ms' => 'ms',
        ],
        'check_summary' => '설치본 점검 — 템플릿 :templates개, 행 :rows개 (ok :ok · fail :fail · mismatch :mismatch), 등록 정합성 — 부적합 :inapplicable · 소실 :missing · 고아 :orphan · 읽기 실패 :unreadable, JSON 조각 참조 누락 :include_warnings, 라우트 미연결 플러그인 사용자 화면 :unrouted.',
        'include_skipped' => '조각 참조 점검 건너뜀 — :source :identifier: :error',
        'include_header' => '=== JSON 조각 참조 누락 (빈 자리로 등록됨) ===',
        'include_headers' => [
            'source' => '출처',
            'identifier' => '식별자',
            'file' => '파일',
            'partial' => '조각',
            'reason' => '사유',
        ],
        'unrouted_header' => '=== 라우트에 연결되지 않은 플러그인 사용자 화면 ===',
        'unrouted_headers' => [
            'plugin' => '플러그인',
            'layout' => '화면',
        ],
        'unrouted_hint' => '고객이 이 화면에 들어가려면 플러그인이 `core.routes.filter_merged` 필터로 사용자 라우트를 주입해야 합니다. 모달·부분 화면으로만 쓰면 무시해도 됩니다.',
        // `layout:audit-expressions`·`layout-versions:recalculate-diffs`·`layout-previews:cleanup` 명령 출력
        'audit_expressions' => [
            'invalid_source' => '--source 는 db|files|all 중 하나여야 합니다.',
            'summary' => '검사한 레이아웃: :scanned건 · 위반: :violations건',
            'headers' => [
                'source' => '출처',
                'target' => '대상',
                'violation' => '위반',
            ],
            'more' => '외 :count건 (--limit 또는 --json 으로 전체 확인)',
            'action_hint' => '조치: 편집기에서 해당 값을 고치거나 이전 버전으로 되돌리세요. 이 커맨드는 값을 바꾸지 않습니다.',
        ],
        'versions_recalculate_diffs' => [
            'start' => '레이아웃 버전 변경 요약 재계산을 시작합니다...',
            'done' => '버전 :count건의 변경 요약을 재계산했습니다.',
        ],
        'previews_cleanup' => [
            'done' => '만료된 미리보기 :count건이 삭제되었습니다.',
        ],
        // `layout:generate-fixture` 명령 출력
        'generate_fixture' => [
            'layout_not_found' => '레이아웃 파일을 찾을 수 없습니다: :path',
            'loading' => '레이아웃 로드 중: :path',
            'source_not_found' => '레이아웃 소스를 찾을 수 없습니다: :path',
            'loading_source' => '소스로 로드합니다: :path',
            'compile_failed' => '소스 컴파일 실패: :error',
            'json_parse_failed' => 'JSON 파싱 실패: :error',
            'partial_merge_failed' => 'Partial 병합 실패: :error',
            'directory_created' => '디렉토리 생성: :path',
            'done' => 'Fixture 생성 완료: :path',
            'file_size' => '파일 크기: :size bytes',
        ],
    ],
];
