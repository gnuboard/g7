<?php

return [
    'popup' => [
        'conflict' => '다른 관리자가 먼저 수정했습니다. 입력 내용은 보존되어 있습니다. 최신 팝업과 비교한 뒤 다시 저장하세요.',
        'fetched' => '팝업을 불러왔습니다.',
        'created' => '팝업을 등록했습니다.',
        'updated' => '팝업을 수정했습니다.',
        'deleted' => '팝업을 삭제했습니다.',
        'reordered' => '팝업 순서를 바꿨습니다.',
        'bulk_status' => '팝업 :count개의 노출 여부를 바꿨습니다.',
        'bulk_copied' => '팝업 :count개를 복사했습니다. 복사본은 비활성으로 맨 뒤에 추가됐습니다.',
        'bulk_copied_partial' => '팝업 :count개를 복사했고 :failed개는 복사하지 못했습니다. 이미지 파일을 확인한 뒤 다시 시도하세요.',
        'bulk_deleted' => '팝업 :count개를 삭제했습니다.',
        'copy_suffix' => ' (복사)',
    ],
    'image' => [
        'uploaded' => '이미지를 올렸습니다.',
        'deleted' => '이미지를 삭제했습니다.',
        'delete_failed' => '이미지 파일을 지우지 못했습니다. 잠시 뒤 다시 시도하세요.',
        'attached_not_deletable' => '팝업에 붙은 이미지는 여기서 지울 수 없습니다. 팝업 수정 화면에서 바꾸고 저장하세요.',
        'not_found' => '이미지를 찾을 수 없습니다.',
    ],
    'settings' => [
        'fetched' => '표시 방식을 불러왔습니다.',
    ],
    'console' => [
        'prune_temp_images' => [
            'retention_disabled' => '보존 기간이 1일보다 짧아 임시 이미지를 정리하지 않습니다.',
            'dry_run' => ':days일보다 오래된 임시 이미지 :count개가 정리 대상입니다.',
            'done' => ':days일보다 오래된 임시 이미지 :scanned개 중 :deleted개를 지웠습니다(실패 :failed개).',
        ],
    ],
];
