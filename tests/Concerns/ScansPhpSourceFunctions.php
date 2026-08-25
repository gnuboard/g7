<?php

namespace Tests\Concerns;

/**
 * 소스에서 함수 경계를 잡는 검출기 — 인스톨러 커버리지 테스트 3종의 공용 판정 (11차 #D11).
 *
 * 종전에는 같은 두 헬퍼가 세 테스트 파일에 문자 그대로 복제돼 있었고, 형제 검출기들과 달리
 * 「검출기가 실제로 구분하는가」를 보는 픽스처가 하나도 없었다. 두 판정 다 뒤집혀도 red 가
 * 없었고, 뒤집히면 커버리지 테스트가 **엉뚱한 함수 본문**을 보면서 초록을 낸다.
 *
 * 알려진 실패 모드 셋을 여기서 처리한다:
 *  ① 이름이 접두사인 형제(`run` / `runTask`)가 먼저 정의돼 있으면 `strpos` 첫 일치가
 *     다른 함수를 가리킨다.
 *  ② 함수가 끝난 뒤의 최상위 코드가 「마지막 함수 안」으로 귀속된다.
 *  ③ PHP 뷰의 `<script>` 안에 있는 JS `function` 선언이 PHP 함수로 계상된다.
 */
trait ScansPhpSourceFunctions
{
    /**
     * `<script>` 구간을 걷어낸다 — 뷰 파일의 JS 선언이 PHP 함수로 계상되는 것을 막는다.
     *
     * 길이를 보존하기 위해 같은 길이의 공백으로 바꾼다(오프셋 기반 판정이 어긋나지 않도록).
     *
     * @param  string  $source  PHP 소스
     * @return string 스크립트 구간이 비워진 소스
     */
    private function withoutInlineScripts(string $source): string
    {
        return (string) preg_replace_callback(
            '#<script\b[^>]*>.*?</script>#is',
            static fn (array $m): string => str_repeat(' ', strlen($m[0])),
            $source,
        );
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

            $open = strpos($scannable, '{', $declaredAt);
            if ($open === false) {
                continue;   // 인터페이스·추상 선언
            }

            $close = $this->matchingBrace($scannable, $open);
            if ($close !== null && $offset > $open && $offset < $close) {
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

        $open = strpos($scannable, '{', $m[0][1]);
        if ($open === false) {
            return '';
        }

        $close = $this->matchingBrace($scannable, $open);

        return $close === null ? '' : substr($source, $open, $close - $open + 1);
    }

    /**
     * 여는 중괄호에 대응하는 닫는 중괄호 위치.
     *
     * @param  string  $source  소스
     * @param  int  $open  여는 중괄호 위치
     * @return int|null 닫는 위치 (짝이 없으면 null)
     */
    private function matchingBrace(string $source, int $open): ?int
    {
        $depth = 0;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
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
        $lastSignificant = '';

        while ($i < $length) {
            $ch = $source[$i];
            $next = $i + 1 < $length ? $source[$i + 1] : '';

            // 주석 — `//` 로 시작하는 정규식 리터럴은 문법상 존재할 수 없고,
            // `/*` 도 마찬가지다(수량자가 앞설 수 없다). 그래서 이 판정이 먼저다.
            if ($ch === '/' && $next === '/') {
                while ($i < $length && $source[$i] !== '
') {
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
                $lastSignificant = '`';

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
                $lastSignificant = $ch;

                continue;
            }

            // 정규식 리터럴 — 직전 유의미 문자가 피연산자면 나눗셈이다
            if ($ch === '/' && ! preg_match('/[A-Za-z0-9_$)\]]/', $lastSignificant)) {
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
                    } elseif ($source[$i] === '
') {
                        // 줄을 넘기면 리터럴이 아니었다 — 나눗셈으로 되돌린다
                        $i++;
                        break;
                    }
                    $i++;
                }
                $lastSignificant = '/';

                continue;
            }

            $out .= $ch;
            if (trim($ch) !== '') {
                $lastSignificant = $ch;
            }
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
