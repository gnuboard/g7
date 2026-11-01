<?php

namespace Tests\Unit\Architecture;

use App\Support\PhpExtensionRequirements;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 코어가 「선택」 PHP 확장 심볼을 가드 없이 쓰지 않는지 잠근다 (#151).
 *
 * bcmath·zip·iconv·zlib·phar 를 가드 없이 부른 자리가 그 확장이 없는 서버에서 500 이 됐다.
 * 심볼 표는 코어 SSoT `app/Support/Requirements/php-extensions.json` 의 `symbols` 이고, 사용이 든 함수 본문에
 * 가드(function_exists·class_exists·extension_loaded·PhpExtensionProbe·…Available()·catch Throwable)나
 * `// php-ext-guard: <사유>` 면제가 있어야 한다. 확장별 잠금(이커머스 bcmath·KCP soap)은 각 확장 tests 에 둔다.
 *
 * 프레임워크 없이 파일만 읽으므로 `PHPUnit\Framework\TestCase` 를 직접 상속한다.
 */
class OptionalPhpExtensionUsageTest extends TestCase
{
    /** 가드로 인정하는 토큰 */
    private const GUARD_PATTERN = '/\b(?:function_exists|class_exists|extension_loaded)\s*\(|PhpExtensionProbe|\b\w*Available\s*\(|catch\s*\(\s*\\\\?Throwable\b/';

    /** 면제 표식 */
    private const EXEMPT_PATTERN = '/php-ext-guard:\s*\S/';

    /**
     * `app/`·`public/install/` 의 선택 확장 심볼 사용이 모두 가드돼 있다.
     *
     * @scenario surface=core_source
     *
     * @effects core_optional_extension_usage_is_guarded
     *
     * @return void 반환값 없음
     */
    public function test_core_sources_guard_optional_extension_symbols(): void
    {
        $root = dirname(__DIR__, 3);
        $symbols = PhpExtensionRequirements::load()['symbols'];
        $violations = [];
        $files = 0;

        foreach (['app', 'public/install'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $files++;
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                foreach ($this->findUnguarded((string) file_get_contents($file->getPathname()), $symbols) as $hit) {
                    $violations[] = $relative.':'.$hit;
                }
            }
        }

        $this->assertGreaterThan(500, $files, '검사 모집단이 비정상적으로 작습니다');
        $this->assertSame([], $violations, '선택 PHP 확장 심볼을 가드 없이 씁니다 — 같은 함수에서 확인하거나 // php-ext-guard: 사유 를 남기세요');
    }

    /**
     * 판정식 자체가 발화·비발화를 가른다. (전수 0건이 공허한 통과가 되지 않게)
     *
     * @scenario surface=judgement_fixture
     *
     * @effects core_optional_extension_usage_is_guarded
     *
     * @return void 반환값 없음
     */
    public function test_judgement_detects_unguarded_and_respects_guards(): void
    {
        $symbols = ['bcmath' => ['bccomp'], 'soap' => ['SoapClient'], 'iconv' => ['iconv']];
        $wrap = fn (string $body): string => "<?php\nclass X\n{\n    public function run(): void\n    {\n{$body}\n    }\n}\n";

        $this->assertSame(['6 bcmath bccomp'], $this->findUnguarded($wrap('        return bccomp($a, $b, 4);'), $symbols));
        $this->assertCount(1, $this->findUnguarded($wrap("        \$s = @iconv('UTF-8', 'UTF-8//IGNORE', \$s);"), $symbols));
        $this->assertCount(1, $this->findUnguarded($wrap('        $c = new \\SoapClient($w);'), $symbols));

        $this->assertSame([], $this->findUnguarded($wrap("        if (! function_exists('bccomp')) { return; }\n        bccomp(\$a, \$b);"), $symbols));
        $this->assertSame([], $this->findUnguarded($wrap('        if (! $this->soapAvailable()) { return; }'."\n".'        new SoapClient($w);'), $symbols));
        $this->assertSame([], $this->findUnguarded($wrap("        // bccomp(\$a)\n        \$m = 'iconv(x)';\n        \$n = \\SoapClient::class;\n        \$this->iconv(\$s);"), $symbols));
        $this->assertSame([], $this->findUnguarded($wrap("        // php-ext-guard: 호출부가 확인한다\n        bccomp(\$a, \$b);"), $symbols));
    }

    /**
     * 무방비 사용을 찾습니다. (audit lib `findUnguarded` 와 같은 규칙)
     *
     * @param  string  $content  PHP 소스
     * @param  array<string, list<string>>  $symbols  확장 → 대표 심볼
     * @return list<string> "줄 확장 심볼" 목록
     */
    private function findUnguarded(string $content, array $symbols): array
    {
        $code = $this->mask($content, true);
        $guardCode = $this->mask($content, false);
        $bodies = $this->functionBodies($code);
        $lines = explode("\n", $content);
        $out = [];

        foreach ($symbols as $extension => $names) {
            foreach ($names as $symbol) {
                $quoted = preg_quote($symbol, '/');
                $pattern = ctype_upper($symbol[0])
                    ? '/(?:\bnew\s+\\\\?'.$quoted.'\b|(?<![\w\\\\$])\\\\?'.$quoted.'::(?!class\b))/'
                    : '/(?<![\w$>:\\\\])\\\\?@?'.$quoted.'\s*\(/';
                preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE);

                foreach ($matches[0] as [$text, $offset]) {
                    if (! ctype_upper($symbol[0]) && preg_match('/\bfunction\s+$/', substr($code, max(0, $offset - 12), min(12, $offset)))) {
                        continue;
                    }
                    $line = substr_count($content, "\n", 0, $offset) + 1;
                    if (preg_match(self::EXEMPT_PATTERN, $lines[$line - 1] ?? '') || preg_match(self::EXEMPT_PATTERN, $lines[$line - 2] ?? '')) {
                        continue;
                    }

                    $enclosing = null;
                    foreach ($bodies as [$start, $end]) {
                        if ($start < $offset && $offset < $end && ($enclosing === null || $end - $start < $enclosing[1] - $enclosing[0])) {
                            $enclosing = [$start, $end];
                        }
                    }
                    $scope = $enclosing ? substr($guardCode, $enclosing[0], $enclosing[1] - $enclosing[0]) : $guardCode;
                    if (preg_match(self::GUARD_PATTERN, $scope)) {
                        continue;
                    }
                    if ($enclosing && preg_match(self::EXEMPT_PATTERN, substr($content, $enclosing[0], $enclosing[1] - $enclosing[0]))) {
                        continue;
                    }

                    $out["{$line} {$extension}"] = "{$line} {$extension} {$symbol}";
                }
            }
        }

        ksort($out, SORT_NATURAL);

        return array_values($out);
    }

    /**
     * 주석(과 선택적으로 문자열 내용)을 공백으로 바꿉니다. 길이·줄은 보존합니다.
     *
     * @param  string  $content  PHP 소스
     * @param  bool  $maskStrings  문자열 내용까지 가릴지
     * @return string 같은 길이의 소스
     */
    private function mask(string $content, bool $maskStrings): string
    {
        $out = '';
        foreach (token_get_all($content) as $token) {
            if (! is_array($token)) {
                $out .= $token;

                continue;
            }
            [$id, $text] = $token;
            $blankable = in_array($id, [T_COMMENT, T_DOC_COMMENT], true)
                || ($maskStrings && in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true));
            $out .= $blankable ? preg_replace('/[^\r\n]/', ' ', $text) : $text;
        }

        return $out;
    }

    /**
     * 함수·클로저 본문 범위를 찾습니다.
     *
     * @param  string  $code  주석·문자열을 가린 소스
     * @return list<array{0: int, 1: int}> [여는 중괄호, 닫는 중괄호] 위치
     */
    private function functionBodies(string $code): array
    {
        $bodies = [];
        preg_match_all('/\bfunction\b/', $code, $matches, PREG_OFFSET_CAPTURE);
        $length = strlen($code);

        foreach ($matches[0] as [, $offset]) {
            $depth = 0;
            for ($i = $offset + 8; $i < $length; $i++) {
                $ch = $code[$i];
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                } elseif ($depth === 0 && ($ch === '{' || $ch === ';')) {
                    break;
                }
            }
            if (($code[$i] ?? '') !== '{') {
                continue;
            }
            $open = $i;
            $braces = 0;
            for (; $i < $length; $i++) {
                if ($code[$i] === '{') {
                    $braces++;
                } elseif ($code[$i] === '}' && --$braces === 0) {
                    break;
                }
            }
            $bodies[] = [$open, $i];
        }

        return $bodies;
    }
}
