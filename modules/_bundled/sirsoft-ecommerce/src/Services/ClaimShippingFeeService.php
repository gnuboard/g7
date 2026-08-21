<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\DTO\ClaimShippingFee;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyRepositoryInterface;

/**
 * 클레임 배송비 산정 서비스
 *
 * 교환/반품 신청에서 고객에게 청구할 배송비를 산정한다. 결과는 클레임 헤더의
 * `shipping_fee_mode` / `claim_shipping_fee` / `shipping_fee_snapshot` 으로 동결된다.
 *
 * 산정 순서:
 *  1. 청구 대상 유형인가 — 환불·취소는 회수 배송이 없어 청구가 없다
 *  2. 귀책이 고객인가 — 판매자·배송사 귀책은 0원
 *  3. 배송정책 국가별 설정의 `claim_fee_mode` 판독
 *     - FIXED: 정책에 입력된 고정액 (반품 `return_fixed_fee` 편도 · 교환 `exchange_fixed_fee` 왕복 총액)
 *     - ORIGINAL_POLICY: 원 배송정책의 부과정책으로 편도 배송비를 재산출한 뒤 반품 ×1 · 교환 ×2
 *
 * `claim_fee_mode` 와 고정액 2종은 **주문 시점 스냅샷이 아니라 현재 배송정책 행**에서 읽는다.
 * `order_shippings.delivery_policy_snapshot` 은 이 세 값을 담지 않으며, 클레임 약관의 동결
 * 시점은 주문 시점이 아니라 **신청 시점**이기 때문이다(`ClaimShippingFeeModeEnum` 참조).
 * 주문 스냅샷이 정하는 것은 "어느 정책의 어느 국가 행을 읽을 것인가" 뿐이다.
 *
 * 이 클래스는 부과정책 분기를 자체 구현하지 않고 `OrderCalculationService::calculateCountryShippingFee()`
 * 를 그대로 호출한다. 같은 판정을 두 벌로 두면 정책이 늘어날 때 한쪽만 갱신되어 주문 배송비와
 * 클레임 배송비가 조용히 갈린다.
 */
class ClaimShippingFeeService
{
    /**
     * @param  OrderCalculationService  $calculationService  주문 계산 서비스 (부과정책 분기 SSoT)
     * @param  ShippingPolicyRepositoryInterface  $shippingPolicyRepository  배송정책 Repository
     */
    public function __construct(
        protected OrderCalculationService $calculationService,
        protected ShippingPolicyRepositoryInterface $shippingPolicyRepository,
    ) {}

    /**
     * 클레임 배송비를 산정합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  ClaimTypeEnum  $claimType  클레임 유형
     * @param  array  $items  클레임 대상 아이템 [{order_option_id, cancel_quantity}]
     * @param  ClaimReasonFaultTypeEnum  $fault  신청 시점 귀책
     * @return ClaimShippingFee 산정 결과
     */
    public function calculate(
        Order $order,
        ClaimTypeEnum $claimType,
        array $items,
        ClaimReasonFaultTypeEnum $fault,
    ): ClaimShippingFee {
        // 1. 환불·취소는 회수 배송이 없어 청구가 발생하지 않는다.
        if (! $claimType->isPayable()) {
            return ClaimShippingFee::notCharged(
                claimType: $claimType,
                fault: $fault,
                reason: ClaimShippingFee::REASON_CLAIM_TYPE_NOT_CHARGEABLE,
            );
        }

        $claimItems = $this->resolveClaimItems($order, $items);

        if (empty($claimItems)) {
            return ClaimShippingFee::notCharged(
                claimType: $claimType,
                fault: $fault,
                reason: ClaimShippingFee::REASON_NO_ITEMS,
            );
        }

        $groups = $this->buildPolicyGroups($order, $claimItems);

        // 2. 판매자·배송사 귀책은 산정 없이 0원이다. 모드는 헤더 컬럼이 NOT NULL 이라
        //    청구 여부와 무관하게 정책 선언 그대로 기록한다.
        if ($fault !== ClaimReasonFaultTypeEnum::CUSTOMER) {
            return ClaimShippingFee::notCharged(
                claimType: $claimType,
                fault: $fault,
                reason: ClaimShippingFee::REASON_FAULT_NOT_CUSTOMER,
                mode: $this->resolveHeaderMode($groups),
                groups: $this->summarizeGroupsWithoutCharge($groups),
            );
        }

        // 3. 그룹별 산정 후 합산.
        $total = 0;
        $basis = [];
        $resolvedAny = false;

        foreach ($groups as $group) {
            $entry = $this->calculateGroupFee($group, $claimType);
            $basis[] = $entry;
            $total += $entry['fee'];

            if ($entry['reason'] !== ClaimShippingFee::REASON_NO_SHIPPING_POLICY) {
                $resolvedAny = true;
            }
        }

        if (! $resolvedAny) {
            return ClaimShippingFee::notCharged(
                claimType: $claimType,
                fault: $fault,
                reason: ClaimShippingFee::REASON_NO_SHIPPING_POLICY,
                groups: $basis,
            );
        }

        return new ClaimShippingFee(
            fee: $total,
            mode: $this->resolveHeaderMode($groups),
            claimType: $claimType,
            fault: $fault,
            charged: true,
            reason: ClaimShippingFee::REASON_CHARGED,
            groups: $basis,
        );
    }

    /**
     * 클레임 대상 아이템을 주문 옵션과 매칭해 정규화합니다.
     *
     * 요청 수량이 없거나 0 이하이면 그 항목은 대상에서 제외한다. 요청 수량이 주문 수량을
     * 넘으면 주문 수량으로 잘라 낸다 — 수량 검증은 신청 FormRequest 의 책임이고, 여기서는
     * 산정 근거가 실재 주문을 넘지 않도록만 방어한다.
     *
     * @param  Order  $order  대상 주문
     * @param  array  $items  클레임 대상 아이템 [{order_option_id, cancel_quantity}]
     * @return array<int, array{option: OrderOption, quantity: int}> 정규화된 대상
     */
    protected function resolveClaimItems(Order $order, array $items): array
    {
        $options = $order->relationLoaded('options')
            ? $order->options
            : $order->options()->get();

        $optionsById = $options->keyBy('id');
        $resolved = [];

        foreach ($items as $item) {
            $optionId = (int) ($item['order_option_id'] ?? 0);
            $quantity = (int) ($item['cancel_quantity'] ?? 0);

            if ($optionId <= 0 || $quantity <= 0) {
                continue;
            }

            $option = $optionsById->get($optionId);

            if ($option === null) {
                continue;
            }

            $resolved[] = [
                'option' => $option,
                'quantity' => min($quantity, (int) $option->quantity),
            ];
        }

        return $resolved;
    }

    /**
     * 클레임 대상을 배송정책 단위로 묶습니다.
     *
     * 한 주문의 아이템이 서로 다른 배송정책에 속할 수 있으므로, 주문 배송비와 같은 기준으로
     * 정책별 그룹을 만들고 그룹마다 따로 산정한다.
     *
     * @param  Order  $order  대상 주문
     * @param  array<int, array{option: OrderOption, quantity: int}>  $claimItems  정규화된 대상
     * @return array<string, array> 정책별 그룹
     */
    protected function buildPolicyGroups(Order $order, array $claimItems): array
    {
        $shippings = $order->relationLoaded('shippings')
            ? $order->shippings
            : $order->shippings()->get();

        $shippingByOption = $shippings->keyBy('order_option_id');
        $groups = [];

        foreach ($claimItems as $claimItem) {
            $option = $claimItem['option'];
            $quantity = $claimItem['quantity'];
            $shipping = $shippingByOption->get($option->id);

            $policyId = $shipping?->shipping_policy_id;
            $countryCode = $shipping->delivery_policy_snapshot['country_code'] ?? 'KR';
            $key = ($policyId ?? 'none').'|'.$countryCode;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'shipping_policy_id' => $policyId,
                    'country_code' => $countryCode,
                    'country_setting' => null,
                    'items' => [],
                    'total_amount' => 0,
                    'total_quantity' => 0,
                    'total_weight' => 0.0,
                    'total_volume' => 0.0,
                ];
            }

            $unitAmount = $this->resolveUnitDiscountedAmount($option);
            $amount = (int) round($unitAmount * $quantity);
            $weight = (float) $option->unit_weight * $quantity;
            $volume = (float) $option->unit_volume * $quantity;

            $groups[$key]['items'][] = [
                'product_option_id' => $option->product_option_id,
                'order_option_id' => $option->id,
                'subtotal' => $amount,
                'quantity' => $quantity,
                'weight' => $weight,
                'volume' => $volume,
            ];
            $groups[$key]['total_amount'] += $amount;
            $groups[$key]['total_quantity'] += $quantity;
            $groups[$key]['total_weight'] += $weight;
            $groups[$key]['total_volume'] += $volume;
        }

        return $this->attachCountrySettings($groups);
    }

    /**
     * 정책 그룹에 국가별 설정을 1회만 해석해 붙입니다.
     *
     * 모드 판정·그룹 산정·근거 요약이 각자 조회하면 그룹 수만큼 같은 행을 다시 읽는다.
     *
     * @param  array<string, array>  $groups  정책별 그룹
     * @return array<string, array> 국가별 설정이 붙은 그룹
     */
    protected function attachCountrySettings(array $groups): array
    {
        $policyIds = array_values(array_unique(array_filter(
            array_column($groups, 'shipping_policy_id')
        )));

        $policies = $this->shippingPolicyRepository->findByIdsKeyed($policyIds);

        if ($policies->isNotEmpty()) {
            $policies->load('countrySettings');
        }

        foreach ($groups as $key => $group) {
            $policy = $group['shipping_policy_id']
                ? $policies->get($group['shipping_policy_id'])
                : null;

            $groups[$key]['country_setting'] = $policy?->countrySettings
                ->firstWhere('country_code', $group['country_code']);
        }

        return $groups;
    }

    /**
     * 주문 옵션의 1단위당 할인 후 금액을 반환합니다.
     *
     * 주문 배송비 계산이 쓰는 `discounted_subtotal`(= 소계 − 상품쿠폰 − 할인코드)과 같은 기준이며,
     * 주문 쿠폰 안분액은 그때와 마찬가지로 제외한다. 조건부무료·구간(금액) 정책이 이 값을
     * 임계값과 비교하므로 기준이 어긋나면 클레임 배송비만 다른 구간에 걸린다.
     *
     * @param  OrderOption  $option  주문 옵션
     * @return float 1단위당 할인 후 금액
     */
    protected function resolveUnitDiscountedAmount(OrderOption $option): float
    {
        $quantity = max(1, (int) $option->quantity);

        $discounted = (float) $option->subtotal_price
            - (float) $option->product_coupon_discount_amount
            - (float) $option->code_discount_amount;

        return max(0.0, $discounted) / $quantity;
    }

    /**
     * 정책 그룹 하나의 배송비를 산정합니다.
     *
     * @param  array  $group  정책 그룹
     * @param  ClaimTypeEnum  $claimType  클레임 유형
     * @return array 산정 근거 항목
     */
    protected function calculateGroupFee(array $group, ClaimTypeEnum $claimType): array
    {
        $countrySetting = $group['country_setting'];

        if ($countrySetting === null) {
            Log::warning('클레임 배송비 산정에 필요한 국가별 배송 설정이 없어 0원으로 처리했습니다', [
                'shipping_policy_id' => $group['shipping_policy_id'],
                'country_code' => $group['country_code'],
                'claim_type' => $claimType->value,
            ]);

            return $this->buildBasisEntry(
                group: $group,
                mode: ClaimShippingFeeModeEnum::FIXED,
                chargePolicy: null,
                baseFee: 0,
                multiplier: 1,
                fee: 0,
                reason: ClaimShippingFee::REASON_NO_SHIPPING_POLICY,
            );
        }

        $mode = $countrySetting->claim_fee_mode ?? ClaimShippingFeeModeEnum::FIXED;

        if ($mode === ClaimShippingFeeModeEnum::FIXED) {
            // 고정액은 정책 화면에서 이미 유형별 총액으로 입력받는다 —
            // 반품은 편도액, 교환은 왕복 총액이므로 배수를 다시 곱하지 않는다(D11).
            $baseFee = $claimType === ClaimTypeEnum::EXCHANGE
                ? (int) round((float) $countrySetting->exchange_fixed_fee)
                : (int) round((float) $countrySetting->return_fixed_fee);

            return $this->buildBasisEntry(
                group: $group,
                mode: $mode,
                chargePolicy: null,
                baseFee: max(0, $baseFee),
                multiplier: 1,
                fee: max(0, $baseFee),
                reason: ClaimShippingFee::REASON_CHARGED,
            );
        }

        // 원 배송정책 기준: 편도 배송비를 재산출한 뒤 교환만 왕복(×2)으로 곱한다(D11).
        $feeGroup = [
            'total_amount' => $group['total_amount'],
            'total_quantity' => $group['total_quantity'],
            'total_weight' => $group['total_weight'],
            'total_volume' => $group['total_volume'],
            'items' => $group['items'],
            // 배송비 훅을 쓰는 플러그인이 주문 배송비와 클레임 배송비를 구분할 수 있게 한다.
            '_claim_context' => [
                'claim_type' => $claimType->value,
                'fee_mode' => $mode->value,
            ],
        ];

        $baseFee = $this->calculationService->calculateCountryShippingFee($countrySetting, $feeGroup);
        $multiplier = $claimType === ClaimTypeEnum::EXCHANGE ? 2 : 1;

        return $this->buildBasisEntry(
            group: $group,
            mode: $mode,
            chargePolicy: $countrySetting->charge_policy?->value,
            baseFee: max(0, $baseFee),
            multiplier: $multiplier,
            fee: max(0, $baseFee) * $multiplier,
            reason: ClaimShippingFee::REASON_CHARGED,
            // 훅이 금액을 정했으면 base_fee 는 부과정책의 산출값이 아니다. 이 사실을 근거에
            // 남기지 않으면 스냅샷이 "이 정책이 이 금액을 냈다" 고 잘못 말한다.
            hookOverridden: ! empty($feeGroup['_hook_overridden']),
        );
    }

    /**
     * 클레임 헤더에 기록할 산정 모드를 결정합니다.
     *
     * 여러 배송정책이 섞인 클레임에서 모드가 갈리면 ORIGINAL_POLICY 를 기록한다 —
     * 금액의 일부가 재산출된 결과인데 헤더가 '고정액'이라고 말하면 근거가 사실과 달라진다.
     * 그룹별 실제 모드는 근거 스냅샷에 그대로 남는다.
     *
     * @param  array<string, array>  $groups  정책별 그룹
     * @return ClaimShippingFeeModeEnum 헤더 산정 모드
     */
    protected function resolveHeaderMode(array $groups): ClaimShippingFeeModeEnum
    {
        $resolved = null;

        foreach ($groups as $group) {
            $mode = $group['country_setting']?->claim_fee_mode
                ?? ClaimShippingFeeModeEnum::FIXED;

            if ($mode === ClaimShippingFeeModeEnum::ORIGINAL_POLICY) {
                return ClaimShippingFeeModeEnum::ORIGINAL_POLICY;
            }

            $resolved = $mode;
        }

        return $resolved ?? ClaimShippingFeeModeEnum::FIXED;
    }

    /**
     * 청구가 없는 결과의 그룹 근거를 만듭니다.
     *
     * @param  array<string, array>  $groups  정책별 그룹
     * @return array<int, array> 근거 항목 목록
     */
    protected function summarizeGroupsWithoutCharge(array $groups): array
    {
        $basis = [];

        foreach ($groups as $group) {
            $countrySetting = $group['country_setting'];

            $basis[] = $this->buildBasisEntry(
                group: $group,
                mode: $countrySetting?->claim_fee_mode ?? ClaimShippingFeeModeEnum::FIXED,
                chargePolicy: $countrySetting?->charge_policy?->value,
                baseFee: 0,
                multiplier: 1,
                fee: 0,
                reason: ClaimShippingFee::REASON_FAULT_NOT_CUSTOMER,
            );
        }

        return $basis;
    }

    /**
     * 근거 스냅샷 항목을 만듭니다.
     *
     * @param  array  $group  정책 그룹
     * @param  ClaimShippingFeeModeEnum  $mode  산정 모드
     * @param  string|null  $chargePolicy  부과정책 (원 정책 기준일 때만)
     * @param  int  $baseFee  편도 산출액
     * @param  int  $multiplier  배수 (반품 1 · 교환 2, 고정액은 1)
     * @param  int  $fee  그룹 청구액
     * @param  string  $reason  사유 키
     * @param  bool  $hookOverridden  배송비 플러그인 훅이 금액을 정했는지 여부
     * @return array 근거 항목
     */
    protected function buildBasisEntry(
        array $group,
        ClaimShippingFeeModeEnum $mode,
        ?string $chargePolicy,
        int $baseFee,
        int $multiplier,
        int $fee,
        string $reason,
        bool $hookOverridden = false,
    ): array {
        return [
            'shipping_policy_id' => $group['shipping_policy_id'],
            'country_code' => $group['country_code'],
            'mode' => $mode->value,
            'charge_policy' => $chargePolicy,
            // 주문 배송비 스냅샷(`AppliedShippingPolicy::toArray()`)이 같은 사실을 같은 이름으로
            // 동결한다. 클레임 근거만 빠뜨리면 두 스냅샷의 해석 방법이 갈린다.
            'hook_overridden' => $hookOverridden,
            'base_fee' => $baseFee,
            'multiplier' => $multiplier,
            'fee' => $fee,
            'reason' => $reason,
            'item_count' => count($group['items']),
            'total_amount' => $group['total_amount'],
            'total_quantity' => $group['total_quantity'],
        ];
    }
}
