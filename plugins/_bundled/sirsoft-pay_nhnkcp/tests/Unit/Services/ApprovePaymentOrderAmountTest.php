<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * KCP CLI 승인 요청이 서버가 산정한 청구액을 ordr_mony 로 싣는지 검증한다.
 *
 * 수정 전 승인 요청(tx_cd=00100000)에는 금액이 없어, KCP 가 결제창에서 인증된 금액을 가맹점
 * 청구액과 대조할 수 없었다. KCP 연동 라이브러리의 주문 데이터 형식(`ordr_data` 묶음 안의
 * `ordr_mony=<금액>`)으로 싣는다.
 */
class ApprovePaymentOrderAmountTest extends PluginTestCase
{
    /**
     * 테스트 모드 설정으로 서비스를 만듭니다.
     *
     * @return NhnKcpApiService API 서비스
     */
    private function makeService(): NhnKcpApiService
    {
        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')->willReturn([
            'is_test_mode' => true,
            'test_site_cd' => 'T0000',
            'test_site_key' => 'TEST_SITE_KEY_0000',
            'live_site_cd' => '',
            'live_site_key' => '',
        ]);

        return new NhnKcpApiService($settingsMock);
    }

    /**
     * 인수를 기록하고 고정 응답을 내는 Linux CLI 스텁을 서비스에 연결합니다.
     *
     * @param  NhnKcpApiService  $service  API 서비스
     * @param  string  $response  CLI 가 출력할 응답
     * @return array{dir: string, log: string} 스텁 디렉터리와 인수 로그 경로
     */
    private function stubCli(NhnKcpApiService $service, string $response): array
    {
        // CLI 스텁은 `#!/bin/sh` 스크립트라 Windows exec() 가 실행하지 못한다.
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Linux CLI 스텁 기반 테스트 — Windows 분기는 소스 계약으로 검증.');
        }

        $dir = sys_get_temp_dir().'/nhnkcp_cli_amt_'.uniqid('', true);
        $this->assertTrue(mkdir($dir), '임시 CLI 디렉터리 생성 실패');

        $logPath = $dir.'/argv.log';
        $responsePath = $dir.'/response.txt';
        file_put_contents($responsePath, $response);

        $script = "#!/bin/sh\n"
            ."printf '%s\\n' \"\$@\" > ".escapeshellarg($logPath)."\n"
            .'cat '.escapeshellarg($responsePath)."\n";

        foreach (['pp_cli', 'pp_cli_x64'] as $binName) {
            file_put_contents($dir.'/'.$binName, $script);
            chmod($dir.'/'.$binName, 0755);
        }

        $property = (new \ReflectionClass($service))->getProperty('binDir');
        $property->setAccessible(true);
        $property->setValue($service, $dir);

        return ['dir' => $dir, 'log' => $logPath];
    }

    /**
     * 스텁 디렉터리를 지웁니다.
     *
     * @param  string  $dir  스텁 디렉터리
     * @return void 반환값 없음
     */
    private function removeStub(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * 승인 CLI 인수에 청구액이 ordr_data 묶음의 ordr_mony 로 실린다. (Linux 분기)
     *
     * @return void 반환값 없음
     *
     * @effects kcp_approval_request_carries_the_order_amount_as_ordr_mony
     */
    public function test_approve_payment_passes_the_order_amount_as_ordr_mony_to_the_cli(): void
    {
        $service = $this->makeService();
        $stub = $this->stubCli($service, 'res_cd=0000'.chr(31).'tno=KCP-ARG-1'.chr(31).'amount=50000');

        try {
            $result = $service->approvePayment('ENCDATA', 'ENCINFO', 'ORD-ARG-1', '127.0.0.1', 50000);

            $this->assertSame('0000', $result['res_cd']);
            $this->assertSame('50000', $result['amount']);

            $lines = file($stub['log'], FILE_IGNORE_NEW_LINES);
            $this->assertIsArray($lines, 'CLI 인자 로그를 읽을 수 있어야 함');
            $args = $lines[1] ?? '';

            $this->assertStringContainsString('tx_cd=00100000', $args);
            $this->assertStringContainsString('ordr_data=ordr_data=ordr_mony=50000'.chr(31), $args);
            $this->assertStringContainsString('modx_data=,', $args, '승인 요청에는 mod_data 가 없다');
        } finally {
            $this->removeStub($stub['dir']);
        }
    }

    /**
     * 청구액이 1 미만이면 CLI 를 부르지 않고 거절한다. (KCP 서버 측 금액 대조가 빠진 승인 차단)
     *
     * @return void 반환값 없음
     *
     * @effects kcp_approval_request_carries_the_order_amount_as_ordr_mony
     */
    public function test_approve_payment_refuses_a_non_positive_order_amount_before_the_cli(): void
    {
        $service = $this->makeService();

        // 존재하지 않는 디렉터리로 돌려 두어, 검증을 지나 CLI 단계로 가면 다른 예외로 드러나게 한다.
        $property = (new \ReflectionClass($service))->getProperty('binDir');
        $property->setAccessible(true);
        $property->setValue($service, sys_get_temp_dir().'/nhnkcp_cli_absent_'.uniqid('', true));

        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/ordr_mony/');

        $service->approvePayment('ENCDATA', 'ENCINFO', 'ORD-ARG-0', '127.0.0.1', 0);
    }

    /**
     * Windows 분기도 plan_data 에 ordr_data 묶음을 이어 붙인다. (Windows 바이너리는 테스트에서 실행할 수 없어 소스 계약으로 잰다)
     *
     * @return void 반환값 없음
     *
     * @effects kcp_approval_request_carries_the_order_amount_as_ordr_mony
     */
    public function test_both_cli_branches_forward_the_order_data(): void
    {
        $method = new \ReflectionMethod(NhnKcpApiService::class, 'executeCliWindows');
        $lines = file((string) $method->getFileName());
        $this->assertIsArray($lines);
        $windowsBody = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertStringContainsString("'ordr_data='.\$ordrData", $windowsBody);

        $linux = new \ReflectionMethod(NhnKcpApiService::class, 'executeCliLinux');
        $linuxBody = implode('', array_slice($lines, $linux->getStartLine() - 1, $linux->getEndLine() - $linux->getStartLine() + 1));

        $this->assertStringContainsString("'ordr_data='.\$ordrArg", $linuxBody);
        $this->assertStringContainsString("assertSafeCliValue(\$ordrArg, 'ordr_data', allowKcpFieldSeparator: true)", $linuxBody);
    }
}
