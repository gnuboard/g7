<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Mileage;

use Modules\Sirsoft\Ecommerce\Exceptions\MileageValidationException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Mileage\Concerns\BuildsMileagePolicyFixtures;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 「상품 합계 N원 미만이면 적립금 사용 불가」 정책 테스트
 *
 * 기준은 상품 판매가 × 수량 합(쿠폰·배송비 반영 전)이다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/mileage-policy-signup-noearn-mintotal.yaml
 */
class MinProductTotalForUseTest extends ModuleTestCase
{
    use BuildsMileagePolicyFixtures;

    /**
     * 기준 미만 주문서에서 적립금을 쓰면 422 와 기준 금액 안내가 온다.
     *
     * @scenario policy=min_product_total_for_use, setting=on, trigger=below_threshold
     *
     * @effects use_below_min_product_total_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_using_points_below_min_product_total_returns_422(): void
    {
        $this->writeMileageSettings(['min_product_total_for_use_enabled' => true, 'min_product_total_for_use' => 50000]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);
        $cart = $this->cartFor($user, 30000);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', ['item_ids' => [$cart->id], 'use_points' => 1000]);

        $response->assertStatus(422);
        $this->assertStringContainsString('50,000', (string) $response->json('errors.message'));
    }

    /**
     * 기준 이상이면 통과하고 주문 스냅샷에 기준이 남는다.
     *
     * @scenario policy=min_product_total_for_use, setting=on, trigger=at_or_above_threshold
     *
     * @effects use_at_min_product_total_passes, order_snapshots_min_product_total
     *
     * @return void 반환값 없음
     */
    public function test_using_points_at_min_product_total_passes_and_is_snapshotted(): void
    {
        $this->writeMileageSettings(['min_product_total_for_use_enabled' => true, 'min_product_total_for_use' => 50000]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);
        $cart = $this->cartFor($user, 50000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', ['item_ids' => [$cart->id], 'use_points' => 1000])
            ->assertStatus(201);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/user/orders', $this->memberOrderPayload(49000))
            ->assertStatus(201);

        $order = Order::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(50000, (int) $order->mileage_policy_snapshot['rule']['min_product_total_for_use']);
    }

    /**
     * 임시주문 뒤 기준을 올리면 주문 확정에서 다시 막는다.
     *
     * @scenario policy=min_product_total_for_use, setting=changed_after_order, trigger=below_threshold
     *
     * @effects order_create_revalidates_min_product_total
     *
     * @return void 반환값 없음
     */
    public function test_order_create_revalidates_min_product_total(): void
    {
        $this->writeMileageSettings(['min_product_total_for_use_enabled' => false]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);
        $cart = $this->cartFor($user, 30000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', ['item_ids' => [$cart->id], 'use_points' => 1000])
            ->assertStatus(201);

        $this->writeMileageSettings(['min_product_total_for_use_enabled' => true, 'min_product_total_for_use' => 50000]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/user/orders', $this->memberOrderPayload(29000))
            ->assertStatus(422);
        $this->assertSame(0, Order::query()->where('user_id', $user->id)->count());
    }

    /**
     * 안내값(최대 사용액·사용 정책)도 같은 기준을 따른다. 상품 합계를 주지 않거나 꺼져 있으면 영향이 없다.
     *
     * @scenario policy=min_product_total_for_use, setting=on, trigger=guidance_values
     *
     * @effects max_usable_is_zero_below_min_product_total, usage_policy_exposes_min_product_total, min_product_total_ignored_when_disabled
     *
     * @return void 반환값 없음
     */
    public function test_guidance_values_follow_the_same_threshold(): void
    {
        $this->writeMileageSettings(['min_product_total_for_use_enabled' => true, 'min_product_total_for_use' => 50000]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);
        $service = app(UserMileageService::class);

        $this->assertSame(0, $service->getMaxUsable($user->id, 30000, null, 30000));
        $this->assertSame(10000, $service->getMaxUsable($user->id, 60000, null, 60000));
        $this->assertSame(10000, $service->getMaxUsable($user->id, 30000));
        $this->assertSame(50000, $service->getUsagePolicy(30000)['min_product_total_for_use']);

        try {
            $service->validateUsage($user->id, 1000, 30000, null, 30000);
            $this->fail('기준 미만인데 통과했습니다.');
        } catch (MileageValidationException $e) {
            $this->assertStringContainsString('50,000', $e->getMessage());
        }

        $this->writeMileageSettings(['min_product_total_for_use_enabled' => false, 'min_product_total_for_use' => 50000]);
        $this->assertSame(1000, $service->validateUsage($user->id, 1000, 30000, null, 30000));
        $this->assertSame(0, $service->getUsagePolicy(30000)['min_product_total_for_use']);
    }

    /**
     * 최대 사용액 API 가 상품 합계(선택 값)를 받아 같은 기준으로 답한다.
     *
     * @scenario policy=min_product_total_for_use, setting=on, trigger=max_usable_api
     *
     * @effects max_usable_api_accepts_product_total
     *
     * @return void 반환값 없음
     */
    public function test_max_usable_api_accepts_product_total(): void
    {
        $this->writeMileageSettings(['min_product_total_for_use_enabled' => true, 'min_product_total_for_use' => 50000]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);

        $url = '/api/modules/sirsoft-ecommerce/user/mileage/max-usable';

        $this->actingAs($user, 'sanctum')->getJson($url.'?order_amount=30000&product_total=30000')
            ->assertOk()->assertJsonPath('data.max_usable', 0);
        $this->actingAs($user, 'sanctum')->getJson($url.'?order_amount=60000&product_total=60000')
            ->assertOk()->assertJsonPath('data.max_usable', 10000);
        $this->actingAs($user, 'sanctum')->getJson($url.'?order_amount=30000&product_total=-1')
            ->assertStatus(422);
    }
}
