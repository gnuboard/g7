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
     *
     * @effects transaction_type_values_and_case_count_are_stable_with_a_label_for_each
     *
     * @return void 반환값 없음
     */
    public function test_transaction_type_has_correct_values(): void
    {
        $this->assertSame('purchase_earn', MileageTransactionTypeEnum::PURCHASE_EARN->value);
        $this->assertSame('order_use', MileageTransactionTypeEnum::ORDER_USE->value);
        $this->assertSame('earn_cancel', MileageTransactionTypeEnum::EARN_CANCEL->value);
        $this->assertSame('refund_credit', MileageTransactionTypeEnum::REFUND_CREDIT->value);
        $this->assertCount(9, MileageTransactionTypeEnum::cases());

        foreach (MileageTransactionTypeEnum::cases() as $case) {
            $this->assertIsString($case->label());
        }
    }

    /**
     * isEarning / isDeducting 분류가 올바른지 확인합니다.
     *
     * @effects earning_and_deducting_classification_is_total_and_mutually_exclusive
     *
     * @return void 반환값 없음
     */
    public function test_earning_and_deducting_classification(): void
    {
        $this->assertTrue(MileageTransactionTypeEnum::PURCHASE_EARN->isEarning());
        $this->assertTrue(MileageTransactionTypeEnum::ADMIN_EARN->isEarning());
        $this->assertTrue(MileageTransactionTypeEnum::REFUND_RESTORE->isEarning());
        $this->assertTrue(MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->isEarning());
        $this->assertTrue(MileageTransactionTypeEnum::REFUND_CREDIT->isEarning());

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
     *
     * @effects admin_badge_group_distinguishes_eight_types_in_five_color_groups
     *
     * @return void 반환값 없음
     */
    public function test_admin_badge_group_maps_to_five_color_groups(): void
    {
        $this->assertSame('green', MileageTransactionTypeEnum::PURCHASE_EARN->adminBadgeGroup());
        $this->assertSame('blue', MileageTransactionTypeEnum::ORDER_USE->adminBadgeGroup());
        $this->assertSame('gray', MileageTransactionTypeEnum::EXPIRED->adminBadgeGroup());
        $this->assertSame('teal', MileageTransactionTypeEnum::REFUND_RESTORE->adminBadgeGroup());
        $this->assertSame('teal', MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->adminBadgeGroup());
        $this->assertSame('teal', MileageTransactionTypeEnum::REFUND_CREDIT->adminBadgeGroup());
        $this->assertSame('amber', MileageTransactionTypeEnum::ADMIN_EARN->adminBadgeGroup());
        $this->assertSame('amber', MileageTransactionTypeEnum::ADMIN_DEDUCT->adminBadgeGroup());
        $this->assertSame('amber', MileageTransactionTypeEnum::EARN_CANCEL->adminBadgeGroup());
    }

    /**
     * 사용자 표시 4분류가 잔액 증가 유형을 전부 earn 에 세우는지 확인합니다.
     *
     * 요약 카드의 '누적 적립' 은 isEarning() 으로 세는데 목록 필터가 같은 행을 '조정' 으로
     * 접으면 한 화면이 같은 거래를 두 갈래로 분류한다. 세부 사유는 description 이 말한다.
     *
     * @scenario case=balance_increase_listed_as_earn
     *
     * @effects balance_increasing_types_listed_under_earn
     *
     * @return void 반환값 없음
     */
    public function test_user_display_category_puts_every_balance_increase_in_earn(): void
    {
        $this->assertSame('earn', MileageTransactionTypeEnum::PURCHASE_EARN->userDisplayCategory());
        $this->assertSame('earn', MileageTransactionTypeEnum::ADMIN_EARN->userDisplayCategory());
        $this->assertSame('earn', MileageTransactionTypeEnum::REFUND_RESTORE->userDisplayCategory());
        $this->assertSame('earn', MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->userDisplayCategory());
        $this->assertSame('earn', MileageTransactionTypeEnum::REFUND_CREDIT->userDisplayCategory());

        $this->assertSame('use', MileageTransactionTypeEnum::ORDER_USE->userDisplayCategory());
        $this->assertSame('expire', MileageTransactionTypeEnum::EXPIRED->userDisplayCategory());

        // 사용·소멸이 아닌 차감만 '조정' 에 남는다
        $this->assertSame('adjust', MileageTransactionTypeEnum::ADMIN_DEDUCT->userDisplayCategory());
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
     * 목록 분류와 요약 집계 기준이 어긋나지 않는지 확인합니다. (회귀 잠금)
     *
     * @scenario case=category_agrees_with_is_earning
     *
     * @effects display_category_agrees_with_is_earning
     *
     * @return void 반환값 없음
     */
    public function test_user_display_category_agrees_with_is_earning(): void
    {
        foreach (MileageTransactionTypeEnum::cases() as $case) {
            if ($case->isEarning()) {
                $this->assertSame(
                    'earn',
                    $case->userDisplayCategory(),
                    "{$case->value} 는 잔액이 늘어나는 거래인데 목록에서 '적립' 에 서지 않는다"
                );
            }
        }
    }

    /**
     * 적립 시점 enum 기본값 및 컬럼 매핑을 확인합니다.
     *
     * @effects earn_trigger_maps_each_case_to_its_timestamp_column
     *
     * @return void 반환값 없음
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
