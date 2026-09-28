<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database;

use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * 취소 기록의 사유 코드 컬럼 폭이 사유 카탈로그 코드 폭(50)과 같은지 고정한다.
 *
 * 설정·REST 모두 코드를 50자까지 받는데 취소 기록 칸이 30 이면, 31자 이상 코드는 즉시취소에서
 * 오류가 나거나 잘려 저장되고, 잘린 뒤에는 `countByCancelReasonType()` 이 원본 코드로 세어 0건이
 * 되어 **사용 중인 사유가 삭제 가드를 통과한다**. 교환·반품·환불 `reason_code` 는 이미 50 이며 함께 고정한다.
 */
class CancelReasonCodeColumnWidthTest extends ModuleTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function reasonCodeColumns(): array
    {
        return [
            'order_cancels.cancel_reason_type' => ['ecommerce_order_cancels', 'cancel_reason_type'],
            'order_options.cancel_reason' => ['ecommerce_order_options', 'cancel_reason'],
            // 교환·반품·환불 헤더의 reason_code — 이미 50 이지만 "코드 50 ↔ 기록 컬럼 폭 전수" 가
            // 이 파일의 목적이므로 서술만 하지 않고 단언한다(좁아지면 같은 삭제 가드 우회가 생긴다).
            'order_exchanges.reason_code' => ['ecommerce_order_exchanges', 'reason_code'],
            'order_returns.reason_code' => ['ecommerce_order_returns', 'reason_code'],
            'order_refunds.reason_code' => ['ecommerce_order_refunds', 'reason_code'],
            'claim_reasons.code (기준)' => ['ecommerce_claim_reasons', 'code'],
        ];
    }

    #[Test]
    #[DataProvider('reasonCodeColumns')]
    public function reason_code_columns_share_the_catalog_width(string $table, string $column): void
    {
        // 기대 폭을 리터럴로 적으면 카탈로그가 넓어졌을 때 여섯 행이 한꺼번에 붉어지고,
        // 그중 무엇이 기준인지 실패 문구만으로는 드러나지 않는다. 기준 컬럼에서 파생한다.
        $catalog = collect(Schema::getColumns('ecommerce_claim_reasons'))->firstWhere('name', 'code');

        $this->assertIsArray($catalog, '기준 컬럼 ecommerce_claim_reasons.code 가 없다');

        $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);

        $this->assertIsArray($definition, "{$table}.{$column} 컬럼이 없다");
        $this->assertSame(
            $catalog['type'],
            $definition['type'],
            "{$table}.{$column} 폭이 사유 카탈로그 코드 폭({$catalog['type']})과 다르다"
        );
    }
}
