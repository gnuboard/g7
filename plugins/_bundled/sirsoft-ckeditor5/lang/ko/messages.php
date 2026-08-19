<?php

return [
    'image' => [
        'not_found' => '이미지를 찾을 수 없습니다.',
    ],
    'upload' => [
        'required' => '업로드할 이미지 파일이 필요합니다.',
        'invalid_file' => '유효하지 않은 파일입니다.',
        'not_image' => '이미지 파일만 업로드할 수 있습니다.',
        'invalid_mime' => 'jpeg, jpg, png, gif, webp 형식만 지원합니다.',
        'too_large' => '파일 크기가 허용 용량(:max MB)을 초과합니다.',
        'forbidden' => '이미지 업로드 권한이 없습니다.',
        'failed' => '이미지 업로드에 실패했습니다.',
    ],
    'uploads' => [
        'not_found' => '업로드 이미지를 찾을 수 없습니다.',
        'file_delete_failed' => '이미지 파일 삭제에 실패했습니다. 잠시 후 다시 시도해 주세요.',
        'ids_required' => '삭제할 이미지를 선택해 주세요.',
        'ids_invalid' => '선택한 이미지 중 더 이상 존재하지 않는 항목이 있습니다. 목록을 새로고침한 뒤 다시 선택해 주세요.',
        'deleted' => '이미지를 삭제했습니다.',
        'bulk_deleted' => '선택한 이미지 :deleted건을 삭제했습니다.',
        'bulk_partially_deleted' => '선택한 이미지 중 :deleted건을 삭제했고 :failed건은 삭제하지 못했습니다. 실패한 항목은 목록에 남아 있습니다.',
    ],
    'cleanup' => [
        'retention_disabled' => '보존기간이 1일 미만으로 설정되어 정리를 수행하지 않았습니다.',
        'sources_incomplete' => '비활성 상태의 모듈이 있어 참조 판정이 불완전할 수 있으므로 미참조 이미지 정리를 건너뛰었습니다. 모듈을 활성화하거나 삭제한 뒤 다시 실행해 주세요.',
    ],
];
