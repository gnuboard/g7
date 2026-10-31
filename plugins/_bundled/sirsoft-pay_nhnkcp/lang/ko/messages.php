<?php

declare(strict_types=1);

return [
    'claim' => [
        'invalid_callback' => '클레임 결제 승인에 필요한 콜백 파라미터가 없습니다.',
        'window_failed' => '결제창 인증이 완료되지 않았습니다.',
        'currency_not_supported' => 'NHN KCP 클레임 결제는 KRW 결제만 지원합니다.',
        'deposit_not_supported' => '클레임 추가결제는 입금 대기 방식(가상계좌)을 지원하지 않습니다.',
        'compensation_cancel_reason' => '클레임 결제 승인 거절에 따른 자동 취소',
        'amount_mismatch' => '결제 금액이 청구액과 맞지 않거나 청구액을 확인할 수 없어 결제를 확정하지 않았습니다(승인된 금액은 취소됩니다). 신청서에서 결제를 다시 시작해 주세요.',
    ],
    'refund' => [
        'missing_tid' => '거래번호(tno)가 없어 환불을 진행할 수 없습니다.',
        'default_reason' => '구매자 환불 요청',
        'in_progress' => 'NHN KCP 환불이 이미 처리 중입니다.',
        'vbank_requires_bank_transfer' => '입금이 끝난 가상계좌 결제는 NHN KCP 로 자동 환불할 수 없습니다. 환불 수단을 계좌 환불로 바꿔 고객 계좌로 직접 송금해 주세요.',
    ],
    'escrow' => [
        'invoice_required' => '운송장번호를 입력해주세요.',
        'courier_required' => '택배사를 선택해주세요.',
    ],
    'errors' => [
        'payment_failed' => '결제 처리에 실패했습니다. 잠시 후 다시 시도해 주세요.',
        'vbank_issuance_incomplete' => '가상계좌 발급 정보가 없어 발급을 확인하지 못했습니다. 결제를 다시 진행해 주세요.',
        'wsdl_missing' => 'KCP WSDL 파일이 없습니다: :file',
        'approval_key_error' => 'KCP 승인키 오류 [:code]: :message',
        'soap_error' => 'KCP SOAP 연동 오류: :message',
        'cli_binary_missing' => 'KCP CLI 바이너리 누락: :path',
        'cli_binary_not_executable' => 'KCP CLI 바이너리 실행 권한 부족 — 운영자 조치 필요 (sudo chmod 755 :path)',
    ],
    // 설정 화면 「시스템 점검」 카드의 항목 줄(항목 이름·결과·조치 안내)과 결제서버 IP 출처 — 서버가 만들어 보내는 문구
    'health' => [
        'checked' => '시스템 점검을 마쳤습니다.',
        'source' => [
            'public_outbound' => '공인 송신 IP',
            'server_variable' => '서버 IP 후보',
            'unavailable' => '확인 필요',
        ],
        'exec' => [
            'label' => 'PHP exec() 함수',
            'ok' => 'CLI 호출 가능',
            'disabled' => 'php.ini 의 disable_functions 에서 exec 가 차단되어 있습니다.',
            'disabled_remediation' => "php.ini 에서 disable_functions 목록에서 'exec' 를 제거한 뒤 PHP-FPM(또는 Apache) 을 재시작하세요.\n예: sudo sed -i 's/disable_functions = .*exec.*/disable_functions =/' /etc/php/8.x/fpm/php.ini && sudo systemctl restart php8.x-fpm",
        ],
        'bin_dir' => [
            'label' => 'CLI 바이너리 디렉토리',
            'ok' => '디렉토리 존재',
            'missing' => 'bin 디렉토리가 없습니다: :path',
            'missing_remediation' => '플러그인을 재설치하거나, KCP 에서 제공한 CLI 바이너리를 bin/ 에 복사하세요.',
        ],
        'cli' => [
            'label' => 'CLI 바이너리 (:arch) — :filename',
            'missing' => '파일이 없습니다: :path',
            'missing_remediation' => '현재 OS(:arch)에 해당하는 KCP CLI 바이너리를 bin/ 에 복사하세요. (테스트 환경은 플러그인 기본 제공)',
            'windows' => 'Windows 바이너리 — 실행 권한 검사 생략',
            'executable' => '실행 권한 OK (:mode)',
            'fixed' => '실행 권한 자동 복구됨: :before → 0755',
            'not_executable' => '실행 권한 없음 (:before) — 자동 복구 실패',
            'not_executable_no_permission' => '실행 권한 없음 (:before) — 플러그인 설정 수정 권한이 없어 자동 복구를 하지 않았습니다',
            'not_executable_remediation' => "이 파일에 실행 권한을 부여하세요:\n  chmod 755 :path\n파일 소유자가 아니면 앞에 sudo 를 붙이세요.",
        ],
        'pub_key' => [
            'label' => 'KCP 공개키 (pub.key)',
            'ok' => '파일 존재',
            'missing' => 'pub.key 파일이 없습니다: :path',
            'missing_remediation' => 'KCP 에서 제공받은 pub.key 파일을 bin/ 에 복사하세요. (테스트 환경은 플러그인 기본 제공)',
        ],
        'soap' => [
            'label' => 'PHP SOAP 확장',
            'ok' => 'SoapClient 사용 가능',
            'missing' => '모바일 결제 (SmartPhone Pay) 가 동작하지 않습니다.',
            'missing_remediation' => "PHP SOAP 확장을 설치하고 PHP-FPM 을 재시작하세요:\nUbuntu/Debian: sudo apt install php8.x-soap && sudo systemctl restart php8.x-fpm\nRHEL/CentOS:   sudo yum install php-soap && sudo systemctl restart php-fpm\n확인: php -m | grep soap",
        ],
        'wsdl' => [
            'label' => 'WSDL 파일',
            'ok' => '테스트/라이브 WSDL 모두 존재',
            'missing' => '누락: :files',
            'missing_remediation' => 'KCP 에서 제공받은 WSDL 파일을 bin/ 에 복사하세요. (테스트/라이브 별로 두 개)',
        ],
    ],
    'settings' => [
        'redirect_url_format' => '결제 후 이동 주소는 {shopBase}, / 또는 http(s):// 로 시작해야 하며 공백을 넣을 수 없습니다. 다른 사이트로 보내는 //주소 와 javascript: 는 쓸 수 없습니다.',
    ],
];
