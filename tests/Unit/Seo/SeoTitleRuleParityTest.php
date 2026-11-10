<?php

namespace Tests\Unit\Seo;

use App\Seo\Concerns\SubstitutesSeoVariables;
use App\Seo\ExpressionEvaluator;
use App\Seo\SeoMetaResolver;
use App\Seo\SeoTitleRule;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 화면 제목 규칙 판정 사례 시험 (서버 쪽)
 *
 * `tests/fixtures/seo/title-rule-cases.json` 을 엔진 시험(`DocumentTitle.parity.test.ts`)과 함께 읽는다 —
 * 검색엔진 `<title>` 과 사람 브라우저 탭 제목이 같은 규칙으로 같은 답을 내는지 잠근다.
 */
class SeoTitleRuleParityTest extends TestCase
{
    /**
     * 판정 사례 파일을 읽습니다.
     *
     * @return array<string, mixed> 사례 묶음
     */
    private static function cases(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/seo/title-rule-cases.json'), true);
    }

    /**
     * 제목 규칙 사례를 공급합니다.
     *
     * @return array<string, array{0: array, 1: array}> 사례 이름 → [입력, 기대값]
     */
    public static function ruleCases(): array
    {
        $out = [];
        foreach (self::cases()['rule'] as $case) {
            $out[$case['name']] = [$case['input'], $case['expected']];
        }

        return $out;
    }

    /**
     * 틀 치환 사례를 공급합니다.
     *
     * @return array<string, array{0: string, 1: array, 2: string}> 사례 이름 → [틀, 변수, 기대값]
     */
    public static function substituteCases(): array
    {
        $out = [];
        foreach (self::cases()['substitute'] as $case) {
            $out[$case['name']] = [$case['template'], $case['vars'], $case['expected']];
        }

        return $out;
    }

    /**
     * 필수 변수 준비 판정 사례를 공급합니다.
     *
     * @return array<string, array{0: array, 1: array, 2: bool}> 사례 이름 → [변수 선언, 변수 값, 기대값]
     */
    public static function requiredVarsCases(): array
    {
        $out = [];
        foreach (self::cases()['required_vars'] as $case) {
            $out[$case['name']] = [$case['definitions'], $case['vars'], $case['expected']];
        }

        return $out;
    }

    /**
     * 확장 제목 틀의 필수(required) 변수가 비었는지 판정이 사례마다 기대값을 냅니다 — 비면 틀을 제목 후보로 쓰지 않습니다.
     *
     * @scenario case=required_vars_server
     *
     * @effects required_var_empty_template_skipped
     *
     * @param  array  $definitions  변수 선언
     * @param  array  $vars  변수 값
     * @param  bool  $expected  기대값
     * @return void 반환값 없음
     */
    #[DataProvider('requiredVarsCases')]
    public function test_missing_required_variable_matches_fixture(array $definitions, array $vars, bool $expected): void
    {
        $this->assertSame($expected, SeoTitleRule::hasMissingRequired($definitions, $vars));
    }

    /**
     * 제목 규칙이 사례마다 기대한 제목·접미사·전체 제목을 냅니다.
     *
     * @scenario case=rule_fixture_server
     *
     * @effects server_title_rule_matches_fixture
     *
     * @param  array  $input  규칙 입력
     * @param  array  $expected  기대값
     * @return void 반환값 없음
     */
    #[DataProvider('ruleCases')]
    public function test_title_rule_matches_fixture(array $input, array $expected): void
    {
        if (isset($input['site_locale'])) {
            Config::set('app.site_locale', $input['site_locale']);
        }
        if (isset($input['locale'])) {
            app()->setLocale($input['locale']);
        }

        $this->assertSame($expected, SeoTitleRule::resolve($input));
    }

    /**
     * 틀 치환(옵셔널 그룹·빈 변수 구분자 정리)이 사례마다 기대값을 냅니다.
     *
     * @scenario case=substitute_fixture_server
     *
     * @effects server_substitute_matches_fixture
     *
     * @param  string  $template  틀
     * @param  array  $vars  변수
     * @param  string  $expected  기대값
     * @return void 반환값 없음
     */
    #[DataProvider('substituteCases')]
    public function test_substitute_vars_matches_fixture(string $template, array $vars, string $expected): void
    {
        $substituter = new class
        {
            use SubstitutesSeoVariables;

            /**
             * 트레이트의 치환을 시험에서 부릅니다.
             *
             * @param  string  $template  틀
             * @param  array  $vars  변수
             * @return string 치환 결과
             */
            public function run(string $template, array $vars): string
            {
                return $this->substituteVars($template, $vars);
            }
        };

        $this->assertSame($expected, $substituter->run($template, $vars));
    }

    /**
     * 봇 해석기가 같은 규칙을 쓴다 — 옮겨 온 상품 meta_title 이 이미 「 - 사이트 이름」으로 끝나면 접미사를 다시 붙이지 않고,
     * 접미사를 비워 둔 사이트도 기본 접미사 ` - {사이트 이름}` 이 붙는다.
     *
     * @scenario case=resolver_imported_title_server
     *
     * @effects server_resolver_applies_shared_rule
     *
     * @return void 반환값 없음
     */
    public function test_resolver_applies_shared_rule_to_imported_meta_title(): void
    {
        Config::set('g7_settings.core.seo.meta_title_suffix', '');
        Config::set('g7_settings.core.general.site_name', 'G7 Shop');
        $resolver = new SeoMetaResolver(new ExpressionEvaluator);

        $imported = $resolver->resolve([], ['product' => ['data' => ['meta_title' => '가죽크로스백 - G7 Shop']]], null, null, [], '상품 상세');
        $this->assertSame('가죽크로스백 - G7 Shop', $imported['title']);
        $this->assertSame('', SeoTitleRule::composeSuffix($imported['title'], SeoTitleRule::assembleSuffix($imported['titleSuffix'], 'G7 Shop'), 'G7 Shop'));

        $plain = $resolver->resolve([], ['product' => ['data' => ['meta_title' => '후드 티']]], null, null, [], '상품 상세');
        $this->assertSame('후드 티 - G7 Shop', $plain['title'].SeoTitleRule::composeSuffix($plain['title'], SeoTitleRule::assembleSuffix($plain['titleSuffix'], 'G7 Shop'), 'G7 Shop'));

        $home = $resolver->resolve(['og' => ['title' => '홈 OG 제목']], ['route' => ['path' => '/']], null, null, [], null);
        $this->assertSame('G7 Shop', $home['title'].SeoTitleRule::composeSuffix($home['title'], SeoTitleRule::assembleSuffix($home['titleSuffix'], 'G7 Shop'), 'G7 Shop'));
    }
}
