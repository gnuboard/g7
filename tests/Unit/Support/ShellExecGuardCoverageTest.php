<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * A-8 — 시스템 정보 조회의 셸 호출은 전부 가용성 가드를 거친다.
 *
 * `disable_functions` 로 막힌 환경에서 `shell_exec()` 를 그냥 부르면 **fatal Error** 다.
 * 그래서 그 호출들은 `ExecutionEnvironment::hasFunction()` 판정 뒤에만 실행돼야 한다.
 * 그런데 그 상태는 PHPUnit 으로 재현할 수 없다(ini 는 PHP_INI_SYSTEM) — 실행 대신 **호출
 * 지점의 형태**를 고정한다.
 *
 * 시나리오 매니페스트(`no-cli-execution-mode.yaml`)의 `shell_exec_guard_surface` 축 4값 중
 * 대시보드 2값은 서비스 단위 테스트가 덮고 있었지만, 설정 화면 2값(메모리·CPU)은 어느 쪽
 * 채널로도 도달하지 않았다 — 그 두 지점이 여기 모집단이다.
 *
 * 커버 축: shell_exec_guard_surface settings_memory·settings_cpu_info
 */
class ShellExecGuardCoverageTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /** 셸 호출을 갖는 파일과, 그 호출이 속한 함수들 */
    private const GUARDED_SURFACES = [
        'app/Services/SettingsService.php' => ['getMemoryUsage', 'getCpuInfo'],
        'app/Services/DashboardService.php' => null,   // 함수 열거 없이 전수
    ];

    /**
     * 스폰 계열 프리미티브 전수.
     *
     * 종전에는 어휘가 `shell_exec` 하나뿐이라, 같은 파일에 `exec(`·`proc_open(`·`popen(`
     * 이 무가드로 생겨도 초록이었다. 8차 #23 이 스폰 룰의 어휘를 6종으로 넓힌 것과 같은 사각이다.
     */
    private const SPAWN_PRIMITIVES = ['shell_exec', 'exec', 'proc_open', 'passthru', 'system', 'popen'];

    /** 판정 SSoT 자신 — 여기서 가용성을 묻는 것이 그 클래스의 일이다. */
    private const JUDGMENT_OWNERS = [
        'app/Support/ExecutionEnvironment.php',
    ];

    /**
     * 다른 공정이 소유한 무가드 지점 — **알고 있고, 아직 이관하지 않았다**.
     *
     * 어휘를 스폰 계열 전수로 넓히자 A-8 면 밖의 실제 무가드 3곳이 드러났고, 그 셋(코어
     * 업데이트 경로)은 **G6 에서 이관을 마쳤다** — `extractWithUnzip`·`isUnzipAvailable` 은
     * `exec`, `executeComposerInstall` 은 `proc_open` 가용성을 SSoT 로 먼저 묻고 도메인
     * 예외로 폴백한다 (#609 D34).
     *
     * 목록을 지우지 않고 **비운 채로 남긴다** — 이 상수가 사라지면 「이관 대상이 있었다」는
     * 사실과 「지금은 없다」는 판정이 함께 사라지고, 새 지점이 생겼을 때 다시 목록을 만드는
     * 사람이 그 규율을 처음부터 발명해야 한다.
     *
     * @var list<string>
     */
    private const DEFERRED_TO_OTHER_TRACKS = [];

    /**
     * `app/**` 에서 스폰 프리미티브를 부르는 파일을 스캔한다.
     *
     * 하드코딩 열거는 「지금 아는 두 파일」만 지킨다 — 세 번째 파일이 생기면 그 파일의
     * 무가드 호출은 영원히 검사 밖이다. 판정 SSoT 자신(ExecutionEnvironment)만 제외한다.
     *
     * @return array<int, string> 저장소 상대 경로
     */
    private function scanSpawnSurfaces(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        // A-8 이 정의한 면(자원 측정)만 본다 — `app/Extension/**`·`app/Services/CoreUpdateService`
        // 등의 스폰 지점은 확장·코어 업데이트 공정 소유이고 그쪽 규율(정적 룰)이 따로 덮는다.
        // 여기서 그것까지 끌어오면 이 검사가 A-8 의 게이트가 아니라 저장소 전체 감사기가 된다.
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app/Services'));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            if (in_array($relative, self::JUDGMENT_OWNERS, true)) {
                continue;
            }

            // 주석 안의 `exec(` 는 스폰이 아니다 — 모집단 판정도 같은 규칙을 받는다.
            $source = $this->stripComments((string) file_get_contents($file->getPathname()));
            foreach (self::SPAWN_PRIMITIVES as $primitive) {
                if (preg_match('/@?(?<![\w$>-])'.$primitive.'\s*\(/', $source) === 1) {
                    $found[] = $relative;
                    break;
                }
            }
        }

        sort($found);

        // 종전 열거가 모집단에서 빠지면 스캔이 죽은 것이다.
        foreach (array_keys(self::GUARDED_SURFACES) as $known) {
            $this->assertContains($known, $found, $known.' 이 스캔 결과에 없다 — 검출기가 죽었다');
        }

        return $found;
    }

    /**
     * 저장소 상대 경로의 파일이 있는지 확인하고 주석을 지운 원문을 돌려준다.
     *
     * @param  string  $relative  저장소 루트 기준 파일 경로
     * @return string 주석을 지운 소스
     */
    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 3).'/'.$relative;
        $this->assertFileExists($path);

        // 주석을 지운 뒤 대조한다 — 원문에는 주석 처리된 배선도 글자로 남아, 원문 대조는 「그 배선이 살아 있다」를 증명하지 못한다 (12차 메타 검사 ①).
        return $this->stripComments((string) file_get_contents($path));
    }

    /**
     * 스캔으로 찾은 스폰 표면의 모든 스폰 프리미티브 호출이 그것을 감싼 함수 안에서 사용 가능 판정 가드를 거치는지 확인한다.
     *
     * @effects settings_system_info_shell_calls_are_guarded
     *
     * @return void 반환값 없음
     */
    public function test_every_shell_call_sits_behind_an_availability_guard(): void
    {
        $unguarded = [];
        $checked = 0;

        // 모집단을 하드코딩 열거가 아니라 **스캔**으로 잡고, 어휘도 스폰 계열 전수로 넓힌다
        // 종전에는 두 파일 × `shell_exec` 하나뿐이라 세 번째 파일에 무가드
        // 호출이 생기거나 같은 파일에 exec(·proc_open( 이 생겨도 초록이었다.
        foreach ($this->scanSpawnSurfaces() as $relative) {
            $source = $this->source($relative);

            foreach (self::SPAWN_PRIMITIVES as $primitive) {
                preg_match_all('/@?(?<![\w$>-])'.$primitive.'\s*\(/', $source, $calls, PREG_OFFSET_CAPTURE);

                foreach ($calls[0] as $call) {
                    $enclosing = $this->enclosingFunctionName($source, $call[1]);
                    $body = $enclosing === '(none)' ? $source : $this->functionBody($source, $enclosing);
                    $bodyAt = $enclosing === '(none)' ? (int) $call[1] : (int) $call[1] - (int) strpos($source, $body);
                    $checked++;

                    // 「본문에 글자가 있는가」가 아니라 「이 호출이 가드 안에 있는가」를 본다.
                    // 종전 판정(str_contains)은 조건을 뒤집어도(`&& ! hasFunction(...)` — 차단
                    // 환경에서 **오히려** 셸을 부른다) 가드를 통째로 밖으로 내보내도 초록이었다.
                    //형제 커버리지 테스트 4종은 전부 부정 픽스처를 갖는데
                    // 이 파일만 없어 세 경우(정상·반전·가드 밖)가 구분되지 않았다.
                    if (! $this->guardCoversOffset($body, $bodyAt, $this->sourceIsPhp($source))) {
                        $unguarded[] = $relative.'::'.$enclosing.' ('.$primitive.')';
                    }
                }
            }
        }

        // 이관 대기 목록은 **정확히 일치**해야 한다 — 하나라도 사라지면(이관 완료) 목록에서
        // 지워야 하고, 새로 생기면 아래 단언이 잡는다.
        $unguarded = array_values(array_unique($unguarded));
        $deferred = array_values(array_intersect($unguarded, self::DEFERRED_TO_OTHER_TRACKS));
        sort($deferred);
        $expectedDeferred = self::DEFERRED_TO_OTHER_TRACKS;
        sort($expectedDeferred);
        $this->assertSame(
            $expectedDeferred,
            $deferred,
            '이관 대기 목록이 실제와 어긋난다 — 이관이 끝났으면 목록에서 지운다'
        );
        $unguarded = array_values(array_diff($unguarded, self::DEFERRED_TO_OTHER_TRACKS));

        $this->assertGreaterThanOrEqual(3, $checked, '셸 호출 지점을 하나도 찾지 못했다 — 검출기가 죽었을 때의 vacuous green 을 막는 하한이다');
        $this->assertSame(
            [],
            $unguarded,
            "가용성 판정 없이 셸을 부르는 지점 (차단 환경에서 fatal Error): \n  ".implode("\n  ", $unguarded)
        );
    }

    /**
     * 이 호출 위치가 가용성 가드의 **보호 범위 안**인가.
     *
     * 실제로 쓰이는 세 형태만 통과시킨다:
     *  ① 조기 반환 — `if (! hasFunction(...)) { return …; }` 뒤
     *  ② 조건 블록 — `if|elseif (… hasFunction(…) …) { … }` 안 (부정이 아닌 조건)
     *  ③ 삼항 참 가지 — `hasFunction(…) ? …호출… : …`
     *
     * @param  string  $body  함수 본문 (또는 파일 전체)
     * @param  int  $offset  본문 기준 호출 위치
     * @param  bool  $isPhp  이 본문이 PHP 인가 — **호출부가 알려 준다**. 본문에는
     *                       `<?php` 가 없어 추론에 맡기면 PHP 가 JS 스캐너로 흘러
     *                       heredoc·`#` 주석의 중괄호를 코드로 세게 된다.
     * @return bool 보호 범위 안이면 true
     */
    private function guardCoversOffset(string $body, int $offset, bool $isPhp): bool
    {
        // ① 조기 반환 가드
        if (preg_match_all('/\bif\s*\(\s*!\s*[A-Za-z_\\\\:]*hasFunction\s*\(/', $body, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as $guard) {
                $open = strpos($body, '{', (int) $guard[1]);
                if ($open === false) {
                    continue;
                }
                $close = $this->matchingBrace($body, $open, $isPhp);
                $block = substr($body, $open, $close - $open + 1);
                if (preg_match('/\b(?:return|throw|exit)\b/', $block) !== 1) {
                    continue;   // 빠져나가지 않는 블록은 가드가 아니다
                }
                if ($offset > $close) {
                    return true;
                }
            }
        }

        // ② 조건 블록 — 조건 안의 hasFunction 이 부정되지 않아야 한다
        if (preg_match_all('/\b(?:if|elseif)\s*\(/', $body, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as $condition) {
                $parenAt = (int) $condition[1] + strlen($condition[0]) - 1;
                $conditionEnd = $this->matchingParen($body, $parenAt);
                if ($conditionEnd === null) {
                    continue;
                }
                $text = substr($body, $parenAt, $conditionEnd - $parenAt + 1);
                if (preg_match('/(?<!!)(?<!!\s)\bhasFunction\s*\(/', $text) !== 1) {
                    continue;
                }
                if (preg_match('/!\s*[A-Za-z_\\\\:]*hasFunction\s*\(/', $text) === 1) {
                    continue;   // 부정 조건은 ① 이 다룬다
                }
                $open = strpos($body, '{', $conditionEnd);
                if ($open === false) {
                    continue;
                }
                $close = $this->matchingBrace($body, $open, $isPhp);
                if ($offset > $open && $offset < $close) {
                    return true;
                }
            }
        }

        // ③ 삼항 참 가지
        if (preg_match_all('/(?<!!)(?<!!\s)\bhasFunction\s*\(/', $body, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as $call) {
                $parenAt = strpos($body, '(', (int) $call[1]);
                if ($parenAt === false) {
                    continue;
                }
                $callEnd = $this->matchingParen($body, $parenAt);
                if ($callEnd === null) {
                    continue;
                }
                $rest = substr($body, $callEnd + 1);
                if (preg_match('/^\s*\?(?!:)/', $rest, $q, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                $branchStart = $callEnd + 1 + (int) $q[0][1] + strlen($q[0][0]);
                $branchEnd = $this->ternaryBranchEnd($body, $branchStart);
                if ($offset > $branchStart && $offset < $branchEnd) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 여는 괄호에 대응하는 닫는 괄호 위치.
     *
     * @param  string  $source  소스
     * @param  int  $open  여는 괄호 위치
     * @return int|null 닫는 위치 (짝이 없으면 null)
     */
    private function matchingParen(string $source, int $open): ?int
    {
        $depth = 0;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '(') {
                $depth++;
            } elseif ($source[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * 삼항 참 가지의 끝 — 깊이 0 의 `:` 또는 문장 끝(`;`).
     *
     * `?:`(엘비스)와 괄호 안의 `:` 는 분기 구분자가 아니다.
     *
     * @param  string  $source  소스
     * @param  int  $from  참 가지 시작 위치
     * @return int 끝 위치
     */
    private function ternaryBranchEnd(string $source, int $from): int
    {
        $depth = 0;
        $length = strlen($source);

        for ($i = $from; $i < $length; $i++) {
            $ch = $source[$i];
            if ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth--;
            } elseif ($depth <= 0 && ($ch === ':' || $ch === ';')) {
                return $i;
            }
        }

        return $length;
    }

    /**
     * 판정의 **부정 분기**를 합성 입력으로 태운다.
     *
     * 실 소스가 준수 상태라 위 검사의 「무가드 0」은 판정이 무엇이든 통과시키게 망가져도
     * 유지된다. 실측한 두 변이(조건 반전·가드 밖 호출)를 그대로 픽스처로 고정한다 —
     * 종전 판정(`str_contains($body, 'hasFunction(')`)은 셋 다 통과시켰다.
     *
     * @effects settings_system_info_shell_calls_are_guarded
     *
     * @return void 반환값 없음
     */
    public function test_guard_judgment_actually_discriminates(): void
    {
        $earlyReturn = "<?php\nfunction f() {\n    if (! ExecutionEnvironment::hasFunction('shell_exec')) {\n        return null;\n    }\n\n    \$out = shell_exec('nproc');\n}\n";
        $conditionalBlock = "<?php\nfunction f() {\n    if (PHP_OS_FAMILY === 'Windows' && ExecutionEnvironment::hasFunction('shell_exec')) {\n        \$out = @shell_exec('wmic');\n    }\n}\n";
        $ternary = "<?php\nfunction f() {\n    \$cores = ExecutionEnvironment::hasFunction('shell_exec')\n        ? ((int) @shell_exec('nproc') ?: 1)\n        : 1;\n}\n";

        foreach (['early_return' => $earlyReturn, 'conditional_block' => $conditionalBlock, 'ternary' => $ternary] as $label => $src) {
            $body = $this->functionBody($src, 'f');
            $at = strpos($body, 'shell_exec(\'');
            $this->assertNotFalse($at, $label.' 픽스처에서 셸 호출을 찾지 못했다');
            $this->assertTrue($this->guardCoversOffset($body, (int) $at, true), $label.' 은 가드 안의 호출이다');
        }

        // (a) 조건 반전 — 차단 환경에서 **오히려** 셸을 부른다
        $inverted = "<?php\nfunction f() {\n    if (PHP_OS_FAMILY === 'Windows' && ! ExecutionEnvironment::hasFunction('shell_exec')) {\n        \$out = @shell_exec('wmic');\n    }\n}\n";
        // (b) 가드가 조기 반환이 아니라 평범한 대입 — 호출이 보호 범위 밖으로 나간다
        $movedOut = "<?php\nfunction f() {\n    \$available = ExecutionEnvironment::hasFunction('shell_exec');\n    \$out = @shell_exec('wmic');\n\n    return \$available;\n}\n";
        // (c) 이름만 있고 가드가 아닌 경우 — 종전 판정이 통과시키던 최소 형태
        $mentionOnly = "<?php\nfunction f() {\n    // ExecutionEnvironment::hasFunction('shell_exec')\n    \$note = 'hasFunction(';\n    \$out = shell_exec('wmic');\n}\n";

        foreach (['inverted' => $inverted, 'moved_out' => $movedOut, 'mention_only' => $mentionOnly] as $label => $src) {
            $body = $this->functionBody($src, 'f');
            $at = strpos($body, "shell_exec('wmic')");
            $this->assertNotFalse($at, $label.' 픽스처에서 셸 호출을 찾지 못했다');
            $this->assertStringContainsString(
                'hasFunction(',
                $body,
                $label.' 은 본문에 이름을 들고 있다 — 그래서 종전 판정이 통과시켰다'
            );
            $this->assertFalse(
                $this->guardCoversOffset($body, (int) $at, true),
                $label.' 은 가드 밖의 호출이다 — 차단 환경에서 fatal Error 가 된다'
            );
        }
    }

    /**
     * 설정 화면의 두 지점이 모집단에서 빠지지 않는다 — 매니페스트 축이 그 둘을 요구한다.
     *
     * @effects settings_system_info_shell_calls_are_guarded
     *
     * @return void 반환값 없음
     */
    public function test_settings_surfaces_are_in_the_population(): void
    {
        $source = $this->source('app/Services/SettingsService.php');

        foreach (self::GUARDED_SURFACES['app/Services/SettingsService.php'] as $method) {
            $body = $this->functionBody($source, $method);

            $this->assertNotSame('', $body, "{$method}() 가 사라졌다 — 매니페스트 축(shell_exec_guard_surface)을 함께 정리할 것");
            $this->assertStringContainsString(
                "hasFunction('shell_exec')",
                $body,
                "{$method}() 의 셸 호출 가드가 사라지면 disable_functions 환경에서 설정 화면이 fatal 로 죽는다"
            );
        }
    }
}
