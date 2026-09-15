<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Extension\Helpers\LayoutFragmentSourceHelper;
use App\Extension\Traits\ValidatesLayoutFiles;
use Illuminate\Support\Facades\File;
use Tests\Support\GateTestPopulation;
use Tests\Support\InjectsGuardSamples;
use Tests\Support\LayoutSourceConvention;

/**
 * 가드의 **기대값이 검증 대상을 통과하지 않는다** (30차 근본 조치 A).
 *
 * S17·S18 이 스무 회차 넘게 같은 형태로 반복된 기전 하나가 여기 있다 — 회차마다 "근본 조치" 로
 * 새 단언을 심었고, 그 단언이 **실패할 수 없는 명제**여도 아무도 알아채지 못했다.
 *
 * 29차가 그 최신판이다. 짝 병존 fragment 에서 "소스가 이긴다" 를 단언하려고 이렇게 썼다:
 *
 * ```php
 * $viaMarkup = LayoutFragmentSourceHelper::read($markupFile);
 * $resolved  = LayoutFragmentSourceHelper::read($jsonSibling);
 * $this->assertSame($viaMarkup, $resolved);
 * ```
 *
 * 그런데 `read()` 는 입력이 `.g7.html` 이든 짝 `.json` 이든
 * `ResolvesLayoutInstallSource::layoutSourceCandidates()` 로 **같은 후보
 * 목록에 수렴**한다. 두 호출은 어느 소스가 이기든 같은 값을 돌려주므로 이 비교는 항등식이다.
 * 변이검증(`LAYOUT_INSTALL_SOURCE=json`)이 red 를 냈지만 그것은 **다른 단언**이 낸 red 였고,
 * 테스트 단위 변이검증은 그 구별을 하지 못한다.
 *
 * 그래서 판정을 기계로 옮긴다: 소스 선택을 검증하는 자리에서 **비교의 양변이 모두 그 선택을
 * 수행하는 진입점을 통과하면** 위반이다. 기대값은 선택 경로를 거치지 않는 독립 오라클
 * (예: 마크업 직접 컴파일)이어야 한다.
 */
class OracleIndependenceGuardTest extends LayoutMarkupTestCase
{
    use InjectsGuardSamples;

    /**
     * 소스 **선택**을 수행하는 코어 진입점 클래스 — 이 클래스의 public static 표면 전량이 대상이다.
     *
     * 31차까지 진입점은 `['read', 'readDirectory']` 라는 **손으로 쓴 이름 열거**였다. 열거의 경계를
     * 저자가 정하므로 여집합이 남고, 실제로 남아 있었다 — 조각 축의 선택 진입점
     * (`ValidatesLayoutFiles::resolvePartialSource()`, S18 P1 의 본체)이 그 목록에 없어 조각
     * 축에서 만든 항진명제는 구조적으로 보이지 않았다. 그래서 열거를 **리플렉션 도출**로 바꾼다:
     * 이 클래스는 "확장이 자기 확장점을 읽는 유일한 코어 진입점" 이라는 단일 목적을 가지므로
     * public static 표면 전체가 곧 선택 진입점이고, 새 메서드가 생기면 자동으로 편입된다.
     */
    private const RESOLVER_CLASS = LayoutFragmentSourceHelper::class;

    /**
     * 리플렉션으로 닿지 않는 선택 진입점 (트레이트의 protected 메서드).
     *
     * 조각 축의 선택은 트레이트 안에서 `protected` 로 일어나 외부 리플렉션 표면에 없다.
     *
     * 34차 — 이 자리는 **손으로 쓴 이름 열거 넷**이었고, 그 여집합에 규약의 소유자
     * (`layoutSourceCandidates`·`mergeLayoutSourcesByPriority`)와 조각 축의 `resolveAllPartials`
     * 가 남아 있었다. 그 진입점으로 만든 항진명제는 문지기(`mentionsResolverEntryPoint`)에서
     * 걸러져 검사에 들어오지도 않는다 — 31차가 `resolvePartialSource` 누락으로 고친 것과 같은
     * 여집합이 한 층 위에 있었던 것이다. 열거를 없애고 규약 표면 정본
     * ({@see LayoutSourceConvention::DELEGATIONS})에서 도출한다. 소유자 실재는
     * {@see test_resolver_entry_points_still_exist_in_core()} 가 확인한다.
     *
     * @var array<int, string>
     */
    private const TRAIT_RESOLVER_ENTRY_POINTS = LayoutSourceConvention::DELEGATIONS;

    /**
     * 비교의 양변이 모두 소스 해석기를 통과하는 단언이 없다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects guard_assertions_do_not_compare_resolver_output_with_itself
     */
    public function test_no_assertion_compares_resolver_output_against_itself(): void
    {
        $files = $this->guardTestFiles();

        $this->assertNotEmpty($files,
            '검사 대상 테스트 파일이 0건이다 — 모집단 도출이 죽었는지 먼저 확인해야 한다');

        $inspected = 0;
        $violations = [];

        foreach ($files as $file) {
            $code = $this->judgedCode(File::get(base_path($file)));

            if (! $this->mentionsResolverEntryPoint($code)) {
                continue;
            }

            $inspected++;

            foreach ($this->violationsInCode($code) as $line) {
                $violations[] = "{$file}: {$line}";
            }
        }

        $this->assertGreaterThan(0, $inspected,
            '소스 해석기를 쓰는 테스트가 0건이다 — 진입점 이름이 바뀌어 판정식이 구조적으로 '
            .'아무것도 보지 못하는 상태인지 먼저 확인해야 한다');

        $this->assertSame([], $violations,
            '비교의 양변이 모두 소스 해석기를 통과합니다 — 어느 소스가 이겨도 같은 값이 나오므로 '
            .'이 단언은 실패할 수 없다. 기대값은 선택 경로를 거치지 않는 독립 오라클로 만들어야 한다.');
    }

    /**
     * 판정식이 29차의 실제 코드 형태를 잡는지 합성 표본으로 시험합니다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects oracle_independence_self_test_detects_round29_shape
     */
    public function test_predicate_detects_the_round29_shape(): void
    {
        // ⓐ 29차 실물 형태 — 양변이 모두 read() (위반)
        $tautology = <<<'PHP'
        <?php
        $viaMarkup = LayoutFragmentSourceHelper::read($markupFile);
        $resolved = LayoutFragmentSourceHelper::read($jsonSibling);
        $this->assertSame($viaMarkup, $resolved, '소스로 해석되지 않았습니다');
        PHP;

        // ⓑ 독립 오라클 — 기대값이 선택 경로를 타지 않는다 (준수)
        $independent = <<<'PHP'
        <?php
        $compiled = $this->compileFragmentMarkup($markupFile);
        $resolved = LayoutFragmentSourceHelper::read($jsonSibling);
        $this->assertSame($compiled, $resolved, '소스로 해석되지 않았습니다');
        PHP;

        // ⓒ 해석기 결과를 원본과 비교 = 준수 (한 변만 통과)
        $againstRaw = <<<'PHP'
        <?php
        $resolved = LayoutFragmentSourceHelper::read($jsonSibling);
        $this->assertNotSame($rawJson, $resolved);
        PHP;

        $this->assertNotSame([],
            $this->violationsInCode($this->codeWithoutComments($tautology)),
            '29차의 항진명제 형태를 놓칩니다 — 이 가드의 초록은 아무것도 뜻하지 않는다');

        $this->assertSame([],
            $this->violationsInCode($this->codeWithoutComments($independent)),
            '독립 오라클을 쓴 준수 형태가 위반으로 잡힙니다 (과탐 가드는 무시당한다)');

        $this->assertSame([],
            $this->violationsInCode($this->codeWithoutComments($againstRaw)),
            '한 변만 해석기를 통과하는 정상 비교가 위반으로 잡힙니다');

        // 31차 — 종전 판정식을 그냥 통과했던 세 형태. 표기를 열거하는 판정이 왜 무한후퇴인지의 증거라
        // 표본으로 상주시킨다. 하나라도 놓치면 판정이 다시 표기 의존으로 되돌아갔다는 뜻이다.
        $bypasses = [
            '첨자 접근 (이 트랙의 지배적 관용구)' => <<<'PHP'
            <?php
            $viaMarkup = LayoutFragmentSourceHelper::read($markupFile);
            $resolved = LayoutFragmentSourceHelper::read($jsonSibling);
            $this->assertSame($viaMarkup['extension_point'], $resolved['extension_point']);
            PHP,
            '인라인 호출 (대입이 없어 검사에 들어오지도 않았다)' => <<<'PHP'
            <?php
            $this->assertSame(
                LayoutFragmentSourceHelper::read($markupFile),
                LayoutFragmentSourceHelper::read($jsonSibling)
            );
            PHP,
            'Canonicalizing 계열 (단언 이름 열거 밖)' => <<<'PHP'
            <?php
            $viaMarkup = LayoutFragmentSourceHelper::read($markupFile);
            $resolved = LayoutFragmentSourceHelper::read($jsonSibling);
            $this->assertEqualsCanonicalizing($viaMarkup, $resolved);
            PHP,
            '조각 축 진입점 (열거에 없어 구조적으로 안 보였다)' => <<<'PHP'
            <?php
            $viaMarkup = $this->resolvePartialSource($markupFile, $base);
            $resolved = $this->resolvePartialSource($jsonSibling, $base);
            $this->assertSame($viaMarkup, $resolved);
            PHP,
        ];

        foreach ($bypasses as $label => $sample) {
            $this->assertNotSame([],
                $this->violationsInCode($this->codeWithoutComments($sample)),
                "종전 판정식을 통과했던 항진명제 형태를 여전히 놓칩니다 ({$label})");
        }

        // 과탐 반례 — 첨자 접근이지만 한 변이 독립 오라클이면 정상이다.
        $independentSubscript = <<<'PHP'
        <?php
        $compiled = $this->compileFragmentMarkup($markupFile);
        $resolved = LayoutFragmentSourceHelper::read($jsonSibling);
        $this->assertSame($compiled['extension_point'], $resolved['extension_point']);
        PHP;

        $this->assertSame([],
            $this->violationsInCode($this->codeWithoutComments($independentSubscript)),
            '첨자 접근 판정을 넓히면서 독립 오라클을 쓴 정상 비교까지 위반으로 잡습니다');

        // 32차 — 진입점을 아예 부르지 않는 코드는 검사 대상이 아니다(문지기가 살아 있는지 확인).
        $unrelated = <<<'PHP'
        <?php
        $a = $this->somethingElse($x);
        $b = $this->somethingElse($y);
        $this->assertSame($a, $b);
        PHP;

        $this->assertSame([],
            $this->violationsInCode($this->codeWithoutComments($unrelated)),
            '해석기와 무관한 비교까지 위반으로 잡습니다 — 문지기가 사라졌다는 뜻이다');
    }

    /**
     * 모집단에 **주입한** 표본에 프로덕션 검사가 반응합니다 (32차 근본 조치 H).
     *
     * 위 자기시험은 검사 구간(`violationsInCode()`)을 부르므로 문지기까지는 시험하지만, 모집단
     * 도출은 여전히 시험 밖이다. 여기서는 표본을 **디스크에 써서 모집단에 넣고 프로덕션 테스트
     * 메서드를 그대로 돌린다** — 도출·문지기·판정식·단언 전 구간이 표본 경로에 들어온다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects oracle_independence_guard_reacts_to_population_injected_samples
     */
    public function test_guard_reacts_to_samples_injected_into_the_population(): void
    {
        $inlineTautology = <<<'PHP'
        <?php
        class InjectedInlineTautologySample
        {
            public function test_pair_resolves_to_authoring_source(): void
            {
                $this->assertSame(
                    LayoutFragmentSourceHelper::read($markupFile),
                    LayoutFragmentSourceHelper::read($jsonSibling)
                );
            }
        }
        PHP;

        $independentOracle = <<<'PHP'
        <?php
        class InjectedIndependentOracleSample
        {
            public function test_pair_resolves_to_authoring_source(): void
            {
                $compiled = $this->compileFragmentMarkupDirectly($markupFile);
                $resolved = LayoutFragmentSourceHelper::read($jsonSibling);
                $this->assertSame($compiled, $resolved);
            }
        }
        PHP;

        $production = function (): void {
            $this->test_no_assertion_compares_resolver_output_against_itself();
        };

        $this->assertGuardRejects('oracle_inline_tautology.php', $inlineTautology, $production,
            '인라인 호출 항진명제 — 32차까지 문지기가 대입문만 봐서 이 표본은 검사에 들어오지도 않았다');

        $this->assertGuardAccepts('oracle_independent.php', $independentOracle, $production,
            '독립 오라클 — 한 변만 선택 경로를 통과하는 정상 형태');
    }

    /**
     * 진입점 **열거**가 실재하는 코어 표면을 가리키는지 잠급니다.
     *
     * 이름이 바뀌면 이 가드는 조용히 축을 잃는다 — 낡은 열거로 초록이 되는 것을 막는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects oracle_independence_entry_point_enumeration_is_anchored_to_core
     */
    public function test_resolver_entry_points_still_exist_in_core(): void
    {
        $derived = $this->resolverEntryPointNames();

        $this->assertNotEmpty($derived,
            '선택 진입점이 0건 도출됐다 — 리플렉션 대상 클래스가 사라졌거나 표면이 비었다면 이 가드는 '
            .'아무 파일도 검사하지 않는다');

        // 리플렉션 축 — public static 표면이 실제로 존재해야 도출이 뜻을 갖는다.
        $reflected = array_filter(
            (new \ReflectionClass(self::RESOLVER_CLASS))->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $method): bool => $method->isStatic()
        );

        $this->assertNotEmpty($reflected,
            self::RESOLVER_CLASS.' 의 public static 표면이 비었습니다 — 확장이 자기 확장점을 읽는 '
            .'코어 진입점이 사라졌다는 뜻이다');

        // 트레이트 축 — 규약 표면 정본에서 도출한 진입점은 코어 어딘가에 실재해야 한다.
        // 소유자를 손으로 적지 않고 찾게 하므로, 진입점이 트레이트 사이를 이사해도 정박은 유지되고
        // **사라지면** red 다.
        $methods = LayoutSourceConvention::selectionMethodNames();

        $this->assertNotEmpty($methods,
            '규약 표면에서 진입점 이름이 0건 도출됐다 — '.LayoutSourceConvention::class
            .'::DELEGATIONS 가 비었거나 표기 규칙이 바뀌었다');

        foreach ($methods as $method) {
            $this->assertNotNull(LayoutSourceConvention::ownerOf($method),
                "선택 진입점을 코어에서 찾을 수 없습니다: {$method}() — 이름이 바뀌었다면 "
                .LayoutSourceConvention::class.'::DELEGATIONS 를 함께 고쳐야 하고, 그러지 않으면 '
                .'그 축이 조용히 검사에서 빠진다');
        }
    }

    /**
     * 코어의 선택 표면 **전량**이 규약 정본에 분류돼 있습니다 — 역방향 폐쇄 (35차 근본 조치 J).
     *
     * ## 왜 이 방향이 필요한가
     *
     * 34차는 흩어진 목록 둘을 {@see LayoutSourceConvention} 하나로 통합했지만, 폐쇄는
     * **목록 → 코어** 한 방향뿐이었다({@see test_resolver_entry_points_still_exist_in_core()} —
     * "등재분이 코어에 실재하는가"). 그래서 **코어에 있는데 목록에 없는** 진입점은 아무도 묻지
     * 않았고, 35차 실측에서 여집합 3건이 그대로 살아 있었다. 그중 `resolveFragmentSource()` 는
     * 공유 오라클이 실제로 부르는 선택 진입점이라, 그것으로 만든 항진명제는 문지기에서 걸러져
     * 검사에 **들어오지도 않았다**.
     *
     * 이 형태가 이 트랙이 반복해 온 골격의 정확한 정의다 — *열거의 여집합이 다음 회차의
     * 검출물이 된다*. 한 방향만 잠근 폐쇄는 폐쇄가 아니므로, 반대 방향을 **코어에서 도출**해
     * 잠근다. 도출은 이름 규칙이 아니라 본문의 성질로 하므로(우선순위를 결정하거나 그 후보를
     * 순회하는가) 저자가 경계를 고를 자리가 없다.
     *
     * 새 선택 표면이 코어에 생기면 이 단언이 red 다 — 위임 표기(`DELEGATIONS`)인지 비위임
     * 표면(`NON_DELEGATING_SURFACE`)인지 **분류를 강제**한다. 둘의 폭이 다른 이유는 소비처가
     * 다르기 때문이고(면제 vs 문지기), 분류를 강제하지 않으면 그 차이가 다시 여집합을 만든다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects core_selection_surface_is_fully_classified_in_the_convention
     */
    public function test_core_selection_surface_is_fully_registered(): void
    {
        $derived = LayoutSourceConvention::derivedSelectionMethods();

        $this->assertNotEmpty($derived,
            '코어에서 선택 표면이 0건 도출됐다 — 도출 성질이 낡아 구조적으로 아무것도 보지 못하는 '
            .'상태인지 먼저 확인해야 한다 (그 상태면 이 폐쇄는 공허하게 참이 된다)');

        $classified = LayoutSourceConvention::selectionSurface();

        $this->assertSame([], array_values(array_diff($derived, $classified)),
            '코어에 있는데 규약 정본이 모르는 선택 표면이 있습니다 — 그 진입점으로 만든 오라클은 '
            .'문지기에서 걸러져 검사에 들어오지 않으므로, 그 축은 조용히 사각이 된다. '
            .LayoutSourceConvention::class.' 의 DELEGATIONS(위임 표기) 또는 '
            .'NON_DELEGATING_SURFACE(사유 등재) 중 하나로 분류해야 한다.');

        // 분류가 거짓말로 굳지 않도록 — 비위임 등재분은 사유를 갖고, 실제로 코어에 있어야 한다.
        foreach (LayoutSourceConvention::NON_DELEGATING_SURFACE as $method => $reason) {
            $this->assertNotSame('', trim($reason),
                "비위임 표면 등재에 사유가 없습니다: {$method}()");

            $this->assertNotNull(LayoutSourceConvention::ownerOf($method),
                "비위임 표면 등재분이 코어에 없습니다: {$method}() — 사라졌다면 등재도 지워야 한다");
        }
    }

    /**
     * 한 표면이 **위임 표기와 비위임 표면에 동시에** 등재되지 않습니다 (39차 신설).
     *
     * ## 왜 이 축이 필요한가
     *
     * {@see LayoutSourceConvention::NON_DELEGATING_SURFACE} 의 독블록은 *"여기 등재된 것은
     * 우선순위 결정을 위임받지 않는다. … 면제 목록에 넣으면 안 된다"* 로 두 목록의 배타를 이미
     * 규정하고 있었다. 그런데 그 규칙을 집행하는 장치가 없었고, 실제로 `resolveAllPartials` 가
     * **양쪽에 동시 등재**돼 있었다. 35차가 두 목록을 갈라 낸 이유가 "폭이 다른 두 소비처"
     * (면제는 좁아야 안전 · 문지기는 넓어야 안전)인데, 한 이름이 양쪽에 있으면 **면제 쪽 폭이
     * 문지기 쪽 폭까지 넓어진다** — 갈라 낸 의미가 그 이름에 대해서만 사라진다.
     *
     * 왜 35차의 역방향 폐쇄가 이것을 못 봤나: {@see test_core_selection_surface_is_fully_registered()}
     * 는 도출 집합이 **합집합**({@see LayoutSourceConvention::selectionSurface()})에 덮이는지만
     * 본다. 합집합은 중복을 흡수하므로 이중 등재는 그 판정에 흔적을 남기지 않는다. 열거의
     * *여집합*은 잠갔으나 *교집합*은 잠근 적이 없었다.
     *
     * **오늘 검출이 바뀌지는 않는다** — 이중 등재를 풀기 전후로 라우팅 가드 두 축이 모두 green
     * 임을 실측했다. 즉 실현된 사각이 아니라 **잠재 면제**이고, 그래서 이 축은 "지금 무엇을
     * 잡는가" 가 아니라 "무엇이 생기면 통과해 버리는가" 를 막는다 — 우선순위를 자기가 다시
     * 구현하면서 `resolveAllPartials()` 를 한 번 부르는 사본이 그 형태다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects selection_surface_classification_is_mutually_exclusive
     */
    public function test_selection_surface_classification_is_mutually_exclusive(): void
    {
        $delegating = LayoutSourceConvention::selectionMethodNames();
        $nonDelegating = array_keys(LayoutSourceConvention::NON_DELEGATING_SURFACE);

        $this->assertNotEmpty($delegating,
            '위임 표기에서 메서드 이름이 0건 도출됐다 — 도출식이 죽으면 교집합은 언제나 공집합이라 '
            .'이 단언이 공허하게 참이 된다');
        $this->assertNotEmpty($nonDelegating, '비위임 표면 등재가 비었다 — 같은 이유로 먼저 확인한다');

        $both = array_values(array_intersect($delegating, $nonDelegating));

        $this->assertSame([], $both,
            '한 선택 표면이 위임 표기(DELEGATIONS)와 비위임 표면(NON_DELEGATING_SURFACE)에 동시에 '
            .'등재됐습니다 — 비위임 등재분을 면제로 쓰면 라우팅 가드가 잡아야 할 사본이 그 이름 '
            .'하나로 통과한다. 둘 중 하나로만 분류할 것 (그 표면을 부른다고 우선순위 규약을 따르는 '
            .'것이 아니라면 비위임 쪽이 정본이다).');
    }

    /**
     * 이 가드 자신도 **면제 없이** 판정 대상이며, 표본은 판정을 오염시키지 않습니다 (35차 근본 조치 J).
     *
     * ## 왜 면제를 없앴는가
     *
     * 31차는 자기 파일을 `SAMPLE_BEARING_FILES` 로 **통째 면제**했다. 그 면제의 실제 사유는
     * "표본을 담고 있어서" 였는데, 표본은 nowdoc 리터럴이므로 **본문만 걷어내면 사라진다**.
     * 즉 면제는 판정이 heredoc 을 보지 못한 데서 온 것이지 그 파일이 특별해서가 아니었다.
     * 그런데 면제가 파일 단위라 **그 파일에 새로 생긴 진짜 항진명제는 영구히 판정 밖**이었다 —
     * 22차가 "감시자가 자기를 관측 밖에 둔다" 로 지목한 형태의 파일-입도 판본이고, 33·34차가
     * 다른 가드에서 고친 입도 결함과 같은 계열이다.
     *
     * 그래서 면제를 없애고 {@see judgedCode()} 가 표본 본문을 걷어낸다. 이 테스트는 그 치환이
     * 실제로 성립하는지를 **양방향**으로 관측한다 — 표본이 살아 있고(원본에서는 위반이 잡힌다),
     * 그 표본이 판정에는 들어오지 않는다(제거 후 0건). 어느 쪽이 무너져도 red 다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects oracle_independence_guard_judges_itself_without_file_exemption
     */
    public function test_this_guard_is_judged_without_exemption(): void
    {
        $relative = GateTestPopulation::relativePath(__FILE__);

        $this->assertArrayHasKey($relative, GateTestPopulation::gateSelectedFiles(),
            '이 가드가 게이트 모집단 밖에 있습니다 — 자기 판정이 성립하지 않는다');

        $this->assertNotSame([], $this->violationsInCode($this->codeWithoutComments(File::get(__FILE__))),
            '원본에 위반 형태(합성 표본)가 없습니다 — 표본이 사라졌다면 판정식의 검출력을 '
            .'증명할 수단이 없어진 것이므로 먼저 표본을 되살려야 한다');

        $this->assertSame([], $this->violationsInCode($this->judgedCode(File::get(__FILE__))),
            '표본 본문을 걷어낸 뒤에도 이 가드에 위반이 남아 있습니다 — 표본이 아니라 실제 '
            .'단언이 항진명제라는 뜻이다');
    }

    /**
     * 한 파일의 위반 목록 — **문지기와 판정식을 한 구간으로 묶은** 검사 본체입니다.
     *
     * 32차 근본 조치 H. 종전에는 문지기(`$resolverVars === []` 이면 건너뜀)가 프로덕션 루프
     * 안에 있고 자기시험은 판정식(`tautologicalComparisons()`)을 **직접** 불렀다. 그래서
     * 자기시험은 "판정식이 그 형태를 안다" 만 증명했고, "가드가 그 형태를 검사한다" 는 아무도
     * 증명하지 않았다 — 실제로 문지기가 표기(대입문) 기반이라 진입점을 인라인 호출하는 게이트
     * 상주 6파일이 통째로 검사 밖이었고, 그 6건에 이 축을 새로 세운 결제 리스너 4파일이 전부
     * 들어 있었다. 검사 구간을 한 메서드로 모아 **자기시험이 프로덕션과 같은 입구로 들어오게**
     * 한다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return array<int, string> 위반 단언 원문
     */
    private function violationsInCode(string $code): array
    {
        if (! $this->mentionsResolverEntryPoint($code)) {
            return [];
        }

        return $this->tautologicalComparisons($code, $this->resolverDerivedVariables($code));
    }

    /**
     * 코드가 소스 선택 진입점을 **어떤 형태로든** 부르는지 판정합니다.
     *
     * 대입문만 보던 종전 문지기의 여집합이 32차 검출물이었다. 대입·인라인·체이닝을 가리지 않고
     * 진입점 호출 자체를 신호로 삼아 문지기에서 표기 의존을 걷어낸다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return bool 진입점을 부르면 true
     */
    private function mentionsResolverEntryPoint(string $code): bool
    {
        foreach ($this->resolverEntryPointNames() as $entryPoint) {
            if (preg_match('/(?:'.$entryPoint.')\s*\(/', $code) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 소스 해석기 호출로 값을 받은 변수명을 모읍니다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return array<int, string> 변수명 (달러 없음)
     */
    private function resolverDerivedVariables(string $code): array
    {
        $entryPoints = implode('|', $this->resolverEntryPointNames());

        preg_match_all(
            '/\$(\w+)\s*=\s*[^;]*(?:'.$entryPoints.')\s*\(/',
            $code,
            $matches
        );

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * 소스 선택 진입점 이름 전량을 도출합니다 (리플렉션 + 트레이트 등재분).
     *
     * @return array<int, string> 정규식에 넣을 수 있게 이스케이프된 이름 목록
     */
    private function resolverEntryPointNames(): array
    {
        $names = [];

        foreach ((new \ReflectionClass(self::RESOLVER_CLASS))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() && $method->getDeclaringClass()->getName() === self::RESOLVER_CLASS) {
                $names[] = preg_quote($method->getName(), '/');
            }
        }

        // 35차 — 문지기는 **선택 표면 전량**을 알아야 한다(위임 표기만으로는 좁다). 출력이 선택에서
        // 나오는 자리를 하나라도 모르면 그 진입점으로 만든 항진명제가 검사에 들어오지 않는다.
        foreach (LayoutSourceConvention::selectionSurface() as $method) {
            $names[] = preg_quote($method, '/');
        }

        return array_values(array_unique($names));
    }

    /**
     * 양변이 모두 해석기 파생 변수인 비교 단언을 찾습니다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @param  array<int, string>  $resolverVars  해석기 파생 변수명
     * @return array<int, string> 위반 단언 원문
     */
    private function tautologicalComparisons(string $code, array $resolverVars): array
    {
        $violations = [];

        // 31차 — 종전 판정은 `assertSame($a, $b` 처럼 **양변이 맨 변수**인 형태만 봤다. 그런데 이
        // 트랙의 지배적 관용구는 첨자 접근(`assertSame($a['k'], $b['k'])`)이고, 인라인 호출
        // (`assertSame(Helper::read($x), Helper::read($y))`)은 대입이 없어 검사 자체에 들어오지도
        // 않았다. 표기를 열거하는 대신 **인자별 오염 판정**으로 바꾼다 — 인자 문자열이 해석기
        // 파생 변수를 참조하거나 진입점을 직접 호출하면 그 변은 오염됐다고 본다.
        foreach ($this->comparisonAssertions($code) as $assertion) {
            [$raw, $arguments] = $assertion;

            if (count($arguments) < 2) {
                continue;
            }

            if ($this->isResolverTainted($arguments[0], $resolverVars)
                && $this->isResolverTainted($arguments[1], $resolverVars)) {
                $violations[] = $raw;
            }
        }

        return $violations;
    }

    /**
     * 비교 단언과 그 앞 두 인자를 뽑습니다 (괄호·대괄호 균형 유지).
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return array<int, array{0: string, 1: array<int, string>}> [원문, 인자 목록]
     */
    private function comparisonAssertions(string $code): array
    {
        $names = 'Same|NotSame|Equals|NotEquals|EqualsCanonicalizing|NotEqualsCanonicalizing'
            .'|EqualsIgnoringCase|NotEqualsIgnoringCase';

        preg_match_all('/assert(?:'.$names.')\s*\(/', $code, $heads, PREG_OFFSET_CAPTURE);

        $found = [];

        foreach ($heads[0] as $head) {
            $open = $head[1] + strlen($head[0]) - 1;
            $depth = 0;
            $length = strlen($code);
            $arguments = [];
            $current = '';

            for ($i = $open; $i < $length; $i++) {
                $char = $code[$i];

                if (in_array($char, ['(', '[', '{'], true)) {
                    $depth++;

                    if ($depth === 1) {
                        continue;
                    }
                } elseif (in_array($char, [')', ']', '}'], true)) {
                    $depth--;

                    if ($depth === 0) {
                        $arguments[] = trim($current);
                        break;
                    }
                } elseif ($char === ',' && $depth === 1) {
                    $arguments[] = trim($current);
                    $current = '';

                    continue;
                }

                $current .= $char;
            }

            $found[] = [$head[0].implode(', ', array_slice($arguments, 0, 2)), $arguments];
        }

        return $found;
    }

    /**
     * 한 인자 표현식이 소스 선택 경로를 통과했는지 판정합니다.
     *
     * @param  string  $argument  인자 표현식 원문
     * @param  array<int, string>  $resolverVars  해석기 파생 변수명
     * @return bool 선택 경로를 통과하면 true
     */
    private function isResolverTainted(string $argument, array $resolverVars): bool
    {
        foreach ($resolverVars as $variable) {
            if (preg_match('/\$'.preg_quote($variable, '/').'\b/', $argument) === 1) {
                return true;
            }
        }

        foreach ($this->resolverEntryPointNames() as $entryPoint) {
            if (preg_match('/(?:'.$entryPoint.')\s*\(/', $argument) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 검사 대상 테스트 파일을 도출합니다 — 게이트가 실제로 실행하는 집합 전량.
     *
     * 31차까지는 손으로 쓴 디렉토리 2줄이라 결제 리스너 테스트와 `tests/Unit/Rules`·`Enums` 의
     * 게이트 상주 테스트가 검사 밖이었다. 도출을 {@see GateTestPopulation} 한 곳에 위임한다.
     *
     * @return array<int, string> 저장소 루트 기준 상대 경로
     */
    private function guardTestFiles(): array
    {
        // 33차 ⚠️F3 — `tests/Support/` 의 공유 오라클(CompilesFragmentMarkup 등)은 게이트 테스트가
        // 실제로 실행하는 비교를 갖는다. 32차가 그 축을 무효 단언 가드(AssertionStrengthGuardTest)
        // 에만 합류시켜, 오염된 오라클이 헬퍼 층으로 이사하면 이 가드의 판정 밖이었다.
        return array_keys(GateTestPopulation::gateSelectedFiles() + GateTestPopulation::gateSupportFiles());
    }

    /**
     * PHP 소스에서 주석만 제거합니다 (문자열·정규식 리터럴 보존).
     *
     * 정규식 치환을 쓰지 않는 이유는 19차가 그 대가를 치렀기 때문이다 — 주석 제거기가 리터럴
     * 사이를 통째로 먹어 가드 전량이 후보 0건인 채 초록으로 통과했다.
     *
     * @param  string  $source  PHP 소스
     * @return string 주석이 제거된 소스
     */
    private function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * 실제로 판정에 넣을 코드 — 주석과 **합성 표본 본문**을 함께 걷어냅니다 (35차).
     *
     * 표본은 nowdoc 리터럴이므로 본문을 지우면 판정에서 사라진다. 이것이 파일 단위 면제를
     * 불필요하게 만드는 치환이고, 동시에 31차가 파일 면제로 우회했던 **정규식 월경 기전**도
     * 닫는다 — `resolverDerivedVariables()` 의 `[^;]*` 가 nowdoc 경계를 넘어 표본까지 한 매치로
     * 삼키던 자리에 애초에 nowdoc 본문이 없다.
     *
     * 제거기는 {@see GateTestPopulation::codeWithoutLiteralBodies()} 를 쓴다 — 표본이 담기는
     * 그릇은 nowdoc 만이 아니라 인라인 문자열이기도 하다. 같은 저장소에 이미 있는 것을 이 가드만
     * 안 쓰고 있었고, 사본을 만들면 그 자리가 다음 회차의 검출물이 된다.
     *
     * @param  string  $source  PHP 소스
     * @return string 주석·표본 본문이 제거된 소스
     */
    private function judgedCode(string $source): string
    {
        return GateTestPopulation::codeWithoutLiteralBodies($this->codeWithoutComments($source));
    }
}
