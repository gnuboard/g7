<?php

namespace Tests\Unit\Support\LayoutMarkup;

use Illuminate\Support\Facades\File;
use Tests\Support\GateTestPopulation;
use Tests\Support\InjectsGuardSamples;

/**
 * 게이트 안의 단언이 **실패할 수 있는 명제**인지 기계로 판정한다 (31차 근본 조치 D).
 *
 * ## 왜 이 층이 필요한가
 *
 * S17 이후 서른 회차가 반복된 실제 기전은 검출력 부족이 아니었다. 회차마다 조치를 넣고 그 조치를
 * 지킬 단언을 심었는데, **그 단언이 실패할 수 없는 명제여도 아무도 알아채지 못했다.** 회차별로:
 *
 * - 29차 — `assertCount(논리 fragment 수, readDirectory 수)`. 양쪽 다 짝을 1건으로 접으므로
 *   어느 소스가 이기든 개수가 같다. 독블록이 주장한 실패 모드가 존재하지 않았다.
 * - 30차 — `assertSame(read($markup), read($json))`. `read()` 가 두 입력을 같은 후보 목록으로
 *   수렴시키므로 항등식. 근본 A(`OracleIndependenceGuardTest`)가 이 형태를 맡았다.
 * - 31차 — 근본 C 가 `assertGreaterThanOrEqual(0, $inspected)` 로 끝난다. 정수는 항상 0 이상이라
 *   **어떤 코드 변경으로도 red 가 될 수 없다.** 그 옆의 하한 부재까지 합쳐, 모집단 도출이 죽으면
 *   가드 전체가 조용히 통과한다.
 *
 * 근본 A 는 이 문제의 **한 형태**(소스 해석기 항등식)만 본다. 나머지 형태는 회차마다 새로 발견됐고,
 * 발견될 때까지 초록이었다. 그래서 판정을 형태별 가드에서 떼어내 **단언 자체의 성질**로 올린다 —
 * 이 가드의 검사 대상은 코드가 아니라 **게이트 안의 다른 테스트들**이다.
 *
 * ## 왜 닫힌 세계인가
 *
 * 지난 회차의 가드들은 "위반을 표현하는 표기가 몇 가지인가" 를 손으로 열거해 판정했고, 그 열거의
 * 여집합이 다음 회차의 검출물이 됐다(28차 후행 슬래시 · 30차 디렉토리 2줄 · 31차 첨자 표기).
 * 표기 공간은 열려 있어 경계를 저자가 통제하지 못한다.
 *
 * 이 가드는 열린 쪽을 판정하지 않는다. **무효 단언의 형태는 유한하고 열거 가능하다** — 상수 하한,
 * 리터럴 동일 비교, 같은 변수 양변 비교. 모집단도 `phpunit.xml` 에서 도출한다
 * ({@see GateTestPopulation}). 즉 양쪽이 모두 닫혀 있다.
 *
 * ## 면제 — **없다** (35차에 폐기)
 *
 * 종전 이 절은 의도된 동결 단언을 `EXEMPTIONS` 상수에 등재하라고 안내했다. 그 면제는 **파일
 * 단위**였고, 사유("표본을 담고 있어서")는 판정이 리터럴 본문을 보지 못한 데서 왔을 뿐인데
 * 파일 단위라 **그 파일의 미래 위반까지 덮었다**. 35차가 {@see GateTestPopulation::codeWithoutLiteralBodies()}
 * 로 판정 단위를 "코드로서의 단언" 으로 좁히면서 면제 자체를 폐기했다.
 *
 * **37차 정정** — 그런데 이 독블록과 아래 단언 메시지는 폐기된 `EXEMPTIONS` 를 계속 지시하고
 * 있었다. 따르면 만들 수 없는 절차를 안내하는 상태였고, 이 트랙이 반복 지적해 온 "독블록이
 * 주장하는 명제와 실제 단언의 괴리" 의 잔여물이다(`docblock_attribution` 계약은 **연속 독블록
 * 고아**만 보므로 이 형태를 잡지 못한다).
 *
 * 지금은 면제가 없다. 정당한 동결 단언은 면제가 아니라 **하한 인정 판정식**
 * ({@see comparesAgainstNonEmptyConstant()})이 형태로 받아들인다.
 */
class AssertionStrengthGuardTest extends LayoutMarkupTestCase
{
    use InjectsGuardSamples;

    /**
     * 실패할 수 없는 단언 형태 → 사람이 읽을 설명.
     *
     * 열거이지만 **닫힌** 열거다 — "무효 단언" 은 논리적으로 유한한 집합이고, 표기 변형이 아니라
     * 명제의 성질로 정의된다. 새 형태를 발견하면 여기 추가하고 자기시험 표본도 함께 넣는다.
     *
     * @var array<string, string>
     */
    private const VACUOUS_PATTERNS = [
        // 정수/개수의 하한 0 — 항상 참
        '/assertGreaterThanOrEqual\s*\(\s*0\s*,/' => 'assertGreaterThanOrEqual(0, …) — 개수·정수는 '
            .'항상 0 이상이므로 어떤 변경으로도 red 가 되지 않는다. 실질 하한(1 이상)을 쓰거나 '
            .'assertNotEmpty 로 바꿔야 한다',
        '/assertGreaterThan\s*\(\s*-1\s*,/' => 'assertGreaterThan(-1, …) — 위와 동일하게 무효',
        '/assertLessThanOrEqual\s*\(\s*PHP_INT_MAX\s*,/' => 'assertLessThanOrEqual(PHP_INT_MAX, …) — 항상 참',
        // 리터럴 자기 비교
        '/assertTrue\s*\(\s*true\s*[,)]/' => 'assertTrue(true) — 방어를 흉내낸 무효 단언',
        '/assertFalse\s*\(\s*false\s*[,)]/' => 'assertFalse(false) — 동일',
        '/assertSame\s*\(\s*(true|false|null)\s*,\s*\1\s*[,)]/' => '리터럴을 자기 자신과 비교',
        '/assertNotNull\s*\(\s*(?:true|false|\d+|\'[^\']*\'|"[^"]*")\s*[,)]/' => '리터럴은 항상 non-null',
    ];

    /**
     * **저장소 콘텐츠**에서 모집단을 도출하는 신호 — 이 신호가 있으면 하한 단언이 있어야 한다.
     *
     * 파일 시스템을 훑어 모집단을 만드는 방법은 유한하고, 이 목록은 그 원시함수 집합이다.
     * 도출이 0건이 되면 "위반 없음" 과 "검사 없음" 이 구별되지 않으므로 하한이 의무가 된다.
     *
     * 임시 디렉토리에 **테스트가 직접 만든** 합성 픽스처를 훑는 것은 여기 해당하지 않는다 — 그쪽은
     * 0건이 의도인 경우가 정상이고(마크업 전용 상황을 세우려고 `.json` 을 일부러 만들지 않는 형태),
     * 하한을 씌우면 그 시나리오를 쓸 수 없게 된다. 구별은 판단이 아니라 기계로 한다: 도출식이
     * 저장소 경로(`base_path(`)나 코퍼스·게이트 도출기를 참조하면 저장소 스캔이고, `$this->tmp`
     * 처럼 테스트가 만든 경로만 참조하면 합성 픽스처다.
     *
     * @var array<int, string>
     */
    private const DERIVATION_SIGNALS = [
        'File::allFiles(',
        'File::files(',
        'File::glob(',
    ];

    /**
     * 도출식이 저장소 콘텐츠를 가리킨다고 보는 신호 ({@see DERIVATION_SIGNALS} 의 **인자** 안에서 찾는다).
     *
     * @var array<int, string>
     */
    private const REPOSITORY_SCOPE_SIGNALS = [
        'base_path(',
    ];

    /**
     * 호출만으로 저장소 스캔이 성립하는 모집단 도출기.
     *
     * 34차 — 종전에는 `GateTestPopulation::` 전체가 신호였다. 그러면 같은 클래스의 **파싱
     * 유틸**(`testMethodBodies` · `codeWithoutHeredocBodies` · `relativePath`)까지 도출로 읽혀,
     * 판정식 헬퍼를 부르는 순수 자기시험이 도출형으로 끌려온다(호출그래프를 1단 넓히자 즉시
     * 과탐이 났다). 도출은 **모집단을 내놓는 메서드**로 한정한다.
     *
     * @var array<int, string>
     */
    private const POPULATION_DERIVERS = [
        'LayoutCorpus::',
        'GateTestPopulation::gateSelectedFiles(',
        'GateTestPopulation::gateSupportFiles(',
        'GateTestPopulation::collectedFiles(',
        'GateTestPopulation::filesForPattern(',
        'GateTestPopulation::suiteDirectories(',
    ];

    /**
     * 모집단이 비지 않았음을 주장하는 단언 형태 (하한).
     *
     * 첫 판본은 하한 값을 **리터럴 숫자**로만 인정해, 상수 참조로 하한을 못박은 코퍼스 게이트
     * (`assertGreaterThanOrEqual(self::CORPUS_MINIMUM, …)`)와 빈 배열 대조로 하한을 세운 서빙
     * 등가성 테스트(`assertNotSame([], …)`)를 위반으로 잡았다. 표기에 의존한 판정은 이 트랙이
     * 28·30·31차에 걸쳐 세 번 고친 바로 그 병이라, 하한의 **의미**로 판정한다 — 하한 값이
     * 리터럴 0 이 아니면 무엇이든(상수·변수·식) 실질 하한으로 인정한다.
     *
     * @var array<int, string>
     */
    private const FLOOR_PATTERNS = [
        '/assertNotEmpty\s*\(/',
        '/assertNotSame\s*\(\s*\[\s*\]\s*,/',
        '/assertNotEquals\s*\(\s*\[\s*\]\s*,/',
        '/assertGreaterThan\s*\(\s*(?!-1\b)/',
        '/assertGreaterThanOrEqual\s*\(\s*(?!0\s*,)/',
        '/assertCount\s*\(\s*[1-9]/',
        '/assertSame\s*\(\s*[1-9]\d*\s*,\s*(?:count|\$\w*[Cc]ount)/',

        // 비어 있지 않은 **문자열 리터럴 목록**과의 비교 — 도출이 죽어 0건이 되면 이 비교는
        // 반드시 실패하므로 실질 하한이다. 32차 정정: 하한을 `assertNotEmpty` 계열로만 인정해
        // `assertEqualsCanonicalizing(['a','b','c'], $derived)` 형태를 하한 없음으로 잡았다.
        // 기대값이 **파생값**이면(스프레드·호출) 양변이 함께 죽을 수 있으므로 인정하지 않는다.
        '/assert(?:Same|Equals|EqualsCanonicalizing)\s*\(\s*\[\s*[\'"]/',

        // **도출 이름의 실재 정박**. 호출처 0건이 정상 종착지인 축(위임이 끝나면 호출처가 사라진다)
        // 은 개수 하한을 걸 수 없다. 그런 자리가 지켜야 하는 명제는 "모집단을 도출하는 **이름**이
        // 살아 있는가" 이고, `method_exists` 단언이 정확히 그것이다 — 이름이 바뀌면 red 다.
        // 34차: 성질은 하한과 같은데 표기가 달라 하한 없음으로 잡히던 자리를 인정한다.
        '/assertTrue\s*\(\s*method_exists\s*\(/',
    ];

    /**
     * 게이트 안의 어떤 단언도 실패 불가능한 명제가 아니다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects gate_assertions_are_all_falsifiable
     */
    public function test_no_gate_assertion_is_vacuous(): void
    {
        // 32차 ⚠️ — 게이트 테스트가 끌어 쓰는 `tests/Support/` 트레이트는 어느 스위트에도 없어
        // 모집단 밖이었다. 그 안에도 게이트가 실제로 실행하는 단언이 있으므로(도달 시험 하네스)
        // import 도출로 합류시킨다. 목록이 아니라 도출이라 쓰임이 바뀌면 자동으로 따라온다.
        $support = GateTestPopulation::gateSupportFiles();

        $this->assertNotEmpty($support,
            '게이트 테스트가 끌어 쓰는 지원 파일이 0건 도출됐다 — import 도출이 죽었는지 먼저 '
            .'확인해야 한다');

        $files = GateTestPopulation::gateSelectedFiles() + $support;

        $this->assertNotEmpty($files,
            '게이트 선택 집합이 0건이다 — 모집단 도출이 죽었는지 먼저 확인해야 한다 '
            .'("위반 0" 과 "검사 0" 은 다른 명제다)');

        $violations = [];

        foreach ($files as $relative => $absolute) {
            // 35차 근본 조치 J — 종전에는 이 가드 자신을 `EXEMPTIONS` 로 **파일 통째** 면제했다.
            // 그 사유는 "합성 표본을 담고 있어서" 였는데 표본은 nowdoc 리터럴이므로 본문만
            // 걷어내면 사라진다. 즉 면제는 판정이 heredoc 을 보지 못한 데서 왔을 뿐인데, 파일
            // 단위라 **그 파일의 새 무효 단언이 영구히 판정 밖**이었다. 표본을 걷어내고 면제를
            // 없앤다 — 이제 이 가드도 다른 파일과 같은 판정을 받는다.
            $code = $this->judgedCode(File::get($absolute));

            foreach ($this->vacuousAssertionsIn($code) as $finding) {
                $violations[] = "{$relative}: {$finding}";
            }
        }

        $this->assertSame([], $violations,
            '실패할 수 없는 단언이 게이트 안에 있습니다 — 그 단언의 초록은 아무것도 뜻하지 않고, '
            .'그것이 S17 이후 회차가 반복된 기전이다. 실질 하한으로 바꿀 것 — 정당한 동결 단언이면 '
            .'비어 있지 않은 상수와의 비교 형태로 적으면 판정식이 하한으로 인정한다 '
            .'(파일 단위 면제는 35차에 폐기됐다. 그 면제가 대상 파일의 미래 위반까지 덮었기 때문이다).');
    }

    /**
     * 모집단을 디스크에서 도출하는 테스트는 하한 단언을 갖는다.
     *
     * 하한이 없으면 도출이 죽어 0건이 된 상태에서 그 테스트는 초록을 돌려준다 — 19차가 "가드가
     * 죽어 후보 0건" 으로, 31차가 근본 C 의 무하한으로 겪은 형태다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects derivation_based_gate_tests_declare_a_population_floor
     */
    public function test_every_derivation_based_test_has_a_population_floor(): void
    {
        $files = GateTestPopulation::gateSelectedFiles();
        $inspected = 0;
        $floorless = [];

        foreach ($files as $relative => $absolute) {
            $code = $this->codeWithoutComments(File::get($absolute));

            // 32차 ⚠️ — 판정 단위가 **파일**이라 한 메서드에 하한이 하나라도 있으면 같은 파일의
            // 나머지 도출형 메서드가 전부 면제됐다. 실물 사례가 있었다(조각 규약 라우팅 가드의
            // 하한 없는 메서드 둘이 이웃 메서드의 `assertNotEmpty` 하나에 가려졌다). 도출도
            // 하한도 메서드가 지는 책임이므로 판정을 **메서드 단위**로 내린다.
            // 34차 ⚠️ — 의무가 발생하는 조건이 "도출식이 이 본문에 **적혀** 있는가" 였다. 도출을
            // private 헬퍼로 빼면 의무가 사라진다(실물 셋이 그 형태였고, 마침 하한을 갖고 있어
            // 오늘 결함이 0 이었을 뿐이다). 도출은 호출로도 일어나므로 **호출그래프 1단**까지
            // 본다 — 헬퍼가 도출하면 그 헬퍼를 부르는 테스트가 의무를 진다. 하한은 어느 쪽에
            // 있어도 된다(헬퍼가 자기 도출 직후 하한을 거는 형태가 오히려 정석이다).
            $helpers = GateTestPopulation::nonPublicMethodBodies($code);

            foreach (GateTestPopulation::testMethodBodies($code) as $method => $body) {
                $reachable = $this->bodyWithCalledHelpers($body, $helpers);

                if (! $this->derivesPopulationFromDisk($reachable)) {
                    continue;
                }

                $inspected++;

                if (! $this->declaresPopulationFloor($reachable, $code)) {
                    $floorless[] = "{$relative}::{$method}";
                }
            }
        }

        $this->assertGreaterThan(0, $inspected,
            '디스크에서 모집단을 도출하는 테스트가 0건이다 — DERIVATION_SIGNALS 가 낡아 판정식이 '
            .'구조적으로 아무것도 보지 못하는 상태인지 먼저 확인해야 한다');

        $this->assertSame([], $floorless,
            '디스크에서 모집단을 도출하는데 하한 단언이 없는 테스트가 있습니다 — 도출이 죽어 0건이 '
            .'되면 그 테스트는 조용히 통과한다. assertNotEmpty 등으로 모집단 실재를 함께 단언해야 한다.');
    }

    /**
     * 모집단에 **주입한** 표본에 프로덕션 검사가 반응합니다 (32차 근본 조치 H).
     *
     * 아래 자기시험들은 판정식에 표본을 직접 넘긴다 — 그것만으로는 "판정식이 그 형태를 안다" 만
     * 증명되고 "가드가 그 형태를 검사한다" 는 증명되지 않는다(32차가 오라클 독립성 가드에서
     * 그 차이로 실물 사각 6파일을 찾았다). 표본을 모집단에 넣고 프로덕션 테스트를 그대로 돌려
     * 도달까지 확인한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects assertion_strength_guard_reacts_to_population_injected_samples
     */
    public function test_guard_reacts_to_samples_injected_into_the_population(): void
    {
        $vacuous = <<<'PHP'
        <?php
        class InjectedVacuousAssertionSample
        {
            public function test_population_is_sane(): void
            {
                $this->assertTrue(true, '방어');
            }
        }
        PHP;

        $solid = <<<'PHP'
        <?php
        class InjectedSolidAssertionSample
        {
            public function test_population_is_sane(): void
            {
                $this->assertGreaterThan(0, $inspected, '모집단 도출이 죽었는지 확인');
            }
        }
        PHP;

        $production = function (): void {
            $this->test_no_gate_assertion_is_vacuous();
        };

        $this->assertGuardRejects('vacuous_assertion_sample.php', $vacuous, $production,
            '실패할 수 없는 단언 — 게이트에 들어오면 그 초록은 아무것도 뜻하지 않는다');

        $this->assertGuardAccepts('solid_assertion_sample.php', $solid, $production,
            '실질 하한 — 도출이 죽으면 red 가 되는 정상 형태');
    }

    /**
     * 판정식이 지난 회차의 실제 무효 형태를 잡는지 합성 표본으로 시험합니다.
     *
     * 표본은 발견 회차의 실물 형태를 그대로 쓴다 — 판정식이 넓어졌는지는 이 표본이 답한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects assertion_strength_self_test_detects_known_vacuous_shapes
     */
    public function test_predicate_detects_known_vacuous_shapes(): void
    {
        $samples = [
            '31차 근본 C 실물' => '$this->assertGreaterThanOrEqual(0, $inspected);',
            '리터럴 방어' => '$this->assertTrue(true, "방어");',
            'false 방어' => '$this->assertFalse(false);',
            '리터럴 자기비교' => '$this->assertSame(null, null);',
            '리터럴 non-null' => '$this->assertNotNull(42);',
            '음수 하한' => '$this->assertGreaterThan(-1, $count);',
        ];

        foreach ($samples as $label => $sample) {
            $this->assertNotSame([], $this->vacuousAssertionsIn($sample),
                "무효 단언 형태를 놓칩니다 ({$label}) — 이 가드의 초록은 아무것도 뜻하지 않는다");
        }

        $compliant = [
            '실질 하한' => '$this->assertGreaterThan(0, $inspected);',
            '비어있지 않음' => '$this->assertNotEmpty($files, "모집단 도출이 죽었는지 확인");',
            '실질 개수' => '$this->assertCount(3, $roots);',
            '변수 비교' => '$this->assertSame($compiled, $resolved);',
            '하한 1 이상' => '$this->assertGreaterThanOrEqual(2, $pairs);',
        ];

        foreach ($compliant as $label => $sample) {
            $this->assertSame([], $this->vacuousAssertionsIn($sample),
                "정상 단언이 무효로 잡힙니다 ({$label}) — 과탐 가드는 무시당한다");
        }
    }

    /**
     * 하한 판정식이 하한 유무를 실제로 구별하는지 시험합니다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects assertion_strength_self_test_distinguishes_floor_presence
     */
    public function test_floor_predicate_distinguishes_presence(): void
    {
        $derivingWithoutFloor = <<<'PHP'
        <?php
        foreach (File::allFiles(base_path('app')) as $file) {
            $violations[] = $file;
        }
        $this->assertSame([], $violations);
        PHP;

        $derivingWithFloor = <<<'PHP'
        <?php
        $files = File::allFiles(base_path('app'));
        $this->assertNotEmpty($files, '모집단 도출이 죽었는지 확인');
        $this->assertSame([], $violations);
        PHP;

        $noDerivation = <<<'PHP'
        <?php
        $this->assertSame('from-markup', $this->inlinedText($layout));
        PHP;

        // 31차 실물 과탐 — 테스트가 만든 tmp 픽스처 스캔. 여기서는 0건이 의도인 시나리오
        // (마크업 전용 상황을 세우려고 `.json` 을 일부러 두지 않는다) 라 하한 의무가 없다.
        $tmpFixtureScan = <<<'PHP'
        <?php
        $definitions = $this->service->collect(File::glob($this->tmp.'/module/*.json') ?: []);
        $this->assertSame(1, $stats['registered']);
        PHP;

        $this->assertTrue($this->derivesPopulationFromDisk($derivingWithoutFloor),
            '디스크 도출 신호를 놓칩니다 — 하한 의무가 아무 파일에도 적용되지 않는다');
        $this->assertFalse($this->declaresPopulationFloor($derivingWithoutFloor),
            '하한이 없는 코드를 있다고 판정합니다');
        $this->assertTrue($this->declaresPopulationFloor($derivingWithFloor),
            '하한이 있는 코드를 없다고 판정합니다 (과탐)');
        $this->assertFalse($this->derivesPopulationFromDisk($noDerivation),
            '디스크 도출이 없는 테스트에 하한 의무를 씌웁니다 (과탐)');
        $this->assertFalse($this->derivesPopulationFromDisk($tmpFixtureScan),
            '테스트가 만든 tmp 픽스처 스캔에 하한 의무를 씌웁니다 — 0건이 의도인 시나리오를 쓸 수 없게 된다');
        $this->assertTrue($this->derivesPopulationFromDisk('$files = File::allFiles(base_path("app"));'),
            '저장소 스캔을 놓칩니다 — 하한 의무가 실제 코퍼스 축에 적용되지 않는다');
        $this->assertTrue($this->derivesPopulationFromDisk('$files = LayoutCorpus::scan(".json");'),
            '코퍼스 도출기 호출을 놓칩니다');

        // 31차 실물 과탐 2형태 — 판정을 표기에서 의미로 옮긴 근거를 표본으로 상주시킨다.
        // 이 둘을 놓치면 판정식이 다시 리터럴 표기 의존으로 되돌아갔다는 뜻이다.
        $constantFloor = '$this->assertGreaterThanOrEqual(self::CORPUS_MINIMUM, count($files), "모집단이 줄었습니다");';
        $emptyArrayFloor = '$this->assertNotSame([], $markupNames, "등록 레이아웃이 없습니다");';

        $this->assertTrue($this->declaresPopulationFloor($constantFloor),
            '상수 참조로 못박은 하한을 하한으로 인정하지 않습니다 — 코퍼스 게이트가 위반으로 잡힌다');
        $this->assertTrue($this->declaresPopulationFloor($emptyArrayFloor),
            '빈 배열 대조로 세운 하한을 인정하지 않습니다 — 서빙 등가성 테스트가 위반으로 잡힌다');
        $this->assertNotSame([], $this->vacuousAssertionsIn('$this->assertGreaterThanOrEqual(0, $n);'),
            '하한 판정을 넓히면서 리터럴 0 하한의 무효 판정까지 풀렸습니다');
    }

    /**
     * 이 가드 자신도 **면제 없이** 판정 대상이며, 표본은 판정을 오염시키지 않습니다 (35차 근본 조치 J).
     *
     * ## 왜 면제를 없앴는가
     *
     * 종전 `EXEMPTIONS` 는 이 가드 자신을 **파일 통째** 면제했다. 사유는 "무효 형태를 합성 표본
     * 문자열로 담고 있어서" 였는데, 표본은 nowdoc 리터럴이므로 본문만 걷어내면 사라진다 — 즉
     * 면제의 원인은 이 파일의 특수성이 아니라 **판정이 heredoc 을 보지 못한 것**이었다. 그런데
     * 면제가 파일 단위라, 그 파일에 새로 생긴 진짜 무효 단언은 영구히 판정 밖이었다.
     *
     * 35차 프로브가 이 자리를 스스로 찾아냈다 — "판정 술어로 소비되는 손 열거" 를 저장소에서
     * 도출하자 `EXEMPTIONS` 와 오라클 가드의 `SAMPLE_BEARING_FILES` 가 같은 형태로 함께 나왔다.
     * 사람이 회차마다 한 자리씩 찾던 것을 기계가 한 번에 낸 것이고, 그것이 근본 조치 J 의 요지다.
     *
     * 이 테스트는 치환이 성립하는지 **양방향**으로 관측한다 — 표본이 살아 있고(원본에서는 무효
     * 단언이 잡힌다), 그 표본이 판정에는 들어오지 않는다(제거 후 0건).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects assertion_strength_guard_judges_itself_without_file_exemption
     */
    public function test_this_guard_is_judged_without_exemption(): void
    {
        $relative = GateTestPopulation::relativePath(__FILE__);

        $this->assertArrayHasKey($relative, GateTestPopulation::gateSelectedFiles(),
            '이 가드가 게이트 모집단 밖에 있습니다 — 자기 판정이 성립하지 않는다');

        $this->assertNotSame([], $this->vacuousAssertionsIn($this->codeWithoutComments(File::get(__FILE__))),
            '원본에 무효 단언 형태(합성 표본)가 없습니다 — 표본이 사라졌다면 판정식의 검출력을 '
            .'증명할 수단이 없어진 것이므로 먼저 표본을 되살려야 한다');

        $this->assertSame([], $this->vacuousAssertionsIn($this->judgedCode(File::get(__FILE__))),
            '표본 본문을 걷어낸 뒤에도 이 가드에 무효 단언이 남아 있습니다 — 표본이 아니라 실제 '
            .'단언이 실패 불가능하다는 뜻이다');
    }

    /**
     * 코드에서 실패 불가능한 단언을 찾습니다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return array<int, string> 위반 설명 목록
     */
    private function vacuousAssertionsIn(string $code): array
    {
        $findings = [];

        foreach (self::VACUOUS_PATTERNS as $pattern => $explanation) {
            if (preg_match_all($pattern, $code, $matches) >= 1) {
                foreach ($matches[0] as $occurrence) {
                    $findings[] = trim($occurrence).' → '.$explanation;
                }
            }
        }

        // 같은 변수를 양변에 두는 비교 (assertSame($x, $x))
        if (preg_match_all(
            '/assert(?:Same|Equals|NotSame|NotEquals)\s*\(\s*\$(\w+)\s*,\s*\$(\w+)\s*[,)]/',
            $code,
            $pairs,
            PREG_SET_ORDER
        ) >= 1) {
            foreach ($pairs as $pair) {
                if ($pair[1] === $pair[2]) {
                    $findings[] = $pair[0].' → 같은 변수를 양변에 둔 비교는 항등식이다';
                }
            }
        }

        return $findings;
    }

    /**
     * 메서드 본문에 그 본문이 부르는 비공개 헬퍼의 본문을 이어 붙입니다 (호출그래프 1단).
     *
     * 1단만 보는 이유: 도출과 하한은 같은 자리에서 일어나는 짝이라 한 단계 안에 함께 있다.
     * 무한 확장하면 파일 전체가 한 단위로 뭉개져 32차가 내린 판정 입도가 되돌아간다.
     *
     * @param  string  $body  테스트 메서드 본문
     * @param  array<string, string>  $helpers  비공개 헬퍼 이름 → 본문
     * @return string 본문 + 호출된 헬퍼 본문
     */
    private function bodyWithCalledHelpers(string $body, array $helpers): string
    {
        $reachable = $body;

        foreach ($helpers as $name => $helperBody) {
            if (preg_match('/\$this->'.preg_quote($name, '/').'\s*\(/', $body) === 1) {
                $reachable .= "\n".$helperBody;
            }
        }

        return $reachable;
    }

    private function derivesPopulationFromDisk(string $code): bool
    {
        // 모집단 도출기는 그 자체가 저장소 스캔이다 (호출만으로 의무 성립).
        foreach (self::POPULATION_DERIVERS as $deriver) {
            if (str_contains($code, $deriver)) {
                return true;
            }
        }

        // 직접 스캔은 그 대상이 저장소일 때만 의무 대상 — 합성 tmp 픽스처는 0건이 의도일 수 있다.
        // 판정은 **호출 단위**로 한다. 파일 안에 `base_path(` 가 어딘가 있는지로 보면(문자열 공존)
        // 무관한 자리의 등장 때문에 tmp 픽스처 스캔이 의무 대상으로 끌려온다 — 근본 C 명제1 이
        // 그 형태로 약해져 있던 자리라 여기서 되풀이하지 않는다.
        //
        // 34차 — 신호 목록이 상수로 선언돼 있는데 판정식이 같은 값을 정규식에 하드코딩해 두 벌이
        // 었다. 단언 문구가 가리키는 이름(`DERIVATION_SIGNALS`)과 실제 판정이 갈리면, 상수를 고쳐도
        // 검출력은 그대로다. 선언에서 도출한다.
        $callPattern = '/(?:'.implode('|', array_map(
            static fn (string $signal): string => preg_quote(rtrim($signal, '('), '/'),
            self::DERIVATION_SIGNALS
        )).')\s*\((.{0,200}?)\)/s';

        preg_match_all($callPattern, $code, $calls, PREG_SET_ORDER);

        foreach ($calls as $call) {
            if ($this->mentionsAnySignal($call[1], self::REPOSITORY_SCOPE_SIGNALS)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 코드가 모집단 하한을 단언하는지 판정합니다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return bool 하한 단언이 하나라도 있으면 true
     */
    private function declaresPopulationFloor(string $code, string $file = ''): bool
    {
        foreach (self::FLOOR_PATTERNS as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                return true;
            }
        }

        return $this->comparesAgainstNonEmptyConstant($code, $file);
    }

    /**
     * 기대값이 **비어 있지 않은 상수 배열**에서 온 비교인지 판정합니다 (실질 하한).
     *
     * 34차 — 하한 인정이 표기에 매여 있었다. 32차가 인라인 리터럴 배열(`assertSame(['a'], …)`)을
     * 실질 하한으로 인정했는데, 같은 성질을 **상수로** 표현한 형태(`$expected = self::LEDGER;`)는
     * 인정하지 않았다. 두 형태의 성질은 같다 — 도출이 죽어 0건이 되면 그 비교는 반드시 실패한다.
     * 표기가 아니라 성질로 판정한다.
     *
     * @param  string  $code  판정 단위 (메서드 본문 + 호출된 헬퍼)
     * @param  string  $file  파일 전체 코드 (상수 선언은 클래스 머리에 있다)
     * @return bool 비어 있지 않은 상수 배열과 비교하면 true
     */
    private function comparesAgainstNonEmptyConstant(string $code, string $file): bool
    {
        if ($file === '') {
            return false;
        }

        preg_match_all(
            '/assert(?:Same|Equals|EqualsCanonicalizing)\s*\(\s*(\$\w+|(?:self|static)::\w+)/',
            $code,
            $matches
        );

        foreach ($matches[1] ?? [] as $expected) {
            $constant = null;

            if (str_contains($expected, '::')) {
                $constant = substr($expected, strrpos($expected, ':') + 1);
            } elseif (preg_match('/\\'.$expected.'\s*=[^;]*(?:self|static)::(\w+)/', $code, $assignment) === 1) {
                // 상수를 변수에 받아 비교하는 형태 — 중간에 `array_keys(` 등이 끼어도 성질은 같다.
                $constant = $assignment[1];
            }

            if ($constant === null) {
                continue;
            }

            if (preg_match('/const\s+'.preg_quote($constant, '/').'\s*=\s*\[\s*[\'"]/', $file) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 문자열이 신호 중 하나를 담는지 판정합니다.
     *
     * @param  string  $haystack  검사 대상
     * @param  array<int, string>  $signals  신호 목록
     * @return bool 하나라도 담으면 true
     */
    private function mentionsAnySignal(string $haystack, array $signals): bool
    {
        foreach ($signals as $signal) {
            if (str_contains($haystack, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHP 코드에서 주석을 제거합니다 (토크나이저 — 정규식 제거기의 19차 사고 재발 방지).
     *
     * 규약을 설명하는 독블록이 위반으로 잡히면 저자가 설명을 지우게 되므로, 판정은 실행되는
     * 코드만 본다. 문자열 리터럴은 보존해야 합성 표본이 판정 대상으로 남는다.
     *
     * @param  string  $source  PHP 원본
     * @return string 주석이 제거된 코드
     */
    private function codeWithoutComments(string $source): string
    {
        if (! str_contains($source, '<?php')) {
            $source = "<?php\n".$source;
        }

        $kept = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kept[] = is_array($token) ? $token[1] : $token;
        }

        return implode('', $kept);
    }

    /**
     * 실제로 판정에 넣을 코드 — 주석과 **합성 표본 본문**을 함께 걷어냅니다 (35차 근본 조치 J).
     *
     * 표본이 리터럴에 담기므로 본문을 지우면 판정에서 사라지고, 그래서 파일 단위 면제가
     * 불필요해진다. 35차 실측 — 이 가드의 표본은 nowdoc 만이 아니라 **무효 형태를 설명하는
     * 문구**와 자기시험의 인라인 문자열에도 있었다(면제를 걷어내자 12건이 드러났다). 그래서
     * 제거기는 heredoc 전용이 아니라 {@see GateTestPopulation::codeWithoutLiteralBodies()} 다 —
     * 사본을 만들면 그 자리가 다음 회차의 검출물이 된다.
     *
     * @param  string  $source  PHP 소스
     * @return string 주석·표본 본문이 제거된 소스
     */
    private function judgedCode(string $source): string
    {
        return GateTestPopulation::codeWithoutLiteralBodies($this->codeWithoutComments($source));
    }
}
