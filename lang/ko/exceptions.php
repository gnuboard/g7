<?php

return [
    // 사용자 관련 예외
    'cannot_delete_super_admin' => '슈퍼 관리자는 삭제할 수 없습니다.',

    'circular_reference' => '레이아웃 순환 참조 감지: :trace',
    'max_depth_exceeded' => '레이아웃 중첩 깊이가 최대 허용 깊이(:max)를 초과했습니다.',
    'template_file_copy_failed' => '템플릿 파일 복사 실패: :source → :destination',
    'template_build_directory_creation_failed' => '템플릿 빌드 디렉토리 생성 실패: :path',
    'template_dist_directory_not_found' => '템플릿 dist 디렉토리를 찾을 수 없습니다: :path',
    'template_not_found' => '템플릿을 찾을 수 없습니다: :identifier',
    'template_not_active' => '템플릿이 활성화되지 않았습니다: :identifier (상태: :status)',

    // 낙관적 잠금 — 동시 저장 충돌
    'concurrent_modification' => '다른 사용자가 먼저 저장했습니다. (리소스: :resource, 현재 버전: :current, 보낸 버전: :expected)',

    // 레이아웃 관련 예외
    'layout' => [
        'duplicate_data_source_id' => 'data_sources ID 중복: :id',
        'duplicate_data_source_id_in_file' => '레이아웃 파일 내 data_sources ID 중복: :ids (파일: :file)',
        'duplicate_data_source_id_extends' => 'extends 상속 관계에서 data_sources ID 중복: :ids (자식: :child, 부모: :parent)',
        'not_found' => '레이아웃을 찾을 수 없습니다: :name',
        'parent_not_found' => '부모 레이아웃을 찾을 수 없습니다: :parent (요청 레이아웃: :child)',

        // include 관련 예외
        'include_file_not_found' => 'include 파일을 찾을 수 없습니다: :path (해석된 경로: :resolved)',
        'invalid_include_json' => 'include 파일의 JSON 형식이 올바르지 않습니다: :path (오류: :error)',
        'circular_include' => 'include 순환 참조가 감지되었습니다: :trace',
        'max_include_depth_exceeded' => 'include 최대 깊이를 초과했습니다 (최대: :max단계)',
        'include_outside_directory' => 'include 경로가 허용된 디렉토리 외부입니다: :path (허용: :allowed_dir)',
    ],

    // 레이아웃 버전 관련 예외
    'layout_version' => [
        'save_failed_after_retries' => '레이아웃 버전 저장에 실패했습니다. :attempts회 재시도 후에도 실패했습니다.',
        'save_failed_unexpected' => '레이아웃 버전 저장 중 예기치 않은 오류가 발생했습니다.',
    ],

    // 레이아웃 저작 포맷(.g7.html) 컴파일 예외
    'layout_markup' => [
        // 위치 접두어 (모든 컴파일 오류 공통 봉투)
        'error_at' => ':file (:line행 :column열): :detail [:code]',
        'error_at_inline' => ':line행 :column열: :detail [:code]',
        'error_in_file' => ':file: :detail [:code]',

        // 마크업 구조
        'unsupported_markup' => '지원하지 않는 마크업입니다: :found — .g7.html 은 요소·텍스트·주석(<!-- -->)만 사용합니다.',
        'unescaped_lt' => '텍스트에 사용된 < 는 태그 시작으로 해석됩니다. 문자 그대로 쓰려면 &lt; 로 표기하세요.',
        'eof_in_tag' => '태그 <:tag> 가 닫히지 않은 채 파일이 끝났습니다. > 또는 /> 를 추가하세요.',
        'eof_in_comment' => '주석이 닫히지 않은 채 파일이 끝났습니다. --> 를 추가하세요.',

        // 태그명
        'empty_tag_name' => '태그명이 비어 있습니다.',
        'tag_uppercase' => '태그명은 소문자로 씁니다: <:found> → <:suggestion>',
        'invalid_tag_name' => '사용할 수 없는 태그명입니다: <:found> — 소문자와 하이픈만 사용합니다 (예: <data-grid>).',
        'end_tag_self_closing' => '종료 태그는 자기닫힘 표기를 쓸 수 없습니다: </:tag/> → </:tag>',
        'end_tag_with_attributes' => '종료 태그에는 속성을 쓸 수 없습니다: </:tag>',
        'void_end_tag' => '<:tag> 는 내용을 가질 수 없는 요소라 종료 태그가 없습니다: </:tag> 를 삭제하세요.',

        // 속성
        'invalid_attribute_name' => '사용할 수 없는 속성명입니다: :found',
        'unquoted_attribute_value' => '속성값은 항상 따옴표로 감쌉니다: :attr=:found → :attr=":found"',
        'unterminated_attribute_value' => '속성 :attr 의 값이 닫히지 않았습니다. 여는 따옴표(:quote)와 짝을 맞추세요.',
        'duplicate_attribute' => '속성 :attr 가 <:tag> 에 중복 지정되었습니다.',
        'missing_attribute_separator' => '속성 사이에는 공백이 필요합니다: <:tag> 의 :attr 앞을 띄우세요.',

        // 문자 참조
        'unknown_entity' => '알 수 없는 문자 참조입니다: :found — & 자체를 쓰려면 &amp; 로 표기하세요.',
        'invalid_entity' => '유효하지 않은 문자 참조입니다: :found',

        // 레지스트리 (components.json)
        'manifest_unreadable' => '컴포넌트 레지스트리 파일을 읽을 수 없습니다.',
        'manifest_invalid_json' => '컴포넌트 레지스트리가 올바른 JSON 이 아닙니다: :reason',
        'manifest_invalid' => '컴포넌트 레지스트리에 필수 항목이 없거나 형식이 다릅니다: :field',
        'ambiguous_component_tag' => '<:tag> 로 해석되는 컴포넌트가 둘입니다: :first, :second — 레지스트리에서 이름을 구분하세요.',
        'manifest_owner_ambiguous' => '컴포넌트 레지스트리가 templateId 와 identifier 를 함께 선언했습니다. 템플릿이면 templateId, 모듈·플러그인이면 identifier 만 남기세요.',
        'manifest_owner_missing' => '컴포넌트 레지스트리에 주인이 없습니다. 템플릿이면 templateId, 모듈·플러그인이면 identifier 를 선언하세요.',
        'extension_overrides_forbidden' => ':extension 은 확장이라 overrides 를 선언할 수 없습니다. 다른 확장의 컴포넌트를 대체하는 것은 템플릿만 할 수 있고, 자기 컴포넌트를 특정 템플릿에서만 다르게 내려면 for 를 사용하세요.',
        'template_for_variant_forbidden' => '템플릿은 for 를 선언할 수 없습니다. for 는 확장이 자기 컴포넌트의 템플릿 전용 판본을 낼 때만 사용합니다.',
        'override_target_component_unknown' => ':target 확장에 :component 컴포넌트가 없습니다. 이름을 확인하거나 targetVersion 제약을 맞추세요.',
        'duplicate_override_target' => ':component 를 두 번 오버라이드했습니다. 대체본은 하나만 선언하세요.',
        'for_variant_component_unknown' => 'for.:template 이 지목한 :component 는 이 확장이 제공하는 컴포넌트가 아닙니다. 전용 판본은 원본이 있어야 합니다.',
        'extension_id_tag_collision' => ':first 와 :second 가 같은 태그 앞자리(:prefix)로 겹칩니다. 두 확장의 컴포넌트를 태그로 구분할 수 없습니다.',

        // 트리 구조
        'unexpected_end_tag' => '열린 태그가 없는데 종료 태그를 만났습니다: </:tag>',
        'mismatched_end_tag' => '<:open> (:open_line행) 이 열려 있는데 </:tag> 로 닫았습니다.',
        'unclosed_tag' => '<:tag> 가 닫히지 않았습니다. </:tag> 를 추가하거나 <:tag/> 로 표기하세요.',
        'mixed_text_and_children' => '<:tag> 에 글자와 자식 태그를 함께 쓸 수 없습니다: ":found" — 글자는 자식 태그로 감싸세요.',
        'text_outside_element' => '태그 밖의 글자는 화면에 표시되지 않습니다: ":found"',
        'consecutive_comments' => '한 태그 앞에 주석을 둘 이상 쓸 수 없습니다. 하나의 <!-- --> 안에 줄바꿈으로 쓰세요.',
        'dangling_comment' => '주석 뒤에 설명할 태그가 없습니다. 주석은 바로 다음 태그의 설명이 됩니다.',

        // 태그 해석
        'unknown_tag' => '<:tag> 는 :template 템플릿에 등록되지 않은 태그입니다.',
        'unknown_tag_with_suggestion' => '<:tag> 는 등록되지 않은 태그입니다: <:suggestion> 을(를) 쓰려던 것 아닌가요?',
        'root_tag_in_node_position' => '<:tag> 는 문서 맨 바깥에만 쓰는 태그입니다.',
        'tag_not_yet_supported' => '<:tag> 는 :owner 표기라 아직 이 단계에서 다루지 않습니다.',

        // tag_not_yet_supported 의 :owner 자리 — 낱말을 코드에 두면 다른 언어 문장에 한국어가 박힌다.
        'tag_owner' => [
            'action' => '액션',
            'embedding' => '임베딩',
            'document_section' => '문서 섹션',
        ],

        // 속성 표기
        'foreign_idiom' => ':found 는 G7 문법이 아닙니다: :suggestion 을(를) 사용하세요.',
        'invalid_type_literal' => '":attr" 의 값이 JSON 이 아닙니다: :found — 표현식·문자열은 콜론 없이 :suggestion="..." 로 쓰세요.',
        'boolean_attribute_false' => ':attr 는 있고 없고로 참·거짓을 표기합니다: 참이면 :attr, 거짓이면 속성을 지우세요. 값 자체를 전달하려면 ::attr="false" 로 쓰세요.',
        'directive_needs_value' => ':attr 에는 값이 필요합니다.',
        'directive_rejects_type_literal' => '::attr 표기는 쓰지 않습니다. 값의 타입이 하나로 정해져 있으니 :suggestion="..." 로 쓰세요.',
        'directive_needs_integer' => ':attr 의 값은 정수여야 합니다: :found',
        'invalid_each_syntax' => 'data-each 는 "아이템[, 인덱스] in 소스" 형식입니다: :found',
        'mixed_if_expression' => ':attr 는 식 전체를 {{ }} 로 감싸야 합니다: :found — 일부만 감싸면 문자열이 되어 조건이 항상 참이 됩니다.',
        'condition_boolean_literal' => ':attr=":found" 대신 :suggestion=":found" 로 쓰세요 — 참·거짓 값 자체를 전달하는 표기입니다.',
        'node_key_as_attribute' => ':attr 는 속성이 아니라 노드 설정입니다: :suggestion 로 쓰세요. 속성으로 쓰면 컴포넌트에 전달만 되고 아무 동작도 하지 않습니다.',
        'attribute_not_supported' => ':attr 속성은 지원하지 않습니다. 태그명 자체가 컴포넌트를 정하므로 필요하지 않습니다.',

        // 구조 태그
        'unexpected_structural_child' => '<:tag> 는 <:parent> 안에서만 쓸 수 있습니다.',
        'missing_required_attribute' => '<:tag> 에는 :attr 속성이 필요합니다.',
        'duplicate_node_key' => ':key 를 두 번 지정했습니다.',
        'invalid_json_body' => '<:tag> 의 내용이 올바른 JSON 이 아닙니다: :reason',
        'field_value_and_children' => '<field name=":name"> 에 값과 하위 field 를 함께 쓸 수 없습니다.',

        // 액션 (S3)
        'action_tag_in_node_position' => '<:tag> 는 이벤트 처리 태그라 컴포넌트 자리에 쓸 수 없습니다. 컴포넌트 안에서 <on event="..."> 으로 감싸세요.',
        'action_trigger_missing' => '<on> 에는 이벤트가 필요합니다: 컴포넌트 이벤트는 event=, DOM 이벤트는 type=, 다른 곳의 이벤트 구독은 listen= 을 쓰세요.',
        'action_trigger_conflict' => '<on> 의 이벤트 표기가 충돌합니다: listen 은 event·type 과 함께 쓸 수 없고, mount·unmount 는 type 과 함께 쓸 수 없습니다.',
        'empty_action_block' => '<:tag> 안에 실행할 액션이 없습니다.',
        'unexpected_action_child' => '<:tag> 는 <:parent> 안에서 이 자리에 올 수 없습니다.',
        'unknown_action_tag' => '<:tag> 는 내장 핸들러가 아닙니다. 확장·동적 핸들러는 <action handler="이름"> 으로 쓰세요.',
        'unknown_action_tag_with_suggestion' => '<:tag> 는 내장 핸들러가 아닙니다: <:suggestion> 을(를) 쓰려던 것 아닌가요? (확장·동적 핸들러는 <action handler="이름">)',
        'duplicate_case_key' => ':case 케이스를 두 번 지정했습니다.',
        'duplicate_action_key' => ':key 를 두 번 지정했습니다. 속성과 태그가 같은 자리를 다투지 않는지 확인하세요.',
        'confirm_not_at_root' => 'confirm 은 이벤트에 직접 연결된 액션에서만 확인창을 띄웁니다. 중첩 액션의 confirm 은 무시되므로 진입 액션으로 옮기세요.',
        'init_action_key_forbidden' => '초기화 액션에는 :key 를 쓸 수 없습니다 — 초기화 단계에서 조용히 무시됩니다. 후속 동작은 <success> 안에 두거나 named action 으로 참조하세요.',
    ],

    // 설정 관련 예외
    'settings' => [
        'backup_creation_failed' => '설정 백업 파일 생성에 실패했습니다.',
        'restore_failed' => '설정 복원에 실패했습니다.',
        'category_not_found' => '설정 카테고리를 찾을 수 없습니다: :category',
        'save_failed' => '설정 저장에 실패했습니다: :category',
        'json_parse_failed' => 'JSON 파일 파싱에 실패했습니다: :path (:error)',
        'add_category_module_only' => 'addCategory는 모듈에서만 사용할 수 있습니다.',
    ],

    // 코어 업데이트 관련 예외
    'core_update' => [
        'handoff' => '업그레이드 핸드오프: :after_version 까지 완료 — :reason (재실행: :resume_command)',
    ],

    // Vendor 번들/설치 관련 예외
    'vendor' => [
        'composer_not_available' => 'Composer를 실행할 수 없는 환경입니다. 번들 모드를 사용하세요.',
        'composer_not_available_for_build' => 'vendor-bundle 빌드는 Composer 실행이 필요합니다. 개발 환경에서 Composer를 설치/설정한 후 다시 시도하세요.',
        'bundle_build_composer_failed' => 'vendor-bundle 빌드 중 composer install 실행 실패 (exit :exit): :message',
        'bundle_build_promote_failed' => 'vendor-bundle 빌드 결과를 최종 경로로 반영하지 못했습니다 (:from → :to)',
        'composer_execution_failed' => 'Composer 실행에 실패했습니다: :message',
        'bundle_zip_missing' => 'vendor-bundle.zip 파일을 찾을 수 없습니다: :path',
        'bundle_manifest_missing' => 'vendor-bundle.json 파일을 찾을 수 없습니다: :path',
        'bundle_manifest_invalid' => 'vendor-bundle.json 파일을 읽을 수 없습니다: :error',
        'bundle_integrity_failed' => '번들 파일의 무결성 검증에 실패했습니다: :details',
        'bundle_schema_unsupported' => '지원하지 않는 vendor-bundle.json 스키마 버전입니다: :version',
        'zip_archive_not_available' => 'PHP ZipArchive 확장이 설치되어 있지 않습니다.',
        'zip_hash_mismatch' => 'vendor-bundle.zip의 해시가 일치하지 않습니다 (기대: :expected, 실제: :actual).',
        'composer_json_sha_mismatch' => 'composer.json이 번들 빌드 시점 이후 변경되었습니다. vendor-bundle:build 로 재빌드하세요.',
        'composer_lock_sha_mismatch' => 'composer.lock이 번들 빌드 시점 이후 변경되었습니다. vendor-bundle:build 로 재빌드하세요.',
        'bundle_contains_unsafe_path' => '번들 zip에 안전하지 않은 파일 경로가 포함되어 있습니다: :path',
        'extraction_failed' => '번들 zip 추출에 실패했습니다: :message',
        'no_vendor_strategy_available' => 'Vendor 설치 전략을 결정할 수 없습니다. Composer와 번들 zip 모두 사용 불가합니다.',
        'source_dir_not_found' => '설치 소스 디렉토리를 찾을 수 없습니다: :path',
        'target_not_writable' => '대상 디렉토리에 쓰기 권한이 없습니다: :path',
        'target_not_writable_owner_hint' => "실행 사용자(:current_user)와 디렉토리 소유자(:owner)가 다릅니다. 'chown -R :current_user_name :path' 후 재시도하거나 소유자(:owner_name)로 SSH 로그인 후 실행하세요.",
        'composer_json_not_found' => 'composer.json 파일을 찾을 수 없습니다: :path',
        'no_composer_lock' => 'composer.lock 파일이 필요합니다. 먼저 composer install을 실행하세요: :path',
        'vendor_dir_not_found' => 'vendor/ 디렉토리가 존재하지 않습니다. 먼저 composer install을 실행하세요: :path',
        'composer_binary_not_found' => 'Composer 바이너리를 찾을 수 없습니다. COMPOSER_BINARY 환경변수를 설정하거나 composer를 PATH에 추가하세요.',
    ],
];
