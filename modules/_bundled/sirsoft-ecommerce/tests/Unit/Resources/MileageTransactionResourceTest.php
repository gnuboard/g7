<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\MileageTransactionCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\MileageTransactionResource;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * MileageTransaction Resource / Collection 테스트 (§18.2-G)
 */
class MileageTransactionResourceTest extends ModuleTestCase
{
    private function makeTx(string $type = 'purchase_earn'): MileageTransaction
    {
        $user = User::factory()->create();

        return MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => $type,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000,
            'expires_at' => now()->addDays(30),
        ]);
    }

    /**
     * Resource: type_label / 배지 그룹 / 표시분류 + *_formatted 동반.
     */
    public function test_resource_exposes_badge_and_display_category(): void
    {
        $tx = $this->makeTx('refund_restore');
        $array = (new MileageTransactionResource($tx))->toArray(Request::create('/'));

        $this->assertSame('refund_restore', $array['type']);
        $this->assertSame('teal', $array['admin_badge_group']);   // 복원계
        $this->assertSame('adjust', $array['user_display_category']); // 사용자 4분류
        $this->assertNotEmpty($array['type_label']);

        // *_at 원시 + *_formatted 사용자 타임존 동반
        $this->assertArrayHasKey('expires_at', $array);
        $this->assertArrayHasKey('expires_at_formatted', $array);
        $this->assertArrayHasKey('created_at_formatted', $array);
    }

    /**
     * Resource: 금액 필드는 float.
     */
    public function test_resource_amounts_are_float(): void
    {
        $tx = $this->makeTx();
        $array = (new MileageTransactionResource($tx))->toArray(Request::create('/'));

        $this->assertIsFloat($array['amount']);
        $this->assertIsFloat($array['remaining_amount']);
        $this->assertSame(1000.0, $array['amount']);
    }

    /**
     * Collection: data + abilities(can_manage) 구조.
     */
    public function test_collection_structure_with_abilities(): void
    {
        $this->makeTx();
        $this->makeTx('admin_deduct');

        $collection = MileageTransaction::query()->paginate(20);
        $array = (new MileageTransactionCollection($collection))->toArray(Request::create('/'));

        $this->assertArrayHasKey('data', $array);
        $this->assertArrayHasKey('abilities', $array);
        $this->assertArrayHasKey('can_manage', $array['abilities']);
        $this->assertCount(2, $array['data']);
    }

    /**
     * 적립계 + 소멸 집계(expired_amount) 기준 expiry_state 계산: active/partial/fully.
     */
    public function test_resource_expiry_state_by_expired_amount(): void
    {
        // 미소멸 적립 → active
        $active = $this->makeTx('purchase_earn');
        $activeArr = (new MileageTransactionResource($active))->toArray(Request::create('/'));
        $this->assertSame('active', $activeArr['expiry_state']);
        $this->assertSame(0.0, $activeArr['expired_amount']);

        // 부분 소멸: 적립 1000, 소멸 집계 400 → partial_expired (서브쿼리 주입값 모사)
        $partial = $this->makeTx('purchase_earn');
        $partial->expired_amount = 400;
        $partialArr = (new MileageTransactionResource($partial))->toArray(Request::create('/'));
        $this->assertSame('partial_expired', $partialArr['expiry_state']);
        $this->assertSame(400.0, $partialArr['expired_amount']);

        // 전체 소멸: 적립 1000, 소멸 집계 1000 → fully_expired
        $full = $this->makeTx('purchase_earn');
        $full->expired_amount = 1000;
        $fullArr = (new MileageTransactionResource($full))->toArray(Request::create('/'));
        $this->assertSame('fully_expired', $fullArr['expiry_state']);
    }

    /**
     * 비적립계(사용)는 소멸 집계가 있어도 expiry_state=active (적립계만 의미).
     */
    public function test_resource_non_earning_expiry_state_is_active(): void
    {
        $use = $this->makeTx('order_use');
        $use->expired_amount = 100; // 비정상 값이 들어와도 비적립계는 active 고정
        $arr = (new MileageTransactionResource($use))->toArray(Request::create('/'));

        $this->assertFalse($arr['is_earning']);
        $this->assertSame('active', $arr['expiry_state']);
    }

    /**
     * can_edit_expiry: 적립계 + 미소멸 + 잔여>0 일 때만 true.
     */
    public function test_resource_can_edit_expiry_flag(): void
    {
        // 적립계 + 잔여 + 미소멸 → true
        $editable = $this->makeTx('purchase_earn');
        $this->assertTrue((new MileageTransactionResource($editable))->toArray(Request::create('/'))['can_edit_expiry']);

        // 소멸됨 → false
        $expired = $this->makeTx('purchase_earn');
        $expired->expired_at = now();
        $expired->remaining_amount = 0;
        $this->assertFalse((new MileageTransactionResource($expired))->toArray(Request::create('/'))['can_edit_expiry']);

        // 비적립계 → false
        $use = $this->makeTx('order_use');
        $this->assertFalse((new MileageTransactionResource($use))->toArray(Request::create('/'))['can_edit_expiry']);
    }

    /**
     * adminBadgeGroup 5색 매핑이 Resource 에 정확히 노출.
     */
    public function test_resource_badge_group_for_each_type(): void
    {
        $map = [
            'purchase_earn' => 'green',
            'order_use' => 'blue',
            'expired' => 'gray',
            'order_cancel_restore' => 'teal',
            'admin_earn' => 'amber',
        ];

        foreach ($map as $type => $expected) {
            $tx = $this->makeTx($type);
            $array = (new MileageTransactionResource($tx))->toArray(Request::create('/'));
            $this->assertSame($expected, $array['admin_badge_group'], "{$type} 배지 그룹");
        }
    }
}
