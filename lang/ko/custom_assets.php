<?php

return [
    'errors' => [
        'not_found' => '파일을 찾을 수 없습니다: :path',
        'not_editable' => '이 형식(:extension)은 본문을 직접 편집할 수 없습니다. 파일을 새로 올려 교체해 주세요.',
        'too_large_to_edit' => '파일이 너무 커서 편집기에서 열 수 없습니다. (최대 :limit 바이트)',
        'read_failed' => '파일을 읽지 못했습니다: :path',
        'write_failed' => '파일을 저장하지 못했습니다: :path',
        'delete_failed' => '파일을 삭제하지 못했습니다: :path',
        'directory_failed' => '디렉토리를 만들지 못했습니다: :path — 사유 :reason, 소유자 :owner, 권한 :perms, 실행 계정 :process_user. 조치: :hint',
        'directory_failed_hint' => '서버에서 `sudo chown -R {웹계정}:{웹계정} :path` 로 소유자를 웹 계정으로 바꾸거나, 그룹 쓰기(`chmod -R g+w :path`)를 부여한 뒤 다시 시도하세요.',
        'reason' => [
            'occupied_by_file' => '같은 이름의 파일이 자리를 차지하고 있습니다',
            'ancestor_not_writable' => '상위 디렉토리에 쓸 수 없습니다',
            'create_failed' => '디렉토리 생성에 실패했습니다',
            'not_writable' => '디렉토리가 있지만 쓸 수 없습니다',
        ],
        'invalid_path' => '허용되지 않는 경로입니다: :path',
        'invalid_extension_target' => '대상 확장을 찾을 수 없습니다: :identifier',
        'extension_not_allowed' => '허용되지 않는 파일 형식입니다: :extension',
        'upload_too_large' => '업로드 파일이 너무 큽니다. (최대 :limit 바이트)',
    ],

    'validation' => [
        'path_required' => '파일 경로를 지정해 주세요.',
        'content_present' => '본문 필드가 필요합니다.',
        'file_required' => '올릴 파일을 선택해 주세요.',
        'file_invalid' => '올바른 파일이 아닙니다.',
        'file_mimes' => '허용되지 않는 파일 형식입니다. (허용: :allowed)',
    ],

    'messages' => [
        'listed' => '목록을 불러왔습니다.',
        'saved' => '저장했습니다.',
        'uploaded' => '파일을 올렸습니다.',
        'deleted' => '파일을 삭제했습니다.',
    ],
];
