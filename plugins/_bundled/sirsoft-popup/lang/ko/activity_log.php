<?php

return [
    'action' => [
        'popup' => [
            'create' => '팝업 등록',
            'update' => '팝업 수정',
            'delete' => '팝업 삭제',
            'bulk_status' => '팝업 일괄 노출 전환',
            'bulk_copy' => '팝업 일괄 복사',
            'bulk_delete' => '팝업 일괄 삭제',
            'reorder' => '팝업 순서 변경',
            'image_update' => '팝업 이미지 교체',
            'image_remove' => '팝업 이미지 삭제',
        ],
    ],
    'description' => [
        'popup_create' => '팝업 「:popup_title」을(를) 등록했습니다.',
        'popup_update' => '팝업 「:popup_title」을(를) 수정했습니다.',
        'popup_delete' => '팝업 「:popup_title」을(를) 삭제했습니다.',
        'popup_bulk_show' => '팝업 「:popup_title」을(를) 노출로 바꿨습니다.',
        'popup_bulk_hide' => '팝업 「:popup_title」을(를) 비노출로 바꿨습니다.',
        'popup_bulk_copy' => '팝업을 복사해 「:popup_title」을(를) 만들었습니다(원본 ID: :source_id).',
        'popup_reorder' => '팝업 :count개의 순서를 바꿨습니다.',
        'popup_image_update' => '팝업 「:popup_title」의 :device 이미지를 교체했습니다.',
        'popup_image_remove' => '팝업 「:popup_title」의 :device 이미지를 뺐습니다.',
    ],
    'fields' => [
        'title' => '관리용 제목',
        'is_active' => '사용 여부',
        'content_type' => '내용 유형',
        'display_period' => '노출 기간 방식',
        'starts_at' => '노출 시작',
        'ends_at' => '노출 종료',
        'page_scope' => '노출 페이지',
        'audience' => '노출 대상',
        'device' => '노출 기기',
        'link_url' => '링크 URL',
    ],
];
