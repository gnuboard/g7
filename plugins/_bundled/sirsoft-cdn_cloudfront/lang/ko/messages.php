<?php

return [
    'disk_label' => 'CloudFront (S3 + CDN)',
    'retry' => [
        'summary' => 'CDN 캐시 무효화 재시도: :retried건 중 성공 :succeeded건, 실패 :failed건',
        'abandoned' => '재시도 상한에 닿아 멈춘 무효화가 :count건 있습니다. 플러그인 설정 화면에서 마지막 오류를 확인하세요.',
    ],
    'test_connection' => [
        'success' => 'CloudFront 배포에 연결했습니다.',
        'missing_credentials' => '배포 ID 와 무효화용 액세스 키, 비밀 키를 모두 저장한 뒤 시험하세요.',
        'request_failed' => 'CloudFront 배포 조회에 실패했습니다: :error',
    ],
];
