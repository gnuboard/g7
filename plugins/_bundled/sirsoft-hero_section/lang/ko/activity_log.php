<?php

return [
    'action' => [
        'hero_banner' => [
            'create' => '히어로섹션 배너 등록',
            'update' => '히어로섹션 배너 수정',
            'delete' => '히어로섹션 배너 삭제',
            'bulk_status' => '히어로섹션 배너 일괄 노출 전환',
            'bulk_delete' => '히어로섹션 배너 일괄 삭제',
            'reorder' => '히어로섹션 배너 순서 변경',
            'image_update' => '히어로섹션 배너 이미지 교체',
            'image_remove' => '히어로섹션 배너 이미지 삭제',
            'video_update' => '히어로섹션 배너 영상 교체',
            'video_remove' => '히어로섹션 배너 영상 삭제',
        ],
        'hero_position' => [
            'update' => '히어로섹션 슬라이더 설정 변경',
        ],
    ],
    'description' => [
        'hero_banner_create' => '히어로섹션 배너 「:banner_title」을(를) 등록했습니다.',
        'hero_banner_update' => '히어로섹션 배너 「:banner_title」을(를) 수정했습니다.',
        'hero_banner_delete' => '히어로섹션 배너 「:banner_title」을(를) 삭제했습니다.',
        'hero_banner_bulk_show' => '히어로섹션 배너 「:banner_title」을(를) 노출로 바꿨습니다.',
        'hero_banner_bulk_hide' => '히어로섹션 배너 「:banner_title」을(를) 비노출로 바꿨습니다.',
        'hero_banner_reorder' => '히어로섹션 :position 위치의 배너 :count개 순서를 바꿨습니다.',
        'hero_banner_image_update' => '히어로섹션 배너 「:banner_title」의 :device 이미지를 교체했습니다.',
        'hero_banner_image_remove' => '히어로섹션 배너 「:banner_title」의 :device 이미지를 뺐습니다.',
        'hero_banner_video_update' => '히어로섹션 배너 「:banner_title」의 :device 영상을 교체했습니다.',
        'hero_banner_video_remove' => '히어로섹션 배너 「:banner_title」의 :device 영상을 뺐습니다.',
        'hero_position_update' => '히어로섹션 :position 위치의 슬라이더 설정을 바꿨습니다.',
    ],
    'fields' => [
        'position' => '노출 위치',
        'media_type' => '미디어 종류',
        'youtube_id' => '유튜브 영상 ID',
        'is_active' => '사용 여부',
        'display_period' => '노출 기간 방식',
        'starts_at' => '노출 시작',
        'ends_at' => '노출 종료',
        'title' => '제목',
        'link_url' => '이동할 URL',
        'autoplay' => '자동 재생',
        'interval_seconds' => '전환 간격(초)',
        'effect' => '전환 효과',
        'width' => '표시 폭',
    ],
];
