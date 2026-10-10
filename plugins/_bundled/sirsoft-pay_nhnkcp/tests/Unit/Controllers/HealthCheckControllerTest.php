<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Controllers;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\PayNhnkcp\Controllers\HealthCheckController;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

require_once dirname(__DIR__, 3).'/src/Controllers/HealthCheckController.php';

class HealthCheckControllerTest extends PluginTestCase
{
    /**
     * 점검 문구가 플러그인 번역(`sirsoft-pay_nhnkcp::messages.health.*`)을 거치므로 _bundled 의 lang 을 등록합니다.
     * 기반 클래스(PluginTestCase)도 같은 등록을 하지만, 이 시험이 기대는 전제라 여기서도 분명히 둔다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace('sirsoft-pay_nhnkcp', dirname(__DIR__, 3).'/lang');
    }

    /**
     * 공인 송신 IP 조회가 되면 결제서버 IP 로 그 주소와 출처 「공인 송신 IP」 를 알려 줍니다.
     *
     * @scenario case=c01
     *
     * @effects health_check_summary_localized
     *
     * @return void 반환값 없음
     */
    public function test_health_summary_exposes_public_cancel_server_ip(): void
    {
        Http::fake([
            'api.ipify.org*' => Http::response(['ip' => '203.0.113.45']),
        ]);

        $response = (new HealthCheckController)->check();
        $payload = $response->getData(true);

        $this->assertSame('203.0.113.45', data_get($payload, 'data.summary.cancel_server_ip.address'));
        $this->assertSame('public_outbound', data_get($payload, 'data.summary.cancel_server_ip.source'));
        $this->assertSame('공인 송신 IP', data_get($payload, 'data.summary.cancel_server_ip.source_label'));
    }

    /**
     * 공인 IP 조회가 실패하면 서버 변수의 주소를 「서버 IP 후보」 로 알려 줍니다.
     *
     * @scenario case=c02
     *
     * @effects health_check_summary_localized
     *
     * @return void 반환값 없음
     */
    public function test_health_summary_falls_back_to_server_addr_when_public_ip_lookup_fails(): void
    {
        Http::fake([
            'api.ipify.org*' => Http::response([], 500),
        ]);

        $this->app['request']->server->set('SERVER_ADDR', '198.51.100.7');
        $_SERVER['SERVER_ADDR'] = '198.51.100.7';

        $response = (new HealthCheckController)->check();
        $payload = $response->getData(true);

        $this->assertSame('198.51.100.7', data_get($payload, 'data.summary.cancel_server_ip.address'));
        $this->assertSame('server_variable', data_get($payload, 'data.summary.cancel_server_ip.source'));
        $this->assertSame('서버 IP 후보', data_get($payload, 'data.summary.cancel_server_ip.source_label'));
    }

    /**
     * 영어로 보는 관리자에게는 시스템 점검 항목(이름·결과·조치 안내)과 결제서버 IP 출처도 영어로 나간다.
     *
     * 카드 제목·건수·결제 경로 줄은 화면 번역 키로 이미 바뀌지만, 항목 줄은 서버가 문구를 만들어 보내므로
     * 서버 번역을 거치지 않으면 영어 화면에 한국어 줄이 섞인다(2026-10-07 실측: 「PHP exec() 함수」·「파일 존재」).
     *
     * @return void 반환값 없음
     *
     * @scenario case=c03
     *
     * @effects health_check_summary_localized
     */
    public function test_health_check_rows_follow_the_request_locale(): void
    {
        Http::fake([
            'api.ipify.org*' => Http::response(['ip' => '203.0.113.45']),
        ]);
        app()->setLocale('en');

        $payload = (new HealthCheckController)->check()->getData(true);

        $checks = data_get($payload, 'data.checks', []);
        $this->assertNotEmpty($checks);
        foreach ($checks as $check) {
            foreach (['label', 'detail', 'remediation'] as $field) {
                $text = (string) ($check[$field] ?? '');
                $this->assertDoesNotMatchRegularExpression('/[\x{AC00}-\x{D7A3}]/u', $text, "{$check['id']}.{$field}: {$text}");
            }
        }
        $this->assertSame('Public outbound IP', data_get($payload, 'data.summary.cancel_server_ip.source_label'));
    }

    /**
     * 점검 컨트롤러는 다른 관리자 컨트롤러와 같은 기반(AdminBaseController)과 응답 헬퍼(ResponseHelper)를 쓴다.
     *
     * 응답은 `success`·`message`·`data` 를 갖고, 화면이 읽는 `data.summary`·`data.checks` 형태는 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=c04
     *
     * @effects health_check_summary_localized
     */
    public function test_health_check_uses_admin_base_and_response_helper_envelope(): void
    {
        Http::fake([
            'api.ipify.org*' => Http::response(['ip' => '203.0.113.45']),
        ]);

        $controller = new HealthCheckController;
        $this->assertInstanceOf(AdminBaseController::class, $controller);

        $payload = $controller->check()->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertSame('시스템 점검을 마쳤습니다.', $payload['message'] ?? null);
        $this->assertIsArray(data_get($payload, 'data.summary'));
        $this->assertIsArray(data_get($payload, 'data.checks'));
    }

    /**
     * 플러그인 설정 수정 권한이 없는 관리자에게는 실행 권한 자동 복구를 하지 않고, 「복구 실패」가 아니라
     * 「권한이 없어 자동 복구를 하지 않았다」고 알린다. 파일 권한은 그대로 둔다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=c05
     *
     * @effects health_check_cli_recovery_skipped_without_permission
     */
    public function test_cli_binary_without_permission_reports_recovery_skipped_not_failed(): void
    {
        $dir = sys_get_temp_dir().'/nhnkcp_health_'.uniqid();
        mkdir($dir);
        $file = $dir.'/test_cli';
        file_put_contents($file, '');
        @chmod($file, 0644);
        $beforeMode = substr(sprintf('%o', fileperms($file)), -4);

        try {
            $controller = new HealthCheckController;
            $binDir = new \ReflectionProperty(HealthCheckController::class, 'binDir');
            $binDir->setAccessible(true);
            $binDir->setValue($controller, $dir);
            $check = new \ReflectionMethod(HealthCheckController::class, 'checkCliBinary');
            $check->setAccessible(true);

            // 로그인하지 않았다 → core.plugins.update 판정이 거짓
            $row = $check->invoke($controller, 'test_cli', 'linux');

            $this->assertSame('error', $row['status']);
            $this->assertSame(
                __('sirsoft-pay_nhnkcp::messages.health.cli.not_executable_no_permission', ['before' => $beforeMode]),
                $row['detail']
            );
            $this->assertStringNotContainsString('자동 복구 실패', $row['detail']);
            $this->assertNotEmpty($row['remediation'] ?? '');
            $this->assertSame($beforeMode, substr(sprintf('%o', fileperms($file)), -4), '권한이 없으면 파일 권한을 바꾸지 않는다');
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }
}
