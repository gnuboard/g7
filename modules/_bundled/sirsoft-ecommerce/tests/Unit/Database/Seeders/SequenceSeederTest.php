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
    public function test_seeder_creates_all_sequences_on_fresh_install(): void
    {
        $this->seed(SequenceSeeder::class);

        $this->assertEquals(5, Sequence::count());

        foreach ([SequenceType::PRODUCT, SequenceType::ORDER, SequenceType::SHIPPING, SequenceType::CANCEL, SequenceType::REFUND] as $type) {
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

        $this->assertEquals(5, Sequence::count(), '재실행해도 중복 생성되지 않아야 함');
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

        $this->assertEquals(count(SequenceType::cases()), Sequence::count(), '누락된 시퀀스가 재생성되어야 함');
    }

    /**
     * Drift 회귀 차단: SequenceSeeder 가 SequenceType::cases() 의 모든 enum 멤버를
     * 빠짐없이 시드하는지 검증. enum 에 새 멤버 추가 시 시더가 자동으로 따라가지
     * 않으면 본 테스트가 실패한다.
     *
     * 격리 보장: TestingSeeder 가 사전 시드한 데이터를 모두 제거하고 SequenceSeeder
     * 단독 동작만 검증해야 함 (그렇지 않으면 시더 결함을 TestingSeeder 가 메워서
     * 통과하는 false-green 발생 — 본 회귀 테스트의 발단이 정확히 이 시나리오).
     */
    public function test_seeder_은_모든_SequenceType_enum_멤버를_시드한다_drift_차단(): void
    {
        // TestingSeeder 사전 시드 제거 — SequenceSeeder 단독 동작 검증
        Sequence::query()->delete();
        $this->assertEquals(0, Sequence::count(), '격리 검증: 사전 시드가 제거되어야 함');

        $this->seed(SequenceSeeder::class);

        foreach (SequenceType::cases() as $type) {
            $this->assertTrue(
                Sequence::where('type', $type->value)->exists(),
                "SequenceSeeder 가 {$type->value} 시퀀스를 시드하지 않음 — enum drift 발생"
            );
        }

        $this->assertEquals(
            count(SequenceType::cases()),
            Sequence::count(),
            '시드된 시퀀스 수가 enum 멤버 수와 일치해야 함'
        );
    }
}
