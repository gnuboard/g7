<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Tests\TestCase;

/**
 * StoreEcommerceSettingsRequest SEO 필드 검증 테스트
 *
 * 코어로 이관된 SEO 필드가 제거되었는지, 이커머스 고유 SEO 필드가 유지되는지 검증합니다.
 */
class StoreEcommerceSettingsRequestSeoTest extends TestCase
{
    private StoreEcommerceSettingsRequest $request;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request = new StoreEcommerceSettingsRequest;
        $this->request->merge(['_tab' => 'seo']);
    }

    /**
     * 요청 인스턴스의 검증 규칙 키 배열을 반환합니다.
     *
     * @return array<string>
     */
    private function getRuleKeys(): array
    {
        return array_keys($this->request->rules());
    }

    // ========================================
    // 코어로 이관된 필드 제거 확인 테스트
    // ========================================

    /**
     * seo.meta_main_title 규칙이 제거되었는지 검증
     */
    public function test_meta_main_title_has_no_rules(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayNotHasKey('seo.meta_main_title', $rules);
    }

    /**
     * seo.meta_main_description 규칙이 제거되었는지 검증
     */
    public function test_meta_main_description_has_no_rules(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayNotHasKey('seo.meta_main_description', $rules);
    }

    /**
     * seo.seo_site_main 규칙이 제거되었는지 검증
     */
    public function test_seo_site_main_has_no_rules(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayNotHasKey('seo.seo_site_main', $rules);
    }

    /**
     * seo.seo_user_agents 규칙이 제거되었는지 검증
     */
    public function test_seo_user_agents_has_no_rules(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayNotHasKey('seo.seo_user_agents', $rules);
        $this->assertArrayNotHasKey('seo.seo_user_agents.*', $rules);
    }

    /**
     * 코어 이관 대상 4개 필드가 모두 제거되었는지 한번에 검증
     */
    public function test_all_removed_fields_have_no_rules(): void
    {
        $rules = $this->request->rules();

        $removedFields = [
            'seo.meta_main_title',
            'seo.meta_main_description',
            'seo.seo_site_main',
            'seo.seo_user_agents',
            'seo.seo_user_agents.*',
        ];

        foreach ($removedFields as $field) {
            $this->assertArrayNotHasKey($field, $rules, "{$field} 규칙이 아직 남아있습니다.");
        }
    }

    // ========================================
    // 이커머스 고유 SEO 필드 유지 확인 테스트
    // ========================================

    /**
     * seo.meta_category_title 규칙이 유지되는지 검증
     */
    public function test_meta_category_title_has_rules(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('seo.meta_category_title', $rules);
    }

    /**
     * seo.meta_category_description 규칙이 유지되는지 검증
     */
    public function test_meta_category_description_has_rules(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('seo.meta_category_description', $rules);
    }

    /**
     * seo.meta_product_title 규칙이 유지되는지 검증
     */
    public function test_meta_product_title_has_rules(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('seo.meta_product_title', $rules);
    }

    /**
     * seo.meta_product_description 규칙이 유지되는지 검증
     */
    public function test_meta_product_description_has_rules(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('seo.meta_product_description', $rules);
    }

    /**
     * seo.seo_category 규칙이 유지되는지 검증
     */
    public function test_seo_category_has_rules(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('seo.seo_category', $rules);
    }

    /**
     * seo.seo_product_detail 규칙이 유지되는지 검증
     */
    public function test_seo_product_detail_has_rules(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('seo.seo_product_detail', $rules);
    }

    /**
     * seo.seo_search_result 규칙이 유지되는지 검증
     */
    public function test_seo_search_result_has_rules(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('seo.seo_search_result', $rules);
    }

    /**
     * seo.meta_search_title 규칙이 유지되는지 검증
     */
    public function test_meta_search_title_has_rules(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('seo.meta_search_title', $rules);
    }

    /**
     * seo.meta_search_description 규칙이 유지되는지 검증
     */
    public function test_meta_search_description_has_rules(): void
    {
        $rules = $this->request->rules();

        $this->assertArrayHasKey('seo.meta_search_description', $rules);
    }
}
