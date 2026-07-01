<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Enums;

use Modules\Sirsoft\Ecommerce\Enums\MileageEarnTriggerEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 마일리지 Enum 테스트
 */
class MileageEnumsTest extends ModuleTestCase
{
    /**
     * 거래 유형 값이 올바른지 확인합니다.
     */
    public function test_transaction_type_has_correct_values(): void
    {
        $this->assertSame('purchase_earn', MileageTransactionTypeEnum::PURCHASE_EARN->value);
        $this->assertSame('order_use', MileageTransactionTypeEnum::ORDER_USE->value);
        $this->assertSame('earn_cancel', MileageTransactionTypeEnum::EARN_CANCEL->value);
        $this->assertCount(8, MileageTransactionTypeEnum::cases());

        foreach (MileageTransactionTypeEnum::cases() as $case) {
            $this->assertIsString($case->label());
        }
    }

    /**
     * isEarning / isDeducting 분류가 올바른지 확인합니다.
     */
    public function test_earning_and_deducting_classification(): void
    {
        $this->assertTrue(MileageTransactionTypeEnum::PURCHASE_EARN->isEarning());
        $this->assertTrue(MileageTransactionTypeEnum::ADMIN_EARN->isEarning());
        $this->assertTrue(MileageTransactionTypeEnum::REFUND_RESTORE->isEarning());
        $this->assertTrue(MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->isEarning());

        $this->assertTrue(MileageTransactionTypeEnum::ORDER_USE->isDeducting());
        $this->assertTrue(MileageTransactionTypeEnum::ADMIN_DEDUCT->isDeducting());
        $this->assertTrue(MileageTransactionTypeEnum::EXPIRED->isDeducting());
        $this->assertTrue(MileageTransactionTypeEnum::EARN_CANCEL->isDeducting());

        // 상호 배타
        foreach (MileageTransactionTypeEnum::cases() as $case) {
            $this->assertNotSame($case->isEarning(), $case->isDeducting());
        }
    }

    /**
     * 관리자 배지 그룹이 5색으로 매핑되는지 확인합니다.
     */
    public function test_admin_badge_group_maps_to_five_color_groups(): void
    {
        $this->assertSame('green', MileageTransactionTypeEnum::PURCHASE_EARN->adminBadgeGroup());
        $this->assertSame('blue', MileageTransactionTypeEnum::ORDER_USE->adminBadgeGroup());
        $this->assertSame('gray', MileageTransactionTypeEnum::EXPIRED->adminBadgeGroup());
        $this->assertSame('teal', MileageTransactionTypeEnum::REFUND_RESTORE->adminBadgeGroup());
        $this->assertSame('teal', MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->adminBadgeGroup());
        $this->assertSame('amber', MileageTransactionTypeEnum::ADMIN_EARN->adminBadgeGroup());
        $this->assertSame('amber', MileageTransactionTypeEnum::ADMIN_DEDUCT->adminBadgeGroup());
        $this->assertSame('amber', MileageTransactionTypeEnum::EARN_CANCEL->adminBadgeGroup());
    }

    /**
     * 사용자 표시 4분류가 복원/수동/회수를 adjust 로 통합하는지 확인합니다.
     */
    public function test_user_display_category_collapses_into_four_groups(): void
    {
        $this->assertSame('earn', MileageTransactionTypeEnum::PURCHASE_EARN->userDisplayCategory());
        $this->assertSame('use', MileageTransactionTypeEnum::ORDER_USE->userDisplayCategory());
        $this->assertSame('expire', MileageTransactionTypeEnum::EXPIRED->userDisplayCategory());

        $this->assertSame('adjust', MileageTransactionTypeEnum::ADMIN_EARN->userDisplayCategory());
        $this->assertSame('adjust', MileageTransactionTypeEnum::ADMIN_DEDUCT->userDisplayCategory());
        $this->assertSame('adjust', MileageTransactionTypeEnum::REFUND_RESTORE->userDisplayCategory());
        $this->assertSame('adjust', MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->userDisplayCategory());
        $this->assertSame('adjust', MileageTransactionTypeEnum::EARN_CANCEL->userDisplayCategory());

        // 4분류만 존재
        $categories = array_unique(array_map(
            fn ($c) => $c->userDisplayCategory(),
            MileageTransactionTypeEnum::cases()
        ));
        sort($categories);
        $this->assertSame(['adjust', 'earn', 'expire', 'use'], $categories);
    }

    /**
     * 적립 시점 enum 기본값 및 컬럼 매핑을 확인합니다.
     */
    public function test_earn_trigger_default_and_timestamp_column(): void
    {
        $this->assertSame('confirmed', MileageEarnTriggerEnum::CONFIRMED->value);
        $this->assertSame('delivered', MileageEarnTriggerEnum::DELIVERED->value);

        $this->assertSame('confirmed_at', MileageEarnTriggerEnum::CONFIRMED->timestampColumn());
        $this->assertSame('delivered_at', MileageEarnTriggerEnum::DELIVERED->timestampColumn());

        $this->assertCount(2, MileageEarnTriggerEnum::cases());
    }
}
