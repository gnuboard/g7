<?php

namespace Tests\Unit\Seo;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Seo\ComponentHtmlMapper;
use App\Seo\DataSourceResolver;
use App\Seo\ExpressionEvaluator;
use App\Seo\SeoConfigMerger;
use App\Seo\SeoMetaResolver;
use App\Seo\SeoRenderer;
use App\Seo\TemplateRouteResolver;
use App\Services\LayoutService;
use App\Services\PluginSettingsService;
use App\Services\SettingsService;
use App\Services\TemplateService;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 봇 화면의 `_global.activeModules` / `_global.activePlugins` 와 레이아웃 가드 식 평가 (#822, engine-v1.84.0)
 *
 * 사용자 화면(브라우저)은 G7Config.activeModules·activePlugins 에서 식별자를 뽑아 `_global` 에 싣고, 템플릿은
 * `{{_global.activeModules?.includes('id') ?? true}}` 한 모양으로 「모듈이 켜져 있나」를 판정한다. 봇 화면도 같은
 * 키를 싣고 같은 식을 브라우저와 같게 평가해야 꺼진 모듈의 링크·데이터소스 호출이 빠진다.
 *
 * 브라우저 쪽 같은 식의 잠금: resources/js/core/__tests__/TemplateApp.activeExtensions.test.ts
 */
class SeoGlobalActiveExtensionsTest extends TestCase
{
    /** 가드 식(D2) — 번들 레이아웃과 같은 글자 */
    private const BOARD_GUARD = "{{_global.activeModules?.includes('sirsoft-board') ?? true}}";

    private const BOARD_GUARD_NEGATED = "{{!(_global.activeModules?.includes('sirsoft-board') ?? true)}}";

    private const POSTCODE_GUARD = "{{_global.activePlugins?.includes('sirsoft-daum_postcode') ?? true}}";

    /**
     * 이름 붙은 `_global` 문맥 — 켜짐 / 꺼짐(다른 모듈만 켜짐) / 빈 목록 / 키 없음(옛 코어).
     *
     * @return array<string, array<string, mixed>> 문맥 이름 → `_global`
     */
    private static function globals(): array
    {
        return [
            'on' => ['activeModules' => ['sirsoft-board', 'sirsoft-page'], 'activePlugins' => ['sirsoft-daum_postcode']],
            'off' => ['activeModules' => ['sirsoft-ecommerce'], 'activePlugins' => ['sirsoft-marketing']],
            'empty' => ['activeModules' => [], 'activePlugins' => []],
            'nokey' => [],
        ];
    }

    /**
     * 식 × 문맥 → 브라우저(JS)와 같은 기대값.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}> 케이스 이름 → [가드 식, 문맥 이름, 기대값]
     */
    public static function guardCases(): array
    {
        $expected = [
            self::BOARD_GUARD => ['on' => true, 'off' => false, 'empty' => false, 'nokey' => true],
            self::BOARD_GUARD_NEGATED => ['on' => false, 'off' => true, 'empty' => true, 'nokey' => false],
            self::POSTCODE_GUARD => ['on' => true, 'off' => false, 'empty' => false, 'nokey' => true],
        ];

        $cases = [];
        foreach ($expected as $expression => $byContext) {
            foreach ($byContext as $context => $value) {
                $cases["{$expression} @ {$context}"] = [$expression, $context, $value];
            }
        }

        return $cases;
    }

    /**
     * 노드·데이터소스 `if` 판정(ComponentHtmlMapper::isRenderable)과 문자열 평가가 브라우저와 같다.
     *
     * 종전에는 `?.includes(…)` 를 풀지 못해 null 이 됐고 `?? true` 로 늘 참이었다 — 꺼진 모듈의 링크가 봇 화면에 남았다.
     *
     * @scenario case=bot_guard_expression
     *
     * @effects bot_guard_expression_matches_browser
     *
     * @param  string  $expression  가드 식
     * @param  string  $context  문맥 이름
     * @param  bool  $expected  기대값
     * @return void 반환값 없음
     */
    #[DataProvider('guardCases')]
    public function test_guard_expression_matches_browser(string $expression, string $context, bool $expected): void
    {
        $evaluator = new ExpressionEvaluator;
        $data = ['_global' => self::globals()[$context]];

        $this->assertSame($expected ? 'true' : 'false', $evaluator->evaluate($expression, $data));
        $this->assertSame($expected, (new ComponentHtmlMapper)->isRenderable(['if' => $expression], $data, $evaluator));
    }

    /**
     * 템플릿이 쓰는 묶음 식(여럿 중 하나 켜짐·삼항 클래스·질의와 AND)도 브라우저와 같다.
     *
     * @scenario case=bot_compound_expression
     *
     * @effects bot_guard_expression_matches_browser
     *
     * @return void 반환값 없음
     */
    public function test_compound_guard_expressions_match_browser(): void
    {
        $evaluator = new ExpressionEvaluator;
        $board = "(_global.activeModules?.includes('sirsoft-board') ?? true)";
        $shop = "(_global.activeModules?.includes('sirsoft-ecommerce') ?? true)";
        $page = "(_global.activeModules?.includes('sirsoft-page') ?? true)";
        $at = fn (?array $modules = null, array $query = []): array => ['_global' => $modules === null ? [] : ['activeModules' => $modules], 'query' => $query];

        // 검색칸 — 셋 중 하나라도 켜지면 참
        $anyOfThree = "{{{$board} || {$shop} || {$page}}}";
        $this->assertSame('false', $evaluator->evaluate($anyOfThree, $at([])));
        $this->assertSame('true', $evaluator->evaluate($anyOfThree, $at(['sirsoft-page'])));
        $this->assertSame('true', $evaluator->evaluate($anyOfThree, $at()));

        // 삼항 클래스
        $grid = "{{{$board} ? 'cols-3' : 'cols-1'}}";
        $this->assertSame('cols-3', $evaluator->evaluate($grid, $at(['sirsoft-board'])));
        $this->assertSame('cols-1', $evaluator->evaluate($grid, $at(['sirsoft-ecommerce'])));
        $this->assertSame('cols-3', $evaluator->evaluate($grid, $at()));

        // 질의와 AND
        $guestCheckout = "{{query.redirect && query.redirect.includes('checkout') && {$shop}}}";
        $mapper = new ComponentHtmlMapper;
        $this->assertTrue($mapper->isRenderable(['if' => $guestCheckout], $at(['sirsoft-ecommerce'], ['redirect' => '/checkout']), $evaluator));
        $this->assertFalse($mapper->isRenderable(['if' => $guestCheckout], $at(['sirsoft-board'], ['redirect' => '/checkout']), $evaluator));
        $this->assertFalse($mapper->isRenderable(['if' => $guestCheckout], $at(['sirsoft-ecommerce'], ['redirect' => '/x']), $evaluator));
        $this->assertTrue($mapper->isRenderable(['if' => $guestCheckout], $at(null, ['redirect' => '/checkout']), $evaluator));
    }

    /**
     * props 자리(evaluateRaw)에서도 참/거짓 값으로 풀린다 — 헤더·푸터 props(`boardEnabled` 등)가 이 경로다.
     *
     * 종전에는 `includes(…)` 뒤의 ` ?? true` 를 프로퍼티 이름으로 읽어 켜진 모듈에서도 null 이 됐다.
     *
     * @scenario case=bot_props_raw
     *
     * @effects bot_guard_expression_matches_browser
     *
     * @return void 반환값 없음
     */
    public function test_guard_expression_raw_value_is_boolean(): void
    {
        $evaluator = new ExpressionEvaluator;

        $this->assertTrue($evaluator->evaluateRaw(self::BOARD_GUARD, ['_global' => self::globals()['on']]));
        $this->assertFalse($evaluator->evaluateRaw(self::BOARD_GUARD, ['_global' => self::globals()['off']]));
        $this->assertFalse($evaluator->evaluateRaw(self::BOARD_GUARD, ['_global' => self::globals()['empty']]));
        $this->assertTrue($evaluator->evaluateRaw(self::BOARD_GUARD, ['_global' => self::globals()['nokey']]));
    }

    /**
     * 옵셔널 체이닝 메서드 호출이 목록 값을 그대로 돌려준다 (`?? true` 없는 식).
     *
     * @scenario case=bot_guard_expression
     *
     * @effects bot_guard_expression_matches_browser
     *
     * @return void 반환값 없음
     */
    public function test_optional_chained_method_call_resolves(): void
    {
        $evaluator = new ExpressionEvaluator;
        $data = ['_global' => ['activeModules' => ['sirsoft-board']]];

        $this->assertSame('true', $evaluator->evaluate("{{_global.activeModules?.includes('sirsoft-board')}}", $data));
        $this->assertSame('false', $evaluator->evaluate("{{_global.activeModules?.includes('sirsoft-page')}}", $data));
        $this->assertSame('', $evaluator->evaluate("{{_global.missing?.includes('sirsoft-board')}}", $data));
        $this->assertSame('a, b', $evaluator->evaluate("{{_global.list?.join(', ')}}", ['_global' => ['list' => ['a', 'b']]]));
    }

    /**
     * seo-config 의 fields 모드 `if: "{prop}"` 는 props 값이 false 면 그 항목을 뺀다 — D2 식으로 묶은 props 포함.
     * 값과 글자가 섞인 attrs(`{shopBase}/products`)도 치환한다.
     *
     * @scenario case=bot_seo_config_fields
     *
     * @effects bot_header_footer_follow_active_modules
     *
     * @return void 반환값 없음
     */
    public function test_seo_config_field_if_hides_disabled_module_links(): void
    {
        $mapper = new ComponentHtmlMapper;
        $mapper->setComponentMap(['Header' => ['tag' => 'header', 'render' => 'header_nav']]);
        $mapper->setRenderModes(['header_nav' => [
            'type' => 'fields',
            'source' => '$all_props',
            'fields' => [
                ['tag' => 'a', 'attrs' => ['href' => '/'], 'content' => '{siteName}'],
                ['tag' => 'nav', 'children' => [
                    ['tag' => 'a', 'attrs' => ['href' => '/boards/popular'], 'content' => 'POPULAR', 'if' => '{boardEnabled|siteName}'],
                    ['tag' => 'a', 'attrs' => ['href' => '{shopBase}/products'], 'content' => 'SHOP', 'if' => '{shopEnabled|siteName}'],
                ]],
            ],
        ]]);

        $props = [
            'siteName' => 'Site',
            'boardEnabled' => self::BOARD_GUARD,
            'shopEnabled' => "{{_global.activeModules?.includes('sirsoft-ecommerce') ?? true}}",
            'shopBase' => "{{_global.shopBase ?? '/shop'}}",
        ];
        $render = fn (array $global): string => $mapper->render([['name' => 'Header', 'props' => $props]], ['_global' => $global], new ExpressionEvaluator);

        // 게시판만 켜짐 → 인기글만
        $boardOnly = $render(['activeModules' => ['sirsoft-board']]);
        $this->assertStringContainsString('<a href="/boards/popular">POPULAR</a>', $boardOnly);
        $this->assertStringNotContainsString('SHOP', $boardOnly);

        // 쇼핑몰만 켜짐 → 상품 링크만, 접두 경로 치환
        $shopOnly = $render(['activeModules' => ['sirsoft-ecommerce'], 'shopBase' => '/mall']);
        $this->assertStringContainsString('<a href="/mall/products">SHOP</a>', $shopOnly);
        $this->assertStringNotContainsString('POPULAR', $shopOnly);

        // 옛 코어(키 없음) → 둘 다
        $legacy = $render([]);
        $this->assertStringContainsString('POPULAR', $legacy);
        $this->assertStringContainsString('<a href="/shop/products">SHOP</a>', $legacy);

        // props 에 boolean false 를 직접 넘겨도 뺀다 — 대체 필드(siteName)로 넘어가지 않는다
        $literal = $mapper->render([['name' => 'Header', 'props' => ['siteName' => 'S', 'boardEnabled' => false, 'shopEnabled' => true, 'shopBase' => '/shop']]], [], new ExpressionEvaluator);
        $this->assertStringNotContainsString('POPULAR', $literal);
        $this->assertStringContainsString('SHOP', $literal);

        // 새 props 가 아예 없는 옛 레이아웃(keep 된 _user_base) → siteName 으로 넘어가 보인다(D13)
        $keptLayout = $mapper->render([['name' => 'Header', 'props' => ['siteName' => 'S', 'shopBase' => '/shop']]], [], new ExpressionEvaluator);
        $this->assertStringContainsString('POPULAR', $keptLayout);
        $this->assertStringContainsString('<a href="/shop/products">SHOP</a>', $keptLayout);
    }

    /**
     * 실제 번들 sirsoft-basic 의 `_user_base` init `shopBase` 식이 브라우저와 같은 경로를 낸다.
     *
     * 종전에는 `'/' + (…)` 문자열 잇기를 풀지 못해 빈 값이 됐고, 머리글 props `{{_global.shopBase ?? '/shop'}}` 가
     * 늘 '/shop' 이라 상점 경로를 바꾼 사이트의 봇 화면 「쇼핑」 링크가 `/shop/products` 로 남았다.
     *
     * @scenario case=bot_shop_base
     *
     * @effects bot_shop_base_matches_browser
     *
     * @return void 반환값 없음
     */
    public function test_bundle_shop_base_expression_matches_browser(): void
    {
        $expression = $this->bundleShopBaseExpression();
        $this->assertSame(
            "{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : ('/' + (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop'))}}",
            $expression,
            '번들 식이 바뀌면 이 시험의 기대값도 다시 맞춘다',
        );

        $evaluator = new ExpressionEvaluator;
        $settings = fn (?array $basicInfo): array => ['_global' => $basicInfo === null ? [] : ['modules' => ['sirsoft-ecommerce' => ['basic_info' => $basicInfo]]]];

        foreach ([
            'no_settings' => [null, '/shop'],
            'custom_path' => [['route_path' => 'mall', 'no_route' => false], '/mall'],
            'root' => [['route_path' => 'mall', 'no_route' => true], ''],
        ] as $case => [$basicInfo, $expected]) {
            // init_actions setState 는 evaluate(), props 는 evaluateRaw() 경로
            $this->assertSame($expected, $evaluator->evaluate($expression, $settings($basicInfo)), "evaluate {$case}");
            $this->assertSame($expected, $evaluator->evaluateRaw($expression, $settings($basicInfo)), "evaluateRaw {$case}");
        }

        // 머리글 props 의 `?? '/shop'` 은 루트 배치의 빈 문자열을 값으로 둔다(JS `??` 와 같다)
        $this->assertSame('', $evaluator->evaluateRaw("{{_global.shopBase ?? '/shop'}}", ['_global' => ['shopBase' => '']]));
        $this->assertSame('/shop', $evaluator->evaluateRaw("{{_global.shopBase ?? '/shop'}}", ['_global' => []]));
    }

    /**
     * 삼항의 참 갈래가 빈 문자열이면 빈 문자열을 돌려준다 — 거짓 갈래의 메서드 호출로 넘어가지 않는다.
     *
     * @scenario case=bot_shop_base
     *
     * @effects bot_shop_base_matches_browser
     *
     * @return void 반환값 없음
     */
    public function test_raw_ternary_empty_branch_is_not_swallowed_by_method_call(): void
    {
        $evaluator = new ExpressionEvaluator;
        $expression = "{{_global.root ? '' : '/'.concat(_global.path ?? 'shop')}}";

        $this->assertSame('', $evaluator->evaluateRaw($expression, ['_global' => ['root' => true, 'path' => 'mall']]));
        $this->assertSame('/mall', $evaluator->evaluateRaw($expression, ['_global' => ['root' => false, 'path' => 'mall']]));
        $this->assertSame('/shop', $evaluator->evaluateRaw($expression, ['_global' => []]));
    }

    /**
     * 더하기·문자열 잇기가 JS `+` 와 같다.
     *
     * @scenario case=bot_plus_operator
     *
     * @effects bot_shop_base_matches_browser
     *
     * @return void 반환값 없음
     */
    public function test_plus_operator_matches_js(): void
    {
        $evaluator = new ExpressionEvaluator;
        $data = ['_global' => ['p' => 'mall', 'n' => 3, 'list' => [1, 2]]];

        $this->assertSame('/shop', $evaluator->evaluate("{{'/' + 'shop'}}", $data));
        $this->assertSame('/shop', $evaluator->evaluateRaw("{{'/' + 'shop'}}", $data));
        $this->assertSame('mall/x', $evaluator->evaluate("{{_global.p + '/x'}}", $data));
        $this->assertSame(4, $evaluator->evaluateRaw('{{_global.n + 1}}', $data));
        $this->assertSame('4x', $evaluator->evaluate("{{_global.n + 1 + 'x'}}", $data));
        $this->assertSame('a1', $evaluator->evaluate("{{'a' + 1}}", $data));
        $this->assertSame('1,2!', $evaluator->evaluate("{{_global.list + '!'}}", $data));
        $this->assertSame('xundefined', $evaluator->evaluate("{{'x' + _global.missing}}", $data));
        // 따옴표 안의 + 는 연산자가 아니다
        $this->assertSame('a+b', $evaluator->evaluate("{{'a+b'}}", $data));

        // 곱셈·나눗셈·나머지·뺄셈이 섞이면 JS 우선순위대로 먼저 계산한다 (a=3)
        $numbers = ['a' => 3, 'b' => 10];
        $this->assertSame('7', $evaluator->evaluate('{{a * 2 + 1}}', $numbers));
        $this->assertSame('7', $evaluator->evaluate('{{1 + a * 2}}', $numbers));
        $this->assertSame('6', $evaluator->evaluate('{{b / 2 + 1}}', $numbers));
        $this->assertSame('3', $evaluator->evaluate('{{b % 3 + 2}}', $numbers));
        $this->assertSame('4', $evaluator->evaluate('{{b - 7 + 1}}', $numbers));
        $this->assertSame(7, $evaluator->evaluateRaw('{{a * 2 + 1}}', $numbers));
        $this->assertSame('x6', $evaluator->evaluate("{{'x' + a * 2}}", $numbers));

        // 피연산자 안의 논리·산술(경로끼리)도 먼저 푼다 — 종전 회귀: 'aundefined'·'1'·'pundefined'
        $mixed = ['n' => 3, 'm' => 4, 'x' => null, 'query' => ['preset' => null]];
        $this->assertSame('ab', $evaluator->evaluate("{{'a' + (x || 'b')}}", $mixed));
        $this->assertSame('13', $evaluator->evaluate('{{n * m + 1}}', $mixed));
        $this->assertSame('p12', $evaluator->evaluate("{{'p' + n * m}}", $mixed));
        $this->assertSame('12', $evaluator->evaluate('{{n * m}}', $mixed));
        $this->assertSame('&preset=7d', $evaluator->evaluate("{{'&preset=' + (query.preset || '7d')}}", $mixed));
        // Math.min 에 수 하나만 들어와도 오류 없이 그 수
        $this->assertSame('width:30%', $evaluator->evaluate("{{'width:' + Math.min(100, n * 10) + '%'}}", $mixed));
        // filter(Boolean) 는 빈 값을 뺀다
        $this->assertSame('', $evaluator->evaluate("{{(query.exclude || '').split(',').filter(Boolean).map(e => '&exclude[]=' + e).join('')}}", $mixed));
        // 이 평가기가 모르는 문법(즉시 실행 함수)이 피연산자면 잇지 않고 종전처럼 빈 값 — `width: undefined%` 를 내지 않는다
        $this->assertSame('', $evaluator->evaluate("{{'width: ' + (function(p) { return p; })(1) + '%'}}", $mixed));
        // 없는 경로는 JS 처럼 undefined 로 잇고, 없는 값을 더한 수는 NaN
        $this->assertSame('Replica #NaN', $evaluator->evaluate('Replica #{{replicaIndex + 1}}', $mixed));
    }

    /**
     * `??` 의 빈 문자열 처리가 문자열 경로(evaluate)와 값 경로(evaluateRaw)에서 같다 — JS 와 같이 빈 문자열은 값.
     *
     * @scenario case=bot_shop_base
     *
     * @effects bot_shop_base_matches_browser
     *
     * @return void 반환값 없음
     */
    public function test_null_coalescing_empty_string_same_in_both_paths(): void
    {
        $evaluator = new ExpressionEvaluator;

        $this->assertSame('', $evaluator->evaluate("{{s ?? 'def'}}", ['s' => '']));
        $this->assertSame('', $evaluator->evaluateRaw("{{s ?? 'def'}}", ['s' => '']));
        $this->assertSame('def', $evaluator->evaluate("{{s ?? 'def'}}", []));
        $this->assertSame('def', $evaluator->evaluateRaw("{{s ?? 'def'}}", []));
    }

    /**
     * 문자열 경로(evaluate)의 `??` 도 JS 와 같이 null(없는 값)일 때만 오른쪽으로 넘어간다 — 빈 문자열은 값이다.
     *
     * 번들 sirsoft-basic 의 상점 링크(마이페이지 문의 목록) `{{_global.shopBase ?? '/shop'}}/products` 는 상점을 루트에 붙인 사이트(shopBase = '')
     * 에서 `/products` 여야 한다. 종전에는 `/shop/products` 였다.
     *
     * @scenario case=bot_shop_base
     *
     * @effects bot_shop_base_matches_browser
     *
     * @return void 반환값 없음
     */
    public function test_string_path_null_coalescing_keeps_empty_string(): void
    {
        $evaluator = new ExpressionEvaluator;
        $href = "{{_global.shopBase ?? '/shop'}}/products";
        $this->assertStringContainsString($href, (string) file_get_contents(base_path('templates/_bundled/sirsoft-basic/layouts/partials/mypage/inquiries/_list.json')), '번들 식이 바뀌면 이 시험도 맞춘다');

        $this->assertSame('/products', $evaluator->evaluate($href, ['_global' => ['shopBase' => '']]));
        $this->assertSame('/mall/products', $evaluator->evaluate($href, ['_global' => ['shopBase' => '/mall']]));
        $this->assertSame('/shop/products', $evaluator->evaluate($href, ['_global' => []]));
        $this->assertSame('/shop/products', $evaluator->evaluate($href, ['_global' => ['shopBase' => null]]));

        // false·0 도 값이다 (JS `??`)
        $this->assertSame('false', $evaluator->evaluate("{{_global.flag ?? 'x'}}", ['_global' => ['flag' => false]]));
        $this->assertSame('0', $evaluator->evaluate("{{_global.count ?? 'x'}}", ['_global' => ['count' => 0]]));
    }

    /**
     * 실제 번들 seo-config(머리글·꼬리말)와 `_user_base` 의 Header·Footer 노드 그대로 봇 화면을 그린다.
     *
     * `"if": "{boardEnabled|siteName}"` — 값이 false 면 숨기고, props 가 없으면(옛 레이아웃) siteName 으로 넘어가 보인다.
     *
     * @scenario case=bot_bundle_header_footer
     *
     * @effects bot_header_footer_follow_active_modules
     *
     * @return void 반환값 없음
     */
    public function test_bundle_header_footer_follow_active_modules(): void
    {
        $config = json_decode((string) file_get_contents(base_path('templates/_bundled/sirsoft-basic/seo-config.json')), true);
        $mapper = new ComponentHtmlMapper;
        $mapper->setComponentMap(array_intersect_key($config['component_map'], ['Header' => true, 'Footer' => true]));
        $mapper->setRenderModes($config['render_modes']);

        $nodes = $this->bundleNodes(['Header', 'Footer']);
        $render = function (array $global) use ($mapper, $nodes): string {
            $global += ['settings' => ['general' => ['site_name' => 'Site']]];

            return $mapper->render(array_values($nodes), ['_global' => $global], new ExpressionEvaluator);
        };

        // 게시판만 켜짐 — 인기글 있음, 쇼핑·정보정책 없음
        $boardOnly = $render(['activeModules' => ['sirsoft-board'], 'shopBase' => '/shop']);
        $this->assertStringContainsString('href="/boards/popular"', $boardOnly);
        $this->assertStringNotContainsString('/products"', $boardOnly);
        $this->assertStringNotContainsString('href="/page/', $boardOnly);

        // 쇼핑몰만 켜짐(상점 경로 /mall) — 쇼핑 링크만, 경로 치환
        $shopOnly = $render(['activeModules' => ['sirsoft-ecommerce'], 'shopBase' => '/mall']);
        $this->assertStringContainsString('href="/mall/products"', $shopOnly);
        $this->assertStringNotContainsString('href="/boards/popular"', $shopOnly);
        $this->assertStringNotContainsString('{shopBase}', $shopOnly);

        // 페이지만 켜짐 — 정보·정책 링크
        $pageOnly = $render(['activeModules' => ['sirsoft-page'], 'shopBase' => '/shop']);
        $this->assertStringContainsString('href="/page/terms"', $pageOnly);
        $this->assertStringNotContainsString('href="/boards/popular"', $pageOnly);

        // 옛 코어(키 없음) — 모두 보임
        $legacy = $render(['shopBase' => '/shop']);
        $this->assertStringContainsString('href="/boards/popular"', $legacy);
        $this->assertStringContainsString('href="/shop/products"', $legacy);
        $this->assertStringContainsString('href="/page/terms"', $legacy);
    }

    /**
     * 번들 `_user_base` init_actions 의 shopBase setState 식을 읽습니다.
     *
     * @return string 식 원문
     */
    private function bundleShopBaseExpression(): string
    {
        $layout = json_decode((string) file_get_contents(base_path('templates/_bundled/sirsoft-basic/layouts/_user_base.json')), true);
        foreach ($layout['init_actions'] ?? [] as $action) {
            if (isset($action['params']['shopBase'])) {
                return $action['params']['shopBase'];
            }
        }

        $this->fail('번들 _user_base 에 shopBase setState 가 없다');
    }

    /**
     * 번들 `_user_base` 에서 이름이 같은 노드를 처음 것 하나씩 찾습니다.
     *
     * @param  array<int, string>  $names  컴포넌트 이름
     * @return array<string, array<string, mixed>> 이름 → 노드(name·props)
     */
    private function bundleNodes(array $names): array
    {
        $layout = json_decode((string) file_get_contents(base_path('templates/_bundled/sirsoft-basic/layouts/_user_base.json')), true);
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found, $names): void {
            if (! is_array($node)) {
                return;
            }
            $name = $node['name'] ?? null;
            if (is_string($name) && in_array($name, $names, true) && ! isset($found[$name])) {
                $found[$name] = ['name' => $name, 'props' => $node['props'] ?? []];
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($layout['components'] ?? []);
        $this->assertSame($names, array_keys($found));

        return $found;
    }

    /**
     * 가드가 거짓인 데이터소스는 봇 렌더에서 부르지 않는다 — `_global` 이 데이터소스 `if` 평가 문맥에 들어간다.
     *
     * @scenario case=bot_data_source_guard
     *
     * @effects bot_skips_disabled_module_api
     *
     * @return void 반환값 없음
     */
    public function test_data_source_guard_skips_disabled_module_api(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $dataSources = [
            ['id' => 'boards', 'if' => self::BOARD_GUARD, 'endpoint' => '/api/modules/sirsoft-board/boards'],
            ['id' => 'settings', 'endpoint' => '/api/settings'],
        ];

        $off = (new DataSourceResolver)->resolve($dataSources, ['boards', 'settings'], [], 'ko', [], ['_global' => self::globals()['off']]);
        $this->assertSame(['settings'], array_keys($off));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/modules/sirsoft-board/'));

        $legacy = (new DataSourceResolver)->resolve($dataSources, ['boards', 'settings'], [], 'ko', [], ['_global' => self::globals()['nokey']]);
        $this->assertSame(['boards', 'settings'], array_keys($legacy));
    }

    /**
     * buildGlobalContext 가 활성 모듈·플러그인 식별자 목록을 싣는다.
     *
     * @scenario case=bot_global_context
     *
     * @effects bot_global_carries_active_extensions
     *
     * @return void 반환값 없음
     */
    public function test_build_global_context_includes_active_extension_identifiers(): void
    {
        $global = $this->globalContextFrom(
            fn ($mock) => $mock->shouldReceive('getActiveModules')->andReturn([
                'sirsoft-board' => $this->extension('sirsoft-board'),
                'sirsoft-page' => $this->extension('sirsoft-page'),
            ]),
            fn ($mock) => $mock->shouldReceive('getActivePlugins')->andReturn([
                'sirsoft-daum_postcode' => $this->extension('sirsoft-daum_postcode'),
            ]),
        );

        $this->assertSame(['sirsoft-board', 'sirsoft-page'], $global['activeModules']);
        $this->assertSame(['sirsoft-daum_postcode'], $global['activePlugins']);
    }

    /**
     * 켜진 확장이 없으면 빈 목록, 목록을 못 구하면 키를 싣지 않는다(가드는 「켜짐」으로 읽는다).
     *
     * @scenario case=bot_global_context
     *
     * @effects bot_global_carries_active_extensions
     *
     * @return void 반환값 없음
     */
    public function test_build_global_context_empty_and_failure(): void
    {
        $global = $this->globalContextFrom(
            fn ($mock) => $mock->shouldReceive('getActiveModules')->andReturn([]),
            fn ($mock) => $mock->shouldReceive('getActivePlugins')->andThrow(new \RuntimeException('boom')),
        );

        $this->assertSame([], $global['activeModules']);
        $this->assertArrayNotHasKey('activePlugins', $global);
    }

    /**
     * 식별자만 가진 확장 대역을 만듭니다.
     *
     * @param  string  $identifier  식별자
     * @return object 확장 대역
     */
    private function extension(string $identifier): object
    {
        return new class($identifier)
        {
            /**
             * 식별자를 받습니다.
             *
             * @param  string  $identifier  식별자
             */
            public function __construct(private string $identifier) {}

            /**
             * 식별자를 돌려줍니다.
             *
             * @return string 식별자
             */
            public function getIdentifier(): string
            {
                return $this->identifier;
            }
        };
    }

    /**
     * 모의 의존으로 SeoRenderer 를 만들고 buildGlobalContext() 결과를 돌려줍니다.
     *
     * @param  \Closure  $modules  모듈 관리자 모의 설정
     * @param  \Closure  $plugins  플러그인 관리자 모의 설정
     * @return array<string, mixed> `_global` 문맥
     */
    private function globalContextFrom(\Closure $modules, \Closure $plugins): array
    {
        $settingsService = Mockery::mock(SettingsService::class);
        $settingsService->shouldReceive('getFrontendSettings')->andReturn([]);
        $pluginSettingsService = Mockery::mock(PluginSettingsService::class);
        $pluginSettingsService->shouldReceive('getAllActiveSettings')->andReturn([]);
        $metaResolver = Mockery::mock(SeoMetaResolver::class);
        $metaResolver->shouldReceive('resolveLocalizedValue')->andReturnUsing(fn ($value) => is_array($value) ? (string) reset($value) : (string) $value);

        $moduleManager = Mockery::mock(ModuleManagerInterface::class);
        $modules($moduleManager);
        $pluginManager = Mockery::mock(PluginManagerInterface::class);
        $plugins($pluginManager);

        $renderer = new SeoRenderer(
            Mockery::mock(TemplateRouteResolver::class),
            Mockery::mock(LayoutService::class),
            Mockery::mock(TemplateService::class),
            Mockery::mock(DataSourceResolver::class),
            $metaResolver,
            Mockery::mock(ComponentHtmlMapper::class),
            Mockery::mock(ExpressionEvaluator::class),
            Mockery::mock(SeoConfigMerger::class),
            $settingsService,
            $pluginSettingsService,
            $moduleManager,
            $pluginManager,
        );

        return (new \ReflectionMethod($renderer, 'buildGlobalContext'))->invoke($renderer);
    }
}
