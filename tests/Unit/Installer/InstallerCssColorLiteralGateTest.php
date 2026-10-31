<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * D16 색 토큰화의 회귀 게이트 (20차 N-1)
 *
 * 인스톨러 CSS 는 색을 `:root`/`[data-theme="dark"]` 의 토큰 선언으로만 갖고, 규칙 본문은
 * `var(--…)` 로만 칠한다 — 그래야 다크 재정의가 단일 지점에서 성립한다 (§11.5: 「화면마다
 * 개별 대응하면 새 화면이 추가될 때마다 누락된다」). 그런데 20차까지 이 규칙을 지키는
 * 게이트가 0건이었다: 새 규칙에 `color: #fff` 를 쓰면 캐스케이드·클래스 커버리지·미선언
 * var() 게이트가 전부 초록인 채로 다크에서만 깨지는 지점이 생긴다.
 *
 * 허용 예외는 둘뿐이고 구조적이다:
 * - **토큰 선언 라인**(`--x: #hex`) — 리터럴이 있어야 하는 유일한 자리. 라인 단위로 걷어낸다.
 * - **흑백 알파 오버레이**(`rgba(0,0,0,α)`/`rgba(255,255,255,α)`) — 그림자·그라디언트의
 *   알파 채널은 토큰이 원리상 담지 못한다 (12차 #B33 이 주석으로 명문화한 축).
 */
class InstallerCssColorLiteralGateTest extends TestCase
{
    /**
     * 인스톨러 스타일시트 원문을 읽어 문자열로 돌려준다.
     *
     * @return string 스타일시트 전체 원문
     */
    private function stylesheetSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/public/install/assets/css/installer.css');
    }

    /**
     * 주석과 토큰 선언(리터럴 허용 자리)을 걷어낸다.
     *
     * 토큰 선언은 줄 앵커가 아니라 선언 단위로 걷는다 (21차 7c) — `:root { --a:#fff; --b:#000 }`
     * 처럼 한 줄에 여러 선언이 와도, 규칙 본문의 지역 커스텀 프로퍼티 선언이어도 전부
     * 허용 자리다. `var(--a, #fff)` 폴백은 `--x:` 형태가 아니라 걷히지 않는다(위반으로 잡힌다).
     *
     * @param  string  $css  검사할 스타일시트 원문
     * @return string 주석과 토큰 선언을 걷어낸 나머지
     */
    private function stripAllowedZones(string $css): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        return (string) preg_replace('/(?<=[{;\n])\s*--[\w-]+\s*:[^;{}]*;/', '', $css);
    }

    /**
     * 허용 자리 밖의 색 리터럴을 수집한다.
     *
     * @param  string  $strippedCss  허용 자리를 걷어낸 스타일시트
     * @return array<int, string> 위반 목록
     */
    private function collectColorLiterals(string $strippedCss): array
    {
        $offenders = [];

        // 선언 값 안의 hex — 선택자(`#install-log`)와 구분하려고 `:` 뒤 문맥만 본다.
        preg_match_all('/:[^;{}]*?(#[0-9a-fA-F]{3,8})\b/', $strippedCss, $hex);
        foreach ($hex[1] as $literal) {
            $offenders[] = $literal;
        }

        // rgb()/rgba() — 흑백 알파 오버레이만 허용.
        preg_match_all('/(?<![\w-])rgba?\([^)]*\)/', $strippedCss, $funcs);
        foreach ($funcs[0] as $func) {
            if (preg_match('/^rgba\(\s*(0|255)\s*,\s*\1\s*,\s*\1\s*,/', $func) === 1) {
                continue;
            }
            $offenders[] = $func;
        }

        // 명명 색 — `white-space` 같은 속성명 오탐을 피해 색을 받는 속성의 값 자리만 본다.
        // color: 한정이던 종전 범위(21차 7b)를 background/border/outline/fill/stroke 계열로
        // 넓힌다 — `background: white` 도 다크 재정의가 닿지 않는 같은 결함이다.
        preg_match_all(
            '/(?<![\w-])(?:-webkit-text-fill-color|color|background(?:-color)?|border(?:-[a-z]+)?-color|outline-color|fill|stroke)\s*:\s*[^;{}]*?(?<![\w-])(white|black)\b/i',
            $strippedCss,
            $named,
            PREG_SET_ORDER
        );
        foreach ($named as $match) {
            $offenders[] = trim($match[0]);
        }

        return $offenders;
    }

    /**
     * 실 스타일시트: 허용 자리 밖 색 리터럴 0.
     *
     * @effects installer_css_colors_come_from_tokens_only
     *
     * @return void 반환값 없음
     */
    public function test_stylesheet_has_no_color_literals_outside_token_declarations(): void
    {
        $offenders = $this->collectColorLiterals($this->stripAllowedZones($this->stylesheetSource()));

        $this->assertSame(
            [],
            $offenders,
            "토큰 블록 밖의 색 리터럴 — 다크 재정의가 도달하지 못한다 (D16):\n".implode("\n", $offenders)
        );
    }

    /**
     * 탐지기 자기증명 (red-probe) — 위장 리터럴 4형태를 실제로 잡는지. 탐지기가 죽으면
     * 실 파일 검사는 영원히 초록이므로, 게이트 자신의 판정력을 먼저 잠근다.
     *
     * @effects installer_css_colors_come_from_tokens_only
     *
     * @return void 반환값 없음
     */
    public function test_detector_catches_probe_literals(): void
    {
        $probe = <<<'CSS'
:root {
  --ok-token: #123456; --ok-inline: #abcdef;
}
.a { color: #fff; }
.b { background: rgb(10, 20, 30); }
.c { border-color: rgba(59, 130, 246, 0.5); }
.d { color: white; }
.e { box-shadow: 0 1px 2px rgba(0, 0, 0, 0.35); }
.f { background: white; }
.g { --local-token: #222222; white-space: nowrap; }
CSS;

        $offenders = $this->collectColorLiterals($this->stripAllowedZones($probe));

        $this->assertContains('#fff', $offenders, 'hex 리터럴 탐지 실패');
        $this->assertContains('rgb(10, 20, 30)', $offenders, 'rgb() 탐지 실패');
        $this->assertContains('rgba(59, 130, 246, 0.5)', $offenders, '유색 rgba() 탐지 실패');
        $this->assertContains('color: white', $offenders, '명명 색 탐지 실패');
        $this->assertContains('background: white', $offenders, 'background 명명 색 탐지 실패 (21차 7b)');
        $this->assertCount(5, $offenders, '허용 예외(토큰 선언 — 인라인·지역 포함 · 흑백 오버레이 · white-space)가 위반으로 세이면 안 된다');
    }
}
