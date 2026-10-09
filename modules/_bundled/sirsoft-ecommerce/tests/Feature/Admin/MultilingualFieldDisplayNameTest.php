<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\CreateCategoryRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreBrandRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreCouponRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreProductCommonInfoRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreProductNoticeTemplateRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreProductRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreShippingCarrierRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreShippingPolicyRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateBrandRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateCategoryRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateCouponRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateProductCommonInfoRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateProductNoticeTemplateRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateProductRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateShippingCarrierRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateShippingPolicyRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 이커머스 관리자 요청의 다국어 필수 칸 오류 문구가 칸 이름을 싣는다.
 *
 * 7.1.0 전수 실측(2026-10-08): 카테고리·브랜드·쿠폰·공통정보·상품정보제공고시·상품 옵션·배송사·배송정책 저장에서
 * 다국어 칸을 비우면 「한국어(ko) 값은 필수입니다.」처럼 칸 이름 없이 나왔다. 코어 규칙(LocaleRequiredTranslatable)은
 * 표시 이름이 정해진 칸에만 칸 이름을 싣는데, 이 요청들에 다국어 칸의 표시 이름 매핑이 없었다.
 * 같은 결함군인 환경설정 배송사·배송유형 신규 행 이름도 함께 확인한다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/admin-multilingual-field-display-name.yaml
 */
class MultilingualFieldDisplayNameTest extends ModuleTestCase
{
    /**
     * 요청 · 규칙 키 · 기대 표시 이름(ko, en) 목록.
     *
     * @return array<string, array{0: class-string<FormRequest>, 1: string, 2: string, 3: string}> 케이스 목록
     */
    public static function requestFieldProvider(): array
    {
        return [
            'category create name' => [CreateCategoryRequest::class, 'name', '카테고리명', 'Category Name'],
            'category update name' => [UpdateCategoryRequest::class, 'name', '카테고리명', 'Category Name'],
            'brand store name' => [StoreBrandRequest::class, 'name', '브랜드명', 'Brand Name'],
            'brand update name' => [UpdateBrandRequest::class, 'name', '브랜드명', 'Brand Name'],
            'coupon store name' => [StoreCouponRequest::class, 'name', '쿠폰명', 'Coupon Name'],
            'coupon update name' => [UpdateCouponRequest::class, 'name', '쿠폰명', 'Coupon Name'],
            'common info store name' => [StoreProductCommonInfoRequest::class, 'name', '공통정보명', 'Common Info Name'],
            'common info update name' => [UpdateProductCommonInfoRequest::class, 'name', '공통정보명', 'Common Info Name'],
            'notice template store name' => [StoreProductNoticeTemplateRequest::class, 'name', '상품군명', 'Product Group Name'],
            'notice template store field name' => [StoreProductNoticeTemplateRequest::class, 'fields.*.name', '항목명', 'Item Name'],
            'notice template store field content' => [StoreProductNoticeTemplateRequest::class, 'fields.*.content', '항목 내용', 'Item Content'],
            'notice template update name' => [UpdateProductNoticeTemplateRequest::class, 'name', '상품군명', 'Product Group Name'],
            'notice template update field name' => [UpdateProductNoticeTemplateRequest::class, 'fields.*.name', '항목명', 'Item Name'],
            'notice template update field content' => [UpdateProductNoticeTemplateRequest::class, 'fields.*.content', '항목 내용', 'Item Content'],
            'product store option group name' => [StoreProductRequest::class, 'option_groups.*.name', '옵션그룹명', 'option group name'],
            'product store additional option name' => [StoreProductRequest::class, 'additional_options.*.name', '추가옵션 그룹명', 'additional option group name'],
            'product store additional option value name' => [StoreProductRequest::class, 'additional_options.*.values.*.name', '추가옵션 선택지명', 'additional option choice name'],
            'product store notice item name' => [StoreProductRequest::class, 'notice_items.*.name', '상품정보제공고시 항목명', 'product notice item name'],
            'product store notice item content' => [StoreProductRequest::class, 'notice_items.*.content', '상품정보제공고시 내용', 'product notice content'],
            'product update option group name' => [UpdateProductRequest::class, 'option_groups.*.name', '옵션그룹명', 'option group name'],
            'product update notice item content' => [UpdateProductRequest::class, 'notice_items.*.content', '상품정보제공고시 내용', 'product notice content'],
            'carrier store name' => [StoreShippingCarrierRequest::class, 'name', '배송사명', 'Carrier Name'],
            'carrier update name' => [UpdateShippingCarrierRequest::class, 'name', '배송사명', 'Carrier Name'],
            'shipping policy store name' => [StoreShippingPolicyRequest::class, 'name', '배송정책명', 'Shipping Policy Name'],
            'shipping policy update name' => [UpdateShippingPolicyRequest::class, 'name', '배송정책명', 'Shipping Policy Name'],
            'settings new carrier name' => [StoreEcommerceSettingsRequest::class, 'shipping.carriers.*.name', '배송사명', 'Carrier name'],
            'settings new shipping type name' => [StoreEcommerceSettingsRequest::class, 'shipping.types.*.name', '배송유형명', 'Shipping type name'],
        ];
    }

    /**
     * 한국어 화면에서 다국어 칸을 비우면 칸 이름과 언어 이름이 함께 나온다.
     *
     * @scenario locale=ko
     *
     * @effects ecommerce_request_locale_required_message_names_field
     *
     * @param  class-string<FormRequest>  $requestClass  요청 클래스
     * @param  string  $field  규칙 키
     * @param  string  $ko  한국어 표시 이름
     * @param  string  $en  영어 표시 이름
     * @return void 반환값 없음
     */
    #[DataProvider('requestFieldProvider')]
    public function test_korean_message_names_field(string $requestClass, string $field, string $ko, string $en): void
    {
        app()->setLocale('ko');

        $this->assertSame("{$ko}의 한국어(ko) 값은 필수입니다.", $this->messageFor($requestClass, $field));
    }

    /**
     * 영어 화면에서 다국어 칸을 비우면 칸 이름과 언어 이름이 함께 나온다.
     *
     * @scenario locale=en
     *
     * @effects ecommerce_request_locale_required_message_names_field
     *
     * @param  class-string<FormRequest>  $requestClass  요청 클래스
     * @param  string  $field  규칙 키
     * @param  string  $ko  한국어 표시 이름
     * @param  string  $en  영어 표시 이름
     * @return void 반환값 없음
     */
    #[DataProvider('requestFieldProvider')]
    public function test_english_message_names_field(string $requestClass, string $field, string $ko, string $en): void
    {
        app()->setLocale('en');

        $this->assertSame("The 한국어 (ko) value of the {$en} field is required.", $this->messageFor($requestClass, $field));
    }

    /**
     * 요청의 규칙·문구·표시 이름으로 대상 다국어 칸만 검증해 첫 오류 문구를 돌려줍니다.
     *
     * 다른 칸 규칙(유일성·존재 검사 등)은 DB 를 읽으므로 대상 칸 규칙만 떼어 돌린다. 와일드카드 칸은 첫 행(0)에 빈 값을 둔다.
     *
     * @param  class-string<FormRequest>  $requestClass  요청 클래스
     * @param  string  $field  규칙 키
     * @return string 첫 오류 문구
     */
    private function messageFor(string $requestClass, string $field): string
    {
        $concrete = str_replace('*', '0', $field);
        $data = [];
        Arr::set($data, $concrete, ['ko' => '', 'en' => '']);

        /** @var FormRequest $request */
        $request = $requestClass::create('/', 'POST', $data);
        $request->setContainer(app());
        $rules = array_intersect_key($request->rules(), [$field => true]);
        $this->assertNotEmpty($rules, "{$requestClass} 에 {$field} 규칙이 없다");

        $validator = Validator::make($data, $rules, $request->messages(), $request->attributes());

        $this->assertTrue($validator->fails(), "{$requestClass} {$field} 가 비었는데 통과했다");

        return (string) $validator->errors()->first($concrete);
    }
}
