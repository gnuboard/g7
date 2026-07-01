<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송정책 계산 API 테스트 호출 엔드포인트 테스트 (MP12)
 *
 * 권한 경계 + 토큰 비노출 + 요청 미리보기/응답 반환.
 */
class ShippingPolicyTestApiCallTest extends ModuleTestCase
{
    private string $url = '/api/modules/sirsoft-ecommerce/admin/shipping-policies/test-api-call';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShippingTypeSeeder::class);
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.shipping-policies.update',
        ]);
    }

    /**
     * 정상 테스트 호출 — 요청 미리보기 + 응답 + 추출 배송비 반환.
     */
    public function test_successful_test_call_returns_preview_and_extracted_fee(): void
    {
        Http::fake(['*' => Http::response(['shipping_fee' => 4800], 200)]);

        $response = $this->actingAs($this->adminUser)->postJson($this->url, [
            'endpoint' => 'https://shipping.example.com/calc',
            'request_fields' => ['policy_id', 'group_total'],
            'config' => [
                'http_method' => 'POST',
                'response_type' => 'json',
                'response_path' => 'shipping_fee',
            ],
            'sample' => ['group_total' => 20000, 'total_quantity' => 2],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.ok', true);
        $response->assertJsonPath('data.extracted_fee', 4800);
        $response->assertJsonPath('data.request.method', 'POST');
        // 요청 본문 미리보기가 항상 포함된다
        $this->assertNotEmpty($response->json('data.request.body'));
        $response->assertJsonPath('data.response.status', 200);
    }

    /**
     * HTTP 오류 응답(4xx/5xx)도 요청 미리보기 + 응답 상태·본문을 반환한다 (진단).
     */
    public function test_http_error_response_still_returns_request_and_response(): void
    {
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);

        $response = $this->actingAs($this->adminUser)->postJson($this->url, [
            'endpoint' => 'https://shipping.example.com/calc',
            'config' => ['http_method' => 'POST'],
        ]);

        $response->assertOk();
        // HTTP 응답은 도달했으므로 request + response 가 모두 채워진다
        $response->assertJsonPath('data.response.status', 500);
        $this->assertStringContainsString('Internal Server Error', $response->json('data.response.body'));
        $this->assertNotEmpty($response->json('data.request.body'));
        // 배송비 추출은 실패 → null
        $this->assertNull($response->json('data.extracted_fee'));
    }

    /**
     * 연결 실패·타임아웃 시에도 요청 미리보기 + 에러 메시지를 반환한다 (진단).
     */
    public function test_connection_failure_still_returns_request_preview_and_error(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused')]);

        $response = $this->actingAs($this->adminUser)->postJson($this->url, [
            'endpoint' => 'https://unreachable.example.com/calc',
            'config' => ['http_method' => 'GET'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.ok', false);
        $response->assertJsonPath('data.reason', 'request_failed');
        $response->assertJsonPath('data.request.method', 'GET');
        // 요청 본문 + 에러 메시지가 진단용으로 제공된다
        $this->assertNotEmpty($response->json('data.request.body'));
        $this->assertNotEmpty($response->json('data.error'));
    }

    /**
     * 인증 토큰은 응답에 평문 노출되지 않는다.
     */
    public function test_auth_token_is_not_exposed_in_response(): void
    {
        Http::fake(['*' => Http::response(['shipping_fee' => 3000], 200)]);

        $secret = 'super-secret-token-xyz';
        $response = $this->actingAs($this->adminUser)->postJson($this->url, [
            'endpoint' => 'https://shipping.example.com/calc',
            'config' => [
                'auth_type' => 'bearer',
                'auth_token' => $secret,
            ],
        ]);

        $response->assertOk();
        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    /**
     * update 권한 없는 계정은 차단.
     */
    public function test_without_update_permission_is_forbidden(): void
    {
        $readOnly = $this->createAdminUser(['sirsoft-ecommerce.shipping-policies.read']);

        $response = $this->actingAs($readOnly)->postJson($this->url, [
            'endpoint' => 'https://shipping.example.com/calc',
        ]);

        $response->assertForbidden();
    }

    /**
     * endpoint 누락 시 422.
     */
    public function test_missing_endpoint_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser)->postJson($this->url, [
            'config' => ['http_method' => 'POST'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('endpoint');
    }
}
