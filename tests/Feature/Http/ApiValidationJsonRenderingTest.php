<?php

namespace Tests\Feature\Http;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * `Accept: application/json` 없이 온 API 요청의 검증 실패가 Referer 로 이동하지 않는지 검증합니다.
 *
 * Laravel 기본 검증 실패 처리는 JSON 을 기대하지 않는 요청을 `previous()`(Referer → 세션 → 요청 Host)
 * 로 302 이동시킨다. API 에서는 그 동작이 쓸모없고 Referer 를 그대로 따라가는 이동이 된다.
 */
class ApiValidationJsonRenderingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Accept 헤더 없는 API 검증 실패는 302 가 아니라 422 JSON 이다.
     *
     * @scenario host_header=canonical, sink=api_validation
     *
     * @effects api_validation_failure_is_json_without_accept_header
     *
     * @return void 반환값 없음
     */
    public function test_api_validation_failure_without_accept_is_json(): void
    {
        $response = $this->withHeaders(['Referer' => 'https://evil.example/landing'])
            ->post('/api/auth/login', []);

        $response->assertStatus(422);
        $this->assertNull($response->headers->get('Location'));
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertIsArray($response->json('errors'));
    }

    /**
     * 웹 라우트는 종전 동작(JSON 강제 없음)을 유지한다 — API 경로만 대상이다.
     *
     * @scenario host_header=canonical, sink=api_validation
     *
     * @effects api_validation_failure_is_json_without_accept_header
     *
     * @return void 반환값 없음
     */
    public function test_json_rendering_is_scoped_to_api_paths(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $method = new \ReflectionMethod($handler, 'shouldReturnJson');
        $method->setAccessible(true);

        $web = Request::create('/shop/checkout', 'POST');
        $api = Request::create('/api/auth/login', 'POST');
        $exception = new \Exception('x');

        $this->assertFalse($method->invoke($handler, $web, $exception));
        $this->assertTrue($method->invoke($handler, $api, $exception));
    }
}
