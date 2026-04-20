<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database\Seeders;

use Modules\Sirsoft\Ecommerce\Database\Seeders\SequenceSeeder;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * SequenceSeeder 테스트
 *
 * 핵심 불변: current_value 는 운영 중 자동 증가하는 counter 이므로 재실행 시 리셋되면 안 됨.
 */
class SequenceSeederTest extends ModuleTestCase
{
    public function test_seeder_creates_four_sequences_on_fresh_install(): void
    {
        $this->seed(SequenceSeeder::class);

        $this->assertEquals(4, Sequence::count());

        foreach ([SequenceType::PRODUCT, SequenceType::ORDER, SequenceType::CANCEL, SequenceType::REFUND] as $type) {
            $this->assertTrue(
                Sequence::where('type', $type->value)->exists(),
                "{$type->value} 시퀀스가 생성되어야 함"
            );
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(SequenceSeeder::class);
        $this->seed(SequenceSeeder::class);

        $this->assertEquals(4, Sequence::count(), '재실행해도 중복 생성되지 않아야 함');
    }

    /**
     * 핵심 회귀 테스트: current_value 는 재시드 시 보존되어야 함
     * (이전 delete+insert 패턴으로 리셋되던 버그 회귀 방지)
     */
    public function test_seeder_preserves_current_value_on_reinstall(): void
    {
        $this->seed(SequenceSeeder::class);

        // 운영 중 주문이 발생하여 current_value 가 증가한 상황 시뮬레이션
        Sequence::where('type', SequenceType::ORDER->value)->update(['current_value' => 12345]);
        Sequence::where('type', SequenceType::PRODUCT->value)->update(['current_value' => 9876]);

        // 재시드 (install --force 시나리오)
        $this->seed(SequenceSeeder::class);

        $orderSequence = Sequence::where('type', SequenceType::ORDER->value)->first();
        $productSequence = Sequence::where('type', SequenceType::PRODUCT->value)->first();

        $this->assertEquals(12345, $orderSequence->current_value, '주문 current_value 가 보존되어야 함');
        $this->assertEquals(9876, $productSequence->current_value, '상품 current_value 가 보존되어야 함');
    }

    public function test_seeder_creates_missing_sequence_when_partially_deleted(): void
    {
        $this->seed(SequenceSeeder::class);

        // 사용자가 주문 시퀀스만 남기고 나머지 삭제한 상황
        Sequence::where('type', '!=', SequenceType::ORDER->value)->delete();
        $this->assertEquals(1, Sequence::count());

        $this->seed(SequenceSeeder::class);

        $this->assertEquals(4, Sequence::count(), '누락된 시퀀스가 재생성되어야 함');
    }
}
