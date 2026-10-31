<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 이커머스 관리자 저장 API 가 다국어 칸을 비운 요청에 칸 이름을 실은 422 문구를 돌려준다(API 왕복).
 *
 * 규칙 단위 판정은 MultilingualFieldDisplayNameTest 가 맡고, 이 테스트는 실제 라우트·미들웨어(관리자 언어)·요청 전처리를
 * 거친 응답 문구를 본다. 검증에서 거절되므로 저장은 일어나지 않는다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/admin-multilingual-field-display-name.yaml
 */
class MultilingualFieldDisplayNameApiTest extends ModuleTestCase
{
    private const BASE = '/api/modules/sirsoft-ecommerce/admin';

    /**
     * 엔드포인트 · 오류 키 · 요청 본문 · 기대 표시 이름(ko, en) 목록.
     *
     * 수정 경로의 `{category}`·`{brand}` 는 라우트 모델 바인딩이라 테스트가 행을 만들어 채운다.
     * 나머지 `{id}` 경로는 검증이 컨트롤러보다 먼저 돌아 없는 번호로도 422 가 난다.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>, 5: string, 6: string}> 케이스 목록
     */
    public static function endpointProvider(): array
    {
        $empty = ['ko' => '', 'en' => ''];

        return [
            'category store' => ['POST', '/categories', 'categories.create', 'name', ['name' => $empty, 'slug' => 'qa-r2-cat'], '카테고리명', 'Category Name'],
            'category update' => ['PUT', '/categories/{category}', 'categories.update', 'name', ['name' => $empty, 'slug' => 'qa-r2-cat'], '카테고리명', 'Category Name'],
            'brand store' => ['POST', '/brands', 'brands.create', 'name', ['name' => $empty, 'slug' => 'qa-r2-brand'], '브랜드명', 'Brand Name'],
            'brand update' => ['PUT', '/brands/{brand}', 'brands.update', 'name', ['name' => $empty, 'slug' => 'qa-r2-brand'], '브랜드명', 'Brand Name'],
            'coupon store' => ['POST', '/promotion-coupons', 'promotion-coupon.create', 'name', ['name' => $empty], '쿠폰명', 'Coupon Name'],
            'coupon update' => ['PUT', '/promotion-coupons/999999', 'promotion-coupon.update', 'name', ['name' => $empty], '쿠폰명', 'Coupon Name'],
            'common info store' => ['POST', '/product-common-infos', 'product-common-infos.create', 'name', ['name' => $empty], '공통정보명', 'Common Info Name'],
            'common info update' => ['PUT', '/product-common-infos/999999', 'product-common-infos.update', 'name', ['name' => $empty], '공통정보명', 'Common Info Name'],
            'notice template store name' => ['POST', '/product-notice-templates', 'product-notice-templates.create', 'name', ['name' => $empty, 'fields' => [['name' => ['ko' => '소재', 'en' => 'Material'], 'content' => ['ko' => '면', 'en' => 'Cotton']]]], '상품군명', 'Product Group Name'],
            'notice template store field name' => ['POST', '/product-notice-templates', 'product-notice-templates.create', 'fields.0.name', ['name' => ['ko' => '의류', 'en' => 'Clothing'], 'fields' => [['name' => $empty, 'content' => ['ko' => '면', 'en' => 'Cotton']]]], '항목명', 'Item Name'],
            'notice template update field content' => ['PUT', '/product-notice-templates/999999', 'product-notice-templates.update', 'fields.0.content', ['name' => ['ko' => '의류', 'en' => 'Clothing'], 'fields' => [['name' => ['ko' => '소재', 'en' => 'Material'], 'content' => $empty]]], '항목 내용', 'Item Content'],
            'product store option group name' => ['POST', '/products', 'products.create', 'option_groups.0.name', ['option_groups' => [['name' => $empty, 'values' => [['ko' => '빨강', 'en' => 'Red']]]]], '옵션그룹명', 'option group name'],
            'product store additional option value name' => ['POST', '/products', 'products.create', 'additional_options.0.values.0.name', ['additional_options' => [['name' => ['ko' => '각인', 'en' => 'Engraving'], 'values' => [['name' => $empty]]]]], '추가옵션 선택지명', 'additional option choice name'],
            'product store notice item content' => ['POST', '/products', 'products.create', 'notice_items.0.content', ['notice_items' => [['name' => ['ko' => '소재', 'en' => 'Material'], 'content' => $empty]]], '상품정보제공고시 내용', 'product notice content'],
            'carrier store' => ['POST', '/shipping-carriers', 'settings.update', 'name', ['code' => 'qa-r2-carrier', 'name' => $empty, 'type' => 'domestic'], '배송사명', 'Carrier Name'],
            'carrier update' => ['PUT', '/shipping-carriers/999999', 'settings.update', 'name', ['name' => $empty], '배송사명', 'Carrier Name'],
            'shipping policy store' => ['POST', '/shipping-policies', 'shipping-policies.create', 'name', ['name' => $empty], '배송정책명', 'Shipping Policy Name'],
            'shipping policy update' => ['PUT', '/shipping-policies/999999', 'shipping-policies.update', 'name', ['name' => $empty], '배송정책명', 'Shipping Policy Name'],
            'settings new carrier' => ['PUT', '/settings', 'settings.update', 'shipping.carriers.0.name', ['shipping' => ['carriers' => [['code' => 'qa-r2-carrier', 'name' => $empty, 'type' => 'domestic', 'is_active' => true]]]], '배송사명', 'Carrier name'],
        ];
    }

    /**
     * 한국어 관리자에게 칸 이름을 실은 422 문구가 간다.
     *
     * @scenario locale=ko
     *
     * @effects ecommerce_admin_api_locale_required_message_names_field
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $path  관리자 API 경로(BASE 뒤)
     * @param  string  $permission  필요한 권한(모듈 접두 제외)
     * @param  string  $errorKey  오류 키
     * @param  array<string, mixed>  $payload  요청 본문
     * @param  string  $ko  한국어 표시 이름
     * @param  string  $en  영어 표시 이름
     * @return void 반환값 없음
     */
    #[DataProvider('endpointProvider')]
    public function test_korean_admin_gets_field_name(string $method, string $path, string $permission, string $errorKey, array $payload, string $ko, string $en): void
    {
        $response = $this->send('ko', $method, $path, $permission, $payload);

        $response->assertStatus(422);
        $this->assertSame("{$ko}의 한국어(ko) 값은 필수입니다.", $this->firstError($response, $errorKey));
    }

    /**
     * 영어 관리자에게도 칸 이름을 실은 422 문구가 간다 — 필수 언어는 화면 언어가 아니라 사이트 기본 언어(ko)다.
     * 언어 이름은 제품 관례대로 그 언어 자신의 표기(「한국어」)다.
     *
     * @scenario locale=en
     *
     * @effects ecommerce_admin_api_locale_required_message_names_field
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $path  관리자 API 경로(BASE 뒤)
     * @param  string  $permission  필요한 권한(모듈 접두 제외)
     * @param  string  $errorKey  오류 키
     * @param  array<string, mixed>  $payload  요청 본문
     * @param  string  $ko  한국어 표시 이름
     * @param  string  $en  영어 표시 이름
     * @return void 반환값 없음
     */
    #[DataProvider('endpointProvider')]
    public function test_english_admin_gets_field_name(string $method, string $path, string $permission, string $errorKey, array $payload, string $ko, string $en): void
    {
        $response = $this->send('en', $method, $path, $permission, $payload);

        $response->assertStatus(422);
        $this->assertSame("The 한국어 (ko) value of the {$en} field is required.", $this->firstError($response, $errorKey));
    }

    /**
     * 그 언어를 쓰는 관리자로 요청을 보냅니다(관리자 언어가 응답 언어를 정한다).
     *
     * @param  string  $locale  관리자 언어
     * @param  string  $method  HTTP 메서드
     * @param  string  $path  관리자 API 경로(BASE 뒤)
     * @param  string  $permission  필요한 권한(모듈 접두 제외)
     * @param  array<string, mixed>  $payload  요청 본문
     * @return TestResponse 응답
     */
    private function send(string $locale, string $method, string $path, string $permission, array $payload): TestResponse
    {
        /** @var User $admin */
        $admin = $this->createAdminUser(["sirsoft-ecommerce.{$permission}"]);
        $admin->forceFill(['language' => $locale])->save();

        if (str_contains($path, '{category}')) {
            $category = Category::create(['name' => ['ko' => '기존', 'en' => 'Existing'], 'slug' => 'qa-r2-existing', 'path' => '', 'depth' => 0]);
            $path = str_replace('{category}', (string) $category->id, $path);
        }
        if (str_contains($path, '{brand}')) {
            $brand = Brand::create(['name' => ['ko' => '기존', 'en' => 'Existing'], 'slug' => 'qa-r2-existing']);
            $path = str_replace('{brand}', (string) $brand->id, $path);
        }

        return $this->actingAs($admin)->json($method, self::BASE.$path, $payload);
    }

    /**
     * 응답의 오류 키 첫 문구를 돌려줍니다(오류 키에 점이 있어 json 점 경로 대신 배열로 읽는다).
     *
     * @param  TestResponse  $response  응답
     * @param  string  $errorKey  오류 키
     * @return string 첫 문구(없으면 오류 키 목록)
     */
    private function firstError(TestResponse $response, string $errorKey): string
    {
        $errors = (array) ($response->json('errors') ?? []);

        return (string) ($errors[$errorKey][0] ?? ('없음: '.implode(', ', array_keys($errors))));
    }
}
