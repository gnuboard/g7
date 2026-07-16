<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 마일리지 설정 저장 (EcommerceSettingsController) 테스트 (§18.2-H)
 *
 * 마일리지 설정은 기존 환경설정 인프라(PUT /admin/settings + _tab: mileage)를 그대로 탄다.
 */
class EcommerceSettingsMileageTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/settings';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);
    }

    /**
     * 마일리지 탭 저장 성공 + 저장값 반영.
     */
    public function test_save_mileage_settings_success(): void
    {
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'mileage',
            'mileage' => [
                'enabled' => true,
                'default_earn_rate' => 5,
                'earn_trigger' => 'delivered',
                'earn_delay_days' => 3,
                'currency_rules' => [
                    ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 1000, 'use_unit' => 10, 'max_use_type' => 'fixed', 'max_use_percent' => 30, 'max_use_value' => 50000],
                ],
                'expiry_enabled' => true,
                'expiry_days' => 180,
                'expiry_notification_enabled' => true,
                'expiry_notification_days_before' => 7,
            ],
        ]);

        $response->assertOk();

        // 저장값이 실제 반영되었는지 확인 (캐시 초기화 후 재조회)
        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $this->assertTrue((bool) $settings->getSetting('mileage.enabled'));
        $this->assertSame('delivered', $settings->getSetting('mileage.earn_trigger'));
        $this->assertEquals(5, $settings->getSetting('mileage.default_earn_rate'));
    }

    /**
     * 잘못된 적립 시점 → 422.
     */
    public function test_save_mileage_invalid_earn_trigger_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'mileage',
            'mileage' => ['enabled' => true, 'earn_trigger' => 'invalid_trigger'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('mileage.earn_trigger');
    }

    /**
     * 음수 적립률 → 422.
     */
    public function test_save_mileage_negative_rate_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'mileage',
            'mileage' => ['enabled' => true, 'default_earn_rate' => -10],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('mileage.default_earn_rate');
    }

    /**
     * 통화 코드 형식 검증 — ISO 4217 3자리 영문 대문자가 아니면 422.
     * (한글/소문자/3자리 아님 모두 거부)
     */
    public function test_save_mileage_invalid_currency_code_format_returns_422(): void
    {
        foreach (['ㅇㅇㅇ', 'usd', 'US', 'USDD', 'U1D'] as $badCode) {
            $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
                '_tab' => 'mileage',
                'mileage' => [
                    'enabled' => true,
                    'currency_rules' => [
                        ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 30, 'max_use_value' => 0],
                        ['currency_code' => $badCode, 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 30, 'max_use_value' => 0],
                    ],
                ],
            ]);

            $response->assertStatus(422, "통화 코드 '{$badCode}' 는 거부되어야 함");
            $response->assertJsonValidationErrors('mileage.currency_rules.1.currency_code');
        }
    }

    /**
     * 마일리지 통화 ↔ 언어/통화 등록 통화 정합성 — 등록되지 않은 통화 코드면 422.
     */
    public function test_save_mileage_currency_not_in_registered_currencies_returns_422(): void
    {
        // 같은 요청에 language_currency 를 함께 보내 등록 통화를 명시(KRW, USD)하고,
        // 미등록 통화(GBP)에 마일리지 규칙을 만들면 거부.
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'mileage',
            'language_currency' => [
                'default_currency' => 'KRW',
                'currencies' => [
                    ['code' => 'KRW', 'name' => ['ko' => '원'], 'is_default' => true],
                    ['code' => 'USD', 'name' => ['en' => 'Dollar']],
                ],
            ],
            'mileage' => [
                'enabled' => true,
                'currency_rules' => [
                    ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 30, 'max_use_value' => 0],
                    ['currency_code' => 'GBP', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 30, 'max_use_value' => 0],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('mileage.currency_rules.1.currency_code');
    }

    /**
     * 기본 통화 행 보장 — 첫 통화 규칙이 기본 통화(KRW)가 아니면 422.
     */
    public function test_save_mileage_first_rule_must_be_default_currency_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'mileage',
            'mileage' => [
                'enabled' => true,
                'currency_rules' => [
                    // 첫 행이 기본 통화(KRW)가 아님 → 거부
                    ['currency_code' => 'USD', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 30, 'max_use_value' => 0],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('mileage.currency_rules.0.currency_code');
    }

    /**
     * max_use_value 상한 — 비합리적 거액(10억 초과)은 422.
     */
    public function test_save_mileage_max_use_value_over_limit_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'mileage',
            'mileage' => [
                'enabled' => true,
                'currency_rules' => [
                    ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'fixed', 'max_use_percent' => 30, 'max_use_value' => 9999999999],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('mileage.currency_rules.0.max_use_value');
    }

    /**
     * 정상 통화 규칙(기본 통화 KRW + 형식 정상)은 저장 성공.
     */
    public function test_save_mileage_valid_currency_rules_success(): void
    {
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'mileage',
            'mileage' => [
                'enabled' => true,
                'currency_rules' => [
                    ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 1000, 'use_unit' => 10, 'max_use_type' => 'fixed', 'max_use_percent' => 30, 'max_use_value' => 50000],
                ],
            ],
        ]);

        $response->assertOk();
    }

    /**
     * 마일리지 활성화(enabled=true) 상태에서 기본 적립률 0% 는 422.
     * (비활성 상태에서는 0% 허용)
     */
    public function test_save_mileage_enabled_with_zero_earn_rate_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'mileage',
            'mileage' => [
                'enabled' => true,
                'default_earn_rate' => 0,
                'currency_rules' => [
                    ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 30, 'max_use_value' => 0],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('mileage.default_earn_rate');
    }

    /**
     * 마일리지 비활성화(enabled=false) 상태에서는 적립률 0% 허용.
     */
    public function test_save_mileage_disabled_with_zero_earn_rate_succeeds(): void
    {
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'mileage',
            'mileage' => [
                'enabled' => false,
                'default_earn_rate' => 0,
                'currency_rules' => [
                    ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 30, 'max_use_value' => 0],
                ],
            ],
        ]);

        $response->assertOk();
    }

    /**
     * 검증 오류 메시지가 raw 필드 경로가 아닌 사람이 읽을 수 있는 문구로 반환된다.
     * (messages()/attributes() 미등록으로 인한 "필드 형식이 올바르지 않습니다" + raw 경로 회귀 방지)
     */
    public function test_currency_code_error_message_is_human_readable(): void
    {
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'mileage',
            'mileage' => [
                'enabled' => true,
                'default_earn_rate' => 1,
                'currency_rules' => [
                    ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 30, 'max_use_value' => 0],
                    ['currency_code' => 'ㅇㅇㅇ', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 30, 'max_use_value' => 0],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $message = $response->json('errors.mileage\.currency_rules\.1\.currency_code.0')
            ?? collect($response->json('errors'))->flatten()->first();

        // raw 필드 경로(점 표기)가 메시지 본문에 그대로 노출되지 않아야 한다
        $this->assertStringNotContainsString('mileage.currency_rules.1.currency_code', (string) $message);
        // ISO 4217 형식 안내 문구가 포함되어야 한다
        $this->assertStringContainsString('ISO 4217', (string) $message);
    }

    /**
     * 설정 조회 응답에 마일리지 소멸 알림 활성 채널이 포함된다 (§22-5a).
     */
    public function test_index_includes_mileage_notification_channels(): void
    {
        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'mileage' => ['notification_channels'],
            ],
        ]);
        $this->assertIsArray($response->json('data.mileage.notification_channels'));
    }

    /**
     * 설정 조회 응답에 배송 계산 API 요청 참고 필드 후보가 포함된다 (A13 W3).
     *
     * 후보는 프론트 하드코딩이 아니라 백엔드 SSoT enum 에서 번역된 {value, label} 로 내려간다.
     * 라벨에 미해석 키 원문(`shipping_api_request_field`)이 노출되면 회귀.
     */
    public function test_index_includes_shipping_api_request_fields(): void
    {
        // ko 로케일 — 사용자 language 컬럼으로 응답 로케일 결정
        $this->adminUser->forceFill(['language' => 'ko'])->save();

        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'shipping' => [
                    'api_request_fields' => [
                        ['value', 'label'],
                    ],
                ],
            ],
        ]);

        $fields = $response->json('data.shipping.api_request_fields');
        $this->assertCount(5, $fields);

        $values = array_column($fields, 'value');
        $this->assertSame(
            ['policy_id', 'country_code', 'items', 'group_total', 'total_quantity'],
            $values,
        );

        // 라벨은 번역되어 내려가야 한다 — 키 원문 노출 금지
        foreach ($fields as $field) {
            $this->assertNotEmpty($field['label']);
            $this->assertStringNotContainsString('shipping_api_request_field', $field['label']);
        }

        // ko 로케일 기준 대표 라벨 확인
        $byValue = collect($fields)->keyBy('value');
        $this->assertSame('배송정책 ID', $byValue['policy_id']['label']);
        $this->assertSame('총 수량', $byValue['total_quantity']['label']);
    }

    /**
     * 배송 계산 API 요청 참고 필드 후보 라벨이 로케일별로 번역되어 내려간다 (A13 W3).
     */
    public function test_index_shipping_api_request_fields_labels_localized_to_en(): void
    {
        $this->adminUser->forceFill(['language' => 'en'])->save();

        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase);

        $response->assertOk();
        $byValue = collect($response->json('data.shipping.api_request_fields'))->keyBy('value');
        $this->assertSame('Shipping policy ID', $byValue['policy_id']['label']);
        $this->assertSame('Total quantity', $byValue['total_quantity']['label']);
    }

    /**
     * 설정 조회 응답에 계산 API 고급 설정 옵션(메서드/인증/응답형식)이 포함된다 (MP12).
     */
    public function test_index_includes_shipping_api_advanced_options(): void
    {
        $this->adminUser->forceFill(['language' => 'ko'])->save();

        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'shipping' => [
                    'api_http_methods' => [['value', 'label']],
                    'api_auth_types' => [['value', 'label']],
                    'api_response_types' => [['value', 'label']],
                ],
            ],
        ]);

        $methods = array_column($response->json('data.shipping.api_http_methods'), 'value');
        $this->assertSame(['GET', 'POST'], $methods);

        $authTypes = array_column($response->json('data.shipping.api_auth_types'), 'value');
        $this->assertSame(['none', 'bearer', 'custom_header'], $authTypes);

        $responseTypes = array_column($response->json('data.shipping.api_response_types'), 'value');
        $this->assertSame(['json', 'text'], $responseTypes);

        // 라벨은 번역되어 내려가야 한다 — 키 원문 노출 금지
        foreach ($response->json('data.shipping.api_auth_types') as $opt) {
            $this->assertStringNotContainsString('shipping_api_auth_type', $opt['label']);
        }
    }

    /**
     * settings.update 권한 없는 계정은 차단.
     */
    public function test_save_without_update_permission_blocked(): void
    {
        $readOnly = $this->createAdminUser(['sirsoft-ecommerce.settings.read']);

        $response = $this->actingAs($readOnly)->putJson($this->apiBase, [
            '_tab' => 'mileage',
            'mileage' => ['enabled' => true],
        ]);

        $response->assertForbidden();
    }
}
