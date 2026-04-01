<?php

return [
    // 기존 메시지
    'not_found' => '모듈 ":module"을(를) 찾을 수 없습니다.',
    'dependency_not_active' => '의존성 모듈 ":dependency"이(가) 설치되지 않았거나 비활성 상태입니다.',

    'status' => [
        'active' => '활성',
        'inactive' => '비활성',
        'updating' => '업데이트 중',
    ],

    'commands' => [
        'list' => [
            'headers' => [
                'identifier' => '식별자',
                'name' => '이름',
                'vendor' => '벤더',
                'version' => '버전',
                'status' => '상태',
            ],
            'status' => [
                'active' => '활성',
                'inactive' => '비활성',
                'uninstalled' => '미설치',
            ],
            'no_modules' => '모듈이 없습니다.',
            'summary' => '총 :total개 모듈 (설치: :installed, 활성: :active)',
            'invalid_status' => '잘못된 상태입니다. (installed, uninstalled, active, inactive)',
        ],
        'install' => [
            'success' => '모듈 ":module" 설치 완료',
            'vendor' => '벤더: :vendor',
            'version' => '버전: :version',
            'roles_created' => ':count개 역할 생성됨',
            'permissions_created' => ':count개 권한 생성됨',
            'menus_created' => ':count개 메뉴 생성됨',
            'already_installed' => '모듈 ":module"이(가) 이미 설치되어 있습니다.',
        ],
        'activate' => [
            'success' => '모듈 ":module" 활성화 완료',
            'not_installed' => '모듈 ":module"이(가) 설치되지 않았습니다.',
            'already_active' => '모듈 ":module"은(는) 이미 활성화되어 있습니다.',
            'layouts_registered' => ':count개 레이아웃 등록됨',
        ],
        'deactivate' => [
            'success' => '모듈 ":module" 비활성화 완료',
            'not_installed' => '모듈 ":module"이(가) 설치되지 않았습니다.',
            'not_active' => '모듈 ":module"은(는) 활성화되어 있지 않습니다.',
            'warning' => '모듈의 라우트와 기능이 비활성화되었습니다.',
            'layouts_deleted' => ':count개 레이아웃 삭제됨',
        ],
        'uninstall' => [
            'success' => '모듈 ":module" 삭제 완료',
            'roles_deleted' => ':count개 역할 삭제됨',
            'permissions_deleted' => ':count개 권한 삭제됨',
            'menus_deleted' => ':count개 메뉴 삭제됨',
            'layouts_deleted' => ':count개 레이아웃 삭제됨',
            'confirm_prompt' => '모듈 ":module"을(를) 삭제하시겠습니까?',
            'confirm_details' => [
                'roles' => '- :count개의 역할이 삭제됩니다.',
                'permissions' => '- :count개의 권한이 삭제됩니다.',
                'menus' => '- :count개의 메뉴가 삭제됩니다.',
                'layouts' => '- :count개의 레이아웃이 삭제됩니다.',
                'data' => '- 모든 모듈 데이터가 삭제됩니다.',
            ],
            'confirm_question' => '정말로 삭제하시겠습니까?',
            'aborted' => '모듈 삭제가 취소되었습니다.',
            'not_installed' => '모듈 ":module"이(가) 설치되지 않았습니다.',
        ],
        'cache_clear' => [
            'clearing_all' => '모든 모듈 캐시를 삭제합니다...',
            'clearing_single' => '모듈 ":module" 캐시를 삭제합니다...',
            'success_all' => '모듈 캐시 삭제 완료 (:count개 항목)',
            'success_single' => '모듈 ":module" 캐시 삭제 완료 (:count개 항목)',
        ],
        'check_updates' => [
            'not_installed' => '모듈 ":module"이(가) 설치되지 않았습니다.',
            'no_installed' => '설치된 모듈이 없습니다.',
            'update_available' => '업데이트 가능',
            'up_to_date' => '최신',
            'headers' => [
                'identifier' => '식별자',
                'current_version' => '현재 버전',
                'latest_version' => '최신 버전',
                'source' => '소스',
                'status' => '상태',
            ],
            'summary' => '총 :total개 확인 (업데이트 가능: :updates개)',
            'single_up_to_date' => '모듈 ":module"은(는) 최신 버전입니다. (v:version)',
            'single_update_available' => '모듈 ":module" 업데이트 가능: v:current → v:latest (:source)',
        ],
        'update' => [
            'success' => '모듈 ":module" 업데이트 완료',
            'version_change' => 'v:from → v:to',
            'no_update' => '모듈 ":module"에 사용 가능한 업데이트가 없습니다.',
            'not_installed' => '모듈 ":module"이(가) 설치되지 않았습니다.',
            'current_version' => '현재 버전: :version',
            'latest_version' => '최신 버전: :version',
            'update_source' => '업데이트 소스: :source',
            'confirm_question' => '업데이트를 진행하시겠습니까?',
            'aborted' => '업데이트가 취소되었습니다.',
            'backup_restored' => '백업에서 이전 버전이 복원되었습니다.',
            'force_mode' => '강제 업데이트 모드: 버전 비교를 건너뛰고 재설치합니다.',
        ],
    ],

    // Composer 의존성 설치 메시지
    'composer_install' => [
        'start' => '모듈 ":module"의 Composer 의존성을 설치합니다...',
        'success' => '모듈 ":module"의 Composer 의존성 설치 완료',
        'no_dependencies' => '모듈 ":module"에 설치할 Composer 의존성이 없습니다.',
        'failed' => '모듈 ":module"의 Composer 의존성 설치에 실패했습니다.',
        'summary' => '📊 결과: 성공 :success개, 스킵 :skip개, 실패 :fail개',
    ],

    // 업데이트 관련 메시지
    'update_success' => '모듈 ":module"이(가) :version 버전으로 업데이트되었습니다.',
    'update_failed' => '모듈 업데이트에 실패했습니다: :error',
    'update_hook_failed' => '모듈 업데이트 훅 실행에 실패했습니다.',
    'no_update_available' => '업데이트할 내용이 없습니다.',
    'check_updates_success' => '업데이트 확인이 완료되었습니다.',
    'check_updates_failed' => '업데이트 확인에 실패했습니다.',
    'not_installed' => '모듈 ":module"이(가) 설치되지 않았습니다.',

    // _pending 관련 메시지
    'pending_not_found' => '설치 대기 목록에서 모듈 ":module"을(를) 찾을 수 없습니다.',
    'already_exists' => '모듈 ":module"이(가) 이미 존재합니다.',
    'move_failed' => '모듈 ":module" 이동에 실패했습니다.',

    // 모듈 서비스 오류 메시지
    'deactivate_warning' => '이 모듈에 의존하는 활성 템플릿이 있습니다.',
    'installation_failed' => '모듈 설치에 실패했습니다: :error',
    'activation_failed' => '모듈 활성화에 실패했습니다: :error',
    'deactivation_failed' => '모듈 비활성화에 실패했습니다: :error',
    'uninstallation_failed' => '모듈 제거에 실패했습니다: :error',
    'refresh_layouts_failed' => '레이아웃 갱신에 실패했습니다: :error',

    // 경고 메시지
    'warnings' => [
        'has_dependent_templates' => '이 모듈에 의존하는 활성화된 템플릿이 있습니다. 비활성화하려면 강제 옵션을 사용하세요.',
        'has_dependents' => '이 모듈에 의존하는 활성화된 확장이 있습니다.',
        'deactivation_warning' => '비활성화하면 다음 템플릿이 영향을 받습니다:',
        'confirm_deactivation' => '그래도 비활성화하시겠습니까?',
        'missing_dependencies' => '이 모듈을 활성화하려면 다음 의존성이 필요합니다.',
    ],

    // 활성화 경고 메시지
    'activate_warning' => '모듈 활성화를 위해 필요한 의존성이 충족되지 않았습니다.',

    // 비활성화 경고 메시지 (확장)
    'deactivate_warning_extended' => '이 모듈에 의존하는 활성화된 확장(템플릿, 모듈, 플러그인)이 있습니다.',

    // 오류 메시지
    'errors' => [
        'zip_open_failed' => 'ZIP 파일을 열 수 없습니다.',
        'module_json_not_found' => 'module.json 파일을 찾을 수 없습니다.',
        'module_json_invalid' => 'module.json 파일 형식이 올바르지 않습니다.',
        'identifier_missing' => '모듈 식별자가 누락되었습니다.',
        'already_installed' => '이미 설치된 모듈입니다.',
        'install_failed' => '모듈 설치에 실패했습니다.',
        'github_url_invalid' => '유효하지 않은 GitHub URL 형식입니다.',
        'github_download_failed' => 'GitHub에서 다운로드에 실패했습니다.',
        'github_repo_not_found' => 'GitHub 저장소를 찾을 수 없습니다.',
        'module_not_found' => '모듈 :name을(를) 찾을 수 없습니다.',
        'module_not_active' => '모듈 :name이(가) 활성화되어 있지 않습니다.',
        // 에셋 서빙 관련 오류 메시지
        'not_found' => '모듈 :module을(를) 찾을 수 없습니다.',
        'file_not_found' => '파일을 찾을 수 없습니다.',
        'file_type_not_allowed' => '허용되지 않은 파일 형식입니다.',
        'unknown_error' => '알 수 없는 오류가 발생했습니다.',
        'invalid_permission_structure' => '모듈 ":identifier"의 권한 구조가 올바르지 않습니다: :reason',
        'invalid_translation_path' => '모듈 ":identifier"의 언어 파일 경로가 올바르지 않습니다. ":wrong_path" 대신 ":correct_path"를 사용해야 합니다.',
        'layout_validation_json_error' => '레이아웃 파일 :file의 JSON 파싱에 실패했습니다: :error',
        'layout_validation_missing_name' => '레이아웃 파일 :file에 layout_name이 누락되었습니다.',
        'layout_validation_partial_error' => '레이아웃 파일 :file의 partial 처리에 실패했습니다: :error (partial: :partial)',
        'layout_validation_validation_failed' => '모듈 :identifier의 레이아웃 검증에 실패했습니다. :count개의 오류가 발견되었습니다.',
        'operation_in_progress' => '모듈 ":name"이(가) 현재 :status 상태입니다. 작업이 완료될 때까지 기다려 주세요.',
        'github_api_failed' => 'GitHub API 호출에 실패했습니다.',
        'invalid_github_url' => '유효하지 않은 GitHub URL입니다.',
        'zip_url_not_found' => 'ZIP 다운로드 URL을 찾을 수 없습니다.',
        'download_failed' => '모듈 ":module" :version 버전 다운로드에 실패했습니다.',
        'zip_extract_failed' => 'ZIP 압축 해제에 실패했습니다.',
        'extracted_dir_not_found' => '압축 해제된 디렉토리를 찾을 수 없습니다.',
        'reload_failed' => '모듈 다시 로드에 실패했습니다.',
        'delete_directory_failed' => '모듈 디렉토리 삭제에 실패했습니다.',
        'update_failed' => '모듈 ":module" 업데이트에 실패했습니다: :error',
    ],

    // 유효성 검사 메시지
    'validation' => [
        // 기본 모듈 필드
        'name_required' => '모듈 이름은 필수입니다.',
        'name_string' => '모듈 이름은 문자열이어야 합니다.',
        'name_max' => '모듈 이름은 255자를 초과할 수 없습니다.',

        // 검색 필터
        'search_max' => '검색어는 255자를 초과할 수 없습니다.',
        'filters_max' => '검색 조건은 최대 10개까지만 지정할 수 있습니다.',
        'filter_field_required' => '검색 필드는 필수입니다.',
        'filter_field_invalid' => '유효하지 않은 검색 필드입니다.',
        'filter_value_required' => '검색 값은 필수입니다.',
        'filter_value_max' => '검색 값은 255자를 초과할 수 없습니다.',
        'filter_operator_invalid' => '유효하지 않은 검색 연산자입니다.',
        'status_invalid' => '유효하지 않은 상태입니다.',
        'per_page_min' => '페이지당 항목 수는 최소 1개입니다.',
        'per_page_max' => '페이지당 항목 수는 최대 100개입니다.',
        'page_min' => '페이지 번호는 최소 1입니다.',

        // 파일 업로드
        'file_required' => '모듈 파일은 필수입니다.',
        'file_invalid' => '유효한 파일이 아닙니다.',
        'file_must_be_zip' => '모듈 파일은 ZIP 형식이어야 합니다.',
        'file_max_size' => '모듈 파일 크기는 :sizeMB를 초과할 수 없습니다.',

        // GitHub
        'github_url_required' => 'GitHub URL은 필수입니다.',
        'github_url_invalid' => '유효한 URL 형식이 아닙니다.',
        'github_url_format' => 'GitHub 저장소 URL 형식이 올바르지 않습니다.',

        // with 파라미터
        'with_max' => 'with 파라미터는 최대 10개까지 지정할 수 있습니다.',
        'with_invalid' => '유효하지 않은 with 관계입니다.',
    ],
];
