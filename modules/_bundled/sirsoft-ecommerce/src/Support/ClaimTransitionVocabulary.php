<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use BackedEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;

/**
 * 클레임 상태 전이 어휘의 단일 출처 (헤더 바 · 상품 행 · FormRequest 공용)
 *
 * 선택지는 두 축의 **교집합**이다 — ⓐ 운영자가 직접 지정할 수 있는 상태
 * (`adminAssignableValues()`)와 ⓑ 지금 상태에서 갈 수 있는 상태(`allowedTransitions()`).
 * ⓐ 만 보면 이미 승인된 건에 "승인" 이 남아 눌러도 422 가 되고, ⓑ 만 보면 완료·처리중처럼
 * **돈이 움직여야 도달하는** 상태가 목록에 서서 돈 없이 완료를 찍을 수 있게 된다.
 *
 * ## 왜 화면이 아니라 서버가 만드는가
 *
 * 같은 교집합을 레이아웃 JSON 이 리터럴로 들고 있으면, 전이표가 바뀔 때 화면과 서버가
 * 갈린다 — 화면엔 값이 남았는데 서버는 422 를 주고, 그 사이 운영자는 "가끔 안 되는 버튼" 을
 * 본다. 세 소비자(헤더 바·상품 행 리소스·FormRequest)가 이 한 메서드를 부른다.
 *
 * ## 결제대기를 상품 축에서 빼는 이유
 *
 * 결제는 **신청서 단위** 축이다(청구액은 신청서 하나에 하나뿐이다). 상품 행에 결제대기가
 * 서면 그 행만 결제를 기다리는 상태가 되어 청구·수납 어느 쪽과도 대응되지 않는다.
 * 같은 이유로 **결제대기 행에는 승인을 내지 않는다** — 그 승인은 결제 확정이 잇는다.
 */
class ClaimTransitionVocabulary
{
    /**
     * 결제대기 상태 값 — 상품 단위 어휘에서 제외한다.
     */
    protected const PAYMENT_PENDING = 'payment_pending';

    /**
     * 승인 상태 값 — 상품 축의 결제대기 행에서는 제시하지 않는다.
     */
    protected const APPROVED = 'approved';

    /**
     * 신청서 단위 칸의 심사 어휘 — 결제 요청·승인·보류·반려.
     */
    protected const REVIEW_VALUES = ['payment_pending', 'approved', 'on_hold', 'rejected'];

    /**
     * 현재 상태에서 제시할 전이 선택지를 만듭니다.
     *
     * 교환은 진행 방식(일반·선교환)마다 전이표가 다르다 — 선교환 행에 회수 단계가 서면 눌러도
     * 서비스가 거절한다. `$mode` 를 넘기면 그 방식의 표(`allowedTransitionsIn()`)를 읽는다.
     *
     * @param  BackedEnum|null  $current  현재 상태 (전이표를 가진 상태 Enum)
     * @param  bool  $excludePaymentPending  결제대기를 뺄지 (상품 행 축이면 true)
     * @param  ExchangeModeEnum|null  $mode  교환 진행 방식 (교환이 아니거나 null 이면 일반 표)
     * @return array<int, array{value: string, label: string}> 전이 선택지
     */
    public static function optionsFrom(?BackedEnum $current, bool $excludePaymentPending = false, ?ExchangeModeEnum $mode = null): array
    {
        if ($current === null || ! method_exists($current, 'allowedTransitions')) {
            return [];
        }

        $enumClass = $current::class;

        if (! method_exists($enumClass, 'adminAssignableValues')) {
            return [];
        }

        $assignable = $enumClass::adminAssignableValues();
        $options = [];

        $targets = $current instanceof ExchangeStatusEnum && $mode !== null
            ? $current->allowedTransitionsIn($mode)
            : $current->allowedTransitions();

        foreach ($targets as $target) {
            if (! in_array($target->value, $assignable, true)) {
                continue;
            }

            if ($excludePaymentPending && $target->value === self::PAYMENT_PENDING) {
                continue;
            }

            // 결제대기 행의 승인은 **결제 확정이 잇는 전이**다(`confirmPaid()` → paid → 승인).
            // 상품 행에서 직접 승인을 내면 운영자가 돈을 받기 전에 그 행을 승인하고, 헤더가
            // `payment_pending → approved` 로 따라가 미수납 게이트를 우회한다(#599 validate P2 #1).
            if ($excludePaymentPending && $current->value === self::PAYMENT_PENDING && $target->value === self::APPROVED) {
                continue;
            }

            $options[] = [
                'value' => $target->value,
                'label' => method_exists($target, 'label') ? $target->label() : $target->value,
            ];
        }

        return $options;
    }

    /**
     * 신청서 단위 칸(상세 「신청서 상태 변경」)에 제시할 **심사** 선택지를 만듭니다.
     *
     * 신청서 칸은 신청 내용을 읽은 자리에서 내리는 결정(승인·보류·반려·결제 요청)만 맡는다.
     * 회수·발송 단계는 상품이 실제로 움직이는 사실이라 상품 칸이 정본이고(헤더는 상품이 한
     * 상태로 모이면 따라간다), 완료는 재고 복구·환불신청서 선택이 딸려 [완료 처리] 가 유일한
     * 입구다. 예전에는 이 둘까지 신청서 칸에 서서, 회수도착 반품에서 「반품완료」 를 고르면
     * 안내 토스트만 뜨고 아무 일도 일어나지 않았다(#599 후속).
     *
     * 지금 상태에서 갈 수 있는지(`optionsFrom()`)까지만 판정한다 — 돈·상품 행에 걸린 게이트는
     * 신청서 인스턴스가 있어야 알 수 있으므로 `AdminClaimService::transitionOptionsFor()` 가 거른다.
     *
     * @param  BackedEnum|null  $current  현재 상태
     * @return array<int, array{value: string, label: string}> 심사 선택지
     */
    public static function reviewOptionsFrom(?BackedEnum $current): array
    {
        return array_values(array_filter(
            self::optionsFrom($current),
            fn (array $option) => in_array($option['value'], self::REVIEW_VALUES, true),
        ));
    }

    /**
     * 전이 선택지의 값만 반환합니다.
     *
     * @param  BackedEnum|null  $current  현재 상태
     * @param  bool  $excludePaymentPending  결제대기를 뺄지
     * @param  ExchangeModeEnum|null  $mode  교환 진행 방식
     * @return array<int, string> 전이 가능 상태 값
     */
    public static function valuesFrom(?BackedEnum $current, bool $excludePaymentPending = false, ?ExchangeModeEnum $mode = null): array
    {
        return array_column(self::optionsFrom($current, $excludePaymentPending, $mode), 'value');
    }

    /**
     * 유형별로 운영자가 지정할 수 있는 상태 값을 반환합니다. (FormRequest 의 `Rule::in`)
     *
     * FormRequest 는 **행마다 다른** 현재 상태를 알 수 없다 — 한 번의 지시가 서로 다른
     * 상태의 행을 함께 담기 때문이다. 그래서 여기서는 유형 축만 검사하고, 행별 전이 가능
     * 여부는 서비스가 행마다 판정해 `skipped` 로 보고한다. 대신 **결제대기 제외**는 여기서도
     * 적용된다 — 그 값은 상품 행에 애초에 의미가 없어 통과시키면 전건이 조용히 탈락한다.
     *
     * @param  ClaimTypeEnum|null  $type  클레임 유형
     * @param  bool  $excludePaymentPending  결제대기를 뺄지 (상품 행 축이면 true)
     * @return array<int, string> 지정 가능 상태 값
     */
    public static function assignableValuesFor(?ClaimTypeEnum $type, bool $excludePaymentPending = false): array
    {
        $values = match ($type) {
            ClaimTypeEnum::EXCHANGE => ExchangeStatusEnum::adminAssignableValues(),
            ClaimTypeEnum::RETURN => ReturnStatusEnum::adminAssignableValues(),
            ClaimTypeEnum::REFUND => RefundStatusEnum::adminAssignableValues(),
            default => [],
        };

        if ($excludePaymentPending) {
            $values = array_diff($values, [self::PAYMENT_PENDING]);
        }

        return array_values($values);
    }
}
