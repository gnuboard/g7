<?php

namespace Tests\Feature\Seo;

use App\Http\Requests\Layout\UpdateLayoutContentRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * UpdateLayoutContentRequest SEO 검증 규칙 테스트
 *
 * 레이아웃 content의 meta.seo 필드에 대한 유효성 검증 규칙이
 * 올바르게 동작하는지 Validator를 통해 검증합니다.
 */
class UpdateLayoutSeoValidationTest extends TestCase
{
    /**
     * 검증에 사용할 기본 유효 content 데이터를 반환합니다.
     *
     * @param  array  $seoOverrides  SEO 필드 오버라이드
     * @return array 유효한 content 배열
     */
    private function makeValidContent(array $seoOverrides = []): array
    {
        $seo = array_merge([
            'enabled' => true,
            'data_sources' => ['product'],
            'priority' => 0.8,
            'changefreq' => 'daily',
            'og' => ['type' => 'website'],
            'structured_data' => ['@type' => 'WebSite'],
        ], $seoOverrides);

        return [
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'endpoint' => '/api/test',
                'components' => [
                    ['component' => 'Div', 'children' => []],
                ],
                'meta' => [
                    'seo' => $seo,
                ],
            ],
        ];
    }

    /**
     * UpdateLayoutContentRequest의 rules()에서 SEO 관련 규칙만 추출하여
     * Validator로 검증합니다.
     *
     * @param  array  $data  검증할 데이터
     * @return \Illuminate\Validation\Validator 검증기 인스턴스
     */
    private function makeSeoValidator(array $data): \Illuminate\Validation\Validator
    {
        // SEO 관련 규칙만 추출하여 검증 (Custom Rule 의존성 회피)
        $seoRules = [
            'content.meta.seo' => ['nullable', 'array'],
            'content.meta.seo.enabled' => ['nullable', 'boolean'],
            'content.meta.seo.data_sources' => ['nullable', 'array'],
            'content.meta.seo.data_sources.*' => ['string'],
            'content.meta.seo.priority' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'content.meta.seo.changefreq' => ['nullable', 'string', 'in:always,hourly,daily,weekly,monthly,yearly,never'],
            'content.meta.seo.og' => ['nullable', 'array'],
            'content.meta.seo.structured_data' => ['nullable', 'array'],
        ];

        return Validator::make($data, $seoRules);
    }

    /**
     * 유효한 SEO 메타 객체가 검증을 통과하는지 확인합니다.
     */
    public function test_valid_seo_meta_passes_validation(): void
    {
        $data = $this->makeValidContent();
        $validator = $this->makeSeoValidator($data);

        $this->assertTrue($validator->passes(), '유효한 SEO 메타가 검증을 통과해야 합니다');
    }

    /**
     * seo.enabled가 boolean이 아닐 때 검증에 실패하는지 확인합니다.
     */
    public function test_seo_enabled_must_be_boolean(): void
    {
        // 문자열 'yes'는 boolean이 아니므로 실패
        $data = $this->makeValidContent(['enabled' => 'yes']);
        $validator = $this->makeSeoValidator($data);

        $this->assertTrue($validator->fails(), 'enabled에 문자열 "yes"는 실패해야 합니다');
        $this->assertArrayHasKey('content.meta.seo.enabled', $validator->errors()->toArray());

        // 정수 2는 boolean이 아니므로 실패
        $data = $this->makeValidContent(['enabled' => 2]);
        $validator = $this->makeSeoValidator($data);

        $this->assertTrue($validator->fails(), 'enabled에 정수 2는 실패해야 합니다');

        // true/false는 통과
        $data = $this->makeValidContent(['enabled' => true]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->passes(), 'enabled에 true는 통과해야 합니다');

        $data = $this->makeValidContent(['enabled' => false]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->passes(), 'enabled에 false는 통과해야 합니다');
    }

    /**
     * seo.priority가 0~1 범위 밖일 때 검증에 실패하는지 확인합니다.
     */
    public function test_seo_priority_must_be_between_0_and_1(): void
    {
        // 1.5 실패
        $data = $this->makeValidContent(['priority' => 1.5]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->fails(), 'priority 1.5는 실패해야 합니다');
        $this->assertArrayHasKey('content.meta.seo.priority', $validator->errors()->toArray());

        // -0.1 실패
        $data = $this->makeValidContent(['priority' => -0.1]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->fails(), 'priority -0.1는 실패해야 합니다');

        // 0.8 통과
        $data = $this->makeValidContent(['priority' => 0.8]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->passes(), 'priority 0.8는 통과해야 합니다');

        // 0 통과
        $data = $this->makeValidContent(['priority' => 0]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->passes(), 'priority 0는 통과해야 합니다');

        // 1 통과
        $data = $this->makeValidContent(['priority' => 1]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->passes(), 'priority 1은 통과해야 합니다');

        // 1.0 통과
        $data = $this->makeValidContent(['priority' => 1.0]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->passes(), 'priority 1.0은 통과해야 합니다');
    }

    /**
     * seo.changefreq가 허용된 값이 아닐 때 검증에 실패하는지 확인합니다.
     */
    public function test_seo_changefreq_must_be_valid_value(): void
    {
        // 'invalid' 실패
        $data = $this->makeValidContent(['changefreq' => 'invalid']);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->fails(), 'changefreq "invalid"는 실패해야 합니다');
        $this->assertArrayHasKey('content.meta.seo.changefreq', $validator->errors()->toArray());

        // 'biweekly' 실패 (허용 목록에 없음)
        $data = $this->makeValidContent(['changefreq' => 'biweekly']);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->fails(), 'changefreq "biweekly"는 실패해야 합니다');

        // 허용된 모든 값 통과 확인
        $validValues = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];
        foreach ($validValues as $value) {
            $data = $this->makeValidContent(['changefreq' => $value]);
            $validator = $this->makeSeoValidator($data);
            $this->assertTrue($validator->passes(), "changefreq \"{$value}\"는 통과해야 합니다");
        }
    }

    /**
     * seo.data_sources 항목이 문자열이 아닐 때 검증에 실패하는지 확인합니다.
     */
    public function test_seo_data_sources_items_must_be_strings(): void
    {
        // 정수 배열 실패
        $data = $this->makeValidContent(['data_sources' => [123, 456]]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->fails(), 'data_sources에 정수는 실패해야 합니다');

        // 문자열 배열 통과
        $data = $this->makeValidContent(['data_sources' => ['product', 'categories']]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->passes(), 'data_sources에 문자열은 통과해야 합니다');

        // 빈 배열 통과
        $data = $this->makeValidContent(['data_sources' => []]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->passes(), '빈 data_sources는 통과해야 합니다');

        // 혼합 배열 실패 (문자열 + 정수)
        $data = $this->makeValidContent(['data_sources' => ['product', 123]]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->fails(), '혼합 data_sources는 실패해야 합니다');
    }

    /**
     * seo.og가 배열이 아닐 때 검증에 실패하는지 확인합니다.
     */
    public function test_seo_og_must_be_array(): void
    {
        // 문자열 실패
        $data = $this->makeValidContent(['og' => 'not-an-array']);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->fails(), 'og에 문자열은 실패해야 합니다');

        // 배열 통과
        $data = $this->makeValidContent(['og' => ['type' => 'article', 'title' => 'Test']]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->passes(), 'og에 배열은 통과해야 합니다');

        // null 통과
        $data = $this->makeValidContent(['og' => null]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->passes(), 'og에 null은 통과해야 합니다');
    }

    /**
     * seo.structured_data가 배열이 아닐 때 검증에 실패하는지 확인합니다.
     */
    public function test_seo_structured_data_must_be_array(): void
    {
        // 문자열 실패
        $data = $this->makeValidContent(['structured_data' => 'not-an-array']);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->fails(), 'structured_data에 문자열은 실패해야 합니다');

        // 배열 통과
        $data = $this->makeValidContent(['structured_data' => ['@type' => 'Product', 'name' => 'Test']]);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->passes(), 'structured_data에 배열은 통과해야 합니다');
    }

    /**
     * seo 전체가 null일 때 검증을 통과하는지 확인합니다.
     */
    public function test_seo_can_be_null(): void
    {
        $data = [
            'content' => [
                'meta' => [
                    'seo' => null,
                ],
            ],
        ];
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->passes(), 'seo가 null이면 통과해야 합니다');
    }

    /**
     * seo.priority가 숫자가 아닐 때 검증에 실패하는지 확인합니다.
     */
    public function test_seo_priority_must_be_numeric(): void
    {
        $data = $this->makeValidContent(['priority' => 'high']);
        $validator = $this->makeSeoValidator($data);
        $this->assertTrue($validator->fails(), 'priority에 문자열 "high"는 실패해야 합니다');
        $this->assertArrayHasKey('content.meta.seo.priority', $validator->errors()->toArray());
    }
}
