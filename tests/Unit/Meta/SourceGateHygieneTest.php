<?php

namespace Tests\Unit\Meta;

use PHPUnit\Framework\TestCase;

/**
 * 게이트 자신을 스캔하는 메타 검사 (12차 라운드 — PO 승인).
 *
 * ## 왜 필요한가
 *
 * 12차 D축이 드러낸 패턴은 개별 결함이 아니라 **형태**다: 한 라운드가 결함을 고치면서 그
 * 결함의 형제 지점을 남긴다.
 *
 *  - 10차 #G4(주석 채널)는 `InstallerEndpointGuardCoverageTest` 의 **두 검사에만**
 *    `stripComments()` 를 적용했다 → 같은 파일의 형제 2검사·터치포인트 스캐너·모니터 계약
 *    20여 단언이 원문 대조로 남았다.
 *  - 11차 #D10(비재귀 스캔)은 격리 스캐너 하나만 재귀로 바꿨다 → 스타일 클래스 스캐너와
 *    자리표시자 스캐너의 한 겹 `glob()` 이 그대로 남았다.
 *
 * 둘 다 「고쳤다」고 기록된 축이고, 남은 형제는 **그 기록 때문에 오히려 덮여 있는 것처럼**
 * 보였다. 그래서 개별 지점이 아니라 규칙 자체를 게이트로 세운다.
 *
 * ## 두 축
 *
 *  ① 소스를 문자로 대조하는 게이트는 예외 없이 주석을 제거한다.
 *     원문에는 주석 처리된 배선도 글자로 남는다 — 원문 대조는 「배선이 살아 있다」를 증명하지
 *     못한다. 실측으로 확인된 실패 모드다(GI-3·GI-4·L-6).
 *  ② 생산 소스 모집단을 만드는 스캔은 예외 없이 재귀다.
 *     한 겹 `glob('*.php')` 은 하위 디렉토리가 하나 생기는 순간 그 안을 통째로 놓친다 —
 *     모집단 밖의 위반은 영원히 초록이다(L-1·L-2).
 *
 * ## 대기 목록(ledger)의 뜻
 *
 * 아직 이관하지 않은 지점은 **정확히 일치하는 목록**으로 고정한다 — 새로 생기면 red 가 되고,
 * 고쳤는데 목록에 남아 있어도 red 가 된다. 조용히 스캔 밖으로 빼면 그 사실 자체가 사라지므로
 * 목록에 남기는 쪽을 택한다 (11차 #D9 의 `DEFERRED_TO_OTHER_TRACKS` 와 같은 규율).
 *
 * 커버 축: gate_hygiene comment_channel·recursive_scan / judgment compliant·pending·detector_fixture
 *
 * @effects installer_source_gates_strip_comments_and_scan_recursively
 */
class SourceGateHygieneTest extends TestCase
{
    /** 주석 제거 헬퍼로 인정하는 이름 — 이 중 하나를 지나야 「대조 전에 지웠다」이다. */
    private const STRIPPERS = ['stripComments', 'stripJsComments', 'withoutComments', 'stripSourceComments'];

    /**
     * 생산 소스 뿌리 — 이 경로를 읽는 게이트만 이 검사의 대상이다.
     *
     * 접미 슬래시를 요구하지 않는다. 뿌리를 한 번 잡아 두고(`.'/public/install'`) 뒤에서
     * 조각을 잇는 형태(`$root.'/views/5-installation.php'`)가 흔한데, 슬래시를 요구하면
     * 그 형태가 통째로 이 축 밖으로 빠진다 — 실제로 한 파일이 그렇게 빠져 있었다.
     */
    private const PRODUCTION_ROOTS = ['public/install', 'app/Services', 'app/Support', 'app/Execution'];

    /** 원문 대조로 판정하는 단언·표현 */
    private const CONTAINMENT_ASSERTIONS = [
        'assertStringContainsString(',
        'assertMatchesRegularExpression(',
        'assertStringNotContainsString(',
        'assertDoesNotMatchRegularExpression(',
        'toContain(',
    ];

    /**
     * 소스가 아닌 산출물(상태·설정·스타일·로그)을 읽는 표기 — 이 축의 대상이 아니다.
     *
     * 주석 채널이 문제가 되는 것은 **코드**를 문자로 대조할 때다. JSON 상태 파일이나 로그를
     * 읽어 값을 확인하는 것은 그 결함군과 무관하다.
     */
    private const NON_SOURCE_HINTS = ['.json', '.css', '.yaml', '.yml', '.log', '.env', 'storage/', 'php://input', 'stateFile', 'logPath'];

    /**
     * ① 축 — 아직 주석 제거로 이관하지 않은 게이트 (파일 단위, 사유 필수).
     *
     * 전부 「원문 대조로 생산 소스를 판정한다」는 같은 형태다. 이 라운드가 소유한 5건
     * (엔드포인트 가드·터치포인트·모니터 계약·스타일 클래스·자리표시자 스캐너)은 이관했고,
     * 나머지는 다른 공정이 같은 창에서 수정 중인 파일이라 여기서 함께 건드리면 충돌한다.
     *
     * @var array<string, string>
     */
    private const PENDING_RAW_SOURCE_GATES = [];

    /**
     * ② 축 — 아직 재귀 스캔으로 이관하지 않은 지점 (파일 단위, 사유 필수).
     *
     * @var array<string, string>
     */
    private const PENDING_NON_RECURSIVE_SCANS = [];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * 모집단 — 인스톨러 테스트 전수 + 무CLI 두 매니페스트가 선언한 테스트 파일.
     *
     * 손으로 열거하지 않는다. 매니페스트가 「이 공정을 검증한다」고 선언한 파일이 곧 이 규칙의
     * 대상이고, 새 파일이 선언되면 자동으로 모집단에 들어온다.
     *
     * @return array<int, string> 저장소 상대 경로 (정렬됨)
     */
    private function populationFiles(): array
    {
        $files = [];

        $installerTests = self::root().'/tests/Unit/Installer';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($installerTests, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen(self::root()) + 1));
            }
        }

        foreach ([
            'tests/scenarios/no-cli-installer-stepwise.yaml',
            'tests/scenarios/no-cli-execution-mode.yaml',
        ] as $manifest) {
            foreach ($this->declaredTestFiles($manifest) as $relative) {
                if (is_file(self::root().'/'.$relative)) {
                    $files[] = $relative;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        // 스캐너 자신은 모집단이 아니다 — 검사 대상 문자열을 본문에 들고 있을 뿐이다.
        return array_values(array_filter(
            $files,
            static fn (string $f): bool => ! str_ends_with($f, 'tests/Unit/Meta/SourceGateHygieneTest.php'),
        ));
    }

    /**
     * 매니페스트의 `test_files` 목록.
     *
     * @param  string  $manifest  매니페스트 상대 경로
     * @return array<int, string>
     */
    private function declaredTestFiles(string $manifest): array
    {
        $lines = file(self::root().'/'.$manifest, FILE_IGNORE_NEW_LINES) ?: [];
        $files = [];
        $inBlock = false;

        foreach ($lines as $line) {
            if (preg_match('/^test_files:\s*$/', $line) === 1) {
                $inBlock = true;

                continue;
            }
            if (! $inBlock) {
                continue;
            }
            if (preg_match('/^\s*#/', $line) === 1 || trim($line) === '') {
                continue;
            }
            if (preg_match('/^\s*-\s*(\S+)\s*$/', $line, $m) !== 1) {
                break;
            }
            $files[] = $m[1];
        }

        return $files;
    }

    /**
     * 이 오프셋이 속한 한 문장 (앞은 직전 `;`/`{`/`}`, 뒤는 다음 `;`).
     *
     * @param  string  $source  소스
     * @param  int  $offset  위치
     * @return string 문장
     */
    private function statementAt(string $source, int $offset): string
    {
        $start = 0;
        foreach ([';', '{', '}'] as $boundary) {
            $at = strrpos(substr($source, 0, $offset), $boundary);
            if ($at !== false && $at + 1 > $start) {
                $start = $at + 1;
            }
        }

        $end = strpos($source, ';', $offset);
        $end = $end === false ? strlen($source) : $end;

        return substr($source, $start, $end - $start);
    }

    /**
     * 이 파일이 「원문 대조로 생산 소스를 판정하는 게이트」이면서 주석을 지우지 않는가.
     *
     * @param  string  $src  테스트 소스
     * @return bool 위반이면 true
     */
    private function comparesRawSource(string $src): bool
    {
        $touchesProduction = false;
        foreach (self::PRODUCTION_ROOTS as $rootPath) {
            if (str_contains($src, $rootPath)) {
                $touchesProduction = true;
                break;
            }
        }
        if (! $touchesProduction) {
            return false;
        }

        $judgesByText = false;
        foreach (self::CONTAINMENT_ASSERTIONS as $assertion) {
            if (str_contains($src, $assertion)) {
                $judgesByText = true;
                break;
            }
        }
        if (! $judgesByText) {
            return false;
        }

        return $this->unstrippedSourceReads($src) !== [];
    }

    /**
     * 주석 제거를 거치지 않는 **소스 읽기** 지점.
     *
     * @param  string  $src  테스트 소스
     * @return array<int, string> 문장 조각
     */
    private function unstrippedSourceReads(string $src): array
    {
        $found = [];

        // `file()` 도 소스를 통째로 읽는다 — 메서드 호출(`$this->file(`)과 구분하기 위해
        // 앞 글자가 식별자·`>`·`:`·`$` 가 아닐 때만 함수 호출로 본다.
        $readPattern = '/(?<![\w>:$])(?:file_get_contents|readFileSync|file|SplFileObject)\s*\(/';

        if (preg_match_all($readPattern, $src, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return $found;
        }

        foreach ($matches[0] as $match) {
            $offset = (int) $match[1];
            $statement = $this->statementAt($src, $offset);

            $isNonSource = false;
            foreach (self::NON_SOURCE_HINTS as $hint) {
                if (str_contains($statement, $hint)) {
                    $isNonSource = true;
                    break;
                }
            }
            if ($isNonSource) {
                continue;
            }

            $wrapped = false;
            foreach (self::STRIPPERS as $stripper) {
                if (str_contains($statement, $stripper.'(')) {
                    $wrapped = true;
                    break;
                }
            }
            if ($wrapped) {
                continue;
            }

            // 인라인 면제 — 원문이 **필요한** 읽기(구간 표식이 주석 안에 있는 경우 등)는
            // 그 자리에 사유를 남긴다. 표식이 곧 이관 대상 목록이다.
            $lineStart = strrpos(substr($src, 0, $offset), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart;
            // 한국어 사유는 글자당 3바이트라 창을 넉넉히 잡는다 (표식이 docblock 안에 있는 경우).
            $windowStart = max(0, $lineStart - 1200);
            if (str_contains(substr($src, $windowStart, $offset - $windowStart), 'meta:allow raw-source-read')) {
                continue;
            }

            $found[] = trim(preg_replace('/\s+/', ' ', $statement) ?? '');
        }

        return $found;
    }

    /**
     * 생산 소스 모집단을 한 겹 `glob()` 으로 만드는가.
     *
     * @param  string  $src  테스트 소스
     * @return bool 위반이면 true
     */
    private function scansNonRecursively(string $src): bool
    {
        // `scandir()` 은 확장자 패턴조차 없는 한 겹 나열이다 — 뿌리가 생산 소스면 그대로 위반.
        if (preg_match_all('/\bscandir\s*\(([^;]*)/', $src, $scandirs) !== 0) {
            foreach ($scandirs[1] as $argument) {
                if (str_contains($argument, 'public/install')
                    || preg_match('/install(?:er)?(?:Root|Dir)\s*\(/i', $argument) === 1) {
                    return true;
                }
            }
        }

        if (preg_match_all('/\bglob\s*\(/', $src, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return false;
        }

        foreach ($matches[0] as $match) {
            $offset = (int) $match[1];
            $open = strpos($src, '(', $offset);
            if ($open === false) {
                continue;
            }

            // **짝이 맞는 괄호**까지 자른다 — 첫 `)` 에서 끊으면 `glob($this->installRoot()` 에서
            // 멈춰 확장자 패턴이 인자에서 사라진다(검출기가 통째로 무력화된다).
            $close = $this->matchingParen($src, $open);
            if ($close === null) {
                continue;
            }
            $expression = substr($src, $open, $close - $open + 1);

            // 한 겹 확장자 패턴만 본다 — `**` 는 대상이 아니다.
            // `*.{php,js}`(GLOB_BRACE) 도 같은 한 겹이다.
            if (preg_match('#/\*\.(?:\{[a-z,]+\}|php|js|ts|css)#', $expression) !== 1) {
                continue;
            }
            if (str_contains($expression, '**')) {
                continue;
            }

            // 생산 소스를 가리키는가 — 경로 리터럴이나 인스톨러 뿌리 헬퍼를 통해.
            $context = substr($src, max(0, $offset - 400), 800);
            $pointsAtProduction = str_contains($expression, 'public/install')
                || preg_match('/install(?:er)?(?:Root|Dir)\s*\(/i', $expression) === 1
                || (preg_match('/\$(?:dir|installDir|root)\b/', $expression) === 1 && str_contains($context, 'public/install'));

            if ($pointsAtProduction) {
                return true;
            }
        }

        return false;
    }

    /**
     * 여는 괄호에 대응하는 닫는 괄호 위치.
     *
     * @param  string  $source  소스
     * @param  int  $open  여는 괄호 위치
     * @return int|null 닫는 위치
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

    public function test_population_is_not_empty(): void
    {
        $files = $this->populationFiles();

        $this->assertGreaterThanOrEqual(25, count($files), '모집단이 비면 이 메타 검사는 무의미하다');
        $this->assertContains('tests/Unit/Installer/InstallerEndpointGuardCoverageTest.php', $files);
        $this->assertContains('resources/js/installer/__tests__/installer-token-wiring.test.ts', $files, '매니페스트가 선언한 vitest 파일도 모집단이다');
    }

    /**
     * ① 소스를 문자로 대조하는 게이트는 예외 없이 주석을 제거한다.
     */
    public function test_source_comparing_gates_strip_comments(): void
    {
        $offenders = [];

        foreach ($this->populationFiles() as $relative) {
            $src = (string) file_get_contents(self::root().'/'.$relative);

            if ($this->comparesRawSource($src)) {
                $offenders[] = $relative;
            }
        }

        sort($offenders);

        $pending = array_keys(self::PENDING_RAW_SOURCE_GATES);
        sort($pending);

        $this->assertSame(
            $pending,
            $offenders,
            "원문 대조로 생산 소스를 판정하는 게이트다 — 주석을 지운 뒤 대조하거나, 이관 대기 목록에 사유와 함께 등재할 것:\n  "
                .implode("\n  ", array_values(array_diff($offenders, $pending)))
                ."\n이관이 끝났는데 목록에 남아 있는 항목:\n  "
                .implode("\n  ", array_values(array_diff($pending, $offenders)))
        );
    }

    /**
     * ② 생산 소스 모집단을 만드는 스캔은 예외 없이 재귀다.
     */
    public function test_production_source_population_scans_are_recursive(): void
    {
        $offenders = [];

        foreach ($this->populationFiles() as $relative) {
            $src = (string) file_get_contents(self::root().'/'.$relative);

            if ($this->scansNonRecursively($src)) {
                $offenders[] = $relative;
            }
        }

        sort($offenders);

        $pending = array_keys(self::PENDING_NON_RECURSIVE_SCANS);
        sort($pending);

        $this->assertSame(
            $pending,
            $offenders,
            "생산 소스 모집단을 한 겹 glob() 으로 만든다 — 재귀 스캔으로 바꾸거나, 이관 대기 목록에 사유와 함께 등재할 것:\n  "
                .implode("\n  ", array_values(array_diff($offenders, $pending)))
        );
    }

    /**
     * 대기 목록 항목은 전부 사유를 갖고, 실재하는 파일을 가리킨다.
     */
    public function test_pending_ledgers_are_documented_and_alive(): void
    {
        // 목록이 비어도 이 검사가 「아무것도 하지 않은 초록」이 되지 않도록 형태를 먼저 고정한다.
        $this->assertIsArray(self::PENDING_RAW_SOURCE_GATES);
        $this->assertIsArray(self::PENDING_NON_RECURSIVE_SCANS);

        foreach ([self::PENDING_RAW_SOURCE_GATES, self::PENDING_NON_RECURSIVE_SCANS] as $ledger) {
            foreach ($ledger as $relative => $reason) {
                $this->assertNotSame('', trim($reason), $relative.' 의 대기 사유가 비어 있다 — 사유 없는 면제는 사각과 같다');
                $this->assertFileExists(self::root().'/'.$relative, $relative.' 이 사라졌다 — 대기 목록을 정리할 것');
            }
        }
    }

    /**
     * 두 판정의 **부정 분기**를 합성 입력으로 태운다.
     *
     * 실 모집단이 준수 상태(또는 대기 목록과 일치)라 위 두 검사는 판정이 무엇이든 통과하도록
     * 망가져도 초록을 유지한다 — 그 상태는 「검사했으나 통과」가 아니라 「아무것도 보지 않음」이다.
     */
    public function test_detectors_actually_discriminate(): void
    {
        $rawGate = <<<'PHP'
            <?php
            $src = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/run-task.php');
            $this->assertStringContainsString('installerStepwiseTokenRejection(', $src);
            PHP;
        $strippedGate = <<<'PHP'
            <?php
            $src = $this->stripComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/run-task.php'));
            $this->assertStringContainsString('installerStepwiseTokenRejection(', $src);
            PHP;
        $stateReader = <<<'PHP'
            <?php
            $state = json_decode((string) file_get_contents(self::$base.'/storage/installer-state.json'), true);
            $this->assertStringContainsString('failed', $state['installation_status']);
            // public/install/ 을 언급하지만 읽는 것은 상태 파일이다
            PHP;
        $behaviourTest = <<<'PHP'
            <?php
            require_once dirname(__DIR__, 3).'/public/install/includes/execution-mode.php';
            $this->assertSame('stepwise', installerResolveInstallationMode([])['mode']);
            PHP;

        $this->assertTrue($this->comparesRawSource($rawGate), '원문 대조 게이트를 놓치면 이 축이 통째로 무의미해진다');
        $this->assertFalse($this->comparesRawSource($strippedGate), '주석을 지운 게이트는 위반이 아니다');
        $this->assertFalse($this->comparesRawSource($stateReader), '상태 파일 읽기는 주석 채널 결함군과 무관하다');
        $this->assertFalse($this->comparesRawSource($behaviourTest), '행위 테스트는 문자 대조 게이트가 아니다');

        $flatGlob = <<<'PHP'
            <?php
            $files = glob($this->installRoot().'/views/*.php') ?: [];
            PHP;
        $recursive = <<<'PHP'
            <?php
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->installRoot()));
            PHP;
        $manifestGlob = <<<'PHP'
            <?php
            foreach (glob(self::root().'/tests/scenarios/*.yaml') ?: [] as $path) {
            }
            PHP;

        // ── 13차: 실측으로 확인된 **사각 4형태**. 검출기를 좁힌 뒤 그 좁힘이 조용히
        //    되돌려지지 않도록 여기서 고정한다. 넷 다 좁히기 전에는 통째로 통과했다.
        $rootThenSuffix = <<<'PHP'
            <?php
            $root = dirname(__DIR__, 3).'/public/install';
            $src = (string) file_get_contents($root.'/views/5-installation.php');
            $this->assertStringContainsString('taskGroupByTaskId', $src);
            PHP;
        $fileFunctionRead = <<<'PHP'
            <?php
            $lines = file(dirname(__DIR__, 3).'/public/install/api/run-task.php');
            $this->assertStringContainsString('hash_equals(', implode('', $lines));
            PHP;

        $this->assertTrue(
            $this->comparesRawSource($rootThenSuffix),
            '뿌리를 먼저 잡고 뒤에서 조각을 잇는 형태 — 접미 슬래시를 요구하면 이 형태가 축 밖으로 빠진다'
        );
        $this->assertTrue(
            $this->comparesRawSource($fileFunctionRead),
            'file() 도 소스를 통째로 읽는다 — 읽기 표기를 file_get_contents 로만 한정하면 사각이 된다'
        );

        $this->assertTrue($this->scansNonRecursively($flatGlob), '한 겹 glob 을 놓치면 모집단 사각이 그대로 남는다');
        $this->assertFalse($this->scansNonRecursively($recursive));
        $this->assertFalse($this->scansNonRecursively($manifestGlob), '생산 소스가 아닌 매니페스트 목록은 이 축의 대상이 아니다');

        $braceGlob = <<<'PHP'
            <?php
            $files = glob($this->installRoot().'/views/*.{php,js}', GLOB_BRACE) ?: [];
            PHP;
        $flatScandir = <<<'PHP'
            <?php
            $files = scandir($this->installRoot().'/views');
            PHP;

        $this->assertTrue($this->scansNonRecursively($braceGlob), '중괄호 확장자 패턴도 한 겹이다');
        $this->assertTrue($this->scansNonRecursively($flatScandir), 'scandir() 은 확장자 패턴조차 없는 한 겹 나열이다');
    }
}
