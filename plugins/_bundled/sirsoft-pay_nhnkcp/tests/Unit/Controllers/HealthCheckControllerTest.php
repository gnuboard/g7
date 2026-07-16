<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Controllers;

use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\PayNhnkcp\Controllers\HealthCheckController;
use Tests\TestCase;

require_once dirname(__DIR__, 3) . '/src/Controllers/HealthCheckController.php';

class HealthCheckControllerTest extends TestCase
{
    public function test_health_summary_exposes_public_cancel_server_ip(): void
    {
        Http::fake([
            'api.ipify.org*' => Http::response(['ip' => '203.0.113.45']),
        ]);

        $response = (new HealthCheckController())->check();
        $payload = $response->getData(true);

        $this->assertSame('203.0.113.45', data_get($payload, 'data.summary.cancel_server_ip.address'));
        $this->assertSame('public_outbound', data_get($payload, 'data.summary.cancel_server_ip.source'));
        $this->assertSame('공인 송신 IP', data_get($payload, 'data.summary.cancel_server_ip.source_label'));
    }

    public function test_health_summary_falls_back_to_server_addr_when_public_ip_lookup_fails(): void
    {
        Http::fake([
            'api.ipify.org*' => Http::response([], 500),
        ]);

        $this->app['request']->server->set('SERVER_ADDR', '198.51.100.7');
        $_SERVER['SERVER_ADDR'] = '198.51.100.7';

        $response = (new HealthCheckController())->check();
        $payload = $response->getData(true);

        $this->assertSame('198.51.100.7', data_get($payload, 'data.summary.cancel_server_ip.address'));
        $this->assertSame('server_variable', data_get($payload, 'data.summary.cancel_server_ip.source'));
        $this->assertSame('서버 IP 후보', data_get($payload, 'data.summary.cancel_server_ip.source_label'));
    }
}
