<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 상품폼 활성 배송정책 노출 테스트 (A11)
 *
 * 상품폼이 배송정책 목록 endpoint(/admin/shipping-policies)에 is_active=true 필터를 적용해
 * 활성 정책만 노출하면서, 폼 렌더에 필요한 풀 필드(id/name/country_settings/fee_summary/is_default)를
 * 그대로 받는지 검증.
 */
class ShippingPolicyActiveListTest extends ModuleTestCase
{
    protected User $adminUser;

    protected string $apiBase = '/api/modules/sirsoft-ecommerce/admin/shipping-policies';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShippingTypeSeeder::class);
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.shipping-policies.read',
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.products.update',
        ]);
    }

    private function makePolicy(string $name, bool $isActive): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => $name, 'en' => $name],
            'is_active' => $isActive,
            'is_default' => false,
            'sort_order' => 1,
        ]);
        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => 'fixed',
            'base_fee' => 3000,
        ]);

        return $policy->load('countrySettings');
    }

    #[Test]
    public function test_is_active_filter_excludes_inactive_policies(): void
    {
        $this->makePolicy('활성정책', true);
        $this->makePolicy('비활성정책', false);

        $response = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase.'?is_active=true');

        $response->assertOk();
        $data = $response->json('data.data');

        $this->assertCount(1, $data);
        $this->assertTrue($data[0]['is_active']);
    }

    #[Test]
    public function test_active_filtered_response_includes_form_fields(): void
    {
        $this->makePolicy('활성정책', true);

        $response = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase.'?is_active=true');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'country_settings',
                            'fee_summary',
                            'is_default',
                            'is_active',
                        ],
                    ],
                ],
            ]);
    }

    #[Test]
    public function test_without_filter_includes_inactive(): void
    {
        // 필터 없으면 비활성 포함 (기존 목록 화면 동작 비파괴)
        $this->makePolicy('활성정책', true);
        $this->makePolicy('비활성정책2', false);

        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase);

        $response->assertOk();
        $this->assertCount(2, $response->json('data.data'));
    }

    #[Test]
    public function test_product_form_includes_assigned_inactive_policy_object(): void
    {
        // Given: 비활성 배송정책을 부여받은 상품
        $inactive = $this->makePolicy('비활성정책', false);
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => $inactive->id,
        ]);

        // When: 상품 수정폼 데이터 조회
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products/'.$product->id.'/form');

        // Then: 비활성이어도 현재 부여된 정책 객체가 union 표시용으로 포함됨
        $response->assertOk();
        $policy = $response->json('data.shipping_policy');

        $this->assertNotNull($policy);
        $this->assertSame($inactive->id, $policy['id']);
        $this->assertFalse($policy['is_active']);
        $this->assertArrayHasKey('country_settings', $policy);
        $this->assertArrayHasKey('fee_summary', $policy);
    }
}
