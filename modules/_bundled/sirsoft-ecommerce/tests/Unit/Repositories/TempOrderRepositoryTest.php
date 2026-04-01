<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use App\Models\User;
use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Database\Factories\TempOrderFactory;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Repositories\TempOrderRepository;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 임시 주문 Repository 테스트
 */
class TempOrderRepositoryTest extends ModuleTestCase
{
    protected TempOrderRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new TempOrderRepository(new TempOrder());
    }

    public function test_find_returns_temp_order(): void
    {
        $tempOrder = TempOrderFactory::new()->create();

        $found = $this->repository->find($tempOrder->id);

        $this->assertNotNull($found);
        $this->assertEquals($tempOrder->id, $found->id);
    }

    public function test_find_returns_null_for_non_existent_id(): void
    {
        $found = $this->repository->find(99999);

        $this->assertNull($found);
    }

    public function test_find_by_cart_key_returns_temp_order(): void
    {
        $cartKey = 'ck_test_cart_key_123';
        $tempOrder = TempOrderFactory::new()->withCartKey($cartKey)->create();

        $found = $this->repository->findByCartKey($cartKey);

        $this->assertNotNull($found);
        $this->assertEquals($cartKey, $found->cart_key);
        $this->assertNull($found->user_id);
    }

    public function test_find_by_cart_key_excludes_user_orders(): void
    {
        $cartKey = 'ck_test_cart_key_456';
        $user = User::factory()->create();

        // 동일 cart_key지만 user_id가 있는 경우는 조회되면 안됨
        TempOrderFactory::new()->create([
            'cart_key' => $cartKey,
            'user_id' => $user->id,
        ]);

        $found = $this->repository->findByCartKey($cartKey);

        $this->assertNull($found);
    }

    public function test_find_by_user_id_returns_temp_order(): void
    {
        $user = User::factory()->create();
        $tempOrder = TempOrderFactory::new()->forUser($user)->create();

        $found = $this->repository->findByUserId($user->id);

        $this->assertNotNull($found);
        $this->assertEquals($user->id, $found->user_id);
    }

    public function test_find_by_user_id_returns_null_for_non_existent_user(): void
    {
        $found = $this->repository->findByUserId(99999);

        $this->assertNull($found);
    }

    public function test_create_creates_temp_order(): void
    {
        $user = User::factory()->create();
        $data = [
            'user_id' => $user->id,
            'items' => [
                ['product_id' => 1, 'product_option_id' => 1, 'quantity' => 2],
            ],
            'calculation_input' => [
                'promotions' => [
                    'item_coupons' => [],
                    'order_coupon_issue_id' => null,
                    'shipping_coupon_issue_id' => null,
                ],
                'use_points' => 0,
                'shipping_address' => null,
            ],
            'calculation_result' => ['summary' => ['final_amount' => 10000]],
            'expires_at' => Carbon::now()->addMinutes(30),
        ];

        $tempOrder = $this->repository->create($data);

        $this->assertNotNull($tempOrder->id);
        $this->assertEquals($user->id, $tempOrder->user_id);
        $this->assertEquals(10000, $tempOrder->getFinalAmount());
    }

    public function test_update_updates_temp_order(): void
    {
        $tempOrder = TempOrderFactory::new()->create();

        $newCalculationInput = [
            'promotions' => [
                'item_coupons' => [],
                'order_coupon_issue_id' => null,
                'shipping_coupon_issue_id' => null,
            ],
            'use_points' => 1000,
            'shipping_address' => null,
        ];

        $updated = $this->repository->update($tempOrder, ['calculation_input' => $newCalculationInput]);

        $this->assertEquals(1000, $updated->getUsedPoints());
    }

    public function test_delete_deletes_temp_order(): void
    {
        $tempOrder = TempOrderFactory::new()->create();
        $id = $tempOrder->id;

        $result = $this->repository->delete($tempOrder);

        $this->assertTrue($result);
        $this->assertNull($this->repository->find($id));
    }

    public function test_upsert_creates_when_not_exists(): void
    {
        $user = User::factory()->create();
        $data = [
            'user_id' => $user->id,
            'items' => [['product_id' => 1, 'product_option_id' => 1, 'quantity' => 1]],
            'calculation_input' => [
                'promotions' => [
                    'item_coupons' => [],
                    'order_coupon_issue_id' => null,
                    'shipping_coupon_issue_id' => null,
                ],
                'use_points' => 0,
                'shipping_address' => null,
            ],
            'calculation_result' => ['summary' => ['final_amount' => 5000]],
            'expires_at' => Carbon::now()->addMinutes(30),
        ];

        $tempOrder = $this->repository->upsert($data);

        $this->assertNotNull($tempOrder->id);
        $this->assertEquals($user->id, $tempOrder->user_id);
    }

    public function test_upsert_updates_when_user_exists(): void
    {
        $user = User::factory()->create();
        $existing = TempOrderFactory::new()->forUser($user)->create();

        $data = [
            'user_id' => $user->id,
            'items' => [['product_id' => 2, 'product_option_id' => 2, 'quantity' => 3]],
            'calculation_input' => [
                'promotions' => [
                    'item_coupons' => [],
                    'order_coupon_issue_id' => 1,
                    'shipping_coupon_issue_id' => 2,
                ],
                'use_points' => 500,
                'shipping_address' => null,
            ],
            'calculation_result' => ['summary' => ['final_amount' => 15000]],
            'expires_at' => Carbon::now()->addMinutes(30),
        ];

        $tempOrder = $this->repository->upsert($data);

        $this->assertEquals($existing->id, $tempOrder->id);
        $this->assertEquals(500, $tempOrder->getUsedPoints());
        $this->assertEquals(1, $tempOrder->getOrderCouponIssueId());
        $this->assertEquals(2, $tempOrder->getShippingCouponIssueId());
    }

    public function test_upsert_updates_when_cart_key_exists(): void
    {
        $cartKey = 'ck_upsert_test_key';
        $existing = TempOrderFactory::new()->withCartKey($cartKey)->create();

        $data = [
            'cart_key' => $cartKey,
            'items' => [['product_id' => 3, 'product_option_id' => 3, 'quantity' => 1]],
            'calculation_input' => [
                'promotions' => [
                    'item_coupons' => [],
                    'order_coupon_issue_id' => null,
                    'shipping_coupon_issue_id' => null,
                ],
                'use_points' => 200,
                'shipping_address' => null,
            ],
            'calculation_result' => ['summary' => ['final_amount' => 8000]],
            'expires_at' => Carbon::now()->addMinutes(30),
        ];

        $tempOrder = $this->repository->upsert($data);

        $this->assertEquals($existing->id, $tempOrder->id);
        $this->assertEquals(200, $tempOrder->getUsedPoints());
    }

    public function test_delete_expired_deletes_only_expired_orders(): void
    {
        // 만료된 임시 주문 2개
        TempOrderFactory::new()->expired()->create();
        TempOrderFactory::new()->expired()->create();

        // 유효한 임시 주문 1개
        $valid = TempOrderFactory::new()->create();

        $deletedCount = $this->repository->deleteExpired();

        $this->assertEquals(2, $deletedCount);
        $this->assertNotNull($this->repository->find($valid->id));
    }

    public function test_delete_by_cart_key_deletes_temp_order(): void
    {
        $cartKey = 'ck_delete_test_key';
        TempOrderFactory::new()->withCartKey($cartKey)->create();

        $result = $this->repository->deleteByCartKey($cartKey);

        $this->assertTrue($result);
        $this->assertNull($this->repository->findByCartKey($cartKey));
    }

    public function test_delete_by_cart_key_returns_false_when_not_exists(): void
    {
        $result = $this->repository->deleteByCartKey('ck_non_existent');

        $this->assertFalse($result);
    }

    public function test_delete_by_user_id_deletes_temp_order(): void
    {
        $user = User::factory()->create();
        TempOrderFactory::new()->forUser($user)->create();

        $result = $this->repository->deleteByUserId($user->id);

        $this->assertTrue($result);
        $this->assertNull($this->repository->findByUserId($user->id));
    }

    public function test_delete_by_user_id_returns_false_when_not_exists(): void
    {
        $result = $this->repository->deleteByUserId(99999);

        $this->assertFalse($result);
    }

    public function test_find_by_user_or_cart_key_returns_user_order(): void
    {
        $user = User::factory()->create();
        $tempOrder = TempOrderFactory::new()->forUser($user)->create();

        $found = $this->repository->findByUserOrCartKey($user->id, null);

        $this->assertNotNull($found);
        $this->assertEquals($tempOrder->id, $found->id);
    }

    public function test_find_by_user_or_cart_key_returns_guest_order(): void
    {
        $cartKey = 'ck_find_test_key';
        $tempOrder = TempOrderFactory::new()->withCartKey($cartKey)->create();

        $found = $this->repository->findByUserOrCartKey(null, $cartKey);

        $this->assertNotNull($found);
        $this->assertEquals($tempOrder->id, $found->id);
    }

    public function test_find_by_user_or_cart_key_returns_null_for_both_null(): void
    {
        $found = $this->repository->findByUserOrCartKey(null, null);

        $this->assertNull($found);
    }

    public function test_find_valid_by_user_or_cart_key_returns_valid_order(): void
    {
        $user = User::factory()->create();
        $tempOrder = TempOrderFactory::new()->forUser($user)->create();

        $found = $this->repository->findValidByUserOrCartKey($user->id, null);

        $this->assertNotNull($found);
        $this->assertEquals($tempOrder->id, $found->id);
    }

    public function test_find_valid_by_user_or_cart_key_returns_null_for_expired(): void
    {
        $user = User::factory()->create();
        TempOrderFactory::new()->forUser($user)->expired()->create();

        $found = $this->repository->findValidByUserOrCartKey($user->id, null);

        $this->assertNull($found);
    }

    public function test_find_valid_by_user_or_cart_key_returns_null_for_non_existent(): void
    {
        $found = $this->repository->findValidByUserOrCartKey(99999, null);

        $this->assertNull($found);
    }
}
