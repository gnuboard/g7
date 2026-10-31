<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Modules\Sirsoft\Ecommerce\DTO\Summary;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 적립 예정 마일리지는 **어느 화면에서도 포인트 단위로** 표기된다 (SA-D9)
 *
 * 마일리지는 화폐가 아니라 포인트다. 그런데 같은 주문의 같은 값(710)이 화면마다 갈렸다 —
 * 장바구니·주문서는 `+710P`(`Summary::mileage_formatted` → `number_format().'P'`), 주문완료·
 * 비회원 주문상세·관리자 주문목록은 `+710원`(`*_earned_points_amount_formatted` →
 * `formatOrderCurrency()`). 구매자는 주문서에서 주문완료로 넘어가는 한 클릭 사이에 단위가
 * 바뀌는 것을 본다(#599 세션A 실측 2026-09-21 · 주문 20260921-1536333520).
 *
 * 같은 도메인의 관리자 주문상세·클레임 비교표는 이미 `710P` 라 어긋난 쪽이 드러났고,
 * ja 언어팩의 마일리지 만료 알림도 단위가 `ポイント` 로 일관된다.
 *
 * 운영 결정(2026-09-22): **P 로 통일**.
 */
class EarnedPointsFormattedAsPointsTest extends ModuleTestCase
{
    /**
     * 적립 포인트 포맷터를 노출하는 익명 리소스를 만든다.
     *
     * @return object 포맷 메서드를 가진 인스턴스
     */
    private function formatter(): object
    {
        return new class
        {
            use HasMultiCurrencyPrices;

            /**
             * 적립 포인트를 포맷한다.
             *
             * @param  float|int|null  $points  포인트
             * @return string 포맷된 포인트
             */
            public function points(float|int|null $points): string
            {
                return $this->formatEarnedPoints($points);
            }

            /**
             * 주문 통화로 금액을 포맷한다.
             *
             * @param  float|int|null  $amount  금액
             * @return string 포맷된 금액
             */
            public function currency(float|int|null $amount): string
            {
                return $this->formatOrderCurrency($amount);
            }
        };
    }

    /**
     * 적립 포인트 포맷은 통화 기호가 아니라 P 접미사를 쓴다.
     *
     * @scenario surface=order_resource
     *
     * @effects earned_points_formatted_uses_point_suffix
     *
     * @return void 반환값 없음
     */
    public function test_earned_points_are_formatted_with_the_point_suffix(): void
    {
        $formatted = $this->formatter()->points(710);

        $this->assertSame('710P', $formatted, '적립 포인트는 710P 로 표기되어야 합니다.');
        $this->assertStringNotContainsString('원', $formatted, '적립 포인트에 통화 단위를 붙이면 안 됩니다.');
        $this->assertStringNotContainsString('₩', $formatted, '적립 포인트에 통화 기호를 붙이면 안 됩니다.');
    }

    /**
     * 천 단위 구분은 금액 표기와 같은 관례를 따른다.
     *
     * @scenario surface=order_resource
     *
     * @effects earned_points_formatted_uses_point_suffix
     *
     * @return void 반환값 없음
     */
    public function test_earned_points_keep_thousand_separators(): void
    {
        $this->assertSame('13,000P', $this->formatter()->points(13000));
        $this->assertSame('0P', $this->formatter()->points(null), 'null 은 0P 로 떨어져야 합니다.');
    }

    /**
     * 장바구니 요약(Summary)과 주문 리소스가 **같은 문자열**을 낸다.
     *
     * 갈라진 두 포맷터가 같은 값을 다르게 쓰는 것이 이 결함의 본체다 — 한쪽만 고치면
     * 다음 화면에서 다시 갈린다.
     *
     * @scenario surface=cart_summary
     *
     * @effects earned_points_formatting_matches_across_surfaces
     *
     * @return void 반환값 없음
     */
    public function test_cart_summary_and_order_resource_agree(): void
    {
        $summary = new Summary(pointsEarning: 710);
        $fromSummary = $summary->toArray()['mileage_formatted'] ?? null;

        $this->assertSame(
            $fromSummary,
            $this->formatter()->points(710),
            '장바구니 요약과 주문 리소스의 적립 표기가 같아야 합니다.'
        );
    }

    /**
     * 통화 포맷은 그대로 통화로 남는다 — 이 수정이 금액 표기까지 바꾸지 않는다.
     *
     * @scenario surface=order_resource
     *
     * @effects earned_points_change_does_not_touch_money_formatting
     *
     * @return void 반환값 없음
     */
    public function test_money_formatting_is_untouched(): void
    {
        $money = $this->formatter()->currency(74000);

        $this->assertStringNotContainsString('P', $money, '금액 표기에 포인트 접미사가 붙으면 안 됩니다.');
        $this->assertStringContainsString('74,000', $money, '금액은 천 단위 구분을 유지해야 합니다.');
    }
}
