<?php

namespace Tests\Support;

use PHPUnit\Framework\AssertionFailedError;

/**
 * 가드의 자기시험을 **프로덕션 검사 전 구간**으로 내리는 하네스 (32차 근본 조치 H).
 *
 * ## 무엇을 고치는가
 *
 * S17 이후 서른두 회차가 같은 형태로 반복됐고, 회차마다 진단이 달랐다 — 21차 "종료 조건이
 * 반증 불가능", 22차 "감시자가 자기를 관측 밖에 둠", 23차 "종료 절차 부재", 30차 "조치를
 * 검증하는 방법이 조치와 같은 입도", 31차 "손으로 쓴 열거로 열린 세계를 판정". 전부 맞지만
 * 전부 **그 회차가 깨진 자리**의 이름이었다.
 *
 * 서른두 회차를 한 장에 겹치면 남는 골격은 하나다. 가드는
 *
 * ```
 * 모집단 도출 → 문지기(후보 필터) → 판정식 → 단언
 * ```
 *
 * 4단인데, **변이검증(자기시험)이 언제나 3단에만 표본을 주입했다.** 그래서 자기시험은 "판정식이
 * 그 형태를 안다" 만 증명하고, "가드가 그 형태를 검사한다" 는 한 번도 증명하지 않았다. 깨진 단이
 * 회차마다 달랐을 뿐이다 —
 *
 * | 회차 | 깨진 단 | 자기시험이 시험한 단 |
 * | --- | --- | --- |
 * | 29차 | ④ 단언 (항진명제) | ③ |
 * | 31차 | ① 모집단 (손 열거의 여집합) | ③ |
 * | 32차 | ② 문지기 (대입문 표기 의존) | ③ |
 *
 * 그러므로 검출력을 더 높이는 것으로는 끝나지 않는다. **표본의 주입 지점을 파이프라인 입구로
 * 내려야** 앞 단들이 시험받는다. 이 트레이트가 그것을 한다 — 합성 표본을 모집단에 넣고
 * **프로덕션 테스트 메서드를 그대로 호출**해 red/green 을 확인한다.
 *
 * 이 형태였다면 29·31·32차 결함은 심긴 회차에 즉시 red 였다.
 *
 * ## 쓰는 법
 *
 * ```php
 * public function test_predicate_detects_the_known_shape(): void
 * {
 *     $this->assertGuardRejects(
 *         'tautology.php',
 *         $violatingSource,
 *         fn () => $this->test_no_assertion_compares_resolver_output_against_itself(),
 *         '29차 항진명제',
 *     );
 * }
 * ```
 *
 * @see GateTestPopulation::withInjectedFiles()
 */
trait InjectsGuardSamples
{
    /**
     * 위반 표본을 모집단에 넣으면 프로덕션 검사가 **실패해야** 합니다.
     *
     * @param  string  $name  표본 파일명 (디렉토리 없이)
     * @param  string  $source  표본 PHP 소스
     * @param  callable():void  $production  프로덕션 테스트 메서드 호출
     * @param  string  $label  실패 메시지에 넣을 표본 설명
     */
    protected function assertGuardRejects(string $name, string $source, callable $production, string $label): void
    {
        $rejected = false;

        GateTestPopulation::withInjectedFiles([$name => $source], function () use ($production, &$rejected): void {
            try {
                $production();
            } catch (AssertionFailedError) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected,
            "모집단에 주입한 위반 표본에 프로덕션 검사가 반응하지 않습니다 ({$label}) — 판정식이 그 "
            .'형태를 알더라도 문지기·모집단이 표본을 걸러내면 가드는 아무것도 지키지 않는다. '
            .'표본이 검사 구간에 도달하는지부터 확인해야 한다.');
    }

    /**
     * 준수 표본을 모집단에 넣어도 프로덕션 검사는 **통과해야** 합니다 (과탐 반례).
     *
     * @param  string  $name  표본 파일명 (디렉토리 없이)
     * @param  string  $source  표본 PHP 소스
     * @param  callable():void  $production  프로덕션 테스트 메서드 호출
     * @param  string  $label  실패 메시지에 넣을 표본 설명
     */
    protected function assertGuardAccepts(string $name, string $source, callable $production, string $label): void
    {
        $failure = null;

        GateTestPopulation::withInjectedFiles([$name => $source], function () use ($production, &$failure): void {
            try {
                $production();
            } catch (AssertionFailedError $error) {
                $failure = $error->getMessage();
            }
        });

        $this->assertNull($failure,
            "준수 표본을 위반으로 잡습니다 ({$label}) — 과탐하는 가드는 무시당하고, 무시당하는 "
            ."가드는 없는 것과 같다. 실패 메시지: {$failure}");
    }
}
