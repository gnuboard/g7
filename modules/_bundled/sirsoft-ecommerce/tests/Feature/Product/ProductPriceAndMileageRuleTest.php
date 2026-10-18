<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Product;

use Illuminate\Contracts\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateOptionsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateProductsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreProductRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 가격·적립률 검증 규칙 테스트 (E2 · E4)
 *
 * - E2: 옵션별 정률 적립률(`mileage_value` + `mileage_type=percent`)에 상한이 없었다.
 *   전역 `default_earn_rate` 는 `max:100` 인데 동일한 `/100` 계산에 쓰이는 옵션 값은 무제한이었다.
 * - E4: 단건 등록/수정은 통화 소수자릿수 규칙 + `min:0.01` + `lte:list_price` 를 적용하는데
 *   일괄 수정은 `['nullable','numeric','min:0']` 뿐이라 우회로가 됐다.
 */
class ProductPriceAndMileageRuleTest extends ModuleTestCase
{
    /**
     * FormRequest 를 요청 데이터로 채워 검증기를 실행합니다.
     *
     * @param  class-string  $requestClass  FormRequest 클래스
     * @param  array  $data  요청 데이터
     * @return Validator 실행된 검증기
     */
    private function validate(string $requestClass, array $data)
    {
        $request = $requestClass::create('/', 'POST', $data);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);

        $validator = $this->app['validator']->make($data, $request->rules());
        $request->setValidator($validator);

        if (method_exists($request, 'withValidator')) {
            $request->withValidator($validator);
        }

        $validator->passes();

        return $validator;
    }

    // ========================================
    // E2 — 옵션별 정률 적립률 상한
    // ========================================

    /**
     * 정률 적립률 100 초과는 차단되는지 테스트합니다.
     *
     * @scenario surface=product_options, field=mileage_value, mileage_type=percent
     *
     * @effects percent_mileage_over_100_rejected
     */
    public function test_percent_mileage_over_100_is_rejected(): void
    {
        $validator = $this->validate(StoreProductRequest::class, [
            'options' => [
                ['mileage_type' => 'percent', 'mileage_value' => 1000],
            ],
        ]);

        $this->assertTrue($validator->errors()->has('options.0.mileage_value'));
    }

    /**
     * 정률 100 은 통과하는지 테스트합니다 (경계).
     *
     * @scenario surface=product_options, field=mileage_value, mileage_type=percent
     *
     * @effects percent_mileage_at_bound_allowed
     */
    public function test_percent_mileage_exactly_100_is_allowed(): void
    {
        $validator = $this->validate(StoreProductRequest::class, [
            'options' => [
                ['mileage_type' => 'percent', 'mileage_value' => 100],
            ],
        ]);

        $this->assertFalse($validator->errors()->has('options.0.mileage_value'));
    }

    /**
     * 정액(fixed) 적립은 금액이므로 상한을 두지 않는지 테스트합니다.
     *
     * @scenario surface=product_options, field=mileage_value, mileage_type=fixed
     *
     * @effects fixed_mileage_has_no_upper_bound
     */
    public function test_fixed_mileage_has_no_upper_bound(): void
    {
        $validator = $this->validate(StoreProductRequest::class, [
            'options' => [
                ['mileage_type' => 'fixed', 'mileage_value' => 5000],
            ],
        ]);

        $this->assertFalse($validator->errors()->has('options.0.mileage_value'));
    }

    // ========================================
    // E4 — 일괄 수정 가격 규칙
    // ========================================

    /**
     * KRW(소수 0자리)에서 소수 가격이 차단되는지 테스트합니다.
     *
     * @scenario surface=bulk_products, field=price, mileage_type=none
     *
     * @effects bulk_price_respects_currency_precision
     */
    public function test_bulk_product_price_rejects_decimals_in_zero_precision_currency(): void
    {
        $validator = $this->validate(BulkUpdateProductsRequest::class, [
            'items' => [
                ['id' => 1, 'list_price' => 1000.5],
            ],
        ]);

        $this->assertTrue($validator->errors()->has('items.0.list_price'));
    }

    /**
     * 판매가가 정가보다 크면 차단되는지 테스트합니다 (둘 다 전송된 경우).
     *
     * @scenario surface=bulk_products, field=price_relation, mileage_type=none
     *
     * @effects bulk_selling_price_cannot_exceed_list_price
     */
    public function test_bulk_product_selling_price_cannot_exceed_list_price(): void
    {
        $validator = $this->validate(BulkUpdateProductsRequest::class, [
            'items' => [
                ['id' => 1, 'list_price' => 10000, 'selling_price' => 20000],
            ],
        ]);

        $this->assertTrue($validator->errors()->has('items.0.selling_price'));
    }

    /**
     * 한쪽만 전송된 부분 수정은 비교하지 않는지 테스트합니다.
     *
     * 일괄 수정은 부분 전송이 흔하다 — 여기서 막으면 정상 사용이 불가능해진다.
     *
     * @scenario surface=bulk_products, field=price_relation, mileage_type=none
     *
     * @effects bulk_partial_send_deferred_to_service
     */
    public function test_bulk_product_partial_price_update_is_not_compared(): void
    {
        $validator = $this->validate(BulkUpdateProductsRequest::class, [
            'items' => [
                ['id' => 1, 'selling_price' => 20000],
            ],
        ]);

        $this->assertFalse($validator->errors()->has('items.0.selling_price'));
    }

    /**
     * 0원 가격이 차단되는지 테스트합니다.
     *
     * @scenario surface=bulk_products, field=price, mileage_type=none
     *
     * @effects bulk_product_price_zero_rejected
     */
    public function test_bulk_product_price_zero_is_rejected(): void
    {
        $validator = $this->validate(BulkUpdateProductsRequest::class, [
            'items' => [
                ['id' => 1, 'list_price' => 0],
            ],
        ]);

        $this->assertTrue($validator->errors()->has('items.0.list_price'));
    }

    /**
     * 정상 가격은 통과하는지 테스트합니다.
     *
     * @scenario surface=bulk_products, field=price, mileage_type=none
     *
     * @effects bulk_valid_price_passes
     */
    public function test_bulk_product_valid_price_passes(): void
    {
        $validator = $this->validate(BulkUpdateProductsRequest::class, [
            'items' => [
                ['id' => 1, 'list_price' => 20000, 'selling_price' => 15000],
            ],
        ]);

        $this->assertFalse($validator->errors()->has('items.0.list_price'));
        $this->assertFalse($validator->errors()->has('items.0.selling_price'));
    }

    /**
     * 옵션 일괄 수정도 통화 정밀도 규칙을 적용하는지 테스트합니다.
     *
     * @scenario surface=bulk_options, field=price, mileage_type=none
     *
     * @effects bulk_price_respects_currency_precision
     */
    public function test_bulk_option_price_rejects_decimals(): void
    {
        $validator = $this->validate(BulkUpdateOptionsRequest::class, [
            'items' => [
                ['product_id' => 1, 'option_id' => 1, 'list_price' => 1000.5],
            ],
        ]);

        $this->assertTrue($validator->errors()->has('items.0.list_price'));
    }

    /**
     * 옵션 가격 0 은 상품 가격과 달리 허용되는지 테스트합니다.
     *
     * 상품 자신의 가격은 `min:0.01`(0원 상품 금지)이지만, 옵션 가격 0 은 "이 옵션은 별도
     * 가격이 없다" 는 정상 입력이다 — 단건 등록(`StoreProductRequest` 의 `options.*.list_price`)
     * 도 `min:0` 이다. 일괄 수정만 `min:0.01` 로 좁히면 단건에서 저장한 옵션을 일괄 화면에서는
     * 수정할 수 없게 된다. 두 경로의 경계가 같아야 한다는 것을 여기서 고정한다.
     *
     * @scenario surface=bulk_options, field=price, mileage_type=none
     *
     * @effects bulk_option_price_zero_allowed
     */
    public function test_bulk_option_price_zero_is_allowed_unlike_product_price(): void
    {
        $optionValidator = $this->validate(BulkUpdateOptionsRequest::class, [
            'items' => [
                ['product_id' => 1, 'option_id' => 1, 'list_price' => 0],
            ],
        ]);

        $this->assertFalse(
            $optionValidator->errors()->has('items.0.list_price'),
            '옵션 가격 0(별도 가격 없음)이 거부되면 단건 등록 경로와 어긋납니다.'
        );

        // 대조군 — 상품 자신의 가격 0 은 여전히 거부되어야 한다
        $productValidator = $this->validate(BulkUpdateProductsRequest::class, [
            'items' => [
                ['id' => 1, 'list_price' => 0],
            ],
        ]);

        $this->assertTrue($productValidator->errors()->has('items.0.list_price'));
    }
}
