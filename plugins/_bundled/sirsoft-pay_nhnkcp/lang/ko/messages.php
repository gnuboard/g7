<?php

declare(strict_types=1);

return [
    'refund' => [
        'missing_tid' => '거래번호(tno)가 없어 환불을 진행할 수 없습니다.',
        'default_reason' => '구매자 환불 요청',
        'in_progress' => 'NHN KCP 환불이 이미 처리 중입니다.',
    ],
    'errors' => [
        'wsdl_missing' => 'KCP WSDL 파일이 없습니다: :file',
        'approval_key_error' => 'KCP 승인키 오류 [:code]: :message',
        'soap_error' => 'KCP SOAP 연동 오류: :message',
        'cli_binary_missing' => 'KCP CLI 바이너리 누락: :path',
        'cli_binary_not_executable' => 'KCP CLI 바이너리 실행 권한 부족 — 운영자 조치 필요 (sudo chmod 755 :path)',
    ],
];
