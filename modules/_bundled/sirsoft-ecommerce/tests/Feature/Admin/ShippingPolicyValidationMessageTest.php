<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 배송정책 검증 메시지 친화화 테스트 (A12 + A13)
 *
 * - 구간/추가배송비 음수·필수 누락 시 키 원문이 아닌 한글/영문 라벨 표시 (A12)
 * - 계산 API 정책 시 api_endpoint 필수화 + 잘못된 URL i18n (A13 W1/W2)
 * - api_request_fields 후보 5종 Rule::in 검증 (A13 W3)
 */
class ShippingPolicyValidationMessageTest extends ModuleTestCase
{
    protected User $adminUser;

    protected string $apiBase = '/api/modules/sirsoft-ecommerce/admin/shipping-policies';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShippingTypeSeeder::class);
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.shipping-policies.create',
        ]);
    }

    private function krSetting(array $overrides = []): array
    {
        return array_merge([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => 'fixed',
            'base_fee' => 3000,
            'free_threshold' => null,
            'ranges' => null,
            'api_endpoint' => null,
            'api_request_fields' => null,
            'api_response_fee_field' => null,
            'extra_fee_enabled' => false,
            'extra_fee_settings' => null,
            'extra_fee_multiply' => false,
            'is_active' => true,
        ], $overrides);
    }

    private function payload(array $countrySettings): array
    {
        return [
            'name' => ['ko' => '테스트정책', 'en' => 'Test Policy'],
            'is_active' => true,
            'is_default' => false,
            'country_settings' => $countrySettings,
        ];
    }

    private function store(array $payload, string $locale = 'ko')
    {
        // SetLocale 미들웨어는 인증 사용자의 language 를 최우선 적용하므로 해당 값을 세팅
        $this->adminUser->forceFill(['language' => $locale])->save();

        return $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);
    }

    /**
     * 검증 메시지 값(error key 가 아닌 message 텍스트)에 raw 키가 노출되지 않았는지 확인.
     *
     * Laravel 의 errors 객체 key 는 정상적으로 필드 경로(country_settings.0.fee)지만,
     * 사용자에게 보이는 message 값에는 :attribute placeholder 미치환 키나 lang 네임스페이스가
     * 들어가면 안 된다.
     */
    private function assertNoRawKeys($response): void
    {
        foreach ($this->allMessages($response) as $message) {
            $this->assertStringNotContainsString('country_settings.', $message, "raw key in: {$message}");
            $this->assertStringNotContainsString('sirsoft-ecommerce::validation', $message, "lang namespace in: {$message}");
            // humanize 폴백(예: "country settings.0.ranges.tiers.1.fee") 차단
            $this->assertStringNotContainsString('country settings', $message, "humanized raw key in: {$message}");
        }
    }

    /**
     * errors 객체의 모든 메시지 값을 평탄화하여 반환합니다.
     *
     * (Laravel error key 는 dot 을 포함하므로 json('errors.x.y') 점표기 접근이 부정확 →
     * 전체 errors 를 받아 메시지 값만 평탄화)
     *
     * @return array<int, string>
     */
    private function allMessages($response): array
    {
        $errors = $response->json('errors') ?? [];
        $flat = [];
        foreach ($errors as $messages) {
            foreach ((array) $messages as $message) {
                $flat[] = $message;
            }
        }

        return $flat;
    }

    // ───────── A12: 구간 배송비 음수 ─────────

    #[Test]
    public function test_negative_tier_fee_returns_friendly_message(): void
    {
        $cs = $this->krSetting([
            'charge_policy' => 'range_amount',
            'ranges' => [
                'type' => 'price',
                'unit_value' => 1000,
                'tiers' => [
                    ['min' => 0, 'max' => null, 'fee' => -500],
                ],
            ],
        ]);

        $response = $this->store($this->payload([$cs]));

        $response->assertStatus(422);
        $this->assertNoRawKeys($response);
        $this->assertStringContainsString('0 이상', implode(' ', $this->allMessages($response)));
    }

    #[Test]
    public function test_negative_unit_value_returns_friendly_message(): void
    {
        $cs = $this->krSetting([
            'charge_policy' => 'range_amount',
            'ranges' => [
                'type' => 'price',
                'unit_value' => -10,
                'tiers' => [
                    ['min' => 0, 'max' => null, 'fee' => 1000],
                ],
            ],
        ]);

        $response = $this->store($this->payload([$cs]));

        $response->assertStatus(422);
        $this->assertNoRawKeys($response);
    }

    // ───────── A12: en locale ─────────

    #[Test]
    public function test_negative_tier_fee_en_locale(): void
    {
        $cs = $this->krSetting([
            'charge_policy' => 'range_amount',
            'ranges' => [
                'type' => 'price',
                'unit_value' => 1000,
                'tiers' => [
                    ['min' => 0, 'max' => null, 'fee' => -500],
                ],
            ],
        ]);

        $response = $this->store($this->payload([$cs]), 'en');

        $response->assertStatus(422);
        $this->assertNoRawKeys($response);
        $this->assertStringContainsString('0 or greater', $response->getContent());
    }

    // ───────── A13 W1: api_endpoint 필수 ─────────

    #[Test]
    public function test_api_policy_requires_endpoint(): void
    {
        $cs = $this->krSetting([
            'charge_policy' => 'api',
            'api_endpoint' => '',
        ]);

        $response = $this->store($this->payload([$cs]));

        $response->assertStatus(422);
        $this->assertNoRawKeys($response);
        $this->assertStringContainsString('API 주소', implode(' ', $this->allMessages($response)));
    }

    #[Test]
    public function test_non_api_policy_allows_null_endpoint(): void
    {
        // 비-API 정책은 endpoint null 허용 (회귀 가드)
        $cs = $this->krSetting(['charge_policy' => 'fixed', 'api_endpoint' => null]);

        $response = $this->store($this->payload([$cs]));

        $response->assertSuccessful();
    }

    #[Test]
    public function test_api_policy_with_valid_endpoint_passes(): void
    {
        $cs = $this->krSetting([
            'charge_policy' => 'api',
            'api_endpoint' => 'https://example.com/calc',
            'api_request_fields' => ['items', 'group_total'],
        ]);

        $response = $this->store($this->payload([$cs]));

        $response->assertSuccessful();
    }

    // ───────── A13 W2: 잘못된 URL ─────────

    #[Test]
    public function test_invalid_url_returns_friendly_message(): void
    {
        $cs = $this->krSetting([
            'charge_policy' => 'api',
            'api_endpoint' => 'not-a-url',
        ]);

        $response = $this->store($this->payload([$cs]));

        $response->assertStatus(422);
        $this->assertNoRawKeys($response);
        $this->assertStringContainsString('URL', $response->getContent());
    }

    // ───────── A13 W3: api_request_fields Rule::in ─────────

    #[Test]
    public function test_unsupported_request_field_rejected(): void
    {
        $cs = $this->krSetting([
            'charge_policy' => 'api',
            'api_endpoint' => 'https://example.com/calc',
            'api_request_fields' => ['weight'], // 후보 외
        ]);

        $response = $this->store($this->payload([$cs]));

        $response->assertStatus(422);
        $this->assertNoRawKeys($response);
    }

    #[Test]
    public function test_supported_request_fields_accepted(): void
    {
        $cs = $this->krSetting([
            'charge_policy' => 'api',
            'api_endpoint' => 'https://example.com/calc',
            'api_request_fields' => ['policy_id', 'country_code', 'items', 'group_total', 'total_quantity'],
        ]);

        $response = $this->store($this->payload([$cs]));

        $response->assertSuccessful();
    }

    // ───────── MP12: 계산 API 고급 설정(api_config) ─────────

    #[Test]
    public function test_api_config_full_settings_accepted(): void
    {
        $cs = $this->krSetting([
            'charge_policy' => 'api',
            'api_endpoint' => 'https://example.com/calc',
            'api_request_fields' => ['policy_id', 'group_total'],
            'api_config' => [
                'http_method' => 'GET',
                'auth_type' => 'bearer',
                'auth_token' => 'tok-123',
                'response_type' => 'json',
                'response_path' => 'data.fee',
                'field_map' => ['policy_id' => 'policyId'],
            ],
        ]);

        $response = $this->store($this->payload([$cs]));

        $response->assertSuccessful();
    }

    #[Test]
    public function test_custom_header_auth_requires_header_name(): void
    {
        $cs = $this->krSetting([
            'charge_policy' => 'api',
            'api_endpoint' => 'https://example.com/calc',
            'api_config' => [
                'auth_type' => 'custom_header',
                'auth_token' => 'key-abc',
                // auth_header_name 누락
            ],
        ]);

        $response = $this->store($this->payload([$cs]));

        $response->assertStatus(422);
        $this->assertNoRawKeys($response);
    }

    #[Test]
    public function test_field_map_rejects_injection_characters(): void
    {
        $cs = $this->krSetting([
            'charge_policy' => 'api',
            'api_endpoint' => 'https://example.com/calc',
            'api_config' => [
                'field_map' => ['policy_id' => "bad\r\nX-Inject: 1"],
            ],
        ]);

        $response = $this->store($this->payload([$cs]));

        $response->assertStatus(422);
        $this->assertNoRawKeys($response);
    }

    #[Test]
    public function test_invalid_http_method_rejected(): void
    {
        $cs = $this->krSetting([
            'charge_policy' => 'api',
            'api_endpoint' => 'https://example.com/calc',
            'api_config' => ['http_method' => 'DELETE'],
        ]);

        $response = $this->store($this->payload([$cs]));

        $response->assertStatus(422);
    }
}
