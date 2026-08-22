<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Mockery;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 사용 중인 배송유형·택배사 삭제 시도의 응답 계약
 *
 * 관리자 화면은 배송설정 저장 payload 에서 항목을 빼는 방식으로 삭제한다. 그 항목이
 * 주문에서 사용 중이면 서비스가 도메인 실패를 던지는데, 컨트롤러가 그것을 generic
 * catch 로 받아 500 "설정 저장 중 오류" 로 뭉개면 운영자는 **왜** 못 지우는지 알 수 없다.
 * 인프라 장애와 구분되지 않으므로 조치 방법도 없다.
 *
 * 도메인 실패는 사유가 담긴 400 으로, 그 외 예외만 500 으로 나가야 한다.
 */
class EcommerceSettingsInUseDeletionTest extends ModuleTestCase
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

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 주문에서 사용 중인 것으로 보이도록 주문배송 Repository 를 대체합니다.
     *
     * 실제 주문 그래프를 세우지 않고 "사용 중" 조건만 만든다 — 이 테스트가 고정하려는
     * 것은 사용량 집계가 아니라 그 결과가 어떤 응답이 되는가이기 때문이다.
     *
     * @param  int  $count  사용 건수
     */
    private function pretendInUse(int $count): void
    {
        $repository = Mockery::mock(OrderShippingRepositoryInterface::class);
        $repository->shouldReceive('countByShippingType')->andReturn($count);
        $repository->shouldReceive('countByCarrierId')->andReturn($count);

        $this->app->instance(OrderShippingRepositoryInterface::class, $repository);
    }

    /**
     * 사용 중인 배송유형을 payload 에서 빼 저장하면 사유가 담긴 400 이 돌아온다.
     *
     * @scenario resource=shipping_type, error_class=domain
     *
     * @effects in_use_shipping_type_deletion_returns_400_with_reason
     */
    public function test_deleting_in_use_shipping_type_returns_400_with_reason(): void
    {
        $type = ShippingType::create([
            'code' => 'inuse_type',
            'name' => ['ko' => '사용중배송유형', 'en' => 'In-use type'],
            'category' => 'delivery',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->pretendInUse(3);

        // payload 에서 이 유형을 빼면 syncShippingTypes 가 삭제 대상으로 판정한다.
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => [
                'types' => [],
            ],
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath(
            'message',
            __('sirsoft-ecommerce::exceptions.shipping_type_in_use', [
                'name' => $type->getLocalizedName(),
                'count' => 3,
            ])
        );

        // 사유가 사라지지 않았는지 — 일반 저장 실패 문구로 대체되면 안 된다.
        $this->assertNotSame(
            __('sirsoft-ecommerce::messages.settings.save_error'),
            $response->json('message')
        );

        // 삭제는 롤백되어야 한다.
        $this->assertDatabaseHas($type->getTable(), ['id' => $type->id]);
    }

    /**
     * 사용 중인 택배사도 같은 계약을 따른다 (같은 저장 경로의 형제 결함).
     *
     * @scenario resource=shipping_carrier, error_class=domain
     *
     * @effects in_use_shipping_carrier_deletion_returns_400_with_reason
     */
    public function test_deleting_in_use_shipping_carrier_returns_400_with_reason(): void
    {
        $carrier = ShippingCarrier::create([
            'code' => 'inuse_carrier',
            'name' => ['ko' => '사용중택배사', 'en' => 'In-use carrier'],
            'type' => 'domestic',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->pretendInUse(2);

        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [],
            ],
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath(
            'message',
            __('sirsoft-ecommerce::exceptions.shipping_carrier_in_use', ['count' => 2])
        );
        $this->assertNotSame(
            __('sirsoft-ecommerce::messages.settings.save_error'),
            $response->json('message')
        );

        $this->assertDatabaseHas($carrier->getTable(), ['id' => $carrier->id]);
    }

    /**
     * 도메인 실패가 아닌 예외는 그대로 500 이어야 한다 (구분의 반대편).
     *
     * @scenario resource=shipping_type, error_class=infrastructure
     *
     * @effects settings_save_infrastructure_exception_returns_500
     */
    public function test_infrastructure_exception_on_settings_save_returns_500(): void
    {
        $repository = Mockery::mock(OrderShippingRepositoryInterface::class);
        $repository->shouldReceive('countByShippingType')
            ->andThrow(new \RuntimeException('SQLSTATE[HY000]: connection lost'));
        $this->app->instance(OrderShippingRepositoryInterface::class, $repository);

        ShippingType::create([
            'code' => 'infra_type',
            'name' => ['ko' => '인프라', 'en' => 'Infra'],
            'category' => 'delivery',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => [
                'types' => [],
            ],
        ]);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->json('message'));
    }

    /**
     * 택배사 경로의 인프라 예외도 같은 구분을 따른다.
     *
     * 배송유형과 택배사는 사용량을 각각 `countByShippingType`·`countByCarrierId` 로 세므로
     * 호출 지점이 다르다. 한쪽만 단언하면 나머지 경로의 뭉개기가 그대로 남는다.
     *
     * @scenario resource=shipping_carrier, error_class=infrastructure
     *
     * @effects settings_save_carrier_infrastructure_exception_returns_500
     */
    public function test_infrastructure_exception_on_carrier_settings_save_returns_500(): void
    {
        $repository = Mockery::mock(OrderShippingRepositoryInterface::class);
        $repository->shouldReceive('countByCarrierId')
            ->andThrow(new \RuntimeException('SQLSTATE[HY000]: connection lost'));
        $this->app->instance(OrderShippingRepositoryInterface::class, $repository);

        ShippingCarrier::create([
            'code' => 'infra_carrier',
            'name' => ['ko' => '인프라택배사', 'en' => 'Infra carrier'],
            'type' => 'domestic',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [],
            ],
        ]);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->json('message'));
    }
}
