<?php

namespace Tests\Concerns;

/**
 * 소스에서 함수 경계를 잡는 검출기 — 인스톨러 커버리지 테스트 3종의 공용 판정 (11차 #D11).
 *
 * 종전에는 같은 두 헬퍼가 세 테스트 파일에 문자 그대로 복제돼 있었고, 형제 검출기들과 달리
 * 「검출기가 실제로 구분하는가」를 보는 픽스처가 하나도 없었다. 두 판정 다 뒤집혀도 red 가
 * 없었고, 뒤집히면 커버리지 테스트가 **엉뚱한 함수 본문**을 보면서 초록을 낸다.
 *
 * 알려진 실패 모드 넷을 여기서 처리한다:
 *  ① 이름이 접두사인 형제(`run` / `runTask`)가 먼저 정의돼 있으면 `strpos` 첫 일치가
 *     다른 함수를 가리킨다.
 *  ② 함수가 끝난 뒤의 최상위 코드가 「마지막 함수 안」으로 귀속된다.
 *  ③ PHP 뷰의 `<script>` 안에 있는 JS `function` 선언이 PHP 함수로 계상된다.
 *  ④ 본문이 중괄호를 담은 **문자열 리터럴**(`'${'` 등)을 가지면 중괄호 균형이 어긋나
 *     경계 판정이 통째로 실패한다 (14차 C11-7).
 *  ⑤ `<script>` 구간을 텍스트 정규식으로 찾으면 PHP 문자열 안의 태그가 구간이 되고,
 *     JS 문자열 안의 `</script>` 뒤·닫히지 않은 구간이 조용히 원문으로 남는다 —
 *     토큰으로 찾고 짝이 맞지 않으면 예외다 (G3 회차 14 W4-4).
 *  ⑥ JS 의 `/` 를 직전 **한 글자**로 가르면 `i++ / 2`·`return /x{/`·PHP 인라인 구간 뒤의
 *     `/` 에서 정규식과 나눗셈이 뒤바뀐다 — 직전 토큰으로 가른다 (G3 회차 14 W4-6).
 */
trait ScansPhpSourceFunctions
{
    /**
     * `<script>` 구간을 걷어낸다 — 뷰 파일의 JS 선언이 PHP 함수로 계상되는 것을 막는다.
     *
     * 길이를 보존하기 위해 같은 길이의 공백으로 바꾼다(오프셋 기반 판정이 어긋나지 않도록).
     * 구간 판정은 `htmlScriptRegions()` 가 한다 — PHP 가 아닌 소스(JS)에는 HTML 구간이
     * 없으므로 그대로 돌려준다.
     *
     * @param  string  $source  소스
     * @return string 스크립트 구간이 비워진 소스
     */
    private function withoutInlineScripts(string $source): string
    {
        if (! $this->sourceIsPhp($source)) {
            return $source;
        }

        foreach ($this->htmlScriptRegions($source) as [$start, $end]) {
            $source = substr($source, 0, $start).str_repeat(' ', $end - $start).substr($source, $end);
        }

        return $source;
    }

    /**
     * PHP 소스의 HTML `<script>` 구간 — 내용의 `[시작, 끝)` 오프셋 목록.
     *
     * **토큰으로** 찾는다. 여는·닫는 태그는 `T_INLINE_HTML` 토큰 안에 있는 것만 인정한다 —
     * PHP 문자열 안의 `'<script>'`(`functions.php` 의 `echo '<script>';`)는
     * `T_CONSTANT_ENCAPSED_STRING` 이라 HTML 구간이 아니다. 텍스트로 찾으면 그 문자열
     * 사이가 JS 로 읽혀 PHP 문자열 내용이 주석으로 지워진다.
     *
     * 구간은 **첫 `</script` 에서 끝난다** — JS 문자열 안이라도. 브라우저가 그렇게 읽고
     * (스크립트 데이터 상태는 `</script` 만 본다), 다르게 읽으면 실제 실행과 판정이 갈린다.
     * 그 뒤가 「JS 로 다시 시작」한다고 가정하지 않는다 — 문자열 안의 `</script>` 뒤에 진짜
     * 닫는 태그가 오면 그것은 여는 태그 없는 닫는 태그이고, 여는 태그에 닫는 태그가 없으면
     * 구간이 끝나지 않은 것이다. 둘 다 **예외**다(`matchingBrace()` 규율) — 종전 정규식은
     * 앞엣것을 조용히 원문으로 두고 뒤엣것은 매치가 없어 원문 그대로 돌려줬는데, 그
     * 상태에서도 「스트리퍼를 거쳤다」는 사실은 변하지 않아 위생 검사는 초록이고 그 구간의
     * 주석 채널 방어만 사라진다(fail-open) (G3 회차 14 W4-4).
     *
     * @param  string  $source  PHP 소스
     * @return list<array{0:int, 1:int}> 내용 구간 (오름차순)
     *
     * @throws \RuntimeException 여는 태그에 닫는 태그가 없거나, 여는 태그 없는 닫는 태그가 있을 때
     */
    private function htmlScriptRegions(string $source): array
    {
        $ranges = [];
        $offset = 0;

        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                $ranges[] = [$offset, $offset + strlen($text)];
            }

            $offset += strlen($text);
        }

        // 인라인 HTML 안의 **HTML 주석**(`<!-- … -->`) 구간 — 그 안의 `<script>` 는 브라우저가
        // 실행하지 않는 태그라 JS 구간이 아니다. 종전에는 이 구간을 몰라 `<!-- <script> -->` 한
        // 줄이 「닫는 태그 없는 여는 태그」로 읽혀 예외(fail-closed 거짓 red)를 냈다 (G3 회차 15 F-34).
        $commentRanges = [];
        foreach ($ranges as [$from, $to]) {
            $pos = $from;
            while (($open = strpos($source, '<!--', $pos)) !== false && $open < $to) {
                $close = strpos($source, '-->', $open + 4);
                $end = $close === false ? $to : min($to, $close + 3);
                $commentRanges[] = [$open, $end];
                $pos = $end;
            }
        }

        $inHtml = static function (int $at) use ($ranges, $commentRanges): bool {
            foreach ($commentRanges as [$from, $to]) {
                if ($at >= $from && $at < $to) {
                    return false;
                }
            }
            foreach ($ranges as [$from, $to]) {
                if ($at >= $from && $at < $to) {
                    return true;
                }
            }

            return false;
        };

        // 인라인 HTML 안에서 다음 등장 위치 — 문자열·주석 토큰 안의 것은 건너뛴다.
        $nextInHtml = static function (string $needle, int $from) use ($source, $inHtml): ?int {
            $pos = $from;

            while (($pos = stripos($source, $needle, $pos)) !== false) {
                if ($inHtml($pos)) {
                    return $pos;
                }

                $pos += strlen($needle);
            }

            return null;
        };

        $regions = [];
        $pos = 0;

        while (true) {
            $open = $nextInHtml('<script', $pos);
            $close = $nextInHtml('</script', $pos);

            if ($open === null && $close === null) {
                break;
            }

            if ($close !== null && ($open === null || $close < $open)) {
                throw new \RuntimeException(
                    'HTML script 구간 판정: '.$close.' 위치에 여는 태그 없는 `</script>` 가 있다 — '
                    .'앞선 구간이 JS 문자열 안의 `</script>` 에서 이미 끝났거나 태그 짝이 맞지 않는다'
                );
            }

            // `<script` 다음 글자가 태그 이름의 연장이면(`<scripts`) 태그가 아니다.
            $after = $open + strlen('<script');
            if ($after < strlen($source) && preg_match('/[A-Za-z0-9_-]/', $source[$after]) === 1) {
                $pos = $after;

                continue;
            }

            $tagEnd = $nextInHtml('>', $after);
            if ($tagEnd === null) {
                throw new \RuntimeException('HTML script 구간 판정: '.$open.' 위치의 `<script` 여는 태그가 닫히지 않는다');
            }

            $closer = $nextInHtml('</script', $tagEnd + 1);
            if ($closer === null) {
                throw new \RuntimeException(
                    'HTML script 구간 판정: '.$open.' 위치의 `<script>` 에 대응하는 `</script>` 가 없다 — '
                    .'그 구간을 원문으로 두면 안의 주석이 대조를 통과한다'
                );
            }

            $regions[] = [$tagEnd + 1, $closer];
            $pos = $closer + strlen('</script');
        }

        return $regions;
    }

    /**
     * 이 오프셋을 감싸는 PHP 함수 이름.
     *
     * 중괄호 깊이를 세어 **함수가 끝난 뒤의 최상위 코드**를 그 함수에 귀속시키지 않는다.
     *
     * @param  string  $source  PHP 소스
     * @param  int  $offset  대상 위치
     * @return string 함수 이름 (최상위면 '(none)')
     */
    private function enclosingFunctionName(string $source, int $offset): string
    {
        $scannable = $this->withoutInlineScripts($source);

        if (preg_match_all('/function\s+([A-Za-z0-9_]+)\s*\(/', $scannable, $found, PREG_OFFSET_CAPTURE) === 0) {
            return '(none)';
        }

        $enclosing = '(none)';

        foreach ($found[1] as $i => $match) {
            $declaredAt = $found[0][$i][1];
            if ($declaredAt > $offset) {
                break;
            }

            $open = $this->nextOpeningBrace($scannable, $declaredAt);
            if ($open === null) {
                continue;   // 인터페이스·추상 선언
            }

            $close = $this->matchingBrace($scannable, $open);
            if ($offset > $open && $offset < $close) {
                $enclosing = $match[0];
            }
        }

        return $enclosing;
    }

    /**
     * 이름으로 지목한 함수의 본문.
     *
     * `strpos` 첫 일치가 아니라 **정확한 이름 경계**로 찾는다 — 접두사가 같은 형제가 먼저
     * 정의돼 있으면 종전 구현은 다른 함수 본문을 돌려줬다.
     *
     * @param  string  $source  PHP 소스
     * @param  string  $name  함수 이름
     * @return string 본문 (없으면 빈 문자열)
     */
    private function functionBody(string $source, string $name): string
    {
        $scannable = $this->withoutInlineScripts($source);

        if (preg_match('/function\s+'.preg_quote($name, '/').'\s*\(/', $scannable, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $open = $this->nextOpeningBrace($scannable, $m[0][1]);
        if ($open === null) {
            return '';
        }

        $close = $this->matchingBrace($scannable, $open, $this->sourceIsPhp($source));

        return substr($source, $open, $close - $open + 1);
    }

    /**
     * 이 소스가 PHP 인가 — 언어 판정의 단일 지점.
     *
     * **전체 소스**에만 물어야 한다. 함수 본문 같은 부분문자열에는 `<?php` 가 없어
     * 언제나 「JS」로 떨어진다 (12차 G3 라운드 B-1).
     *
     * @param  string  $source  전체 소스
     * @return bool PHP 이면 true
     */
    private function sourceIsPhp(string $source): bool
    {
        return str_contains($source, '<?php');
    }

    /**
     * 소스의 **코드 중괄호** 위치 표 (오프셋 => `{` 또는 `}`).
     *
     * 문자로 세지 않고 토크나이저로 뽑는다. 문자 스캔은 문자열 리터럴 안의 중괄호를
     * 그대로 깊이에 넣어, `'${'` 한 조각만 있어도 그 함수의 경계 판정이 통째로 실패한다.
     * 실물이 그 상태였다 — `escapeEnvValue()`(`functions.php`·`installer-runtime.php`)의
     * 본문 도출이 `''` 를, 그 안의 호출에 대한 소속 판정이 `'(none)'` 을 돌려줬고,
     * 소비하는 커버리지 검사는 `'(none)'` 을 「함수 밖」으로 읽어 판정 범위를 파일 전체로
     * 넓혔다(fail-open) (14차 C11-7).
     *
     * 보간이 여는 중괄호(`"{$x}"` 의 `T_CURLY_OPEN`, `"${x}"` 의 `T_DOLLAR_OPEN_CURLY_BRACES`)
     * 도 여는 쪽으로 센다 — 닫는 쪽은 평문 `}` 로 오므로 세지 않으면 깊이가 한 칸 내려가
     * 블록이 그 자리에서 끝난 것으로 읽힌다.
     *
     * @param  string  $source  PHP 소스
     * @param  bool|null  $isPhp  소스가 PHP 인지 호출부가 아는 값 (null 이면 `<?php` 포함 여부로 추론)
     * @return array<int, string> 오프셋 => 중괄호 문자 (오프셋 오름차순)
     */
    private function braceOffsets(string $source, ?bool $isPhp = null): array
    {
        static $cache = [];

        $key = md5($source).'|'.($isPhp === null ? 'auto' : ($isPhp ? 'php' : 'js'));

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        // 이 트레이트의 소비처에는 **JavaScript 를 스캔하는 검사도 있다**
        // (`installer.js` · `installation-monitor.js` 의 함수 본문 도출). `token_get_all()`
        // 은 PHP 전용이라 JS 를 넣으면 파일 전체가 인라인 HTML 토큰 하나로 와서 중괄호
        // 표가 통째로 비고, 그 결과 함수 본문이 전부 `''` 가 된다 — 검사는 「그 함수를
        // 찾지 못했다」로 red 가 되지만 원인은 대상 코드가 아니라 도출기다.
        //
        // 그래서 PHP 로 판정되는 소스에만 토크나이저를 쓰고, 그 밖은 문자열·주석을
        // 인지하는 문자 스캐너로 센다. 두 경로 모두 문자열 안의 중괄호를 깊이에 넣지
        // 않는다는 성질은 같다 (14차 C11-7 의 목적).
        // 언어를 **추론**하면 함수 본문에서 뒤집힌다 — `functionBody()` 가 돌려주는 것은
        // 여는 중괄호부터의 부분문자열이라 `<?php` 가 **절대** 들어 있지 않다. 그러면
        // 이 판정이 PHP 본문을 JS 문자 스캐너로 보내고, 그 스캐너는 heredoc·`#` 주석 같은
        // PHP 전용 문법을 모른다: 그 안의 중괄호를 코드로 세어 짝이 어긋나고, 소비처는
        // 예외(거짓 red)를 받거나 — 조건 블록 경로에서는 — 가드 밖 호출을 「가드 안」으로
        // 읽는다(fail-open). 14차가 고친 바로 그 결함이 소비처 한 겹 아래에서 살아 있었다.
        //
        // 그래서 아는 쪽이 말하게 한다: 파일을 고르는 소비처는 자기가 무엇을 스캔하는지
        // 알고 있으므로 `$isPhp` 로 전달하고, 전체 소스를 그대로 넘기는 자리에서만
        // 종전의 추론을 쓴다 (12차 G3 라운드 B-1).
        $php = $isPhp ?? str_contains($source, '<?php');

        if (! $php) {
            return $cache[$key] = $this->braceOffsetsByScan($source);
        }

        // `token_get_all()` 은 **완전한 소스**를 요구한다 — 여는 태그가 없으면 입력 전체를
        // 인라인 HTML 토큰 하나로 돌려주어 중괄호 표가 통째로 빈다. 함수 **본문**은 정의상
        // 여는 태그를 갖지 않으므로, 그 조각에는 태그를 앞에 붙여 토큰화하고 오프셋을
        // 되돌린다. 이렇게 해야 본문에서도 heredoc·`#` 주석·보간이 PHP 규칙으로 읽힌다.
        $prefix = str_contains($source, '<?php') ? '' : '<?php ';
        $shift = strlen($prefix);

        $map = [];
        $offset = 0;

        foreach (token_get_all($prefix.$source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $at = $offset + strlen($token[1]) - 1 - $shift;

                    if ($at >= 0) {
                        $map[$at] = '{';
                    }
                }

                $offset += strlen($token[1]);

                continue;
            }

            if ($token === '{' || $token === '}') {
                $at = $offset - $shift;

                if ($at >= 0) {
                    $map[$at] = $token;
                }
            }

            $offset += strlen($token);
        }

        return $cache[$key] = $map;
    }

    /**
     * JS 소스의 **코드 중괄호** 표 — 문자열·템플릿·정규식 리터럴·주석 안은 세지 않는다.
     *
     * 종전에는 문자를 그대로 세었다. 「문자열 안의 중괄호는 여닫이가 짝을 이루므로 깊이
     * 계산에서 상쇄된다」는 전제였는데, 그 전제는 **홑중괄호** 한 글자에서 무너진다 —
     * `'{'` 는 짝이 없어 `matchingBrace()` 가 예외를 던지고(거짓 red), `'}'` 는 본문을 그
     * 자리에서 끊는다. 끊긴 본문 뒤의 호출은 `enclosingFunctionName()` 이 `'(none)'` 으로
     * 돌려주고, 소비처는 그 값을 「함수 밖」으로 읽어 판정 범위를 넓힌다(fail-open). 정규식
     * 리터럴 `/\{/` 과 `// }` 주석도 같은 형태다 (G3 회차 13 W5-3).
     *
     * 리터럴 판정은 `stripJsComments()` 와 같은 규칙이다 — 주석이 먼저, 그 다음 문자열·
     * 템플릿(`consumeJsTemplateLiteral()` 이 중첩을 흡수한다)·정규식(직전 유의미 문자가
     * 피연산자면 나눗셈). 오프셋은 원문 기준으로 보존한다.
     *
     * @param  string  $source  비-PHP 소스
     * @return array<int, string> 오프셋 => 중괄호 문자 (오프셋 오름차순)
     */
    private function braceOffsetsByScan(string $source): array
    {
        $map = [];
        $len = strlen($source);
        $i = 0;

        while ($i < $len) {
            $ch = $source[$i];
            $next = $i + 1 < $len ? $source[$i + 1] : '';

            // PHP 인라인 구간(여는 태그 `<?=` / `<?php` 부터 닫는 태그까지) — 뷰의 `<script>`
            // 안에서 만난다. 치환 결과가 값이므로 피연산자이고, 안의 중괄호는 JS 의 것이 아니다.
            // (이 주석에 닫는 태그를 적지 않는다 — 줄 주석 안의 닫는 태그는 PHP 를 실제로 닫는다.)
            if ($ch === '<' && $next === '?') {
                $end = strpos($source, '?>', $i + 2);
                $i = $end === false ? $len : $end + 2;

                continue;
            }

            if ($ch === '/' && $next === '/') {
                while ($i < $len && $source[$i] !== "\n" && $source[$i] !== "\r") {
                    $i++;
                }

                continue;
            }

            if ($ch === '/' && $next === '*') {
                $end = strpos($source, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;

                continue;
            }

            if ($ch === '`') {
                $sink = '';
                $i = $this->consumeJsTemplateLiteral($source, $i + 1, $len, $sink);

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $i++;
                while ($i < $len) {
                    if ($source[$i] === '\\') {
                        $i += 2;

                        continue;
                    }
                    if ($source[$i] === $ch) {
                        $i++;
                        break;
                    }
                    $i++;
                }

                continue;
            }

            if ($ch === '/' && $this->jsSlashStartsRegexLiteral($source, $i)) {
                $i++;
                $inClass = false;
                while ($i < $len) {
                    $c = $source[$i];
                    if ($c === '\\') {
                        $i += 2;

                        continue;
                    }
                    if ($c === '[') {
                        $inClass = true;
                    } elseif ($c === ']') {
                        $inClass = false;
                    } elseif (($c === '/' && ! $inClass) || $c === "\n") {
                        $i++;
                        break;
                    }
                    $i++;
                }

                continue;
            }

            if ($ch === '{' || $ch === '}') {
                $map[$i] = $ch;
            }

            $i++;
        }

        return $map;
    }

    /**
     * 이 위치의 `/` 가 정규식 리터럴의 시작인가 (아니면 나눗셈).
     *
     * 직전 유의미 토큰으로 판정한다 — **한 글자**가 아니라 토큰이다 (G3 회차 14 W4-6).
     * 종전 판정(`preg_match('/[A-Za-z0-9_$)\]]/', $lastSignificant)`)은 두 방향으로 틀렸다:
     *  · `i++ / 2` — 직전 글자 `+` 가 피연산자 문자가 아니라 정규식으로 읽혀, 그 줄 끝까지의
     *    중괄호·주석이 리터럴 안으로 삼켜졌다. 후위 `++`/`--` 뒤는 피연산자다.
     *  · `return /x{/` — 직전 글자 `n` 이 식별자 문자라 나눗셈으로 읽혀, 리터럴 안의 `{` 가
     *    코드 중괄호로 계수됐다. 키워드 뒤는 피연산자가 올 수 없으므로 정규식이다.
     *  · `<?= $n ?> / 2` — 직전 글자 `>` 가 정규식으로 읽혔다. PHP 인라인 구간은 치환 결과가
     *    값이므로 피연산자다.
     *
     * 문자열·템플릿 리터럴의 닫는 따옴표·백틱, `)`·`]`, 숫자·식별자(키워드 제외) 뒤는 나눗셈이고
     * 그 밖(연산자·여는 괄호·`;`·`{`·`}`·소스 시작)은 정규식이다. `}` 는 블록 끝과 객체 리터럴
     * 끝이 구분되지 않아 정규식 쪽으로 둔다(종전과 같다).
     *
     * @param  string  $source  JS 소스
     * @param  int  $slashAt  `/` 의 위치
     * @return bool 정규식 리터럴이면 true
     */
    private function jsSlashStartsRegexLiteral(string $source, int $slashAt): bool
    {
        $j = $slashAt - 1;

        while ($j >= 0 && ctype_space($source[$j])) {
            $j--;
        }

        if ($j < 0) {
            return true;
        }

        $prev = $source[$j];

        // 후위 증감 뒤 — `i++ / 2`
        if (($prev === '+' || $prev === '-') && $j > 0 && $source[$j - 1] === $prev) {
            return false;
        }

        // PHP 인라인 구간의 끝(닫는 태그 뒤의 `/`) — 독블록의 세 번째 형태
        if ($prev === '>' && $j > 0 && $source[$j - 1] === '?') {
            return false;
        }

        if (in_array($prev, [')', ']', '"', "'", '`'], true)) {
            return false;
        }

        if (preg_match('/[A-Za-z0-9_$]/', $prev) !== 1) {
            return true;
        }

        $start = $j;
        while ($start > 0 && preg_match('/[A-Za-z0-9_$]/', $source[$start - 1]) === 1) {
            $start--;
        }

        $word = substr($source, $start, $j - $start + 1);

        return in_array($word, [
            'return', 'typeof', 'case', 'in', 'of', 'delete', 'void', 'new', 'do', 'else',
            'yield', 'await', 'throw', 'instanceof',
        ], true);
    }

    /**
     * 파일을 읽으면서 그 언어의 주석을 지운 소스 — 문자 대조 게이트의 단일 진입점.
     *
     * 원문(`file_get_contents`)을 `assertStringContainsString` 류로 대조하면 **주석 속
     * 문장**도 통과한다 — 호출 줄을 `//` 로 감싸도 글자는 남는다(실증: 상태 보존 판정 호출을
     * 주석 처리해도 게이트가 초록이었다). 20여 게이트가 그 형태였고, 각자 원문을 읽은 뒤
     * 스트리퍼를 부르는 것을 잊거나 언어를 잘못 골랐다(JS 파일에 PHP 토크나이저 — 파일
     * 전체가 인라인 HTML 토큰 하나라 아무것도 지워지지 않는다). 그래서 읽기와 지우기를 한
     * 지점에 묶는다 (G3 회차 13 W5-1).
     *
     * 언어는 아는 쪽이 말한다 — `$isPhp` 를 주면 그것이 우선이고, 없으면 확장자로 정한다.
     * 확장자로도 정할 수 없는 파일은 **예외**다: 조용히 원문을 돌려주면 그 호출부가 이관
     * 전과 같은 사각으로 돌아간다.
     *
     * PHP 뷰의 `<script>` 안은 JS 라 그 구간은 JS 규칙으로 한 번 더 지운다 — 뷰에 주석
     * 처리된 배선이 남으면 `installerXxx(` 대조가 그 주석을 통과한다. 구간은
     * `htmlScriptRegions()` 가 토큰으로 정한다 — 문자열 안 `</script>` 는 브라우저와 같이
     * 종료로 보되 짝이 맞지 않으면 예외다(그 독블록 참조).
     *
     * CSS(`.css`)는 블록 주석만 갖는 언어라 그것만 지운다 — JS 규칙을 쓰면 `url(http://…)`
     * 의 `//` 가 줄 주석으로 읽혀 그 줄이 잘린다.
     *
     * @param  string  $path  파일 경로
     * @param  bool|null  $isPhp  PHP 이면 true, JS/TS 이면 false, null 이면 확장자로 판정
     * @return string 주석이 제거된 소스
     *
     * @throws \RuntimeException 파일을 읽을 수 없거나 언어를 정할 수 없을 때, `<script>` 짝이 맞지 않을 때
     */
    private function sourceWithoutComments(string $path, ?bool $isPhp = null): string
    {
        if (! is_file($path)) {
            throw new \RuntimeException('소스 파일이 없다: '.$path);
        }

        if ($isPhp === null) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if ($extension === 'css') {
                return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));
            }

            $isPhp = match ($extension) {
                'php' => true,
                'js', 'ts', 'mjs', 'cjs' => false,
                default => throw new \RuntimeException('주석 규칙을 정할 수 없는 확장자다 — $isPhp 를 명시할 것: '.$path),
            };
        }

        $source = (string) file_get_contents($path);

        if (! $isPhp) {
            return $this->stripJsComments($source);
        }

        // PHP 열기 태그가 없는 파일은 이 스트리퍼의 대상이 아니다 — 토크나이저가 파일 전체를 인라인
        // HTML 한 토큰으로 읽어 **아무것도 지우지 않은 원문**을 돌려주고, 그 상태에서도 「스트리퍼를
        // 거쳤다」는 사실은 변하지 않아 위생 검사는 초록이다(fail-open). 예외로 세운다 (G3 회차 15 F-31).
        if (preg_match('/<\?(?:php\b|=|\s)/', $source) !== 1) {
            throw new \RuntimeException('PHP 열기 태그가 없는 파일은 이 스트리퍼의 대상이 아니다 — 원문이 그대로 돌아와 주석 채널 방어가 사라진다: '.$path);
        }

        $stripped = $this->stripComments($source);

        // 뒤에서부터 치환한다 — JS 주석 제거는 길이를 바꾸므로 앞 구간을 먼저 바꾸면 뒤
        // 구간의 오프셋이 밀린다.
        foreach (array_reverse($this->htmlScriptRegions($stripped)) as [$start, $end]) {
            $stripped = substr($stripped, 0, $start)
                .$this->stripJsComments(substr($stripped, $start, $end - $start))
                .substr($stripped, $end);
        }

        return $stripped;
    }

    /**
     * 주석에 더해 **문자열 리터럴의 내용**까지 지운 소스 — 배선을 문자로 재는 게이트용.
     *
     * 주석만 지우면 nowdoc/heredoc·일반 문자열이 **글자 채널**로 남는다: 소비처가
     * `assertStringContainsString('installerXxx(', …)` 로 배선을 재는 형태에서, 그 이름이 안내
     * 문구·nowdoc 안에 있으면 실제 호출이 사라져도 초록이다 (G3 회차 15 F-32). 이 헬퍼는
     * 문자열 **내용**을 같은 길이의 공백으로 바꾼다 — 따옴표·줄 수는 보존해 오프셋 판정이 어긋나지
     * 않는다. PHP 는 토큰으로(heredoc/nowdoc 본문 포함), 뷰의 `<script>` 구간과 JS 파일은
     * 따옴표·템플릿 리터럴을 훑어 지운다(`${…}` 안은 코드라 남긴다).
     *
     * @param  string  $path  파일 경로
     * @param  bool|null  $isPhp  PHP 이면 true, JS/TS 이면 false, null 이면 확장자로 판정
     * @return string 주석과 문자열 내용이 제거된 소스
     *
     * @throws \RuntimeException `sourceWithoutComments()` 와 같은 조건
     */
    private function sourceWithoutCommentsAndStrings(string $path, ?bool $isPhp = null): string
    {
        $stripped = $this->sourceWithoutComments($path, $isPhp);

        if ($isPhp === null) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($extension === 'css') {
                return $stripped;
            }
            $isPhp = $extension === 'php';
        }

        if (! $isPhp) {
            return $this->blankJsStrings($stripped);
        }

        $out = '';
        foreach (token_get_all($stripped) as $token) {
            if (! is_array($token)) {
                $out .= $token;

                continue;
            }
            [$id, $text] = $token;
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                // 따옴표는 남기고 내용만 공백으로 — 길이·줄 수 보존.
                $out .= $text[0].$this->blankKeepingNewlines(substr($text, 1, -1)).substr($text, -1);

                continue;
            }
            if ($id === T_ENCAPSED_AND_WHITESPACE) {
                // 보간 문자열·heredoc/nowdoc 본문.
                $out .= $this->blankKeepingNewlines($text);

                continue;
            }
            if ($id === T_INLINE_HTML) {
                // 뷰의 `<script>` 안 JS 문자열 — HTML 텍스트는 그대로 두고 JS 구간만 지운다.
                $out .= $this->blankJsStringsInScriptTags($text);

                continue;
            }
            $out .= $text;
        }

        return $out;
    }

    /**
     * 개행만 남기고 공백으로 바꾼다 (길이·줄 수 보존).
     *
     * @param  string  $text  원문
     * @return string 공백으로 바뀐 문자열
     */
    private function blankKeepingNewlines(string $text): string
    {
        return (string) preg_replace('/[^\r\n]/', ' ', $text);
    }

    /**
     * 인라인 HTML 조각의 `<script>…</script>` 안에서만 JS 문자열을 지운다.
     *
     * @param  string  $html  인라인 HTML 토큰 텍스트
     * @return string 처리된 텍스트
     */
    private function blankJsStringsInScriptTags(string $html): string
    {
        return (string) preg_replace_callback(
            '/(<script\b[^>]*>)(.*?)(<\/script)/is',
            fn (array $m): string => $m[1].$this->blankJsStrings($m[2]).$m[3],
            $html
        );
    }

    /**
     * JS 소스의 문자열·템플릿 리터럴 **내용**을 공백으로 바꾼다 (따옴표·줄 수 보존).
     *
     * 템플릿 리터럴의 `${…}` 안은 코드라 남긴다 — 중첩 백틱은 중괄호 깊이로 따라간다.
     * 정규식 리터럴은 건드리지 않는다(주석은 이미 지워져 있으므로 슬래시는 코드다).
     *
     * @param  string  $js  주석을 지운 JS 소스
     * @return string 문자열 내용이 지워진 소스
     */
    private function blankJsStrings(string $js): string
    {
        $out = '';
        $length = strlen($js);
        $i = 0;

        while ($i < $length) {
            $ch = $js[$i];

            if ($ch === "'" || $ch === '"') {
                $out .= $ch;
                $i++;
                while ($i < $length && $js[$i] !== $ch) {
                    if ($js[$i] === "\n" || $js[$i] === "\r") {
                        // 닫히지 않은 채 줄이 끝나면 문자열이 아니었다 — 그대로 둔다.
                        break;
                    }
                    $out .= ' ';
                    if ($js[$i] === '\\') {
                        $i++;
                        if ($i < $length && $js[$i] !== "\n") {
                            $out .= ' ';
                        }
                    }
                    $i++;
                }
                if ($i < $length && $js[$i] === $ch) {
                    $out .= $ch;
                    $i++;
                }

                continue;
            }

            if ($ch === '`') {
                $out .= $ch;
                $i = $this->blankJsTemplateLiteral($js, $i + 1, $length, $out);

                continue;
            }

            $out .= $ch;
            $i++;
        }

        return $out;
    }

    /**
     * 템플릿 리터럴 하나의 내용을 지운다 (여는 백틱 다음 오프셋에서 진입). `${…}` 안은 재귀로 코드 취급.
     *
     * @param  string  $js  JS 소스
     * @param  int  $i  시작 오프셋
     * @param  int  $length  소스 길이
     * @param  string  $out  누적 출력
     * @return int 닫는 백틱 다음 오프셋
     */
    private function blankJsTemplateLiteral(string $js, int $i, int $length, string &$out): int
    {
        while ($i < $length) {
            $ch = $js[$i];

            if ($ch === '`') {
                $out .= $ch;

                return $i + 1;
            }

            if ($ch === '$' && $i + 1 < $length && $js[$i + 1] === '{') {
                $out .= '${';
                $i += 2;
                $depth = 1;
                $inner = '';
                while ($i < $length && $depth > 0) {
                    $c = $js[$i];
                    if ($c === '{') {
                        $depth++;
                    } elseif ($c === '}') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                    $inner .= $c;
                    $i++;
                }
                // 보간 안은 코드 — 그 안의 문자열·중첩 템플릿은 다시 지운다.
                $out .= $this->blankJsStrings($inner);
                if ($i < $length) {
                    $out .= '}';
                    $i++;
                }

                continue;
            }

            $out .= ($ch === "\n" || $ch === "\r") ? $ch : ' ';
            $i++;
        }

        return $i;
    }

    /**
     * 이 PHP 소스가 지목한 파일을 **실제로 로드**하는가 — 판정 불가는 `null`.
     *
     * 판정은 토큰으로 한다. 문자열 대조로는 두 형태를 가를 수 없다:
     *  ① 주석 속 경로 — `// … (`public/install/includes/request-handler.php` 위임)`. 정규식에 단어
     *     경계가 없으면 `include` 가 **`includes`** 의 앞 일곱 글자에 매치된다.
     *  ② 픽스처로 **써 넣는** 코드 문자열 — `"<?php\n\nrequire __DIR__.'/public/index.php';"`
     *     는 그 파일에 쓰이는 데이터이지 이 클래스가 그것을 로드한다는 뜻이 아니다.
     *
     * `T_REQUIRE`/`T_INCLUDE` 계열 토큰을 찾고, 그 문장이 끝날 때까지(`;`) 뒤따르는
     * **문자열 토큰**에서만 경로를 본다 — 주석 토큰은 문장 안에 끼어 있어도 경로가 아니다
     * (종전에는 모든 배열 토큰을 봐서 `require $p` 뒤의 블록 주석이 경로로 읽혔다).
     *
     * 세 값을 돌려준다 (G3 회차 13 W5-4):
     *  - `true`  — 그 파일을 로드한다. 대조는 **세그먼트 경계**다(`config.php` 는
     *              `some-config.php` 에 맞지 않는다).
     *  - `false` — 로드하지 않는다. 파일명이 리터럴인 require 가 다른 파일을 가리키거나,
     *              변수를 한 단계 풀었을 때 인스톨러 트리 밖·다른 디렉토리임이 드러난 경우.
     *  - `null`  — **판정 불가**. `require $var;` 처럼 파일명이 변수라 무엇을 로드하는지
     *              소스만으로 알 수 없다. `false` 로 접으면 소비처가 「로드하지 않는다」로
     *              읽어 모집단 밖으로 뺀다(fail-open) — 소비처는 이 값을 보수적으로
     *              「로드할 수 있다」로 다룬다.
     *
     * @param  string  $src  PHP 소스
     *                       needle 의 **디렉토리**가 배제의 근거다 — `require $x.'/lang/'.$f` 는 needle 이
     *                       `'includes/config.php'` 이면 「다른 디렉토리」로 배제되지만, `config.php` 처럼 파일명뿐이면
     *                       어느 디렉토리의 그 이름인지 알 수 없어 판정 불가(`null`)다. 종전에는 이 경우도 `false`
     *                       로 접었다(fail-open) (G3 회차 14 W4-7). 스캔 트리의 **루트에 있는** 파일은 상대 경로에
     *                       디렉토리가 없으므로, 호출부가 `./index.php` 처럼 `./` 를 붙여 「트리 루트 기준」임을
     *                       선언한다 — 그러면 디렉토리 `./` 가 배제 근거가 된다.
     * @param  string  $needle  찾을 경로 조각 (`'api/x.php'` 같은 상대 경로, `'./index.php'` 같은 루트 기준 경로, 또는 파일명)
     * @return bool|null 로드하면 true, 아니면 false, 판정 불가면 null
     */
    private function loadsFile(string $src, string $needle): ?bool
    {
        $tokens = token_get_all($src);
        $count = count($tokens);
        $undecidable = false;
        // `./` 접두는 「트리 루트 기준」 선언이다 — 경로 대조에서는 벗기고, 디렉토리는 `./` 로 둔다.
        $rooted = str_starts_with($needle, './');
        $needle = $rooted ? substr($needle, 2) : $needle;
        $needleDir = str_contains($needle, '/') ? dirname($needle).'/' : ($rooted ? './' : '');
        $needleName = basename($needle);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || ! in_array($token[0], [
                T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE,
            ], true)) {
                continue;
            }

            $literal = '';
            $symbols = [];

            for ($j = $i + 1; $j < $count; $j++) {
                $arg = $tokens[$j];

                if ($arg === ';') {
                    break;
                }
                if (! is_array($arg)) {
                    continue;
                }
                if (in_array($arg[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    $literal .= trim($arg[1], '\'"');
                } elseif ($arg[0] === T_VARIABLE || ($arg[0] === T_STRING && preg_match('/^[A-Z][A-Z0-9_]+$/', $arg[1]) === 1)) {
                    $symbols[] = $arg[1];
                }
            }

            // 변수·상수를 한 단계 푼다 — `$root = dirname(__DIR__, 3).'/public/install/lang';`
            // 처럼 디렉토리가 대입식에 있으면 그것으로 판정할 수 있다.
            foreach ($symbols as $symbol) {
                $literal = $this->assignedLiteral($tokens, $symbol, $i).$literal;
            }

            if ($this->pathMatchesOnSegmentBoundary($literal, $needle)) {
                return true;
            }

            // 같은 디렉토리의 형제를 파일명만으로 부르는 형태 — `__DIR__.'/config.php'`.
            if ($needleDir !== '' && preg_match('#^/?'.preg_quote($needleName, '#').'$#', $literal) === 1) {
                return true;
            }

            // 파일명이 리터럴이면 판정이 끝난 것이다 — 다른 파일을 가리킨다.
            if (preg_match('#(?:^|/)[A-Za-z0-9_-][A-Za-z0-9_.-]*\.[A-Za-z0-9]+$#', $literal) === 1) {
                continue;
            }

            // 파일명이 변수다. 디렉토리 리터럴이 needle 의 디렉토리를 배제하면 false 로
            // 확정할 수 있고, 아니면 판정 불가다.
            if (preg_match('#public/install/?([^\'"]*)#', $literal, $m) === 1) {
                $dir = trim($m[1], '/');
                if ($dir !== '' && ! str_starts_with($needleDir, $dir.'/')) {
                    continue;   // 다른 디렉토리(예: lang/)를 가리킨다
                }
            } elseif (preg_match('#([A-Za-z0-9_-]+)/+[^/]*$#', $literal, $m) === 1) {
                // `/../lang/` · `/vendor/` — 마지막 디렉토리 세그먼트가 needle 의 디렉토리가
                // 아니면 그 파일은 아니다.
                //
                // needle 이 **파일명뿐**이면 배제할 근거가 없다 — 어느 디렉토리의 그 이름인지
                // 호출부가 말하지 않았으므로, 디렉토리 리터럴은 「다른 파일이다」를 증명하지
                // 못한다. 종전에는 그 경우도 `continue`(false)로 접어 「모른다」가 「로드하지
                // 않는다」로 바뀌었고, 소비처(격리 커버리지의 전이 정의자 도출이 basename 으로
                // 부른다)는 false 를 모집단 밖으로 읽었다(fail-open) (G3 회차 14 W4-7).
                if ($needleDir !== '' && ! str_ends_with($needleDir, $m[1].'/')) {
                    continue;
                }
            }

            $undecidable = true;
        }

        return $undecidable ? null : false;
    }

    /**
     * 변수·상수의 대입식에 실린 문자열 리터럴을 이어 붙인 것 (한 단계만 푼다).
     *
     * 세 형태를 푼다 — `$var = <식>;`, `foreach (<식> as [$k =>] $var)` 의 배열 리터럴,
     * `define('NAME', <식>)`. 변수는 require **직전**의 대입을 본다 — 파일 첫 대입을 보면
     * 다른 메서드의 같은 이름 변수(`foreach (… as $path)`)가 잡혀 판정이 어긋난다. 그 이상
     * (중첩 변수)은 따라가지 않는다 — 판정 불가로 남는 쪽이 틀린 확신보다 낫다.
     *
     * @param  array<int, array{0:int, 1:string}|string>  $tokens  토큰 목록
     * @param  string  $symbol  `$name` 또는 상수 이름
     * @param  int  $before  이 인덱스 앞의 대입만 본다 (require 토큰 위치)
     * @return string 리터럴 조각 (없으면 빈 문자열)
     */
    private function assignedLiteral(array $tokens, string $symbol, int $before): string
    {
        for ($i = $before - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                continue;
            }

            // `$var = …;`
            if ($token[0] === T_VARIABLE && $token[1] === $symbol && $this->significantToken($tokens, $i + 1, 1) === '=') {
                return $this->literalUntil($tokens, $i + 1, [';']);
            }

            // `foreach (… as $var)` / `foreach (… as $k => $var)`
            if ($token[0] === T_VARIABLE && $token[1] === $symbol) {
                $preceding = [];
                for ($k = $i - 1; $k >= 0 && count($preceding) < 3; $k--) {
                    if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $preceding[] = $tokens[$k];
                }
                $isForeachValue = (isset($preceding[0]) && is_array($preceding[0]) && $preceding[0][0] === T_AS)
                    || (isset($preceding[2]) && is_array($preceding[0]) && $preceding[0][0] === T_DOUBLE_ARROW
                        && is_array($preceding[2]) && $preceding[2][0] === T_AS);
                if ($isForeachValue) {
                    for ($k = $i - 1; $k >= 0; $k--) {
                        if (is_array($tokens[$k]) && $tokens[$k][0] === T_FOREACH) {
                            return $this->literalUntil($tokens, $k + 1, [T_AS]);
                        }
                    }
                }
            }
        }

        // `define('NAME', …)` — 상수는 파일 어디에 있어도 같은 값이다.
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'define') {
                $name = $this->significantToken($tokens, $i + 2, 1);
                if (is_array($name) && $name[0] === T_CONSTANT_ENCAPSED_STRING && trim($name[1], '\'"') === $symbol) {
                    return $this->literalUntil($tokens, $i + 3, [';']);
                }
            }
        }

        return '';
    }

    /**
     * 이 인덱스부터 종결 토큰 전까지의 문자열 리터럴을 이어 붙인다.
     *
     * @param  array<int, array{0:int, 1:string}|string>  $tokens  토큰 목록
     * @param  int  $from  시작 인덱스
     * @param  array<int, int|string>  $until  종결 토큰(문자 또는 토큰 ID)
     * @return string 리터럴 조각
     */
    private function literalUntil(array $tokens, int $from, array $until): string
    {
        $literal = '';
        $count = count($tokens);

        for ($j = $from; $j < $count; $j++) {
            $arg = $tokens[$j];
            if (! is_array($arg) && in_array($arg, $until, true)) {
                break;
            }
            if (is_array($arg) && in_array($arg[0], $until, true)) {
                break;
            }
            if (is_array($arg) && in_array($arg[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $literal .= trim($arg[1], '\'"');
            }
        }

        return $literal;
    }

    /**
     * 경로 조각이 **세그먼트 경계**에서 일치하는가.
     *
     * @param  string  $haystack  경로 리터럴
     * @param  string  $needle  찾을 경로 조각
     * @return bool 일치하면 true
     */
    private function pathMatchesOnSegmentBoundary(string $haystack, string $needle): bool
    {
        return preg_match('#(?<![A-Za-z0-9_.-])'.preg_quote($needle, '#').'(?![A-Za-z0-9_.-])#', $haystack) === 1;
    }

    /**
     * 이 위치 이후 첫 **코드** 여는 중괄호.
     *
     * 문자열·주석 안의 `{` 를 함수 본문의 시작으로 오인하지 않는다.
     *
     * @param  string  $source  PHP 소스
     * @param  int  $from  탐색 시작 위치
     * @return int|null 여는 중괄호 위치 (없으면 null)
     */
    private function nextOpeningBrace(string $source, int $from): ?int
    {
        foreach ($this->braceOffsets($source) as $offset => $brace) {
            if ($offset >= $from && $brace === '{') {
                return $offset;
            }
        }

        return null;
    }

    /**
     * 여는 중괄호에 대응하는 닫는 중괄호 위치.
     *
     * 짝을 찾지 못하면 **예외**다. 종전에는 `null` 을 돌려줬고 호출부가 그것을 「함수가
     * 없다」(빈 본문 · `'(none)'`)와 같은 값으로 접었다 — 그래서 검출기가 실패한 상태와
     * 대상이 없는 상태가 구분되지 않았고, 소비처는 실패를 「함수 밖」으로 읽어 판정을
     * 넓혔다. 실패는 조용히 넘길 것이 아니라 그 자리에서 멈춰야 한다 (14차 C11-7).
     *
     * @param  string  $source  소스
     * @param  int  $open  여는 중괄호 위치
     * @param  bool|null  $isPhp  소스가 PHP 인지 호출부가 아는 값 (null 이면 중괄호 표 도출이 추론)
     * @return int 닫는 위치
     *
     * @throws \RuntimeException 그 위치가 여는 중괄호가 아니거나 짝이 없을 때
     */
    private function matchingBrace(string $source, int $open, ?bool $isPhp = null): int
    {
        $braces = $this->braceOffsets($source, $isPhp);

        if (($braces[$open] ?? null) !== '{') {
            throw new \RuntimeException(
                '중괄호 짝 판정: '.$open.' 위치는 코드 여는 중괄호가 아니다 (문자열·주석 안이거나 존재하지 않는다)'
            );
        }

        $depth = 0;

        foreach ($braces as $offset => $brace) {
            if ($offset < $open) {
                continue;
            }

            $depth += $brace === '{' ? 1 : -1;

            if ($depth === 0) {
                return $offset;
            }
        }

        throw new \RuntimeException('중괄호 짝 판정: '.$open.' 위치의 여는 중괄호에 대응하는 닫는 중괄호가 없다');
    }

    /**
     * 소스가 **직접 호출**하는 함수 이름을 관심 목록 안에서 골라 돌려줍니다.
     *
     * 토크나이저로 본다 — 문자열·주석 안의 같은 글자(번역 문구의 `ini_set(` 등)를 호출로
     * 세면 도출 모집단이 부풀어 그 파일에 없는 의무가 생긴다. 메서드 호출(`$x->ini_set()`)
     * 과 선언(`function ini_set(`)도 제외한다.
     *
     * @param  string  $source  PHP 소스
     * @param  list<string>  $names  관심 함수 이름
     * @return list<string> 실제로 호출된 이름 (입력 순서 유지, 중복 제거)
     */
    private function directFunctionCalls(string $source, array $names): array
    {
        $tokens = token_get_all($this->withoutInlineScripts($source));
        $count = count($tokens);
        $called = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], $names, true)) {
                continue;
            }

            $next = $this->significantToken($tokens, $i + 1, 1);
            if ($next !== '(') {
                continue;
            }

            $previous = $this->significantToken($tokens, $i - 1, -1);
            if (is_array($previous) && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            $called[$token[1]] = true;
        }

        return array_values(array_filter($names, static fn (string $name): bool => isset($called[$name])));
    }

    /**
     * 공백을 건너뛴 이웃 토큰.
     *
     * @param  array<int, array{0:int, 1:string}|string>  $tokens  토큰 목록
     * @param  int  $from  탐색 시작 인덱스
     * @param  int  $step  진행 방향 (1 또는 -1)
     * @return array{0:int, 1:string}|string|null 토큰 (없으면 null)
     */
    private function significantToken(array $tokens, int $from, int $step)
    {
        for ($i = $from; $i >= 0 && $i < count($tokens); $i += $step) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    /**
     * PHP 주석(//, #, 블록)을 지운 소스. 문자열 리터럴 안의 `//` 는 보존한다.
     *
     * 소스를 문자로 대조하는 게이트는 **예외 없이** 이 처리를 거친다 — 호출을 주석으로
     * 감싸도 원문에는 글자가 남으므로, 원문 검색만으로는 「그 배선이 살아 있다」를 증명하지
     * 못한다. 10차 #G4 가 이 규칙을 세웠지만 한 파일의 두 검사에만 적용해, 같은 사각이
     * 형제 검사들에 그대로 남아 있었다 (12차 D축).
     *
     * 줄 수는 보존한다 — 오프셋 기반 판정(정의/호출 구분·순서 비교)이 크게 어긋나지 않도록.
     *
     * @param  string  $source  PHP 소스
     * @return string 주석이 제거된 소스
     */
    private function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $out .= str_repeat('
', substr_count($token[1], '
'));

                    continue;
                }
                $out .= $token[1];

                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * JS 주석(//, 블록)을 지운 소스. 문자열·템플릿 리터럴·정규식 리터럴 안은 보존한다.
     *
     * `token_get_all` 은 JS 에 쓸 수 없어 직접 훑는다. 정규식 리터럴은 `/\//g` 처럼 안에
     * 슬래시를 품을 수 있어 순진한 스캐너가 그 지점부터 소스를 통째로 잃는다 — 그래서
     * 리터럴 시작 판정을 함께 둔다(직전 유의미 문자가 피연산자면 나눗셈, 아니면 리터럴).
     *
     * @param  string  $source  JS 소스
     * @return string 주석이 제거된 소스
     */
    private function stripJsComments(string $source): string
    {
        $out = '';
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $ch = $source[$i];
            $next = $i + 1 < $length ? $source[$i + 1] : '';

            // PHP 인라인 구간(여는 태그 `<?=` 부터 닫는 태그까지) — 뷰의 `<script>` 안에서 만난다. 그대로 옮기고
            // 피연산자로 본다(`jsSlashStartsRegexLiteral()`).
            if ($ch === '<' && $next === '?') {
                $end = strpos($source, '?>', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $out .= substr($source, $i, $end - $i);
                $i = $end;

                continue;
            }

            // 주석 — `//` 로 시작하는 정규식 리터럴은 문법상 존재할 수 없고,
            // `/*` 도 마찬가지다(수량자가 앞설 수 없다). 그래서 이 판정이 먼저다.
            if ($ch === '/' && $next === '/') {
                // 줄 종결자는 남긴다 — `\r` 까지 삼키면 CRLF 파일의 그 줄만 LF 가 되어 줄 수는
                // 같아도 종결자가 갈리고, 원문 오프셋으로 대응시키는 판정이 어긋난다.
                while ($i < $length && $source[$i] !== "\n" && $source[$i] !== "\r") {
                    $i++;
                }

                continue;
            }
            if ($ch === '/' && $next === '*') {
                $end = strpos($source, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $out .= str_repeat('
', substr_count(substr($source, $i, $end - $i), '
'));
                $i = $end;

                continue;
            }

            // 템플릿 리터럴 — 따옴표와 같이 다루면 안 된다. `${…}` 안은 **코드**라
            // 중첩 템플릿 리터럴이 올 수 있고, 그 안쪽 백틱을 바깥의 종료로 오인하면
            // 그 지점부터 백틱 짝이 영구히 어긋나 **파일 나머지 전체의 주석이 남는다**.
            // 그 상태에서도 「스트리퍼를 거쳤다」는 사실은 변하지 않으므로 위생 검사는
            // 초록이고, 주석 채널 방어만 조용히 사라진다.
            if ($ch === '`') {
                $out .= $ch;
                $i = $this->consumeJsTemplateLiteral($source, $i + 1, $length, $out);

                continue;
            }

            // 일반 문자열
            if ($ch === "'" || $ch === '"') {
                $out .= $ch;
                $i++;
                while ($i < $length) {
                    $out .= $source[$i];
                    if ($source[$i] === '\\') {
                        $i++;
                        if ($i < $length) {
                            $out .= $source[$i];
                            $i++;
                        }

                        continue;
                    }
                    if ($source[$i] === $ch) {
                        $i++;
                        break;
                    }
                    $i++;
                }

                continue;
            }

            // 정규식 리터럴 — 직전 유의미 토큰이 피연산자면 나눗셈이다 (`jsSlashStartsRegexLiteral()`)
            if ($ch === '/' && $this->jsSlashStartsRegexLiteral($source, $i)) {
                $out .= $ch;
                $i++;
                $inClass = false;
                while ($i < $length) {
                    $out .= $source[$i];
                    if ($source[$i] === '\\') {
                        $i++;
                        if ($i < $length) {
                            $out .= $source[$i];
                            $i++;
                        }

                        continue;
                    }
                    if ($source[$i] === '[') {
                        $inClass = true;
                    } elseif ($source[$i] === ']') {
                        $inClass = false;
                    } elseif ($source[$i] === '/' && ! $inClass) {
                        $i++;
                        break;
                    } elseif ($source[$i] === "\n" || $source[$i] === "\r") {
                        // 줄을 넘기면 리터럴이 아니었다 — 나눗셈으로 되돌린다
                        $i++;
                        break;
                    }
                    $i++;
                }

                continue;
            }

            $out .= $ch;
            $i++;
        }

        return $out;
    }

    /**
     * 템플릿 리터럴 하나를 소비한다 (여는 백틱은 호출부가 이미 소비한 상태로 진입).
     *
     * `${…}` 안은 문자열이 아니라 **코드**다. 그 안에 또 템플릿 리터럴이 올 수 있으므로
     * 재귀로 내려간다 — 이 구분이 없으면 안쪽 백틱이 바깥의 종료로 읽혀 그 뒤 모든
     * 백틱의 짝이 뒤집힌다.
     *
     * @param  string  $source  JS 소스 전체
     * @param  int  $i  여는 백틱 **다음** 오프셋
     * @param  int  $length  소스 길이
     * @param  string  $out  누적 출력 (참조로 이어 붙인다)
     * @return int 닫는 백틱 다음 오프셋 (닫히지 않았으면 소스 끝)
     */
    private function consumeJsTemplateLiteral(string $source, int $i, int $length, string &$out): int
    {
        while ($i < $length) {
            $ch = $source[$i];

            if ($ch === '\\') {
                $out .= $ch;
                $i++;
                if ($i < $length) {
                    $out .= $source[$i];
                    $i++;
                }

                continue;
            }

            if ($ch === '`') {
                $out .= $ch;

                return $i + 1;
            }

            // 보간 구간 — 중괄호 균형을 세며 코드로 훑는다.
            if ($ch === '$' && $i + 1 < $length && $source[$i + 1] === '{') {
                $out .= '${';
                $i += 2;
                $depth = 1;

                while ($i < $length && $depth > 0) {
                    $inner = $source[$i];

                    if ($inner === '\\') {
                        $out .= $inner;
                        $i++;
                        if ($i < $length) {
                            $out .= $source[$i];
                            $i++;
                        }

                        continue;
                    }

                    // 중첩 템플릿 리터럴 — 여기가 이 함수의 존재 이유다.
                    if ($inner === '`') {
                        $out .= $inner;
                        $i = $this->consumeJsTemplateLiteral($source, $i + 1, $length, $out);

                        continue;
                    }

                    // 보간 안의 일반 문자열도 중괄호를 품을 수 있다.
                    if ($inner === "'" || $inner === '"') {
                        $out .= $inner;
                        $i++;
                        while ($i < $length) {
                            $out .= $source[$i];
                            if ($source[$i] === '\\') {
                                $i++;
                                if ($i < $length) {
                                    $out .= $source[$i];
                                    $i++;
                                }

                                continue;
                            }
                            if ($source[$i] === $inner) {
                                $i++;
                                break;
                            }
                            $i++;
                        }

                        continue;
                    }

                    if ($inner === '{') {
                        $depth++;
                    } elseif ($inner === '}') {
                        $depth--;
                    }

                    $out .= $inner;
                    $i++;
                }

                continue;
            }

            $out .= $ch;
            $i++;
        }

        return $i;
    }
}
