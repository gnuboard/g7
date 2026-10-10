<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateProductRequest;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;
use Modules\Sirsoft\Ecommerce\Models\ProductNotice;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 수정 — 이미 저장된 다국어 이름은 사이트 기본 언어 필수에서 면제한다
 *
 * B3 로 필수 언어가 사이트 기본 언어(ko)가 되자, 그 전에 영어 관리자가 영어로만 저장한 옵션 그룹 이름 등은
 * 수정 화면에 고칠 칸이 없는데도 저장할 때마다 「ko 값 필수」로 막혔다(제품 결정 2026-10-08 — 기존 행 필수 면제,
 * 설정 카탈로그·클레임 사유와 같은 방식). 저장된 값과 **같은** 이름은 길이·형식만 보고, 바꾸거나 새로 넣은
 * 이름은 종전대로 사이트 기본 언어가 필수다.
 */
class UpdateProductLegacyLocaleNamesTest extends ModuleTestCase
{
    /**
     * 사이트 기본 언어 ko · 요청 언어 ko 로 시작한다(한국어 관리자가 저장하는 상황)
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.site_locale' => 'ko']);
        app()->setLocale('ko');
    }

    /**
     * 영어로만 저장된 옵션 그룹·옵션·추가옵션·고시를 가진 상품(저장하지 않은 메모리 모델)을 만든다.
     *
     * @return Product 관계가 채워진 상품
     */
    private function legacyProduct(): Product
    {
        $product = new Product;
        $product->id = 501;
        $product->product_code = 'LEGACY-EN';
        $product->option_groups = [['name' => ['en' => 'Color'], 'values' => [['en' => 'Red']]]];

        $option = new ProductOption;
        $option->id = 9001;
        $option->option_name = ['en' => 'Color: Red'];
        $product->setRelation('options', new Collection([$option]));

        $value = new ProductAdditionalOptionValue;
        $value->name = ['en' => 'Gift wrap'];
        $additional = new ProductAdditionalOption;
        $additional->name = ['en' => 'Extras'];
        $additional->setRelation('values', new Collection([$value]));
        $product->setRelation('additionalOptions', new Collection([$additional]));

        $notice = new ProductNotice;
        $notice->values = [['name' => ['en' => 'Material'], 'content' => ['en' => 'Cotton']]];
        $product->setRelation('notice', $notice);

        return $product;
    }

    /**
     * 수정 요청 하나를 그 상품 경로로 검증한다.
     *
     * @param  array<string, mixed>  $data  입력
     * @param  Product  $product  경로에 바인딩된 상품
     * @return \Illuminate\Validation\Validator 검증기
     */
    private function check(array $data, Product $product): \Illuminate\Validation\Validator
    {
        $request = new class extends UpdateProductRequest
        {
            /** @var Product|null 시험용 경로 상품 */
            public ?Product $boundProduct = null;

            /**
             * 경로 인자를 시험용 상품으로 돌려준다.
             *
             * @param  string|null  $param  경로 인자 이름
             * @param  mixed  $default  기본값
             * @return mixed 경로 인자 값
             */
            public function route($param = null, $default = null)
            {
                return $param === 'product' ? $this->boundProduct : $default;
            }
        };
        $request->boundProduct = $product;
        $request->merge($data);
        $validator = Validator::make($request->all(), $request->rules());
        $validator->passes();

        return $validator;
    }

    /**
     * 그 속성에 「사이트 기본 언어(ko) 필수」 오류가 있는가.
     *
     * @param  \Illuminate\Validation\Validator  $v  검증기
     * @param  string  $key  속성 경로
     * @return bool 필수 언어 오류가 있으면 true
     */
    private function requiresSiteLocale(\Illuminate\Validation\Validator $v, string $key): bool
    {
        $expected = __('validation.translatable.current_locale_required', ['locale' => 'ko']);

        return in_array($expected, $v->errors()->get($key), true);
    }

    /**
     * 수정 화면이 손대지 않고 되보내는 영어 전용 이름 5종은 ko 가 비어도 막지 않는다
     *
     * @scenario case=unchanged_legacy_names
     *
     * @effects legacy_names_exempt_from_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_unchanged_english_only_names_are_not_blocked(): void
    {
        $v = $this->check([
            'option_groups' => [['name' => ['en' => 'Color', 'ko' => ''], 'values' => [['en' => 'Red']]]],
            'options' => [['id' => 9001, 'option_name' => ['en' => 'Color: Red']]],
            'additional_options' => [['name' => ['en' => 'Extras'], 'values' => [['name' => ['en' => 'Gift wrap']]]]],
            'notice_items' => [['name' => ['en' => 'Material'], 'content' => ['en' => 'Cotton']]],
        ], $this->legacyProduct());

        foreach (['option_groups.0.name', 'options.0.option_name', 'additional_options.0.name', 'additional_options.0.values.0.name', 'notice_items.0.name', 'notice_items.0.content'] as $key) {
            $this->assertFalse($v->errors()->has($key), $key.' : '.$v->errors()->toJson());
        }
    }

    /**
     * 이름을 바꾸거나 새로 넣으면 종전대로 사이트 기본 언어가 필수다
     *
     * @scenario case=changed_or_new_names
     *
     * @effects changed_names_still_require_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_changed_or_new_english_only_names_still_require_site_locale(): void
    {
        $v = $this->check([
            'option_groups' => [
                ['name' => ['en' => 'Colour'], 'values' => [['en' => 'Red']]],
                ['name' => ['en' => 'Size'], 'values' => [['en' => 'L']]],
            ],
            'options' => [['id' => 9001, 'option_name' => ['en' => 'Colour: Red']]],
            'additional_options' => [['name' => ['en' => 'Extras 2'], 'values' => [['name' => ['en' => 'Card']]]]],
            'notice_items' => [['name' => ['en' => 'Origin'], 'content' => ['en' => 'Korea']]],
        ], $this->legacyProduct());

        foreach (['option_groups.0.name', 'option_groups.1.name', 'options.0.option_name', 'additional_options.0.name', 'additional_options.0.values.0.name', 'notice_items.0.name', 'notice_items.0.content'] as $key) {
            $this->assertTrue($this->requiresSiteLocale($v, $key), $key.' : '.$v->errors()->toJson());
        }
    }

    /**
     * 면제를 받아도 형식은 본다 — 빈 이름·길이 초과는 막는다
     *
     * @scenario case=unchanged_legacy_names
     *
     * @effects legacy_names_keep_format_check
     *
     * @return void 반환값 없음
     */
    public function test_exempt_names_still_get_format_check(): void
    {
        $product = $this->legacyProduct();
        $product->option_groups = [['name' => ['en' => str_repeat('a', 120)], 'values' => [['en' => 'Red']]]];

        $v = $this->check([
            'option_groups' => [['name' => ['en' => str_repeat('a', 120)], 'values' => [['en' => 'Red']]]],
        ], $product);

        $this->assertTrue($v->errors()->has('option_groups.0.name'), $v->errors()->toJson());
        $this->assertFalse($this->requiresSiteLocale($v, 'option_groups.0.name'), $v->errors()->toJson());
    }
}
