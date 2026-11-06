<?php

namespace Tests\Unit\Seo;

use App\Seo\ExpressionEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 기본 사용자 템플릿 상품 상세(`shop/show`) 의 SEO 식이 상품 고유 검색 제목·설명이 없을 때 상품명·설명으로 넘어가는지 감시합니다.
 *
 * 배경:
 * 레이아웃 `meta.seo.vars`·`og` 식은 서버 평가기(봇 `<title>`)와 화면 엔진(방문자 탭 제목)이 함께 평가한다. 식이
 * `$localized(meta_title) ?? $localized(name)` 이면 화면 `$localized` 는 빈 값을 빈 글자로 돌려줘 `??` 가 다음 후보로
 * 넘어가지 않았다 — 서버는 상품명을, 화면은 빈 값을 냈다. 빈 다국어 값(`{ko: ''}`)은 서버도 멈췄다. 두 구현이 같은 답을
 * 내도록 레이아웃 식은 빈 글자도 건너뛰는 `||` 로 잇는다. 화면 쪽 짝 시험은 템플릿의
 * `__tests__/layouts/shop-product-document-title.test.ts` 다.
 *
 * @scenario-manifest templates/_bundled/sirsoft-basic/tests/scenarios/shop-product-document-title.yaml
 */
class ProductDetailLayoutSeoExpressionTest extends TestCase
{
    /**
     * 실제 레이아웃(짝 .json)의 SEO 선언을 읽습니다.
     *
     * @return array<string, mixed> 상품 상세 SEO 선언(meta.seo)
     */
    private function seo(): array
    {
        $layout = json_decode((string) file_get_contents(base_path('templates/_bundled/sirsoft-basic/layouts/shop/show.json')), true);

        return $layout['meta']['seo'];
    }

    /**
     * 고유 검색 제목·설명이 비어 있는 상품 모양을 공급합니다.
     *
     * @return array<string, array{0: mixed}> 사례 이름 → [비어 있는 값]
     */
    public static function emptyOwnValues(): array
    {
        return [
            'null' => [null],
            '빈 다국어 값' => [['ko' => '', 'en' => '']],
        ];
    }

    /**
     * 고유 검색 제목이 비면 제목 변수·소셜 공유 제목이 상품명이고, 고유 설명이 비면 설명 변수가 요약 설명입니다.
     *
     * @scenario case=no_meta_title_uses_product_name
     *
     * @effects product_name_var_falls_back_to_name
     *
     * @param  mixed  $empty  비어 있는 고유 값
     * @return void 반환값 없음
     */
    #[DataProvider('emptyOwnValues')]
    public function test_empty_own_values_fall_back_to_product_fields(mixed $empty): void
    {
        app()->setLocale('ko');
        $seo = $this->seo();
        $context = ['product' => ['data' => [
            'name' => ['ko' => '배 1박스 (5kg)', 'en' => 'Pear box'],
            'meta_title' => $empty,
            'meta_description' => $empty,
            'short_description' => ['ko' => '산지 직송'],
            'description' => ['ko' => '<p>본문</p>'],
        ]]];
        $evaluator = new ExpressionEvaluator;

        $this->assertSame('배 1박스 (5kg)', $evaluator->evaluate($seo['vars']['product_name'], $context));
        $this->assertSame('배 1박스 (5kg)', $evaluator->evaluate($seo['og']['title'], $context));
        $this->assertSame('산지 직송', $evaluator->evaluate($seo['vars']['product_description'], $context));
    }

    /**
     * 고유 검색 제목이 있으면 그 값이 먼저이고, 확장 제목 틀 결과(`_seo.product.title`)가 있으면 소셜 공유 제목은 그 값입니다.
     *
     * @scenario case=meta_title_wins
     *
     * @effects product_meta_title_first_candidate
     *
     * @return void 반환값 없음
     */
    public function test_own_meta_title_and_extension_template_keep_priority(): void
    {
        app()->setLocale('ko');
        $seo = $this->seo();
        $context = ['product' => ['data' => [
            'name' => ['ko' => '겨울 패딩 점퍼'],
            'meta_title' => ['ko' => '겨울 패딩 점퍼 | 추천 상품'],
        ]]];
        $evaluator = new ExpressionEvaluator;

        $this->assertSame('겨울 패딩 점퍼 | 추천 상품', $evaluator->evaluate($seo['vars']['product_name'], $context));
        $this->assertSame('겨울 패딩 점퍼 | 추천 상품', $evaluator->evaluate($seo['og']['title'], $context));

        $context['_seo'] = ['product' => ['title' => '겨울 패딩 점퍼 특가', 'description' => '']];
        $this->assertSame('겨울 패딩 점퍼 특가', $evaluator->evaluate($seo['og']['title'], $context));
    }
}
