<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use Modules\Sirsoft\Ecommerce\Support\MileageRounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\PureTestCase;

/**
 * 적립 마일리지 절사 규칙 SSoT 단위 테스트
 *
 * 이 클래스가 규칙 해석과 적용을 단독으로 책임진다. 계산·안분·스냅샷 세 지점이 모두
 * 여기를 거치므로, 폴백이 종전 하드코딩(`(int) floor`)과 어긋나면 기존 사이트의 적립액이
 * 아무도 설정을 바꾸지 않았는데 달라진다.
 */
class MileageRoundingTest extends PureTestCase
{
    /**
     * 절사 규칙이 없거나 비어 있으면 1점 버림으로 해석되고, 그 결과가 종전 (int) floor 와 같은 금액이 된다.
     *
     * @scenario rule_source=legacy_absent, rounding_unit=1, rounding_method=floor
     *
     * @effects fallback_matches_legacy_int_floor
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 규칙이_없으면_종전_동작인_1점_버림으로_폴백한다(): void
    {
        $this->assertSame(['unit' => '1', 'method' => 'floor'], MileageRounding::normalize(null));
        $this->assertSame(['unit' => '1', 'method' => 'floor'], MileageRounding::normalize([]));

        // 폴백 등가성: 절사 전 값이 무엇이든 (int) floor 와 같은 결과여야 한다
        foreach ([0, 0.4, 1.0, 781.2, 781.6, 1234.999] as $raw) {
            $this->assertSame(
                (int) floor($raw),
                MileageRounding::apply($raw),
                "폴백이 종전 (int) floor 동작과 어긋난다 (입력: {$raw})"
            );
        }
    }

    /**
     * 허용 목록 밖의 단위·방식 값은 임의 방식으로 해석하지 않고 1점 버림 기본값으로 접는다.
     *
     * @scenario settings_input=out_of_vocabulary
     *
     * @effects out_of_vocabulary_rule_folds_to_default
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 허용되지_않은_값은_기본값으로_접는다(): void
    {
        $folded = MileageRounding::normalize([
            MileageRounding::UNIT_KEY => '7',        // 허용 목록 밖
            MileageRounding::METHOD_KEY => 'trunc',  // 허용 목록 밖
        ]);

        // 해석 불가한 값에서 임의 방식을 고르면 지급액이 조용히 달라진다 — 기본값으로 접는다.
        $this->assertSame(['unit' => '1', 'method' => 'floor'], $folded);
    }

    /**
     * 절사 단위(1/10/100)와 방식(버림/반올림/올림) 조합별 입력값과 기대 적립액을 제공한다.
     *
     * @return array<string, array{0: string, 1: string, 2: float, 3: int}> 사례 이름별 [단위, 방식, 절사 전 값, 기대 적립액]
     */
    public static function 절사_조합(): array
    {
        return [
            '1점 버림' => ['1', 'floor', 781.6, 781],
            '1점 반올림' => ['1', 'round', 781.6, 782],
            '1점 올림' => ['1', 'ceil', 781.2, 782],
            '10점 버림' => ['10', 'floor', 781.6, 780],
            '10점 반올림' => ['10', 'round', 785.0, 790],
            '10점 올림' => ['10', 'ceil', 781.0, 790],
            '100점 버림' => ['100', 'floor', 781.6, 700],
            '100점 반올림' => ['100', 'round', 781.6, 800],
            '100점 올림' => ['100', 'ceil', 701.0, 800],
            '0점은 어떤 조합에서도 0' => ['100', 'ceil', 0.0, 0],
        ];
    }

    /**
     * 지정한 절사 단위와 방식이 그대로 적용되어 기대 적립액이 산출되고, 0점은 어떤 조합에서도 0 이다.
     *
     * @scenario rounding_unit=100, rounding_method=ceil, rule_source=live_settings
     *
     * @effects default_rate_branch_applies_rounding_rule
     *
     * @param  string  $unit  절사 단위(1·10·100)
     * @param  string  $method  절사 방식(floor·round·ceil)
     * @param  float  $raw  절사 전 적립 포인트
     * @param  int  $expected  기대 적립 포인트
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('절사_조합')]
    public function 단위와_방식_조합이_그대로_적용된다(string $unit, string $method, float $raw, int $expected): void
    {
        $result = MileageRounding::applyRule($raw, [
            MileageRounding::UNIT_KEY => $unit,
            MileageRounding::METHOD_KEY => $method,
        ]);

        $this->assertSame($expected, $result);
    }

    /**
     * 7810 × 0.1 처럼 부동소수 오차로 780.999… 가 되는 값도 버림에서 781 로 유지되어 1점이 깎이지 않는다.
     *
     * @scenario rounding_unit=1, rounding_method=floor
     *
     * @effects float_error_does_not_shave_one_unit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 부동소수_오차가_한_단위를_깎지_않는다(): void
    {
        // 7810 × 0.1 은 780.9999999999999 로 계산돼 버림에서 780 이 된다.
        // 절사 기준 자체와 무관한 계산 잔여물이 지급액을 1점 깎으면 안 된다.
        $this->assertSame(781, MileageRounding::apply(7810 * 0.1));
    }

    /**
     * 주문 마일리지 정책 스냅샷의 rule 에 기록된 절사 단위·방식을 그대로 읽어 낸다.
     *
     * @scenario rule_source=order_snapshot, rounding_unit=100, rounding_method=ceil
     *
     * @effects recalculation_uses_snapshot_not_live_settings
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 주문_스냅샷에서_절사_기준을_읽는다(): void
    {
        $snapshot = [
            'usable' => true,
            'currency' => 'KRW',
            'rule' => [
                'use_unit' => 10,
                MileageRounding::UNIT_KEY => '100',
                MileageRounding::METHOD_KEY => 'ceil',
            ],
        ];

        $this->assertSame(
            ['unit' => '100', 'method' => 'ceil'],
            MileageRounding::fromOrderSnapshot($snapshot)
        );
    }

    /**
     * 절사 키가 없는 구형 주문 스냅샷이나 스냅샷 부재는 1점 버림 기본값으로 해석되어 재계산 결과가 보존된다.
     *
     * @scenario rule_source=legacy_absent, rounding_unit=1, rounding_method=floor
     *
     * @effects legacy_order_snapshot_resolves_to_default
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 이_기능_이전_주문의_스냅샷은_종전_동작으로_해석된다(): void
    {
        // 절사 키가 없던 시절의 스냅샷 — 그때의 동작이 곧 기본값이므로 재계산 결과가 보존된다.
        $legacy = [
            'usable' => true,
            'currency' => 'KRW',
            'rule' => ['point_value' => 1.0, 'use_unit' => 10, 'max_use_type' => 'percent'],
        ];

        $this->assertSame(['unit' => '1', 'method' => 'floor'], MileageRounding::fromOrderSnapshot($legacy));
        $this->assertSame(['unit' => '1', 'method' => 'floor'], MileageRounding::fromOrderSnapshot(null));
    }
}
