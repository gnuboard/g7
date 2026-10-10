<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 적립 마일리지 표기의 서비스·Support 층 단일 출처
 *
 * 마일리지는 화폐가 아니라 포인트이므로 통화 기호를 붙이지 않는다(운영 결정 2026-09-22 「P 로 통일」).
 * 리소스 층은 `HasMultiCurrencyPrices::formatEarnedPoints()`, 장바구니·주문서 요약은
 * `Summary::mileage_formatted` 가 같은 식을 쓴다 — 서비스가 만드는 비교 스냅샷(신청 미리보기)은
 * 리소스 트레이트를 쓸 수 없어 이 클래스가 같은 식을 낸다. 세 곳(리소스 트레이트·`Summary`·서비스
 * 스냅샷)이 모두 이 클래스를 거치고, 단위 글자는 다국어 키(`messages.mileage.points_format`)가 정한다.
 */
final class EarnedPointsFormatter
{
    /**
     * 적립 포인트를 `1,234P` 형태로 표기합니다.
     *
     * @param  float|int|null  $points  적립 포인트 (null 은 0 으로 처리)
     * @return string 포인트 표기
     */
    public static function format(float|int|null $points): string
    {
        // 단위 글자는 다국어 키가 정한다 — 코드에 `'P'` 를 박으면 로케일마다 바꿀 자리가 없다.
        return __('sirsoft-ecommerce::messages.mileage.points_format', [
            'points' => number_format((float) ($points ?? 0)),
        ]);
    }
}
