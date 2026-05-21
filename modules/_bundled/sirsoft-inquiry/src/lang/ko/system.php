<?php

return [
    'status' => [
        'received' => '접수',
        'quoted' => '견적',
        'in_progress' => '진행',
        'completed' => '완료',
        'canceled' => '취소',
    ],
    'message' => [
        'quote_issued' => '운영자가 견적을 발행했습니다 (회차 #:version, 합계 :total원).',
        'quote_revoked' => '운영자가 견적을 철회했습니다 (회차 #:version).',
        'quote_rejected' => '의뢰자가 견적을 거절했습니다 (회차 #:version).',
        'payment_confirmed' => '결제가 확인되었습니다.',
        'payment_confirmed_offline' => '운영자가 결제를 수동 확인했습니다.',
        'completed' => '의뢰가 완료되었습니다.',
        'canceled_by_client' => '의뢰자가 의뢰를 취소했습니다.',
        'canceled_by_operator' => '운영자가 의뢰를 취소했습니다.',
    ],
];
