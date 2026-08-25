<?php

namespace Tests\Unit\Concerns;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 소스 함수 경계 검출기 자신의 계약 (11차 #D11).
 *
 * 이 두 판정은 인스톨러 커버리지 테스트 3종이 「그 호출이 어느 함수 안에 있는가」·「그 함수
 * 본문에 가드가 있는가」를 정하는 근거다. 종전에는 세 파일에 문자 그대로 복제돼 있었고,
 * 형제 검출기들과 달리 **자기 검증 픽스처가 하나도 없었다** — 두 판정이 뒤집혀도 red 가
 * 없었고, 뒤집히면 커버리지 테스트가 엉뚱한 본문을 보면서 초록을 낸다.
 *
 * 커버 축: source_shape prefix_sibling·top_level_after_function·inline_script·nested_braces
 *
 * @effects installer_source_function_scanner_discriminates_known_failure_modes
 */
class ScansPhpSourceFunctionsTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 이름이 접두사인 형제가 먼저 정의돼 있어도 지목한 함수를 찾는다.
     *
     * 종전 구현은 `strpos('function '.$name)` 첫 일치를 썼다 — `run` 을 찾으면 먼저 정의된
     * `runTask` 가 걸려 **다른 함수 본문**을 검사했다.
     */
    public function test_function_body_matches_exact_name_not_prefix_sibling(): void
    {
        $source = <<<'PHP'
<?php
function runTask()
{
    return 'WRONG';
}

function run()
{
    return 'RIGHT';
}
PHP;

        $this->assertStringContainsString("'RIGHT'", $this->functionBody($source, 'run'));
        $this->assertStringNotContainsString("'WRONG'", $this->functionBody($source, 'run'));
        $this->assertStringContainsString("'WRONG'", $this->functionBody($source, 'runTask'));
    }

    /**
     * 함수가 끝난 뒤의 최상위 코드는 그 함수에 귀속되지 않는다.
     *
     * 종전 구현은 중괄호를 세지 않고 「앞에서 마지막으로 선언된 함수」를 돌려줬다 —
     * 파일 끝의 최상위 호출이 마지막 함수 안에 있는 것으로 보였다.
     */
    public function test_top_level_code_after_a_function_is_not_attributed_to_it(): void
    {
        $source = <<<'PHP'
<?php
function guarded()
{
    if (hasFunction('exec')) {
        exec('safe');
    }
}

exec('unguarded');
PHP;

        $inside = strpos($source, "exec('safe')");
        $outside = strpos($source, "exec('unguarded')");

        $this->assertSame('guarded', $this->enclosingFunctionName($source, (int) $inside));
        $this->assertSame(
            '(none)',
            $this->enclosingFunctionName($source, (int) $outside),
            '함수 밖 호출을 그 함수 안으로 보면 무가드 지점이 「가드 있음」으로 통과한다'
        );
    }

    /**
     * PHP 뷰의 `<script>` 안 JS 선언은 PHP 함수로 세지 않는다.
     *
     * 인스톨러 뷰에는 인라인 스크립트가 실재하고, 그 안의 `function` 선언이 계상되면
     * 그 뒤의 PHP 호출이 존재하지 않는 함수 안에 있는 것으로 판정된다.
     */
    public function test_inline_script_declarations_are_not_counted_as_php_functions(): void
    {
        $source = <<<'PHP'
<?php
function phpOwner()
{
    echo 'x';
}
?>
<script>
function jsHelper() { return 1; }
</script>
<?php
exec('top-level');
PHP;

        $at = strpos($source, "exec('top-level')");

        $this->assertSame(
            '(none)',
            $this->enclosingFunctionName($source, (int) $at),
            'JS 선언을 PHP 함수로 세면 최상위 호출이 그 안에 있는 것으로 보인다'
        );
    }

    /**
     * 중첩 중괄호(조건·클로저)를 지나 올바른 끝을 찾는다.
     */
    public function test_nested_braces_do_not_end_the_body_early(): void
    {
        $source = <<<'PHP'
<?php
function outer()
{
    if (true) {
        $f = function () { return 1; };
    }

    return 'TAIL';
}
PHP;

        $body = $this->functionBody($source, 'outer');

        $this->assertStringContainsString("'TAIL'", $body, '첫 닫는 중괄호에서 끊으면 본문 뒷부분의 가드를 놓친다');
    }

    /**
     * 없는 이름은 빈 문자열 — 호출부가 「본문에 가드가 없다」로 읽어 fail-closed 가 된다.
     */
    public function test_unknown_function_returns_empty_body(): void
    {
        $this->assertSame('', $this->functionBody('<?php function a() {}', 'b'));
    }

    /**
     * 중첩 템플릿 리터럴 뒤의 주석도 지운다 (13차).
     *
     * 템플릿 리터럴의 `${…}` 안은 문자열이 아니라 **코드**라 또 다른 템플릿 리터럴이 올 수
     * 있다. 백틱을 따옴표와 같이 다루면 안쪽 백틱이 바깥의 종료로 읽혀 그 지점부터 짝이
     * 영구히 뒤집히고, **파일 나머지 전체의 주석이 남는다**.
     *
     * 이 형태는 `installer.js` 의 실패 안내 렌더에 실재했고(파일 5,800행 중 238행), 그 뒤
     * 모든 JS 소스 게이트가 주석 채널에 무방비였다. 그런데 「스트리퍼를 거쳤다」는 사실은
     * 변하지 않으므로 위생 검사(`SourceGateHygieneTest`)는 초록이다 — 거쳤는지는 보지만
     * 제대로 작동하는지는 보지 않는다.
     */
    public function test_comments_after_a_nested_template_literal_are_stripped(): void
    {
        $source = <<<'JS'
const markup = `
    <div>
        ${flag ? `<span>${inner}</span>` : ''}
    </div>
`;
// 이 주석이 남으면 그 뒤 모든 게이트가 주석 채널에 무방비다
const wired = true;
JS;

        $stripped = $this->stripJsComments($source);

        $this->assertStringNotContainsString(
            '무방비',
            $stripped,
            '중첩 템플릿 리터럴 뒤의 주석이 남았다 — 백틱 짝이 어긋나 그 지점부터 주석 제거가 멈춘 것이다',
        );
        $this->assertStringContainsString('const wired = true;', $stripped, '주석 밖의 코드는 보존되어야 한다');
        $this->assertStringContainsString('<span>${inner}</span>', $stripped, '중첩 리터럴 내용은 보존되어야 한다');
    }

    /**
     * 보간 안의 중괄호·문자열이 리터럴 종료를 앞당기지 않는다.
     *
     * `${ {a:1} }` 처럼 객체 리터럴이 오거나 문자열 안에 `}` 가 있으면 균형 계산이 틀려
     * 위와 같은 desync 가 다시 생긴다.
     */
    public function test_interpolation_with_braces_and_strings_keeps_the_literal_balanced(): void
    {
        $source = <<<'JS'
const a = `x${ obj['}'] }y${ {k: 1}.k }z`;
// 이 주석도 사라져야 한다
const b = 2;
JS;

        $stripped = $this->stripJsComments($source);

        $this->assertStringNotContainsString('사라져야', $stripped, '보간 안의 중괄호·문자열이 리터럴 종료를 앞당겼다');
        $this->assertStringContainsString('const b = 2;', $stripped);
    }
}
