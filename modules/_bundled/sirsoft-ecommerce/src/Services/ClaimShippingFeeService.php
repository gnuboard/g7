<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\DTO\ClaimShippingFee;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\ShippingPolicySnapshot;

/**
 * 클레임 배송비 산정 서비스
 *
 * 교환/반품 신청에서 고객에게 청구할 배송비를 산정한다. 결과는 클레임 헤더의
 * `shipping_fee_mode` / `claim_shipping_fee` / `mc_claim_shipping_fee` /
 * `shipping_fee_snapshot` 으로 동결된다.
 *
 * 산정 순서:
 *  1. 청구 대상 유형인가 — 환불·취소는 회수 배송이 없어 청구가 없다
 *  2. 귀책이 고객인가 — 판매자·배송사 귀책은 0원
 *  3. 배송정책 국가별 설정의 `claim_fee_mode` 판독
 *     - FIXED: 정책에 입력된 고정액 (반품 `return_fixed_fee` 편도 · 교환 `exchange_fixed_fee` 왕복 총액)
 *     - ORIGINAL_POLICY: 원 배송정책의 부과정책으로 편도 배송비를 재산출한 뒤 반품 ×1 · 교환 ×2,
 *       그 위에 배송 구간별 도서산간 추가배송비를 얹는다
 *
 * ## 정책값의 출처가 둘이다
 *
 * - **클레임 약관 3종**(`claim_fee_mode` · `return_fixed_fee` · `exchange_fixed_fee`)은 언제나
 *   **현재 배송정책 행**에서 읽는다. 주문 시점 스냅샷은 이 세 값을 담지 않으며, 클레임 약관의
 *   동결 시점은 주문 시점이 아니라 **신청 시점**이기 때문이다(`ClaimShippingFeeModeEnum` 참조).
 * - **부과정책 파라미터**(`charge_policy` · `base_fee` · `free_threshold` · `ranges` ·
 *   `extra_fee_*`)는 **주문 시점 스냅샷**(`order_shippings.delivery_policy_snapshot`)에서 복원한다.
 *   '원 배송정책 기준'이 뜻하는 '원 정책'은 그 주문이 실제로 적용받은 정책이며, 운영자가 이후
 *   요금표를 바꿔도 과거 주문의 클레임 배송비가 따라 움직이면 안 된다. 스냅샷이 없는 과거 주문만
 *   현재 정책 행으로 폴백한다.
 *
 * 어느 경로든 부과정책 파라미터는 **재구성된 사본**으로 다룬다. 사본에는 `api_endpoint` /
 * `api_config` 가 실리지 않으므로 `charge_policy = api` 는 외부 HTTP 호출 없이 `base_fee` 로
 * 떨어진다 — 스냅샷 페이로드가 그 둘을 담지 않아 환불 재계산 경로가 이미 같은 폴백을 쓰고 있고,
 * 신청 시점에 외부 API 장애가 클레임 신청 흐름을 막아서도 안 된다. 그 사실은 근거 스냅샷의
 * `api_fallback` 에 남는다.
 *
 * 이 클래스는 부과정책 분기와 도서산간 판정을 자체 구현하지 않고
 * `OrderCalculationService::calculateCountryShippingFee()` / `::calculateExtraShippingFee()` 를
 * 그대로 호출한다. 같은 판정을 두 벌로 두면 정책이 늘어날 때 한쪽만 갱신되어 주문 배송비와
 * 클레임 배송비가 조용히 갈린다.
 */
class ClaimShippingFeeService
{
    /** 부과정책 파라미터를 주문 시점 스냅샷에서 복원한 그룹 */
    public const SOURCE_ORDER_SNAPSHOT = 'order_snapshot';

    /** 주문 스냅샷이 없어 현재 배송정책 행으로 폴백한 그룹 */
    public const SOURCE_LIVE_POLICY = 'live_policy';

    /**
     * @param  OrderCalculationService  $calculationService  주문 계산 서비스 (부과정책·도서산간 판정 SSoT)
     * @param  ShippingPolicyRepositoryInterface  $shippingPolicyRepository  배송정책 Repository
     * @param  CurrencyConversionService  $currencyService  통화 변환 서비스 (주문 시점 환율 기준 다통화 금액)
     */
    public function __construct(
        protected OrderCalculationService $calculationService,
        protected ShippingPolicyRepositoryInterface $shippingPolicyRepository,
        protected CurrencyConversionService $currencyService,
    ) {}

    /**
     * 클레임 배송비를 산정합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  ClaimTypeEnum  $claimType  클레임 유형
     * @param  array  $items  클레임 대상 아이템 [{order_option_id, cancel_quantity}]
     * @param  ClaimReasonFaultTypeEnum  $fault  신청 시점 귀책
     * @param  array|null  $redeliveryAddress  교환 재발송지 (신청 시 변경 허용, D14) [{country_code, zipcode}]
     * @return ClaimShippingFee 산정 결과
     */
    public function calculate(
        Order $order,
        ClaimTypeEnum $claimType,
        array $items,
        ClaimReasonFaultTypeEnum $fault,
        ?array $redeliveryAddress = null,
    ): ClaimShippingFee {
        // 1. 환불·취소는 회수 배송이 없어 청구가 발생하지 않는다.
        if (! $claimType->isPayable()) {
            return $this->withMultiCurrency(ClaimShippingFee::notCharged(
                claimType: $claimType,
                fault: $fault,
                reason: ClaimShippingFee::REASON_CLAIM_TYPE_NOT_CHARGEABLE,
            ), $order);
        }

        $claimItems = $this->resolveClaimItems($order, $items);

        if (empty($claimItems)) {
            return $this->withMultiCurrency(ClaimShippingFee::notCharged(
                claimType: $claimType,
                fault: $fault,
                reason: ClaimShippingFee::REASON_NO_ITEMS,
            ), $order);
        }

        $groups = $this->buildPolicyGroups($order, $claimItems);
        $legs = $this->resolveDeliveryLegs($order, $claimType, $redeliveryAddress);

        // 2. 판매자·배송사 귀책은 산정 없이 0원이다. 모드는 헤더 컬럼이 NOT NULL 이라
        //    청구 여부와 무관하게 정책 선언 그대로 기록한다.
        if ($fault !== ClaimReasonFaultTypeEnum::CUSTOMER) {
            return $this->withMultiCurrency(ClaimShippingFee::notCharged(
                claimType: $claimType,
                fault: $fault,
                reason: ClaimShippingFee::REASON_FAULT_NOT_CUSTOMER,
                mode: $this->resolveHeaderMode($groups),
                groups: $this->summarizeGroupsWithoutCharge($groups, $legs),
                redeliveryChanged: $legs['redelivery_changed'],
            ), $order);
        }

        // 3. 그룹별 산정 후 합산.
        $total = 0;
        $extraTotal = 0;
        $basis = [];
        $resolvedAny = false;

        foreach ($groups as $group) {
            $entry = $this->calculateGroupFee($group, $claimType, $legs);
            $basis[] = $entry;
            $total += $entry['fee'];
            $extraTotal += $entry['extra_fee'];

            if ($entry['reason'] !== ClaimShippingFee::REASON_NO_SHIPPING_POLICY) {
                $resolvedAny = true;
            }
        }

        if (! $resolvedAny) {
            return $this->withMultiCurrency(ClaimShippingFee::notCharged(
                claimType: $claimType,
                fault: $fault,
                reason: ClaimShippingFee::REASON_NO_SHIPPING_POLICY,
                groups: $basis,
                redeliveryChanged: $legs['redelivery_changed'],
            ), $order);
        }

        return $this->withMultiCurrency(new ClaimShippingFee(
            fee: $total,
            mode: $this->resolveHeaderMode($groups),
            claimType: $claimType,
            fault: $fault,
            charged: true,
            reason: ClaimShippingFee::REASON_CHARGED,
            groups: $basis,
            extraFee: $extraTotal,
            redeliveryChanged: $legs['redelivery_changed'],
        ), $order);
    }

    /**
     * 산정 결과에 주문 시점 환율 기준 다통화 금액을 붙입니다.
     *
     * 현재 환율이 아니라 주문 `currency_snapshot` 의 동결 환율을 쓴다 — 클레임 청구액은 그 주문의
     * 거래 통화 계약 안에서 표기되어야 하고, 운영자가 이후 환율을 갱신해도 이미 산정된 클레임의
     * 표기가 따라 움직이면 안 된다.
     *
     * @param  ClaimShippingFee  $result  산정 결과
     * @param  Order  $order  대상 주문
     * @return ClaimShippingFee 다통화 금액이 붙은 결과
     */
    protected function withMultiCurrency(ClaimShippingFee $result, Order $order): ClaimShippingFee
    {
        $currencySnapshot = $order->currency_snapshot ?? [];

        if (empty($currencySnapshot)) {
            return $result;
        }

        $result->mcFee = $this->currencyService->convertToMultiCurrencyWithSnapshot(
            $result->fee,
            $currencySnapshot
        );

        return $result;
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
     * 회수 / 재발송 두 구간의 배송지를 해석합니다 (D14).
     *
     * 회수 구간은 그 주문이 실제로 배송된 주소이므로 주문 시점 스냅샷이 SSoT 다. 재발송 구간은
     * 교환에만 있고 신청 시 변경할 수 있으므로, 지정되면 그 주소로 도서산간을 다시 판정한다.
     * 재발송지를 지정하지 않으면 원 배송지 그대로다.
     *
     * 재발송지의 국가코드는 도서산간 판정(KR 전용)에만 쓰고 정책 행 선택에는 쓰지 않는다 —
     * 교환은 해외배송을 지원하지 않으므로 국가가 바뀌는 재발송은 신청 검증에서 걸린다.
     *
     * @param  Order  $order  대상 주문
     * @param  ClaimTypeEnum  $claimType  클레임 유형
     * @param  array|null  $redeliveryAddress  재발송지 [{country_code, zipcode}]
     * @return array{collection: array, redelivery: array|null, redelivery_changed: bool} 구간별 배송지
     */
    protected function resolveDeliveryLegs(Order $order, ClaimTypeEnum $claimType, ?array $redeliveryAddress): array
    {
        $collection = ShippingPolicySnapshot::deliveryAddress(
            $order->shipping_policy_applied_snapshot ?? [],
            function () use ($order) {
                $shippingAddress = $order->shippingAddress;

                return [
                    'country_code' => $shippingAddress?->recipient_country_code,
                    'zipcode' => $shippingAddress?->zipcode ?: $shippingAddress?->intl_postal_code,
                ];
            }
        );

        // 회수 구간만 있는 반품은 재발송 구간 자체가 없다.
        if ($claimType !== ClaimTypeEnum::EXCHANGE) {
            return [
                'collection' => $collection,
                'redelivery' => null,
                'redelivery_changed' => false,
            ];
        }

        $redelivery = $collection;
        $changed = false;

        if (! empty($redeliveryAddress)) {
            $zipcode = $redeliveryAddress['zipcode'] ?? null;
            $countryCode = $redeliveryAddress['country_code'] ?? null;

            $redelivery = [
                'country_code' => strtoupper((string) ($countryCode ?: $collection['country_code'])),
                'zipcode' => ($zipcode !== null && $zipcode !== '') ? (string) $zipcode : $collection['zipcode'],
            ];

            $changed = $redelivery !== $collection;
        }

        return [
            'collection' => $collection,
            'redelivery' => $redelivery,
            'redelivery_changed' => $changed,
        ];
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
            $policySnapshot = $shipping?->delivery_policy_snapshot;
            $countryCode = $policySnapshot['country_code'] ?? 'KR';
            $key = ($policyId ?? 'none').'|'.$countryCode;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'shipping_policy_id' => $policyId,
                    'country_code' => $countryCode,
                    // 주문 시점 부과정책 파라미터의 출처. 같은 정책·같은 국가 그룹의 배송 행은
                    // 같은 스냅샷을 갖는다(주문 시점에 한 번 계산된 결과가 옵션마다 복사된 것).
                    'policy_snapshot' => $policySnapshot,
                    'country_setting' => null,
                    'policy_source' => null,
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
     * 정책 그룹에 산정용 국가별 설정을 1회만 해석해 붙입니다.
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

            // 주문 배송비 경로(`resolveCountrySetting()`)와 달리 `is_active` 로 거르지 않는다.
            // 국가 설정을 끄는 것은 '앞으로 그 나라로 보내지 않는다' 는 선언이지 '이미 배송된
            // 주문의 반품비를 받지 않는다' 는 선언이 아니다. 거르면 주문 시점에 정상이던 정책이
            // 사후에 꺼졌다는 이유로 과거 주문의 클레임 배송비가 0원이 된다 (확정 2026-08-21).
            $liveSetting = $policy?->countrySettings
                ->firstWhere('country_code', $group['country_code']);

            [$setting, $source] = $this->buildClaimCountrySetting($liveSetting, $group['policy_snapshot']);

            $groups[$key]['country_setting'] = $setting;
            $groups[$key]['policy_source'] = $source;
        }

        return $groups;
    }

    /**
     * 산정에 쓸 국가별 설정 사본을 만듭니다.
     *
     * 클레임 약관 3종은 현재 정책 행에서, 부과정책 파라미터는 주문 시점 스냅샷에서 온다.
     * 현재 정책 행이 없으면(정책·국가 행 삭제) 클레임 약관을 해석할 수 없으므로 산정 자체가
     * 성립하지 않는다 — 스냅샷만으로 금액을 만들어 내면 운영자가 선언한 적 없는 청구가 된다.
     *
     * @param  ShippingPolicyCountrySetting|null  $liveSetting  현재 정책의 국가별 설정
     * @param  array|null  $policySnapshot  주문 시점 배송정책 스냅샷 (`delivery_policy_snapshot`)
     * @return array{0: ShippingPolicyCountrySetting|null, 1: string|null} [산정용 설정, 파라미터 출처]
     */
    protected function buildClaimCountrySetting(
        ?ShippingPolicyCountrySetting $liveSetting,
        ?array $policySnapshot,
    ): array {
        if ($liveSetting === null) {
            return [null, null];
        }

        $reconstructed = $policySnapshot
            ? $this->calculationService->buildSnapshotCountrySetting(['policy_snapshot' => $policySnapshot])
            : null;

        $source = self::SOURCE_ORDER_SNAPSHOT;

        if ($reconstructed === null) {
            // 주문 시점 스냅샷이 없는 과거 주문: 현재 정책 행의 부과정책을 그대로 쓴다.
            $reconstructed = $liveSetting->replicate();
            $source = self::SOURCE_LIVE_POLICY;
        }

        // 클레임 약관 3종은 스냅샷에 없다 — 어느 출처든 현재 정책 행이 정한다.
        $reconstructed->claim_fee_mode = $liveSetting->claim_fee_mode;
        $reconstructed->return_fixed_fee = $liveSetting->return_fixed_fee;
        $reconstructed->exchange_fixed_fee = $liveSetting->exchange_fixed_fee;

        // 배송비 훅을 쓰는 플러그인이 어느 정책 행인지 식별할 수 있게 보존한다.
        $reconstructed->shipping_policy_id = $liveSetting->shipping_policy_id;

        // 외부 요금 API 는 클레임 문맥에서 호출하지 않는다(클래스 docblock 참조).
        $reconstructed->api_endpoint = null;
        $reconstructed->api_config = null;

        return [$reconstructed, $source];
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
     * @param  array  $legs  구간별 배송지 (`resolveDeliveryLegs`)
     * @return array 산정 근거 항목
     */
    protected function calculateGroupFee(array $group, ClaimTypeEnum $claimType, array $legs): array
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
                extraFeeLegs: $this->emptyExtraFeeLegs($legs),
                reason: ClaimShippingFee::REASON_NO_SHIPPING_POLICY,
            );
        }

        $mode = $countrySetting->claim_fee_mode ?? ClaimShippingFeeModeEnum::FIXED;

        if ($mode === ClaimShippingFeeModeEnum::FIXED) {
            // 고정액은 정책 화면에서 이미 유형별 총액으로 입력받는다 —
            // 반품은 편도액, 교환은 왕복 총액이므로 배수를 다시 곱하지 않는다(D11).
            // 도서산간도 얹지 않는다. 고정액은 배송 구간이 아니라 "이 클레임의 배송비"를
            // 통째로 선언한 값이라, 여기에 구간별 추가액을 더하면 선언액과 청구액이 갈린다.
            $baseFee = $claimType === ClaimTypeEnum::EXCHANGE
                ? (int) round((float) $countrySetting->exchange_fixed_fee)
                : (int) round((float) $countrySetting->return_fixed_fee);

            return $this->buildBasisEntry(
                group: $group,
                mode: $mode,
                chargePolicy: null,
                baseFee: max(0, $baseFee),
                multiplier: 1,
                extraFeeLegs: $this->emptyExtraFeeLegs($legs),
                reason: ClaimShippingFee::REASON_CHARGED,
                policySource: $group['policy_source'],
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
            extraFeeLegs: $this->calculateExtraFeeLegs($countrySetting, $group, $feeGroup, $legs),
            reason: ClaimShippingFee::REASON_CHARGED,
            // 훅이 금액을 정했으면 base_fee 는 부과정책의 산출값이 아니다. 이 사실을 근거에
            // 남기지 않으면 스냅샷이 "이 정책이 이 금액을 냈다" 고 잘못 말한다.
            hookOverridden: ! empty($feeGroup['_hook_overridden']),
            apiFallback: $countrySetting->charge_policy === ChargePolicyEnum::API,
            policySource: $group['policy_source'],
        );
    }

    /**
     * 구간별 도서산간 추가배송비를 산정합니다.
     *
     * 회수 구간은 원 배송지, 재발송 구간(교환 전용)은 신청 시 지정한 재발송지 기준이다(D14).
     * 재발송지를 바꾸지 않았다면 두 구간이 같은 금액이 되어 "왕복 도서산간"과 결과가 같고,
     * 도서산간이 아닌 곳으로 바꾸면 그 구간만 0원이 된다.
     *
     * 기본 배송비의 ×2 와 달리 구간별로 각각 판정하는 이유가 여기에 있다 — 배수를 곱하면
     * 재발송지를 바꿔도 회수지 기준 금액이 두 번 붙는다.
     *
     * @param  ShippingPolicyCountrySetting  $countrySetting  산정용 국가별 설정
     * @param  array  $group  정책 그룹
     * @param  array  $feeGroup  부과정책 산출에 쓴 그룹 페이로드
     * @param  array  $legs  구간별 배송지 (`resolveDeliveryLegs`)
     * @return array<string, int|null> 구간별 추가배송비
     */
    protected function calculateExtraFeeLegs(
        ShippingPolicyCountrySetting $countrySetting,
        array $group,
        array $feeGroup,
        array $legs,
    ): array {
        $collection = $this->calculationService->calculateExtraShippingFee(
            $countrySetting,
            $legs['collection']['country_code'] ?? $group['country_code'],
            $legs['collection']['zipcode'] ?? null,
            $feeGroup
        );

        if ($legs['redelivery'] === null) {
            return ['collection' => $collection, 'redelivery' => null];
        }

        $redelivery = $this->calculationService->calculateExtraShippingFee(
            $countrySetting,
            $legs['redelivery']['country_code'] ?? $group['country_code'],
            $legs['redelivery']['zipcode'] ?? null,
            $feeGroup
        );

        return ['collection' => $collection, 'redelivery' => $redelivery];
    }

    /**
     * 도서산간을 산정하지 않는 경우의 구간별 값을 만듭니다.
     *
     * 구간 자체는 근거에 남긴다 — 값이 0 인 것과 그 구간이 없는 것은 다르다.
     *
     * @param  array  $legs  구간별 배송지 (`resolveDeliveryLegs`)
     * @return array<string, int|null> 구간별 추가배송비
     */
    protected function emptyExtraFeeLegs(array $legs): array
    {
        return [
            'collection' => 0,
            'redelivery' => $legs['redelivery'] === null ? null : 0,
        ];
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
     * @param  array  $legs  구간별 배송지 (`resolveDeliveryLegs`)
     * @return array<int, array> 근거 항목 목록
     */
    protected function summarizeGroupsWithoutCharge(array $groups, array $legs): array
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
                extraFeeLegs: $this->emptyExtraFeeLegs($legs),
                reason: ClaimShippingFee::REASON_FAULT_NOT_CUSTOMER,
                policySource: $group['policy_source'],
            );
        }

        return $basis;
    }

    /**
     * 근거 스냅샷 항목을 만듭니다.
     *
     * 그룹 청구액은 `base_fee × multiplier + 구간별 도서산간 합` 이며, 그 값을 클레임 대상
     * 아이템에 금액 비례로 안분해 `items[].fee` 로 남긴다. 안분은
     * `OrderCalculationService::apportionAmountByItems()` 로 위임한다 — 주문 배송비 경로가 쓰는
     * `apportionShippingFee()` 와 배분 규칙이 같고(금액 비례 · 반올림 · 잔여는 마지막 항목),
     * 키만 `product_option_id` 대신 그룹 내 순번이라 같은 옵션이 두 번 담겨도 접히지 않는다.
     *
     * @param  array  $group  정책 그룹
     * @param  ClaimShippingFeeModeEnum  $mode  산정 모드
     * @param  string|null  $chargePolicy  부과정책 (원 정책 기준일 때만)
     * @param  int  $baseFee  편도 산출액
     * @param  int  $multiplier  배수 (반품 1 · 교환 2, 고정액은 1)
     * @param  array<string, int|null>  $extraFeeLegs  구간별 도서산간 추가배송비
     * @param  string  $reason  사유 키
     * @param  bool  $hookOverridden  배송비 플러그인 훅이 금액을 정했는지 여부
     * @param  bool  $apiFallback  외부 요금 API 정책이 기본 배송비로 떨어졌는지 여부
     * @param  string|null  $policySource  부과정책 파라미터의 출처 (SOURCE_* 상수)
     * @return array 근거 항목
     */
    protected function buildBasisEntry(
        array $group,
        ClaimShippingFeeModeEnum $mode,
        ?string $chargePolicy,
        int $baseFee,
        int $multiplier,
        array $extraFeeLegs,
        string $reason,
        bool $hookOverridden = false,
        bool $apiFallback = false,
        ?string $policySource = null,
    ): array {
        $extraFee = (int) ($extraFeeLegs['collection'] ?? 0) + (int) ($extraFeeLegs['redelivery'] ?? 0);
        $fee = $baseFee * $multiplier + $extraFee;

        return [
            'shipping_policy_id' => $group['shipping_policy_id'],
            'country_code' => $group['country_code'],
            'mode' => $mode->value,
            'charge_policy' => $chargePolicy,
            // 부과정책 파라미터가 주문 스냅샷에서 왔는지 현재 정책 행에서 왔는지. 같은 상점에서
            // 주문 시기에 따라 금액이 갈릴 수 있으므로 근거가 출처를 말해야 한다.
            'policy_source' => $policySource,
            // 주문 배송비 스냅샷(`AppliedShippingPolicy::toArray()`)이 같은 사실을 같은 이름으로
            // 동결한다. 클레임 근거만 빠뜨리면 두 스냅샷의 해석 방법이 갈린다.
            'hook_overridden' => $hookOverridden,
            'api_fallback' => $apiFallback,
            'base_fee' => $baseFee,
            'multiplier' => $multiplier,
            'extra_fee' => $extraFee,
            'extra_fee_legs' => $extraFeeLegs,
            'fee' => $fee,
            'reason' => $reason,
            'item_count' => count($group['items']),
            'total_amount' => $group['total_amount'],
            'total_quantity' => $group['total_quantity'],
            'items' => $this->apportionGroupFee($group['items'], $fee),
        ];
    }

    /**
     * 그룹 청구액을 클레임 대상 아이템에 안분합니다.
     *
     * @param  array<int, array>  $items  그룹 아이템
     * @param  int  $fee  그룹 청구액
     * @return array<int, array> 안분된 아이템 근거
     */
    protected function apportionGroupFee(array $items, int $fee): array
    {
        // 같은 주문 옵션이 두 번 담길 수 있으므로 옵션 ID 가 아니라 그룹 내 순번으로 안분한다.
        $shares = $this->calculationService->apportionAmountByItems($items, max(0, $fee));

        $apportioned = [];

        foreach ($items as $index => $item) {
            $apportioned[] = [
                'order_option_id' => $item['order_option_id'],
                'product_option_id' => $item['product_option_id'],
                'quantity' => $item['quantity'],
                'subtotal' => $item['subtotal'],
                'fee' => (int) ($shares[$index] ?? 0),
            ];
        }

        return $apportioned;
    }
}
