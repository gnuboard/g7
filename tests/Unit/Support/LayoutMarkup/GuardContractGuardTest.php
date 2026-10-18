<?php

namespace Tests\Unit\Support\LayoutMarkup;

use Illuminate\Support\Facades\File;
use Tests\Support\GateTestPopulation;
use Tests\Support\InjectsGuardSamples;

/**
 * 가드의 품질 속성을 **전 가드에 전수 강제되는 계약**으로 집행합니다 (35차 근본 조치 J).
 *
 * ## 왜 이 가드가 있는가 — 서른다섯 회차를 한 장에 겹치면
 *
 * S17 이후 회차마다 "근본 원인" 진단이 나왔고 전부 달랐다. 그런데 조치가 **적용된 범위**를 보면
 * 골격이 하나로 겹친다 —
 *
 * | 회차 | 깨진 자리 | 조치가 적용된 범위 |
 * | --- | --- | --- |
 * | 28차 | 판정식이 표기(후행 슬래시) 의존 | 그 가드 1개 |
 * | 29차 | 단언이 항진명제 | 그 단언 2개 |
 * | 30~32차 | 오라클 · 게이트 소속 · 모집단 · 문지기 | 가드 3개씩 |
 * | 33·34차 | 판정 입도가 파일 단위 | 명제 1개 → 3개 |
 * | 35차 | 열거의 폐쇄가 단방향 | ? |
 *
 * **모든 조치가 「그 회차에 깨진 개체」에만 적용됐다.** 조치는 성질(모집단 하한 · 표기 비의존 ·
 * 판정 입도 · 도달 시험)로 표현되지만, 그 성질을 각 가드에 부여하는 일은 저자의 기억에 달려
 * 있었다. 그래서 성질을 아직 못 받은 개체가 다음 회차의 검출물이 된다 — 회차마다 진단이 달랐던
 * 이유는 매번 **다른 개체**가 걸렸기 때문이지 층이 달라서가 아니다.
 *
 * 34차의 근본 I 는 이 방향으로 한 발 갔다("하네스 사용자 **전량**이 판정 대상인가"). 그러나 그
 * 전수도 **속성 하나**에 대해서였고, 나머지 속성은 여전히 산발적이었다.
 *
 * ## 규율
 *
 * 가드의 품질 속성은 **등록부에 모으고, 등록부는 전 가드에 곱집합으로 집행**한다. 새 회차가 새
 * 축을 발견하면 등록부에 계약을 더하는 것으로 전 가드에 자동 적용된다 — 가드마다 손으로 심지
 * 않는다. 그리고 이미 다른 메타가드가 전수 집행 중인 계약도 등록부에 **집행자와 함께** 남긴다.
 * 축이 조용히 사라지는 것을 막는 것이 등록부의 두 번째 역할이다.
 *
 * ## 이 회차가 집행하는 계약
 *
 * - **C1 열거 폐쇄 방향** — 판정 술어로 소비되는 코어 표면 열거는 여집합을 잡는 폐쇄를 갖는다.
 *   35차 실측에서 이 판정식은 저장소를 훑어 위험 열거 5건을 냈고, 그중 하나
 *   (`AssertionStrengthGuardTest::EXEMPTIONS`)는 **사람의 전수조사가 놓친 자리**였다.
 * - **C2 면제 입도** — 면제는 파일을 통째로 검사에서 빼지 않는다.
 */
class GuardContractGuardTest extends LayoutMarkupTestCase
{
    use InjectsGuardSamples;

    /**
     * 가드 계약 등록부 — 계약 id → 설명·집행자.
     *
     * `self` 는 이 가드가 직접 집행한다는 뜻이고, 그 밖은 **다른 메타가드가 전수 집행 중**이라는
     * 선언이다. 후자는 집행자 실재를 이 가드가 확인한다 — 집행자가 사라지면 그 축이 조용히
     * 없어지는 것이 이 트랙이 반복해 온 형태이기 때문이다.
     *
     * **37차** — 자기집행분은 `method` 로 집행 메서드명을 함께 등재한다. 그 전에는 자기집행이
     * 집행자 실재 확인에서 빠져 있어(아래 판정의 `continue`), 이 독블록이 약속하는 폐쇄가
     * 위임분에만 성립했다.
     *
     * @var array<string, array{summary: string, enforcer: string, method?: string}>
     */
    private const CONTRACTS = [
        'enumeration_closure' => [
            'summary' => '판정 술어로 소비되는 코어 표면 열거는 여집합을 잡는 폐쇄를 갖는다',
            'enforcer' => 'self',
            'method' => 'test_every_predicate_enumeration_is_closed',
        ],
        'exemption_granularity' => [
            'summary' => '면제는 파일을 통째로 검사에서 빼지 않는다',
            'enforcer' => 'self',
            'method' => 'test_no_guard_skips_a_whole_file_by_exemption',
        ],
        'docblock_attribution' => [
            'summary' => '연속 독블록으로 앞 블록이 고아가 되지 않는다 (@effects 귀속 보존)',
            'enforcer' => 'self',
            'method' => 'test_no_docblock_is_orphaned_by_a_following_block',
        ],
        'population_floor' => [
            'summary' => '디스크에서 모집단을 도출하는 검사는 하한 단언을 갖는다',
            'enforcer' => AssertionStrengthGuardTest::class,
        ],
        'falsifiable_assertions' => [
            'summary' => '게이트 안의 어떤 단언도 실패 불가능한 명제가 아니다',
            'enforcer' => AssertionStrengthGuardTest::class,
        ],
        'self_test_reachability' => [
            'summary' => '합성 표본으로 판정식을 시험하는 가드는 도달 시험도 갖는다',
            'enforcer' => SelfTestDepthGuardTest::class,
        ],
        'gate_membership' => [
            'summary' => '게이트 소속은 네임스페이스의 우연이 아니라 매니페스트·필터·디스크 3축',
            'enforcer' => GateMembershipGuardTest::class,
        ],
        'oracle_independence' => [
            'summary' => '비교의 양변이 모두 검증 대상 진입점을 통과하지 않는다',
            'enforcer' => OracleIndependenceGuardTest::class,
        ],
        'convention_routing' => [
            'summary' => '레이아웃 소스 규약은 사본 없이 한 곳을 탄다',
            'enforcer' => FragmentSourceConventionRoutingGuardTest::class,
        ],
    ];

    /**
     * C1 폐쇄가 선언된 열거 → 그 폐쇄를 집행하는 테스트 메서드.
     *
     * 후보는 기계가 도출하고(사람이 고를 자리 없음), 분류만 선언한다. 선언이 참인지는 이 가드가
     * 확인한다 — 등재된 테스트가 실재하고, 그 본문이 실제로 그 열거를 대조하는지.
     *
     * @var array<string, string>
     */
    private const CLOSED_ENUMERATIONS = [
        'GateTestPopulation::GATE_FILTERS' => GateMembershipGuardTest::class
            .'::test_every_declared_php_test_file_is_reachable_by_gate_filters',
        'LayoutSourceConvention::DELEGATIONS' => OracleIndependenceGuardTest::class
            .'::test_core_selection_surface_is_fully_registered',
        'LayoutSourceConvention::NON_DELEGATING_SURFACE' => OracleIndependenceGuardTest::class
            .'::test_core_selection_surface_is_fully_registered',
    ];

    /**
     * 아직 폐쇄되지 않은 열거 → 사유 (래칫).
     *
     * 비어 있는 것이 정상 상태다. 폐쇄가 불가능한 자리가 나오면 사유와 함께 등재하되, 등재
     * 자체가 부채임을 잊지 않도록 이 목록은 늘지 않게 관리한다.
     *
     * @var array<string, string>
     */
    private const ENUMERATION_DEBT = [];

    /**
     * 판정 술어로 소비되는 코어 표면 열거는 전부 폐쇄돼 있거나 부채로 등재돼 있다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects predicate_enumerations_are_closed_or_declared_as_debt
     */
    public function test_every_predicate_enumeration_is_closed(): void
    {
        $files = GateTestPopulation::gateSelectedFiles() + GateTestPopulation::gateSupportFiles();

        $this->assertNotEmpty($files,
            '게이트 선택 집합이 0건이다 — 모집단 도출이 죽었는지 먼저 확인해야 한다');

        $symbols = $this->coreSurfaceSymbols();

        $this->assertNotEmpty($symbols['methods'],
            '코어 표면 심볼이 0건 도출됐다 — 도출이 죽으면 이 계약은 후보 0건으로 공허하게 참이 된다');

        $unclosed = [];
        $candidates = 0;

        foreach ($files as $relative => $absolute) {
            foreach ($this->predicateEnumerationsIn(File::get($absolute), $symbols) as $name) {
                $candidates++;

                $identifier = basename($relative, '.php').'::'.$name;

                if ($name === '(inline)') {
                    $unclosed[] = "{$relative}: 인라인 열거 — 이름이 없어 폐쇄를 걸 수 없다. 규약 "
                        .'정본(상수)으로 흡수해야 한다';

                    continue;
                }

                if (! isset(self::CLOSED_ENUMERATIONS[$identifier])
                    && ! isset(self::ENUMERATION_DEBT[$identifier])) {
                    $unclosed[] = "{$relative}: {$name}";
                }
            }
        }

        $this->assertGreaterThan(0, $candidates,
            '판정 술어로 소비되는 코어 표면 열거가 0건이다 — 판정 성질이 낡아 구조적으로 아무것도 '
            .'보지 못하는 상태인지 먼저 확인해야 한다');

        $this->assertSame([], $unclosed,
            '판정에 쓰이는 손 열거에 폐쇄가 없습니다 — 그 열거의 **여집합**이 곧 가드의 사각이 '
            .'되고, 그것이 S17 이후 회차가 반복된 기전이다(31차 모집단 · 34차 진입점 · 35차 규약 '
            .'표면이 전부 같은 형태였다). 여집합을 잡는 역방향 단언을 세워 CLOSED_ENUMERATIONS 에 '
            .'등재하거나, 불가능하면 ENUMERATION_DEBT 에 사유와 함께 등재해야 한다.');
    }

    /**
     * 폐쇄 선언이 참입니다 — 등재된 집행 테스트가 실재하고 그 열거를 실제로 대조합니다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects declared_enumeration_closures_are_verified_not_asserted
     */
    public function test_declared_closures_actually_exist(): void
    {
        $this->assertNotEmpty(self::CLOSED_ENUMERATIONS,
            '폐쇄 선언이 0건이다 — 등록부가 비면 위 계약이 공허하게 참이 된다');

        foreach (self::CLOSED_ENUMERATIONS as $enumeration => $enforcer) {
            [$class, $method] = explode('::', $enforcer);

            $this->assertTrue(method_exists($class, $method),
                "폐쇄 집행 테스트가 없습니다: {$enforcer} — {$enumeration} 의 폐쇄가 선언만 남았다");

            $body = File::get((new \ReflectionClass($class))->getFileName());
            [, $constant] = explode('::', $enumeration);

            $this->assertStringContainsString($constant, $body,
                "폐쇄 집행 테스트가 대상 열거를 참조하지 않습니다: {$enforcer} → {$enumeration}");
        }
    }

    /**
     * 면제 목록을 **파일 단위**로 소비하는 가드가 없습니다.
     *
     * ## 왜 입도가 계약인가
     *
     * 33·34차가 판정 입도를 파일에서 메서드로 내렸지만, 그 조치는 두 명제에만 적용됐다. 35차
     * 실측에서 **면제 쪽 입도**가 그대로 남아 있었고 — 오라클 독립성 가드와 무효 단언 가드가 자기
     * 파일을 통째로 건너뛰고 있었다 — 그 면제 안에 실제로 무효 형태 12건이 살아 있었다. 면제가
     * 파일 단위면 "표본을 담고 있어서" 라는 사유가 그 파일의 **모든 미래 위반**까지 덮는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects guard_exemptions_never_skip_a_whole_file
     */
    public function test_no_guard_skips_a_whole_file_by_exemption(): void
    {
        $files = GateTestPopulation::gateSelectedFiles() + GateTestPopulation::gateSupportFiles();

        $this->assertNotEmpty($files,
            '게이트 선택 집합이 0건이다 — 모집단 도출이 죽었는지 먼저 확인해야 한다');

        $violations = [];
        $inspected = 0;

        foreach ($files as $relative => $absolute) {
            $code = GateTestPopulation::codeWithoutLiteralBodies(File::get($absolute));

            foreach (GateTestPopulation::testMethodBodies($code) as $method => $body) {
                if (! str_contains($body, 'foreach')) {
                    continue;
                }

                $inspected++;

                if ($this->skipsWholeFileByExemption($body)) {
                    $violations[] = "{$relative}::{$method}()";
                }
            }
        }

        $this->assertGreaterThan(0, $inspected,
            '순회하는 테스트가 0건이다 — 본문 분해가 죽었는지 먼저 확인해야 한다');

        $this->assertSame([], $violations,
            '면제 목록을 보고 파일을 통째로 건너뛰는 자리가 있습니다 — 그 사유가 그 파일의 모든 '
            .'미래 위반까지 덮는다. 면제는 표본 구간 단위로 좁히거나(리터럴 본문 제거), 판정 '
            .'단위를 메서드로 내려야 한다.');
    }

    /**
     * 연속 독블록으로 앞 블록이 **고아**가 되는 자리가 없습니다.
     *
     * ## 왜 계약인가
     *
     * PHP 는 마지막 독블록만 심볼에 귀속시키므로, 독블록 둘이 붙어 있으면 앞 블록의 `@effects` 는
     * 아무 메서드에도 매달리지 않는다. 그러면 그 효과는 매니페스트 대조에서 **선언만 있고 집행은
     * 없는** 상태가 되고, 정작 독블록이 없어진 메서드는 커버리지에서 사라진다.
     *
     * 34차가 라우팅 가드에서 한 건, 35차가 사본 검출 가드에서 한 건 — **같은 형태가 두 회차
     * 연속**으로 나왔다. 개별 수정은 다음 자리를 막지 못하므로 계약으로 올려 전 가드에 전수
     * 적용한다. 이것이 근본 조치 J 의 요지다: 회차가 발견한 형태는 그 개체가 아니라 **등록부**에
     * 심는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects guard_docblocks_are_never_orphaned_by_a_following_block
     */
    public function test_no_docblock_is_orphaned_by_a_following_block(): void
    {
        $files = GateTestPopulation::gateSelectedFiles() + GateTestPopulation::gateSupportFiles();

        $this->assertNotEmpty($files,
            '게이트 선택 집합이 0건이다 — 모집단 도출이 죽었는지 먼저 확인해야 한다');

        $orphans = [];
        $inspected = 0;

        foreach ($files as $relative => $absolute) {
            $source = File::get($absolute);
            $inspected += substr_count($source, '/**');

            if (preg_match_all('#\*/\s*\n\s*/\*\*#', $source, $matches, PREG_OFFSET_CAPTURE) >= 1) {
                foreach ($matches[0] as $match) {
                    $line = substr_count(substr($source, 0, (int) $match[1]), "\n") + 1;
                    $orphans[] = "{$relative}:{$line}";
                }
            }
        }

        $this->assertGreaterThan(0, $inspected,
            '독블록이 0건 도출됐다 — 판정이 구조적으로 아무것도 보지 못하는 상태인지 확인해야 한다');

        $this->assertSame([], $orphans,
            '독블록 둘이 연속으로 붙어 있습니다 — PHP 는 마지막 것만 귀속시키므로 앞 블록의 '
            .'@effects 는 아무 메서드에도 매달리지 않고, 독블록을 잃은 메서드는 커버리지에서 '
            .'사라진다. 34·35차에 같은 형태가 연속으로 나왔다.');
    }

    /**
     * 등록부의 계약은 전부 집행자를 갖고, 그 집행자는 실재합니다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects guard_contract_registry_enforcers_exist
     */
    public function test_every_contract_has_a_living_enforcer(): void
    {
        $this->assertNotEmpty(self::CONTRACTS,
            '계약 등록부가 비었다 — 등록부가 비면 이 가드는 아무것도 집행하지 않는다');

        foreach (self::CONTRACTS as $id => $contract) {
            $this->assertNotSame('', trim($contract['summary']),
                "계약에 설명이 없습니다: {$id}");

            // **37차 — 자기집행도 집행자 실재를 확인한다.**
            //
            // 종전 이 자리는 `continue` 였다. 그래서 등록부가 스스로 약속한 "집행자가 사라지면
            // 그 축이 조용히 없어지는 것을 막는다" 가 **위임분에만** 성립했고, 자기집행 3종은
            // 집행 메서드를 지워도 계약이 초록으로 남았다 — 등록부가 자기 자신에게만 폐쇄를
            // 걸지 않은 상태다(22차 "감시자가 자기를 관측 밖에 둠" 의 등록부 판본).
            if ($contract['enforcer'] === 'self') {
                $this->assertArrayHasKey('method', $contract,
                    "자기집행 계약은 집행 메서드명을 등재해야 한다: {$id} — 이름이 없으면 "
                    .'집행자 실재를 기계가 확인할 수 없고, 그 축은 지워져도 초록이다');

                $this->assertTrue(method_exists(self::class, $contract['method']),
                    "자기집행 계약의 집행 메서드가 사라졌습니다: {$id} → {$contract['method']}()");
            }

            // **38차 정정 — 게이트 소속 확인이 위임분에만 걸려 있었다.**
            //
            // 37차가 자기집행의 «집행 메서드 실재» 를 닫으면서 그 직후 `continue` 를 뒀고, 그래서
            // 바로 아래의 «집행자가 게이트 안에 있는가» 는 위임 6종만 통과했다. 실질 위험은 없다
            // (자기집행의 집행자는 이 클래스 자신이고 게이트 필터에 걸린다). 문제는 **계약이
            // 스스로 약속한 축 하나가 자기에게만 적용되지 않은 상태** 라는 것이고, 그것이 22차
            // "감시자가 자기를 관측 밖에 둠" 의 세 번째 판본이다. 분기를 없애고 집행자 클래스를
            // 통일해 양쪽이 같은 검사를 받게 한다.
            $enforcer = $contract['enforcer'] === 'self' ? self::class : $contract['enforcer'];

            $this->assertTrue(class_exists($enforcer),
                "계약의 집행자가 사라졌습니다: {$id} → {$enforcer} — 축이 조용히 "
                .'없어지는 것이 이 트랙이 반복해 온 형태다');

            $this->assertArrayHasKey(
                GateTestPopulation::relativePath((new \ReflectionClass($enforcer))->getFileName()),
                GateTestPopulation::gateSelectedFiles(),
                "계약의 집행자가 게이트 밖에 있습니다: {$id} → {$enforcer} — 게이트 "
                .'밖 집행자는 종료 검증에서 돌지 않으므로 집행되지 않는 것과 같다');
        }
    }

    /**
     * 판정식 자기시험 — 합성 표본을 **모집단에 주입해** 프로덕션 검사가 반응하는지 봅니다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects guard_contract_guard_reacts_to_population_injected_samples
     */
    public function test_guard_reacts_to_samples_injected_into_the_population(): void
    {
        $unclosedEnumeration = <<<'PHP'
        <?php
        class InjectedUnclosedEnumerationSample
        {
            private const KNOWN_ENTRY_POINTS = [
                'layoutSourceCandidates(',
                'collectFragmentSources(',
            ];

            public function judge(string $code): bool
            {
                foreach (self::KNOWN_ENTRY_POINTS as $entry) {
                    if (str_contains($code, $entry)) {
                        return true;
                    }
                }

                return false;
            }
        }
        PHP;

        $this->assertGuardRejects(
            'injected_unclosed_enumeration.php',
            $unclosedEnumeration,
            fn () => $this->test_every_predicate_enumeration_is_closed(),
            '폐쇄 없는 판정 열거 — 여집합이 곧 사각이다'
        );

        $wholeFileSkip = <<<'PHP'
        <?php
        class InjectedWholeFileExemptionSample
        {
            private const EXEMPT_FILES = [
                'tests/Unit/Support/LayoutMarkup/SomeGuardTest.php' => '표본을 담고 있어서',
            ];

            public function test_something(): void
            {
                foreach ($this->files() as $relative => $absolute) {
                    if (isset(self::EXEMPT_FILES[$relative])) {
                        continue;
                    }
                }
            }
        }
        PHP;

        $this->assertGuardRejects(
            'injected_whole_file_exemption.php',
            $wholeFileSkip,
            fn () => $this->test_no_guard_skips_a_whole_file_by_exemption(),
            '파일 통째 면제 — 그 사유가 미래 위반까지 덮는다'
        );
    }

    /**
     * 면제 목록을 보고 파일을 통째로 건너뛰는 형태인지 판정합니다.
     *
     * @param  string  $body  테스트 메서드 본문 (리터럴 본문 제거됨)
     * @return bool 파일 단위 건너뛰기면 true
     */
    private function skipsWholeFileByExemption(string $body): bool
    {
        // 면제 조회 직후 `continue` 로 순회 단위를 통째로 버리는 형태.
        return preg_match(
            '/(?:isset|array_key_exists|in_array)\s*\([^)]*(?:EXEMPT|EXEMPTION|SAMPLE_BEARING|SKIP)[^)]*\)[^;{]*\{\s*continue\s*;/i',
            $body
        ) === 1;
    }

    /**
     * 코어(`app/`)가 선언한 표면 심볼을 디스크에서 도출합니다.
     *
     * @return array{methods: array<string, true>, classes: array<string, true>}
     */
    private function coreSurfaceSymbols(): array
    {
        $methods = [];
        $classes = [];

        foreach (File::allFiles(base_path('app')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = File::get($file->getPathname());

            if (preg_match_all('/(?:public|protected|private)(?:\s+static)?\s+function\s+(\w+)\s*\(/', $source, $m) >= 1) {
                foreach ($m[1] as $name) {
                    $methods[$name] = true;
                }
            }

            if (preg_match_all('/^\s*(?:final\s+|abstract\s+)?(?:class|trait|interface|enum)\s+(\w+)/m', $source, $m) >= 1) {
                foreach ($m[1] as $name) {
                    $classes[$name] = true;
                }
            }
        }

        return ['methods' => $methods, 'classes' => $classes];
    }

    /**
     * 코드에서 **판정 술어로 소비되는 코어 표면 열거**의 이름을 모읍니다.
     *
     * 문지기는 성질로 잡는다 — 열거가 판정(`in_array`·`str_contains` 순회·정규식 조립)에 쓰이면
     * 그 여집합이 곧 사각이지만, 케이스 공급에 쓰이면 짧아도 덜 시험할 뿐이고 그 하한은 다른
     * 계약(`population_floor`)이 이미 집행한다.
     *
     * @param  string  $source  PHP 소스
     * @param  array{methods: array<string, true>, classes: array<string, true>}  $symbols  코어 심볼
     * @return array<int, string> 상수명 (인라인이면 `(inline)`)
     */
    private function predicateEnumerationsIn(string $source, array $symbols): array
    {
        $code = GateTestPopulation::codeWithoutHeredocBodies($source);
        $found = [];

        if (preg_match_all('/const\s+(\w+)\s*=\s*\[(.*?)\];/s', $code, $matches, PREG_SET_ORDER) >= 1) {
            foreach ($matches as $match) {
                if (! $this->hasCoreSurfaceElement($match[2], $symbols)) {
                    continue;
                }

                foreach ($this->windowsAround($code, '::'.$match[1]) as $window) {
                    if ($this->consumedAsPredicate($window)) {
                        $found[] = $match[1];

                        break;
                    }
                }
            }
        }

        $withoutConsts = (string) preg_replace('/const\s+\w+\s*=\s*\[.*?\];/s', '', $code);

        if (preg_match_all(
            '/\[\s*((?:\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*,?\s*){2,})\]/s',
            $withoutConsts,
            $inline,
            PREG_SET_ORDER
        ) >= 1) {
            foreach ($inline as $match) {
                if (! $this->hasCoreSurfaceElement($match[1], $symbols)) {
                    continue;
                }

                $position = strpos($withoutConsts, $match[0]);
                $window = $position === false
                    ? $match[0]
                    : substr($withoutConsts, max(0, $position - 160), strlen($match[0]) + 480);

                if ($this->consumedAsPredicate($window)) {
                    $found[] = '(inline)';
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * 배열 본문에 코어 표면을 가리키는 원소가 있는지 판정합니다.
     *
     * @param  string  $body  배열 리터럴 본문
     * @param  array{methods: array<string, true>, classes: array<string, true>}  $symbols  코어 심볼
     * @return bool 하나라도 있으면 true
     */
    private function hasCoreSurfaceElement(string $body, array $symbols): bool
    {
        if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\"/", $body, $matches, PREG_SET_ORDER) < 1) {
            return false;
        }

        foreach ($matches as $match) {
            $element = trim($match[1] !== '' ? $match[1] : ($match[2] ?? ''));

            if ($element === '') {
                continue;
            }

            // `Class::method(` 는 **그 클래스가 코어 소유일 때만** 코어 표면이다. 프레임워크
            // 파사드(`File::get(`)는 호출 신호이지 코어 표면 열거가 아니고, 우연히 같은 이름의
            // 메서드가 app/ 에 있다는 이유로 후보가 되면 판정이 과탐으로 무너진다.
            if (preg_match('/^(\w+)::/', $element, $prefix) === 1 && ! isset($symbols['classes'][$prefix[1]])) {
                continue;
            }

            if (preg_match('/(?:^|->|::)(\w+)\($/', $element, $method) === 1
                && isset($symbols['methods'][$method[1]])) {
                return true;
            }

            if (preg_match('/^\w+$/', $element) === 1 && isset($symbols['classes'][$element])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 열거가 판정 술어로 소비되는지 판정합니다.
     *
     * @param  string  $window  열거 참조 지점 주변 코드
     * @return bool 판정에 쓰이면 true
     */
    private function consumedAsPredicate(string $window): bool
    {
        foreach ([
            'in_array(', 'str_contains(', 'str_starts_with(', 'str_ends_with(',
            'implode(', 'preg_match', 'isset(', 'array_key_exists(',
        ] as $signal) {
            if (str_contains($window, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 코드에서 이름이 등장하는 지점들의 주변 창을 모읍니다.
     *
     * @param  string  $code  PHP 코드
     * @param  string  $needle  찾을 표기
     * @return array<int, string> 주변 코드 조각
     */
    private function windowsAround(string $code, string $needle): array
    {
        $windows = [];
        $offset = 0;

        while (($position = strpos($code, $needle, $offset)) !== false) {
            $windows[] = substr($code, max(0, $position - 320), 640);
            $offset = $position + strlen($needle);
        }

        return $windows;
    }
}
