<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ActivityLogSampleSeeder;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimImageUploaderTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyClaimAddress;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\ExchangeDifferenceRefundPlanner;
use Modules\Sirsoft\Ecommerce\Services\SequenceService;

/**
 * 클레임(교환·반품·환불·취소 재활성) 샘플 시더
 *
 * 1.3.0 의 클레임 축을 화면에서 한 번씩 볼 수 있도록 상태별 신청서를 만든다. 상태 전이 서비스를 부르지 않고
 * 각 단계가 남기는 문서를 직접 기록한다 — 서비스를 부르면 고객 알림·PG 환불 호출이 나가기 때문이다.
 * 번호는 SequenceService 로 채번하고, 사유는 클레임 사유 카탈로그, 회수지는 배송정책의 클레임 회수지 스냅샷,
 * 귀책별 배송비(고객 귀책 반품 3,000 · 교환 왕복 6,000, 판매자 귀책 0), 부모 주문 상태는
 * OrderStatusEnum::deriveParentStatus 규칙을 따른다.
 *
 * 샘플 14건: 교환 신청(첨부) · 교환 회수중 · 교환 재발송중 · 교환 완료 · 상위 옵션 교환(추가결제 완료) ·
 * 선교환 회수 지연 · 교환품 반품(교환 차액 환급) · 반품 신청(운영자 대리) · 반품 완료+환불 ·
 * 환불 마일리지 지급(수단 변경, 마일리지 환급+현금분 지급) · 환불 직접 신청 · 환불 보류 ·
 * 철회·반려(교환 철회, 반품 반려) · 부분 수량 반품 + 취소 재활성 1건.
 *
 * MileageSeeder 다음, NotificationLogSeeder 앞에서 실행한다. 주문이 모자라면 해당 사례만 건너뛴다.
 */
class ClaimSeeder extends Seeder
{
    /**
     * 고객 귀책 반품 배송비 (편도)
     */
    private const RETURN_FEE = 3000;

    /**
     * 고객 귀책 교환 배송비 (왕복)
     */
    private const EXCHANGE_FEE = 6000;

    /**
     * 이미 클레임에 쓴 주문 id
     *
     * @var array<int, true>
     */
    private array $usedOrderIds = [];

    /**
     * 처리 관리자
     */
    private ?User $admin = null;

    /**
     * 만든 사례 수
     */
    private int $created = 0;

    /**
     * 샘플 재시드(DatabaseSeeder --sample) 흐름 안에서 불렸는지
     */
    private bool $withinSampleReseed = false;

    /**
     * 샘플 재시드 흐름 안에서 실행됨을 표시합니다 (DatabaseSeeder 가 호출).
     *
     * @param  bool  $value  재시드 흐름 여부
     * @return static 이 시더
     */
    public function setWithinSampleReseed(bool $value): static
    {
        $this->withinSampleReseed = $value;

        return $this;
    }

    /**
     * 시더 실행
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command->info('클레임 샘플 생성을 시작합니다.');

        // 이 시더는 지우고 다시 만드는 대신 **있는 주문**에 클레임을 붙이고 주문 상태·마일리지를 바꾼다.
        // 샘플 재시드가 방금 만든 주문이 아니면 실제 고객 주문일 수 있으므로, 단독 실행은 건너뛴다.
        if (! $this->withinSampleReseed) {
            $this->command->warn('  - 클레임 샘플은 샘플 재시드(module:seed sirsoft-ecommerce --sample)에서만 만듭니다. 기존 주문을 바꾸지 않도록 건너뜁니다.');

            return;
        }

        $this->admin = User::whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->orderBy('id')->first();
        if ($this->admin === null) {
            $this->command->warn('  - 관리자 계정이 없어 클레임 샘플을 건너뜁니다.');

            return;
        }

        $cases = [
            // 마일리지를 쓴 배송완료 주문은 드물다 — 다른 사례가 먼저 가져가지 않게 맨 앞에서 고른다
            '환불 마일리지 지급' => fn () => $this->refundToMileageCase(),
            '교환 신청(첨부)' => fn () => $this->exchangeCase(ExchangeStatusEnum::REQUESTED, attach: true),
            '교환 회수중' => fn () => $this->exchangeCase(ExchangeStatusEnum::COLLECTING, sellerFault: true),
            '교환 재발송중' => fn () => $this->exchangeCase(ExchangeStatusEnum::REDELIVERING),
            '교환 완료' => fn () => $this->exchangeCase(ExchangeStatusEnum::COMPLETED, optionChange: true),
            '상위 옵션 교환(추가결제)' => fn () => $this->exchangeCase(ExchangeStatusEnum::COMPLETED, optionChange: true, upgrade: true, keepForReturn: true),
            '선교환 회수 지연' => fn () => $this->exchangeCase(ExchangeStatusEnum::REDELIVERED, advance: true),
            '교환품 반품(차액 환급)' => fn () => $this->returnOfExchangedItem(),
            '반품 신청(운영자 대리)' => fn () => $this->returnCase(ReturnStatusEnum::REQUESTED, byAdmin: true),
            '반품 완료+환불' => fn () => $this->returnCase(ReturnStatusEnum::COMPLETED),
            '환불 직접 신청' => fn () => $this->refundRequestCase(RefundStatusEnum::REQUESTED, RefundSourceTypeEnum::DIRECT),
            '환불 보류' => fn () => $this->refundRequestCase(RefundStatusEnum::ON_HOLD, RefundSourceTypeEnum::ADMIN),
            '철회·반려' => fn () => $this->withdrawnAndRejectedCases(),
            '부분 수량 반품·취소 재활성' => fn () => $this->partialAndReactivationCases(),
        ];

        foreach ($cases as $label => $case) {
            $done = $case();
            $this->command->line(($done ? '  - 생성: ' : '  - 건너뜀(대상 주문 없음): ').$label);
            $this->created += $done ? 1 : 0;
        }

        $this->command->info("클레임 샘플 {$this->created}/".count($cases).'건을 만들었습니다.');
    }

    /**
     * 이번 상위 옵션 교환 (교환품 반품 사례가 이어 쓴다)
     */
    private ?OrderExchange $upgradeExchange = null;

    // ──────────────────────────────────────────────
    // 교환
    // ──────────────────────────────────────────────

    /**
     * 교환 신청서를 원하는 단계까지 만듭니다.
     *
     * @param  ExchangeStatusEnum  $status  목표 상태
     * @param  bool  $attach  첨부 이미지 1장
     * @param  bool  $sellerFault  판매자 귀책(불량) — 배송비 0
     * @param  bool  $optionChange  다른 옵션으로 교환
     * @param  bool  $upgrade  더 비싼 옵션(추가 결제 완료)
     * @param  bool  $advance  선교환(회수 전에 먼저 발송) + 회수 지연
     * @param  bool  $keepForReturn  교환품 반품 사례가 쓰도록 보관
     * @return bool 만들었으면 true
     */
    private function exchangeCase(
        ExchangeStatusEnum $status,
        bool $attach = false,
        bool $sellerFault = false,
        bool $optionChange = false,
        bool $upgrade = false,
        bool $advance = false,
        bool $keepForReturn = false,
    ): bool {
        $order = $this->takeOrder([OrderStatusEnum::DELIVERED]);
        if ($order === null) {
            return false;
        }

        $option = $order->options->first();
        $target = $optionChange ? $this->siblingOption($option, $upgrade) : null;
        if ($optionChange && $target === null) {
            $optionChange = false;
        }

        $reason = $this->reason('exchange', $sellerFault ? 'defective_product' : ($optionChange ? 'size_mismatch' : 'color_mismatch'));
        $fee = $sellerFault ? 0 : self::EXCHANGE_FEE;
        $unitPrice = (float) $option->unit_price;
        $newUnitPrice = $target ? (float) $option->unit_price - (float) $this->optionAdjustment($option) + (float) $target->price_adjustment : $unitPrice;
        $quantity = (int) $option->quantity;
        $difference = $upgrade ? max(1000.0, ($newUnitPrice - $unitPrice) * $quantity) : 0.0;
        if ($upgrade && $newUnitPrice <= $unitPrice) {
            $newUnitPrice = $unitPrice + $difference / $quantity;
        }
        $totalCharge = $fee + $difference;

        $t = $this->claimTimeline($order);
        $originalStatus = $option->option_status;
        $courseOption = $this->moveOption($option, OrderStatusEnum::EXCHANGE_IN_PROGRESS, $quantity, $t['requested']);

        $exchange = $this->create(OrderExchange::class, [
            'order_id' => $order->id,
            'exchange_number' => $this->sequence(SequenceType::EXCHANGE),
            'exchange_status' => $status,
            'exchange_mode' => $advance ? ExchangeModeEnum::ADVANCE : ExchangeModeEnum::STANDARD,
            'exchange_mode_set_by' => $advance ? $this->admin->id : null,
            'exchange_mode_set_at' => $advance ? $t['approved'] : null,
            'collection_due_at' => $advance ? $t['approved']->copy()->addDays(3)->min(now()->subDay()) : null,
            'collection_overdue_notified_at' => $advance ? now()->subHours(rand(1, 12)) : null,
            'reason_code' => $reason?->code ?? 'size_mismatch',
            'reason_detail' => $optionChange ? '한 치수 큰 것으로 바꾸고 싶어요.' : null,
            'fault_type' => $this->faultOf($reason),
            'charge_method' => $totalCharge > 0 ? ($upgrade ? ClaimChargeMethodEnum::PAY_ON_REQUEST : ClaimChargeMethodEnum::PAY_ON_APPROVAL) : null,
            'shipping_fee_mode' => ClaimShippingFeeModeEnum::FIXED,
            'claim_shipping_fee' => $fee,
            'mc_claim_shipping_fee' => $fee > 0 ? $this->mc($fee) : null,
            'shipping_fee_snapshot' => ['mode' => 'fixed', 'fee' => $fee, 'fault_type' => $reason?->fault_type?->value],
            'additional_product_amount' => $difference,
            'refund_product_amount' => 0,
            'total_charge_amount' => $totalCharge,
            'mc_total_charge_amount' => $this->mc($totalCharge),
            'currency_code' => $order->currency,
            'refund_priority' => RefundPriorityEnum::PG_FIRST,
            'items_snapshot' => [$this->itemSnapshot($option, $quantity) + [
                'new_product_option_id' => $target?->id,
                'new_option_name' => $target?->option_name,
                'new_unit_price' => $newUnitPrice,
                'price_difference' => $difference,
            ]],
            'return_address_snapshot' => $this->returnAddress($option, ClaimTypeEnum::EXCHANGE),
            'redelivery_address_snapshot' => $this->redeliveryAddress($order),
            'requested_by' => $order->user_id,
            'processed_by' => $status === ExchangeStatusEnum::REQUESTED ? null : $this->admin->id,
            'approved_at' => $status === ExchangeStatusEnum::REQUESTED ? null : $t['approved'],
            'collection_completed_at' => $this->reached($status, ExchangeStatusEnum::COLLECTED) && ! $advance ? $t['collected'] : null,
            'redelivered_at' => $this->reached($status, ExchangeStatusEnum::REDELIVERED) ? $t['redelivered'] : null,
            'completed_at' => $status === ExchangeStatusEnum::COMPLETED ? $t['completed'] : null,
            'created_at' => $t['requested'],
            'updated_at' => $t['last'],
        ]);

        $carrierId = $this->carrierId();
        $collecting = $this->reached($status, ExchangeStatusEnum::COLLECTING) && ! $advance;
        $redelivering = $this->reached($status, ExchangeStatusEnum::REDELIVERING) || $advance;

        $item = $this->create(OrderExchangeOption::class, [
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $courseOption->id,
            'option_status' => $status,
            'exchange_quantity' => $quantity,
            'original_quantity' => $quantity,
            'original_option_status' => $originalStatus,
            'unit_price' => $unitPrice,
            'new_product_option_id' => $target?->id,
            'new_option_snapshot' => $target ? $target->toSnapshotArray() : null,
            'new_unit_price' => $newUnitPrice,
            'price_difference' => $difference,
            'mc_price_difference' => $this->mc($difference),
            'is_new_stock_deducted' => $this->reached($status, ExchangeStatusEnum::APPROVED),
            'collection_carrier_id' => $collecting ? $carrierId : null,
            'collection_tracking_number' => $collecting ? $this->trackingNumber() : null,
            'collection_shipped_at' => $collecting ? $t['collecting'] : null,
            'collected_at' => $this->reached($status, ExchangeStatusEnum::COLLECTED) && ! $advance ? $t['collected'] : null,
            'redelivery_carrier_id' => $redelivering ? $carrierId : null,
            'redelivery_tracking_number' => $redelivering ? $this->trackingNumber() : null,
            'redelivery_shipped_at' => $redelivering ? $t['redelivering'] : null,
            'redelivered_at' => $this->reached($status, ExchangeStatusEnum::REDELIVERED) ? $t['redelivered'] : null,
            'completed_at' => $status === ExchangeStatusEnum::COMPLETED ? $t['completed'] : null,
            'is_stock_restored' => $status === ExchangeStatusEnum::COMPLETED,
            'return_address_snapshot' => $this->returnAddress($option, ClaimTypeEnum::EXCHANGE),
            'processed_by' => $status === ExchangeStatusEnum::REQUESTED ? null : $this->admin->id,
            'created_at' => $t['requested'],
            'updated_at' => $t['last'],
        ]);

        if ($attach) {
            $this->attachImage(ClaimTypeEnum::EXCHANGE, $exchange->id, (int) $order->user_id);
        }

        if ($upgrade) {
            $this->createClaimPayment(ClaimTypeEnum::EXCHANGE, $exchange->id, $order, $totalCharge, $t['requested']->copy()->addMinutes(5));
        }

        // 재발송이 시작되면 후속 주문옵션이 생기고, 완료되면 원 행은 교환완료·후속 행은 배송완료가 된다
        if ($redelivering) {
            $successor = $this->createSuccessor($courseOption, $target, $quantity, $status === ExchangeStatusEnum::COMPLETED
                ? OrderStatusEnum::DELIVERED : OrderStatusEnum::SHIPPING, $t, $carrierId);
            $item->update(['new_order_option_id' => $successor->id]);
        }

        if ($status === ExchangeStatusEnum::COMPLETED) {
            $courseOption->update(['option_status' => OrderStatusEnum::EXCHANGED]);
        }

        $this->syncOrderStatus($order);

        if ($keepForReturn) {
            $this->upgradeExchange = $exchange->fresh(['exchangeOptions']);
        }

        return true;
    }

    /**
     * 상위 옵션으로 교환받은 상품을 반품해 교환 때 낸 차액까지 돌려받는 사례 (exchange_difference_refunds).
     *
     * @return bool 만들었으면 true
     */
    private function returnOfExchangedItem(): bool
    {
        $exchange = $this->upgradeExchange;
        $item = $exchange?->exchangeOptions->first();
        if ($exchange === null || $item?->new_order_option_id === null) {
            return false;
        }

        $order = Order::with(['options', 'shippings', 'shippingAddress', 'payment'])->find($exchange->order_id);
        $successor = $order->options->firstWhere('id', $item->new_order_option_id);
        if ($successor === null) {
            return false;
        }

        $quantity = (int) $successor->quantity;
        $differenceRefunds = app(ExchangeDifferenceRefundPlanner::class)->plan($order, [['order_option_id' => $successor->id, 'quantity' => $quantity]]);

        $this->returnFor($order, $successor, $quantity, ReturnStatusEnum::COMPLETED, false, 'defective_product', $differenceRefunds);

        // 교환 때 낸 차액이 환급됐다 — 추가결제 행에 환급액을 남긴다
        foreach ($differenceRefunds as $entry) {
            ClaimPayment::whereKey($entry['claim_payment_id'])->update([
                'refunded_amount' => (float) $entry['amount'] + (float) $entry['points_amount'],
                'refunded_at' => now()->subHours(rand(1, 24)),
            ]);
        }

        return true;
    }

    // ──────────────────────────────────────────────
    // 반품
    // ──────────────────────────────────────────────

    /**
     * 반품 신청서를 만듭니다.
     *
     * @param  ReturnStatusEnum  $status  목표 상태
     * @param  bool  $byAdmin  운영자 대리 접수
     * @return bool 만들었으면 true
     */
    private function returnCase(ReturnStatusEnum $status, bool $byAdmin = false): bool
    {
        $order = $this->takeOrder([OrderStatusEnum::DELIVERED]);
        if ($order === null) {
            return false;
        }

        $option = $order->options->first();
        $this->returnFor($order, $option, (int) $option->quantity, $status, $byAdmin, 'changed_mind');

        return true;
    }

    /**
     * 반품 신청서(+완료면 환불신청서)를 만듭니다.
     *
     * @param  Order  $order  주문 (options·shippings 로드)
     * @param  OrderOption  $option  반품할 주문옵션
     * @param  int  $quantity  반품 수량
     * @param  ReturnStatusEnum  $status  목표 상태
     * @param  bool  $byAdmin  운영자 대리 접수
     * @param  string  $reasonCode  사유 코드
     * @param  array  $differenceRefunds  교환 차액 환급 계획 (교환품 반품)
     * @return OrderReturn 반품 신청서
     */
    private function returnFor(Order $order, OrderOption $option, int $quantity, ReturnStatusEnum $status, bool $byAdmin, string $reasonCode, array $differenceRefunds = []): OrderReturn
    {
        $reason = $this->reason('return', $reasonCode);
        $sellerFault = $this->faultOf($reason) !== ClaimReasonFaultTypeEnum::CUSTOMER;
        $fee = $sellerFault ? 0 : self::RETURN_FEE;
        $t = $this->claimTimeline($order);
        $originalStatus = $option->option_status;
        $originalQuantity = (int) $option->quantity;
        $subtotal = (float) $option->unit_price * $quantity;

        $courseOption = $this->moveOption($option, OrderStatusEnum::RETURN_IN_PROGRESS, $quantity, $t['requested']);
        $completed = $status === ReturnStatusEnum::COMPLETED;

        $return = $this->create(OrderReturn::class, [
            'order_id' => $order->id,
            'return_number' => $this->sequence(SequenceType::ORDER_RETURN),
            'return_status' => $status,
            'reason_code' => $reason?->code ?? $reasonCode,
            'reason_detail' => null,
            'fault_type' => $this->faultOf($reason),
            'charge_method' => $fee > 0 ? ClaimChargeMethodEnum::DEDUCT_FROM_REFUND : null,
            'shipping_fee_mode' => ClaimShippingFeeModeEnum::FIXED,
            'claim_shipping_fee' => $fee,
            'mc_claim_shipping_fee' => $fee > 0 ? $this->mc($fee) : null,
            'shipping_fee_snapshot' => ['mode' => 'fixed', 'fee' => $fee, 'fault_type' => $reason?->fault_type?->value],
            'total_charge_amount' => $fee,
            'mc_total_charge_amount' => $this->mc($fee),
            'currency_code' => $order->currency,
            'refund_priority' => RefundPriorityEnum::PG_FIRST,
            'items_snapshot' => [$this->itemSnapshot($option, $quantity)],
            'return_address_snapshot' => $this->returnAddress($option, ClaimTypeEnum::RETURN),
            'collection_completed_at' => $completed ? $t['collected'] : null,
            'is_stock_restored' => $completed,
            'requested_by' => $byAdmin ? null : $order->user_id,
            'requested_by_admin' => $byAdmin ? $this->admin->id : null,
            'processed_by' => $status === ReturnStatusEnum::REQUESTED ? null : $this->admin->id,
            'approved_at' => $status === ReturnStatusEnum::REQUESTED ? null : $t['approved'],
            'completed_at' => $completed ? $t['completed'] : null,
            'created_at' => $t['requested'],
            'updated_at' => $t['last'],
        ]);

        $returnOption = $this->create(OrderReturnOption::class, [
            'order_return_id' => $return->id,
            'order_id' => $order->id,
            'order_option_id' => $courseOption->id,
            'option_status' => $status,
            'return_quantity' => $quantity,
            'original_quantity' => $originalQuantity,
            'original_option_status' => $originalStatus,
            'unit_price' => (float) $option->unit_price,
            'subtotal_amount' => $subtotal,
            'collection_carrier_id' => $completed ? $this->carrierId() : null,
            'collection_tracking_number' => $completed ? $this->trackingNumber() : null,
            'collection_shipped_at' => $completed ? $t['collecting'] : null,
            'collected_at' => $completed ? $t['collected'] : null,
            'completed_at' => $completed ? $t['completed'] : null,
            'is_stock_restored' => $completed,
            'return_address_snapshot' => $this->returnAddress($option, ClaimTypeEnum::RETURN),
            'processed_by' => $status === ReturnStatusEnum::REQUESTED ? null : $this->admin->id,
            'created_at' => $t['requested'],
            'updated_at' => $t['last'],
        ]);

        if ($completed) {
            $courseOption->update(['option_status' => OrderStatusEnum::RETURNED]);
            $differenceTotal = array_sum(array_map(fn ($e) => (float) $e['amount'] + (float) $e['points_amount'], $differenceRefunds));
            $refundAmount = max(0.0, (float) $courseOption->subtotal_paid_amount - $fee) + $differenceTotal;
            $refund = $this->createRefund($order, [$courseOption->id => [$quantity, $refundAmount]], RefundStatusEnum::COMPLETED, RefundSourceTypeEnum::RETURN, $return->id, $reason, $t, $refundAmount, 0, $differenceRefunds);
            $returnOption->update(['refund_id' => $refund->id]);
        }

        $this->syncOrderStatus($order);

        return $return;
    }

    // ──────────────────────────────────────────────
    // 환불 (직접 신청 · 보류 · 마일리지 지급)
    // ──────────────────────────────────────────────

    /**
     * 결제 후 배송 전 주문의 환불신청서(직접 신청/운영자 접수)를 만듭니다.
     *
     * @param  RefundStatusEnum  $status  REQUESTED 또는 ON_HOLD
     * @param  RefundSourceTypeEnum  $source  DIRECT(고객) 또는 ADMIN(운영자)
     * @return bool 만들었으면 true
     */
    private function refundRequestCase(RefundStatusEnum $status, RefundSourceTypeEnum $source): bool
    {
        $order = $this->takeOrder([OrderStatusEnum::PAYMENT_COMPLETE, OrderStatusEnum::PREPARING]);
        if ($order === null) {
            return false;
        }

        $reason = $this->reason('refund', $source === RefundSourceTypeEnum::DIRECT ? 'changed_mind' : 'admin_cancel');
        $t = $this->claimTimeline($order, fromPaid: true);
        $items = $order->options->mapWithKeys(fn (OrderOption $o) => [$o->id => [(int) $o->quantity, (float) $o->subtotal_paid_amount]])->all();
        $amount = (float) $order->total_paid_amount;

        $this->createRefund($order, $items, $status, $source, null, $reason, $t, $amount, 0);

        return true;
    }

    /**
     * 반품 환불을 마일리지로 받는 사례 — 운영자가 환불 수단을 마일리지로 바꿨다(refund_method_overridden).
     * 쓴 마일리지는 돌려주고(REFUND_RESTORE), 현금분도 마일리지로 지급한다(REFUND_CREDIT, 무기한).
     *
     * @return bool 만들었으면 true
     */
    private function refundToMileageCase(): bool
    {
        $order = $this->takeOrder([OrderStatusEnum::DELIVERED], preferPointsUsed: true, needCashPaid: true);
        if ($order === null) {
            return false;
        }

        $option = $order->options->first(fn (OrderOption $o) => (float) $o->subtotal_points_used_amount > 0 && (float) $o->subtotal_paid_amount > 0)
            ?? $order->options->first(fn (OrderOption $o) => (float) $o->subtotal_paid_amount > 0)
            ?? $order->options->first();

        // 마일리지를 쓴 주문이 하나도 없으면(마일리지 샘플이 잔액 부족으로 사용을 만들지 못한 경우) 이 주문에
        // 사용을 기록한다 — 그래야 「쓴 마일리지 환급(REFUND_RESTORE) + 현금분 마일리지 지급(REFUND_CREDIT)」이 함께 보인다.
        if ((float) $option->subtotal_points_used_amount <= 0) {
            $this->seedPointsUsage($order, $option);
            $order->refresh()->load(['options', 'shippings', 'payment', 'shippingAddress']);
            $option = $order->options->firstWhere('id', $option->id);
        }
        // 판매자 귀책(불량)이라 반품비를 빼지 않는다 — 고객 귀책이면 저가 상품은 반품비를 빼고 현금분이 0원이 되어
        // 「현금분 마일리지 지급」(REFUND_CREDIT) 사례가 생기지 않는다.
        $return = $this->returnFor($order, $option, (int) $option->quantity, ReturnStatusEnum::COMPLETED, false, 'defective_product');

        $refund = OrderRefund::where('refund_source_type', RefundSourceTypeEnum::RETURN->value)->where('refund_source_id', $return->id)->first();
        if ($refund === null) {
            return true;
        }

        $points = (float) $order->options->firstWhere('id', $option->id)?->subtotal_points_used_amount;
        $cash = (float) $refund->refund_amount;
        $refund->update([
            'refund_method' => RefundMethodEnum::POINTS,
            'refund_method_overridden' => true,
            'refund_points_amount' => $points,
            'mc_refund_points_amount' => $this->mc($points),
        ]);

        $at = $refund->refunded_at instanceof Carbon ? $refund->refunded_at->copy() : now();
        if ($points > 0) {
            $this->insertMileage($order, MileageTransactionTypeEnum::REFUND_RESTORE, $points, $refund->id, $at, $at->copy()->addYear());
        }
        $this->insertMileage($order, MileageTransactionTypeEnum::REFUND_CREDIT, $cash, $refund->id, $at->copy()->addSecond(), null);

        $cache = app(MileageBalanceRepositoryInterface::class);
        $cache->recalculateForUser((int) $order->user_id, $order->currency);

        return true;
    }

    /**
     * 주문 상품 하나에 마일리지 사용을 기록합니다 (관리자 사전 지급 → 주문 때 사용, 결제금액 차감).
     *
     * 금액 반영은 MileageSeeder 의 사용 기록과 같은 식이다 — 사용액만큼 옵션·주문·결제 행의 현금 금액이 준다.
     *
     * @param  Order  $order  주문 (payment 로드)
     * @param  OrderOption  $option  사용을 기록할 주문옵션
     * @return void 반환값 없음
     */
    private function seedPointsUsage(Order $order, OrderOption $option): void
    {
        $paid = (float) $option->subtotal_paid_amount;
        $use = (int) (floor(min($paid * 0.3, 5000) / 10) * 10);
        if ($use < 10) {
            return;
        }

        $orderedAt = $order->ordered_at instanceof Carbon ? $order->ordered_at->copy() : now()->subDays(7);
        $grantedAt = $orderedAt->copy()->subDays(3);
        $currency = $order->currency;
        $balance = (float) MileageTransaction::where('user_id', $order->user_id)->where('currency', $currency)
            ->whereNull('expired_at')->where('remaining_amount', '>', 0)->sum('remaining_amount');

        $earn = MileageTransaction::create([
            'user_id' => $order->user_id,
            'currency' => $currency,
            'type' => MileageTransactionTypeEnum::ADMIN_EARN,
            'amount' => $use,
            'remaining_amount' => 0,
            'balance_after' => $balance + $use,
            'granted_by' => $this->admin->id,
            'memo' => '사전 적립금 지급',
            'description' => __('sirsoft-ecommerce::activity_log.description.mileage_admin_earn', ['amount' => ecommerce_format_price($use, $currency)]),
            'expires_at' => $grantedAt->copy()->addYear(),
        ]);
        $earn->created_at = $grantedAt;
        $earn->updated_at = $orderedAt;
        $earn->save();

        $usage = MileageTransaction::create([
            'user_id' => $order->user_id,
            'currency' => $currency,
            'type' => MileageTransactionTypeEnum::ORDER_USE,
            'amount' => -$use,
            'remaining_amount' => 0,
            'balance_after' => $balance,
            'order_id' => $order->id,
            'source_transaction_id' => $earn->id,
            'description' => __('sirsoft-ecommerce::activity_log.description.mileage_use', ['amount' => ecommerce_format_price($use, $currency)]),
        ]);
        $usage->created_at = $orderedAt;
        $usage->updated_at = $orderedAt;
        $usage->save();

        $newPaid = $paid - $use;
        $option->update([
            'subtotal_points_used_amount' => $use,
            'mc_subtotal_points_used_amount' => $this->mc($use),
            'subtotal_paid_amount' => $newPaid,
            'mc_final_amount' => $this->mc($newPaid),
            'subtotal_tax_amount' => round($newPaid / 11, 2),
        ]);

        $totalAmount = max(0.0, (float) $order->total_amount - $use);
        $totalPaid = max(0.0, (float) $order->total_paid_amount - $use);
        $order->update([
            'total_points_used_amount' => (float) $order->total_points_used_amount + $use,
            'mc_total_points_used_amount' => $this->mc((float) $order->total_points_used_amount + $use),
            'is_mileage_deducted' => true,
            'total_amount' => $totalAmount,
            'mc_total_amount' => $this->mc($totalAmount),
            'total_tax_amount' => round($totalAmount / 11, 2),
            'total_paid_amount' => $totalPaid,
            'mc_total_paid_amount' => $this->mc($totalPaid),
        ]);

        $payment = $order->payment;
        if ($payment) {
            $payment->update([
                'paid_amount_local' => max(0.0, (float) $payment->paid_amount_local - $use),
                'paid_amount_base' => max(0.0, (float) $payment->paid_amount_base - $use),
                'mc_paid_amount' => $this->mc(max(0.0, (float) $payment->paid_amount_local - $use)),
            ]);
        }
    }

    // ──────────────────────────────────────────────
    // 철회·반려 · 부분 수량 · 취소 재활성
    // ──────────────────────────────────────────────

    /**
     * 교환 철회(신청자) 1건 + 반품 반려(운영자) 1건 — 상품은 원래 상태로 돌아간다.
     *
     * @return bool 하나라도 만들었으면 true
     */
    private function withdrawnAndRejectedCases(): bool
    {
        $made = false;

        $order = $this->takeOrder([OrderStatusEnum::DELIVERED]);
        if ($order !== null) {
            $option = $order->options->first();
            $t = $this->claimTimeline($order);
            $reason = $this->reason('exchange', 'color_mismatch');
            $exchange = $this->create(OrderExchange::class, [
                'order_id' => $order->id,
                'exchange_number' => $this->sequence(SequenceType::EXCHANGE),
                'exchange_status' => ExchangeStatusEnum::WITHDRAWN,
                'exchange_mode' => ExchangeModeEnum::STANDARD,
                'reason_code' => $reason?->code ?? 'color_mismatch',
                'fault_type' => $this->faultOf($reason),
                'shipping_fee_mode' => ClaimShippingFeeModeEnum::FIXED,
                'claim_shipping_fee' => self::EXCHANGE_FEE,
                'total_charge_amount' => self::EXCHANGE_FEE,
                'mc_total_charge_amount' => $this->mc(self::EXCHANGE_FEE),
                'currency_code' => $order->currency,
                'items_snapshot' => [$this->itemSnapshot($option, (int) $option->quantity)],
                'return_address_snapshot' => $this->returnAddress($option, ClaimTypeEnum::EXCHANGE),
                'requested_by' => $order->user_id,
                'withdrawn_at' => $t['approved'],
                'withdrawn_by_admin' => null, // 고객 본인 철회 (운영자 대리 철회면 운영자 id)
                'withdraw_reason' => '그냥 쓰기로 했어요.',
                'created_at' => $t['requested'],
                'updated_at' => $t['approved'],
            ]);
            $this->create(OrderExchangeOption::class, [
                'order_exchange_id' => $exchange->id,
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'option_status' => ExchangeStatusEnum::WITHDRAWN,
                'exchange_quantity' => (int) $option->quantity,
                'original_quantity' => (int) $option->quantity,
                'original_option_status' => $option->option_status,
                'unit_price' => (float) $option->unit_price,
                'new_unit_price' => (float) $option->unit_price,
                'price_difference' => 0,
                'created_at' => $t['requested'],
                'updated_at' => $t['approved'],
            ]);
            $made = true;
        }

        $order = $this->takeOrder([OrderStatusEnum::DELIVERED]);
        if ($order !== null) {
            $option = $order->options->first();
            $t = $this->claimTimeline($order);
            $reason = $this->reason('return', 'changed_mind');
            $return = $this->create(OrderReturn::class, [
                'order_id' => $order->id,
                'return_number' => $this->sequence(SequenceType::ORDER_RETURN),
                'return_status' => ReturnStatusEnum::REJECTED,
                'reason_code' => $reason?->code ?? 'changed_mind',
                'fault_type' => $this->faultOf($reason),
                'shipping_fee_mode' => ClaimShippingFeeModeEnum::FIXED,
                'claim_shipping_fee' => self::RETURN_FEE,
                'total_charge_amount' => self::RETURN_FEE,
                'mc_total_charge_amount' => $this->mc(self::RETURN_FEE),
                'currency_code' => $order->currency,
                'items_snapshot' => [$this->itemSnapshot($option, (int) $option->quantity)],
                'return_address_snapshot' => $this->returnAddress($option, ClaimTypeEnum::RETURN),
                'requested_by' => $order->user_id,
                'processed_by' => $this->admin->id,
                'rejected_at' => $t['approved'],
                'reject_reason' => '사용 흔적이 확인되어 반품이 어렵습니다.',
                'created_at' => $t['requested'],
                'updated_at' => $t['approved'],
            ]);
            $this->create(OrderReturnOption::class, [
                'order_return_id' => $return->id,
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'option_status' => ReturnStatusEnum::REJECTED,
                'return_quantity' => (int) $option->quantity,
                'original_quantity' => (int) $option->quantity,
                'original_option_status' => $option->option_status,
                'unit_price' => (float) $option->unit_price,
                'subtotal_amount' => (float) $option->unit_price * (int) $option->quantity,
                'reject_reason' => '사용 흔적이 확인되어 반품이 어렵습니다.',
                'processed_by' => $this->admin->id,
                'created_at' => $t['requested'],
                'updated_at' => $t['approved'],
            ]);
            $made = true;
        }

        return $made;
    }

    /**
     * 부분 수량 반품(수량 2 이상 중 1개) 1건 + 입금 전 취소 주문의 취소 재활성 1건.
     *
     * @return bool 하나라도 만들었으면 true
     */
    private function partialAndReactivationCases(): bool
    {
        $made = false;

        $order = $this->takeOrder([OrderStatusEnum::DELIVERED], needMultiQuantity: true);
        if ($order !== null) {
            $option = $order->options->first(fn (OrderOption $o) => (int) $o->quantity >= 2) ?? $order->options->first();
            $this->returnFor($order, $option, 1, ReturnStatusEnum::COLLECTING, false, 'size_mismatch');
            $made = true;
        }

        $cancel = OrderCancel::query()
            ->whereNull('reactivated_at')
            ->whereHas('order', fn ($q) => $q->where('order_status', OrderStatusEnum::CANCELLED->value)->whereNull('paid_at')->whereNotIn('id', array_keys($this->usedOrderIds)))
            ->first();
        if ($cancel !== null) {
            $this->reactivate($cancel);
            $made = true;
        }

        return $made;
    }

    /**
     * 입금 전에 취소된 주문을 운영자가 되살린 상태로 만듭니다 (취소신청서에 재활성 수량·시각 기록).
     *
     * @param  OrderCancel  $cancel  취소신청서
     * @return void 반환값 없음
     */
    private function reactivate(OrderCancel $cancel): void
    {
        $order = Order::with(['options', 'payment'])->find($cancel->order_id);
        $this->usedOrderIds[$order->id] = true;
        $at = ($cancel->cancelled_at instanceof Carbon ? $cancel->cancelled_at->copy() : now()->subDay())->addHours(rand(2, 20))->min(now());

        $quantity = 0;
        foreach (OrderCancelOption::where('order_cancel_id', $cancel->id)->get() as $row) {
            $row->update(['reactivated_quantity' => (int) $row->cancel_quantity]);
            $quantity += (int) $row->cancel_quantity;
        }
        $cancel->update(['reactivated_quantity' => $quantity, 'reactivated_at' => $at]);

        foreach ($order->options as $option) {
            $option->update(['option_status' => OrderStatusEnum::PENDING_PAYMENT, 'cancelled_quantity' => 0, 'cancel_reason' => null]);
        }

        $order->update(['order_status' => OrderStatusEnum::PENDING_PAYMENT, 'total_due_amount' => (float) $order->total_amount]);
        $order->payment?->update([
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'cancelled_at' => null,
            'cancel_reason' => null,
            'vbank_due_at' => $order->payment->vbank_due_at ? now()->addDays(2) : null,
            'deposit_due_at' => $order->payment->deposit_due_at ? now()->addDays(2) : null,
        ]);
    }

    // ──────────────────────────────────────────────
    // 문서 공용
    // ──────────────────────────────────────────────

    /**
     * 환불신청서와 옵션별 환불 행을 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  array<int, array{0: int, 1: float}>  $items  주문옵션 id => [수량, 환불액]
     * @param  RefundStatusEnum  $status  환불 상태
     * @param  RefundSourceTypeEnum  $source  출처
     * @param  int|null  $sourceId  출처 신청서 id (반품·교환)
     * @param  ClaimReason|null  $reason  사유
     * @param  array<string, Carbon>  $t  시각 사슬
     * @param  float  $amount  현금 환불액
     * @param  float  $points  마일리지 환불액
     * @param  array  $differenceRefunds  교환 차액 환급 계획
     * @return OrderRefund 환불신청서
     */
    private function createRefund(Order $order, array $items, RefundStatusEnum $status, RefundSourceTypeEnum $source, ?int $sourceId, ?ClaimReason $reason, array $t, float $amount, float $points, array $differenceRefunds = []): OrderRefund
    {
        $completed = $status === RefundStatusEnum::COMPLETED;
        $method = $order->payment?->payment_method === 'dbank' ? RefundMethodEnum::BANK : RefundMethodEnum::PG;

        $refund = $this->create(OrderRefund::class, [
            'order_id' => $order->id,
            'refund_source_type' => $source,
            'refund_source_id' => $sourceId,
            'refund_number' => $this->sequence(SequenceType::REFUND),
            'refund_status' => $status,
            'refund_method' => $method,
            'refund_method_overridden' => false,
            'reason_code' => $reason?->code ?? 'etc',
            'reason_detail' => $status === RefundStatusEnum::ON_HOLD ? '재고 확인 후 처리 예정' : null,
            'fault_type' => $this->faultOf($reason),
            'requested_by' => $source === RefundSourceTypeEnum::ADMIN ? $this->admin->id : $order->user_id,
            'refund_amount' => $amount,
            'refund_points_amount' => $points,
            'refund_shipping_amount' => 0,
            'exchange_difference_refunds' => $differenceRefunds ?: null,
            'refund_priority' => RefundPriorityEnum::PG_FIRST,
            'mc_refund_amount' => $this->mc($amount),
            'mc_refund_points_amount' => $this->mc($points),
            'mc_refund_shipping_amount' => $this->mc(0),
            'refund_bank_code' => $method === RefundMethodEnum::BANK ? '088' : null,
            'refund_bank_account' => $method === RefundMethodEnum::BANK ? '110-'.rand(100, 999).'-'.rand(100000, 999999) : null,
            'refund_bank_holder' => $method === RefundMethodEnum::BANK ? $order->shippingAddress?->orderer_name : null,
            'pg_transaction_id' => $method === RefundMethodEnum::PG && $completed ? $order->payment?->transaction_id : null,
            'approved_at' => $completed ? $t['approved'] : null,
            'on_hold_at' => $status === RefundStatusEnum::ON_HOLD ? $t['approved'] : null,
            'refunded_at' => $completed ? $t['completed'] : null,
            'processed_by' => $status === RefundStatusEnum::REQUESTED ? null : $this->admin->id,
            'created_at' => $t['requested'],
            'updated_at' => $t['last'],
        ]);

        foreach ($items as $optionId => [$quantity, $optionAmount]) {
            $option = OrderOption::find($optionId);
            $this->create(OrderRefundOption::class, [
                'order_refund_id' => $refund->id,
                'order_id' => $order->id,
                'order_option_id' => $optionId,
                'option_status' => $status,
                'quantity' => $quantity,
                'unit_price' => (float) $option?->unit_price,
                'subtotal_amount' => (float) $option?->unit_price * $quantity,
                'discount_amount' => (float) $option?->subtotal_discount_amount,
                'shipping_amount' => 0,
                'refund_amount' => $optionAmount,
                'completed_at' => $completed ? $t['completed'] : null,
                'processed_by' => $completed ? $this->admin->id : null,
                'created_at' => $t['requested'],
                'updated_at' => $t['last'],
            ]);
        }

        if ($completed) {
            $order->update([
                'total_refunded_amount' => (float) $order->total_refunded_amount + $amount,
                'total_refunded_points_amount' => (float) $order->total_refunded_points_amount + $points,
            ]);
        }

        return $refund;
    }

    /**
     * 추가 결제(교환 차액·배송비) 결제 완료 행을 만듭니다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  int  $claimId  신청서 id
     * @param  Order  $order  주문
     * @param  float  $amount  결제액
     * @param  Carbon  $paidAt  결제 시각
     * @return void 반환값 없음
     */
    private function createClaimPayment(ClaimTypeEnum $type, int $claimId, Order $order, float $amount, Carbon $paidAt): void
    {
        $pg = $order->payment?->pg_provider;
        $isPg = $pg !== null && ! in_array($pg, ['manual', 'internal', 'none'], true);

        $this->create(ClaimPayment::class, [
            'payable_type' => $type->value,
            'payable_id' => $claimId,
            'order_id' => $order->id,
            'payment_number' => $this->sequence(SequenceType::CLAIM_PAYMENT),
            'payment_method' => $isPg ? 'card' : 'dbank',
            'pg_provider' => $isPg ? $pg : 'manual',
            'payment_status' => ClaimPaymentStatusEnum::PAID,
            'amount' => $amount,
            'points_amount' => 0,
            'currency_code' => $order->currency,
            'mc_amount' => $this->mc($amount),
            'transaction_id' => $isPg ? OrderSeeder::SAMPLE_TRANSACTION_PREFIX.strtoupper(bin2hex(random_bytes(8))) : null,
            'raw_response' => $isPg ? ['is_test_mode' => true] : null,
            'paid_at' => $paidAt->min(now()),
            'created_at' => $paidAt->min(now()),
            'updated_at' => $paidAt->min(now()),
        ]);
    }

    /**
     * 교환 재발송 후속 주문옵션을 만듭니다 (원 행 금액 승계, 상품옵션 정체만 교체, 배송 행 복제).
     *
     * @param  OrderOption  $original  원 주문옵션 (교환 코스 행)
     * @param  ProductOption|null  $target  새 상품옵션 (같은 옵션 교환이면 null)
     * @param  int  $quantity  수량
     * @param  OrderStatusEnum  $status  후속 행 상태 (배송중/배송완료)
     * @param  array<string, Carbon>  $t  시각 사슬
     * @param  int|null  $carrierId  재발송 택배사
     * @return OrderOption 후속 주문옵션
     */
    private function createSuccessor(OrderOption $original, ?ProductOption $target, int $quantity, OrderStatusEnum $status, array $t, ?int $carrierId): OrderOption
    {
        $successor = $original->replicate(['parent_option_id', 'cancelled_quantity', 'cancel_reason', 'confirmed_at', 'delivered_at']);
        $successor->option_status = $status;
        $successor->is_stock_deducted = true;
        $successor->source_type = OrderOptionSourceTypeEnum::EXCHANGE;
        $successor->source_option_id = $original->id;
        $successor->parent_option_id = null;
        $successor->cancelled_quantity = 0;
        $successor->quantity = $quantity;
        $successor->delivered_at = $status === OrderStatusEnum::DELIVERED ? $t['redelivered'] : null;

        if ($target) {
            $successor->product_option_id = $target->id;
            $successor->sku = $target->sku;
            $successor->product_option_name = $target->option_name ?? [];
            $successor->option_name = $target->option_name ?? [];
            $successor->option_snapshot = array_merge($target->toSnapshotArray(), [
                'price_adjustment' => ($original->option_snapshot ?? [])['price_adjustment'] ?? $target->price_adjustment,
            ]);
        }

        $successor->created_at = $t['redelivering'];
        $successor->updated_at = $t['last'];
        $successor->save();

        $shipping = OrderShipping::where('order_option_id', $original->id)->first();
        if ($shipping) {
            $copy = $shipping->replicate(['shipped_at', 'estimated_arrival_at', 'delivered_at', 'confirmed_at']);
            $copy->order_option_id = $successor->id;
            $copy->base_shipping_amount = 0;
            $copy->extra_shipping_amount = 0;
            $copy->total_shipping_amount = 0;
            $copy->shipping_discount_amount = 0;
            $copy->mc_base_shipping_amount = null;
            $copy->mc_total_shipping_amount = null;
            $copy->shipping_status = $status === OrderStatusEnum::DELIVERED ? ShippingStatusEnum::DELIVERED : ShippingStatusEnum::SHIPPED;
            $copy->carrier_id = $carrierId;
            $copy->tracking_number = $carrierId ? $this->trackingNumber() : null;
            $copy->shipped_at = $t['redelivering'];
            $copy->delivered_at = $status === OrderStatusEnum::DELIVERED ? $t['redelivered'] : null;
            $copy->save();
        }

        return $successor;
    }

    /**
     * 주문옵션을 클레임 코스 상태로 옮깁니다. 수량 일부면 행을 나눠 그 조각만 옮깁니다(금액은 수량 비례).
     *
     * @param  OrderOption  $option  주문옵션
     * @param  OrderStatusEnum  $status  옮길 상태
     * @param  int  $quantity  옮길 수량
     * @param  Carbon  $at  전이 시각
     * @return OrderOption 그 상태를 갖게 된 행
     */
    private function moveOption(OrderOption $option, OrderStatusEnum $status, int $quantity, Carbon $at): OrderOption
    {
        if ($quantity >= (int) $option->quantity) {
            $option->update(['option_status' => $status]);

            return $option;
        }

        $ratio = $quantity / (int) $option->quantity;
        $amountFields = [
            'subtotal_price', 'subtotal_discount_amount', 'coupon_discount_amount', 'product_coupon_discount_amount',
            'order_coupon_discount_amount', 'code_discount_amount', 'subtotal_points_used_amount', 'subtotal_deposit_used_amount',
            'subtotal_paid_amount', 'subtotal_tax_amount', 'subtotal_tax_free_amount', 'subtotal_earned_points_amount',
            'subtotal_weight', 'subtotal_volume',
        ];

        $split = $option->replicate();
        $split->quantity = $quantity;
        $split->option_status = $status;
        $split->parent_option_id = $option->id;
        $split->source_type = OrderOptionSourceTypeEnum::SPLIT;
        $remaining = [];
        foreach ($amountFields as $field) {
            $total = (float) $option->{$field};
            $split->{$field} = round($total * $ratio, 2);
            $remaining[$field] = round($total - (float) $split->{$field}, 2);
        }
        $split->mc_final_amount = $this->mc((float) $split->subtotal_paid_amount);
        $split->mc_subtotal_price = $this->mc((float) $split->subtotal_price);
        $split->created_at = $at;
        $split->updated_at = $at;
        $split->save();

        $option->update($remaining + [
            'quantity' => (int) $option->quantity - $quantity,
            'mc_final_amount' => $this->mc($remaining['subtotal_paid_amount']),
            'mc_subtotal_price' => $this->mc($remaining['subtotal_price']),
        ]);

        // 분할 행에도 배송 행을 둔다 — 없으면 이 행의 클레임 배송비 정책을 찾지 못한다 (금액 0)
        $shipping = OrderShipping::where('order_option_id', $option->id)->first();
        if ($shipping) {
            $copy = $shipping->replicate();
            $copy->order_option_id = $split->id;
            $copy->base_shipping_amount = 0;
            $copy->extra_shipping_amount = 0;
            $copy->total_shipping_amount = 0;
            $copy->shipping_discount_amount = 0;
            $copy->mc_base_shipping_amount = null;
            $copy->mc_total_shipping_amount = null;
            $copy->save();
        }

        return $split;
    }

    /**
     * 활성 상품 상태에서 부모 주문 상태를 다시 정합니다 (OrderOptionService::syncParentOrderStatus 와 같은 규칙).
     *
     * @param  Order  $order  주문
     * @return void 반환값 없음
     */
    private function syncOrderStatus(Order $order): void
    {
        $statuses = OrderOption::where('order_id', $order->id)->pluck('option_status')
            ->map(fn ($s) => $s instanceof OrderStatusEnum ? $s->value : (string) $s);

        $active = $statuses->reject(fn ($s) => in_array($s, OrderStatusEnum::syncExcludedValues(), true))->values()->all();

        if ($active === []) {
            $inProgress = $statuses->first(fn ($s) => in_array($s, [OrderStatusEnum::EXCHANGE_IN_PROGRESS->value, OrderStatusEnum::RETURN_IN_PROGRESS->value], true));
            $status = match (true) {
                $inProgress !== null => OrderStatusEnum::from($inProgress),
                $statuses->contains(OrderStatusEnum::RETURNED->value) => OrderStatusEnum::RETURNED,
                $statuses->contains(OrderStatusEnum::EXCHANGED->value) => OrderStatusEnum::EXCHANGED,
                default => OrderStatusEnum::CANCELLED,
            };
        } else {
            $status = OrderStatusEnum::deriveParentStatus($active);
        }

        $order->update(['order_status' => $status]);
    }

    /**
     * 클레임에 쓸 주문을 고릅니다 (회원 주문, 아직 클레임에 안 쓴 주문).
     *
     * @param  array<int, OrderStatusEnum>  $statuses  대상 주문 상태
     * @param  bool  $needMultiQuantity  수량 2 이상 상품이 있어야 함
     * @param  bool  $preferPointsUsed  마일리지를 쓴 주문 우선
     * @param  bool  $needCashPaid  현금 결제분이 남은 상품이 있어야 함
     * @return Order|null 주문 (options·shippings·payment·shippingAddress 로드)
     */
    private function takeOrder(array $statuses, bool $needMultiQuantity = false, bool $preferPointsUsed = false, bool $needCashPaid = false): ?Order
    {
        $query = Order::query()
            ->with(['options', 'shippings', 'payment', 'shippingAddress'])
            ->whereNotNull('user_id')
            ->whereIn('order_status', array_map(fn ($s) => $s->value, $statuses))
            ->whereNotIn('id', array_keys($this->usedOrderIds) ?: [0])
            ->whereDoesntHave('options', fn ($q) => $q->whereIn('option_status', OrderStatusEnum::claimCourseValues()));

        if ($needMultiQuantity) {
            $query->whereHas('options', fn ($q) => $q->where('quantity', '>=', 2));
        }

        // 현금 환불이 생겨야 하는 사례는 남은 결제금액이 있는 상품이 있어야 한다 (마일리지로 전액 결제한 주문 제외)
        if ($needCashPaid) {
            $query->whereHas('options', fn ($q) => $q->where('subtotal_paid_amount', '>=', 1000));
        }

        $order = $preferPointsUsed
            ? (clone $query)->where('total_points_used_amount', '>', 0)->inRandomOrder()->first() ?? $query->inRandomOrder()->first()
            : $query->inRandomOrder()->first();

        if ($order !== null) {
            $this->usedOrderIds[$order->id] = true;
        }

        return $order;
    }

    /**
     * 클레임 시각 사슬 — 배송완료(또는 결제) 뒤 신청, 이후 승인·회수·재발송·완료. 현재 시각을 넘지 않는다.
     *
     * @param  Order  $order  주문
     * @param  bool  $fromPaid  결제 시각 기준 (배송 전 환불)
     * @return array<string, Carbon> 시각 사슬
     */
    private function claimTimeline(Order $order, bool $fromPaid = false): array
    {
        $now = now();
        $deliveredAt = $order->options->pluck('delivered_at')->filter()->max();
        $base = $fromPaid || ! $deliveredAt
            ? ($order->paid_at instanceof Carbon ? $order->paid_at->copy() : $now->copy()->subDays(5))
            : $deliveredAt->copy();

        $step = fn (Carbon $from, int $minHours, int $maxHours) => $from->copy()->addMinutes(rand($minHours * 60, $maxHours * 60))->min($now);

        $t = ['requested' => $step($base, 2, 24)];
        $t['approved'] = $step($t['requested'], 1, 12);
        $t['collecting'] = $step($t['approved'], 4, 24);
        $t['collected'] = $step($t['collecting'], 12, 36);
        $t['redelivering'] = $step($t['collected'], 2, 12);
        $t['redelivered'] = $step($t['redelivering'], 12, 36);
        $t['completed'] = $step($t['redelivered'], 1, 6);
        $t['last'] = $t['completed'];

        return $t;
    }

    /**
     * 교환 상태 진행 순서에서 목표 상태가 기준 상태 이상인지 판정합니다.
     *
     * @param  ExchangeStatusEnum|ReturnStatusEnum  $status  목표 상태
     * @param  ExchangeStatusEnum  $threshold  기준 상태
     * @return bool 기준 이상이면 true
     */
    private function reached($status, ExchangeStatusEnum $threshold): bool
    {
        $order = [
            ExchangeStatusEnum::REQUESTED, ExchangeStatusEnum::APPROVED, ExchangeStatusEnum::COLLECTION_READY,
            ExchangeStatusEnum::COLLECTING, ExchangeStatusEnum::COLLECTED, ExchangeStatusEnum::REDELIVERY_READY,
            ExchangeStatusEnum::REDELIVERING, ExchangeStatusEnum::REDELIVERED, ExchangeStatusEnum::COMPLETED,
        ];
        $index = array_search($status, $order, true);

        return $index !== false && $index >= array_search($threshold, $order, true);
    }

    /**
     * 같은 상품의 다른 옵션을 고릅니다 (상위 교환이면 추가금이 더 큰 옵션).
     *
     * @param  OrderOption  $option  주문옵션
     * @param  bool  $upgrade  더 비싼 옵션
     * @return ProductOption|null 대상 옵션
     */
    private function siblingOption(OrderOption $option, bool $upgrade): ?ProductOption
    {
        $siblings = ProductOption::where('product_id', $option->product_id)
            ->where('id', '!=', $option->product_option_id)
            ->where('is_active', true)
            ->get();

        if ($upgrade) {
            $current = (float) $this->optionAdjustment($option);

            return $siblings->filter(fn (ProductOption $p) => (float) $p->price_adjustment > $current)->sortBy('price_adjustment')->first()
                ?? $siblings->sortByDesc('price_adjustment')->first();
        }

        return $siblings->isNotEmpty() ? $siblings->random() : null;
    }

    /**
     * 주문 시점 옵션 추가금을 읽습니다.
     *
     * @param  OrderOption  $option  주문옵션
     * @return float 옵션 추가금
     */
    private function optionAdjustment(OrderOption $option): float
    {
        return (float) (($option->option_snapshot ?? [])['price_adjustment'] ?? 0);
    }

    /**
     * 신청 대상 스냅샷 한 줄 (서비스 buildItemsSnapshot 과 같은 키).
     *
     * @param  OrderOption  $option  주문옵션
     * @param  int  $quantity  수량
     * @return array<string, mixed> 스냅샷
     */
    private function itemSnapshot(OrderOption $option, int $quantity): array
    {
        return [
            'option_id' => $option->id,
            'product_name' => $option->product_name,
            'option_name' => $option->option_name,
            'option_value' => $option->option_value,
            'quantity' => $quantity,
            'unit_price' => (float) $option->unit_price,
            'option_status' => $option->option_status instanceof OrderStatusEnum ? $option->option_status->value : $option->option_status,
        ];
    }

    /**
     * 그 상품 배송정책의 클레임 회수지 스냅샷 (서비스 resolveReturnAddressSnapshot 과 같은 모양).
     *
     * @param  OrderOption  $option  주문옵션
     * @param  ClaimTypeEnum  $type  교환/반품
     * @return array<string, mixed>|null 스냅샷
     */
    private function returnAddress(OrderOption $option, ClaimTypeEnum $type): ?array
    {
        $policyId = OrderShipping::where('order_option_id', $option->id)->value('shipping_policy_id');
        $address = $policyId ? ShippingPolicyClaimAddress::where('shipping_policy_id', $policyId)->where('claim_type', $type->value)->first() : null;

        return $address ? [
            'shipping_policy_id' => (int) $policyId,
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'zipcode' => $address->zipcode,
            'address1' => $address->address1,
            'address2' => $address->address2,
            'country_code' => $address->country_code,
        ] : null;
    }

    /**
     * 교환품 받을 주소 스냅샷 (주문 배송지).
     *
     * @param  Order  $order  주문
     * @return array<string, mixed>|null 스냅샷
     */
    private function redeliveryAddress(Order $order): ?array
    {
        $a = $order->shippingAddress;

        return $a ? [
            'recipient_name' => $a->recipient_name,
            'recipient_phone' => $a->recipient_phone,
            'zipcode' => $a->zipcode,
            'address' => $a->address,
            'address_detail' => $a->address_detail,
            'country_code' => $a->recipient_country_code ?: 'KR',
        ] : null;
    }

    /**
     * 클레임 사유를 찾습니다.
     *
     * @param  string  $type  사유 유형 (exchange|return|refund)
     * @param  string  $code  사유 코드
     * @return ClaimReason|null 사유
     */
    private function reason(string $type, string $code): ?ClaimReason
    {
        return ClaimReason::where('type', $type)->where('code', $code)->first()
            ?? ClaimReason::where('type', $type)->first();
    }

    /**
     * 사유의 귀책을 돌려줍니다 (사유 카탈로그가 비어 있으면 고객 귀책 — 귀책 칸은 비울 수 없다).
     *
     * @param  ClaimReason|null  $reason  사유
     * @return ClaimReasonFaultTypeEnum 귀책
     */
    private function faultOf(?ClaimReason $reason): ClaimReasonFaultTypeEnum
    {
        return $reason?->fault_type ?? ClaimReasonFaultTypeEnum::CUSTOMER;
    }

    /**
     * 첨부 이미지 1장을 실제 파일로 올립니다 (첨부 서비스 경로 — 저장소·행 모양이 운영과 같다).
     *
     * 업로드 훅이 남기는 활동 로그에는 샘플 표식을 달아, 다음 재시드가 그 로그도 정리하게 한다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  int  $claimId  신청서 id
     * @param  int  $userId  업로더
     * @return void 반환값 없음
     */
    private function attachImage(ClaimTypeEnum $type, int $claimId, int $userId): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            return;
        }

        // tempnam 이 만든 빈 파일과 .png 를 붙인 실제 파일을 둘 다 정리한다
        $tempFile = tempnam(sys_get_temp_dir(), 'claim');
        $path = $tempFile.'.png';
        $image = imagecreatetruecolor(320, 240);
        imagefill($image, 0, 0, imagecolorallocate($image, 220, 230, 245));
        imagestring($image, 5, 90, 110, 'SAMPLE CLAIM', imagecolorallocate($image, 60, 70, 90));
        imagepng($image, $path);

        try {
            $uploaded = app(ClaimAttachmentService::class)->uploadFor(
                new UploadedFile($path, 'claim-photo.png', 'image/png', null, true),
                $type,
                $claimId,
                $userId,
                ClaimImageUploaderTypeEnum::CUSTOMER,
            );

            ActivityLog::where('loggable_type', $uploaded->getMorphClass())
                ->where('loggable_id', $uploaded->id)
                ->get()
                ->each(fn ($log) => $log->update(['properties' => array_merge((array) $log->properties, ['seeded_by' => ActivityLogSampleSeeder::SEED_MARK])]));
        } catch (\Throwable $e) {
            $this->command->warn('  - 클레임 첨부 샘플을 올리지 못했습니다: '.$e->getMessage());
        } finally {
            @unlink($path);
            @unlink($tempFile);
        }
    }

    /**
     * 환불 마일리지 거래를 원장에 직접 기록합니다 (서비스를 부르면 실제 활동 로그가 남는다).
     *
     * @param  Order  $order  주문
     * @param  MileageTransactionTypeEnum  $type  REFUND_RESTORE / REFUND_CREDIT
     * @param  float  $amount  금액
     * @param  int  $refundId  환불신청서 id
     * @param  Carbon  $at  거래 시각
     * @param  Carbon|null  $expiresAt  만료 (현금분 지급은 무기한)
     * @return void 반환값 없음
     */
    private function insertMileage(Order $order, MileageTransactionTypeEnum $type, float $amount, int $refundId, Carbon $at, ?Carbon $expiresAt): void
    {
        if ($amount <= 0) {
            return;
        }

        $currency = $order->currency;
        $balance = (float) MileageTransaction::where('user_id', $order->user_id)->where('currency', $currency)
            ->whereNull('expired_at')->where('remaining_amount', '>', 0)->sum('remaining_amount');

        $tx = MileageTransaction::create([
            'user_id' => $order->user_id,
            'currency' => $currency,
            'type' => $type,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'balance_after' => $balance + $amount,
            'order_id' => $order->id,
            'order_refund_id' => $refundId,
            'description' => __('sirsoft-ecommerce::activity_log.description.mileage_restore', ['amount' => ecommerce_format_price($amount, $currency)]),
            'expires_at' => $expiresAt,
        ]);
        $tx->created_at = $at;
        $tx->updated_at = $at;
        $tx->save();

        if ($type === MileageTransactionTypeEnum::REFUND_RESTORE) {
            MileageTransaction::where('order_id', $order->id)
                ->where('type', MileageTransactionTypeEnum::ORDER_USE->value)
                ->whereNull('order_refund_id')
                ->update(['order_refund_id' => $refundId]);
        }
    }

    /**
     * 대상 모델을 시각 컬럼까지 그대로 만듭니다 (created_at 은 fillable 이 아니라 가드를 푼다).
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $class  모델 클래스
     * @param  array<string, mixed>  $attributes  속성
     * @return T 생성된 모델
     */
    private function create(string $class, array $attributes)
    {
        return $class::unguarded(fn () => $class::create($attributes));
    }

    /**
     * 채번합니다.
     *
     * @param  SequenceType  $type  채번 유형
     * @return string 번호
     */
    private function sequence(SequenceType $type): string
    {
        return app(SequenceService::class)->generateCode($type);
    }

    /**
     * 국내 택배사 id (CJ대한통운 우선).
     *
     * @return int|null 택배사 id
     */
    private function carrierId(): ?int
    {
        $carriers = ShippingCarrier::where('is_active', true)->where('type', 'domestic')->orderBy('sort_order')->get(['id', 'code']);

        return ($carriers->firstWhere('code', 'cj') ?? $carriers->first())?->id;
    }

    /**
     * 송장번호 (12자리).
     *
     * @return string 송장번호
     */
    private function trackingNumber(): string
    {
        return (string) rand(100000000000, 999999999999);
    }

    /**
     * 기본 통화 금액을 등록 통화 전체 금액 맵으로 바꿉니다.
     *
     * @param  float|int  $amount  기본 통화 금액
     * @return array<string, float|int> 통화코드 => 금액
     */
    private function mc(float|int $amount): array
    {
        $result = [];
        foreach (app(CurrencyConversionService::class)->convertToMultiCurrency((int) $amount) as $code => $data) {
            $result[$code] = $data['price'];
        }

        return $result ?: [app(CurrencyConversionService::class)->getDefaultCurrency() => $amount];
    }
}
