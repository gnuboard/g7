<?php

namespace Tests\Unit\Concerns;

use PHPUnit\Framework\Attributes\DataProvider;
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
 * 커버 축: source_shape prefix_sibling·top_level_after_function·inline_script·nested_braces·string_literal_braces·unbalanced_source·call_vs_mention
 */
class ScansPhpSourceFunctionsTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 이름이 접두사인 형제가 먼저 정의돼 있어도 지목한 함수를 찾는다.
     *
     * 종전 구현은 `strpos('function '.$name)` 첫 일치를 썼다 — `run` 을 찾으면 먼저 정의된
     * `runTask` 가 걸려 **다른 함수 본문**을 검사했다.
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
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
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
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
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
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
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
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
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
     */
    public function test_unknown_function_returns_empty_body(): void
    {
        $this->assertSame('', $this->functionBody('<?php function a() {}', 'b'));
    }

    /**
     * 문자열 리터럴 안의 중괄호가 함수 경계를 무너뜨리지 않는다 (14차 C11-7).
     *
     * 종전 판정은 문자 단위로 중괄호를 셌다. 그래서 `'${'` 처럼 **짝이 없는 중괄호를 담은
     * 문자열**이 본문에 있으면 깊이가 영원히 0 으로 돌아오지 않아, 이 함수의 본문 도출이
     * `''` 를, 그 안의 호출에 대한 소속 판정이 `'(none)'` 을 돌려줬다. 실물이 그 상태였다 —
     * `escapeEnvValue()` 는 `.env` 보간 차단을 위해 정확히 그 리터럴을 쓴다.
     *
     * 소비처는 `'(none)'` 을 「함수 밖」으로 읽고 판정 범위를 **파일 전체로 넓힌다**(fail-open).
     * 즉 검출기의 실패가 가드 검사의 통과로 바뀌었다.
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
     */
    public function test_string_literal_braces_do_not_break_the_function_boundary(): void
    {
        $source = <<<'PHP'
<?php
function escapesInterpolation(string $value): string
{
    $escaped = str_replace('${', '\\${', $value);
    $escaped = str_replace('}', '}', $escaped);

    return 'TAIL'.$escaped;
}

exec('unguarded');
PHP;

        $body = $this->functionBody($source, 'escapesInterpolation');

        $this->assertStringContainsString(
            "'TAIL'",
            $body,
            '문자열 안의 `${` 를 코드 중괄호로 세면 본문 도출이 빈 문자열이 되고, 그 함수의 가드가 통째로 사라진다'
        );

        $inside = (int) strpos($source, "str_replace('\${'");
        $outside = (int) strpos($source, "exec('unguarded')");

        $this->assertSame('escapesInterpolation', $this->enclosingFunctionName($source, $inside));
        $this->assertSame(
            '(none)',
            $this->enclosingFunctionName($source, $outside),
            '함수 밖 판정까지 무너지면 소비처가 「함수 밖」과 「검출 실패」를 구분하지 못한다'
        );
    }

    /**
     * 검출 실패는 「함수 없음」과 **다른 신호**다 (14차 C11-7).
     *
     * 종전 구현은 짝을 못 찾으면 `null` → 호출부에서 빈 본문/`(none)` 으로 접혔다. 두 상태가
     * 같은 값이면 소비처는 실패를 「대상이 없다」로 읽고 그대로 통과시킨다.
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
     */
    public function test_unbalanced_source_raises_instead_of_looking_like_an_absent_function(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->functionBody("<?php\nfunction broken()\n{\n    if (true) {\n", 'broken');
    }

    /**
     * 직접 호출만 센다 — 문자열·주석·메서드 호출·선언은 호출이 아니다 (14차 C11-10).
     *
     * 이 도출은 「막힌 함수를 부르는 파일」의 모집단을 만드는 데 쓴다. 번역 파일이 안내
     * 문구로 담은 `ini_set(` 을 호출로 세면 그 파일에 없는 가드 의무가 생겨 거짓 red 가 나고,
     * 반대로 문자 대조로 넓게 세면 모집단이 부풀어 진짜 사각이 묻힌다.
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
     */
    public function test_direct_function_calls_ignore_strings_declarations_and_methods(): void
    {
        $source = <<<'PHP'
<?php
$hint = 'ini_set() 을 쓸 수 없는 호스팅입니다';

function ignore_user_abort_helper(): void {}

class Fake
{
    public function set_time_limit(int $s): void {}
}

(new Fake)->set_time_limit(1);

ini_set('display_errors', '0');
PHP;

        $this->assertSame(
            ['ini_set'],
            $this->directFunctionCalls($source, ['ini_set', 'set_time_limit', 'ignore_user_abort']),
            '문자열·선언·메서드 호출을 직접 호출로 세면 모집단이 부풀어 없는 의무가 생긴다'
        );
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
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
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
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
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

    /**
     * 함수 **본문**(여는 태그 없는 PHP 조각)도 PHP 규칙으로 읽는다.
     *
     * 언어 판정이 `str_contains($source, '<?php')` 추론이던 동안, `functionBody()` 가
     * 돌려주는 조각에는 그 태그가 **정의상** 없어 언제나 JS 문자 스캐너로 흘렀다. 그
     * 스캐너는 heredoc·`#` 주석 같은 PHP 전용 문법을 모르므로 그 안의 중괄호를 코드로
     * 세어 짝이 어긋난다 — 14차가 토크나이저로 바꿔 고친 그 결함이 소비처 한 겹 아래에서
     * 그대로 살아 있었고, 이 경로를 태우는 픽스처는 하나도 없었다 (12차 G3 라운드 B-1).
     *
     * 양쪽 방향을 다 잰다: 거짓 red(짝을 못 찾아 예외)와 fail-open(경계를 잘못 잡아
     * 가드 밖 호출이 가드 안으로 읽히는 것) 둘 다 이 경계 판정에서 나온다.
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
     */
    public function test_function_body_fragment_is_read_with_php_rules(): void
    {
        // heredoc 안의 짝 없는 여는 중괄호 — JS 스캐너는 이것을 코드로 센다.
        $heredocBody = "{
    \$sql = <<<SQL
    SELECT '{' AS x
SQL;

    return 1;
}";

        $this->assertSame(
            strlen($heredocBody) - 1,
            $this->matchingBrace($heredocBody, 0, true),
            'heredoc 안의 중괄호를 코드로 세었다 — 본문이 JS 스캐너로 흐르고 있다'
        );

        // PHP 전용 `#` 주석 안의 중괄호.
        $hashCommentBody = '{
    # 여는 괄호 { 하나
    return 1;
}';

        $this->assertSame(
            strlen($hashCommentBody) - 1,
            $this->matchingBrace($hashCommentBody, 0, true),
            '`#` 주석 안의 중괄호를 코드로 세었다 — PHP 전용 주석 문법을 모르는 경로다'
        );

        // 반대 방향(JS 본문을 PHP 토크나이저로 보내면 표가 빈다)은 여기서 재지 않는다 —
        // 그 축은 `braceOffsets()` 의 분기를 지우는 변이로 이미 red 가 되고(문자열 리터럴
        // 픽스처가 잡는다), JS 스캐너 자신의 한계(템플릿 보간 안의 따옴표)는 별개 축이다.
    }

    /**
     * JS 스캐너는 문자열·정규식·주석 안의 **홑중괄호**를 세지 않는다 (G3 회차 13 W5-3).
     *
     * 종전 스캐너는 「문자열 안의 중괄호는 짝을 이루므로 상쇄된다」를 전제로 문자를 그대로
     * 세었다. 그 전제는 `'{'` 한 글자에서 무너진다 — 여는 홑중괄호는 짝이 없어
     * `RuntimeException`(거짓 red)이 되고, 닫는 홑중괄호 `'}'` 는 본문을 그 자리에서
     * 끊는다. 끊긴 본문은 그 뒤의 호출을 「함수 밖」(`'(none)'`)으로 보이게 하고, 소비처는
     * 그 값을 판정 범위 확대의 신호로 읽는다(fail-open). 정규식 리터럴 `/\{/` 과 `// }` 주석도
     * 같은 형태다.
     *
     * @return array<string, array{0: string}> 사례 이름을 키로 한 JS 소스
     */
    public static function jsSingleBraceSources(): array
    {
        return [
            'opening brace in a string' => ["function f() {\n    const a = '{';\n    return 'TAIL';\n}\ncall();\n"],
            'closing brace in a string' => ["function f() {\n    const a = \"}\";\n    return 'TAIL';\n}\ncall();\n"],
            'brace in a regex literal' => ["function f() {\n    const re = /\\{/;\n    return 'TAIL';\n}\ncall();\n"],
            'brace in a line comment' => ["function f() {\n    // }\n    return 'TAIL';\n}\ncall();\n"],
            'brace in a block comment' => ["function f() {\n    /* { */\n    return 'TAIL';\n}\ncall();\n"],
            'brace in a template literal' => ["function f() {\n    const t = `}`;\n    return 'TAIL';\n}\ncall();\n"],
        ];
    }

    /**
     * JS 문자열, 정규식, 주석, 템플릿 리터럴 안의 홑중괄호가 함수 본문 경계를 옮기지 않아 본문이 끝까지 잡히는지 확인한다.
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @param  string  $source  홑중괄호를 품은 JS 소스
     * @return void 반환값 없음
     */
    #[DataProvider('jsSingleBraceSources')]
    public function test_js_single_braces_in_strings_regex_and_comments_do_not_move_the_boundary(string $source): void
    {
        $body = $this->functionBody($source, 'f');

        $this->assertStringContainsString("'TAIL'", $body, '본문이 문자열·주석 안의 중괄호에서 끊기거나 비었다');
        $this->assertStringEndsWith('}', $body);
        $this->assertStringNotContainsString('call();', $body, '함수 밖의 호출이 본문에 들어왔다');

        $inside = (int) strpos($source, "return 'TAIL'");
        $outside = (int) strpos($source, 'call();');

        $this->assertSame('f', $this->enclosingFunctionName($source, $inside), '본문 안의 위치가 함수 밖으로 읽힌다 (fail-open)');
        $this->assertSame('(none)', $this->enclosingFunctionName($source, $outside));
    }

    /**
     * 정규식 리터럴과 나눗셈을 가른다 — 나눗셈 뒤의 코드가 「정규식 안」으로 읽히면 그 줄
     * 이후의 중괄호가 전부 사라진다.
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
     */
    public function test_js_division_is_not_read_as_a_regex_literal(): void
    {
        $source = "function f() {\n    const x = a / b;\n    if (x) { return 'TAIL'; }\n}\ncall();\n";

        $body = $this->functionBody($source, 'f');

        $this->assertStringContainsString("'TAIL'", $body);
        $this->assertStringNotContainsString('call();', $body);
    }

    /**
     * JS 스캐너의 정규식/나눗셈 판정 — 후위 `++`/`--` 뒤는 나눗셈, 키워드 뒤는 정규식 (G3 회차 14 W4-6).
     *
     * 종전 판정은 직전 유의미 **한 글자**만 봤다. `i++ / 2` 는 `+` 가 피연산자 문자가 아니라
     * 정규식 시작으로 읽혀 그 줄 끝까지의 중괄호가 사라졌고, `return /x{/` 는 `n` 이
     * 식별자 문자라 나눗셈으로 읽혀 리터럴 안의 `{` 가 코드 중괄호로 계수됐다. 두 방향 다
     * 함수 경계를 옮기고(fail-open 또는 거짓 red), 같은 판정을 쓰는 주석 제거기는 그
     * 뒤의 `//` 주석을 남긴다.
     *
     * @return array<string, array{0: string, 1: string}> 사례 이름을 키로 한 JS 소스와 지워져야 할 주석
     */
    public static function jsSlashJudgmentSources(): array
    {
        return [
            'postfix increment then division' => [
                "function f() {\n    let x = i++ / 2; if (x) { // ghost\n        return 'TAIL';\n    }\n}\ncall();\n",
                'ghost',
            ],
            'postfix decrement then division' => [
                "function f() {\n    let x = i-- / 2; if (x) { // ghost\n        return 'TAIL';\n    }\n}\ncall();\n",
                'ghost',
            ],
            'regex literal after return keyword' => [
                "function f() {\n    if (a) return /x{/.test(s); // ghost\n    return 'TAIL';\n}\ncall();\n",
                'ghost',
            ],
            'regex literal after typeof keyword' => [
                "function f() {\n    const t = typeof /x{/; // ghost\n    return 'TAIL';\n}\ncall();\n",
                'ghost',
            ],
        ];
    }

    /**
     * 후위 증감 연산자와 키워드 뒤의 슬래시를 나눗셈과 정규식으로 바르게 갈라, 함수 본문이 잘리지 않고 그 뒤 주석도 지워지는지 확인한다.
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @param  string  $source  슬래시 판정이 갈리는 JS 소스
     * @param  string  $comment  지워져야 할 주석 문자열
     * @return void 반환값 없음
     */
    #[DataProvider('jsSlashJudgmentSources')]
    public function test_js_slash_judgment_uses_postfix_operators_and_keywords(string $source, string $comment): void
    {
        $body = $this->functionBody($source, 'f');

        $this->assertStringContainsString("'TAIL'", $body, '슬래시 판정이 틀려 본문이 잘리거나 비었다');
        $this->assertStringNotContainsString('call();', $body, '슬래시 판정이 틀려 함수 밖 호출이 본문에 들어왔다');

        $stripped = $this->stripJsComments($source);

        $this->assertStringNotContainsString($comment, $stripped, '슬래시 판정이 틀려 그 뒤의 주석이 남았다');
        $this->assertStringContainsString("'TAIL'", $stripped);
    }

    /**
     * `sourceWithoutComments()` — 뷰의 `<script>` 안에서 PHP 인라인 구간(`<?= … ?>`)은 피연산자다
     * (G3 회차 14 W4-4).
     *
     * `<?= $n ?> / 2; // ghost` 에서 종전 판정은 `?>` 의 `>` 를 보고 `/` 를 정규식 시작으로
     * 읽었다 — 줄 끝까지 리터럴로 삼켜 `// ghost` 가 남는다(fail-open: 주석 처리된 배선이
     * 대조를 통과한다). 치환 결과가 값이므로 그 구간은 피연산자로 본다.
     *
     * CRLF 파일도 같은 결과여야 한다 — 줄 종결자가 `\r\n` 인 뷰에서 `\r` 이 판정에 끼면
     * 같은 자리가 다시 사각이 된다.
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
     */
    public function test_source_without_comments_treats_php_inline_segment_as_operand(): void
    {
        $dir = sys_get_temp_dir().'/g7-scan-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);

        try {
            $lf = "<?php \$n = 4; ?>\n<script>\nvar half = <?= \$n ?> / 2; // ghost\nlive();\n</script>\n";
            file_put_contents($dir.'/lf.php', $lf);
            file_put_contents($dir.'/crlf.php', str_replace("\n", "\r\n", $lf));

            foreach (['lf.php', 'crlf.php'] as $name) {
                $stripped = $this->sourceWithoutComments($dir.'/'.$name);

                $this->assertStringNotContainsString('ghost', $stripped, $name.': PHP 인라인 구간 뒤의 `/` 를 정규식 시작으로 읽어 주석이 남았다');
                $this->assertStringContainsString('<?= $n ?> / 2;', $stripped, $name.': 인라인 구간과 나눗셈은 보존되어야 한다');
                $this->assertStringContainsString('live();', $stripped);
            }

            $this->assertSame(
                substr_count($lf, "\n"),
                substr_count($this->sourceWithoutComments($dir.'/crlf.php'), "\r\n"),
                'CRLF 뷰의 줄 종결자가 보존되지 않았다 — 오프셋 기반 판정이 어긋난다'
            );
        } finally {
            @unlink($dir.'/lf.php');
            @unlink($dir.'/crlf.php');
            @rmdir($dir);
        }
    }

    /**
     * `<script>` 구간은 **첫 `</script>` 에서 끝난다** — JS 문자열 안이라도 (브라우저와 같다).
     * 그 뒤가 「JS 로 다시 시작」한다고 가정하지 않으며, 짝이 맞지 않는 여는 태그·홀로 남은
     * 닫는 태그는 `matchingBrace()` 규율대로 **예외**다 (G3 회차 14 W4-4).
     *
     * 종전 정규식(`<script…>.*?</script>`)은 문자열 안 `</script>` 에서 조기 종료한 뒤 그
     * 뒤를 조용히 원문으로 두었고, 닫는 태그가 없는 구간은 매치 자체가 없어 **원문 그대로**
     * 돌려줬다 — 둘 다 「주석을 지웠다」는 사실은 변하지 않으므로 위생 검사가 초록이고,
     * 그 구간의 주석 채널 방어만 사라진다.
     *
     * @return array<string, array{0: string}> 사례 이름을 키로 한 뷰 소스
     */
    public static function unbalancedScriptSources(): array
    {
        return [
            'closing tag inside a JS string leaves a stray closer' => [
                "<?php echo 1; ?>\n<script>\nvar s = '</script>'; // early\n</script>\n",
            ],
            'unterminated script region' => [
                "<?php echo 1; ?>\n<script>\nlive(); // tail\n",
            ],
            'stray closing tag without an opener' => [
                "<?php echo 1; ?>\n<div></div>\n</script>\n",
            ],
        ];
    }

    /**
     * 짝이 맞지 않는 script 구간을 가진 뷰 파일은 원문을 그대로 돌려주지 않고 예외를 던지는지 확인한다.
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @param  string  $source  짝이 맞지 않는 script 구간을 가진 뷰 소스
     * @return void 반환값 없음
     */
    #[DataProvider('unbalancedScriptSources')]
    public function test_unbalanced_script_regions_raise_instead_of_falling_open(string $source): void
    {
        $dir = sys_get_temp_dir().'/g7-scan-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/v.php', $source);

        try {
            $this->expectException(\RuntimeException::class);
            $this->sourceWithoutComments($dir.'/v.php');
        } finally {
            @unlink($dir.'/v.php');
            @rmdir($dir);
        }
    }

    /**
     * PHP **문자열 안**의 `<script>` 는 HTML 구간이 아니다 — 토큰(`T_CONSTANT_ENCAPSED_STRING`)이다.
     *
     * 실물(`public/install/includes/functions.php` 의 `echo '<script>'; … echo '</script>';`)이
     * 이 형태다. 텍스트로 구간을 찾으면 그 문자열 사이가 JS 로 읽혀 PHP 문자열의 내용이
     * 주석으로 지워진다.
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
     */
    public function test_script_tags_inside_php_strings_are_not_html_script_regions(): void
    {
        $dir = sys_get_temp_dir().'/g7-scan-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/s.php', "<?php\necho '<script>';\necho 'alert(1); // kept';\necho '</script>';\n// dropped\n");

        try {
            $stripped = $this->sourceWithoutComments($dir.'/s.php');

            $this->assertStringContainsString("'alert(1); // kept'", $stripped, 'PHP 문자열 안의 내용이 JS 주석으로 지워졌다');
            $this->assertStringNotContainsString('dropped', $stripped);
        } finally {
            @unlink($dir.'/s.php');
            @rmdir($dir);
        }
    }

    /**
     * `loadsFile()` — needle 이 파일명뿐이면 동적 require 는 「판정 불가」다 (G3 회차 14 W4-7).
     *
     * 종전에는 디렉토리 리터럴을 가진 동적 require(`$root.'/includes/'.$f`)를 만나면
     * needle 에 디렉토리가 없다는 이유로 `continue` → **false** 로 접었다. 「무엇을 로드하는지
     * 모른다」가 「로드하지 않는다」로 바뀌는 자리이고, 소비처(격리 커버리지)는 false 를
     * 모집단 밖으로 읽는다(fail-open).
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
     */
    public function test_loads_file_with_bare_needle_treats_dynamic_require_as_undecidable(): void
    {
        $this->assertNull(
            $this->loadsFile("<?php\nforeach (\$files as \$f) {\n    require \$root.'/includes/'.\$f;\n}\n", 'config.php'),
            '파일명만 있는 needle 에서 동적 require 를 false 로 접으면 소비처가 모집단 밖으로 읽는다'
        );
        $this->assertNull(
            $this->loadsFile("<?php\nrequire dirname(__DIR__).'/../lang/'.\$locale.'.php';\n", 'config.php'),
            '다른 디렉토리로 보이더라도 needle 에 디렉토리가 없으면 배제할 근거가 없다 — 판정 불가다'
        );

        // `./` 접두 — 호출부가 「트리 루트 기준」을 선언하면 루트 파일도 디렉토리를 갖는다.
        $langRequire = "<?php\n\$langFile = __DIR__.\"/../lang/{\$lang}.php\";\n\$t = require \$langFile;\n";

        $this->assertFalse($this->loadsFile($langRequire, './index.php'), '루트 기준 선언이 있으면 `lang/` 은 다른 디렉토리라 배제된다');
        $this->assertNull($this->loadsFile($langRequire, 'index.php'), '선언이 없으면 같은 형태가 판정 불가다');
        $this->assertTrue($this->loadsFile("<?php\nrequire __DIR__.'/index.php';\n", './index.php'), '같은 디렉토리 형제 형태는 루트 기준 선언과 함께도 잡힌다');
        $this->assertTrue($this->loadsFile("<?php\nrequire_once __DIR__.'/../includes/config.php';\n", './includes/config.php'), '상대 경로 needle 에 붙은 `./` 는 경로 대조에서 벗겨진다');
    }

    /**
     * `loadsFile()` — 주석 토큰은 경로가 아니고, 동적 require 는 「판정 불가」다 (W5-4).
     *
     * 종전 구현은 require 문장 안의 **모든** 배열 토큰을 봤다 — `require $p` 뒤에 붙은
     * 블록 주석 속 `config.php` 가 경로로 읽혀 독블록의 선언(「주석 토큰은 그 지점이 되지
     * 못한다」)과 모순됐다.
     * 그리고 `require $var;` 는 문자열 토큰이 없어 `false` 로 떨어졌다 — 「로드하지 않는다」와
     * 「무엇을 로드하는지 모른다」가 같은 값이면 소비처는 후자를 모집단 밖으로 접는다(fail-open).
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
     */
    public function test_loads_file_ignores_comment_tokens_and_separates_dynamic_requires(): void
    {
        $this->assertTrue($this->loadsFile("<?php\nrequire_once __DIR__.'/../includes/config.php';\n", 'includes/config.php'));
        $this->assertTrue($this->loadsFile("<?php\nrequire_once __DIR__.'/config.php';\n", 'config.php'), '같은 디렉토리의 형제 require');

        // 주석은 경로가 아니다 — 남는 것은 `require $p` 뿐이라 판정 불가(null)가 정답이다.
        $this->assertNull(
            $this->loadsFile("<?php\nrequire \$p /* includes/config.php */;\n", 'includes/config.php'),
            '주석 토큰이 경로로 읽혔다'
        );
        $this->assertNull(
            $this->loadsFile("<?php\nrequire \$p; // includes/config.php\n", 'includes/config.php'),
            '줄 주석 토큰이 경로로 읽혔다'
        );
        $this->assertFalse(
            $this->loadsFile("<?php\nrequire __DIR__.'/other.php' /* includes/config.php */;\n", 'includes/config.php'),
            '파일명이 리터럴이면 주석의 경로와 무관하게 판정이 끝난다'
        );

        $this->assertNull(
            $this->loadsFile("<?php\nrequire \$var;\n", 'includes/config.php'),
            '동적 require 는 「판정 불가」다 — false 로 접으면 소비처가 모집단 밖으로 읽는다'
        );
        $this->assertNull(
            $this->loadsFile("<?php\nforeach (\$files as \$file) {\n    require \$root.'/public/install/api/'.\$file;\n}\n", 'api/save-extensions.php'),
            '파일명이 변수인 require 가 정의자의 디렉토리를 가리키면 판정 불가다'
        );
        $this->assertFalse(
            $this->loadsFile("<?php\n\$root = dirname(__DIR__, 3).'/public/install/lang';\n\$t = require \$root.'/'.\$locale.'.php';\n", 'api/save-extensions.php'),
            '변수를 한 단계 풀면 다른 디렉토리(lang/)임이 드러난다 — 판정 불가로 남기면 거짓 red 가 된다'
        );
        $this->assertFalse(
            $this->loadsFile("<?php\n\$file = \$projectRoot.'/lang/'.\$locale.'/'.\$domain.'.php';\n\$t = include \$file;\n", 'api/save-extensions.php'),
            '인스톨러 트리 밖의 디렉토리 리터럴은 판정 가능(false)이다'
        );

        // 실물 형태 셋 — 생산 소스(`execution-mode.php`·`artisan-executor.php`·
        // `installer-runtime.php`)의 동적 require. 풀지 못하면 그 파일들이 전부 전이
        // 정의자가 되어 그것을 로드하는 테스트 전수에 거짓 격리 의무가 생긴다.
        $foreachRequire = "<?php\nforeach (['A' => '/app/Support/A.php', 'B' => '/app/Support/B.php'] as \$class => \$relativePath) {\n    require_once \$root.\$relativePath;\n}\n";
        $constantRequire = "<?php\ndefine('RUNTIME_PATH', BASE_PATH.'/storage/installer/runtime.php');\n\$data = @include RUNTIME_PATH;\n";
        $interpolatedRequire = "<?php\n\$langFile = __DIR__.\"/../lang/{\$lang}.php\";\n\$t = require \$langFile;\n";

        $this->assertFalse($this->loadsFile($foreachRequire, 'config.php'), 'foreach 값 변수는 배열 리터럴로 푼다');
        $this->assertFalse($this->loadsFile($constantRequire, 'config.php'), '상수는 define 으로 푼다');
        // 보간 문자열의 디렉토리 조각(`lang/`)은 needle 에 디렉토리가 **있을 때만** 배제 근거다.
        // 파일명뿐인 needle 은 어느 디렉토리의 그 이름인지 말하지 않으므로 판정 불가(null)가
        // 정답이다 — 종전에는 이 자리를 false 로 잠갔고, 그것이 W4-7 이 고친 fail-open 이다.
        $this->assertNull($this->loadsFile($interpolatedRequire, 'config.php'), '파일명뿐인 needle 에서 디렉토리 조각은 배제 근거가 아니다 — 판정 불가다');
        $this->assertFalse($this->loadsFile($interpolatedRequire, 'includes/config.php'), '디렉토리가 있는 needle 은 보간 문자열의 디렉토리 조각(lang/)으로 배제한다');
    }

    /**
     * 경로 대조는 세그먼트 경계다 — `config.php` 는 `some-config.php` 에 맞지 않는다.
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
     */
    public function test_loads_file_matches_on_segment_boundaries(): void
    {
        $this->assertFalse($this->loadsFile("<?php\nrequire_once __DIR__.'/some-config.php';\n", 'config.php'));
        $this->assertFalse($this->loadsFile("<?php\nrequire_once __DIR__.'/xconfig.php';\n", 'config.php'));
        $this->assertFalse($this->loadsFile("<?php\nrequire_once __DIR__.'/config.php.bak';\n", 'config.php'));
        $this->assertTrue($this->loadsFile("<?php\nrequire_once __DIR__.'/config.php';\n", 'config.php'));
    }

    /**
     * `sourceWithoutComments()` — 파일을 읽으면서 그 언어의 주석을 지운다 (W5-1 공용 진입점).
     *
     * 이관한 게이트 전부가 이 한 지점을 지난다. 여기가 주석을 남기면 20여 게이트가 한꺼번에
     * 「주석 속 문장」을 통과시키므로, 두 언어·뷰 안의 `<script>` 까지 이 픽스처가 잰다.
     *
     * @effects installer_source_function_scanner_discriminates_known_failure_modes
     *
     * @return void 반환값 없음
     */
    public function test_source_without_comments_strips_by_language(): void
    {
        $dir = sys_get_temp_dir().'/g7-scan-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);

        try {
            file_put_contents($dir.'/a.php', "<?php\n// installerWired(\n/* installerWired( */\n\$u = 'https://x/y'; # installerWired(\nlive();\n");
            file_put_contents($dir.'/b.js', "// installerWired(\n/* installerWired( */\nconst u = 'https://x/y';\nlive();\n");
            file_put_contents($dir.'/c.php', "<?php echo 1; ?>\n<script>\n// installerWired(\nlive();\n</script>\n");
            file_put_contents($dir.'/d.txt', "x\n");

            foreach (['a.php', 'b.js', 'c.php'] as $name) {
                $stripped = $this->sourceWithoutComments($dir.'/'.$name);

                $this->assertStringNotContainsString('installerWired(', $stripped, $name.' 의 주석이 남았다');
                $this->assertStringContainsString('live();', $stripped, $name.' 의 코드가 사라졌다');
            }

            $this->assertStringContainsString("'https://x/y'", $this->sourceWithoutComments($dir.'/a.php'), '문자열 안의 // 는 주석이 아니다');
            $this->assertStringContainsString("'https://x/y'", $this->sourceWithoutComments($dir.'/b.js'));

            // 언어를 명시하면 확장자보다 우선한다.
            $this->assertStringNotContainsString('installerWired(', $this->sourceWithoutComments($dir.'/b.js', false));

            $this->expectException(\RuntimeException::class);
            $this->sourceWithoutComments($dir.'/d.txt');
        } finally {
            foreach (['a.php', 'b.js', 'c.php', 'd.txt'] as $name) {
                @unlink($dir.'/'.$name);
            }
            @rmdir($dir);
        }
    }
}
