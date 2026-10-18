<?php

namespace Tests\Unit\Support\LayoutMarkup;

use Illuminate\Support\Facades\File;
use Tests\Support\GateTestPopulation;
use Tests\Support\InjectsGuardSamples;

/**
 * 가드의 자기시험이 **프로덕션 검사에 도달하는지** 잠급니다 (32차 근본 조치 H).
 *
 * ## 왜 이 가드가 있는가 — 서른두 회차의 공통 골격
 *
 * S17 이후 회차마다 "근본 원인" 진단이 나왔고 전부 달랐다. 21차 "종료 조건이 반증 불가능",
 * 22차 "감시자가 자기를 관측 밖에 둠", 23차 "종료 절차 부재", 30차 "조치를 검증하는 방법이
 * 조치와 같은 입도", 31차 "손으로 쓴 열거로 열린 세계를 판정". 각 진단은 그 회차가 깨진 자리를
 * 정확히 짚었지만, **다음 회차는 다른 자리에서 같은 방식으로 깨졌다.**
 *
 * 회차들을 겹치면 남는 골격이 하나다. 가드는 네 단이다 —
 * `모집단 도출 → 문지기 → 판정식 → 단언`. 그런데 **자기시험(변이검증)이 언제나 3단에만 표본을
 * 주입했다.** 표본을 판정식에 직접 넘기므로, 자기시험이 증명하는 명제는 "판정식이 그 형태를
 * 안다" 뿐이고 "가드가 그 형태를 검사한다" 는 누구도 증명하지 않는다.
 *
 * | 회차 | 실제로 깨진 단 | 자기시험이 시험한 단 | 결과 |
 * | --- | --- | --- | --- |
 * | 29차 | ④ 단언이 항진명제 | ③ | 초록인 채 통과 |
 * | 31차 | ① 모집단이 손 열거 | ③ | 초록인 채 통과 |
 * | 32차 | ② 문지기가 대입문 표기 의존 | ③ | 초록인 채 통과 |
 *
 * 32차 실측으로 게이트의 자기시험 **넷이 전부** 이 형태였다. 즉 검출력을 더 높이는 조치로는
 * 끝나지 않는다 — 깨질 수 있는 단이 넷인데 시험받는 단이 하나면, 남은 셋이 다음 회차의
 * 검출물이 된다. 그래서 규율을 바꾼다.
 *
 * ## 규율
 *
 * 합성 표본으로 판정식을 시험하는 가드는, **그 표본이 프로덕션 검사에 도달하는지도 함께
 * 시험해야 한다.** 도달 시험의 방법은 {@see InjectsGuardSamples} 가 제공한다 — 표본을 모집단에
 * 넣고 프로덕션 테스트 메서드를 그대로 돌려 red/green 을 확인한다.
 *
 * 판정은 닫힌 세계다. 비공개 메서드 목록은 리플렉션이, 하네스 표면도 리플렉션이, 게이트 모집단은
 * `phpunit.xml` × `--filter` 가 준다. 사람이 고를 자리가 없다.
 */
class SelfTestDepthGuardTest extends LayoutMarkupTestCase
{
    use InjectsGuardSamples;

    /**
     * 프로덕션 검사임을 알리는 신호 — 실제 세계를 읽는 호출.
     *
     * 이 게이트에서 모집단은 {@see GateTestPopulation} 이 정본이고, 그 밖의 디스크 도출은
     * `File` 파사드가 정본이다(파사드 강제는 audit 이 별도로 지킨다). 둘 중 하나를 부르면
     * 그 메서드는 합성 입력이 아니라 실물을 검사한다.
     *
     * @var array<int, string>
     */
    private const PRODUCTION_SIGNALS = [
        'GateTestPopulation::',
        'File::',
    ];

    /**
     * 자기시험임을 알리는 신호 — **PHP 코드를 데이터로 담은 리터럴**.
     *
     * 판정식을 합성 입력으로 시험하는 메서드는 검사 대상 코드를 문자열로 들고 있다. 그것이
     * "실물을 읽는 검사" 와 "합성 표본을 먹이는 시험" 을 가르는 신호다. 픽스처를 쓰는 Feature
     * 테스트는 마크업·JSON 을 담지 PHP 를 담지 않으므로 여기 걸리지 않는다(과탐 반례로 상주).
     *
     * @var array<int, string>
     */
    private const PHP_AS_DATA_SIGNALS = [
        "<<<'PHP'",
        '<<<PHP',
        "'<?php",
        '"<?php',
        "'\$this->assert",
        '"$this->assert',
    ];

    /**
     * 합성 표본으로 판정식을 시험하는 가드는 도달 시험도 갖는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects guard_self_tests_prove_samples_reach_the_production_check
     */
    public function test_every_predicate_self_test_also_proves_reachability(): void
    {
        // 33차 ⚠️F3 — 자기시험이 판정식을 직접 부르는 우회는 `tests/Support/` 의 공유 헬퍼로도
        // 이사할 수 있다(헬퍼가 판정식을 직접 불러 주면 테스트는 깊어 보인다). 지원 파일도 판정
        // 대상에 합류시킨다 — 목록은 import 도출이라 손 열거가 아니다.
        $files = GateTestPopulation::gateSelectedFiles() + GateTestPopulation::gateSupportFiles();

        $this->assertNotEmpty($files,
            '게이트 선택 집합이 0건이다 — 모집단 도출이 죽었는지 먼저 확인해야 한다');

        $inspected = 0;
        $shallow = [];

        foreach ($files as $relative => $absolute) {
            $verdict = $this->selfTestDepthVerdict(File::get($absolute));

            if ($verdict === null) {
                continue;
            }

            $inspected++;

            if ($verdict === false) {
                $shallow[] = $relative;
            }
        }

        $this->assertGreaterThan(0, $inspected,
            '합성 표본으로 판정식을 시험하는 가드가 0건이다 — 판정 신호가 낡아 구조적으로 '
            .'아무것도 보지 못하는 상태인지 먼저 확인해야 한다');

        $this->assertSame([], $shallow,
            '자기시험이 판정식에만 표본을 주고 프로덕션 검사에 도달하는지는 시험하지 않습니다 — '
            .'그러면 문지기·모집단이 표본을 걸러내도 자기시험은 초록이고, 그것이 S17 이후 서른두 '
            .'회차가 반복된 기전이다. '.InjectsGuardSamples::class.' 의 assertGuardRejects()/'
            .'assertGuardAccepts() 로 도달 시험을 함께 세워야 한다.');
    }

    /**
     * 판정식이 얕은 자기시험과 깊은 자기시험을 구별하는지 시험합니다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects self_test_depth_predicate_distinguishes_shallow_from_deep
     */
    public function test_predicate_distinguishes_shallow_from_deep_self_tests(): void
    {
        $this->assertFalse($this->selfTestDepthVerdict($this->shallowGuardSource()),
            '얕은 자기시험(판정식 직접 호출, 도달 시험 없음)을 놓칩니다 — 이 가드의 초록은 '
            .'아무것도 뜻하지 않는다');

        $this->assertTrue($this->selfTestDepthVerdict($this->deepGuardSource()),
            '도달 시험을 갖춘 가드를 위반으로 잡습니다 (과탐 가드는 무시당한다)');

        $this->assertNull($this->selfTestDepthVerdict($this->productionOnlyGuardSource()),
            '자기시험이 없는 프로덕션 전용 검사를 판정 대상으로 삼습니다 — 판정 범위가 넓어졌다');
    }

    /**
     * 모집단에 **주입한** 표본에 이 가드 자신이 반응합니다 (자기 규율 적용).
     *
     * 이 가드가 요구하는 도달 시험을 이 가드도 갖는다. 갖지 않으면 위 판정식이 자기를 반려한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects self_test_depth_guard_reacts_to_population_injected_samples
     */
    public function test_guard_reacts_to_samples_injected_into_the_population(): void
    {
        $production = function (): void {
            $this->test_every_predicate_self_test_also_proves_reachability();
        };

        $this->assertGuardRejects('shallow_guard_sample.php', $this->shallowGuardSource(), $production,
            '얕은 자기시험 — 판정식에만 표본을 주고 도달은 시험하지 않는 가드');

        $this->assertGuardAccepts('deep_guard_sample.php', $this->deepGuardSource(), $production,
            '깊은 자기시험 — 표본이 프로덕션 검사에 도달하는지 함께 시험하는 가드');
    }

    /**
     * 34차 자기시험 — 문지기가 걸러낸 하네스 사용자를 근본 조치 I 가 잡는다.
     *
     * 표본은 도달 시험 하네스를 쓰지만 프로덕션 신호도 자기 비공개 판정식도 없어 문지기에서
     * 탈락한다. 33차까지의 형태(`inspected > 0`)라면 이 표본이 모집단에 있어도 전건 초록이다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects gatekeeper_coverage_guard_detects_hidden_harness_user
     */
    public function test_self_rejects_a_harness_user_hidden_from_the_gatekeeper(): void
    {
        $production = function (): void {
            $this->test_every_reachability_harness_user_is_judged();
        };

        $this->assertGuardRejects(
            'harness_user_hidden_from_gatekeeper.php',
            <<<'PHP'
            <?php

            class HarnessUserHiddenFromGatekeeperSample
            {
                public function test_guard_reacts_to_injected_samples(): void
                {
                    $this->assertGuardRejects('x.php', '<?php // 위반', fn () => null, '표본');
                }
            }
            PHP,
            $production,
            '도달 시험은 갖췄으나 문지기가 걸러내는 가드 (34차 실물 형태)',
        );

        $this->assertGuardAccepts(
            'harness_user_visible_to_gatekeeper.php',
            <<<'PHP'
            <?php

            class HarnessUserVisibleToGatekeeperSample
            {
                public function test_no_violation_in_population(): void
                {
                    foreach (GateTestPopulation::gateSelectedFiles() as $absolute) {
                        $this->assertSame([], $this->violationsIn(File::get($absolute)));
                    }
                }

                public function test_guard_reacts_to_injected_samples(): void
                {
                    $this->assertGuardRejects('x.php', '<?php // 위반', fn () => null, '표본');
                }

                private function violationsIn(string $code): array
                {
                    return [];
                }
            }
            PHP,
            $production,
            '문지기가 보는 정상 가드 (과탐 반례)',
        );
    }

    /**
     * 한 가드 소스의 자기시험 깊이를 판정합니다.
     *
     * @param  string  $source  가드 테스트 파일 소스
     * @return bool|null 판정 대상이 아니면 null, 깊으면 true, 얕으면 false
     */
    private function selfTestDepthVerdict(string $source): ?bool
    {
        $code = $this->codeWithoutComments($source);
        $methods = GateTestPopulation::testMethodBodies($code);

        if ($methods === []) {
            return null;
        }

        $nonPublic = $this->nonPublicMethodNames($code);

        if ($nonPublic === []) {
            return null;
        }

        // 34차 근본 조치 I — 신호를 **파일 전체**에서 찾는다.
        //
        // 33차까지 이 문지기는 세 조건을 전부 `test_` 메서드 **본문 문자열**에서 요구했다. 그래서
        // 표본을 private 헬퍼로 빼기만 하면(이 가드 자신이 `shallowGuardSource()` 등으로 그렇게
        // 했다) 파일이 통째로 `null` 이 됐다. 실측: 게이트 50파일 중 판정 대상이 3건이었고, 판정
        // 밖에 **이 가드 자신**과 33차가 강화한 조각 규약 라우팅 가드가 있었다. 독블록이 "갖지
        // 않으면 판정식이 자기를 반려한다" 고 적은 그 명제가 성립한 적이 없었다.
        //
        // 자기시험이 이를 못 잡는 이유는 구조적이다 — **표본은 저자가 만들므로 언제나 문지기의
        // 통과 조건을 만족한다.** 그래서 표본을 아무리 깊이 주입해도(32차 근본 H) 문지기의 좁음은
        // 드러나지 않는다. 관측해야 하는 것은 통과분이 아니라 **탈락분**이고, 그 명제가
        // {@see test_every_reachability_harness_user_is_judged()} 다.
        $hasProduction = $this->mentionsAny($code, self::PRODUCTION_SIGNALS);

        $hasPredicateSelfTest = $this->mentionsAny($code, self::PHP_AS_DATA_SIGNALS)
            && $this->callsAnyOwnMethodAnywhere($code, $methods, $nonPublic);

        if (! $hasProduction || ! $hasPredicateSelfTest) {
            return null;
        }

        return $this->mentionsAny($code, $this->reachabilityHarnessNames());
    }

    /**
     * 파일 어딘가에서 자기 비공개 메서드를 부르는지 판정합니다.
     *
     * 표본 생성기를 헬퍼로 뺀 가드는 `test_` 본문에서 그 헬퍼를 부르고, 헬퍼는 다시 판정식을
     * 부른다. 어느 쪽이든 "자기 판정식을 자기가 시험한다" 는 사실은 같으므로 파일 단위로 본다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @param  array<string, string>  $methods  테스트 메서드 본문
     * @param  array<int, string>  $nonPublic  비공개 메서드 이름
     * @return bool 하나라도 부르면 true
     */
    private function callsAnyOwnMethodAnywhere(string $code, array $methods, array $nonPublic): bool
    {
        if ($this->callsAnyOwnMethod($code, $nonPublic)) {
            return true;
        }

        foreach ($methods as $body) {
            if ($this->callsAnyOwnMethod($body, $nonPublic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 도달 시험 하네스를 쓰는 게이트 파일은 **전부** 이 가드의 판정 대상이다 (근본 조치 I).
     *
     * ## 왜 이 명제인가 — 서른네 회차가 반복한 골격의 다음 층
     *
     * 회차마다 새 결함 형태를 판정식으로 만들었지만, 그 판정식을 태울 **입구(문지기)를 다시
     * 표기로 좁혔다**. 그리고 자기시험 표본은 저자가 만들므로 그 좁은 입구를 언제나 통과한다 —
     * 즉 문지기가 무엇을 떨어뜨리는지는 어떤 자기시험으로도 드러나지 않는다. 29차 ④ 단언 ·
     * 31차 ① 모집단 · 32차 ② 문지기 · 33차 판정 입도가 전부 이 형태였고, 32차가 표본을 모집단
     * 입구로 내린 뒤에도 34차에 같은 층이 다시 뚫렸다.
     *
     * 그래서 관측 대상을 **통과분에서 탈락분으로** 옮긴다. 하한을 `inspected > 0`(절대수)에서
     * **"기계로 도출한 후보 집합을 검사 집합이 덮는가"** 로 바꾸면, 문지기가 좁아지는 순간
     * 그 좁음 자체가 red 다. 후보 도출은 리플렉션이라 사람이 고를 자리가 없다 —
     * {@see InjectsGuardSamples} 의 메서드 이름을 쓰는 파일이 곧 "판정식 자기시험을 가진 가드" 다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects gatekeeper_rejections_are_observed_not_assumed
     */
    public function test_every_reachability_harness_user_is_judged(): void
    {
        $files = GateTestPopulation::gateSelectedFiles() + GateTestPopulation::gateSupportFiles();

        $this->assertNotEmpty($files,
            '게이트 선택 집합이 0건이다 — 모집단 도출이 죽었는지 먼저 확인해야 한다');

        $harnessNames = $this->reachabilityHarnessNames();
        $harnessUsers = [];
        $unjudged = [];

        foreach ($files as $relative => $absolute) {
            $source = File::get($absolute);

            // 하네스 **정의**(트레이트 자신)는 사용자가 아니다.
            if (str_contains($source, 'trait InjectsGuardSamples')) {
                continue;
            }

            if (! $this->mentionsAny($source, $harnessNames)) {
                continue;
            }

            $harnessUsers[] = $relative;

            if ($this->selfTestDepthVerdict($source) === null) {
                $unjudged[] = $relative;
            }
        }

        $this->assertNotEmpty($harnessUsers,
            '도달 시험 하네스를 쓰는 가드가 0건이다 — 하네스 이름이 바뀌어 후보 도출이 죽었는지 '
            .'먼저 확인해야 한다');

        $this->assertSame([], $unjudged,
            '도달 시험을 갖추고도 이 가드의 **판정 대상 밖**에 있는 파일이 있습니다 — 문지기가 '
            .'좁아 그 파일을 걸러낸다는 뜻이고, 그러면 그 가드의 자기시험이 사라져도 red 가 '
            .'뜨지 않는다. 판정 대상 여부는 표기가 아니라 성질로 정해져야 한다.');
    }

    /**
     * 도달 시험 하네스가 제공하는 메서드 이름 — 리플렉션 도출(열거 아님).
     *
     * @return array<int, string> 호출 표기 (`->name(` 형태)
     */
    private function reachabilityHarnessNames(): array
    {
        $names = [];

        foreach ((new \ReflectionClass(InjectsGuardSamples::class))->getMethods() as $method) {
            $names[] = '->'.$method->getName().'(';
        }

        return $names;
    }

    /**
     * 클래스가 선언한 비공개 메서드 이름을 모읍니다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return array<int, string> 메서드 이름
     */
    private function nonPublicMethodNames(string $code): array
    {
        preg_match_all('/(?:private|protected) function (\w+)\s*\(/', $code, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * 본문이 같은 클래스의 비공개 메서드를 부르는지 판정합니다.
     *
     * @param  string  $body  메서드 본문
     * @param  array<int, string>  $names  비공개 메서드 이름
     * @return bool 하나라도 부르면 true
     */
    private function callsAnyOwnMethod(string $body, array $names): bool
    {
        foreach ($names as $name) {
            if (preg_match('/\$this->'.preg_quote($name, '/').'\s*\(/', $body) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 문자열이 신호 중 하나를 담는지 판정합니다.
     *
     * @param  string  $haystack  검사 대상
     * @param  array<int, string>  $needles  신호 목록
     * @return bool 하나라도 담으면 true
     */
    private function mentionsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 표본 — 얕은 자기시험을 가진 가드 (32차까지의 실물 형태).
     */
    private function shallowGuardSource(): string
    {
        return <<<'PHP'
        <?php
        class ShallowGuardSample
        {
            public function test_no_violation_in_population(): void
            {
                foreach (GateTestPopulation::gateSelectedFiles() as $relative => $absolute) {
                    $this->assertSame([], $this->violationsIn($absolute));
                }
            }

            public function test_predicate_detects_known_shape(): void
            {
                $this->assertNotSame([], $this->violationsIn('<?php $a = read($x); $b = read($y);'));
            }

            private function violationsIn(string $code): array
            {
                return [];
            }
        }
        PHP;
    }

    /**
     * 표본 — 도달 시험을 갖춘 가드 (근본 조치 H 준수 형태).
     */
    private function deepGuardSource(): string
    {
        return <<<'PHP'
        <?php
        class DeepGuardSample
        {
            public function test_no_violation_in_population(): void
            {
                foreach (GateTestPopulation::gateSelectedFiles() as $relative => $absolute) {
                    $this->assertSame([], $this->violationsIn($absolute));
                }
            }

            public function test_predicate_detects_known_shape(): void
            {
                $this->assertNotSame([], $this->violationsIn('<?php $a = read($x); $b = read($y);'));
            }

            public function test_guard_reacts_to_injected_samples(): void
            {
                $this->assertGuardRejects('x.php', '<?php // 위반', fn () => null, '표본');
            }

            private function violationsIn(string $code): array
            {
                return [];
            }
        }
        PHP;
    }

    /**
     * 표본 — 자기시험이 없는 프로덕션 전용 검사 (판정 대상 아님).
     */
    private function productionOnlyGuardSource(): string
    {
        return <<<'PHP'
        <?php
        class ProductionOnlyGuardSample
        {
            public function test_no_violation_in_population(): void
            {
                foreach (GateTestPopulation::gateSelectedFiles() as $relative => $absolute) {
                    $this->assertSame([], $this->violationsIn($absolute));
                }
            }

            private function violationsIn(string $code): array
            {
                return [];
            }
        }
        PHP;
    }

    /**
     * PHP 소스에서 주석만 제거합니다 (문자열·정규식 리터럴 보존).
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
}
