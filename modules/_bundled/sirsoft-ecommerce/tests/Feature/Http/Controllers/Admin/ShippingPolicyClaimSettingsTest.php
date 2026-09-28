<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyClaimAddress;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송정책 클레임 설정 API Feature 테스트 (S6c — 회수지 + 국가별 클레임 배송비)
 *
 * 검증 축:
 * - GET — 회수지 2유형 + 국가별 설정의 클레임 축 노출
 * - PUT — 회수지 upsert / null 삭제, 국가별 클레임 배송비 갱신 (부분 수정 계약)
 * - 상위 스코프 — 이 정책의 국가별 설정에 없는 국가 코드는 422 (다른 정책의 국가 포함)
 * - 본 폼 저장(sync 재생성)이 클레임 배송비 축을 보존하는지 (승계 회귀 차단)
 * - 권한 403
 */
class ShippingPolicyClaimSettingsTest extends ModuleTestCase
{
    protected User $adminUser;

    protected string $apiBase = '/api/modules/sirsoft-ecommerce/admin/shipping-policies';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ShippingTypeSeeder::class);

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.shipping-policies.read',
            'sirsoft-ecommerce.shipping-policies.update',
        ]);
    }

    /**
     * 배송정책 + KR 국가 설정을 생성합니다.
     */
    protected function makePolicy(array $countrySettings = []): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '기본택배', 'en' => 'Standard Delivery'],
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 1,
        ]);

        foreach ($countrySettings === [] ? [$this->krSetting()] : $countrySettings as $cs) {
            $policy->countrySettings()->create($cs);
        }

        return $policy->load('countrySettings');
    }

    /**
     * KR 국가 설정 데이터를 만듭니다.
     */
    protected function krSetting(array $overrides = []): array
    {
        return array_merge([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => 'fixed',
            'base_fee' => 3000,
            'extra_fee_enabled' => false,
            'is_active' => true,
        ], $overrides);
    }

    /**
     * 회수지 페이로드를 만듭니다.
     */
    protected function addressPayload(array $overrides = []): array
    {
        return array_merge([
            'recipient_name' => '반품 담당자',
            'phone' => '02-1234-5678',
            'zipcode' => '06236',
            'address1' => '서울시 강남구 테헤란로 1',
            'address2' => '3층',
            'country_code' => 'KR',
        ], $overrides);
    }

    public function test_get_returns_addresses_and_country_claim_axes(): void
    {
        $policy = $this->makePolicy();

        $response = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}/{$policy->id}/claim-settings")
            ->assertOk();

        // 회수지 미등록 상태 — 유형 키는 항상 존재하고 값은 null
        $response->assertJsonPath('data.addresses.exchange', null)
            ->assertJsonPath('data.addresses.return', null)
            ->assertJsonPath('data.country_settings.0.country_code', 'KR')
            ->assertJsonPath('data.country_settings.0.claim_fee_mode', ClaimShippingFeeModeEnum::FIXED->value)
            ->assertJsonPath('data.country_settings.0.return_fixed_fee', 0)
            ->assertJsonPath('data.country_settings.0.exchange_fixed_fee', 0);
    }

    public function test_put_upserts_addresses_and_updates_country_fees(): void
    {
        $policy = $this->makePolicy();

        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$policy->id}/claim-settings", [
                'addresses' => [
                    'exchange' => $this->addressPayload(['recipient_name' => '교환 담당자']),
                    'return' => $this->addressPayload(),
                ],
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'claim_fee_mode' => ClaimShippingFeeModeEnum::FIXED->value,
                        'return_fixed_fee' => 3000,
                        'exchange_fixed_fee' => 6000,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.addresses.exchange.recipient_name', '교환 담당자')
            ->assertJsonPath('data.addresses.return.recipient_name', '반품 담당자')
            ->assertJsonPath('data.country_settings.0.return_fixed_fee', 3000)
            ->assertJsonPath('data.country_settings.0.exchange_fixed_fee', 6000);

        // 영속 확인 (3단 — DB)
        $this->assertDatabaseHas('ecommerce_shipping_policy_claim_addresses', [
            'shipping_policy_id' => $policy->id,
            'claim_type' => 'exchange',
            'recipient_name' => '교환 담당자',
        ]);
        $this->assertDatabaseHas('ecommerce_shipping_policy_country_settings', [
            'shipping_policy_id' => $policy->id,
            'country_code' => 'KR',
            'exchange_fixed_fee' => 6000,
        ]);

        // 부분 수정 — 반품 회수지만 null 로 보내면 삭제, 교환 회수지는 불변
        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$policy->id}/claim-settings", [
                'addresses' => ['return' => null],
            ])
            ->assertOk()
            ->assertJsonPath('data.addresses.return', null)
            ->assertJsonPath('data.addresses.exchange.recipient_name', '교환 담당자');

        $this->assertSame(1, ShippingPolicyClaimAddress::where('shipping_policy_id', $policy->id)->count());
    }

    public function test_put_rejects_country_code_outside_this_policy(): void
    {
        $policy = $this->makePolicy();
        $otherPolicy = $this->makePolicy([$this->krSetting(['country_code' => 'US', 'currency_code' => 'USD'])]);

        // 이 정책에 없는 국가 (다른 정책에는 실재) — 상위 스코프 Rule::exists 가 422
        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$policy->id}/claim-settings", [
                'country_settings' => [
                    [
                        'country_code' => 'US',
                        'claim_fee_mode' => ClaimShippingFeeModeEnum::FIXED->value,
                        'return_fixed_fee' => 10,
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['country_settings.0.country_code']);

        // 대상 정책의 값은 불변
        $this->assertDatabaseHas('ecommerce_shipping_policy_country_settings', [
            'shipping_policy_id' => $otherPolicy->id,
            'country_code' => 'US',
            'return_fixed_fee' => 0,
        ]);
    }

    public function test_main_policy_update_preserves_claim_fee_settings(): void
    {
        $policy = $this->makePolicy();

        // 클레임 탭에서 저장
        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$policy->id}/claim-settings", [
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'claim_fee_mode' => ClaimShippingFeeModeEnum::ORIGINAL_POLICY->value,
                        'return_fixed_fee' => 2500,
                        'exchange_fixed_fee' => 5000,
                    ],
                ],
            ])
            ->assertOk();

        // 본 폼 저장 — 클레임 키를 보내지 않는다 (기존 폼 형태). 국가별 설정은 삭제 후
        // 재생성되므로 승계가 없으면 클레임 축이 기본값으로 초기화된다.
        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$policy->id}", [
                'name' => ['ko' => '기본택배', 'en' => 'Standard Delivery'],
                'is_active' => true,
                'country_settings' => [$this->krSetting(['base_fee' => 3500])],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_shipping_policy_country_settings', [
            'shipping_policy_id' => $policy->id,
            'country_code' => 'KR',
            'claim_fee_mode' => ClaimShippingFeeModeEnum::ORIGINAL_POLICY->value,
            'return_fixed_fee' => 2500,
            'exchange_fixed_fee' => 5000,
            'base_fee' => 3500,
        ]);
    }

    public function test_main_policy_update_preserves_claim_fee_settings_when_keys_are_explicitly_null(): void
    {
        $policy = $this->makePolicy();

        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$policy->id}/claim-settings", [
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'claim_fee_mode' => ClaimShippingFeeModeEnum::ORIGINAL_POLICY->value,
                        'return_fixed_fee' => 2500,
                        'exchange_fixed_fee' => 5000,
                    ],
                ],
            ])
            ->assertOk();

        // 본 폼이 클레임 키를 **명시 null** 로 싣는 경우 — 폼이 클레임 탭을 그리지 않아도
        // 직렬화 과정에서 빈 필드가 null 로 실려 나갈 수 있다. 세 컬럼 모두 NOT NULL 이라
        // null 은 저장 가능한 값이 아니므로 "값 없음" 으로 보고 기존 값을 승계해야 한다.
        // 키 부재만 승계하면 이 경로에서 저장값이 조용히 사라진다.
        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$policy->id}", [
                'name' => ['ko' => '기본택배', 'en' => 'Standard Delivery'],
                'is_active' => true,
                'country_settings' => [$this->krSetting([
                    'base_fee' => 3500,
                    'claim_fee_mode' => null,
                    'return_fixed_fee' => null,
                    'exchange_fixed_fee' => null,
                ])],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_shipping_policy_country_settings', [
            'shipping_policy_id' => $policy->id,
            'country_code' => 'KR',
            'claim_fee_mode' => ClaimShippingFeeModeEnum::ORIGINAL_POLICY->value,
            'return_fixed_fee' => 2500,
            'exchange_fixed_fee' => 5000,
            'base_fee' => 3500,
        ]);
    }

    public function test_main_policy_update_still_applies_explicit_zero_claim_fees(): void
    {
        $policy = $this->makePolicy();

        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$policy->id}/claim-settings", [
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'claim_fee_mode' => ClaimShippingFeeModeEnum::FIXED->value,
                        'return_fixed_fee' => 2500,
                        'exchange_fixed_fee' => 5000,
                    ],
                ],
            ])
            ->assertOk();

        // 0 은 유효값이다 — 승계 판정이 empty() 로 넓어지면 "무료로 내림" 이 조용히 무시된다.
        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$policy->id}", [
                'name' => ['ko' => '기본택배', 'en' => 'Standard Delivery'],
                'is_active' => true,
                'country_settings' => [$this->krSetting([
                    'return_fixed_fee' => 0,
                    'exchange_fixed_fee' => 0,
                ])],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_shipping_policy_country_settings', [
            'shipping_policy_id' => $policy->id,
            'country_code' => 'KR',
            'claim_fee_mode' => ClaimShippingFeeModeEnum::FIXED->value,
            'return_fixed_fee' => 0,
            'exchange_fixed_fee' => 0,
        ]);
    }

    public function test_claim_settings_requires_shipping_policy_permissions(): void
    {
        $policy = $this->makePolicy();
        $noPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);

        $this->actingAs($noPermission)
            ->getJson("{$this->apiBase}/{$policy->id}/claim-settings")
            ->assertForbidden();

        $readOnly = $this->createAdminUser(['sirsoft-ecommerce.shipping-policies.read']);

        $this->actingAs($readOnly)
            ->putJson("{$this->apiBase}/{$policy->id}/claim-settings", [
                'addresses' => ['return' => $this->addressPayload()],
            ])
            ->assertForbidden();
    }

    public function test_policy_creation_accepts_explicitly_null_claim_fee_keys(): void
    {
        // 본 폼 생성 경로 — 폼이 클레임 탭을 그리지 않아도 직렬화 과정에서 빈 필드가 null 로
        // 실려 나갈 수 있다. 세 컬럼 모두 NOT NULL 이라 null 은 저장 가능한 값이 아니므로
        // "값 없음" 으로 보고 컬럼 기본값이 적용되어야 한다. 수정 경로만 닫으면 생성 경로가
        // 같은 페이로드에서 그대로 500 이 된다 (두 경로 검증 강도 비대칭).
        $creator = $this->createAdminUser([
            'sirsoft-ecommerce.shipping-policies.create',
            'sirsoft-ecommerce.shipping-policies.read',
        ]);

        $this->actingAs($creator)
            ->postJson($this->apiBase, [
                'name' => ['ko' => '신규정책', 'en' => 'New Policy'],
                'is_active' => true,
                'country_settings' => [$this->krSetting([
                    'claim_fee_mode' => null,
                    'return_fixed_fee' => null,
                    'exchange_fixed_fee' => null,
                ])],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('ecommerce_shipping_policy_country_settings', [
            'country_code' => 'KR',
            'claim_fee_mode' => ClaimShippingFeeModeEnum::FIXED->value,
            'return_fixed_fee' => 0,
            'exchange_fixed_fee' => 0,
        ]);
    }

    public function test_main_policy_update_accepts_new_country_with_explicitly_null_claim_fee_keys(): void
    {
        $policy = $this->makePolicy();

        // 신규 국가는 승계할 기존 행이 없다 — 승계 분기가 `$existing` 없을 때 그냥 빠지면
        // 명시 null 이 그대로 INSERT 되어 NOT NULL 위반이 된다.
        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$policy->id}", [
                'name' => ['ko' => '기본택배', 'en' => 'Standard Delivery'],
                'is_active' => true,
                'country_settings' => [
                    $this->krSetting(),
                    $this->krSetting([
                        'country_code' => 'US',
                        'claim_fee_mode' => null,
                        'return_fixed_fee' => null,
                        'exchange_fixed_fee' => null,
                    ]),
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_shipping_policy_country_settings', [
            'shipping_policy_id' => $policy->id,
            'country_code' => 'US',
            'claim_fee_mode' => ClaimShippingFeeModeEnum::FIXED->value,
            'return_fixed_fee' => 0,
            'exchange_fixed_fee' => 0,
        ]);
    }

    public function test_claim_settings_update_keeps_fixed_fees_when_keys_are_omitted(): void
    {
        $policy = $this->makePolicy();

        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$policy->id}/claim-settings", [
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'claim_fee_mode' => ClaimShippingFeeModeEnum::FIXED->value,
                        'return_fixed_fee' => 2500,
                        'exchange_fixed_fee' => 5000,
                    ],
                ],
            ])
            ->assertOk();

        // 부분 수정 계약 — 보낸 축만 반영한다. 고정액 입력란이 비활성인 모드로 바꾸면서
        // 화면이 fee 키를 빼고 보내는 것은 자연스러운 페이로드다. 이때 기존 고정액을 0 으로
        // 덮으면, 같은 파일의 승계 판정(null=값 없음=불변)과 정반대로 동작해 한 리소스의
        // 두 경로가 같은 입력을 다르게 해석한다.
        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$policy->id}/claim-settings", [
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'claim_fee_mode' => ClaimShippingFeeModeEnum::ORIGINAL_POLICY->value,
                    ],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_shipping_policy_country_settings', [
            'shipping_policy_id' => $policy->id,
            'country_code' => 'KR',
            'claim_fee_mode' => ClaimShippingFeeModeEnum::ORIGINAL_POLICY->value,
            'return_fixed_fee' => 2500,
            'exchange_fixed_fee' => 5000,
        ]);
    }

    public function test_claim_settings_update_applies_explicit_zero_fees(): void
    {
        $policy = $this->makePolicy();

        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$policy->id}/claim-settings", [
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'claim_fee_mode' => ClaimShippingFeeModeEnum::FIXED->value,
                        'return_fixed_fee' => 2500,
                        'exchange_fixed_fee' => 5000,
                    ],
                ],
            ])
            ->assertOk();

        // 0 은 유효값이다 — "무료로 내림" 이 불변 판정에 삼켜지면 안 된다.
        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$policy->id}/claim-settings", [
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'claim_fee_mode' => ClaimShippingFeeModeEnum::FIXED->value,
                        'return_fixed_fee' => 0,
                        'exchange_fixed_fee' => 0,
                    ],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_shipping_policy_country_settings', [
            'shipping_policy_id' => $policy->id,
            'country_code' => 'KR',
            'return_fixed_fee' => 0,
            'exchange_fixed_fee' => 0,
        ]);
    }

    public function test_module_declares_the_eight_claim_permissions_routes_require(): void
    {
        // 권한 8종은 S1 이 module.php 에 선언했고 S6c 의 라우트 3종·관리자 메뉴가 그중
        // claims.read 를 참조한다. 선언이 사라지면 미들웨어가 존재하지 않는 권한을 요구해
        // 전 운영자가 403 이 되는데, 어느 테스트도 그 선언을 붙들고 있지 않았다.
        $module = new Module;

        $declared = [];
        foreach ($module->getPermissions()['categories'] ?? [] as $category) {
            foreach ($category['permissions'] ?? [] as $permission) {
                $declared[] = $category['identifier'].'.'.$permission['action'];
            }
        }

        foreach ([
            'claims.read',
            'claims.update-status',
            'claims.update-amounts',
            'claims.execute-refund',
            'user-claims.request',
            'user-claims.read',
            'user-claims.withdraw',
            'user-claims.pay',
        ] as $expected) {
            $this->assertContains($expected, $declared, $expected.' 권한 선언이 사라졌습니다');
        }
    }
}
