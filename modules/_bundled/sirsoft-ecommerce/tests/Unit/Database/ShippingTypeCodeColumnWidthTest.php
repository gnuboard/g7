<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\NestedRules;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 배송유형 카탈로그 코드 ↔ 그 코드를 기록하는 컬럼의 폭 파리티
 *
 * `CancelReasonCodeColumnWidthTest` 와 같은 축인데 카탈로그가 다르다 — 그 파일은 사유
 * 카탈로그(`ecommerce_claim_reasons.code`) 기준이라 배송유형 축을 덮지 못했고, 실제로
 * 사유 코드 폭 정비 때 이 카탈로그가 빠졌다.
 *
 * 좁아지면 증상이 조용하다: 긴 코드가 잘려 저장되면 `countByShippingType()` 이 원본
 * 코드로 세어 0건이 되므로 **사용 중인 배송유형이 삭제 가드를 통과**하고, 목록의 배송방법
 * 라벨은 카탈로그 조회에 실패해 빈칸이 된다. strict 모드면 그 전에 주문 생성이 500 이 된다.
 */
class ShippingTypeCodeColumnWidthTest extends ModuleTestCase
{
    #[Test]
    public function order_shipping_type_column_shares_the_catalog_width(): void
    {
        // 기대 폭을 리터럴로 적으면 카탈로그가 넓어졌을 때 이 단언이 "지금 상태" 를 다시
        // 적는 일이 된다. 기준 컬럼에서 파생해, 카탈로그가 움직이면 기록 컬럼도 따라가게 한다.
        $catalog = collect(Schema::getColumns('ecommerce_shipping_types'))->firstWhere('name', 'code');

        $this->assertIsArray($catalog, '기준 컬럼(ecommerce_shipping_types.code)을 읽지 못했다 — 파생이 비면 이 검사가 공전한다');
        $this->assertMatchesRegularExpression(
            '/^varchar\(\d+\)$/',
            (string) ($catalog['type'] ?? ''),
            '기준 컬럼이 varchar 가 아니다 — 폭 비교의 전제가 무너졌다'
        );

        $recorded = collect(Schema::getColumns('ecommerce_order_shippings'))->firstWhere('name', 'shipping_type');

        $this->assertIsArray($recorded, 'ecommerce_order_shippings.shipping_type 컬럼이 없다');
        $this->assertSame(
            $catalog['type'],
            $recorded['type'],
            'shipping_type 이 배송유형 카탈로그 코드보다 좁다 — 긴 코드가 잘려 저장되면 사용 중인 배송유형이 삭제 가드를 통과한다'
        );
    }

    /**
     * 저장 규칙의 상한도 같은 폭이어야 한다.
     *
     * 컬럼만 넓히고 규칙이 더 관대하면 같은 결함이 남고, 규칙이 더 좁으면 운영자가
     * 만들 수 없는 폭을 컬럼만 갖는다. 세 축(카탈로그 컬럼 · 기록 컬럼 · 저장 규칙)이
     * 같은 수를 보는지 함께 고정한다.
     */
    #[Test]
    public function the_settings_rule_caps_the_code_at_the_same_width(): void
    {
        $catalog = collect(Schema::getColumns('ecommerce_shipping_types'))->firstWhere('name', 'code');
        preg_match('/^varchar\((\d+)\)$/', (string) ($catalog['type'] ?? ''), $matches);

        $this->assertNotEmpty($matches, '기준 컬럼 폭을 읽지 못했다');

        // 파일 전문을 문자열로 훑으면 그 규칙과 결속되지 않는다. 실측(18차): 같은 파일에
        // 무관한 `max:50` 이 9건 있어 규칙을 지워도 대신 만족시키고, 그 9건이 전부 사라져도
        // `max:500` 6건이 **부분문자열**이라 규칙을 `max:30` 으로 좁혀도 초록이었다.
        // 그래서 그 규칙이 실제로 사는 자리(필드 키)에서 값을 꺼내 견준다.
        $field = 'shipping.types.*.code';
        $rules = StoreEcommerceSettingsRequest::create('/', 'PUT', [])->rules();

        $this->assertArrayHasKey(
            $field,
            $rules,
            "설정 규칙에 {$field} 가 없다 — 배송유형 코드 규칙의 자리가 바뀌었다(이 검사가 볼 대상이 사라졌다)"
        );

        $nested = $rules[$field];

        $this->assertInstanceOf(
            NestedRules::class,
            $nested,
            "{$field} 가 Rule::forEach 형태가 아니다 — 규칙 추출 전제가 무너졌다"
        );

        // 신규 행(id 없음) 경로로 컴파일한다 — 기존 행 면제 분기와 무관하게 상한은 공통이다.
        $attribute = 'shipping.types.0.code';
        $compiled = Arr::flatten($nested->compile($attribute, 'standard', [])->rules);

        $widths = collect($compiled)
            ->filter(fn ($rule) => is_string($rule))
            ->map(fn (string $rule) => preg_match('/^max:(\d+)$/', $rule, $found) ? $found[1] : null)
            ->filter()
            ->values();

        $this->assertCount(
            1,
            $widths,
            "{$attribute} 규칙에서 상한(max:N)을 정확히 하나 읽지 못했다 — 파생이 비면 이 검사가 공전한다"
        );

        $this->assertSame(
            $matches[1],
            $widths->first(),
            '배송유형 코드 규칙의 상한이 카탈로그 컬럼 폭과 다르다'
        );
    }
}
