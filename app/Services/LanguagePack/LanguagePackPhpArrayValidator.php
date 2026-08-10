<?php

namespace App\Services\LanguagePack;

use ParseError;

/**
 * 언어팩에 동봉된 PHP 파일이 "번역 배열만" 담고 있는지 문법으로 검사하는 순수 유틸.
 *
 * 언어팩의 PHP 파일은 활성화되면 `LanguagePackTranslator` 가 `require` 하므로 그 내용이
 * 그대로 실행된다. 위험 함수 이름을 나열해 막는 방식은 목록에 없는 함수(`file_put_contents`,
 * `fopen`, `call_user_func`, 백틱 등)로 언제든 우회되므로, 반대로 **허용된 문법만 통과**시킨다.
 *
 * 허용 문법:
 *
 *   파일    := "<?php" declare? "return" 값 ";" 닫는태그? EOF
 *   declare := declare(strict_types=1);
 *   값      := 배열 | 스칼라
 *   배열    := "[" 항목들? "]" | array( 항목들? )
 *   항목    := 값 ("=>" 값)?
 *   스칼라  := 문자열리터럴 ("." 문자열리터럴)* | 부호? 숫자 | true | false | null
 *              (공백·주석은 어디서나 허용)
 *
 * 토큰 집합만 검사해서는 안 된다 — "허용 토큰 목록에 없으면 거부" 로 구현하면
 * `<?php return [('system')('id')];` 가 통과한다. 여기 쓰인 토큰은 문자열 리터럴과
 * 괄호뿐이기 때문이다. 따라서 토큰의 **등장 위치와 순서를 강제하는 상태기계**로 구현하고,
 * `(` 는 `array` 바로 뒤와 `declare` 프리픽스에서만 허용한다.
 *
 * 블록리스트보다 나은 점은 빠뜨림이 원리적으로 불가능하다는 것이다. 함수 호출은 이름과
 * 무관하게 전부 거부되고, 변수·가변변수·백틱·heredoc 보간·`?>` 뒤 인라인 코드·속성·`new`·
 * `match` 도 전부 문법에 없다. PHP 에 새 위험 함수가 추가되어도 목록을 갱신할 필요가 없다.
 *
 * `nikic/php-parser` 를 쓰지 않는다 — 개발 의존성이라 배포본에서 사라질 수 있다.
 * `token_get_all()` 은 별도 확장 없이 항상 쓸 수 있다.
 */
class LanguagePackPhpArrayValidator
{
    /** 검사 대상 파일 크기 상한 (파서 자원 소모 방지) */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** 배열 중첩 깊이 상한 (파서 자원 소모 방지) */
    public const MAX_DEPTH = 30;

    /** @var array<int, array{type: int|string, text: string, line: int}> 공백·주석을 제거한 토큰 목록 */
    private array $tokens = [];

    /** @var int 현재 토큰 위치 */
    private int $position = 0;

    /**
     * 소스가 번역 배열만 담고 있는지 판정합니다.
     *
     * @param  string  $source  PHP 소스 문자열
     * @return bool 안전하면 true
     */
    public static function isSafe(string $source): bool
    {
        return self::inspect($source) === null;
    }

    /**
     * 소스를 검사해 위반 지점을 돌려줍니다.
     *
     * @param  string  $source  PHP 소스 문자열
     * @return array{line: int, reason: string}|null 안전하면 null
     */
    public static function inspect(string $source): ?array
    {
        if (strlen($source) > self::MAX_BYTES) {
            return ['line' => 0, 'reason' => 'file_too_large'];
        }

        try {
            $raw = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError) {
            return ['line' => 0, 'reason' => 'parse_error'];
        }

        return (new self)->run($raw);
    }

    /**
     * 파일을 읽어 검사합니다.
     *
     * @param  string  $path  절대 경로
     * @return array{line: int, reason: string}|null 안전하면 null
     */
    public static function inspectFile(string $path): ?array
    {
        // 크기 상한은 읽기 **전에** 본다 — 내용을 먼저 메모리에 올리면 압축률 높은 ZIP 으로
        // 반입한 거대 파일이 2MB 컷에 걸리기 전에 메모리를 소모한다.
        $size = @filesize($path);

        if ($size !== false && $size > self::MAX_BYTES) {
            return ['line' => 0, 'reason' => 'file_too_large'];
        }

        $source = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);

        if ($source === false) {
            return ['line' => 0, 'reason' => 'unreadable'];
        }

        return self::inspect($source);
    }

    /**
     * 토큰 목록을 문법에 따라 검사합니다.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $raw  token_get_all 결과
     * @return array{line: int, reason: string}|null 안전하면 null
     */
    private function run(array $raw): ?array
    {
        $line = 1;

        foreach ($raw as $token) {
            if (is_array($token)) {
                $line = $token[2];

                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $this->tokens[] = ['type' => $token[0], 'text' => $token[1], 'line' => $token[2]];

                continue;
            }

            $this->tokens[] = ['type' => $token, 'text' => $token, 'line' => $line];
        }

        if (! $this->expectType(T_OPEN_TAG)) {
            return $this->violation('missing_open_tag');
        }

        if ($this->peekType() === T_DECLARE && ! $this->consumeStrictTypesDeclare()) {
            return $this->violation('unsupported_declare');
        }

        if (! $this->expectType(T_RETURN)) {
            return $this->violation('missing_return');
        }

        if (! $this->consumeValue(0)) {
            return $this->violation('not_a_literal_value');
        }

        if (! $this->expectText(';')) {
            return $this->violation('missing_semicolon');
        }

        if ($this->peekType() === T_CLOSE_TAG) {
            $this->position++;

            /*
             * PHP 는 닫는 태그 직후 개행 하나를 삼킨다. 그 이상 남은 것은 인라인 출력이므로
             * 공백만 허용한다 (닫는 태그 `?>` 뒤의 임의 코드·두 번째 여는 태그를 여기서 막는다).
             * 한 줄 주석에 닫는 태그를 쓰면 그 지점에서 PHP 모드가 끝나므로 블록 주석을 쓴다.
             */
            if ($this->peekType() === T_INLINE_HTML) {
                if (trim($this->tokens[$this->position]['text']) !== '') {
                    return $this->violation('code_after_close_tag');
                }

                $this->position++;
            }
        }

        if ($this->position !== count($this->tokens)) {
            return $this->violation('trailing_tokens');
        }

        return null;
    }

    /**
     * `declare(strict_types=1);` 프리픽스를 소비합니다.
     *
     * @return bool 정확히 이 형태였으면 true
     */
    private function consumeStrictTypesDeclare(): bool
    {
        $this->position++; // T_DECLARE

        if (! $this->expectText('(')) {
            return false;
        }

        $directive = $this->tokens[$this->position] ?? null;

        if ($directive === null || $directive['type'] !== T_STRING || strtolower($directive['text']) !== 'strict_types') {
            return false;
        }

        $this->position++;

        return $this->expectText('=')
            && $this->expectType(T_LNUMBER)
            && $this->expectText(')')
            && $this->expectText(';');
    }

    /**
     * 값(배열 또는 스칼라) 하나를 소비합니다.
     *
     * @param  int  $depth  현재 중첩 깊이
     * @return bool 소비했으면 true
     */
    private function consumeValue(int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }

        $token = $this->tokens[$this->position] ?? null;

        if ($token === null) {
            return false;
        }

        if ($token['type'] === '[') {
            $this->position++;

            return $this->consumeArrayItems(']', $depth + 1);
        }

        // `(` 는 `array` 바로 뒤에서만 허용한다 — 그 외 위치의 괄호는 호출·그룹화이므로 거부.
        if ($token['type'] === T_ARRAY) {
            $this->position++;

            return $this->expectText('(') && $this->consumeArrayItems(')', $depth + 1);
        }

        return $this->consumeScalar();
    }

    /**
     * 배열 항목들과 닫는 기호를 소비합니다.
     *
     * @param  string  $closer  닫는 기호 (`]` 또는 `)`)
     * @param  int  $depth  현재 중첩 깊이
     * @return bool 소비했으면 true
     */
    private function consumeArrayItems(string $closer, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }

        while (true) {
            if ($this->peekText() === $closer) {
                $this->position++;

                return true;
            }

            if (! $this->consumeValue($depth)) {
                return false;
            }

            if ($this->peekType() === T_DOUBLE_ARROW) {
                $this->position++;

                if (! $this->consumeValue($depth)) {
                    return false;
                }
            }

            if ($this->peekText() === ',') {
                $this->position++;

                continue;
            }

            if ($this->peekText() === $closer) {
                $this->position++;

                return true;
            }

            return false;
        }
    }

    /**
     * 스칼라 하나를 소비합니다.
     *
     * @return bool 소비했으면 true
     */
    private function consumeScalar(): bool
    {
        $token = $this->tokens[$this->position] ?? null;

        if ($token === null) {
            return false;
        }

        if ($token['type'] === T_CONSTANT_ENCAPSED_STRING) {
            $this->position++;

            // 문자열 리터럴 연결만 허용한다 (변수 보간은 T_ENCAPSED_AND_WHITESPACE 라 여기 오지 않는다).
            while ($this->peekText() === '.') {
                $this->position++;

                if (! $this->expectType(T_CONSTANT_ENCAPSED_STRING)) {
                    return false;
                }
            }

            return true;
        }

        if ($token['type'] === '-' || $token['type'] === '+') {
            $this->position++;

            return $this->expectType(T_LNUMBER) || $this->expectType(T_DNUMBER);
        }

        if ($token['type'] === T_LNUMBER || $token['type'] === T_DNUMBER) {
            $this->position++;

            return true;
        }

        if ($token['type'] === T_STRING && in_array(strtolower($token['text']), ['true', 'false', 'null'], true)) {
            $this->position++;

            return true;
        }

        return false;
    }

    /**
     * 다음 토큰이 지정한 타입이면 소비합니다.
     *
     * @param  int  $type  토큰 타입
     * @return bool 소비했으면 true
     */
    private function expectType(int $type): bool
    {
        if (($this->tokens[$this->position]['type'] ?? null) !== $type) {
            return false;
        }

        $this->position++;

        return true;
    }

    /**
     * 다음 토큰이 지정한 기호면 소비합니다.
     *
     * @param  string  $text  기호
     * @return bool 소비했으면 true
     */
    private function expectText(string $text): bool
    {
        if (($this->tokens[$this->position]['type'] ?? null) !== $text) {
            return false;
        }

        $this->position++;

        return true;
    }

    /**
     * 다음 토큰의 타입을 봅니다 (소비하지 않음).
     *
     * @return int|string|null 토큰 타입
     */
    private function peekType(): int|string|null
    {
        return $this->tokens[$this->position]['type'] ?? null;
    }

    /**
     * 다음 토큰이 기호일 때 그 기호를 봅니다 (소비하지 않음).
     *
     * @return string|null 기호, 기호가 아니면 null
     */
    private function peekText(): ?string
    {
        $type = $this->peekType();

        return is_string($type) ? $type : null;
    }

    /**
     * 현재 위치를 위반 지점으로 보고합니다.
     *
     * @param  string  $reason  위반 사유 코드
     * @return array{line: int, reason: string} 위반 정보
     */
    private function violation(string $reason): array
    {
        $token = $this->tokens[$this->position] ?? $this->tokens[count($this->tokens) - 1] ?? null;

        return ['line' => $token['line'] ?? 0, 'reason' => $reason];
    }
}
