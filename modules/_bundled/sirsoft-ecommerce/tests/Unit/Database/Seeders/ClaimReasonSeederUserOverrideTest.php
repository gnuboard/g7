<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database\Seeders;

use Modules\Sirsoft\Ecommerce\Database\Seeders\ClaimReasonSeeder;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 클레임 사유 재시드 시 운영자 편집값 보존 테스트
 *
 * 설정 화면에서 운영자가 편집할 수 있는 필드는 전부 `trackableFields` 에 있어야 한다.
 * 빠진 필드는 재시드가 무조건 시드값으로 덮어쓰므로, 같은 행의 두 토글이 다른 규칙을 갖게
 * 된다 — 운영자가 구매자 선택지에서 내려 둔 사유가 모듈 재설치 한 번에 다시 노출된다.
 */
class ClaimReasonSeederUserOverrideTest extends ModuleTestCase
{
    #[Test]
    public function reseed_preserves_operator_edited_fault_type_and_user_selectability(): void
    {
        $this->seed(ClaimReasonSeeder::class);

        $reason = ClaimReason::where('code', 'order_mistake')->firstOrFail();

        // 시드 정의값 확인 (보존 대상이 실제로 바뀌는 값인지 먼저 고정한다)
        $this->assertSame(ClaimReasonFaultTypeEnum::CUSTOMER, $reason->fault_type);
        $this->assertTrue($reason->is_user_selectable);

        // 운영자가 설정 화면에서 귀책 유형을 바꾸고 구매자 선택지에서 내린 상황
        $reason->update([
            'fault_type' => ClaimReasonFaultTypeEnum::SELLER->value,
            'is_user_selectable' => false,
            'is_active' => false,
        ]);

        // 재시드 (모듈 재설치 / install --force 시나리오)
        $this->seed(ClaimReasonSeeder::class);

        $reason->refresh();

        $this->assertSame(
            ClaimReasonFaultTypeEnum::SELLER,
            $reason->fault_type,
            '운영자가 바꾼 귀책 유형이 재시드 후에도 보존되어야 함'
        );
        $this->assertFalse(
            $reason->is_user_selectable,
            '운영자가 구매자 선택지에서 내린 사유가 재시드로 다시 노출되면 안 됨'
        );
        $this->assertFalse(
            $reason->is_active,
            '형제 토글(is_active)과 같은 규칙이어야 함'
        );
    }
}
