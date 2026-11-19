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

    /**
     * 배송유형 시드와 배송정책 조회·상품 조회/수정 권한을 가진 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 한국 택배 국가 설정 하나를 가진 배송정책을 만듭니다.
     *
     * @param  string  $name  정책 이름(ko·en 같은 값)
     * @param  bool  $isActive  사용 여부
     * @return ShippingPolicy 국가 설정을 실은 배송정책
     */
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

    /**
     * 상품 폼이 쓰는 `is_active=true` 필터가 비활성 정책을 빼는지 검증합니다(신규 상품에는 사용 중인 정책만).
     *
     * @scenario product_form=new, filter=applied
     *
     * @effects active_list_excludes_inactive_for_new
     *
     * @return void 반환값 없음
     */
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

    /**
     * 사용 중 필터를 건 응답이 상품 폼이 그리는 필드(국가 설정·배송비 요약·기본 여부)를 그대로 싣는지 검증합니다.
     *
     * @scenario endpoint=active_list,opt_in=default
     *
     * @effects list_keeps_fields_the_screen_draws
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_active_filtered_response_includes_form_fields(): void
    {
        $this->makePolicy('활성정책', true);

        // 기본 응답도 목록 표시용 국가별 설정·요약을 싣는다(관리 목록 화면이 국가 칩과
        // 배송비 요약 열을 그린다). `with_country_settings=true` 는 표시용을 넘어 **전체 컬럼**
        // 이 필요한 호출자를 위한 하위호환 경로이며, 여기서는 그 경로의 응답 형태를 고정한다.
        $response = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase.'?is_active=true&with_country_settings=true');

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

    /**
     * 필터가 없으면 비활성 정책도 함께 오는지 검증합니다 — 배송정책 관리 목록 화면 동작을 깨지 않는다.
     *
     * @scenario policy_count=many, filter=none
     *
     * @effects admin_list_without_filter_keeps_inactive
     *
     * @return void 반환값 없음
     */
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

    /**
     * 비활성 정책이 붙은 상품의 수정 폼 응답에 그 정책 객체가 실려 선택지에 함께 그려지는지 검증합니다.
     *
     * @scenario product_form=edit_inactive
     *
     * @effects inactive_assigned_policy_union_shown
     *
     * @return void 반환값 없음
     */
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

    /**
     * 상품 등록·수정 화면의 배송정책 선택지는 `per_page=all` 로 활성 정책 전부를 받는다 — 100건을 넘어도 잘리지 않는다.
     *
     * 종전에는 목록 API 가 쪽당 최대 100건만 받아 화면이 `per_page=100` 한 쪽만 그렸고, 101번째 이후 활성 정책을 고를 수 없었다.
     *
     * @scenario endpoint=admin_list,opt_in=default
     *
     * @effects admin_list_per_page_all_returns_every_row
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_per_page_all_returns_every_active_policy(): void
    {
        for ($i = 1; $i <= 101; $i++) {
            $this->makePolicy('활성정책 '.$i, true);
        }
        $this->makePolicy('비활성정책', false);

        $response = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase.'?is_active=true&per_page=all');

        $response->assertOk();
        $this->assertCount(101, $response->json('data.data'));
        $this->assertArrayHasKey('fee_summary', $response->json('data.data.0'));

        // 숫자 쪽당 상한은 그대로다
        $this->actingAs($this->adminUser)->getJson($this->apiBase.'?per_page=101')->assertStatus(422);
    }
}
