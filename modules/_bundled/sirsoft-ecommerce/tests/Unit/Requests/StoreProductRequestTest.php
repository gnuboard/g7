<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreProductRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 생성 요청 검증 테스트
 */
class StoreProductRequestTest extends ModuleTestCase
{
    /**
     * 검증 수행
     *
     * @param array $data
     * @return \Illuminate\Validation\Validator
     */
    protected function validate(array $data): \Illuminate\Validation\Validator
    {
        $request = new StoreProductRequest();

        return Validator::make($data, $request->rules());
    }

    /**
     * 기본 유효한 상품 데이터
     *
     * @return array
     */
    protected function validProductData(): array
    {
        $category = new \Modules\Sirsoft\Ecommerce\Models\Category([
            'name' => ['ko' => '테스트 카테고리', 'en' => 'Test Category'],
            'slug' => 'test-category',
            'is_active' => true,
            'depth' => 0,
        ]);
        $category->path = 'temp';
        $category->save();
        $category->generatePath();
        $category->save();

        return [
            'name' => ['ko' => '테스트 상품'],
            'product_code' => 'TEST-001',
            'category_ids' => [$category->id],
            'list_price' => 10000,
            'selling_price' => 8000,
            'stock_quantity' => 100,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'options' => [
                [
                    'option_code' => 'OPT-001',
                    'option_name' => '기본 옵션',
                    'option_values' => ['기본'],
                    'list_price' => 10000,
                    'selling_price' => 8000,
                    'stock_quantity' => 100,
                ],
            ],
        ];
    }

    // ========================================
    // 다국어 로케일 동적 처리 테스트
    // ========================================

    public function test_rules_generates_dynamic_locale_keys_for_name(): void
    {
        $request = new StoreProductRequest();
        $rules = $request->rules();

        $locales = config('app.translatable_locales', ['ko', 'en']);
        $primaryLocale = $locales[0];

        // 기본 로케일은 required
        $this->assertArrayHasKey("name.{$primaryLocale}", $rules);
        $this->assertContains('required', $rules["name.{$primaryLocale}"]);

        // 나머지 로케일은 nullable
        foreach (array_slice($locales, 1) as $locale) {
            $this->assertArrayHasKey("name.{$locale}", $rules);
            $this->assertContains('nullable', $rules["name.{$locale}"]);
            $this->assertNotContains('required', $rules["name.{$locale}"]);
        }

        // 하드코딩된 'name.ko' 키가 없어야 함 (동적으로 생성되므로 키 자체는 있을 수 있지만 하드코딩은 아님)
        // 대신 name.* 와일드카드 존재 확인
        $this->assertArrayHasKey('name.*', $rules);
    }

    public function test_rules_generates_dynamic_locale_keys_for_description(): void
    {
        $request = new StoreProductRequest();
        $rules = $request->rules();

        $locales = config('app.translatable_locales', ['ko', 'en']);

        foreach ($locales as $locale) {
            $this->assertArrayHasKey("description.{$locale}", $rules);
            $this->assertContains('nullable', $rules["description.{$locale}"]);
        }
    }

    public function test_rules_generates_dynamic_locale_keys_for_additional_options_name(): void
    {
        $request = new StoreProductRequest();
        $rules = $request->rules();

        $locales = config('app.translatable_locales', ['ko', 'en']);
        $primaryLocale = $locales[0];

        // 기본 로케일은 required_with
        $this->assertArrayHasKey("additional_options.*.name.{$primaryLocale}", $rules);
        $this->assertContains('required_with:additional_options', $rules["additional_options.*.name.{$primaryLocale}"]);

        // 나머지 로케일은 nullable
        foreach (array_slice($locales, 1) as $locale) {
            $this->assertArrayHasKey("additional_options.*.name.{$locale}", $rules);
            $this->assertContains('nullable', $rules["additional_options.*.name.{$locale}"]);
        }
    }

    public function test_rules_all_locale_fields_have_string_and_max_constraints(): void
    {
        $request = new StoreProductRequest();
        $rules = $request->rules();

        $locales = config('app.translatable_locales', ['ko', 'en']);

        foreach ($locales as $locale) {
            // name: string, max:200
            $this->assertContains('string', $rules["name.{$locale}"]);
            $this->assertContains('max:200', $rules["name.{$locale}"]);

            // description: string
            $this->assertContains('string', $rules["description.{$locale}"]);

            // additional_options.*.name: string, max:100
            $this->assertContains('string', $rules["additional_options.*.name.{$locale}"]);
            $this->assertContains('max:100', $rules["additional_options.*.name.{$locale}"]);
        }
    }

    // ========================================
    // messages() 키 완전성 테스트
    // ========================================

    public function test_messages_contains_all_required_field_messages(): void
    {
        $request = new StoreProductRequest();
        $messages = $request->messages();

        $primaryLocale = config('app.translatable_locales', ['ko', 'en'])[0];

        $expectedKeys = [
            'name.required',
            "name.{$primaryLocale}.required",
            'product_code.required',
            'product_code.unique',
            'list_price.required',
            'selling_price.required',
            'selling_price.lte',
            'stock_quantity.required',
            'sales_status.required',
            'sales_status.in',
            'display_status.required',
            'display_status.in',
            'tax_status.required',
            'tax_status.in',
            'category_ids.required',
            'category_ids.min',
            'category_ids.max',
            'options.required',
            'options.min',
            'options.*.option_code.required_with',
            'options.*.option_name.required_with',
            'options.*.option_values.required_with',
            'options.*.list_price.required_with',
            'options.*.selling_price.required_with',
            'options.*.stock_quantity.required_with',
            'label_assignments.*.label_id.required',
            'label_assignments.*.label_id.exists',
            'label_assignments.*.end_date.after_or_equal',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $messages, "메시지 키 '{$key}'가 누락되었습니다.");
        }
    }

    public function test_messages_does_not_contain_dead_code(): void
    {
        $request = new StoreProductRequest();
        $messages = $request->messages();

        // options.*.selling_price에는 lte 규칙이 없으므로 메시지도 없어야 함
        $this->assertArrayNotHasKey('options.*.selling_price.lte', $messages);
    }

    public function test_messages_use_dynamic_primary_locale(): void
    {
        $request = new StoreProductRequest();
        $messages = $request->messages();

        $primaryLocale = config('app.translatable_locales', ['ko', 'en'])[0];

        // 동적 로케일 키가 존재해야 함
        $this->assertArrayHasKey("name.{$primaryLocale}.required", $messages);

        // 하드코딩된 'name.ko.required' 키가 아닌 동적 키 사용 확인
        // (현재 primary가 ko이면 같은 키이지만, 메시지 값은 name_primary 키를 참조)
        $messageValue = $messages["name.{$primaryLocale}.required"];
        $this->assertNotEmpty($messageValue);
    }

    // ========================================
    // 다국어 메시지 키 존재 확인 테스트
    // ========================================

    public function test_all_message_translation_keys_exist(): void
    {
        $request = new StoreProductRequest();
        $messages = $request->messages();

        foreach ($messages as $field => $translatedMessage) {
            // __() 함수가 번역 키를 찾지 못하면 키 자체를 반환함
            // 'sirsoft-ecommerce::validation.' 으로 시작하는 값이면 번역이 안 된 것
            $this->assertStringNotContainsString(
                'sirsoft-ecommerce::validation.',
                $translatedMessage,
                "필드 '{$field}'의 번역 키가 validation.json에 존재하지 않습니다: {$translatedMessage}"
            );
        }
    }

    // ========================================
    // 필드별 유효성 검사 테스트
    // ========================================

    public function test_valid_product_data_passes(): void
    {
        $data = $this->validProductData();
        $validator = $this->validate($data);

        $this->assertFalse($validator->fails(), '유효한 상품 데이터가 검증을 통과해야 합니다: ' . json_encode($validator->errors()->toArray()));
    }

    public function test_name_is_required(): void
    {
        $data = $this->validProductData();
        unset($data['name']);
        $validator = $this->validate($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    public function test_primary_locale_name_is_required(): void
    {
        $primaryLocale = config('app.translatable_locales', ['ko', 'en'])[0];

        $data = $this->validProductData();
        $data['name'] = ['en' => 'Test Product']; // 기본 로케일 누락
        $validator = $this->validate($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey("name.{$primaryLocale}", $validator->errors()->toArray());
    }

    public function test_non_primary_locale_name_is_optional(): void
    {
        $data = $this->validProductData();
        // 기본 로케일만 있고 나머지는 없어도 통과
        $data['name'] = ['ko' => '테스트 상품'];
        $validator = $this->validate($data);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_selling_price_must_be_lte_list_price(): void
    {
        $data = $this->validProductData();
        $data['selling_price'] = 15000; // list_price(10000)보다 큼
        $validator = $this->validate($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('selling_price', $validator->errors()->toArray());
    }

    public function test_stock_quantity_is_required(): void
    {
        $data = $this->validProductData();
        unset($data['stock_quantity']);
        $validator = $this->validate($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('stock_quantity', $validator->errors()->toArray());
    }

    public function test_sales_status_must_be_valid_enum(): void
    {
        $data = $this->validProductData();
        $data['sales_status'] = 'invalid_status';
        $validator = $this->validate($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('sales_status', $validator->errors()->toArray());
    }

    public function test_display_status_must_be_valid_enum(): void
    {
        $data = $this->validProductData();
        $data['display_status'] = 'invalid_status';
        $validator = $this->validate($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('display_status', $validator->errors()->toArray());
    }

    public function test_tax_status_must_be_valid_enum(): void
    {
        $data = $this->validProductData();
        $data['tax_status'] = 'invalid_status';
        $validator = $this->validate($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tax_status', $validator->errors()->toArray());
    }

    public function test_category_ids_requires_at_least_one(): void
    {
        $data = $this->validProductData();
        $data['category_ids'] = [];
        $validator = $this->validate($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('category_ids', $validator->errors()->toArray());
    }

    public function test_category_ids_max_five(): void
    {
        $data = $this->validProductData();
        $data['category_ids'] = [1, 2, 3, 4, 5, 6]; // 6개
        $validator = $this->validate($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('category_ids', $validator->errors()->toArray());
    }

    public function test_options_is_required(): void
    {
        $data = $this->validProductData();
        unset($data['options']);
        $validator = $this->validate($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('options', $validator->errors()->toArray());
    }

    public function test_options_requires_at_least_one(): void
    {
        $data = $this->validProductData();
        $data['options'] = [];
        $validator = $this->validate($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('options', $validator->errors()->toArray());
    }

    // ========================================
    // 이미지 alt_text 다국어 객체 검증 테스트
    // ========================================

    public function test_images_alt_text_accepts_translatable_array(): void
    {
        $data = $this->validProductData();
        $data['images'] = [
            [
                'id' => 1,
                'alt_text' => ['ko' => '테스트 이미지', 'en' => 'Test Image'],
                'is_thumbnail' => true,
                'sort_order' => 0,
            ],
        ];
        $validator = $this->validate($data);

        $errors = $validator->errors()->toArray();
        $this->assertArrayNotHasKey('images.0.alt_text', $errors, '다국어 alt_text 배열은 검증을 통과해야 합니다.');
    }

    public function test_images_alt_text_accepts_null(): void
    {
        $data = $this->validProductData();
        $data['images'] = [
            [
                'id' => 1,
                'alt_text' => null,
                'is_thumbnail' => true,
                'sort_order' => 0,
            ],
        ];
        $validator = $this->validate($data);

        $errors = $validator->errors()->toArray();
        $this->assertArrayNotHasKey('images.0.alt_text', $errors, 'null alt_text는 검증을 통과해야 합니다.');
    }

    // ========================================
    // notice_items 필드명 content 검증 테스트
    // ========================================

    public function test_options_id_is_included_in_rules(): void
    {
        $request = new StoreProductRequest();
        $rules = $request->rules();

        $this->assertArrayHasKey('options.*.id', $rules, 'options.*.id 규칙이 존재해야 합니다 (validated()에서 id 유지).');
        $this->assertContains('nullable', $rules['options.*.id']);
        $this->assertContains('integer', $rules['options.*.id']);
    }

    // ========================================
    // notice_items 필드명 content 검증 테스트
    // ========================================

    public function test_notice_items_uses_content_field_not_value(): void
    {
        $request = new StoreProductRequest();
        $rules = $request->rules();

        // content 필드 규칙이 존재해야 함
        $this->assertArrayHasKey('notice_items.*.content', $rules, 'notice_items.*.content 규칙이 존재해야 합니다.');
        $this->assertArrayHasKey('notice_items.*.content.ko', $rules, 'notice_items.*.content.ko 규칙이 존재해야 합니다.');

        // value 필드 규칙은 존재하면 안 됨
        $this->assertArrayNotHasKey('notice_items.*.value', $rules, 'notice_items.*.value 규칙은 존재하면 안 됩니다.');
        $this->assertArrayNotHasKey('notice_items.*.value.ko', $rules, 'notice_items.*.value.ko 규칙은 존재하면 안 됩니다.');
    }

    public function test_notice_items_with_content_field_passes_validation(): void
    {
        $data = $this->validProductData();
        $data['notice_items'] = [
            [
                'name' => ['ko' => '항목1', 'en' => 'Field 1'],
                'content' => ['ko' => '상세페이지 참조', 'en' => 'See product page'],
            ],
        ];
        $validator = $this->validate($data);

        $errors = $validator->errors()->toArray();
        $this->assertArrayNotHasKey('notice_items.0.content', $errors, 'content 필드를 사용한 notice_items는 검증을 통과해야 합니다.');
        $this->assertArrayNotHasKey('notice_items.0.value', $errors, 'value 필드 관련 오류가 발생하면 안 됩니다.');
    }
}
