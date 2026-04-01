<?php

namespace Tests\Unit\Seo;

use App\Seo\ExpressionEvaluator;
use Tests\TestCase;

class ExpressionEvaluatorTest extends TestCase
{
    private ExpressionEvaluator $evaluator;

    /**
     * 테스트 초기화 - ExpressionEvaluator 인스턴스를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new ExpressionEvaluator;
    }

    /**
     * dot notation 으로 중첩 객체의 값을 해석합니다.
     */
    public function test_dot_notation_resolves_nested_value(): void
    {
        $context = ['user' => ['name' => '홍길동']];

        $result = $this->evaluator->evaluate('{{user.name}}', $context);

        $this->assertSame('홍길동', $result);
    }

    /**
     * 깊은 dot notation 경로를 해석합니다.
     */
    public function test_deep_dot_notation(): void
    {
        $context = ['product' => ['data' => ['name' => '에어맥스']]];

        $result = $this->evaluator->evaluate('{{product.data.name}}', $context);

        $this->assertSame('에어맥스', $result);
    }

    /**
     * optional chaining(?.)으로 존재하는 값을 해석합니다.
     */
    public function test_optional_chaining_value_exists(): void
    {
        $context = ['user' => ['profile' => ['image' => 'https://example.com/avatar.jpg']]];

        $result = $this->evaluator->evaluate('{{user?.profile?.image}}', $context);

        $this->assertSame('https://example.com/avatar.jpg', $result);
    }

    /**
     * optional chaining(?.)으로 존재하지 않는 값은 빈 문자열을 반환합니다.
     */
    public function test_optional_chaining_missing_value(): void
    {
        $context = ['user' => ['name' => '홍길동']];

        $result = $this->evaluator->evaluate('{{user?.profile?.image}}', $context);

        $this->assertSame('', $result);
    }

    /**
     * null coalescing(??)으로 null 값에 기본값을 사용합니다.
     */
    public function test_null_coalescing_with_null_value(): void
    {
        $context = ['value' => null];

        $result = $this->evaluator->evaluate("{{value ?? 'default'}}", $context);

        $this->assertSame('default', $result);
    }

    /**
     * null coalescing(??)으로 존재하지 않는 키에 기본값을 사용합니다.
     */
    public function test_null_coalescing_with_nonexistent_key(): void
    {
        $context = [];

        $result = $this->evaluator->evaluate("{{missing ?? 'fallback'}}", $context);

        $this->assertSame('fallback', $result);
    }

    /**
     * null coalescing(??)으로 값이 존재하면 실제 값을 반환합니다.
     */
    public function test_null_coalescing_with_existing_value(): void
    {
        $context = ['value' => '실제값'];

        $result = $this->evaluator->evaluate("{{value ?? 'default'}}", $context);

        $this->assertSame('실제값', $result);
    }

    /**
     * $t: 접두사로 번역 키를 처리합니다.
     */
    public function test_translation_key(): void
    {
        // __() 함수가 번역 키를 반환하도록 app translator 설정
        app('translator')->addLines(['seo.title' => 'SEO 제목'], 'ko');
        app()->setLocale('ko');

        $result = $this->evaluator->evaluate('$t:seo.title', []);

        $this->assertSame('SEO 제목', $result);
    }

    /**
     * 배열 인덱스([N])를 올바르게 해석합니다.
     */
    public function test_array_index_access(): void
    {
        $context = [
            'images' => [
                ['url' => '첫번째URL'],
                ['url' => '두번째URL'],
            ],
        ];

        $result = $this->evaluator->evaluate('{{images[0]?.url}}', $context);

        $this->assertSame('첫번째URL', $result);
    }

    /**
     * 범위를 벗어난 배열 인덱스는 빈 문자열을 반환합니다.
     */
    public function test_array_index_out_of_range(): void
    {
        $context = [
            'images' => [
                ['url' => '첫번째URL'],
            ],
        ];

        $result = $this->evaluator->evaluate('{{images[99]?.url}}', $context);

        $this->assertSame('', $result);
    }

    /**
     * 삼항 연산자: truthy 조건에서 true 분기 값을 반환합니다.
     */
    public function test_ternary_evaluates_true_branch(): void
    {
        $context = ['a' => true, 'b' => 'yes', 'c' => 'no'];

        $result = $this->evaluator->evaluate('{{a ? b : c}}', $context);

        $this->assertSame('yes', $result);
    }

    /**
     * 존재하지 않는 키는 빈 문자열을 반환합니다.
     */
    public function test_nonexistent_key_returns_empty(): void
    {
        $context = ['user' => ['name' => '홍길동']];

        $result = $this->evaluator->evaluate('{{nonexistent.key}}', $context);

        $this->assertSame('', $result);
    }

    /**
     * dot notation과 null coalescing을 혼합하여 사용합니다.
     */
    public function test_mixed_dot_notation_with_null_coalescing(): void
    {
        $context = ['product' => ['data' => ['name' => '에어맥스']]];

        $result = $this->evaluator->evaluate("{{product.data.name ?? ''}}", $context);

        $this->assertSame('에어맥스', $result);
    }

    /**
     * 숫자 값을 문자열로 변환합니다.
     */
    public function test_numeric_value_converted_to_string(): void
    {
        $context = ['price' => 15000];

        $result = $this->evaluator->evaluate('{{price}}', $context);

        $this->assertSame('15000', $result);
    }

    /**
     * boolean 값을 문자열로 변환합니다.
     */
    public function test_boolean_value_converted_to_string(): void
    {
        $context = ['active' => true];

        $result = $this->evaluator->evaluate('{{active}}', $context);

        $this->assertSame('true', $result);
    }

    /**
     * 표현식 없는 일반 문자열은 그대로 반환합니다.
     */
    public function test_plain_string_without_expression(): void
    {
        $result = $this->evaluator->evaluate('Hello World', []);

        $this->assertSame('Hello World', $result);
    }

    /**
     * 다국어 객체에서 현재 로케일 값을 자동 추출합니다.
     */
    public function test_localized_object_resolves_to_current_locale(): void
    {
        app()->setLocale('ko');

        $context = ['product' => ['data' => ['name' => ['ko' => '무선 마우스', 'en' => 'Wireless Mouse']]]];

        $result = $this->evaluator->evaluate('{{product.data.name}}', $context);

        $this->assertSame('무선 마우스', $result);
    }

    /**
     * 다국어 객체에서 현재 로케일이 없으면 fallback 로케일 값을 반환합니다.
     */
    public function test_localized_object_falls_back_to_fallback_locale(): void
    {
        app()->setLocale('ja');
        config(['app.fallback_locale' => 'en']);

        $context = ['product' => ['data' => ['name' => ['ko' => '무선 마우스', 'en' => 'Wireless Mouse']]]];

        $result = $this->evaluator->evaluate('{{product.data.name}}', $context);

        $this->assertSame('Wireless Mouse', $result);
    }

    // ==========================================
    // 비교 연산자 (Comparison Operators)
    // ==========================================

    /**
     * > 연산자: 좌측이 우측보다 클 때 true를 반환합니다.
     */
    public function test_comparison_greater_than_true(): void
    {
        $context = ['product' => ['data' => ['discount_rate' => 25.6]]];

        $result = $this->evaluator->evaluate('{{product.data.discount_rate > 0}}', $context);

        $this->assertSame('true', $result);
    }

    /**
     * > 연산자: 좌측이 우측보다 작을 때 false를 반환합니다.
     */
    public function test_comparison_greater_than_false(): void
    {
        $context = ['product' => ['data' => ['discount_rate' => 0]]];

        $result = $this->evaluator->evaluate('{{product.data.discount_rate > 0}}', $context);

        $this->assertSame('false', $result);
    }

    /**
     * optional chaining이 포함된 비교 표현식을 처리합니다.
     */
    public function test_comparison_with_optional_chaining(): void
    {
        $context = ['product' => ['data' => ['discount_rate' => 15]]];

        $result = $this->evaluator->evaluate('{{product.data?.discount_rate > 0}}', $context);

        $this->assertSame('true', $result);
    }

    /**
     * === 연산자: 문자열 동등 비교입니다.
     */
    public function test_comparison_strict_equals_string(): void
    {
        $context = ['product' => ['data' => ['sales_status' => 'on_sale']]];

        $result = $this->evaluator->evaluate("{{product.data?.sales_status === 'on_sale'}}", $context);

        $this->assertSame('true', $result);
    }

    /**
     * !== 연산자: 문자열 불일치 비교입니다.
     */
    public function test_comparison_strict_not_equals(): void
    {
        $context = ['product' => ['data' => ['sales_status' => 'on_sale']]];

        $result = $this->evaluator->evaluate("{{product.data?.sales_status !== 'sold_out'}}", $context);

        $this->assertSame('true', $result);
    }

    /**
     * <= 연산자: 숫자 비교입니다.
     */
    public function test_comparison_less_than_or_equal(): void
    {
        $context = ['product' => ['data' => ['discount_rate' => 0]]];

        $result = $this->evaluator->evaluate('{{product.data?.discount_rate <= 0}}', $context);

        $this->assertSame('true', $result);
    }

    /**
     * 좌측 경로가 미존재하면 빈 문자열을 반환합니다.
     */
    public function test_comparison_with_null_left_returns_empty(): void
    {
        $context = [];

        $result = $this->evaluator->evaluate('{{missing.path > 0}}', $context);

        $this->assertSame('', $result);
    }

    // ==========================================
    // 논리 연산자 (Logical Operators)
    // ==========================================

    /**
     * || 연산자: 좌측이 truthy이면 true를 반환합니다.
     */
    public function test_logical_or_left_truthy(): void
    {
        $context = ['a' => 1, 'b' => 0];

        $result = $this->evaluator->evaluate('{{a || b}}', $context);

        $this->assertSame('true', $result);
    }

    /**
     * || 연산자: 우측이 truthy이면 true를 반환합니다.
     */
    public function test_logical_or_right_truthy(): void
    {
        $context = ['a' => 0, 'b' => 1];

        $result = $this->evaluator->evaluate('{{a || b}}', $context);

        $this->assertSame('true', $result);
    }

    /**
     * || 연산자: 양쪽 모두 falsy이면 false를 반환합니다.
     */
    public function test_logical_or_both_falsy(): void
    {
        $context = ['a' => 0, 'b' => 0];

        $result = $this->evaluator->evaluate('{{a || b}}', $context);

        $this->assertSame('false', $result);
    }

    /**
     * && 연산자: 양쪽 모두 truthy이면 true를 반환합니다.
     */
    public function test_logical_and_both_truthy(): void
    {
        $context = ['a' => 1, 'b' => 1];

        $result = $this->evaluator->evaluate('{{a && b}}', $context);

        $this->assertSame('true', $result);
    }

    /**
     * && 연산자: 한쪽이 falsy이면 false를 반환합니다.
     */
    public function test_logical_and_one_falsy(): void
    {
        $context = ['a' => 1, 'b' => 0];

        $result = $this->evaluator->evaluate('{{a && b}}', $context);

        $this->assertSame('false', $result);
    }

    /**
     * ! 연산자: truthy 값을 false로 변환합니다.
     */
    public function test_negation_truthy_becomes_false(): void
    {
        $context = ['value' => 25.6];

        $result = $this->evaluator->evaluate('{{!value}}', $context);

        $this->assertSame('false', $result);
    }

    /**
     * ! 연산자: falsy 값을 true로 변환합니다.
     */
    public function test_negation_falsy_becomes_true(): void
    {
        $context = ['value' => 0];

        $result = $this->evaluator->evaluate('{{!value}}', $context);

        $this->assertSame('true', $result);
    }

    /**
     * 복합 논리 표현식: !a || a <= 0 패턴을 처리합니다.
     */
    public function test_complex_logical_with_negation_and_comparison(): void
    {
        // discount_rate가 있고 > 0이면 "할인 없음" 조건은 false
        $context = ['product' => ['data' => ['discount_rate' => 25.6]]];

        $result = $this->evaluator->evaluate('{{!product.data?.discount_rate || product.data?.discount_rate <= 0}}', $context);

        $this->assertSame('false', $result);
    }

    /**
     * 복합 논리 표현식: discount_rate가 0이면 "할인 없음" 조건은 true입니다.
     */
    public function test_complex_logical_no_discount(): void
    {
        $context = ['product' => ['data' => ['discount_rate' => 0]]];

        $result = $this->evaluator->evaluate('{{!product.data?.discount_rate || product.data?.discount_rate <= 0}}', $context);

        $this->assertSame('true', $result);
    }

    // ==========================================
    // 브래킷 접근자 (Bracket Accessor)
    // ==========================================

    /**
     * 문자열 리터럴 브래킷 접근자를 해석합니다.
     */
    public function test_bracket_accessor_string_literal(): void
    {
        $context = ['prices' => ['KRW' => ['formatted' => '64,000원']]];

        $result = $this->evaluator->evaluate("{{prices['KRW'].formatted}}", $context);

        $this->assertSame('64,000원', $result);
    }

    /**
     * null coalescing이 포함된 브래킷 접근자를 해석합니다.
     */
    public function test_bracket_accessor_with_null_coalescing(): void
    {
        $context = [
            'product' => [
                'data' => [
                    'multi_currency_list_price' => [
                        'KRW' => ['formatted' => '86,000원'],
                        'USD' => ['formatted' => '$66.00'],
                    ],
                ],
            ],
        ];

        // _global.preferredCurrency가 없으므로 'KRW' fallback 사용
        $result = $this->evaluator->evaluate("{{product.data?.multi_currency_list_price?.[_global.preferredCurrency ?? 'KRW']?.formatted}}", $context);

        $this->assertSame('86,000원', $result);
    }

    /**
     * 브래킷 접근자 + 최상위 ?? fallback 경로입니다.
     */
    public function test_bracket_accessor_with_outer_null_coalescing(): void
    {
        $context = [
            'product' => [
                'data' => [
                    'list_price_formatted' => '86,000원',
                    // multi_currency_list_price가 없음
                ],
            ],
        ];

        $result = $this->evaluator->evaluate("{{product.data?.multi_currency_list_price?.[_global.preferredCurrency ?? 'KRW']?.formatted ?? product.data?.list_price_formatted}}", $context);

        $this->assertSame('86,000원', $result);
    }

    /**
     * 브래킷 접근자: 동적 키가 context에 있으면 그 값을 사용합니다.
     */
    public function test_bracket_accessor_with_dynamic_key_from_context(): void
    {
        $context = [
            '_global' => ['preferredCurrency' => 'USD'],
            'prices' => [
                'KRW' => ['formatted' => '86,000원'],
                'USD' => ['formatted' => '$66.00'],
            ],
        ];

        $result = $this->evaluator->evaluate("{{prices.[_global.preferredCurrency ?? 'KRW'].formatted}}", $context);

        $this->assertSame('$66.00', $result);
    }

    // ==========================================
    // 인라인 $t: 토큰 (Inline Translation Tokens)
    // ==========================================

    /**
     * 텍스트 중간에 위치한 $t: 토큰을 해석합니다.
     */
    public function test_inline_translation_token(): void
    {
        $this->evaluator->setTranslations([
            'shop' => ['product' => ['reviews_count' => '개 리뷰']],
        ]);

        $result = $this->evaluator->evaluate('(0 $t:shop.product.reviews_count)', []);

        $this->assertSame('(0 개 리뷰)', $result);
    }

    /**
     * {{binding}}과 $t: 토큰이 혼합된 표현식을 처리합니다.
     */
    public function test_mixed_binding_and_translation(): void
    {
        $this->evaluator->setTranslations([
            'shop' => ['product' => ['reviews_count' => '개 리뷰']],
        ]);
        $context = ['review_count' => 5];

        $result = $this->evaluator->evaluate('({{review_count}} $t:shop.product.reviews_count)', $context);

        $this->assertSame('(5 개 리뷰)', $result);
    }

    /**
     * 미등록 인라인 $t: 토큰은 빈 문자열로 치환됩니다.
     */
    public function test_inline_unknown_translation_returns_empty(): void
    {
        $result = $this->evaluator->evaluate('(0 $t:unknown.key)', []);

        $this->assertSame('(0 )', $result);
    }

    // ==========================================
    // 후처리: 고립 연산자 정리
    // ==========================================

    /**
     * 빈 {{}} 치환으로 남은 고립 연산자(+)를 정리합니다.
     */
    public function test_orphaned_operator_cleaned_up(): void
    {
        // 복잡한 표현식이 빈 문자열로 평가된 후 + 만 남는 경우
        $result = $this->evaluator->evaluate('+{{someFunc()}}', []);

        $this->assertSame('', $result);
    }

    // ==========================================
    // 통합 시나리오: 실제 레이아웃 표현식
    // ==========================================

    /**
     * 실제 상품 상세 가격 표현식: 할인가 표시 조건입니다.
     */
    public function test_real_world_discount_price_condition(): void
    {
        $context = [
            'product' => [
                'data' => [
                    'discount_rate' => 25.6,
                    'selling_price_formatted' => '64,000원',
                    'list_price_formatted' => '86,000원',
                    'multi_currency_selling_price' => [
                        'KRW' => ['formatted' => '64,000원'],
                    ],
                    'multi_currency_list_price' => [
                        'KRW' => ['formatted' => '86,000원'],
                    ],
                ],
            ],
        ];

        // if 조건: 할인 여부
        $condition = $this->evaluator->evaluate('{{product.data?.discount_rate > 0}}', $context);
        $this->assertSame('true', $condition);

        // 할인율
        $rate = $this->evaluator->evaluate('{{product.data?.discount_rate}}%', $context);
        $this->assertSame('25.6%', $rate);

        // 원가 (multi_currency → formatted)
        $listPrice = $this->evaluator->evaluate("{{product.data?.multi_currency_list_price?.[_global.preferredCurrency ?? 'KRW']?.formatted ?? product.data?.list_price_formatted}}", $context);
        $this->assertSame('86,000원', $listPrice);

        // 할인가
        $sellingPrice = $this->evaluator->evaluate("{{product.data?.multi_currency_selling_price?.[_global.preferredCurrency ?? 'KRW']?.formatted ?? product.data?.selling_price_formatted}}", $context);
        $this->assertSame('64,000원', $sellingPrice);
    }

    /**
     * 실제 상품 상세: 할인 없는 상품의 가격 표시 조건입니다.
     */
    public function test_real_world_no_discount_price_condition(): void
    {
        $context = [
            'product' => [
                'data' => [
                    'discount_rate' => 0,
                    'selling_price_formatted' => '30,000원',
                ],
            ],
        ];

        // 할인 있음 조건 → false
        $hasDiscount = $this->evaluator->evaluate('{{product.data?.discount_rate > 0}}', $context);
        $this->assertSame('false', $hasDiscount);

        // 할인 없음 조건 → true
        $noDiscount = $this->evaluator->evaluate('{{!product.data?.discount_rate || product.data?.discount_rate <= 0}}', $context);
        $this->assertSame('true', $noDiscount);
    }

    /**
     * 문자열 연결 연산자(+)가 포함된 복합 표현식은 빈 문자열을 반환합니다.
     */
    public function test_string_concat_operator_returns_empty(): void
    {
        $context = ['product' => ['data' => ['selling_price' => 64000]]];

        // + 연산자(문자열 연결)는 미지원 → 빈 문자열
        $result = $this->evaluator->evaluate("{{(product.data?.selling_price).toLocaleString() + '원'}}", $context);

        $this->assertSame('', $result);
    }

    /**
     * setTranslations로 설정된 번역은 인라인 $t: 토큰에서도 사용됩니다.
     */
    public function test_set_translations_used_in_inline_token(): void
    {
        $this->evaluator->setTranslations([
            'common' => ['back' => '뒤로가기'],
        ]);

        $result = $this->evaluator->evaluate('$t:common.back', []);

        $this->assertSame('뒤로가기', $result);
    }

    /**
     * 괄호로 감싼 null coalescing + 비교 연산자 테스트
     * 실제 사용 예: 탭 패널 조건 `{{(_local.activeTab ?? 'info') === 'info'}}`
     */
    public function test_parenthesized_null_coalescing_comparison(): void
    {
        $context = [];

        // _local.activeTab 미존재 → 'info' fallback → 'info' === 'info' → true
        $result = $this->evaluator->evaluate("{{(_local.activeTab ?? 'info') === 'info'}}", $context);
        $this->assertSame('true', $result);
    }

    /**
     * 괄호 내 null coalescing으로 fallback된 값이 비교 우측과 다를 때
     */
    public function test_parenthesized_null_coalescing_comparison_false(): void
    {
        $context = [];

        // _local.activeTab 미존재 → 'info' fallback → 'info' === 'reviews' → false
        $result = $this->evaluator->evaluate("{{(_local.activeTab ?? 'info') === 'reviews'}}", $context);
        $this->assertSame('false', $result);
    }

    /**
     * 괄호 내 null coalescing에서 실제 값이 있는 경우
     */
    public function test_parenthesized_null_coalescing_with_existing_value(): void
    {
        $context = ['_local' => ['activeTab' => 'reviews']];

        // _local.activeTab = 'reviews' → 'reviews' === 'reviews' → true
        $result = $this->evaluator->evaluate("{{(_local.activeTab ?? 'info') === 'reviews'}}", $context);
        $this->assertSame('true', $result);
    }

    // === 가상 프로퍼티 해석 테스트 ===

    /**
     * 배열의 .length 가상 프로퍼티 해석
     */
    public function test_array_length_virtual_property(): void
    {
        $context = [
            'products' => ['data' => [['id' => 1], ['id' => 2], ['id' => 3]]],
        ];

        $result = $this->evaluator->evaluate('{{products.data.length}}', $context);
        $this->assertSame('3', $result);
    }

    /**
     * 빈 배열의 .length 가상 프로퍼티
     */
    public function test_empty_array_length_virtual_property(): void
    {
        $context = ['items' => []];

        $result = $this->evaluator->evaluate('{{items.length}}', $context);
        $this->assertSame('0', $result);
    }

    /**
     * .length를 비교 연산자와 함께 사용
     */
    public function test_array_length_in_comparison(): void
    {
        $context = [
            'popularProducts' => ['data' => [['id' => 1], ['id' => 2]]],
        ];

        $result = $this->evaluator->evaluate('{{popularProducts.data.length > 0}}', $context);
        $this->assertSame('true', $result);
    }

    /**
     * .length를 && 조건과 함께 사용 (인기상품 패턴)
     */
    public function test_array_length_with_and_condition(): void
    {
        $context = [
            'popularProducts' => ['data' => [['id' => 1]]],
        ];

        $result = $this->evaluator->evaluate('{{popularProducts.data && popularProducts.data.length > 0}}', $context);
        $this->assertSame('true', $result);
    }

    /**
     * 빈 배열 .length와 && 조건 (false 반환)
     */
    public function test_empty_array_length_with_and_condition(): void
    {
        $context = [
            'popularProducts' => ['data' => []],
        ];

        $result = $this->evaluator->evaluate('{{popularProducts.data && popularProducts.data.length > 0}}', $context);
        $this->assertSame('false', $result);
    }

    /**
     * 문자열의 .length 가상 프로퍼티
     */
    public function test_string_length_virtual_property(): void
    {
        $context = ['name' => '홍길동'];

        $result = $this->evaluator->evaluate('{{name.length}}', $context);
        $this->assertSame('3', $result);
    }

    /**
     * optional chaining과 .length 조합
     */
    public function test_optional_chaining_with_length(): void
    {
        $context = [
            'product' => ['data' => ['notice' => ['values' => [['key' => 'a', 'value' => 'b']]]]],
        ];

        $result = $this->evaluator->evaluate('{{product.data?.notice?.values.length > 0}}', $context);
        $this->assertSame('true', $result);
    }

    /**
     * 존재하지 않는 경로의 .length → 빈 문자열
     */
    public function test_nonexistent_path_length_returns_empty(): void
    {
        $context = [];

        $result = $this->evaluator->evaluate('{{missing.data.length}}', $context);
        $this->assertSame('', $result);
    }

    /**
     * 중첩 깊은 경로의 .length
     */
    public function test_deeply_nested_array_length(): void
    {
        $context = [
            'product' => [
                'data' => [
                    'notice' => [
                        'values' => [
                            ['key' => 'color', 'value' => 'red'],
                            ['key' => 'size', 'value' => 'M'],
                            ['key' => 'weight', 'value' => '100g'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->evaluator->evaluate('{{product.data.notice.values.length}}', $context);
        $this->assertSame('3', $result);
    }

    // ==========================================
    // 배열 인스턴스 메서드 (Array Instance Methods)
    // ==========================================

    /**
     * Array.join() — 배열을 구분자로 결합합니다.
     */
    public function test_array_join(): void
    {
        $context = ['colors' => ['빨강', '파랑', '초록']];

        $result = $this->evaluator->evaluate("{{colors.join(', ')}}", $context);
        $this->assertSame('빨강, 파랑, 초록', $result);
    }

    /**
     * Array.join() — 구분자 없이 기본 쉼표 사용
     */
    public function test_array_join_default_separator(): void
    {
        $context = ['items' => ['a', 'b', 'c']];

        $result = $this->evaluator->evaluate('{{items.join()}}', $context);
        $this->assertSame('a,b,c', $result);
    }

    /**
     * Array.slice() — 부분 배열 추출
     */
    public function test_array_slice(): void
    {
        $context = ['items' => ['a', 'b', 'c', 'd', 'e']];

        $result = $this->evaluator->evaluate('{{items.slice(1, 3).join()}}', $context);
        $this->assertSame('b,c', $result);
    }

    /**
     * Array.includes() — 요소 포함 여부
     */
    public function test_array_includes_true(): void
    {
        $context = ['tags' => ['sale', 'new', 'hot']];

        $result = $this->evaluator->evaluate("{{tags.includes('sale')}}", $context);
        $this->assertSame('true', $result);
    }

    /**
     * Array.includes() — 미포함
     */
    public function test_array_includes_false(): void
    {
        $context = ['tags' => ['sale', 'new']];

        $result = $this->evaluator->evaluate("{{tags.includes('hot')}}", $context);
        $this->assertSame('false', $result);
    }

    /**
     * Array.indexOf() — 요소 인덱스 검색
     */
    public function test_array_index_of(): void
    {
        $context = ['items' => ['a', 'b', 'c']];

        $result = $this->evaluator->evaluate("{{items.indexOf('b')}}", $context);
        $this->assertSame('1', $result);
    }

    /**
     * Array.indexOf() — 없는 요소는 -1 반환
     */
    public function test_array_index_of_not_found(): void
    {
        $context = ['items' => ['a', 'b']];

        $result = $this->evaluator->evaluate("{{items.indexOf('z')}}", $context);
        $this->assertSame('-1', $result);
    }

    /**
     * Array.reverse() — 배열 역순
     */
    public function test_array_reverse(): void
    {
        $context = ['items' => [1, 2, 3]];

        $result = $this->evaluator->evaluate('{{items.reverse().join()}}', $context);
        $this->assertSame('3,2,1', $result);
    }

    /**
     * Array.flat() — 중첩 배열 평탄화
     */
    public function test_array_flat(): void
    {
        $context = ['nested' => [[1, 2], [3, 4], [5]]];

        $result = $this->evaluator->evaluate('{{nested.flat().join()}}', $context);
        $this->assertSame('1,2,3,4,5', $result);
    }

    /**
     * Array.at() — 음수 인덱스 지원
     */
    public function test_array_at_negative_index(): void
    {
        $context = ['items' => ['a', 'b', 'c']];

        $result = $this->evaluator->evaluate('{{items.at(-1)}}', $context);
        $this->assertSame('c', $result);
    }

    /**
     * Array.concat() — 배열 병합
     */
    public function test_array_concat(): void
    {
        $context = ['a' => [1, 2], 'b' => [3, 4]];

        $result = $this->evaluator->evaluate('{{a.concat(b).join()}}', $context);
        $this->assertSame('1,2,3,4', $result);
    }

    /**
     * Array.map() — 객체 배열에서 특정 프로퍼티 추출
     */
    public function test_array_map_property(): void
    {
        $context = [
            'products' => [
                ['name' => '마우스', 'price' => 15000],
                ['name' => '키보드', 'price' => 25000],
            ],
        ];

        $result = $this->evaluator->evaluate("{{products.map(item => item.name).join(', ')}}", $context);
        $this->assertSame('마우스, 키보드', $result);
    }

    /**
     * Array.filter() — 조건에 맞는 요소만 추출
     */
    public function test_array_filter(): void
    {
        $context = [
            'items' => [
                ['name' => 'A', 'active' => true],
                ['name' => 'B', 'active' => false],
                ['name' => 'C', 'active' => true],
            ],
        ];

        $result = $this->evaluator->evaluate("{{items.filter(item => item.active).map(item => item.name).join(', ')}}", $context);
        $this->assertSame('A, C', $result);
    }

    /**
     * Array.find() — 조건에 맞는 첫 요소
     */
    public function test_array_find(): void
    {
        $context = [
            'users' => [
                ['id' => 1, 'name' => '홍길동'],
                ['id' => 2, 'name' => '김철수'],
            ],
        ];

        $result = $this->evaluator->evaluate('{{users.find(u => u.id).name}}', $context);
        $this->assertSame('홍길동', $result);
    }

    /**
     * Array.some() — 하나라도 조건 충족
     */
    public function test_array_some(): void
    {
        $context = ['items' => [['stock' => 0], ['stock' => 5], ['stock' => 0]]];

        $result = $this->evaluator->evaluate('{{items.some(item => item.stock)}}', $context);
        $this->assertSame('true', $result);
    }

    /**
     * Array.every() — 모두 조건 충족
     */
    public function test_array_every_true(): void
    {
        $context = ['items' => [['active' => true], ['active' => true]]];

        $result = $this->evaluator->evaluate('{{items.every(item => item.active)}}', $context);
        $this->assertSame('true', $result);
    }

    /**
     * Array.every() — 일부 미충족
     */
    public function test_array_every_false(): void
    {
        $context = ['items' => [['active' => true], ['active' => false]]];

        $result = $this->evaluator->evaluate('{{items.every(item => item.active)}}', $context);
        $this->assertSame('false', $result);
    }

    /**
     * Array.findIndex() — 조건에 맞는 첫 인덱스
     */
    public function test_array_find_index(): void
    {
        $context = ['items' => [['id' => 10], ['id' => 20], ['id' => 30]]];

        $result = $this->evaluator->evaluate('{{items.findIndex(item => item.id)}}', $context);
        $this->assertSame('0', $result);
    }

    /**
     * Array.sort() — 콜백 없이 기본 정렬
     */
    public function test_array_sort_default(): void
    {
        $context = ['items' => [3, 1, 2]];

        $result = $this->evaluator->evaluate('{{items.sort().join()}}', $context);
        $this->assertSame('1,2,3', $result);
    }

    // ==========================================
    // 문자열 인스턴스 메서드 (String Instance Methods)
    // ==========================================

    /**
     * String.split() — 문자열 분할
     */
    public function test_string_split(): void
    {
        $context = ['csv' => 'a,b,c'];

        $result = $this->evaluator->evaluate("{{csv.split(',').length}}", $context);
        $this->assertSame('3', $result);
    }

    /**
     * String.trim()
     */
    public function test_string_trim(): void
    {
        $context = ['text' => '  hello  '];

        $result = $this->evaluator->evaluate('{{text.trim()}}', $context);
        $this->assertSame('hello', $result);
    }

    /**
     * String.toLowerCase()
     */
    public function test_string_to_lower_case(): void
    {
        $context = ['text' => 'HELLO WORLD'];

        $result = $this->evaluator->evaluate('{{text.toLowerCase()}}', $context);
        $this->assertSame('hello world', $result);
    }

    /**
     * String.toUpperCase()
     */
    public function test_string_to_upper_case(): void
    {
        $context = ['text' => 'hello'];

        $result = $this->evaluator->evaluate('{{text.toUpperCase()}}', $context);
        $this->assertSame('HELLO', $result);
    }

    /**
     * String.includes()
     */
    public function test_string_includes(): void
    {
        $context = ['text' => 'Hello World'];

        $result = $this->evaluator->evaluate("{{text.includes('World')}}", $context);
        $this->assertSame('true', $result);
    }

    /**
     * String.startsWith()
     */
    public function test_string_starts_with(): void
    {
        $context = ['url' => 'https://example.com'];

        $result = $this->evaluator->evaluate("{{url.startsWith('https')}}", $context);
        $this->assertSame('true', $result);
    }

    /**
     * String.endsWith()
     */
    public function test_string_ends_with(): void
    {
        $context = ['file' => 'image.png'];

        $result = $this->evaluator->evaluate("{{file.endsWith('.png')}}", $context);
        $this->assertSame('true', $result);
    }

    /**
     * String.replace() — 최초 1회만 치환
     */
    public function test_string_replace(): void
    {
        $context = ['text' => 'foo bar foo'];

        $result = $this->evaluator->evaluate("{{text.replace('foo', 'baz')}}", $context);
        $this->assertSame('baz bar foo', $result);
    }

    /**
     * String.replaceAll() — 전체 치환
     */
    public function test_string_replace_all(): void
    {
        $context = ['text' => 'foo bar foo'];

        $result = $this->evaluator->evaluate("{{text.replaceAll('foo', 'baz')}}", $context);
        $this->assertSame('baz bar baz', $result);
    }

    /**
     * String.substring()
     */
    public function test_string_substring(): void
    {
        $context = ['text' => 'Hello World'];

        $result = $this->evaluator->evaluate('{{text.substring(0, 5)}}', $context);
        $this->assertSame('Hello', $result);
    }

    /**
     * String.slice() — 음수 인덱스 지원
     */
    public function test_string_slice_negative(): void
    {
        $context = ['text' => 'Hello World'];

        $result = $this->evaluator->evaluate('{{text.slice(-5)}}', $context);
        $this->assertSame('World', $result);
    }

    /**
     * String.indexOf()
     */
    public function test_string_index_of(): void
    {
        $context = ['text' => 'Hello World'];

        $result = $this->evaluator->evaluate("{{text.indexOf('World')}}", $context);
        $this->assertSame('6', $result);
    }

    /**
     * String.repeat()
     */
    public function test_string_repeat(): void
    {
        $context = ['star' => '★'];

        $result = $this->evaluator->evaluate('{{star.repeat(3)}}', $context);
        $this->assertSame('★★★', $result);
    }

    /**
     * String.padStart()
     */
    public function test_string_pad_start(): void
    {
        $context = ['num' => '5'];

        $result = $this->evaluator->evaluate("{{num.padStart(3, '0')}}", $context);
        $this->assertSame('005', $result);
    }

    /**
     * String.charAt()
     */
    public function test_string_char_at(): void
    {
        $context = ['text' => 'ABC'];

        $result = $this->evaluator->evaluate('{{text.charAt(1)}}', $context);
        $this->assertSame('B', $result);
    }

    /**
     * String.at() — 음수 인덱스
     */
    public function test_string_at_negative(): void
    {
        $context = ['text' => 'ABCDE'];

        $result = $this->evaluator->evaluate('{{text.at(-1)}}', $context);
        $this->assertSame('E', $result);
    }

    // ==========================================
    // 숫자 인스턴스 메서드 (Number Instance Methods)
    // ==========================================

    /**
     * Number.toLocaleString() — 천 단위 구분
     */
    public function test_number_to_locale_string(): void
    {
        $context = ['price' => 64000];

        $result = $this->evaluator->evaluate('{{price.toLocaleString()}}', $context);
        $this->assertSame('64,000', $result);
    }

    /**
     * Number.toFixed() — 소수점 고정
     */
    public function test_number_to_fixed(): void
    {
        $context = ['rate' => 3.14159];

        $result = $this->evaluator->evaluate('{{rate.toFixed(2)}}', $context);
        $this->assertSame('3.14', $result);
    }

    /**
     * Number.toString() — 진수 변환
     */
    public function test_number_to_string_base(): void
    {
        $context = ['num' => 255];

        $result = $this->evaluator->evaluate('{{num.toString(16)}}', $context);
        $this->assertSame('ff', $result);
    }

    // ==========================================
    // 정적 메서드 (Static Methods)
    // ==========================================

    /**
     * Object.keys() — 객체 키 추출
     */
    public function test_object_keys(): void
    {
        $context = ['obj' => ['a' => 1, 'b' => 2, 'c' => 3]];

        $result = $this->evaluator->evaluate("{{Object.keys(obj).join(', ')}}", $context);
        $this->assertSame('a, b, c', $result);
    }

    /**
     * Object.values() — 객체 값 추출
     */
    public function test_object_values(): void
    {
        $context = ['obj' => ['x' => 10, 'y' => 20]];

        $result = $this->evaluator->evaluate("{{Object.values(obj).join(', ')}}", $context);
        $this->assertSame('10, 20', $result);
    }

    /**
     * Object.keys().length — 키 수 (메서드 + 프로퍼티 체이닝)
     */
    public function test_object_keys_length(): void
    {
        $context = ['obj' => ['a' => 1, 'b' => 2, 'c' => 3]];

        $result = $this->evaluator->evaluate('{{Object.keys(obj).length}}', $context);
        $this->assertSame('3', $result);
    }

    /**
     * Math.min()
     */
    public function test_math_min(): void
    {
        $context = [];

        $result = $this->evaluator->evaluate('{{Math.min(10, 5, 20)}}', $context);
        $this->assertSame('5', $result);
    }

    /**
     * Math.max()
     */
    public function test_math_max(): void
    {
        $context = [];

        $result = $this->evaluator->evaluate('{{Math.max(10, 5, 20)}}', $context);
        $this->assertSame('20', $result);
    }

    /**
     * Math.floor()
     */
    public function test_math_floor(): void
    {
        $context = [];

        $result = $this->evaluator->evaluate('{{Math.floor(3.7)}}', $context);
        $this->assertSame('3', $result);
    }

    /**
     * Math.ceil()
     */
    public function test_math_ceil(): void
    {
        $context = [];

        $result = $this->evaluator->evaluate('{{Math.ceil(3.2)}}', $context);
        $this->assertSame('4', $result);
    }

    /**
     * Math.round()
     */
    public function test_math_round(): void
    {
        $context = [];

        $result = $this->evaluator->evaluate('{{Math.round(3.5)}}', $context);
        $this->assertSame('4', $result);
    }

    /**
     * Math.abs()
     */
    public function test_math_abs(): void
    {
        $context = [];

        $result = $this->evaluator->evaluate('{{Math.abs(-42)}}', $context);
        $this->assertSame('42', $result);
    }

    /**
     * Array.isArray() — 배열 판별
     */
    public function test_array_is_array_true(): void
    {
        $context = ['items' => [1, 2, 3]];

        $result = $this->evaluator->evaluate('{{Array.isArray(items)}}', $context);
        $this->assertSame('true', $result);
    }

    /**
     * Array.isArray() — 비배열
     */
    public function test_array_is_array_false(): void
    {
        $context = ['text' => 'hello'];

        $result = $this->evaluator->evaluate('{{Array.isArray(text)}}', $context);
        $this->assertSame('false', $result);
    }

    /**
     * JSON.stringify()
     */
    public function test_json_stringify(): void
    {
        $context = ['obj' => ['name' => '테스트']];

        $result = $this->evaluator->evaluate('{{JSON.stringify(obj)}}', $context);
        $this->assertSame('{"name":"테스트"}', $result);
    }

    // ==========================================
    // 전역 함수 (Global Functions)
    // ==========================================

    /**
     * Number() — 형 변환
     */
    public function test_global_number(): void
    {
        $context = ['str' => '42'];

        $result = $this->evaluator->evaluate('{{Number(str)}}', $context);
        $this->assertSame('42', $result);
    }

    /**
     * String() — 형 변환
     */
    public function test_global_string(): void
    {
        $context = ['num' => 123];

        $result = $this->evaluator->evaluate('{{String(num)}}', $context);
        $this->assertSame('123', $result);
    }

    /**
     * Boolean() — truthy 판별
     */
    public function test_global_boolean_truthy(): void
    {
        $context = ['val' => 'hello'];

        $result = $this->evaluator->evaluate('{{Boolean(val)}}', $context);
        $this->assertSame('true', $result);
    }

    /**
     * parseInt()
     */
    public function test_global_parse_int(): void
    {
        $context = ['str' => '42.7'];

        $result = $this->evaluator->evaluate('{{parseInt(str)}}', $context);
        $this->assertSame('42', $result);
    }

    /**
     * parseFloat()
     */
    public function test_global_parse_float(): void
    {
        $context = ['str' => '3.14'];

        $result = $this->evaluator->evaluate('{{parseFloat(str)}}', $context);
        $this->assertSame('3.14', $result);
    }

    /**
     * encodeURIComponent()
     */
    public function test_global_encode_uri_component(): void
    {
        $context = ['q' => '한글 검색'];

        $result = $this->evaluator->evaluate('{{encodeURIComponent(q)}}', $context);
        $this->assertSame(rawurlencode('한글 검색'), $result);
    }

    /**
     * isNaN() — 비숫자 판별
     */
    public function test_global_is_na_n(): void
    {
        $context = ['val' => 'abc'];

        $result = $this->evaluator->evaluate('{{isNaN(val)}}', $context);
        $this->assertSame('true', $result);
    }

    // ==========================================
    // 메서드 체이닝 (Method Chaining)
    // ==========================================

    /**
     * Object.values().join() — 정적 메서드 → 인스턴스 메서드 체이닝
     */
    public function test_method_chaining_static_to_instance(): void
    {
        $context = ['option' => ['color' => '빨강', 'size' => 'M']];

        $result = $this->evaluator->evaluate("{{Object.values(option).join(', ')}}", $context);
        $this->assertSame('빨강, M', $result);
    }

    /**
     * split → join 체이닝
     */
    public function test_method_chaining_split_join(): void
    {
        $context = ['text' => 'a-b-c'];

        $result = $this->evaluator->evaluate("{{text.split('-').join(', ')}}", $context);
        $this->assertSame('a, b, c', $result);
    }

    /**
     * filter → map → join 체이닝
     */
    public function test_method_chaining_filter_map_join(): void
    {
        $context = [
            'items' => [
                ['name' => '마우스', 'stock' => 5],
                ['name' => '키보드', 'stock' => 0],
                ['name' => '모니터', 'stock' => 3],
            ],
        ];

        $result = $this->evaluator->evaluate("{{items.filter(item => item.stock).map(item => item.name).join(', ')}}", $context);
        $this->assertSame('마우스, 모니터', $result);
    }

    // ==========================================
    // 실제 레이아웃 표현식 시나리오
    // ==========================================

    /**
     * 실제 레이아웃: 옵션 그룹 값 표시 (Object.values.join 패턴)
     */
    public function test_real_world_option_values_display(): void
    {
        $context = [
            'selectedOptions' => ['컬러' => '블랙', '사이즈' => 'L'],
        ];

        $result = $this->evaluator->evaluate("{{Object.values(selectedOptions).join(' / ')}}", $context);
        $this->assertSame('블랙 / L', $result);
    }

    /**
     * 실제 레이아웃: 쿠폰 목록에서 이름만 추출
     */
    public function test_real_world_coupon_names(): void
    {
        $context = [
            'coupons' => [
                ['name' => '10% 할인', 'type' => 'percent'],
                ['name' => '무료배송', 'type' => 'shipping'],
            ],
        ];

        $result = $this->evaluator->evaluate("{{coupons.map(c => c.name).join(', ')}}", $context);
        $this->assertSame('10% 할인, 무료배송', $result);
    }

    /**
     * 실제 레이아웃: 가격 포맷 (toLocaleString)
     */
    public function test_real_world_price_format(): void
    {
        $context = ['product' => ['data' => ['selling_price' => 64000]]];

        $result = $this->evaluator->evaluate('{{product.data.selling_price.toLocaleString()}}', $context);
        $this->assertSame('64,000', $result);
    }

    /**
     * 실제 레이아웃: 상품정보제공고시 항목 키 매핑
     */
    public function test_real_world_notice_item_mapping(): void
    {
        $context = [
            'notice' => [
                'values' => [
                    ['key' => 'material', 'value' => '면 100%'],
                    ['key' => 'origin', 'value' => '대한민국'],
                ],
            ],
        ];

        $result = $this->evaluator->evaluate('{{notice.values.map(item => item.key).join()}}', $context);
        $this->assertSame('material,origin', $result);
    }

    /**
     * 실제 레이아웃: 색상 옵션 목록 (join 표현식)
     */
    public function test_real_world_color_options_join(): void
    {
        $context = [
            'group' => [
                'values' => [
                    ['name' => '블랙'],
                    ['name' => '화이트'],
                    ['name' => '네이비'],
                ],
            ],
        ];

        $result = $this->evaluator->evaluate("{{group.values.map(v => v.name).join(', ')}}", $context);
        $this->assertSame('블랙, 화이트, 네이비', $result);
    }

    /**
     * Number.isNaN — 정적 메서드
     */
    public function test_number_is_na_n_static(): void
    {
        $context = ['val' => 42];

        $result = $this->evaluator->evaluate('{{Number.isNaN(val)}}', $context);
        $this->assertSame('false', $result);
    }

    /**
     * Number.isFinite — 정적 메서드
     */
    public function test_number_is_finite_static(): void
    {
        $context = ['val' => 42];

        $result = $this->evaluator->evaluate('{{Number.isFinite(val)}}', $context);
        $this->assertSame('true', $result);
    }

    // =========================================================================
    // 숫자 리터럴 처리 테스트
    // =========================================================================

    /**
     * 숫자 리터럴 0은 경로가 아닌 리터럴로 해석됩니다.
     */
    public function test_numeric_literal_zero(): void
    {
        $result = $this->evaluator->evaluate('{{0}}', []);

        $this->assertSame('0', $result);
    }

    /**
     * 양의 정수 리터럴이 올바르게 해석됩니다.
     */
    public function test_numeric_literal_positive_integer(): void
    {
        $result = $this->evaluator->evaluate('{{42}}', []);

        $this->assertSame('42', $result);
    }

    /**
     * 음의 정수 리터럴이 올바르게 해석됩니다.
     */
    public function test_numeric_literal_negative_integer(): void
    {
        $result = $this->evaluator->evaluate('{{-1}}', []);

        $this->assertSame('-1', $result);
    }

    /**
     * 소수점 리터럴이 올바르게 해석됩니다.
     */
    public function test_numeric_literal_decimal(): void
    {
        $result = $this->evaluator->evaluate('{{3.14}}', []);

        $this->assertSame('3.14', $result);
    }

    /**
     * null coalescing 폴백으로 숫자 리터럴 0이 올바르게 반환됩니다.
     */
    public function test_null_coalescing_fallback_to_numeric_zero(): void
    {
        $context = ['products' => ['data' => [['id' => 1]]]];

        // products.data.pagination은 존재하지 않으므로 ?? 0 으로 폴백
        $result = $this->evaluator->evaluate('{{products?.data?.pagination?.total ?? 0}}', $context);

        $this->assertSame('0', $result);
    }

    /**
     * null coalescing 폴백으로 숫자 리터럴 1이 올바르게 반환됩니다.
     */
    public function test_null_coalescing_fallback_to_numeric_one(): void
    {
        $context = [];

        $result = $this->evaluator->evaluate('{{missing?.path ?? 1}}', $context);

        $this->assertSame('1', $result);
    }

    /**
     * null coalescing에서 좌측 값이 존재하면 폴백하지 않습니다.
     */
    public function test_null_coalescing_no_fallback_when_value_exists(): void
    {
        $context = ['products' => ['meta' => ['total' => 97]]];

        $result = $this->evaluator->evaluate('{{products?.meta?.total ?? 0}}', $context);

        $this->assertSame('97', $result);
    }

    // =========================================================================
    // $t: 파라미터 {{}} 표현식 해석 테스트
    // =========================================================================

    /**
     * $t: 파라미터 값에 {{}} 표현식이 포함되면 컨텍스트에서 해석됩니다.
     */
    public function test_translation_param_with_expression(): void
    {
        $this->evaluator->setTranslations(['shop' => ['total_count' => '총 {{count}}개']]);
        $context = ['products' => ['meta' => ['total' => 97]]];

        $result = $this->evaluator->evaluate('$t:shop.total_count|count={{products?.meta?.total ?? 0}}', $context);

        $this->assertSame('총 97개', $result);
    }

    /**
     * $t: 파라미터의 {{}} 표현식이 폴백을 사용할 때 올바르게 해석됩니다.
     */
    public function test_translation_param_expression_with_fallback(): void
    {
        $this->evaluator->setTranslations(['shop' => ['total_count' => '총 {{count}}개']]);
        $context = ['products' => ['data' => []]];

        // products.data.pagination.total은 존재하지 않으므로 ?? 0 폴백
        $result = $this->evaluator->evaluate('$t:shop.total_count|count={{products?.data?.pagination?.total ?? 0}}', $context);

        $this->assertSame('총 0개', $result);
    }

    /**
     * $t: 파라미터에 리터럴 값이 올바르게 처리됩니다.
     */
    public function test_translation_param_literal_value(): void
    {
        $this->evaluator->setTranslations(['shop' => ['total_count' => '총 {{count}}개']]);

        $result = $this->evaluator->evaluate('$t:shop.total_count|count=42', []);

        $this->assertSame('총 42개', $result);
    }

    /**
     * 템플릿 번역의 {{param}} 형식과 Laravel의 :param 형식 모두 치환됩니다.
     */
    public function test_translation_both_param_formats(): void
    {
        // {{param}} 형식 (템플릿 번역)
        $this->evaluator->setTranslations(['test' => ['msg' => '{{name}}님 환영']]);

        $result = $this->evaluator->evaluate('$t:test.msg|name=홍길동', []);
        $this->assertSame('홍길동님 환영', $result);

        // :param 형식 (Laravel 번역) — Laravel __() fallback
        app('translator')->addLines(['test.laravel_msg' => ':name님 환영합니다'], 'ko');
        app()->setLocale('ko');

        $evaluator2 = new ExpressionEvaluator;
        $result2 = $evaluator2->evaluate('$t:test.laravel_msg|name=홍길동', []);
        $this->assertSame('홍길동님 환영합니다', $result2);
    }

    /**
     * $t: 파라미터 없이도 번역이 올바르게 동작합니다.
     */
    public function test_translation_without_params(): void
    {
        $this->evaluator->setTranslations(['shop' => ['title' => '쇼핑몰']]);

        $result = $this->evaluator->evaluate('$t:shop.title', []);

        $this->assertSame('쇼핑몰', $result);
    }

    /**
     * $t: 파라미터에 여러 {{}} 표현식이 포함될 때 모두 해석됩니다.
     */
    public function test_translation_multiple_expression_params(): void
    {
        $this->evaluator->setTranslations([
            'shop' => ['page_info' => '{{current}} / {{total}} 페이지'],
        ]);
        $context = ['meta' => ['current_page' => 3, 'last_page' => 10]];

        $result = $this->evaluator->evaluate(
            '$t:shop.page_info|current={{meta?.current_page ?? 1}}|total={{meta?.last_page ?? 1}}',
            $context
        );

        $this->assertSame('3 / 10 페이지', $result);
    }

    // =========================================================================
    // 산술 연산 (Arithmetic Operations)
    // =========================================================================

    /**
     * 덧셈: 경로 값 + 숫자 리터럴이 올바르게 계산됩니다.
     */
    public function test_arithmetic_addition(): void
    {
        $context = ['page' => ['current' => 3]];

        $result = $this->evaluator->evaluate('{{page.current + 1}}', $context);

        $this->assertSame('4', $result);
    }

    /**
     * 뺄셈: 경로 값 - 숫자 리터럴이 올바르게 계산됩니다.
     */
    public function test_arithmetic_subtraction(): void
    {
        $context = ['page' => ['current' => 3]];

        $result = $this->evaluator->evaluate('{{page.current - 1}}', $context);

        $this->assertSame('2', $result);
    }

    /**
     * null coalescing + 산술 연산이 조합됩니다.
     */
    public function test_arithmetic_with_null_coalescing(): void
    {
        $context = ['products' => ['data' => ['pagination' => ['current_page' => 5]]]];

        $result = $this->evaluator->evaluate('{{(products?.data?.pagination?.current_page ?? 1) + 1}}', $context);
        $this->assertSame('6', $result);

        $result = $this->evaluator->evaluate('{{(products?.data?.pagination?.current_page ?? 1) - 1}}', $context);
        $this->assertSame('4', $result);
    }

    /**
     * null coalescing fallback + 산술 연산이 올바르게 동작합니다.
     */
    public function test_arithmetic_with_null_coalescing_fallback(): void
    {
        $context = [];

        // missing path → fallback to 1, then 1 + 1 = 2
        $result = $this->evaluator->evaluate('{{(missing?.path ?? 1) + 1}}', $context);
        $this->assertSame('2', $result);

        // missing path → fallback to 1, then 1 - 1 = 0
        $result = $this->evaluator->evaluate('{{(missing?.path ?? 1) - 1}}', $context);
        $this->assertSame('0', $result);
    }

    /**
     * 산술 연산에서 좌측이 비숫자일 때 산술이 무시됩니다.
     */
    public function test_arithmetic_non_numeric_left_side(): void
    {
        $context = ['name' => '텍스트'];

        $result = $this->evaluator->evaluate('{{name + 1}}', $context);

        // 비숫자이므로 산술 연산 무시, 빈 문자열 반환
        $this->assertSame('', $result);
    }

    /**
     * 소수점 산술 연산이 올바르게 동작합니다.
     */
    public function test_arithmetic_with_decimal(): void
    {
        $context = ['price' => 100];

        $result = $this->evaluator->evaluate('{{price + 0.5}}', $context);
        $this->assertSame('100.5', $result);
    }

    /**
     * 페이지네이션 실제 패턴: 이전/다음 페이지 계산
     */
    public function test_arithmetic_real_world_pagination(): void
    {
        $context = [
            'products' => [
                'data' => [
                    'pagination' => [
                        'current_page' => 3,
                        'last_page' => 10,
                    ],
                ],
            ],
        ];

        // 이전 페이지
        $prev = $this->evaluator->evaluate('{{(products?.data?.pagination?.current_page ?? 1) - 1}}', $context);
        $this->assertSame('2', $prev);

        // 다음 페이지
        $next = $this->evaluator->evaluate('{{(products?.data?.pagination?.current_page ?? 1) + 1}}', $context);
        $this->assertSame('4', $next);
    }

    // ==========================================
    // null 비교 JavaScript 시맨틱 (SEO 컨텍스트)
    // ==========================================

    /**
     * null !== 값 → true (JavaScript null 시맨틱)
     */
    public function test_null_strict_not_equals_value_returns_true(): void
    {
        $context = ['_global' => []];

        // _global.commentEdit?.editingCommentId가 null (미존재)
        // null !== comment?.id → true
        $result = $this->evaluator->evaluate('{{_global.commentEdit?.editingCommentId !== 123}}', $context);

        $this->assertSame('true', $result);
    }

    /**
     * null === 값 → false (JavaScript null 시맨틱)
     */
    public function test_null_strict_equals_value_returns_false(): void
    {
        $context = [];

        $result = $this->evaluator->evaluate('{{missing.path === "test"}}', $context);

        $this->assertSame('false', $result);
    }

    /**
     * null !== null → false
     */
    public function test_null_strict_not_equals_null_returns_false(): void
    {
        $context = [];

        $result = $this->evaluator->evaluate('{{missing.a !== missing.b}}', $context);

        $this->assertSame('false', $result);
    }

    /**
     * null === null → true
     */
    public function test_null_strict_equals_null_returns_true(): void
    {
        $context = [];

        $result = $this->evaluator->evaluate('{{missing.a === missing.b}}', $context);

        $this->assertSame('true', $result);
    }

    /**
     * SEO 댓글 if 조건: _global 미존재 시에도 && 체인이 정상 작동합니다.
     */
    public function test_seo_comment_if_condition_with_empty_global(): void
    {
        $context = [
            '_global' => [],
            'comment' => [
                'deleted_at' => null,
                'status' => 'active',
                'id' => 42,
            ],
        ];

        // 댓글 컨텐츠 표시 조건: !deleted_at && status !== 'blinded' && editingId !== commentId
        $result = $this->evaluator->evaluate(
            '{{!comment?.deleted_at && comment?.status !== "blinded" && _global.commentEdit?.editingCommentId !== comment?.id}}',
            $context
        );

        $this->assertSame('true', $result);
    }

    // ==========================================
    // evaluateRaw (원본 값 반환)
    // ==========================================

    /**
     * evaluateRaw: 단일 {{expr}} 패턴으로 배열을 원본 반환합니다.
     */
    public function test_evaluate_raw_returns_array(): void
    {
        $context = [
            'comment' => [
                'author' => ['id' => 1, 'nickname' => '홍길동', 'profile_photo_url' => '/avatar.jpg'],
            ],
        ];

        $result = $this->evaluator->evaluateRaw('{{comment?.author}}', $context);

        $this->assertIsArray($result);
        $this->assertSame('홍길동', $result['nickname']);
    }

    /**
     * evaluateRaw: 문자열 값은 문자열로 반환합니다.
     */
    public function test_evaluate_raw_returns_string(): void
    {
        $context = ['comment' => ['created_at' => '2026-03-19 10:00:00']];

        $result = $this->evaluator->evaluateRaw('{{comment?.created_at}}', $context);

        $this->assertSame('2026-03-19 10:00:00', $result);
    }

    /**
     * evaluateRaw: 복합 표현식은 문자열로 반환합니다.
     */
    public function test_evaluate_raw_complex_expression_returns_string(): void
    {
        $context = ['a' => 'hello'];

        $result = $this->evaluator->evaluateRaw('prefix {{a}} suffix', $context);

        $this->assertSame('prefix hello suffix', $result);
    }

    /**
     * evaluateRaw: 미존재 경로는 null을 반환합니다.
     */
    public function test_evaluate_raw_missing_path_returns_null(): void
    {
        $context = [];

        $result = $this->evaluator->evaluateRaw('{{missing.path}}', $context);

        $this->assertNull($result);
    }

    // ===================================================================
    // 배열 리터럴 파싱 테스트
    // ===================================================================

    /**
     * 배열 리터럴 + includes() — 포함
     */
    public function test_array_literal_includes_true(): void
    {
        $context = ['board' => ['type' => 'gallery']];

        $result = $this->evaluator->evaluate("{{['gallery','card'].includes(board?.type)}}", $context);
        $this->assertSame('true', $result);
    }

    /**
     * 배열 리터럴 + includes() — 미포함
     */
    public function test_array_literal_includes_false(): void
    {
        $context = ['board' => ['type' => 'basic']];

        $result = $this->evaluator->evaluate("{{['gallery','card'].includes(board?.type)}}", $context);
        $this->assertSame('false', $result);
    }

    /**
     * 배열 리터럴 + 부정 연산자 + includes() — 게시판 타입 분기 실제 패턴
     */
    public function test_array_literal_negated_includes_board_type(): void
    {
        $context = ['posts' => ['data' => ['board' => ['type' => 'gallery']]]];

        // gallery 타입 → includes=true → !includes=false → basic 블록 미렌더링
        $result = $this->evaluator->evaluate(
            "{{posts?.data?.board?.type === 'basic' || !['gallery','card'].includes(posts?.data?.board?.type ?? 'basic')}}",
            $context
        );
        $this->assertSame('false', $result);
    }

    /**
     * 배열 리터럴 + 부정 연산자 — basic 타입은 fallback으로 렌더링
     */
    public function test_array_literal_negated_includes_basic_type(): void
    {
        $context = ['posts' => ['data' => ['board' => ['type' => 'basic']]]];

        $result = $this->evaluator->evaluate(
            "{{posts?.data?.board?.type === 'basic' || !['gallery','card'].includes(posts?.data?.board?.type ?? 'basic')}}",
            $context
        );
        $this->assertSame('true', $result);
    }

    /**
     * 배열 리터럴 + 부정 연산자 — 알 수 없는 타입은 basic으로 fallback
     */
    public function test_array_literal_negated_includes_unknown_type(): void
    {
        $context = ['posts' => ['data' => ['board' => ['type' => 'unknown']]]];

        $result = $this->evaluator->evaluate(
            "{{posts?.data?.board?.type === 'basic' || !['gallery','card'].includes(posts?.data?.board?.type ?? 'basic')}}",
            $context
        );
        $this->assertSame('true', $result);
    }

    /**
     * 빈 배열 리터럴 includes — 항상 false
     */
    public function test_empty_array_literal_includes(): void
    {
        $context = ['val' => 'test'];

        $result = $this->evaluator->evaluate("{{[].includes(val)}}", $context);
        $this->assertSame('false', $result);
    }

    /**
     * 배열 리터럴 — 숫자 요소 포함
     */
    public function test_array_literal_with_numbers(): void
    {
        $context = ['status' => ['code' => 200]];

        $result = $this->evaluator->evaluate("{{[200, 201, 204].includes(status?.code)}}", $context);
        $this->assertSame('true', $result);
    }

    /**
     * 배열 리터럴 — 표현식 요소가 context로 해석되어 배열로 파싱됨
     */
    public function test_array_literal_with_expression_elements_resolves(): void
    {
        $context = ['items' => ['a', 'b'], 'val' => 'a'];

        // val은 'a'로 해석되므로 [['a','b'], 'a'].includes('a') → true
        $result = $this->evaluator->evaluate("{{[items, val].includes('a')}}", $context);
        $this->assertSame('true', $result);
    }

    // ====================================================================
    // SEO Overrides (seo_overrides 와일드카드 매칭)
    // ====================================================================

    public function test_seo_override_wildcard_returns_value_for_dynamic_bracket_access(): void
    {
        $this->evaluator->setSeoOverrides([
            '_local' => [
                'collapsedReplies' => ['*' => false],
            ],
        ]);

        $context = [
            '_local' => [],
            'comment' => ['id' => 5, 'depth' => 1],
        ];

        // _local.collapsedReplies?.[$computed.xxx] — $computed 미존재 → 브래킷 키 null
        // seo_overrides 와일드카드가 false 반환 → false === false → true
        $result = $this->evaluator->evaluate(
            '{{_local.collapsedReplies?.[$computed.commentRootMap?.[comment?.id]] === false}}',
            $context
        );
        $this->assertSame('true', $result);
    }

    public function test_seo_override_wildcard_returns_value_for_resolved_bracket_key(): void
    {
        $this->evaluator->setSeoOverrides([
            '_local' => [
                'collapsedReplies' => ['*' => false],
            ],
        ]);

        $context = [
            '_local' => ['collapsedReplies' => []],
            'rootId' => '3',
        ];

        // 브래킷 키가 정상 해석되지만, _local.collapsedReplies.3이 없음 → 와일드카드 매칭
        $result = $this->evaluator->evaluate(
            '{{_local.collapsedReplies?.[rootId] === false}}',
            $context
        );
        $this->assertSame('true', $result);
    }

    public function test_seo_override_exact_match_returns_value(): void
    {
        $this->evaluator->setSeoOverrides([
            '_local' => [
                'showAllComments' => true,
            ],
        ]);

        $context = ['_local' => []];

        $result = $this->evaluator->evaluate('{{_local.showAllComments}}', $context);
        $this->assertSame('true', $result);
    }

    public function test_seo_override_does_not_affect_existing_values(): void
    {
        $this->evaluator->setSeoOverrides([
            '_local' => [
                'collapsedReplies' => ['*' => false],
            ],
        ]);

        $context = [
            '_local' => [
                'collapsedReplies' => ['3' => true],
            ],
            'rootId' => '3',
        ];

        // 실제 값이 존재하면 오버라이드가 아닌 실제 값 사용
        $result = $this->evaluator->evaluate(
            '{{_local.collapsedReplies?.[rootId] === true}}',
            $context
        );
        $this->assertSame('true', $result);
    }

    public function test_seo_override_without_overrides_returns_normal(): void
    {
        // seo_overrides 미설정 → 기존 동작 유지
        $context = [
            '_local' => [],
            'comment' => ['id' => 5],
        ];

        $result = $this->evaluator->evaluate(
            '{{_local.collapsedReplies?.[comment?.id] === false}}',
            $context
        );
        // _local.collapsedReplies 없음 → null === false → false
        $this->assertSame('false', $result);
    }

    public function test_seo_override_comment_depth_condition_with_override(): void
    {
        $this->evaluator->setSeoOverrides([
            '_local' => [
                'collapsedReplies' => ['*' => false],
            ],
        ]);

        // 대댓글 (depth=1) — SEO에서 표시되어야 함
        $context = [
            '_local' => [],
            'comment' => ['id' => 10, 'depth' => 1],
        ];

        // (depth ?? 0) === 0 || (collapsedReplies?.[xxx] === false)
        // = false || true = true → 대댓글 표시
        $result = $this->evaluator->evaluate(
            '{{(comment?.depth ?? 0) === 0 || (_local.collapsedReplies?.[$computed.commentRootMap?.[comment?.id]] === false)}}',
            $context
        );
        $this->assertSame('true', $result);
    }

    public function test_seo_override_root_comment_still_shows(): void
    {
        $this->evaluator->setSeoOverrides([
            '_local' => [
                'collapsedReplies' => ['*' => false],
            ],
        ]);

        // 루트 댓글 (depth=0) — 오버라이드와 무관하게 항상 표시
        $context = [
            '_local' => [],
            'comment' => ['id' => 1, 'depth' => 0],
        ];

        $result = $this->evaluator->evaluate(
            '{{(comment?.depth ?? 0) === 0 || (_local.collapsedReplies?.[$computed.commentRootMap?.[comment?.id]] === false)}}',
            $context
        );
        $this->assertSame('true', $result);
    }

    public function test_seo_override_global_scope(): void
    {
        $this->evaluator->setSeoOverrides([
            '_global' => [
                'expandedSections' => ['*' => true],
            ],
        ]);

        $context = [
            '_global' => [],
            'sectionId' => 'faq',
        ];

        $result = $this->evaluator->evaluate(
            '{{_global.expandedSections?.[sectionId]}}',
            $context
        );
        $this->assertSame('true', $result);
    }

    /**
     * 배열 다중 값 매칭: seo_overrides에서 배열로 설정된 값은
     * === 비교 시 배열 내 어떤 값과도 매칭됨
     */
    public function test_seo_override_array_multi_value_equals_match(): void
    {
        $this->evaluator->setSeoOverrides([
            '_local' => [
                'activeTab' => ['info', 'reviews', 'qna'],
            ],
        ]);

        $context = ['_local' => []];

        // 배열 내 각 값에 대해 === 비교가 true
        $this->assertSame('true', $this->evaluator->evaluate(
            "{{_local.activeTab === 'reviews'}}",
            $context
        ));
        $this->assertSame('true', $this->evaluator->evaluate(
            "{{_local.activeTab === 'info'}}",
            $context
        ));
        $this->assertSame('true', $this->evaluator->evaluate(
            "{{_local.activeTab === 'qna'}}",
            $context
        ));

        // 배열에 없는 값은 false
        $this->assertSame('false', $this->evaluator->evaluate(
            "{{_local.activeTab === 'unknown'}}",
            $context
        ));
    }

    /**
     * 배열 다중 값 매칭: !== 비교 시 배열 내 매칭 값이 있으면 false
     */
    public function test_seo_override_array_multi_value_not_equals(): void
    {
        $this->evaluator->setSeoOverrides([
            '_local' => [
                'activeTab' => ['info', 'reviews', 'qna'],
            ],
        ]);

        $context = ['_local' => []];

        // 배열 내 값과 매칭되므로 !== 는 false
        $this->assertSame('false', $this->evaluator->evaluate(
            "{{_local.activeTab !== 'reviews'}}",
            $context
        ));

        // 배열에 없는 값은 !== true
        $this->assertSame('true', $this->evaluator->evaluate(
            "{{_local.activeTab !== 'unknown'}}",
            $context
        ));
    }

    /**
     * 배열 오버라이드는 context 값보다 우선함
     * (init_actions로 _local.activeTab = 'reviews' 설정되어도 배열 오버라이드가 우선)
     */
    public function test_seo_override_array_takes_priority_over_context(): void
    {
        $this->evaluator->setSeoOverrides([
            '_local' => [
                'activeTab' => ['info', 'reviews', 'qna'],
            ],
        ]);

        // context에 단일 값이 설정되어 있어도 배열 오버라이드가 우선
        $context = ['_local' => ['activeTab' => 'reviews']];

        // 'info'는 context 값('reviews')과 다르지만 배열 오버라이드로 매칭됨
        $this->assertSame('true', $this->evaluator->evaluate(
            "{{_local.activeTab === 'info'}}",
            $context
        ));
        $this->assertSame('true', $this->evaluator->evaluate(
            "{{_local.activeTab === 'qna'}}",
            $context
        ));
    }

    /**
     * 배열 다중 값 매칭 + null coalescing: 실제 파셜 패턴
     * (_local.activeTab ?? 'info') === 'reviews' 표현식에서 배열 오버라이드 동작
     */
    public function test_seo_override_array_with_null_coalescing(): void
    {
        $this->evaluator->setSeoOverrides([
            '_local' => [
                'activeTab' => ['info', 'reviews', 'qna'],
            ],
        ]);

        // init_actions로 설정된 context 값이 있어도 배열 오버라이드 우선
        $context = ['_local' => ['activeTab' => 'info']];

        // 실제 파셜 표현식 패턴: (_local.activeTab ?? 'info') === 'reviews'
        $this->assertSame('true', $this->evaluator->evaluate(
            "{{(_local.activeTab ?? 'info') === 'reviews'}}",
            $context
        ));
        $this->assertSame('true', $this->evaluator->evaluate(
            "{{(_local.activeTab ?? 'info') === 'info'}}",
            $context
        ));
        $this->assertSame('true', $this->evaluator->evaluate(
            "{{(_local.activeTab ?? 'info') === 'qna'}}",
            $context
        ));
        $this->assertSame('false', $this->evaluator->evaluate(
            "{{(_local.activeTab ?? 'info') === 'unknown'}}",
            $context
        ));

        // !== 비교도 정상 동작
        $this->assertSame('false', $this->evaluator->evaluate(
            "{{(_local.activeTab ?? 'info') !== 'reviews'}}",
            $context
        ));
    }

    /**
     * 와일드카드 오버라이드는 기존대로 fallback만 (회귀 테스트)
     */
    public function test_seo_override_wildcard_still_fallback_only(): void
    {
        $this->evaluator->setSeoOverrides([
            '_local' => [
                'collapsedReplies' => ['*' => false],
            ],
        ]);

        // context에 값이 있으면 와일드카드는 적용 안됨 (기존 동작)
        $context = [
            '_local' => ['collapsedReplies' => ['comment_1' => true]],
            'commentId' => 'comment_1',
        ];

        $result = $this->evaluator->evaluate(
            '{{_local.collapsedReplies?.[commentId]}}',
            $context
        );
        $this->assertSame('true', $result);

        // context에 값이 없으면 와일드카드가 fallback으로 적용
        $context2 = [
            '_local' => [],
            'commentId' => 'comment_2',
        ];

        $result2 = $this->evaluator->evaluate(
            '{{_local.collapsedReplies?.[commentId]}}',
            $context2
        );
        $this->assertSame('false', $result2);
    }

    // ========================================================
    // 삼항 연산자 (Ternary Operator)
    // ========================================================

    /**
     * 삼항 연산자: 기본 true 분기를 평가합니다.
     */
    public function test_ternary_basic_true_branch(): void
    {
        $context = ['status' => 'active'];

        $result = $this->evaluator->evaluate("{{status === 'active' ? '활성' : '비활성'}}", $context);

        $this->assertSame('활성', $result);
    }

    /**
     * 삼항 연산자: 기본 false 분기를 평가합니다.
     */
    public function test_ternary_basic_false_branch(): void
    {
        $context = ['status' => 'inactive'];

        $result = $this->evaluator->evaluate("{{status === 'active' ? '활성' : '비활성'}}", $context);

        $this->assertSame('비활성', $result);
    }

    /**
     * 삼항 연산자: truthy/falsy 값 기반 평가입니다.
     */
    public function test_ternary_truthy_falsy(): void
    {
        $context = ['count' => 5];

        $result = $this->evaluator->evaluate("{{count ? '있음' : '없음'}}", $context);

        $this->assertSame('있음', $result);
    }

    /**
     * 삼항 연산자: falsy 값(빈 문자열)은 false 분기입니다.
     */
    public function test_ternary_falsy_empty_string(): void
    {
        $context = ['name' => ''];

        $result = $this->evaluator->evaluate("{{name ? name : '이름 없음'}}", $context);

        $this->assertSame('이름 없음', $result);
    }

    /**
     * 삼항 연산자: optional chaining과 함께 사용합니다.
     */
    public function test_ternary_with_optional_chaining(): void
    {
        $context = ['user' => ['profile' => ['name' => '홍길동']]];

        $result = $this->evaluator->evaluate("{{user?.profile?.name ? user.profile.name : '게스트'}}", $context);

        $this->assertSame('홍길동', $result);
    }

    /**
     * 삼항 연산자: optional chaining 값이 없을 때 false 분기입니다.
     */
    public function test_ternary_with_optional_chaining_missing(): void
    {
        $context = ['user' => []];

        $result = $this->evaluator->evaluate("{{user?.profile?.name ? user.profile.name : '게스트'}}", $context);

        $this->assertSame('게스트', $result);
    }

    /**
     * 삼항 연산자: 중첩 삼항 (우측 결합)을 평가합니다.
     */
    public function test_ternary_nested_right_associative(): void
    {
        $context = ['status' => 'pending'];

        $result = $this->evaluator->evaluate(
            "{{status === 'active' ? '활성' : status === 'pending' ? '대기' : '비활성'}}",
            $context
        );

        $this->assertSame('대기', $result);
    }

    /**
     * 삼항 연산자: 중첩 삼항에서 첫 번째 조건 매칭입니다.
     */
    public function test_ternary_nested_first_match(): void
    {
        $context = ['status' => 'active'];

        $result = $this->evaluator->evaluate(
            "{{status === 'active' ? '활성' : status === 'pending' ? '대기' : '비활성'}}",
            $context
        );

        $this->assertSame('활성', $result);
    }

    /**
     * 삼항 연산자: 숫자 비교를 조건으로 사용합니다.
     */
    public function test_ternary_with_number_comparison(): void
    {
        $context = ['cart' => ['count' => 150]];

        $result = $this->evaluator->evaluate("{{cart.count > 99 ? '99+' : cart.count}}", $context);

        $this->assertSame('99+', $result);
    }

    /**
     * 삼항 연산자: 숫자 비교 false 분기입니다.
     */
    public function test_ternary_number_comparison_false(): void
    {
        $context = ['cart' => ['count' => 5]];

        $result = $this->evaluator->evaluate("{{cart.count > 99 ? '99+' : cart.count}}", $context);

        $this->assertSame('5', $result);
    }

    /**
     * 삼항 연산자: 논리 AND 조건과 함께 사용합니다.
     */
    public function test_ternary_with_logical_and(): void
    {
        $context = ['isLoggedIn' => true, 'isAdmin' => true];

        $result = $this->evaluator->evaluate("{{isLoggedIn && isAdmin ? '관리자' : '일반'}}", $context);

        $this->assertSame('관리자', $result);
    }

    /**
     * 삼항 연산자: 부정 연산자를 조건으로 사용합니다.
     */
    public function test_ternary_with_negation(): void
    {
        $context = ['isLoggedIn' => false];

        $result = $this->evaluator->evaluate("{{!isLoggedIn ? '로그인' : '마이페이지'}}", $context);

        $this->assertSame('로그인', $result);
    }

    /**
     * 삼항 연산자: evaluateRaw에서도 원본 값을 반환합니다.
     */
    public function test_ternary_raw_returns_original_value(): void
    {
        $context = ['items' => [1, 2, 3]];

        $result = $this->evaluator->evaluateRaw('{{items ? items : []}}', $context);

        $this->assertSame([1, 2, 3], $result);
    }

    /**
     * 삼항 연산자: evaluateRaw에서 false 분기 원본 값을 반환합니다.
     */
    public function test_ternary_raw_false_branch(): void
    {
        $context = ['items' => null];

        $result = $this->evaluator->evaluateRaw('{{items ? items : []}}', $context);

        $this->assertSame([], $result);
    }

    // ========================================================
    // $t() 함수 호출 구문
    // ========================================================

    /**
     * $t() 함수: 문자열 키로 번역을 반환합니다.
     */
    public function test_t_function_basic(): void
    {
        $this->evaluator->setTranslations([
            'shop' => ['product' => ['sold_out' => '품절']],
        ]);

        $result = $this->evaluator->evaluate("{{\$t('shop.product.sold_out')}}", []);

        $this->assertSame('품절', $result);
    }

    /**
     * $t() 함수: 삼항 연산자 내부에서 사용합니다.
     */
    public function test_t_function_inside_ternary(): void
    {
        $this->evaluator->setTranslations([
            'shop' => ['product' => [
                'in_stock' => '판매중',
                'sold_out' => '품절',
            ]],
        ]);
        $context = ['product' => ['stock' => 0]];

        $result = $this->evaluator->evaluate(
            "{{product.stock > 0 ? \$t('shop.product.in_stock') : \$t('shop.product.sold_out')}}",
            $context
        );

        $this->assertSame('품절', $result);
    }

    /**
     * $t() 함수: 존재하지 않는 번역 키는 빈 문자열입니다.
     */
    public function test_t_function_missing_key(): void
    {
        $this->evaluator->setTranslations([]);

        $result = $this->evaluator->evaluate("{{\$t('nonexistent.key')}}", []);

        $this->assertSame('', $result);
    }

    /**
     * $t() 함수: 큰따옴표 문자열 키를 지원합니다.
     */
    public function test_t_function_double_quotes(): void
    {
        $this->evaluator->setTranslations([
            'common' => ['save' => '저장'],
        ]);

        $result = $this->evaluator->evaluate('{{$t("common.save")}}', []);

        $this->assertSame('저장', $result);
    }

    // ========================================================
    // $localized() 함수
    // ========================================================

    /**
     * $localized(): 현재 로케일(ko)에 해당하는 값을 반환합니다.
     */
    public function test_localized_returns_current_locale(): void
    {
        app()->setLocale('ko');
        $context = ['product' => ['name' => ['ko' => '운동화', 'en' => 'Sneakers']]];

        $result = $this->evaluator->evaluate('{{$localized(product.name)}}', $context);

        $this->assertSame('운동화', $result);
    }

    /**
     * $localized(): 영어 로케일에서 영어 값을 반환합니다.
     */
    public function test_localized_returns_en_locale(): void
    {
        app()->setLocale('en');
        $context = ['product' => ['name' => ['ko' => '운동화', 'en' => 'Sneakers']]];

        $result = $this->evaluator->evaluate('{{$localized(product.name)}}', $context);

        $this->assertSame('Sneakers', $result);
    }

    /**
     * $localized(): 현재 로케일이 없으면 ko fallback합니다.
     */
    public function test_localized_fallback_to_ko(): void
    {
        app()->setLocale('ja');
        $context = ['product' => ['name' => ['ko' => '운동화', 'en' => 'Sneakers']]];

        $result = $this->evaluator->evaluate('{{$localized(product.name)}}', $context);

        $this->assertSame('운동화', $result);
    }

    /**
     * $localized(): 비배열 값은 그대로 반환합니다.
     */
    public function test_localized_non_array_passthrough(): void
    {
        $context = ['product' => ['name' => '단순 문자열']];

        $result = $this->evaluator->evaluate('{{$localized(product.name)}}', $context);

        $this->assertSame('단순 문자열', $result);
    }

    /**
     * $localized(): ko도 없으면 첫 번째 값을 반환합니다.
     */
    public function test_localized_fallback_to_first_value(): void
    {
        app()->setLocale('ja');
        $context = ['product' => ['name' => ['en' => 'Sneakers', 'zh' => '运动鞋']]];

        $result = $this->evaluator->evaluate('{{$localized(product.name)}}', $context);

        $this->assertSame('Sneakers', $result);
    }

    // =========================================================================
    // 객체 리터럴 파서 테스트
    // =========================================================================

    /**
     * 단순 객체 리터럴 {key: 'value'}를 파싱합니다.
     */
    public function test_object_literal_simple(): void
    {
        $context = [];
        $result = $this->evaluator->evaluateRaw("{{  {status: 'active', count: 3}  }}", $context);

        $this->assertIsArray($result);
        $this->assertEquals('active', $result['status']);
        $this->assertEquals(3, $result['count']);
    }

    /**
     * 객체 리터럴에서 boolean/null 값을 지원합니다.
     */
    public function test_object_literal_with_boolean_null(): void
    {
        $context = [];
        $result = $this->evaluator->evaluateRaw("{{  {enabled: true, disabled: false, empty: null}  }}", $context);

        $this->assertIsArray($result);
        $this->assertTrue($result['enabled']);
        $this->assertFalse($result['disabled']);
        $this->assertNull($result['empty']);
    }

    /**
     * 빈 객체 리터럴 {}을 파싱합니다.
     */
    public function test_object_literal_empty(): void
    {
        $context = [];
        $result = $this->evaluator->evaluateRaw('{{  {}  }}', $context);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * 객체 스프레드: {...obj, key: value}
     */
    public function test_object_spread(): void
    {
        $context = ['defaults' => ['color' => 'red', 'size' => 'lg']];
        $result = $this->evaluator->evaluateRaw("{{  {...defaults, size: 'sm'}  }}", $context);

        $this->assertIsArray($result);
        $this->assertEquals('red', $result['color']);
        $this->assertEquals('sm', $result['size']); // 후순위 키가 덮어씀
    }

    /**
     * 동적 키: {[expr]: value}
     */
    public function test_object_dynamic_key(): void
    {
        $context = ['item' => ['id' => 'abc123']];
        $result = $this->evaluator->evaluateRaw("{{  {[item.id]: 'found'}  }}", $context);

        $this->assertIsArray($result);
        $this->assertEquals('found', $result['abc123']);
    }

    /**
     * 객체 리터럴에서 값으로 경로 참조를 사용합니다.
     */
    public function test_object_literal_with_path_value(): void
    {
        $context = ['product' => ['name' => '에어맥스', 'price' => 129000]];
        $result = $this->evaluator->evaluateRaw('{{  {name: product.name, price: product.price}  }}', $context);

        $this->assertIsArray($result);
        $this->assertEquals('에어맥스', $result['name']);
        $this->assertEquals(129000, $result['price']);
    }

    /**
     * 중첩 객체 리터럴: {outer: {inner: 'value'}}
     */
    public function test_object_literal_nested(): void
    {
        $context = [];
        $result = $this->evaluator->evaluateRaw("{{  {outer: {inner: 'value'}}  }}", $context);

        $this->assertIsArray($result);
        $this->assertIsArray($result['outer']);
        $this->assertEquals('value', $result['outer']['inner']);
    }

    // =========================================================================
    // 배열 스프레드 테스트
    // =========================================================================

    /**
     * 배열 스프레드: [...arr, item]
     */
    public function test_array_spread(): void
    {
        $context = ['items' => ['a', 'b', 'c']];
        $result = $this->evaluator->evaluateRaw("{{  [...items, 'd']  }}", $context);

        $this->assertIsArray($result);
        $this->assertEquals(['a', 'b', 'c', 'd'], $result);
    }

    /**
     * 배열 스프레드에서 빈 배열을 스프레드합니다.
     */
    public function test_array_spread_empty_source(): void
    {
        $context = ['items' => []];
        $result = $this->evaluator->evaluateRaw("{{  [...items, 'new']  }}", $context);

        $this->assertIsArray($result);
        $this->assertEquals(['new'], $result);
    }

    /**
     * 복합 패턴: 객체 스프레드 + 동적 키 (reduce 콜백 시뮬레이션)
     * {...map, [c.id]: [...(map[c.id] ?? []), c]}
     */
    public function test_spread_with_dynamic_key_reduce_pattern(): void
    {
        $context = [
            'map' => ['g1' => [['id' => 'g1', 'text' => 'first']]],
            'c' => ['id' => 'g1', 'text' => 'second'],
        ];

        // {[c.id]: 'value'} 동적 키 기본 동작 확인
        $result = $this->evaluator->evaluateRaw("{{  {[c.id]: 'value'}  }}", $context);
        $this->assertIsArray($result);
        $this->assertEquals('value', $result['g1']);
    }

    /**
     * (subexpr).property 패턴: 괄호 내 하위 표현식 + 후속 프로퍼티 접근
     *
     * SEO 렌더링에서 (array ?? []).length 같은 JavaScript 패턴을 PHP로 해석합니다.
     *
     * @since engine-v1.17.6
     */
    public function test_parenthesized_subexpression_with_property_access_length(): void
    {
        $context = [
            'review' => [
                'images' => [
                    ['id' => 1, 'url' => 'https://example.com/img1.jpg'],
                    ['id' => 2, 'url' => 'https://example.com/img2.jpg'],
                ],
                'image_count' => 2,
            ],
        ];

        // (review.images ?? []).length → 2
        $result = $this->evaluator->evaluate('{{(review.images ?? []).length}}', $context);
        $this->assertEquals('2', $result);
    }

    public function test_parenthesized_subexpression_length_comparison(): void
    {
        $context = [
            'review' => [
                'images' => [
                    ['id' => 1, 'url' => 'https://example.com/img1.jpg'],
                ],
                'image_count' => 1,
            ],
        ];

        // (review.images ?? []).length > 0 → true
        $result = $this->evaluator->evaluate('{{(review.images ?? []).length > 0}}', $context);
        $this->assertEquals('true', $result);

        // (review.images ?? []).length > 1 → false
        $result = $this->evaluator->evaluate('{{(review.images ?? []).length > 1}}', $context);
        $this->assertEquals('false', $result);
    }

    public function test_parenthesized_subexpression_with_empty_fallback(): void
    {
        $context = [
            'review' => [
                'image_count' => 0,
            ],
        ];

        // review.images가 없을 때 (review.images ?? []).length → 0
        $result = $this->evaluator->evaluate('{{(review.images ?? []).length}}', $context);
        $this->assertEquals('0', $result);

        // (review.images ?? []).length > 0 → false
        $result = $this->evaluator->evaluate('{{(review.images ?? []).length > 0}}', $context);
        $this->assertEquals('false', $result);
    }

    public function test_parenthesized_subexpression_equality_comparison(): void
    {
        $context = [
            'items' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
                ['id' => 4],
            ],
        ];

        // (items ?? []).length == 4 → true
        $result = $this->evaluator->evaluate('{{(items ?? []).length == 4}}', $context);
        $this->assertEquals('true', $result);

        // (items ?? []).length >= 5 → false
        $result = $this->evaluator->evaluate('{{(items ?? []).length >= 5}}', $context);
        $this->assertEquals('false', $result);
    }

    // =========================================================================
    // 산술 연산 확장: *, /, % (Arithmetic Operations Extended)
    // =========================================================================

    /**
     * 곱셈: 경로 값 * 숫자 리터럴이 올바르게 계산됩니다.
     */
    public function test_arithmetic_multiplication(): void
    {
        $context = ['quantity' => 5];

        $result = $this->evaluator->evaluate('{{quantity * 3}}', $context);

        $this->assertSame('15', $result);
    }

    /**
     * 나눗셈: 경로 값 / 숫자 리터럴이 올바르게 계산됩니다.
     */
    public function test_arithmetic_division(): void
    {
        $context = ['total' => 100];

        $result = $this->evaluator->evaluate('{{total / 4}}', $context);

        $this->assertSame('25', $result);
    }

    /**
     * 나눗셈 소수점 결과가 올바르게 반환됩니다.
     */
    public function test_arithmetic_division_with_decimal_result(): void
    {
        $context = ['total' => 10];

        $result = $this->evaluator->evaluate('{{total / 3}}', $context);

        // PHP float 나눗셈 결과
        $this->assertSame((string) (10 / 3), $result);
    }

    /**
     * 0으로 나눗셈 시 0을 반환합니다.
     */
    public function test_arithmetic_division_by_zero(): void
    {
        $context = ['total' => 100];

        $result = $this->evaluator->evaluate('{{total / 0}}', $context);

        $this->assertSame('0', $result);
    }

    /**
     * 나머지 연산: 경로 값 % 숫자 리터럴이 올바르게 계산됩니다.
     */
    public function test_arithmetic_modulo(): void
    {
        $context = ['index' => 7];

        $result = $this->evaluator->evaluate('{{index % 3}}', $context);

        $this->assertSame('1', $result);
    }

    /**
     * 0으로 나머지 연산 시 0을 반환합니다.
     */
    public function test_arithmetic_modulo_by_zero(): void
    {
        $context = ['index' => 7];

        $result = $this->evaluator->evaluate('{{index % 0}}', $context);

        $this->assertSame('0', $result);
    }

    /**
     * 곱셈 + null coalescing 조합이 동작합니다.
     */
    public function test_arithmetic_multiplication_with_null_coalescing(): void
    {
        $context = ['item' => ['price' => 1500, 'quantity' => 3]];

        $result = $this->evaluator->evaluate('{{(item?.quantity ?? 1) * 2}}', $context);
        $this->assertSame('6', $result);
    }

    /**
     * 소수점 곱셈이 올바르게 동작합니다.
     */
    public function test_arithmetic_multiplication_decimal(): void
    {
        $context = ['price' => 100];

        $result = $this->evaluator->evaluate('{{price * 1.1}}', $context);
        $this->assertSame('110', $result);
    }

    // =========================================================================
    // 파이프 표현식 (Pipe Expressions)
    // =========================================================================

    /**
     * number 파이프가 evaluate에서 올바르게 동작합니다.
     */
    public function test_pipe_number_in_evaluate(): void
    {
        $context = ['price' => 1234567];

        $result = $this->evaluator->evaluate('{{price | number}}', $context);

        $this->assertSame('1,234,567', $result);
    }

    /**
     * number 파이프가 소수점 인자와 함께 동작합니다.
     */
    public function test_pipe_number_with_decimals(): void
    {
        $context = ['price' => 1234.5];

        $result = $this->evaluator->evaluate('{{price | number(2)}}', $context);

        $this->assertSame('1,234.50', $result);
    }

    /**
     * date 파이프가 evaluate에서 올바르게 동작합니다.
     */
    public function test_pipe_date_in_evaluate(): void
    {
        $context = ['post' => ['created_at' => '2024-01-15 14:30:00']];

        $result = $this->evaluator->evaluate('{{post.created_at | date}}', $context);

        $this->assertSame('2024-01-15', $result);
    }

    /**
     * datetime 파이프가 evaluate에서 올바르게 동작합니다.
     */
    public function test_pipe_datetime_in_evaluate(): void
    {
        $context = ['post' => ['created_at' => '2024-01-15 14:30:45']];

        $result = $this->evaluator->evaluate('{{post.created_at | datetime}}', $context);

        $this->assertSame('2024-01-15 14:30', $result);
    }

    /**
     * uppercase 파이프가 evaluate에서 올바르게 동작합니다.
     */
    public function test_pipe_uppercase_in_evaluate(): void
    {
        $context = ['name' => 'hello'];

        $result = $this->evaluator->evaluate('{{name | uppercase}}', $context);

        $this->assertSame('HELLO', $result);
    }

    /**
     * lowercase 파이프가 evaluate에서 올바르게 동작합니다.
     */
    public function test_pipe_lowercase_in_evaluate(): void
    {
        $context = ['name' => 'HELLO'];

        $result = $this->evaluator->evaluate('{{name | lowercase}}', $context);

        $this->assertSame('hello', $result);
    }

    /**
     * truncate 파이프가 evaluate에서 올바르게 동작합니다.
     */
    public function test_pipe_truncate_in_evaluate(): void
    {
        $context = ['description' => '이것은 매우 긴 설명 텍스트입니다. 잘라야 합니다.'];

        $result = $this->evaluator->evaluate('{{description | truncate(10)}}', $context);

        $this->assertSame('이것은 매우 긴 설...', $result);
    }

    /**
     * stripHtml 파이프가 evaluate에서 HTML 태그를 제거합니다.
     */
    public function test_pipe_strip_html_in_evaluate(): void
    {
        $context = ['content' => '<p>안녕 <b>세계</b></p>'];

        $result = $this->evaluator->evaluate('{{content | stripHtml}}', $context);

        $this->assertSame('안녕 세계', $result);
    }

    /**
     * 다중 파이프 체인이 올바르게 동작합니다.
     */
    public function test_pipe_chain(): void
    {
        $context = ['content' => '<p>Hello World</p>'];

        $result = $this->evaluator->evaluate('{{content | stripHtml | uppercase}}', $context);

        $this->assertSame('HELLO WORLD', $result);
    }

    /**
     * 파이프와 null coalescing 조합이 동작합니다.
     */
    public function test_pipe_with_null_coalescing_in_base_expr(): void
    {
        $context = ['item' => ['name' => '테스트 상품']];

        $result = $this->evaluator->evaluate('{{item.name ?? "없음" | uppercase}}', $context);

        $this->assertSame('테스트 상품', $result);
    }

    /**
     * 파이프가 없는 기존 || 연산자는 영향받지 않습니다.
     */
    public function test_pipe_does_not_affect_or_operator(): void
    {
        $context = ['a' => '', 'b' => 'hello'];

        $result = $this->evaluator->evaluate('{{a || b}}', $context);

        $this->assertSame('true', $result);
    }

    /**
     * join 파이프가 배열을 문자열로 결합합니다.
     */
    public function test_pipe_join_in_evaluate(): void
    {
        $context = ['tags' => ['태그1', '태그2', '태그3']];

        $result = $this->evaluator->evaluate('{{tags | join}}', $context);

        $this->assertSame('태그1, 태그2, 태그3', $result);
    }

    /**
     * default 파이프가 null에 기본값을 적용합니다.
     */
    public function test_pipe_default_in_evaluate(): void
    {
        $context = ['user' => ['name' => null]];

        $result = $this->evaluator->evaluate("{{user.name | default('미지정')}}", $context);

        $this->assertSame('미지정', $result);
    }

    /**
     * evaluateRaw에서 파이프가 원본 타입을 유지합니다.
     */
    public function test_pipe_in_evaluate_raw(): void
    {
        $context = ['items' => ['a', 'b', 'c']];

        $result = $this->evaluator->evaluateRaw('{{items | length}}', $context);

        $this->assertSame(3, $result);
    }

    /**
     * evaluateRaw에서 다중 파이프 체인이 동작합니다.
     */
    public function test_pipe_chain_in_evaluate_raw(): void
    {
        $context = ['data' => ['a' => 1, 'b' => 2, 'c' => 3]];

        $result = $this->evaluator->evaluateRaw('{{data | keys}}', $context);

        $this->assertSame(['a', 'b', 'c'], $result);
    }

    /**
     * localized 파이프가 다국어 객체에서 값을 추출합니다.
     */
    public function test_pipe_localized_in_evaluate(): void
    {
        $context = ['product' => ['name' => ['ko' => '상품명', 'en' => 'Product']]];

        $result = $this->evaluator->evaluate('{{product.name | localized}}', $context);

        $this->assertSame('상품명', $result);
    }

    /**
     * 미등록 파이프는 원본 값 그대로 출력합니다.
     */
    public function test_unknown_pipe_passes_through(): void
    {
        $context = ['name' => 'hello'];

        // 미등록 파이프는 무시되어 원본 값 반환
        $result = $this->evaluator->evaluate('{{name | nonexistent}}', $context);

        $this->assertSame('hello', $result);
    }

    /**
     * 복합 텍스트 내 파이프가 올바르게 동작합니다.
     */
    public function test_pipe_in_mixed_text(): void
    {
        $context = ['price' => 15000];

        $result = $this->evaluator->evaluate('가격: {{price | number}}원', $context);

        $this->assertSame('가격: 15,000원', $result);
    }
}
