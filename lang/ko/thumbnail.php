<?php

/**
 * 이미지 썸네일 — 관리 API 응답·명령 출력·원본 제공자 라벨
 */
return [
    'messages' => [
        'stats_loaded' => '썸네일 사용량을 불러왔습니다.',
        'purged' => '썸네일을 비웠습니다.',
        'pregenerate_progressed' => '기존 이미지 썸네일을 만들었습니다.',
    ],

    // 원본 제공자 라벨 (ThumbnailSourceProvider::label)
    'sources' => [
        'core_attachment' => '첨부파일 이미지',
        'layout_attachment' => '레이아웃 편집기 이미지',
    ],

    // 썸네일 전용 디스크 라벨 (공개 자산 디스크는 드라이버 카탈로그 라벨을 쓴다)
    'disks' => [
        'thumbnails' => '서버 비공개 저장소',
    ],

    'attributes' => [
        'location' => '저장 위치',
        'preset' => '썸네일 규격',
        'cursor' => '이어하기 위치',
        'sources' => '원본 종류',
        'presets' => '썸네일 규격',
    ],

    'console' => [
        'prune' => [
            'disabled' => '썸네일 자동 정리가 꺼져 있어 실행하지 않았습니다. (upload.thumbnail_prune_enabled = false)',
            'no_engine' => '쓸 수 있는 이미지 처리 엔진이 없어 규격 변경 여부는 확인하지 않았습니다.',
            'disk_failed' => ':disk 디스크를 읽지 못해 건너뛰었습니다: :error',
            'dry_run' => '정리 대상 썸네일 :total개 (규격이 바뀜 :stale, 없는 규격 :unknown, 원본 없음 :orphan, :days일 넘게 그대로 :expired)',
            'done' => '썸네일 :total개를 지웠습니다. (규격이 바뀜 :stale, 없는 규격 :unknown, 원본 없음 :orphan, :days일 넘게 그대로 :expired, 실패 :failed)',
            'days_unset' => '-',
        ],
        'pregenerate' => [
            'unknown_source' => '알 수 없는 원본 종류입니다: :value (쓸 수 있는 값: :allowed)',
            'unknown_preset' => '알 수 없는 썸네일 규격입니다: :value (쓸 수 있는 값: :allowed)',
            'invalid_limit' => '--limit 값은 1 이상의 정수여야 합니다 (입력: :value)',
            'no_engine' => '쓸 수 있는 이미지 처리 엔진이 없어 썸네일을 만들 수 없습니다.',
            'nothing' => '썸네일을 만들 원본 이미지가 없습니다.',
            'dry_run' => '썸네일을 만들 원본 이미지 :processed개 (전체 :total개)',
            'done' => '원본 이미지 :processed개를 확인해 썸네일 :generated개를 새로 만들었습니다. (전체 :total개)',
        ],
    ],
];
