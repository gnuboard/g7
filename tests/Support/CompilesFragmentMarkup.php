<?php

namespace Tests\Support;

use App\Extension\Traits\CollectsFragmentSources;
use Illuminate\Support\Facades\File;
use Tests\Unit\Support\LayoutMarkup\FragmentSourceCopyGuardTest;

/**
 * fragment 소스를 **소스 선택 경로를 타지 않고** 직접 컴파일하는 독립 오라클.
 *
 * 소스 선택(`.g7.html` 우선)을 검증하는 자리에서 기대값을 `LayoutFragmentSourceHelper::read()` 로
 * 만들면 비교가 항등식이 된다 — 그 함수는 입력이 어느 확장자든 같은 후보 목록으로 수렴하기
 * 때문이다(30차 검출, `OracleIndependenceGuardTest` 가 상주 감시). 기대값은 선택을 거치지 않아야
 * 하므로 소스를 곧바로 컴파일한다.
 *
 * 이 트레이트가 `tests/Support` 에 있는 이유는 소비자가 코어 가드와 결제 플러그인 리스너 테스트
 * 양쪽이기 때문이다. 오라클을 각자 복제하면 그 사본이 다음 사각이 된다 — 이 트랙이 `app/` 에서
 * 세 회차에 걸쳐 확인한 사실을 테스트 층에도 같게 적용한다.
 */
trait CompilesFragmentMarkup
{
    /**
     * 소스(`.g7.html`)을 컴파일해 fragment 정의 배열을 돌려줍니다.
     *
     * @param  string  $markupFile  `.g7.html` 절대 경로
     * @return array<string, mixed> 컴파일된 fragment 정의
     */
    protected function compileFragmentMarkupDirectly(string $markupFile): array
    {
        $compiler = new class
        {
            use CollectsFragmentSources;

            /**
             * @param  string  $markupFile  `.g7.html` 절대 경로
             * @return array<string, mixed> 컴파일 산출
             */
            public function compile(string $markupFile): array
            {
                return $this->compileMarkupFragmentFile($markupFile);
            }
        };

        return $compiler->compile($markupFile);
    }

    /**
     * 코어 규약이 **실제로 고른 소스 파일**의 경로를 돌려줍니다 (32차 ⚠️ 관측력 보증).
     *
     * 값 비교(`compileFragmentMarkupDirectly()` vs `read()`)의 red 경로는 *"`.json` 이 이기고
     * 그 `.json` 이 컴파일 산출과 다를 때"* 하나뿐이다. 짝이 수렴해 있으면 어느 소스가 이겨도
     * 값이 같아 관측력이 0 이 된다 — 그리고 굽기(`layout:decompile --all --sync`)가 짝을
     * 기계로 수렴시키는 순간 그 상태가 **전건**이 된다. 즉 값 단언만 두면 굽기 착수와 동시에
     * 이 축의 단언 넷이 조용히 무력해진다.
     *
     * 그래서 값이 아니라 **선택 자체**를 관측한다. 기대값이 리터럴(`.g7.html` 접미사)이므로
     * 짝이 수렴해도 red 경로가 살아 있고, 오라클 독립성도 유지된다.
     *
     * **S18.5 — 예견한 그 수렴이 실제로 일어났고, 이 메서드가 그 자리를 받았다.** 코퍼스 짝
     * 우선순위 가드({@see FragmentSourceCopyGuardTest::test_real_fragment_directories_resolve_to_authoring_sources()})
     * 가 짝마다 이 단언을 함께 걸고, 31차의 divergence 하한은 계량으로 강등됐다. "값 오라클이
     * `.json` 승리를 잡는다" 는 명제는 어긋난 합성 짝이 따로 시험한다.
     *
     * @param  string  $fragmentPath  `.json` 참조 경로
     * @return string 규약이 고른 소스 절대 경로
     */
    protected function resolvedFragmentSourcePathFor(string $fragmentPath): string
    {
        $resolver = new class
        {
            use CollectsFragmentSources;

            /**
             * @param  string  $fragmentPath  `.json` 참조 경로
             * @return array<string, mixed>|null 고른 소스 디스크립터
             */
            public function resolve(string $fragmentPath): ?array
            {
                return $this->resolveFragmentSource($fragmentPath);
            }
        };

        $source = $resolver->resolve($fragmentPath);

        return is_array($source) && isset($source['path']) ? (string) $source['path'] : '';
    }

    /**
     * 규약이 소스(`.g7.html`)을 골랐음을 단언합니다.
     *
     * @param  string  $fragmentPath  `.json` 참조 경로
     */
    protected function assertFragmentSourceIsAuthoringMarkup(string $fragmentPath): void
    {
        $chosen = $this->resolvedFragmentSourcePathFor($fragmentPath);

        $this->assertStringEndsWith('.g7.html', $chosen,
            "확장점 정의의 소스로 소스가 아니라 `{$chosen}` 이 선택됐습니다 — `.g7.html` 만 고치면 "
            .'이 소비 경로에는 반영되지 않는다. (값 비교는 짝이 수렴하면 관측력을 잃으므로 선택 '
            .'자체를 단언한다.)');
    }

    /**
     * `.json` 참조 경로에 대응하는 소스 경로를 돌려줍니다 (실재하지 않으면 null).
     *
     * @param  string  $jsonPath  `.json` 절대 경로
     * @return string|null 소스 절대 경로
     */
    protected function markupSiblingOf(string $jsonPath): ?string
    {
        $candidate = preg_replace('/\.json$/i', '.g7.html', $jsonPath);

        return $candidate !== null && File::exists($candidate) ? $candidate : null;
    }
}
