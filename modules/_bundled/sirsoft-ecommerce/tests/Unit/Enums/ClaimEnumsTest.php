<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Enums;

use Modules\Sirsoft\Ecommerce\Enums\AdjustmentType;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
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

    public function test_exchange_status_has_14_cases(): void
    {
        $values = ExchangeStatusEnum::values();

        $this->assertCount(14, $values);
        $this->assertSame([
            'requested',
            'payment_pending',
            'paid',
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
        // 결제완료도 승인 전이다 — 철회는 전액 환급을 동반한다(§4-2 paid 규약 ⓒ).
        $this->assertTrue(ExchangeStatusEnum::PAID->isWithdrawable());
        $this->assertTrue(ExchangeStatusEnum::ON_HOLD->isWithdrawable());

        $this->assertFalse(ExchangeStatusEnum::APPROVED->isWithdrawable());
        $this->assertFalse(ExchangeStatusEnum::COLLECTION_READY->isWithdrawable());
        $this->assertFalse(ExchangeStatusEnum::COMPLETED->isWithdrawable());
    }

    public function test_exchange_status_forward_transitions_follow_lifecycle(): void
    {
        $this->assertTrue(ExchangeStatusEnum::REQUESTED->canTransitionTo(ExchangeStatusEnum::APPROVED));
        $this->assertTrue(ExchangeStatusEnum::REQUESTED->canTransitionTo(ExchangeStatusEnum::PAYMENT_PENDING));
        $this->assertTrue(ExchangeStatusEnum::REQUESTED->canTransitionTo(ExchangeStatusEnum::ON_HOLD));
        $this->assertTrue(ExchangeStatusEnum::REQUESTED->canTransitionTo(ExchangeStatusEnum::REJECTED));
        $this->assertTrue(ExchangeStatusEnum::PAYMENT_PENDING->canTransitionTo(ExchangeStatusEnum::APPROVED));
        $this->assertTrue(ExchangeStatusEnum::APPROVED->canTransitionTo(ExchangeStatusEnum::COLLECTION_READY));
        $this->assertTrue(ExchangeStatusEnum::COLLECTION_READY->canTransitionTo(ExchangeStatusEnum::COLLECTING));
        $this->assertTrue(ExchangeStatusEnum::COLLECTING->canTransitionTo(ExchangeStatusEnum::COLLECTED));
        $this->assertTrue(ExchangeStatusEnum::COLLECTED->canTransitionTo(ExchangeStatusEnum::REDELIVERY_READY));
        $this->assertTrue(ExchangeStatusEnum::REDELIVERY_READY->canTransitionTo(ExchangeStatusEnum::REDELIVERING));
        $this->assertTrue(ExchangeStatusEnum::REDELIVERING->canTransitionTo(ExchangeStatusEnum::REDELIVERED));
        $this->assertTrue(ExchangeStatusEnum::REDELIVERED->canTransitionTo(ExchangeStatusEnum::COMPLETED));
    }

    public function test_exchange_status_payment_pending_keeps_all_three_exits(): void
    {
        // §4-2 전이표 payment_pending 행 — 유저 철회 / 관리자 반려 / (수동·결제 완료) 승인.
        // 반품 축(test_return_status_payment_pending_keeps_all_three_exits)과 같은 형태로 고정한다 —
        // 한쪽만 단언하면 두 유형의 방어 강도가 갈린다.
        $this->assertTrue(ExchangeStatusEnum::PAYMENT_PENDING->canTransitionTo(ExchangeStatusEnum::WITHDRAWN));
        $this->assertTrue(ExchangeStatusEnum::PAYMENT_PENDING->canTransitionTo(ExchangeStatusEnum::REJECTED));
        $this->assertTrue(ExchangeStatusEnum::PAYMENT_PENDING->canTransitionTo(ExchangeStatusEnum::APPROVED));
        // 결제 확정의 도착지 — 자동승인 on/off 를 가리지 않고 항상 여기를 거친다.
        $this->assertTrue(ExchangeStatusEnum::PAYMENT_PENDING->canTransitionTo(ExchangeStatusEnum::PAID));

        $this->assertFalse(ExchangeStatusEnum::PAYMENT_PENDING->canTransitionTo(ExchangeStatusEnum::COLLECTION_READY));
    }

    public function test_exchange_status_on_hold_can_be_approved_or_rejected(): void
    {
        // §4-2 전이표 on_hold 행의 관리자 축은 승인·반려 둘 다이다.
        $this->assertTrue(ExchangeStatusEnum::ON_HOLD->canTransitionTo(ExchangeStatusEnum::APPROVED));
        $this->assertTrue(ExchangeStatusEnum::ON_HOLD->canTransitionTo(ExchangeStatusEnum::REJECTED));
    }

    public function test_exchange_status_approved_cannot_be_rejected(): void
    {
        // 승인 뒤에는 반려로 되돌릴 수 없다 — 승인 시점에 신규 옵션 재고가 차감되고 회수 준비가
        // 시작되므로, 되돌릴 대상이 이미 움직여 있다. 승인 후의 종료는 완료뿐이다.
        $this->assertFalse(ExchangeStatusEnum::APPROVED->canTransitionTo(ExchangeStatusEnum::REJECTED));
        $this->assertSame([ExchangeStatusEnum::COLLECTION_READY], ExchangeStatusEnum::APPROVED->allowedTransitions());
    }

    public function test_exchange_status_skipping_stages_is_blocked(): void
    {
        // 단계 건너뛰기 금지 — 회수도 하지 않고 완료로 넘기면 재고·정산 근거가 사라진다.
        $this->assertFalse(ExchangeStatusEnum::APPROVED->canTransitionTo(ExchangeStatusEnum::COMPLETED));
        $this->assertFalse(ExchangeStatusEnum::REQUESTED->canTransitionTo(ExchangeStatusEnum::COLLECTED));
        $this->assertFalse(ExchangeStatusEnum::COLLECTING->canTransitionTo(ExchangeStatusEnum::REDELIVERED));
        // 회수도착에서 재발송준비중을 건너뛰고 곧장 재발송중으로 가면 후속 주문옵션 생성 시점이 흐트러진다.
        $this->assertFalse(ExchangeStatusEnum::COLLECTED->canTransitionTo(ExchangeStatusEnum::REDELIVERING));
    }

    /**
     * 상태 14종의 허용 간선을 **행 단위 등식**으로 고정한다 (§4-2 전이표).
     *
     * 개별 셀만 짚으면 그 행에 간선이 새로 늘어나도 red 가 되지 않는다 — 단계를 건너뛰는 통로가
     * 조용히 열려도 재고·정산 근거가 사라진 채 통과한다. 표 전체를 한 벌로 적어 그 사각을 없앤다.
     */
    public function test_exchange_status_allowed_transitions_match_the_lifecycle_table(): void
    {
        $expected = [
            ExchangeStatusEnum::REQUESTED->value => [
                ExchangeStatusEnum::PAYMENT_PENDING,
                ExchangeStatusEnum::APPROVED,
                ExchangeStatusEnum::ON_HOLD,
                ExchangeStatusEnum::REJECTED,
                ExchangeStatusEnum::WITHDRAWN,
            ],
            ExchangeStatusEnum::PAYMENT_PENDING->value => [
                ExchangeStatusEnum::PAID,
                ExchangeStatusEnum::APPROVED,
                ExchangeStatusEnum::REJECTED,
                ExchangeStatusEnum::WITHDRAWN,
            ],
            ExchangeStatusEnum::PAID->value => [
                ExchangeStatusEnum::APPROVED,
                ExchangeStatusEnum::ON_HOLD,
                ExchangeStatusEnum::REJECTED,
                ExchangeStatusEnum::WITHDRAWN,
            ],
            ExchangeStatusEnum::ON_HOLD->value => [
                ExchangeStatusEnum::APPROVED,
                ExchangeStatusEnum::REJECTED,
                ExchangeStatusEnum::WITHDRAWN,
            ],
            ExchangeStatusEnum::APPROVED->value => [ExchangeStatusEnum::COLLECTION_READY],
            ExchangeStatusEnum::COLLECTION_READY->value => [ExchangeStatusEnum::COLLECTING],
            ExchangeStatusEnum::COLLECTING->value => [ExchangeStatusEnum::COLLECTED],
            ExchangeStatusEnum::COLLECTED->value => [ExchangeStatusEnum::REDELIVERY_READY],
            ExchangeStatusEnum::REDELIVERY_READY->value => [ExchangeStatusEnum::REDELIVERING],
            ExchangeStatusEnum::REDELIVERING->value => [ExchangeStatusEnum::REDELIVERED],
            ExchangeStatusEnum::REDELIVERED->value => [ExchangeStatusEnum::COMPLETED],
            ExchangeStatusEnum::COMPLETED->value => [],
            ExchangeStatusEnum::REJECTED->value => [],
            ExchangeStatusEnum::WITHDRAWN->value => [],
        ];

        $this->assertEqualsCanonicalizing(
            array_map(fn (ExchangeStatusEnum $status) => $status->value, ExchangeStatusEnum::cases()),
            array_keys($expected),
            '표가 상태 전수를 덮어야 한다 — 상태가 늘면 이 표도 함께 늘어난다.'
        );

        foreach ($expected as $value => $targets) {
            $this->assertEqualsCanonicalizing(
                $targets,
                ExchangeStatusEnum::from($value)->allowedTransitions(),
                "{$value} 의 허용 간선이 전이표와 달라졌습니다."
            );
        }
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

    public function test_return_status_has_11_cases_without_redelivery(): void
    {
        $values = ReturnStatusEnum::values();

        $this->assertCount(11, $values);
        $this->assertContains('paid', $values);
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
        $this->assertTrue(ReturnStatusEnum::PAID->isWithdrawable());
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
        $this->assertTrue(ReturnStatusEnum::PAYMENT_PENDING->canTransitionTo(ReturnStatusEnum::PAID));

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

    public function test_return_status_allowed_transitions_match_the_lifecycle_table(): void
    {
        // 교환 축(test_exchange_status_allowed_transitions_match_the_lifecycle_table)과 같은 형태로
        // 11행 전수를 행 등식으로 고정한다. 개별 케이스들이 approved·회수 3단계·터미널 3종 7행만
        // 고정하고 있어 requested·payment_pending·on_hold 3행은 간선이 늘어도 red 가 나지 않았다.
        $expected = [
            ReturnStatusEnum::REQUESTED->value => [
                ReturnStatusEnum::PAYMENT_PENDING,
                ReturnStatusEnum::APPROVED,
                ReturnStatusEnum::ON_HOLD,
                ReturnStatusEnum::REJECTED,
                ReturnStatusEnum::WITHDRAWN,
            ],
            ReturnStatusEnum::PAYMENT_PENDING->value => [
                ReturnStatusEnum::PAID,
                ReturnStatusEnum::APPROVED,
                ReturnStatusEnum::REJECTED,
                ReturnStatusEnum::WITHDRAWN,
            ],
            ReturnStatusEnum::PAID->value => [
                ReturnStatusEnum::APPROVED,
                ReturnStatusEnum::ON_HOLD,
                ReturnStatusEnum::REJECTED,
                ReturnStatusEnum::WITHDRAWN,
            ],
            ReturnStatusEnum::ON_HOLD->value => [
                ReturnStatusEnum::APPROVED,
                ReturnStatusEnum::REJECTED,
                ReturnStatusEnum::WITHDRAWN,
            ],
            ReturnStatusEnum::APPROVED->value => [ReturnStatusEnum::COLLECTION_READY],
            ReturnStatusEnum::COLLECTION_READY->value => [ReturnStatusEnum::COLLECTING],
            ReturnStatusEnum::COLLECTING->value => [ReturnStatusEnum::COLLECTED],
            // 반품은 회수도착에서 곧바로 완료다 — 재발송 3단계가 없다(§4-2).
            ReturnStatusEnum::COLLECTED->value => [ReturnStatusEnum::COMPLETED],
            ReturnStatusEnum::COMPLETED->value => [],
            ReturnStatusEnum::REJECTED->value => [],
            ReturnStatusEnum::WITHDRAWN->value => [],
        ];

        $this->assertEqualsCanonicalizing(
            array_map(fn (ReturnStatusEnum $status) => $status->value, ReturnStatusEnum::cases()),
            array_keys($expected),
            '표가 상태 전수를 덮어야 한다 — 상태가 늘면 이 표도 함께 늘어난다.'
        );

        foreach ($expected as $value => $targets) {
            $this->assertEqualsCanonicalizing(
                $targets,
                ReturnStatusEnum::from($value)->allowedTransitions(),
                "{$value} 의 허용 간선이 전이표와 달라졌습니다."
            );
        }
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

    public function test_refund_status_transition_table_is_pinned_row_by_row(): void
    {
        // 교환(14종)·반품(11종)은 행 등식으로 전수 고정돼 있는데 환불축만 샘플 6셀이었다 — 간선을
        // 지우거나 더해도 green 이 나는 상태였다. 같은 강도로 맞춘다.
        $expected = [
            'requested' => ['approved', 'on_hold', 'rejected', 'withdrawn'],
            'on_hold' => ['approved', 'rejected', 'withdrawn'],
            'approved' => ['processing', 'completed', 'on_hold'],
            'processing' => ['completed', 'on_hold'],
            'completed' => [],
            'rejected' => [],
            'withdrawn' => [],
        ];

        $this->assertSame(
            count($expected),
            count(RefundStatusEnum::cases()),
            '환불 상태가 늘거나 줄었습니다 — 전이표 기대값을 함께 갱신해야 이 테스트가 의미를 갖습니다.',
        );

        foreach (RefundStatusEnum::cases() as $status) {
            $this->assertArrayHasKey($status->value, $expected, "전이표에 없는 상태: {$status->value}");

            $this->assertEqualsCanonicalizing(
                $expected[$status->value],
                array_map(fn (RefundStatusEnum $s) => $s->value, $status->allowedTransitions()),
                "환불 상태 `{$status->value}` 의 전이 목록이 기대와 다릅니다.",
            );
        }
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

    // ========================================
    // 상태 아이콘 (진행단계 스텝퍼 · 상태 뱃지 공용)
    // ========================================

    /**
     * 상태 Enum 4종의 모든 case 가 라이브러리 중립 의미 토큰(`status-*`)을 가진다. 백엔드가
     * 특정 아이콘 라이브러리의 글리프 이름을 박으면 그 라이브러리에 묶인다 — 토큰을 글리프로
     * 바꾸는 표는 템플릿의 Icon 컴포넌트가 가진다. 어휘 밖 토큰은 화면에서 빈 네모로 그려진다.
     */
    public function test_every_claim_status_has_a_library_neutral_icon_token(): void
    {
        $allowed = [
            'status-request', 'status-payment-pending', 'status-paid', 'status-approved', 'status-on-hold',
            'status-rejected', 'status-pickup-ready', 'status-pickup', 'status-received', 'status-dispatch-ready',
            'status-dispatch', 'status-delivered', 'status-completed', 'status-withdrawn', 'status-processing',
        ];

        foreach ([ExchangeStatusEnum::class, ReturnStatusEnum::class, RefundStatusEnum::class, CancelStatusEnum::class] as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertContains($case->icon(), $allowed, "{$enum}::{$case->name} 의 아이콘 토큰이 어휘 밖입니다.");
                $this->assertStringStartsWith('status-', $case->icon());
                $this->assertStringStartsNotWith('fa-', $case->icon(), '백엔드는 아이콘 라이브러리 글리프 이름을 알지 않습니다.');
            }
        }
    }

    /**
     * 유형이 달라도 같은 단계는 같은 그림이어야 운영자가 유형마다 단계를 다시 익히지 않는다.
     */
    public function test_shared_stages_use_the_same_icon_across_claim_types(): void
    {
        foreach (['requested', 'approved', 'on_hold', 'rejected', 'completed', 'withdrawn'] as $value) {
            $this->assertSame(ExchangeStatusEnum::from($value)->icon(), ReturnStatusEnum::from($value)->icon(), $value);
            $this->assertSame(ExchangeStatusEnum::from($value)->icon(), RefundStatusEnum::from($value)->icon(), $value);
        }

        foreach (['payment_pending', 'paid', 'collection_ready', 'collecting', 'collected'] as $value) {
            $this->assertSame(ExchangeStatusEnum::from($value)->icon(), ReturnStatusEnum::from($value)->icon(), $value);
        }

        $this->assertSame(ExchangeStatusEnum::REQUESTED->icon(), CancelStatusEnum::REQUESTED->icon());
        $this->assertSame(ExchangeStatusEnum::COMPLETED->icon(), CancelStatusEnum::COMPLETED->icon());
    }

    /**
     * 취소는 신청 → 완료 두 칸이고 이탈 상태가 없다.
     */
    public function test_cancel_status_progress_sequence_is_two_stages(): void
    {
        $this->assertSame([CancelStatusEnum::REQUESTED, CancelStatusEnum::COMPLETED], CancelStatusEnum::progressSequence());
        $this->assertFalse(CancelStatusEnum::REQUESTED->isOffProgressPath());
    }
}
