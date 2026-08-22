<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Enums;

use Modules\Sirsoft\Ecommerce\Enums\AdjustmentType;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임(교환/반품/환불) 관련 Enum 테스트
 */
class ClaimEnumsTest extends ModuleTestCase
{
    // ========================================
    // ExchangeStatusEnum
    // ========================================

    public function test_exchange_status_has_13_cases(): void
    {
        $values = ExchangeStatusEnum::values();

        $this->assertCount(13, $values);
        $this->assertSame([
            'requested',
            'payment_pending',
            'approved',
            'on_hold',
            'rejected',
            'collection_ready',
            'collecting',
            'collected',
            'redelivery_ready',
            'redelivering',
            'redelivered',
            'completed',
            'withdrawn',
        ], $values);
    }

    public function test_exchange_status_final_statuses(): void
    {
        $this->assertTrue(ExchangeStatusEnum::COMPLETED->isFinal());
        $this->assertTrue(ExchangeStatusEnum::REJECTED->isFinal());
        $this->assertTrue(ExchangeStatusEnum::WITHDRAWN->isFinal());

        $this->assertFalse(ExchangeStatusEnum::REQUESTED->isFinal());
        $this->assertFalse(ExchangeStatusEnum::COLLECTED->isFinal());
        $this->assertFalse(ExchangeStatusEnum::REDELIVERED->isFinal());
    }

    public function test_exchange_status_withdrawable_boundary_is_approval(): void
    {
        // 승인 전까지만 신청자가 철회할 수 있다 — 승인 이후는 회수 절차가 시작된다.
        $this->assertTrue(ExchangeStatusEnum::REQUESTED->isWithdrawable());
        $this->assertTrue(ExchangeStatusEnum::PAYMENT_PENDING->isWithdrawable());
        $this->assertTrue(ExchangeStatusEnum::ON_HOLD->isWithdrawable());

        $this->assertFalse(ExchangeStatusEnum::APPROVED->isWithdrawable());
        $this->assertFalse(ExchangeStatusEnum::COLLECTION_READY->isWithdrawable());
        $this->assertFalse(ExchangeStatusEnum::COMPLETED->isWithdrawable());
    }

    public function test_exchange_status_forward_transitions_follow_lifecycle(): void
    {
        $this->assertTrue(ExchangeStatusEnum::REQUESTED->canTransitionTo(ExchangeStatusEnum::APPROVED));
        $this->assertTrue(ExchangeStatusEnum::REQUESTED->canTransitionTo(ExchangeStatusEnum::PAYMENT_PENDING));
        $this->assertTrue(ExchangeStatusEnum::PAYMENT_PENDING->canTransitionTo(ExchangeStatusEnum::APPROVED));
        $this->assertTrue(ExchangeStatusEnum::APPROVED->canTransitionTo(ExchangeStatusEnum::COLLECTION_READY));
        $this->assertTrue(ExchangeStatusEnum::COLLECTION_READY->canTransitionTo(ExchangeStatusEnum::COLLECTING));
        $this->assertTrue(ExchangeStatusEnum::COLLECTING->canTransitionTo(ExchangeStatusEnum::COLLECTED));
        $this->assertTrue(ExchangeStatusEnum::COLLECTED->canTransitionTo(ExchangeStatusEnum::REDELIVERY_READY));
        $this->assertTrue(ExchangeStatusEnum::REDELIVERY_READY->canTransitionTo(ExchangeStatusEnum::REDELIVERING));
        $this->assertTrue(ExchangeStatusEnum::REDELIVERING->canTransitionTo(ExchangeStatusEnum::REDELIVERED));
        $this->assertTrue(ExchangeStatusEnum::REDELIVERED->canTransitionTo(ExchangeStatusEnum::COMPLETED));
    }

    public function test_exchange_status_skipping_stages_is_blocked(): void
    {
        // 단계 건너뛰기 금지 — 회수도 하지 않고 완료로 넘기면 재고·정산 근거가 사라진다.
        $this->assertFalse(ExchangeStatusEnum::APPROVED->canTransitionTo(ExchangeStatusEnum::COMPLETED));
        $this->assertFalse(ExchangeStatusEnum::REQUESTED->canTransitionTo(ExchangeStatusEnum::COLLECTED));
        $this->assertFalse(ExchangeStatusEnum::COLLECTING->canTransitionTo(ExchangeStatusEnum::REDELIVERED));
    }

    public function test_exchange_status_terminal_states_have_no_transitions(): void
    {
        foreach (ExchangeStatusEnum::finalStatuses() as $terminal) {
            $this->assertSame([], $terminal->allowedTransitions());
            $this->assertFalse($terminal->canTransitionTo(ExchangeStatusEnum::APPROVED));
        }
    }

    public function test_exchange_status_self_transition_is_allowed_as_noop(): void
    {
        // 일괄 전이에서 이미 그 상태인 건이 섞여도 실패하지 않아야 한다.
        foreach (ExchangeStatusEnum::cases() as $status) {
            $this->assertTrue($status->canTransitionTo($status));
        }
    }

    public function test_exchange_status_withdrawn_reachable_only_before_approval(): void
    {
        foreach (ExchangeStatusEnum::cases() as $status) {
            $canWithdraw = $status->canTransitionTo(ExchangeStatusEnum::WITHDRAWN);

            if ($status === ExchangeStatusEnum::WITHDRAWN) {
                continue; // self-transition
            }

            $this->assertSame(
                $status->isWithdrawable(),
                $canWithdraw,
                "{$status->value} 의 철회 가능 여부와 전이 게이트가 일치해야 합니다."
            );
        }
    }

    public function test_exchange_status_labels_are_type_prefixed_and_translated(): void
    {
        foreach (ExchangeStatusEnum::cases() as $status) {
            $label = $status->label();

            $this->assertNotSame(
                'sirsoft-ecommerce::enums.exchange_status.'.$status->value,
                $label,
                "{$status->value} 라벨이 다국어로 해석되어야 합니다."
            );
            $this->assertStringStartsWith('교환', $label, '상태 라벨은 유형 접두 정확 명칭이어야 합니다.');
        }
    }

    // ========================================
    // ReturnStatusEnum
    // ========================================

    public function test_return_status_has_10_cases_without_redelivery(): void
    {
        $values = ReturnStatusEnum::values();

        $this->assertCount(10, $values);
        $this->assertNotContains('redelivery_ready', $values);
        $this->assertNotContains('redelivering', $values);
        $this->assertNotContains('redelivered', $values);
    }

    public function test_return_status_collected_goes_straight_to_completed(): void
    {
        $this->assertTrue(ReturnStatusEnum::COLLECTED->canTransitionTo(ReturnStatusEnum::COMPLETED));
        $this->assertFalse(ReturnStatusEnum::COLLECTING->canTransitionTo(ReturnStatusEnum::COMPLETED));
    }

    public function test_return_status_withdrawable_boundary_is_approval(): void
    {
        $this->assertTrue(ReturnStatusEnum::REQUESTED->isWithdrawable());
        $this->assertTrue(ReturnStatusEnum::ON_HOLD->isWithdrawable());
        $this->assertFalse(ReturnStatusEnum::APPROVED->isWithdrawable());
        $this->assertFalse(ReturnStatusEnum::COLLECTED->isWithdrawable());
    }

    public function test_return_status_payment_pending_keeps_all_three_exits(): void
    {
        // §4-2 전이표 payment_pending 행 — 유저 철회 / 관리자 반려 / (수동·결제 완료) 승인.
        // 결제 자체는 ClaimPaymentService(S5) 몫이지만 그 서비스가 도착할 지점이 열려 있는지는
        // 게이트가 지금 고정한다 — 닫혀 있으면 S5 가 전이할 곳이 없다.
        $this->assertTrue(ReturnStatusEnum::PAYMENT_PENDING->canTransitionTo(ReturnStatusEnum::WITHDRAWN));
        $this->assertTrue(ReturnStatusEnum::PAYMENT_PENDING->canTransitionTo(ReturnStatusEnum::REJECTED));
        $this->assertTrue(ReturnStatusEnum::PAYMENT_PENDING->canTransitionTo(ReturnStatusEnum::APPROVED));

        // 결제 대기에서 회수로 바로 건너뛸 수는 없다 (승인을 거쳐야 한다).
        $this->assertFalse(ReturnStatusEnum::PAYMENT_PENDING->canTransitionTo(ReturnStatusEnum::COLLECTION_READY));
    }

    public function test_return_status_on_hold_can_be_rejected(): void
    {
        // §4-2 전이표 on_hold 행의 관리자 축은 승인·반려 둘 다이다.
        $this->assertTrue(ReturnStatusEnum::ON_HOLD->canTransitionTo(ReturnStatusEnum::APPROVED));
        $this->assertTrue(ReturnStatusEnum::ON_HOLD->canTransitionTo(ReturnStatusEnum::REJECTED));
    }

    public function test_return_status_requested_can_enter_payment_pending(): void
    {
        // §4-2 전이표 requested 행의 자동 축 — charge>0 ∧ pay_on_request 미결제 → payment_pending.
        // 축 구현은 S5 지만 진입 게이트가 열려 있지 않으면 그 서비스가 들어갈 곳이 없다.
        // 교환 축은 같은 셀을 test_exchange_status_forward_transitions_follow_lifecycle 이
        // 고정하고 있어, 이 케이스가 없으면 두 유형의 방어 강도가 갈린다.
        $this->assertTrue(ReturnStatusEnum::REQUESTED->canTransitionTo(ReturnStatusEnum::PAYMENT_PENDING));
    }

    public function test_return_status_approved_cannot_be_rejected(): void
    {
        // 승인 뒤에는 반려로 되돌릴 수 없다 — 승인 시점에 회수 준비가 시작되고,
        // 반려는 원 주문옵션을 신청 직전 상태로 되돌리는 경로라 회수가 진행된 뒤에는
        // 되돌릴 대상이 이미 움직여 있다. 승인 후의 종료는 완료뿐이다.
        $this->assertFalse(ReturnStatusEnum::APPROVED->canTransitionTo(ReturnStatusEnum::REJECTED));
        $this->assertSame([ReturnStatusEnum::COLLECTION_READY], ReturnStatusEnum::APPROVED->allowedTransitions());
    }

    public function test_return_status_withdrawn_reachable_only_before_approval(): void
    {
        // 교환 축의 정합성 루프(test_exchange_status_withdrawn_reachable_only_before_approval)와
        // 동형. isWithdrawable() 과 전이 게이트가 서로 다른 답을 내면 화면은 [철회] 버튼을
        // 보여 주는데 요청은 거부되는(혹은 그 반대) 형태가 된다.
        foreach (ReturnStatusEnum::cases() as $status) {
            if ($status === ReturnStatusEnum::WITHDRAWN) {
                continue; // self-transition 은 무조건 허용이라 비교 대상이 아니다
            }

            $this->assertSame(
                $status->isWithdrawable(),
                $status->canTransitionTo(ReturnStatusEnum::WITHDRAWN),
                "{$status->value} 의 철회 가능 여부와 전이 게이트가 일치해야 합니다."
            );
        }
    }

    public function test_return_status_self_transition_is_allowed_as_noop(): void
    {
        // 교환 축과 같은 형태 — 일괄 전이에서 이미 그 상태인 건이 섞여도 실패하지 않아야 한다.
        foreach (ReturnStatusEnum::cases() as $status) {
            $this->assertTrue($status->canTransitionTo($status));
        }
    }

    public function test_return_status_collection_stages_only_move_forward(): void
    {
        // 회수 3단계는 행 전체를 고정한다 — 승인 후에는 반려·철회·역행이 없고 다음 단계 하나뿐이다.
        // approved 행만 고정돼 있던 동안 이 3행의 불허 20셀은 무단언이었다.
        $this->assertSame([ReturnStatusEnum::COLLECTING], ReturnStatusEnum::COLLECTION_READY->allowedTransitions());
        $this->assertSame([ReturnStatusEnum::COLLECTED], ReturnStatusEnum::COLLECTING->allowedTransitions());
        $this->assertSame([ReturnStatusEnum::COMPLETED], ReturnStatusEnum::COLLECTED->allowedTransitions());

        foreach ([ReturnStatusEnum::COLLECTION_READY, ReturnStatusEnum::COLLECTING, ReturnStatusEnum::COLLECTED] as $stage) {
            $this->assertFalse($stage->canTransitionTo(ReturnStatusEnum::REJECTED), "{$stage->value} 에서 반려로 갈 수 없어야 합니다.");
            $this->assertFalse($stage->canTransitionTo(ReturnStatusEnum::WITHDRAWN), "{$stage->value} 에서 철회로 갈 수 없어야 합니다.");
            $this->assertFalse($stage->canTransitionTo(ReturnStatusEnum::APPROVED), "{$stage->value} 에서 승인으로 역행할 수 없어야 합니다.");
        }

        // 신청 → 승인 도 Enum 단위로 직접 고정한다 (교환 축과 대칭 — 서비스 경로 간접 증명에만 의존하지 않는다).
        $this->assertTrue(ReturnStatusEnum::REQUESTED->canTransitionTo(ReturnStatusEnum::APPROVED));
    }

    public function test_return_status_terminal_states_have_no_transitions(): void
    {
        foreach (ReturnStatusEnum::finalStatuses() as $terminal) {
            $this->assertSame([], $terminal->allowedTransitions());
        }
    }

    public function test_return_status_labels_are_type_prefixed_and_translated(): void
    {
        foreach (ReturnStatusEnum::cases() as $status) {
            $label = $status->label();

            $this->assertNotSame(
                'sirsoft-ecommerce::enums.return_status.'.$status->value,
                $label
            );
            $this->assertStringStartsWith('반품', $label, '상태 라벨은 유형 접두 정확 명칭이어야 합니다.');
        }
    }

    // ========================================
    // RefundStatusEnum (withdrawn 확장 + 동형 전이 게이트)
    // ========================================

    public function test_refund_status_gains_withdrawn_without_losing_existing_cases(): void
    {
        $values = RefundStatusEnum::values();

        $this->assertCount(7, $values);
        foreach (['requested', 'approved', 'processing', 'on_hold', 'completed', 'rejected'] as $legacy) {
            $this->assertContains($legacy, $values, '기존 환불 상태 값은 유지되어야 합니다.');
        }
        $this->assertContains('withdrawn', $values);
    }

    public function test_refund_status_transition_gate_is_homomorphic_with_claims(): void
    {
        $this->assertTrue(RefundStatusEnum::REQUESTED->canTransitionTo(RefundStatusEnum::APPROVED));
        $this->assertTrue(RefundStatusEnum::REQUESTED->canTransitionTo(RefundStatusEnum::WITHDRAWN));
        $this->assertTrue(RefundStatusEnum::APPROVED->canTransitionTo(RefundStatusEnum::COMPLETED));
        $this->assertTrue(RefundStatusEnum::PROCESSING->canTransitionTo(RefundStatusEnum::ON_HOLD));

        $this->assertFalse(RefundStatusEnum::APPROVED->canTransitionTo(RefundStatusEnum::WITHDRAWN));
        $this->assertFalse(RefundStatusEnum::COMPLETED->canTransitionTo(RefundStatusEnum::APPROVED));
    }

    public function test_refund_status_withdrawn_is_final(): void
    {
        $this->assertTrue(RefundStatusEnum::WITHDRAWN->isFinal());
        $this->assertContains(RefundStatusEnum::WITHDRAWN, RefundStatusEnum::finalStatuses());
    }

    public function test_refund_status_labels_are_translated(): void
    {
        foreach (RefundStatusEnum::cases() as $status) {
            $this->assertNotSame(
                'sirsoft-ecommerce::enums.refund_status.'.$status->value,
                $status->label()
            );
        }
    }

    // ========================================
    // ClaimTypeEnum / ClaimChargeMethodEnum / ClaimPaymentStatusEnum
    // ========================================

    public function test_claim_type_covers_four_axes(): void
    {
        $this->assertSame(['exchange', 'return', 'refund', 'cancel'], ClaimTypeEnum::values());
    }

    public function test_claim_type_attachable_excludes_cancel(): void
    {
        // 취소는 신청서를 따로 받지 않고 즉시취소 파이프라인으로 처리되므로
        // 공용 부속물(첨부/추가결제)의 다형 참조 대상이 아니다.
        $this->assertTrue(ClaimTypeEnum::EXCHANGE->isAttachable());
        $this->assertTrue(ClaimTypeEnum::RETURN->isAttachable());
        $this->assertTrue(ClaimTypeEnum::REFUND->isAttachable());
        $this->assertFalse(ClaimTypeEnum::CANCEL->isAttachable());
    }

    public function test_claim_type_payable_is_exchange_and_return_only(): void
    {
        $this->assertTrue(ClaimTypeEnum::EXCHANGE->isPayable());
        $this->assertTrue(ClaimTypeEnum::RETURN->isPayable());
        $this->assertFalse(ClaimTypeEnum::REFUND->isPayable());
        $this->assertFalse(ClaimTypeEnum::CANCEL->isPayable());
    }

    public function test_claim_charge_method_deduct_from_refund_creates_no_payment(): void
    {
        $this->assertTrue(ClaimChargeMethodEnum::PAY_ON_REQUEST->requiresPayment());
        $this->assertTrue(ClaimChargeMethodEnum::PAY_ON_APPROVAL->requiresPayment());
        $this->assertFalse(ClaimChargeMethodEnum::DEDUCT_FROM_REFUND->requiresPayment());

        $this->assertTrue(ClaimChargeMethodEnum::DEDUCT_FROM_REFUND->requiresRefundSource());
        $this->assertFalse(ClaimChargeMethodEnum::PAY_ON_REQUEST->requiresRefundSource());
    }

    public function test_claim_payment_status_refundable_only_when_paid(): void
    {
        $this->assertTrue(ClaimPaymentStatusEnum::PAID->isSettled());
        $this->assertTrue(ClaimPaymentStatusEnum::PAID->isRefundable());

        foreach ([
            ClaimPaymentStatusEnum::PENDING,
            ClaimPaymentStatusEnum::FAILED,
            ClaimPaymentStatusEnum::CANCELLED,
            ClaimPaymentStatusEnum::REFUNDED,
        ] as $status) {
            $this->assertFalse($status->isRefundable(), "{$status->value} 는 환급 대상이 아니어야 합니다.");
        }
    }

    public function test_claim_payment_status_final_states(): void
    {
        $this->assertTrue(ClaimPaymentStatusEnum::CANCELLED->isFinal());
        $this->assertTrue(ClaimPaymentStatusEnum::REFUNDED->isFinal());
        $this->assertFalse(ClaimPaymentStatusEnum::PENDING->isFinal());
        $this->assertFalse(ClaimPaymentStatusEnum::PAID->isFinal());
    }

    public function test_claim_shipping_fee_mode_values(): void
    {
        $this->assertSame(['fixed', 'original_policy'], ClaimShippingFeeModeEnum::values());
    }

    public function test_claim_enum_labels_are_all_translated(): void
    {
        $sets = [
            'claim_type' => ClaimTypeEnum::cases(),
            'claim_charge_method' => ClaimChargeMethodEnum::cases(),
            'claim_payment_status' => ClaimPaymentStatusEnum::cases(),
            'claim_shipping_fee_mode' => ClaimShippingFeeModeEnum::cases(),
            'refund_source_type' => RefundSourceTypeEnum::cases(),
        ];

        foreach ($sets as $key => $cases) {
            foreach ($cases as $case) {
                $this->assertNotSame(
                    'sirsoft-ecommerce::enums.'.$key.'.'.$case->value,
                    $case->label(),
                    "{$key}.{$case->value} 라벨이 다국어로 해석되어야 합니다."
                );
            }
        }
    }

    // ========================================
    // RefundSourceTypeEnum
    // ========================================

    public function test_refund_source_type_maps_to_source_models(): void
    {
        $this->assertSame(OrderCancel::class, RefundSourceTypeEnum::CANCEL->sourceModelClass());
        $this->assertSame(OrderReturn::class, RefundSourceTypeEnum::RETURN->sourceModelClass());
        $this->assertSame(OrderExchange::class, RefundSourceTypeEnum::EXCHANGE->sourceModelClass());
    }

    public function test_refund_source_type_direct_has_no_source_model(): void
    {
        // 관리자 직접 환불은 참조 레코드가 없다. 이 축을 morphTo 로 두지 않는 근거다.
        $this->assertNull(RefundSourceTypeEnum::DIRECT->sourceModelClass());
        $this->assertNull(RefundSourceTypeEnum::DIRECT->claimType());
    }

    public function test_refund_source_type_claim_type_mapping(): void
    {
        $this->assertSame(ClaimTypeEnum::CANCEL, RefundSourceTypeEnum::CANCEL->claimType());
        $this->assertSame(ClaimTypeEnum::RETURN, RefundSourceTypeEnum::RETURN->claimType());
        $this->assertSame(ClaimTypeEnum::EXCHANGE, RefundSourceTypeEnum::EXCHANGE->claimType());
    }

    // ========================================
    // ClaimReasonTypeEnum / SequenceType / AdjustmentType 확장
    // ========================================

    public function test_claim_reason_type_gains_exchange_and_return(): void
    {
        $values = ClaimReasonTypeEnum::values();

        $this->assertSame(['refund', 'exchange', 'return'], $values);
    }

    public function test_sequence_type_gains_claim_types_with_prefixes(): void
    {
        $this->assertContains('exchange', SequenceType::values());
        $this->assertContains('order_return', SequenceType::values());
        $this->assertContains('claim_payment', SequenceType::values());

        $this->assertSame('EX', SequenceType::EXCHANGE->getDefaultConfig()['prefix']);
        $this->assertSame('RT', SequenceType::ORDER_RETURN->getDefaultConfig()['prefix']);
        $this->assertSame('CP', SequenceType::CLAIM_PAYMENT->getDefaultConfig()['prefix']);
    }

    public function test_sequence_type_every_case_has_default_config(): void
    {
        // 시더가 cases() 전체를 순회하므로 설정 누락이 있으면 설치가 깨진다.
        foreach (SequenceType::cases() as $type) {
            $config = $type->getDefaultConfig();

            $this->assertArrayHasKey('algorithm', $config);
            $this->assertArrayHasKey('max_value', $config);
            $this->assertArrayHasKey('pad_length', $config);
        }
    }

    public function test_adjustment_type_gains_exchange_and_return(): void
    {
        $this->assertSame(['cancel', 'exchange', 'order_return'], AdjustmentType::values());

        foreach (AdjustmentType::cases() as $case) {
            $this->assertNotSame(
                'sirsoft-ecommerce::enums.adjustment_type.'.$case->value,
                $case->label()
            );
            $this->assertNotSame('', $case->variant());
        }
    }
}
