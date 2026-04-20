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

    // 설정 관련 예외
    'settings' => [
        'backup_creation_failed' => '설정 백업 파일 생성에 실패했습니다.',
        'restore_failed' => '설정 복원에 실패했습니다.',
        'category_not_found' => '설정 카테고리를 찾을 수 없습니다: :category',
        'save_failed' => '설정 저장에 실패했습니다: :category',
    ],

    // Vendor 번들/설치 관련 예외
    'vendor' => [
        'composer_not_available' => 'Composer를 실행할 수 없는 환경입니다. 번들 모드를 사용하세요.',
        'composer_not_available_for_build' => 'vendor-bundle 빌드는 Composer 실행이 필요합니다. 개발 환경에서 Composer를 설치/설정한 후 다시 시도하세요.',
        'bundle_build_composer_failed' => 'vendor-bundle 빌드 중 composer install 실행 실패 (exit :exit): :message',
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
    ],
];
