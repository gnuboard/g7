<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Support\ClaimPhysicalGate;

/**
 * 관리자 직권 접수 오케스트레이션 (§3-11 · D5~D8)
 *
 * 운영자가 주문 상세에서 고객을 대신해 교환·반품·취소/환불을 접수한다. 입구는 주문 상세 상품 표의
 * 선택 바 하나다 — 입구가 둘이면 게이트가 둘이 된다. 종전의 관리자 [주문취소](`AdminOrderCancelService`)는
 * 이 서비스의 취소·환불 분기로 흡수됐다.
 *
 * ## 운영자 축 (고객 접수와 다른 점)
 *
 *  - **물리 게이트**(D5) — 신청 가능 기간·신청 가능 상태·취소 가능 상태 설정을 따르지 않는다. 물건이
 *    고객에게 간 상태(배송중·배송완료·구매확정)면 교환·반품을, 아직 보내지 않은 상태면 취소·환불을
 *    받는다(`ClaimPhysicalGate`). 수량 잠금(`ClaimQuantityGuard`)은 그대로다.
 *  - **판매자 귀책 사유**도 고를 수 있다.
 *  - **청구액**(D7) — 사유로 자동 계산한 배송비를 운영자가 고칠 수 있고, `free` 면 배송비 0 이다.
 *  - **판매자 접수**(D8) — 교환·반품 신청서의 `requested_by_admin`, 환불신청서의 `refund_source_type = admin`.
 *    고객은 그 신청을 철회·수정할 수 없다. 고객에게 접수 알림이 간다(「판매자가 고객님을 대신해 접수했습니다」).
 *
 * ## 접수와 승인은 다른 사실이다 (D6)
 *
 * 「접수와 함께 승인」 을 켜면 접수가 커밋된 **뒤에** 승인을 시도한다. 추가결제가 필요한 건은 승인
 * 대신 결제대기로 간다(기존 게이트). 승인 자체가 막히면(재고 부족 등) 접수는 남기고 응답에
 * `approval_skipped` 를 싣는다 — 접수까지 되돌리면 상담 내용을 다시 입력해야 한다.
 */
class AdminClaimRequestService
{
    /** 청구 방식 선택지 중 「무료(배송비 0)」 — 저장 값이 아니라 접수 창의 선택이다 */
    public const CHARGE_FREE = 'free';

    /**
     * @param  UserClaimService  $claimService  클레임 접수 서비스 (접수·첨부·결제 진입 공용)
     * @param  AdminClaimService  $adminClaimService  관리자 클레임 서비스 (승인·결제 요청 게이트)
     * @param  OrderExchangeService  $exchangeService  교환 서비스 (미리보기)
     * @param  OrderReturnService  $returnService  반품 서비스 (미리보기)
     * @param  OrderRefundRequestService  $refundRequestService  환불신청 서비스 (미리보기)
     */
    public function __construct(
        protected UserClaimService $claimService,
        protected AdminClaimService $adminClaimService,
        protected OrderExchangeService $exchangeService,
        protected OrderReturnService $returnService,
        protected OrderRefundRequestService $refundRequestService,
    ) {}

    /**
     * 주문 옵션이 그 유형의 직권 접수를 받는지 판정합니다. (D5 — 물리 게이트)
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  OrderOption  $option  주문 옵션
     * @return bool 받으면 true
     */
    public function physicalGate(ClaimTypeEnum $type, OrderOption $option): bool
    {
        return ClaimPhysicalGate::allows($type, $option->option_status);
    }

    /**
     * 주문 상세 선택 바가 쓸 유형별 직권 접수 가능 상태 목록을 반환합니다. (화면 게이트 — 서버 판정과 같은 출처)
     *
     * @return array{exchange: array<int, string>, return: array<int, string>, refund: array<int, string>} 유형별 상태 값
     */
    public function gateStatuses(): array
    {
        return [
            ClaimTypeEnum::EXCHANGE->value => ClaimPhysicalGate::statuses(ClaimTypeEnum::EXCHANGE),
            ClaimTypeEnum::RETURN->value => ClaimPhysicalGate::statuses(ClaimTypeEnum::RETURN),
            ClaimTypeEnum::REFUND->value => ClaimPhysicalGate::statuses(ClaimTypeEnum::REFUND),
        ];
    }

    /**
     * 직권 접수 창의 청구 미리보기를 계산합니다. (DB 변경 없음 — D7)
     *
     * 고객 신청과 같은 산식(귀책별 배송비·옵션 차액)이고, 운영자가 고친 배송비·「무료」 를 반영한다.
     *
     * @param  Order  $order  대상 주문
     * @param  ClaimTypeEnum  $type  클레임 유형 (exchange / return / refund)
     * @param  array  $input  접수 입력 (items / reason_code / claim_shipping_fee? / charge_method? / refund_priority? / redelivery_address?)
     * @return array 미리보기 결과 (유형별 형태 + 공통 `type`)
     *
     * @throws OrderClaimException 물리 게이트·수량·사유 위반
     */
    public function preview(Order $order, ClaimTypeEnum $type, array $input): array
    {
        [$chargeMethod, $feeOverride] = $this->resolveCharge($input);
        $refundPriority = $this->resolveRefundPriority($input['refund_priority'] ?? null);
        $items = $this->normalizeItems((array) ($input['items'] ?? []));

        return match ($type) {
            ClaimTypeEnum::EXCHANGE => ['type' => $type->value] + $this->exchangeService->previewExchange(
                $order,
                $items,
                (string) ($input['reason_code'] ?? ''),
                $this->exchangeChargeMethod($chargeMethod),
                $refundPriority,
                $input['redelivery_address'] ?? null,
                null,
                operator: true,
                shippingFeeOverride: $feeOverride,
            ),
            ClaimTypeEnum::RETURN => ['type' => $type->value] + $this->returnService->previewReturn(
                $order,
                $items,
                (string) ($input['reason_code'] ?? ''),
                $this->exchangeChargeMethod($chargeMethod),
                $refundPriority,
                null,
                operator: true,
                shippingFeeOverride: $feeOverride,
            ),
            default => ['type' => ClaimTypeEnum::REFUND->value] + $this->refundRequestService->previewRefund(
                $order,
                $this->toCancelItems($order, (bool) ($input['full_cancel'] ?? false), $items),
                $refundPriority,
                null,
                ClaimPhysicalGate::statuses(ClaimTypeEnum::REFUND),
                (bool) ($input['auto_advance'] ?? false)
                    ? OrderRefundRequestService::BRANCH_AUTO
                    : OrderRefundRequestService::BRANCH_REQUEST,
            ),
        };
    }

    /**
     * 교환을 직권 접수합니다. (D5~D8)
     *
     * @param  Order  $order  대상 주문
     * @param  array  $input  접수 입력 (items / reason_code / reason_detail / redelivery_address / claim_shipping_fee / charge_method / refund_priority / refund_bank / approve)
     * @param  int|null  $adminId  접수 관리자 ID
     * @return array{claim: OrderExchange, payment_requested: bool, approval_skipped: array{reason: string}|null} 접수 결과
     *
     * @throws OrderClaimException 물리 게이트·수량·사유·청구 방식·교환 옵션 위반
     */
    public function requestExchange(Order $order, array $input, ?int $adminId): array
    {
        $outcome = $this->claimService->requestExchange($order, $this->claimPayload($input, $adminId) + [
            'redelivery_address' => $input['redelivery_address'] ?? null,
        ]);

        return $this->approveIfRequested($outcome['claim'], (bool) ($input['approve'] ?? true), $adminId);
    }

    /**
     * 반품을 직권 접수합니다. (D5~D8)
     *
     * @param  Order  $order  대상 주문
     * @param  array  $input  접수 입력 (items / reason_code / reason_detail / claim_shipping_fee / charge_method / refund_priority / refund_bank / approve)
     * @param  int|null  $adminId  접수 관리자 ID
     * @return array{claim: OrderReturn, payment_requested: bool, approval_skipped: array{reason: string}|null} 접수 결과
     *
     * @throws OrderClaimException 물리 게이트·수량·사유·청구 방식 위반
     */
    public function requestReturn(Order $order, array $input, ?int $adminId): array
    {
        $outcome = $this->claimService->requestReturn($order, $this->claimPayload($input, $adminId));

        return $this->approveIfRequested($outcome['claim'], (bool) ($input['approve'] ?? true), $adminId);
    }

    /**
     * 취소·환불을 직권 접수합니다. (종전 관리자 [주문취소] 의 흡수 — 환불신청서 접수)
     *
     * 환불신청서는 `refund_source_type = admin` 이라 고객이 철회·수정할 수 없다. 게이트는 D5 물리
     * 게이트(아직 보내지 않은 상태)다 — 종전의 `order_settings.cancellable_statuses` 는 고객 축으로만 남는다.
     *
     * @param  Order  $order  대상 주문
     * @param  array{full_cancel?: bool, items?: array, reason_code?: ?string, reason_detail?: ?string, refund_priority?: mixed, refund_bank?: ?array, auto_advance?: bool}  $input  취소 입력
     * @param  int|null  $adminId  접수 관리자 ID
     * @return array{branch: ?string, refund: mixed, cancel: mixed, claim_number: ?string, cancelled: bool} 접수 결과
     *
     * @throws OrderClaimException 물리 게이트·사유·수량 잠금 위반
     * @throws OrderCancellationException 즉시 확정 분기의 취소 파이프라인 거절
     */
    public function requestCancelOrRefund(Order $order, array $input, ?int $adminId): array
    {
        $autoAdvance = (bool) ($input['auto_advance'] ?? false);

        $outcome = $this->claimService->requestRefund($order, [
            'items' => $this->toCancelItems($order, (bool) ($input['full_cancel'] ?? false), (array) ($input['items'] ?? [])),
            'reason_code' => $input['reason_code'] ?? null,
            'reason_detail' => $input['reason_detail'] ?? null,
            'requested_by' => null,
            'processed_by' => $adminId,
            'refund_priority' => $this->resolveRefundPriority($input['refund_priority'] ?? null),
            'refund_bank' => $input['refund_bank'] ?? null,
            // 운영자 대리 접수 — 고객이 이 신청서를 철회·수정하지 못하게 하는 축이다(§4-3-1 · D8).
            'refund_source_type' => RefundSourceTypeEnum::ADMIN,
            'auto_advance' => $autoAdvance,
            // 분기는 운영자의 선택이 정한다 — 고객용 신청 창구로 재지 않는다.
            'branch' => $autoAdvance
                ? OrderRefundRequestService::BRANCH_AUTO
                : OrderRefundRequestService::BRANCH_REQUEST,
            // 물리 게이트(D5) — 아직 보내지 않은 상태면 받는다. 고객용 설정은 따르지 않는다.
            'gate_statuses' => ClaimPhysicalGate::statuses(ClaimTypeEnum::REFUND),
            // 관리자 경로는 화면에 노출되지 않는 사유(판매자 귀책 전용)도 고를 수 있다.
            'user_selectable_reason_only' => false,
        ]);

        $refund = $outcome['refund'] ?? null;
        $cancel = $outcome['cancel'] ?? null;

        return [
            'branch' => $outcome['branch'] ?? null,
            'refund' => $refund,
            'cancel' => $cancel,
            'claim_number' => $refund?->refund_number ?? $cancel?->cancel_number,
            // 취소 확정 여부 — 취소신청서는 환불이 집행된 순간에만 생긴다(D3).
            'cancelled' => $cancel !== null || ($refund !== null && $refund->order_cancel_id !== null),
        ];
    }

    /**
     * 취소 요청을 클레임 신청 아이템 형태로 바꿉니다. (전체취소 = 잔여 활성 상품 전부)
     *
     * @param  Order  $order  대상 주문
     * @param  bool  $fullCancel  전체취소 여부
     * @param  array<int, array{order_option_id: int|string, cancel_quantity?: int|string, quantity?: int|string}>  $cancelItems  부분취소 대상
     * @return array<int, array{order_option_id: int, quantity: int}> 신청 아이템
     */
    public function toCancelItems(Order $order, bool $fullCancel, array $cancelItems): array
    {
        if (! $fullCancel) {
            return array_map(
                fn (array $item): array => [
                    'order_option_id' => (int) $item['order_option_id'],
                    'quantity' => (int) ($item['cancel_quantity'] ?? $item['quantity'] ?? 0),
                ],
                $cancelItems,
            );
        }

        $order->loadMissing('options');
        $items = [];

        foreach ($order->options as $option) {
            if ($option->option_status === OrderStatusEnum::CANCELLED) {
                continue;
            }

            $items[] = [
                'order_option_id' => (int) $option->id,
                'quantity' => (int) $option->quantity,
            ];
        }

        return $items;
    }

    /**
     * 교환·반품 접수 페이로드(운영자 축)를 만듭니다.
     *
     * @param  array  $input  접수 입력
     * @param  int|null  $adminId  접수 관리자 ID
     * @return array<string, mixed> 서비스 페이로드
     */
    protected function claimPayload(array $input, ?int $adminId): array
    {
        [$chargeMethod, $feeOverride] = $this->resolveCharge($input);

        return [
            'items' => $this->normalizeItems((array) ($input['items'] ?? [])),
            'reason_code' => $input['reason_code'] ?? null,
            'reason_detail' => $input['reason_detail'] ?? null,
            'charge_method' => $this->exchangeChargeMethod($chargeMethod),
            'refund_priority' => $input['refund_priority'] ?? null,
            'refund_bank' => $input['refund_bank'] ?? null,
            // 고객이 낸 신청이 아니다 — 신청자 축은 비우고 대리 접수자를 남긴다(D8).
            'requested_by' => null,
            'requested_by_admin' => $adminId,
            'operator' => true,
            'shipping_fee_override' => $feeOverride,
        ];
    }

    /**
     * 청구 방식·배송비 입력을 해석합니다. (`free` 는 배송비 0 + 청구 방식 없음)
     *
     * @param  array  $input  접수 입력
     * @return array{0: string|null, 1: float|null} [청구 방식 값, 배송비 덮어쓰기]
     */
    protected function resolveCharge(array $input): array
    {
        $chargeMethod = $input['charge_method'] ?? null;
        $fee = $input['claim_shipping_fee'] ?? null;

        if ($chargeMethod === self::CHARGE_FREE) {
            return [null, 0.0];
        }

        return [
            $chargeMethod === '' ? null : $chargeMethod,
            $fee === null || $fee === '' ? null : (float) $fee,
        ];
    }

    /**
     * 청구 방식 값을 서비스가 받는 형태로 돌려줍니다. (빈 값은 null)
     *
     * @param  string|null  $chargeMethod  청구 방식 값
     * @return ClaimChargeMethodEnum|null 청구 방식
     */
    protected function exchangeChargeMethod(?string $chargeMethod): ?ClaimChargeMethodEnum
    {
        if ($chargeMethod === null || $chargeMethod === '') {
            return null;
        }

        return ClaimChargeMethodEnum::tryFrom($chargeMethod)
            ?? throw OrderClaimException::chargeMethodNotAllowed();
    }

    /**
     * 환불 우선순위 입력을 Enum 으로 해석합니다. (없으면 PG 우선)
     *
     * @param  mixed  $value  입력 값
     * @return RefundPriorityEnum 환불 우선순위
     */
    protected function resolveRefundPriority(mixed $value): RefundPriorityEnum
    {
        if ($value instanceof RefundPriorityEnum) {
            return $value;
        }

        return RefundPriorityEnum::tryFrom((string) $value) ?? RefundPriorityEnum::PG_FIRST;
    }

    /**
     * 접수 아이템의 수량 키를 서비스가 읽는 형태로 맞춥니다. (`quantity`)
     *
     * @param  array<int, array<string, mixed>>  $items  접수 아이템
     * @return array<int, array<string, mixed>> 정규화된 아이템
     */
    protected function normalizeItems(array $items): array
    {
        return array_values(array_map(function (array $item): array {
            $item['order_option_id'] = (int) ($item['order_option_id'] ?? 0);
            $item['quantity'] = (int) ($item['quantity'] ?? $item['cancel_quantity'] ?? 0);

            return $item;
        }, $items));
    }

    /**
     * 「접수와 함께 승인」 이 켜져 있으면 승인합니다. (D6 — 접수는 이미 커밋됐다)
     *
     * 추가결제가 필요한 건은 승인 대신 결제대기로 간다(`AdminClaimService::transition()` 의 기존 게이트).
     * 승인이 막히면 접수는 남기고 사유를 돌려준다.
     *
     * @param  OrderExchange|OrderReturn  $claim  접수된 신청서
     * @param  bool  $approve  함께 승인할지
     * @param  int|null  $adminId  처리 관리자 ID
     * @return array{claim: OrderExchange|OrderReturn, payment_requested: bool, approval_skipped: array{reason: string}|null} 결과
     */
    protected function approveIfRequested(OrderExchange|OrderReturn $claim, bool $approve, ?int $adminId): array
    {
        if (! $approve) {
            return ['claim' => $claim, 'payment_requested' => false, 'approval_skipped' => null];
        }

        try {
            $result = $this->adminClaimService->transition($claim, 'approved', ['processed_by' => $adminId]);

            return ['claim' => $result['claim'], 'payment_requested' => (bool) $result['payment_requested'], 'approval_skipped' => null];
        } catch (OrderClaimException $e) {
            return [
                'claim' => $claim->fresh() ?? $claim,
                'payment_requested' => false,
                'approval_skipped' => ['reason' => $e->getMessage()],
            ];
        }
    }
}
