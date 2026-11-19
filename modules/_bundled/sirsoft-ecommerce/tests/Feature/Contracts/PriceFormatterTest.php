<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts;

use Modules\Sirsoft\Ecommerce\Contracts\Currency\PriceFormatter;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 금액 표시 형식 공개 계약 (#916)
 *
 * 결제사 플러그인은 이커머스 전역 헬퍼 대신 이 계약으로 금액 문자열을 만든다. 결과는 헬퍼와 같아야 한다.
 */
class PriceFormatterTest extends ModuleTestCase
{
    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 통화를 주든 안 주든 전역 헬퍼 ecommerce_format_price 와 같은 문자열이다.
     *
     * @scenario case=price_formatter_mirrors_helper
     *
     * @effects price_formatter_output_equals_helper
     *
     * @return void 반환값 없음
     */
    public function test_format_mirrors_global_helper(): void
    {
        $formatter = app(PriceFormatter::class);

        $this->assertSame(ecommerce_format_price(12345), $formatter->format(12345));
        $this->assertSame(ecommerce_format_price(12345, 'KRW'), $formatter->format(12345, 'KRW'));
        $this->assertSame(ecommerce_format_price(12.5, 'USD'), $formatter->format(12.5, 'USD'));
    }
}
