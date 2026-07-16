<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException;
use Plugins\Sirsoft\PayNhnkcp\Services\KcpSoapService;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class NhnKcpApiServiceTest extends PluginTestCase
{
    private const TEST_SITE_CD = 'T0000';

    private const TEST_SITE_KEY = 'TEST_SITE_KEY_0000';

    private function makeService(array $settingsOverrides = []): NhnKcpApiService
    {
        $defaults = [
            'is_test_mode' => true,
            'test_site_cd' => self::TEST_SITE_CD,
            'test_site_key' => self::TEST_SITE_KEY,
            'live_site_cd' => '',
            'live_site_key' => '',
        ];

        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')
            ->willReturn(array_merge($defaults, $settingsOverrides));

        return new NhnKcpApiService($settingsMock);
    }

    /**
     * KCP CLI 실행을 외부 의존 없이 검증하기 위한 임시 바이너리 디렉터리 생성.
     *
     * @return array{service: NhnKcpApiService, dir: string, log: string}
     */
    private function makeServiceWithStubbedCli(string $response, array $settingsOverrides = []): array
    {
        // CLI 스텁은 `#!/bin/sh` 스크립트라 Windows exec() 가 실행하지 못한다
        // (.exe 로 이름만 바꿔도 "not compatible with the version of Windows" 로 거부됨).
        // Windows 분기(executeCliWindows)는 별도 검증 대상이므로 여기서는 건너뛴다.
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Linux CLI 스텁 기반 테스트 — Windows 분기 별도 검증.');
        }

        $service = $this->makeService($settingsOverrides);
        $dir = sys_get_temp_dir().'/nhnkcp_cli_'.uniqid('', true);
        $this->assertTrue(mkdir($dir), '임시 CLI 디렉터리 생성 실패');

        $logPath = $dir.'/argv.log';
        $responsePath = $dir.'/response.txt';
        file_put_contents($responsePath, $response);

        $script = "#!/bin/sh\n"
            ."printf '%s\\n' \"\$@\" > ".escapeshellarg($logPath)."\n"
            .'cat '.escapeshellarg($responsePath)."\n";

        foreach (['pp_cli', 'pp_cli_x64'] as $binName) {
            $binPath = $dir.'/'.$binName;
            file_put_contents($binPath, $script);
            chmod($binPath, 0755);
        }

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('binDir');
        $property->setAccessible(true);
        $property->setValue($service, $dir);

        return [
            'service' => $service,
            'dir' => $dir,
            'log' => $logPath,
        ];
    }

    private function removeStubbedCliDirectory(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($dir);
    }

    private function capturedCliArgs(string $logPath): string
    {
        $lines = file($logPath, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines, 'CLI 인자 로그를 읽을 수 있어야 함');

        return $lines[1] ?? '';
    }

    public function test_get_site_cd_returns_test_site_cd_in_test_mode(): void
    {
        $service = $this->makeService();

        $this->assertEquals(self::TEST_SITE_CD, $service->getSiteCd());
    }

    public function test_kcp_soap_service_falls_back_to_test_site_cd_when_escrow_test_site_cd_is_empty(): void
    {
        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')->willReturn([
            'is_test_mode' => true,
            'test_site_cd' => self::TEST_SITE_CD,
            'escrow_test_site_cd' => '',
        ]);

        $service = new KcpSoapService($settingsMock);

        $this->assertSame(self::TEST_SITE_CD, $service->getEscrowSiteCd());
    }

    public function test_get_site_cd_returns_live_site_cd_in_live_mode(): void
    {
        $service = $this->makeService([
            'is_test_mode' => false,
            'live_site_cd' => 'SR123456',
            'live_site_key' => 'live_site_key_value',
        ]);

        $this->assertEquals('SR123456', $service->getSiteCd());
    }

    public function test_use_stored_credentials_restores_payment_time_mode_and_site_cd(): void
    {
        $service = $this->makeService([
            'is_test_mode' => true,
            'test_site_cd' => 'T0000',
            'live_site_cd' => 'SR999999',
            'live_site_key' => 'live_key_value',
        ]);

        $service->useStoredCredentials(false, 'SR123456');

        $this->assertEquals('SR123456', $service->getSiteCd());
        $this->assertStringContainsString('pay.kcp.co.kr', $service->getJsUrl());
        $this->assertStringNotContainsString('testpay', $service->getJsUrl());
    }

    public function test_get_js_url_returns_test_url_in_test_mode(): void
    {
        $service = $this->makeService();

        $this->assertStringContainsString('testpay.kcp.co.kr', $service->getJsUrl());
    }

    public function test_get_js_url_returns_live_url_in_live_mode(): void
    {
        $service = $this->makeService([
            'is_test_mode' => false,
            'live_site_cd' => 'SR123456',
            'live_site_key' => 'live_key',
        ]);

        $jsUrl = $service->getJsUrl();
        $this->assertStringContainsString('pay.kcp.co.kr', $jsUrl);
        $this->assertStringNotContainsString('testpay', $jsUrl);
    }

    public function test_get_transaction_calls_correct_url_with_auth_headers(): void
    {
        $service = $this->makeService();

        $tno = 'KCP_TNO_TEST_001';
        $ordrIdxx = 'ORD-TEST-12345';

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'tno' => $tno,
                'ordr_idxx' => $ordrIdxx,
                'app_no' => 'APP12345',
            ], 200),
        ]);

        $result = $service->getTransaction($tno, $ordrIdxx);

        $this->assertEquals('0000', $result['res_cd']);
        $this->assertEquals($tno, $result['tno']);

        Http::assertSent(function ($request) use ($tno, $ordrIdxx) {
            return str_contains($request->url(), 'stgapi.kcp.co.kr')
                && str_contains($request->url(), urlencode($tno))
                && $request->hasHeader('Authorization')
                && $request->hasHeader('X-Kcp-Site-Code')
                && $request->hasHeader('X-Kcp-Timestamp')
                && $request->hasHeader('X-Kcp-Signature')
                && $request['ordr_idxx'] === $ordrIdxx;
        });
    }

    public function test_get_transaction_uses_basic_auth_with_site_credentials(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response(['res_cd' => '0000'], 200),
        ]);

        $service->getTransaction('TNO_001', 'ORD-001');

        $expectedAuthValue = 'Basic '.base64_encode(self::TEST_SITE_CD.':'.self::TEST_SITE_KEY);

        Http::assertSent(function ($request) use ($expectedAuthValue) {
            return $request->header('Authorization')[0] === $expectedAuthValue;
        });
    }

    public function test_get_transaction_throws_on_http_error(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response(null, 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $service->getTransaction('TNO_ERR', 'ORD-ERR');
    }

    public function test_cancel_payment_calls_cli_with_full_cancel_params(): void
    {
        $stub = $this->makeServiceWithStubbedCli(
            'res_cd=0000'.chr(31).'res_msg=취소완료'.chr(31).'tno=KCP_TNO_CANCEL_001'
        );
        /** @var NhnKcpApiService $service */
        $service = $stub['service'];

        $tno = 'KCP_TNO_CANCEL_001';
        $ordrIdxx = 'ORD-TEST-CANCEL';
        $cancelAmt = 50000;
        $cancelMsg = '고객 요청';

        try {
            $result = $service->cancelPayment($tno, $ordrIdxx, $cancelAmt, $cancelMsg, false);

            $this->assertEquals('0000', $result['res_cd']);

            $args = $this->capturedCliArgs($stub['log']);
            $this->assertStringContainsString('tx_cd=00200000', $args);
            $this->assertStringContainsString('ordr_idxx='.$ordrIdxx, $args);
            $this->assertStringContainsString('modx_data=mod_data=tno='.$tno, $args);
            $this->assertStringContainsString('mod_type=STSC', $args);
            $this->assertStringContainsString('mod_desc='.$cancelMsg, $args);
            $this->assertStringNotContainsString('mod_mny=', $args);
            $this->assertStringNotContainsString('rem_mny=', $args);
        } finally {
            $this->removeStubbedCliDirectory($stub['dir']);
        }
    }

    public function test_cancel_payment_calls_cli_with_partial_cancel_params(): void
    {
        $stub = $this->makeServiceWithStubbedCli('res_cd=0000'.chr(31).'res_msg=부분취소완료');
        /** @var NhnKcpApiService $service */
        $service = $stub['service'];

        try {
            $service->cancelPayment('TNO_PART', 'ORD-PART', 10000, '부분취소', true, 50000);

            $args = $this->capturedCliArgs($stub['log']);
            $this->assertStringContainsString('mod_type=RN07', $args);
            $this->assertStringContainsString('mod_mny=10000', $args);
            $this->assertStringContainsString('rem_mny=50000', $args);
        } finally {
            $this->removeStubbedCliDirectory($stub['dir']);
        }
    }

    public function test_register_escrow_delivery_uses_test_site_cd_when_escrow_test_site_cd_is_empty(): void
    {
        $stub = $this->makeServiceWithStubbedCli(
            'res_cd=0000'.chr(31).'res_msg=배송등록완료',
            ['escrow_test_site_cd' => '']
        );
        /** @var NhnKcpApiService $service */
        $service = $stub['service'];

        try {
            $service->registerEscrowDelivery('TNO_ESCROW', 'ORD-ESCROW', '1234567890', '04');

            $args = $this->capturedCliArgs($stub['log']);
            $this->assertStringContainsString('site_cd='.self::TEST_SITE_CD, $args);
        } finally {
            $this->removeStubbedCliDirectory($stub['dir']);
        }
    }

    public function test_cancel_payment_throws_on_non_0000_res_cd(): void
    {
        $stub = $this->makeServiceWithStubbedCli('res_cd=9999'.chr(31).'res_msg=취소 실패');
        /** @var NhnKcpApiService $service */
        $service = $stub['service'];

        try {
            $this->expectException(NhnKcpApiException::class);
            $this->expectExceptionMessage('취소 실패');

            $service->cancelPayment('TNO_FAIL', 'ORD-FAIL', 50000, '고객 요청');
        } finally {
            $this->removeStubbedCliDirectory($stub['dir']);
        }
    }

    public function test_cancel_payment_throws_on_cli_fallback_error(): void
    {
        $stub = $this->makeServiceWithStubbedCli('');
        /** @var NhnKcpApiService $service */
        $service = $stub['service'];

        try {
            $this->expectException(NhnKcpApiException::class);
            $this->expectExceptionMessage('연동 모듈 호출 오류');

            $service->cancelPayment('TNO_CLI_ERR', 'ORD-CLI-ERR', 50000, '오류');
        } finally {
            $this->removeStubbedCliDirectory($stub['dir']);
        }
    }

    public function test_cli_debug_logs_do_not_include_raw_response_or_site_key(): void
    {
        $source = file_get_contents((new \ReflectionClass(NhnKcpApiService::class))->getFileName());
        $this->assertIsString($source);

        $this->assertStringNotContainsString("'res_data' =>", $source);
        $this->assertStringNotContainsString("'output_lines' =>", $source);
        $this->assertStringNotContainsString("['command' => \$command]", $source);
        $this->assertStringContainsString("'res_cd' => \$result['res_cd'] ?? null", $source);
    }

    public function test_cancel_payment_rejects_unsafe_cancel_message_before_cli_exec(): void
    {
        $stub = $this->makeServiceWithStubbedCli('res_cd=0000');
        /** @var NhnKcpApiService $service */
        $service = $stub['service'];

        try {
            $this->expectException(NhnKcpApiException::class);
            $this->expectExceptionMessageMatches('/control character/');

            $service->cancelPayment('TNO_UNSAFE', 'ORD-UNSAFE', 50000, "고객 요청\nINJECT");
        } finally {
            $this->removeStubbedCliDirectory($stub['dir']);
        }
    }

    public function test_get_transaction_uses_live_api_url_in_live_mode(): void
    {
        $service = $this->makeService([
            'is_test_mode' => false,
            'live_site_cd' => 'SR123456',
            'live_site_key' => 'live_key_value',
        ]);

        Http::fake([
            'api.kcp.co.kr/*' => Http::response(['res_cd' => '0000'], 200),
        ]);

        $service->getTransaction('TNO_LIVE', 'ORD-LIVE');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.kcp.co.kr')
                && ! str_contains($request->url(), 'stgapi');
        });
    }

    /**
     * 결제 직전 CLI 바이너리 실행 권한 자가 복구 (9502 회귀 차단).
     *
     * 시나리오: plugin:update 후 활성 디렉토리 pp_cli/pp_cli_x64 가 _bundled 권한 (0664) 으로
     * 회귀해 실행 권한이 사라진 상태에서 결제 시도. ensureCliExecutable() 가 결제 직전
     * 자동으로 chmod 0755 + stat 캐시 무효화를 수행하여 9502 발생을 차단해야 한다.
     *
     * 단위 테스트는 ensureCliExecutable() 만 reflection 으로 직접 호출하고
     * (실제 KCP CLI 실행은 외부 의존이라 단위 범위 밖) 권한 변경 + 멱등성 + 실패 분기
     * 3가지 동작을 검증한다.
     */
    public function test_ensure_cli_executable_promotes_0664_to_0755(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->markTestSkipped('파일 모드 비트가 Windows 와 호환되지 않음.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'kcp_cli_test_');
        $this->assertNotFalse($tmpFile);
        file_put_contents($tmpFile, '#!/bin/sh'.PHP_EOL.'exit 0'.PHP_EOL);
        chmod($tmpFile, 0664);
        $this->assertFalse(is_executable($tmpFile), 'precondition: 0664 는 실행 권한 없음');

        try {
            $service = $this->makeService();
            $method = (new \ReflectionClass($service))->getMethod('ensureCliExecutable');
            $method->setAccessible(true);
            $method->invoke($service, $tmpFile);

            clearstatcache(true, $tmpFile);
            $this->assertTrue(is_executable($tmpFile), 'chmod 0755 로 자가 복구되어야 함');
            $mode = substr(sprintf('%o', fileperms($tmpFile)), -4);
            $this->assertSame('0755', $mode, "권한이 0755 로 정확히 설정되어야 함 (현재: {$mode})");
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_ensure_cli_executable_is_idempotent_when_already_executable(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->markTestSkipped('파일 모드 비트가 Windows 와 호환되지 않음.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'kcp_cli_test_');
        $this->assertNotFalse($tmpFile);
        file_put_contents($tmpFile, '#!/bin/sh'.PHP_EOL.'exit 0'.PHP_EOL);
        chmod($tmpFile, 0755);

        try {
            $service = $this->makeService();
            $method = (new \ReflectionClass($service))->getMethod('ensureCliExecutable');
            $method->setAccessible(true);
            // 호출이 예외 없이 통과해야 하며, 권한도 그대로여야 함.
            $method->invoke($service, $tmpFile);

            clearstatcache(true, $tmpFile);
            $mode = substr(sprintf('%o', fileperms($tmpFile)), -4);
            $this->assertSame('0755', $mode, '이미 실행 가능한 파일의 권한은 변경되지 않아야 함');
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_ensure_cli_executable_throws_when_binary_missing(): void
    {
        $service = $this->makeService();
        $method = (new \ReflectionClass($service))->getMethod('ensureCliExecutable');
        $method->setAccessible(true);

        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/CLI 바이너리/');

        $method->invoke($service, '/tmp/nonexistent_kcp_cli_'.uniqid());
    }

    /**
     * KCP CLI 가 디스크에 결제 로그를 남기지 않도록 (PCI DSS 평문 카드번호 저장 방지) 보장.
     *
     * 기존 동작: log_path 가 빈 문자열 → CLI 가 기본 경로(bin/log/)에 verbose 로그 작성.
     *   TX_ENDED 라인에 card_no, app_no, tno 등 결제 메타 평문 누적.
     * 수정 동작: gnuboard5 검증 패턴 — 의도적으로 존재하지 않는 경로(/home100/kcp)를
     *   전달하면 CLI 가 디렉토리 생성 실패 → 로그 작성 단계 silent skip.
     *   추가로 log_level=1 (오류만) 로 낮춰 미래에 누가 log_path 를 살려도 카드번호 같은
     *   verbose 페이로드가 자동으로 차단되도록 이중 안전장치.
     */
    public function test_log_path_is_intentionally_nonexistent_to_disable_disk_logging(): void
    {
        $reflection = new \ReflectionClass(NhnKcpApiService::class);
        $this->assertTrue(
            $reflection->hasConstant('LOG_DISABLED_PATH'),
            'LOG_DISABLED_PATH 상수가 정의되어 있어야 함 (디스크 로깅 차단 의도 명시)',
        );
        $this->assertEquals(
            '/home100/kcp',
            $reflection->getConstant('LOG_DISABLED_PATH'),
            'gnuboard5 의 검증된 패턴(/home100/kcp) 과 일치해야 함',
        );
        $this->assertFalse(
            is_dir($reflection->getConstant('LOG_DISABLED_PATH')),
            '경로가 실제 시스템에 존재하지 않아야 (로그 작성 silent skip 보장)',
        );
    }

    public function test_log_level_is_minimal_to_prevent_pii_leak(): void
    {
        $reflection = new \ReflectionClass(NhnKcpApiService::class);
        $logLevel = $reflection->getConstant('LOG_LEVEL');

        $this->assertSame(
            '1',
            $logLevel,
            'LOG_LEVEL=1 (오류만) 이어야 함 — verbose(3) 는 응답 페이로드에 카드번호 평문 포함하므로 PCI DSS 위반 위험',
        );
    }

    public function test_cli_args_include_disabled_log_path_constant(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->markTestSkipped('Linux CLI 경로 테스트 — Windows 분기 별도 검증.');
        }

        $reflection = new \ReflectionClass(NhnKcpApiService::class);
        $source = file_get_contents($reflection->getFileName());

        // executeCliLinux 와 executeCliWindows 모두 LOG_DISABLED_PATH 참조해야 함.
        $logPathReferences = substr_count($source, "'log_path=' . self::LOG_DISABLED_PATH");
        $this->assertGreaterThanOrEqual(
            2,
            $logPathReferences,
            '두 OS 분기(executeCliLinux + executeCliWindows) 모두 LOG_DISABLED_PATH 를 사용해야 함',
        );

        // 기존 빈 값 패턴(log_path=,)이 남아있지 않아야 함.
        $this->assertStringNotContainsString(
            "'log_path=,'",
            $source,
            '빈 log_path 패턴이 남아있으면 CLI 가 기본 경로(bin/log/)에 로그 작성 회귀',
        );
    }
}
