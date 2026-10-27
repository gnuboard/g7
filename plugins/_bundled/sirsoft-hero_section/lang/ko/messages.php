<?php

return [
    'banner' => [
        'conflict' => '다른 관리자가 먼저 수정했습니다. 입력 내용은 보존되어 있습니다. 최신 배너를 다른 탭에서 확인하고 변경 내용을 비교하세요.',
        'fetched' => '배너를 불러왔습니다.',
        'created' => '배너를 등록했습니다.',
        'updated' => '배너를 수정했습니다.',
        'deleted' => '배너를 삭제했습니다.',
        'reordered' => '배너 순서를 바꿨습니다.',
        'bulk_status' => '배너 :count개의 노출 여부를 바꿨습니다.',
        'bulk_deleted' => '배너 :count개를 삭제했습니다.',
    ],
    'image' => [
        'uploaded' => '이미지를 올렸습니다.',
        'deleted' => '이미지를 삭제했습니다.',
        'delete_failed' => '이미지 파일을 지우지 못했습니다. 잠시 뒤 다시 시도하세요.',
        'attached_not_deletable' => '배너에 붙은 이미지는 여기서 지울 수 없습니다. 배너 수정 화면에서 바꾸고 저장하세요.',
        'not_found' => '이미지를 찾을 수 없습니다.',
    ],
    'position' => [
        'fetched' => '노출 위치 설정을 불러왔습니다.',
        'updated' => '슬라이더 설정을 저장했습니다.',
        'not_found' => '노출 위치를 찾을 수 없습니다.',
    ],
    'console' => [
        'prune_temp_images' => [
            'retention_disabled' => '보존 기간이 1일보다 짧아 임시 이미지를 정리하지 않습니다.',
            'dry_run' => ':days일보다 오래된 임시 이미지 :count개가 정리 대상입니다.',
            'done' => ':days일보다 오래된 임시 이미지 :scanned개 중 :deleted개를 지웠습니다(실패 :failed개).',
        ],
    ],
];
