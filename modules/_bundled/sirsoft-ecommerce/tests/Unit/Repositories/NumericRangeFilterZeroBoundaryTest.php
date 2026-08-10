<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

require_once __DIR__.'/../../ModuleTestCase.php';

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * 수치 범위 필터의 0 경계 회귀 가드 (#492 브라우저 실측).
 *
 * `! empty($filters['max_shipping_amount'])` 처럼 판정하면 0 이 "미입력"으로 취급돼
 * 필터가 통째로 무시된다. 실측에서 "배송비 0원(무료배송) 주문만 보기" 가 전체 100건을
 * 돌려줬고, 1 을 넣어야 47건이 나왔다.
 *
 * 소스 판정식을 직접 검사한다 — 데이터에 의존하지 않아 어떤 시드에서도 성립하고,
 * 새 범위 필터가 추가돼도 같은 규칙을 강제한다.
 */
#[Group('ecommerce')]
class NumericRangeFilterZeroBoundaryTest extends ModuleTestCase
{
    /**
     * 0 이 유효 경계값인 수치 범위 필터 키 목록.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function rangeFilterProvider(): array
    {
        $base = dirname(__DIR__, 3).'/src/Repositories/';

        return [
            '주문 금액·배송비 범위' => [
                $base.'OrderRepository.php',
                ['min_amount', 'max_amount', 'min_shipping_amount', 'max_shipping_amount'],
            ],
            '상품 가격·재고 범위' => [
                $base.'ProductRepository.php',
                ['min_price', 'max_price', 'min_stock', 'max_stock'],
            ],
            '쿠폰 혜택·주문금액 범위' => [
                $base.'CouponRepository.php',
                ['min_benefit_amount', 'max_benefit_amount', 'min_order_amount'],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $keys  검사할 필터 키 목록
     */
    #[DataProvider('rangeFilterProvider')]
    public function test_수치_범위_필터는_empty_판정으로_0을_버리지_않는다(string $file, array $keys): void
    {
        $this->assertFileExists($file);
        $source = file_get_contents($file);

        foreach ($keys as $key) {
            $pattern = "/!\s*empty\(\s*\\\$filters\[\s*'".preg_quote($key, '/')."'\s*\]\s*\)/";

            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $source,
                sprintf(
                    "%s 의 '%s' 필터가 empty() 로 판정되고 있습니다. 0 이 유효 경계값이므로 "
                    ."isset(\$filters['%s']) && \$filters['%s'] !== '' 형태를 사용해야 합니다.",
                    basename($file), $key, $key, $key
                )
            );

            // 필터 키가 실제로 사용되고는 있는지 (오탈자로 조용히 사라지는 것 방지)
            $this->assertStringContainsString(
                "'".$key."'",
                $source,
                sprintf('%s 에서 %s 필터 키를 찾지 못했습니다.', basename($file), $key)
            );
        }
    }
}
