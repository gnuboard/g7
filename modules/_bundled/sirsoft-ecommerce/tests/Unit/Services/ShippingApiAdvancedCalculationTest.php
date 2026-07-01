<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송정책 외부 API 연동 고도화 계산 로직 테스트 (MP12)
 *
 * axis: HTTP 메서드(GET/POST) × 인증(none/bearer/custom) × 응답형식(json/text) × 필드매핑(유/무)
 */
class ShippingApiAdvancedCalculationTest extends ModuleTestCase
{
    private OrderCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder::class);
        $this->service = app(OrderCalculationService::class);
    }

    /**
     * 테스트용 API 정책 + 상품/옵션을 만들고 계산 결과를 반환합니다.
     *
     * @param  array  $apiConfig  api_config 설정
     * @param  array|null  $requestFields  api_request_fields
     * @return int 계산된 배송비
     */
    private function calculateWith(array $apiConfig, ?array $requestFields = null): int
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => 'API 정책', 'en' => 'API Policy'],
            'is_default' => false,
            'is_active' => true,
        ]);
        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::API,
            'base_fee' => 5000,
            'api_endpoint' => 'https://shipping.example.com/calc',
            'api_request_fields' => $requestFields,
            'api_config' => $apiConfig,
            'extra_fee_enabled' => false,
            'is_active' => true,
        ]);

        $countrySetting = $policy->countrySettings()->first();
        $group = [
            'items' => [
                ['product_option_id' => 1, 'quantity' => 2, 'subtotal' => 20000, 'weight' => 3, 'volume' => 1],
            ],
            'total_amount' => 20000,
            'total_quantity' => 2,
        ];

        // protected 메서드 직접 호출 (계산 단위 검증)
        $ref = new \ReflectionMethod($this->service, 'calculateApiShippingFee');
        $ref->setAccessible(true);

        return $ref->invoke($this->service, $countrySetting, $group);
    }

    /**
     * POST + JSON 단일 키 응답 — 기존 동작 유지 (api_config 빈 설정).
     */
    public function test_post_json_single_key_default(): void
    {
        Http::fake(['*' => Http::response(['shipping_fee' => 7500], 200)]);

        $fee = $this->calculateWith([]);

        $this->assertSame(7500, $fee);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST');
    }

    /**
     * JSON 중첩 경로(response_path) 추출.
     */
    public function test_json_nested_response_path(): void
    {
        Http::fake(['*' => Http::response(['data' => ['shipping' => ['fee' => 9900]]], 200)]);

        $fee = $this->calculateWith([
            'response_type' => 'json',
            'response_path' => 'data.shipping.fee',
        ]);

        $this->assertSame(9900, $fee);
    }

    /**
     * 텍스트 응답 — 통화기호/콤마 제거 후 숫자 추출.
     */
    public function test_text_response_strips_currency_and_commas(): void
    {
        Http::fake(['*' => Http::response('₩3,000', 200)]);

        $fee = $this->calculateWith(['response_type' => 'text']);

        $this->assertSame(3000, $fee);
    }

    /**
     * GET 메서드 — query string 으로 전송.
     */
    public function test_get_method_sends_query_string(): void
    {
        Http::fake(['*' => Http::response(['shipping_fee' => 4200], 200)]);

        $fee = $this->calculateWith(['http_method' => 'GET']);

        $this->assertSame(4200, $fee);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), 'policy_id='));
    }

    /**
     * 필드 매핑 — 우리 키를 외부 키 이름으로 리네임해 전송.
     */
    public function test_field_map_renames_request_keys(): void
    {
        Http::fake(['*' => Http::response(['shipping_fee' => 6000], 200)]);

        $fee = $this->calculateWith(
            apiConfig: [
                'http_method' => 'POST',
                'field_map' => ['policy_id' => 'policyId', 'group_total' => 'orderAmount'],
            ],
            requestFields: ['policy_id', 'group_total'],
        );

        $this->assertSame(6000, $fee);
        Http::assertSent(function (Request $r) {
            $body = $r->data();

            // 외부 키로 리네임됨, 원래 키는 없음
            return array_key_exists('policyId', $body)
                && array_key_exists('orderAmount', $body)
                && ! array_key_exists('policy_id', $body)
                && ! array_key_exists('group_total', $body);
        });
    }

    /**
     * Bearer 인증 — Authorization 헤더 부착.
     */
    public function test_bearer_auth_attaches_authorization_header(): void
    {
        Http::fake(['*' => Http::response(['shipping_fee' => 5500], 200)]);

        $fee = $this->calculateWith([
            'auth_type' => 'bearer',
            'auth_token' => 'secret-token-123',
        ]);

        $this->assertSame(5500, $fee);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer secret-token-123'));
    }

    /**
     * 커스텀 헤더 인증 — 지정 헤더명에 토큰 부착.
     */
    public function test_custom_header_auth_attaches_named_header(): void
    {
        Http::fake(['*' => Http::response(['shipping_fee' => 5800], 200)]);

        $fee = $this->calculateWith([
            'auth_type' => 'custom_header',
            'auth_header_name' => 'X-Api-Key',
            'auth_token' => 'key-abc',
        ]);

        $this->assertSame(5800, $fee);
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Api-Key', 'key-abc'));
    }

    /**
     * 텍스트 응답이 숫자가 아니면 base_fee 폴백.
     */
    public function test_non_numeric_text_falls_back_to_base_fee(): void
    {
        Http::fake(['*' => Http::response('error: no rate', 200)]);

        $fee = $this->calculateWith(['response_type' => 'text']);

        $this->assertSame(5000, $fee);
    }
}
