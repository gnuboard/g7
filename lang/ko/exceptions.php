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
];
