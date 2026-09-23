<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 관리자 [주문취소] 접수 오케스트레이션 (#599 후속 D7)
 *
 * 관리자 주문 취소는 **환불신청서 접수**다 — 취소 즉시 환불되지 않고, 금액확인 → 승인 → 환불완료를
 * 운영자가 클레임 상세에서 밟는다. 취소신청서는 그 환불이 완료되는 순간에 생긴다(D3). 이 서비스는
 * 컨트롤러에 있던 두 가지를 한 자리로 옮긴 것이다:
 *
 *  - 취소 요청 → 클레임 신청 아이템 변환(`toClaimItems()`) — 전체취소는 잔여 활성 상품 전부로 편다.
 *  - 운영자 축의 접수 페이로드 조립 — 출처(`ADMIN`)·분기(운영자 선택)·자격 상태 목록·사유 노출 범위.
 *
 * 컨트롤러가 이것을 들고 있으면 같은 접수를 부르는 다른 경로(일괄 취소·자동화)가 규칙을 복제하게
 * 되고, 한쪽만 고쳐진 사본이 운영자 축의 게이트를 우회한다.
 */
class AdminOrderCancelService
{
    /**
     * @param  OrderCancellationService  $cancellationService  주문 취소 서비스 (운영자 자격 판정)
     * @param  UserClaimService  $claimService  클레임 접수 서비스
     */
    public function __construct(
        protected OrderCancellationService $cancellationService,
        protected UserClaimService $claimService,
    ) {}

    /**
     * 운영자 취소를 환불신청서로 접수합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  array{full_cancel: bool, items: array, reason_code: ?string, reason_detail: ?string, refund_priority: RefundPriorityEnum, refund_bank: ?array, auto_advance: bool}  $input  취소 입력
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return array{branch: ?string, refund: mixed, cancel: mixed, claim_number: ?string, cancelled: bool} 접수 결과
     *
     * @throws OrderCancellationException 운영자 취소 자격이 없을 때
     * @throws OrderClaimException 신청 자격·사유·수량 잠금 위반
     */
    public function requestCancel(Order $order, array $input, ?int $processedBy): array
    {
        // 운영자의 권한 축은 `order_settings.cancellable_statuses` 다. 환불신청서 접수 경로는 그
        // 설정을 모르므로 여기서 먼저 지킨다 — 없으면 배송중 주문처럼 설정이 막아야 하는 상태가
        // 통과한다(§12-B).
        $this->cancellationService->assertCancellable($order);

        $autoAdvance = (bool) ($input['auto_advance'] ?? false);

        $outcome = $this->claimService->requestRefund($order, [
            'items' => $this->toClaimItems($order, (bool) ($input['full_cancel'] ?? false), (array) ($input['items'] ?? [])),
            'reason_code' => $input['reason_code'] ?? null,
            'reason_detail' => $input['reason_detail'] ?? null,
            'requested_by' => null,
            'processed_by' => $processedBy,
            'refund_priority' => $input['refund_priority'] ?? RefundPriorityEnum::PG_FIRST,
            'refund_bank' => $input['refund_bank'] ?? null,
            // 운영자 대리 접수 — 고객이 이 신청서를 철회·수정하지 못하게 하는 축이다(§4-3-1).
            'refund_source_type' => RefundSourceTypeEnum::ADMIN,
            'auto_advance' => $autoAdvance,
            // 분기는 운영자의 선택이 정한다 — 고객용 신청 창구로 재지 않는다(§12-B).
            // 운영자의 취소 권한은 `order_settings.cancellable_statuses` + `orders.update` 에서
            // 나오고 그 판정은 취소 서비스가 이미 한다. 여기서 다시 재면 두 목록에 없는
            // 상태(배송완료 등)에서 운영자가 아무것도 할 수 없어진다.
            'branch' => $autoAdvance
                ? OrderRefundRequestService::BRANCH_AUTO
                : OrderRefundRequestService::BRANCH_REQUEST,
            'gate_statuses' => $this->cancellationService->operatorCancellableStatusValues($order),
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
            // 취소 확정 여부 — 취소신청서는 환불이 집행된 순간에만 생긴다(D3). 자동 전이가 완료까지
            // 갔으면 환불신청서가 그 문서를 가리킨다(N12). 기본 운영(자동 전이 꺼짐)에서는 거짓이다.
            'cancelled' => $cancel !== null || ($refund !== null && $refund->order_cancel_id !== null),
        ];
    }

    /**
     * 취소 요청을 클레임 신청 아이템 형태로 바꿉니다. (전체취소 = 잔여 활성 상품 전부)
     *
     * 전체취소에도 상품 목록을 명시해 넘긴다 — 클레임 신청 경로는 "무엇을 얼마나" 를 반드시
     * 받아야 수량 잠금·금액 산정이 성립하고, 그 산정이 곧 환불신청서의 금액이 된다.
     *
     * @param  Order  $order  대상 주문
     * @param  bool  $fullCancel  전체취소 여부
     * @param  array<int, array{order_option_id: int|string, cancel_quantity: int|string}>  $cancelItems  부분취소 대상
     * @return array<int, array{order_option_id: int, quantity: int}> 신청 아이템
     */
    public function toClaimItems(Order $order, bool $fullCancel, array $cancelItems): array
    {
        if (! $fullCancel) {
            return array_map(
                fn (array $item): array => [
                    'order_option_id' => (int) $item['order_option_id'],
                    'quantity' => (int) $item['cancel_quantity'],
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
}
