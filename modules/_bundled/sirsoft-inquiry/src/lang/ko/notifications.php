<?php

return [
    'inquiry_received' => [
        'subject' => '[제작의뢰] 새 의뢰가 접수되었습니다',
        'greeting' => '안녕하세요',
        'line' => ':title — :client 님의 새 의뢰가 접수되었습니다.',
        'action' => '의뢰 보기',
    ],
    'quote_issued' => [
        'subject' => '[제작의뢰] 견적이 발행되었습니다',
        'line' => ':title — 견적 #:version (합계 :total원)이 발행되었습니다.',
        'action' => '견적 확인',
    ],
    'quote_revoked' => [
        'subject' => '[제작의뢰] 견적이 철회되었습니다',
        'line' => ':title — 견적 #:version 이 운영자에 의해 철회되었습니다.',
        'action' => '의뢰 보기',
    ],
    'payment_confirmed' => [
        'subject' => '[제작의뢰] 결제가 확인되어 작업이 시작됩니다',
        'line' => ':title — 결제가 확인되어 진행이 시작되었습니다.',
        'action' => '의뢰 보기',
    ],
    'inquiry_completed' => [
        'subject' => '[제작의뢰] 의뢰가 완료되었습니다',
        'line' => ':title — 운영자가 의뢰를 완료 처리했습니다.',
        'action' => '의뢰 보기',
    ],
    'inquiry_canceled' => [
        'subject' => '[제작의뢰] 의뢰가 취소되었습니다',
        'line_by_client' => ':title — 의뢰자가 의뢰를 취소했습니다.',
        'line_by_operator' => ':title — 운영자에 의해 의뢰가 취소되었습니다.',
        'action' => '의뢰 보기',
    ],
    'new_message' => [
        'subject' => '[제작의뢰] 새 메시지가 도착했습니다',
        'line' => ':title — :sender 님이 새 메시지를 남겼습니다.',
        'action' => '의뢰 보기',
    ],
];
