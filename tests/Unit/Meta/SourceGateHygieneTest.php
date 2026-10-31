<?php

namespace Tests\Unit\Meta;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 게이트 자신을 스캔하는 메타 검사 (12차 라운드에서 승인).
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
 */
class SourceGateHygieneTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 주석 제거 헬퍼로 인정하는 이름 — 이 중 하나를 지나야 「대조 전에 지웠다」이다.
     *
     * 대조는 **식별자 경계**로 한다 (G3 회차 14 W4-8). 종전에는 `str_contains($statement, $name.'(')`
     * 였고, `withoutComments(` 가 `sourceWithoutComments(` 의 부분문자열이라 공용 진입점이
     * 목록에 없는데도 통과했다 — 그 우연이 사라지면(이름을 바꾸면) 이관한 게이트 전부가 한꺼번에
     * 위반으로 읽힌다. 진입점을 명시하고, 부분문자열 매칭을 없앤다.
     */
    private const STRIPPERS = ['sourceWithoutComments', 'sourceWithoutCommentsAndStrings', 'stripComments', 'stripJsComments', 'withoutComments', 'stripSourceComments'];

    /**
     * 스트리퍼 ↔ 읽는 파일의 **언어 정합** (G3 회차 14 W4-8).
     *
     * 스트리퍼를 거쳤다는 사실만 재면 언어를 잘못 고른 읽기가 통과한다 — JS 파일에 PHP
     * 토크나이저를 쓰면 파일 전체가 인라인 HTML 토큰 하나라 아무것도 지워지지 않고, PHP
     * **뷰**(`views/*.php`)에 `stripComments()` 만 쓰면 `<script>` 안의 JS 주석이 남는다
     * (실물: 5-installation.php 를 `stripComments()` 로 읽던 게이트). 뷰는 두 언어라
     * `sourceWithoutComments()` 만 정합이다.
     *
     * 값은 「그 스트리퍼가 **감당하지 못하는** 파일 형태」의 정규식이다 — 읽기 문장의 경로
     * 리터럴에 매치하면 위반. 확장자로 언어를 고르는 두 진입점(`sourceWithoutComments` ·
     * `sourceWithoutCommentsAndStrings`)은 감당하지 못하는 형태가 없어 값이 `null` 이다 — 표에
     * 두는 것은 「빠뜨린 것이 아니라 정합 축이 없다」를 적기 위해서다 (G3 회차 15 F-32).
     *
     * `sourceWithoutCommentsAndStrings` 는 주석에 더해 **문자열 채널**(nowdoc/heredoc·안내 문구)
     * 까지 지운다. 배선을 `assertStringContainsString('installerXxx(', …)` 로 재는 게이트는 이쪽을
     * 써야 한다 — 주석만 지우면 nowdoc 안의 같은 글자로 초록이 된다.
     */
    private const STRIPPER_MISMATCH = [
        'sourceWithoutComments' => null,
        'sourceWithoutCommentsAndStrings' => null,
        'stripComments' => '~\.(?:js|ts|mjs|cjs)[\'"]|/views/[^\'"]*\.php[\'"]~',
        'stripJsComments' => '~\.php[\'"]~',
        'stripSourceComments' => '~\.(?:js|ts|mjs|cjs)[\'"]|/views/[^\'"]*\.php[\'"]~',
    ];

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
     * 원문을 **직접 훑는 판정 함수** — 결과를 일반 단언에 넘기는 형태 (G3 회차 14 W4-8).
     *
     * 위 다섯 단언만 보면 `preg_match_all(…, $src)` 로 모집단을 만들고 `assertSame()` 으로 판정하는
     * 게이트가 통째로 축 밖이다 — 실물이 그 상태였다(스타일 클래스 스캐너: 원문 3읽기 + 정규식
     * 도출 + `assertSame`/`assertNotEmpty`). 판정 함수와 일반 단언이 **함께** 있으면 문자 대조
     * 게이트로 본다.
     */
    private const TEXT_JUDGING_FUNCTIONS = ['preg_match(', 'preg_match_all(', 'str_contains(', 'strpos(', 'stripos(', 'substr_count(', 'str_starts_with('];

    /** `TEXT_JUDGING_FUNCTIONS` 의 결과를 받는 일반 단언. */
    private const GENERIC_ASSERTIONS = ['assertSame(', 'assertNotSame(', 'assertTrue(', 'assertFalse(', 'assertContains(', 'assertNotContains(', 'assertNotEmpty(', 'assertEmpty(', 'assertCount(', 'assertEquals(', 'expect('];

    /**
     * 소스가 아닌 산출물(상태·설정·스타일·로그)을 읽는 표기 — 이 축의 대상이 아니다.
     *
     * 주석 채널이 문제가 되는 것은 **코드**를 문자로 대조할 때다. JSON 상태 파일이나 로그를
     * 읽어 값을 확인하는 것은 그 결함군과 무관하다.
     */
    private const NON_SOURCE_HINTS = ['.json', '.css', '.yaml', '.yml', '.log', '.env', '.md', 'storage/', 'php://input', 'stateFile', 'logPath', 'BASE_PATH.$'];

    /**
     * ① 축 — 아직 주석 제거로 이관하지 않은 게이트 (파일 단위, 사유 필수).
     *
     * 전부 「원문 대조로 생산 소스를 판정한다」는 같은 형태다. 이 라운드가 소유한 5건
     * (엔드포인트 가드·터치포인트·모니터 계약·스타일 클래스·자리표시자 스캐너)은 이관했고,
     * 나머지는 다른 공정이 같은 창에서 수정 중인 파일이라 여기서 함께 건드리면 충돌한다.
     *
     * develop-7.2.0 병합으로 이 모집단에 들어온 인스톨러 테스트 6건(7.2.0 이 F25·F37 등 설치기 결함을
     * 고치며 더한 원문 대조 게이트)은 이관 전이라 여기 등재한다 — 그중 일부 읽기는 소스가 아니라
     * 자식 프로세스 출력·eval 실행이라 줄마다 `meta:allow raw-source-read` 판정이 함께 필요하다.
     *
     * @var array<string, string>
     */
    private const PENDING_RAW_SOURCE_GATES = [
        'tests/Unit/Installer/ExistingDbActionGuardTest.php' => '7.2.0 병합 유입 — task-runner.php 원문 대조, 주석 제거 이관 대기',
        'tests/Unit/Installer/InstallerDbTestGateTest.php' => '7.2.0 병합 유입 — check-configuration.php·request-handler.php 원문 대조, 주석 제거 이관 대기',
        'tests/Unit/Installer/InstallerDropConfirmTest.php' => '7.2.0 병합 유입 — install-process.php 원문 대조, 주석 제거 이관 대기',
        'tests/Unit/Installer/InstallerFinalizeCacheTasksTest.php' => '7.2.0 병합 유입 — task-runner.php·installer-state.php 원문 대조, 주석 제거 이관 대기',
        'tests/Unit/Installer/InstallerFinalizeWaitsForWorkerTest.php' => '7.2.0 병합 유입 — 자식 프로세스 출력·eval 읽기 판정(meta:allow) 포함 이관 대기',
        'tests/Unit/Installer/InstallerWorkerTimeLimitTest.php' => '7.2.0 병합 유입 — 생산 소스 원문 대조와 자식 프로세스 출력 읽기 판정 이관 대기',
    ];

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
        $path = self::root().'/'.$manifest;

        // 매니페스트가 없으면 **실패**다. 종전 `file(...) ?: []` 는 부재를 빈 목록으로 접어, 그
        // 매니페스트가 선언하던 테스트 파일 전부가 모집단에서 조용히 빠졌다 (G3 회차 14 W4-8).
        if (! is_file($path)) {
            throw new \RuntimeException('시나리오 매니페스트가 없다 — 그것이 선언하던 테스트 파일이 모집단에서 통째로 빠진다: '.$manifest);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('시나리오 매니페스트를 읽지 못했다: '.$manifest);
        }
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
     * 테스트 소스 자신도 **주석을 지운 뒤** 본다 — 그 언어의 스트리퍼로(G3 회차 13 W5-6).
     * 종전에는 원문을 봤다: 독블록 속 `file_get_contents(` 언급이 읽기로 잡히고, 읽기 문장
     * 앞의 주석이 문장에 딸려 들어와 위반 목록에 주석 문장이 실렸다. 언어는 추론하지 않고
     * 호출부가 말한다 — 모집단은 확장자를 알고 있다.
     *
     * @param  string  $raw  테스트 소스 원문
     * @param  bool  $isPhp  PHP 이면 true, JS/TS 이면 false
     * @return bool 위반이면 true
     */
    private function comparesRawSource(string $raw, bool $isPhp): bool
    {
        $src = $isPhp ? $this->stripComments($raw) : $this->stripJsComments($raw);

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

        if (! $this->judgesByText($src)) {
            return false;
        }

        return $this->unstrippedSourceReads($raw, $isPhp) !== [];
    }

    /**
     * 이 테스트 소스가 **문자로 판정**하는가 — 원문 대조 단언이 있거나, 원문을 훑는 판정 함수와
     * 일반 단언이 함께 있는가.
     *
     * @param  string  $src  주석을 지운 테스트 소스
     * @return bool 문자 대조 게이트면 true
     */
    private function judgesByText(string $src): bool
    {
        foreach (self::CONTAINMENT_ASSERTIONS as $assertion) {
            if (str_contains($src, $assertion)) {
                return true;
            }
        }

        $judges = false;
        foreach (self::TEXT_JUDGING_FUNCTIONS as $function) {
            if (preg_match('/(?<![A-Za-z0-9_>:$])'.preg_quote($function, '/').'/', $src) === 1) {
                $judges = true;
                break;
            }
        }
        if (! $judges) {
            return false;
        }

        foreach (self::GENERIC_ASSERTIONS as $assertion) {
            if (str_contains($src, $assertion)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 주석 제거를 거치지 않는 **소스 읽기** 지점.
     *
     * 세 가지는 이 축의 대상이 아니다 — ① 산출물 읽기(`NON_SOURCE_HINTS`), ② 스트리퍼를
     * 지난 읽기, ③ **실행하려고** 읽은 소스(`eval(source)` · `new Function(block)`). ③은
     * 문자 대조가 아니라 행위 테스트다 — 그 변수(와 그것에서 파생된 변수)가 문자 대조
     * 단언에 한 번도 닿지 않을 때만 제외한다. 파생을 따라가지 않으면 `const block =
     * src.slice(…)` 뒤의 `new Function(block)` 을 놓쳐 행위 테스트가 위반으로 잡힌다.
     *
     * @param  string  $raw  테스트 소스 원문
     * @param  bool  $isPhp  PHP 이면 true, JS/TS 이면 false
     * @return array<int, string> 문장 조각
     */
    private function unstrippedSourceReads(string $raw, bool $isPhp): array
    {
        $src = $isPhp ? $this->stripComments($raw) : $this->stripJsComments($raw);
        $rawLines = explode("\n", $raw);
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

            $wrapped = [];
            foreach (self::STRIPPERS as $stripper) {
                if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($stripper, '/').'\(/', $statement) === 1) {
                    $wrapped[] = $stripper;
                }
            }
            if ($wrapped !== []) {
                // 언어 정합 — 스트리퍼가 감당하지 못하는 파일 형태를 읽으면 「지웠다」가 아니다.
                // 한 문장에 스트리퍼가 **둘**이면 확장자로 분기하는 형태(`str_ends_with($f, '.js')
                // ? stripJsComments(…) : stripComments(…)`)라 문장 안의 `.js` 리터럴은 조건이지
                // 읽는 경로가 아니다 — 그 형태는 정합 판정 밖이다(그 분기의 뷰 처리는 정적으로
                // 판정할 수 없다).
                if (count($wrapped) === 1) {
                    $mismatch = self::STRIPPER_MISMATCH[$wrapped[0]] ?? null;
                    if ($mismatch !== null && preg_match($mismatch, $statement) === 1) {
                        $found[] = '[언어 불일치: '.$wrapped[0].'] '.trim(preg_replace('/\s+/', ' ', $statement) ?? '');
                    }
                }

                continue;
            }

            // 읽은 내용 **전체**를 기대값과 등가 비교하는 문장(`assertSame('…', file_get_contents($p))`)은
            // 값 검증이지 문자 대조가 아니다 — 주석 처리된 한 줄도 등가를 깨므로 주석 채널이 없다.
            // (임시 픽스처를 되읽는 행위 테스트가 이 형태다.)
            if (preg_match('/\bassert(?:Same|NotSame|Equals|NotEquals)\s*\(/', $statement) === 1) {
                continue;
            }

            if ($this->readIsOnlyExecuted($src, $statement, $isPhp)) {
                continue;
            }

            // 인라인 면제 — 원문이 **필요한** 읽기(구간 표식이 주석 안에 있는 경우 등)는
            // 그 자리에 사유를 남긴다. 표식이 곧 이관 대상 목록이다. 표식은 주석 안에 있으므로
            // **원문**에서 본다 — 스트리퍼가 줄 수를 보존하므로 줄 번호로 대응시킨다.
            $line = substr_count(substr($src, 0, $offset), "\n");
            $from = max(0, $line - 40);
            $window = implode("\n", array_slice($rawLines, $from, $line - $from + 1));
            if (str_contains($window, 'meta:allow raw-source-read')) {
                continue;
            }

            $found[] = trim(preg_replace('/\s+/', ' ', $statement) ?? '');
        }

        return $found;
    }

    /**
     * 이 읽기의 결과가 **실행**에만 쓰이고 문자 대조에는 닿지 않는가.
     *
     * @param  string  $src  주석을 지운 테스트 소스
     * @param  string  $statement  읽기 문장
     * @param  bool  $isPhp  PHP 이면 true
     * @return bool 실행 전용이면 true
     */
    private function readIsOnlyExecuted(string $src, string $statement, bool $isPhp): bool
    {
        $assignment = $isPhp ? '/\$(\w+)\s*=(?!=)/' : '/\b(?:const|let|var)\s+(\w+)\s*=/';
        if (preg_match($assignment, $statement, $m) !== 1) {
            return false;
        }

        // 파생 변수까지 접는다 — `$b = substr($a, …)` / `const block = src.slice(…)`.
        $names = [$m[1] => true];
        $assignments = $isPhp ? '/\$(\w+)\s*=\s*([^;]*);/' : '/\b(?:const|let|var)\s+(\w+)\s*=\s*([^;]*);/';
        preg_match_all($assignments, $src, $all, PREG_SET_ORDER);
        do {
            $grew = false;
            foreach ($all as [$whole, $name, $rhs]) {
                if (isset($names[$name]) || ! $this->mentionsAny($rhs, $names, $isPhp)) {
                    continue;
                }
                $names[$name] = true;
                $grew = true;
            }
        } while ($grew);

        $executed = false;
        if (preg_match_all('/(?:\beval|new\s+Function|\(0,\s*eval\))\s*\(([^;]*)/', $src, $sinks) > 0) {
            foreach ($sinks[1] as $arguments) {
                if ($this->mentionsAny($arguments, $names, $isPhp)) {
                    $executed = true;
                    break;
                }
            }
        }
        if (! $executed) {
            return false;
        }

        foreach (self::CONTAINMENT_ASSERTIONS as $assertion) {
            $at = 0;
            while (($at = strpos($src, $assertion, $at)) !== false) {
                if ($this->mentionsAny($this->statementAt($src, $at), $names, $isPhp)) {
                    return false;   // 실행도 하지만 문자 대조에도 쓴다 — 위반이다
                }
                $at += strlen($assertion);
            }
        }

        return true;
    }

    /**
     * @param  array<string, true>  $names
     */
    private function mentionsAny(string $text, array $names, bool $isPhp): bool
    {
        foreach (array_keys($names) as $name) {
            if (preg_match('/'.($isPhp ? '\$' : '\b').preg_quote($name, '/').'\b/', $text) === 1) {
                return true;
            }
        }

        return false;
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

            // 짝을 찾지 못하면 **검사할 수 없다는 사실을 드러낸다.** 종전에는 `continue` 라
            // 그 지점을 조용히 「위반 없음」으로 넘겼다(fail-open) — 괄호 판정이 어긋나는
            // 순간 `scansNonRecursively()` 가 아무것도 검사하지 않게 되는데 그 사실이
            // 어디에도 나타나지 않는다. 14차가 `matchingBrace` 에서 없앤 것과 문자 그대로
            // 같은 형태가 이 형제 게이트에 남아 있었다 (12차 G3 라운드 B-5).
            if ($close === null) {
                throw new \RuntimeException(
                    '괄호 짝 판정 실패: '.$open.' 위치의 `glob(` 인자 범위를 정할 수 없다 — '
                    .'이 지점을 「위반 없음」으로 넘기면 검출기가 조용히 무력화된다'
                );
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

    /**
     * 모집단 하한 — 게이트 두 테스트 **각각의 안**에서도 잰다 (G3 회차 14 W4-8).
     *
     * 하한을 별도 테스트에만 두면 `--filter` 로 게이트 하나만 돌릴 때 빈 모집단이 초록이 된다 —
     * 스캔 뿌리가 옮겨지거나 매니페스트 표기가 바뀌어 모집단이 비어도 그 실행은 「위반 0」이다.
     *
     * @param  array<int, string>  $files  모집단
     */
    private function assertPopulationFloor(array $files): void
    {
        // 하한은 **도출**한다 — 리터럴 25 는 실측 105 (2026-08-29) 의 4분의 1 이라, 모집단이 4분의 3
        // 사라져도 초록이었다 (G3 회차 15 F-37). 인스톨러 테스트 디렉토리의 실재 파일 수(재귀 · `scandir`)
        // 의 80% 를 하한으로 삼되 25 아래로는 내리지 않는다. 매니페스트가 더한 파일은 이 도출에 들지
        // 않으므로 실제 모집단은 언제나 이 값보다 크다.
        $installerTests = self::root().'/tests/Unit/Installer';
        $count = 0;
        $walk = static function (string $dir) use (&$walk, &$count): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $dir.'/'.$entry;
                if (is_dir($full)) {
                    $walk($full);
                } elseif (str_ends_with($entry, 'Test.php')) {
                    $count++;
                }
            }
        };
        $walk($installerTests);

        $floor = max(25, (int) floor($count * 0.8));
        $this->assertGreaterThanOrEqual($floor, count($files), sprintf('모집단이 하한(%d, 인스톨러 테스트 %d 의 80%%) 아래다 — 비면 이 메타 검사는 무의미하다', $floor, $count));
        $this->assertContains('tests/Unit/Installer/InstallerEndpointGuardCoverageTest.php', $files);
        $this->assertContains('resources/js/installer/__tests__/installer-token-wiring.test.ts', $files, '매니페스트가 선언한 vitest 파일도 모집단이다');
    }

    public function test_population_is_not_empty(): void
    {
        $this->assertPopulationFloor($this->populationFiles());
    }

    /**
     * ① 소스를 문자로 대조하는 게이트는 예외 없이 주석을 제거한다.
     *
     * @effects installer_source_gates_strip_comments_and_scan_recursively
     */
    public function test_source_comparing_gates_strip_comments(): void
    {
        $offenders = [];
        $files = $this->populationFiles();
        $this->assertPopulationFloor($files);

        foreach ($files as $relative) {
            $src = (string) file_get_contents(self::root().'/'.$relative);

            if ($this->comparesRawSource($src, str_ends_with($relative, '.php'))) {
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
     *
     * @effects installer_source_gates_strip_comments_and_scan_recursively
     */
    public function test_production_source_population_scans_are_recursive(): void
    {
        $offenders = [];
        $files = $this->populationFiles();
        $this->assertPopulationFloor($files);

        foreach ($files as $relative) {
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
     * 스트리퍼의 fail-open 구멍 셋을 합성 파일로 잰다 (G3 회차 15 F-31 · F-32 · F-34).
     *
     *  ① PHP 열기 태그가 없는 `.php` — 원문이 그대로 돌아오던 것을 예외로 세웠다.
     *  ② nowdoc/heredoc·안내 문구 안의 배선 이름 — `sourceWithoutCommentsAndStrings()` 가 지운다.
     *  ③ HTML 주석 안의 `<script>` — 구간이 아니므로 예외를 내지 않는다.
     */
    public function test_stripper_closes_its_fail_open_channels(): void
    {
        $dir = sys_get_temp_dir().'/g7-source-gate-'.bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            // ① 열기 태그 없음 → 예외 (종전: 원문 반환 = 「지웠다」가 거짓)
            $noTag = $dir.'/no-open-tag.php';
            file_put_contents($noTag, "<div>// installerWired(</div>\n");
            try {
                $this->sourceWithoutComments($noTag);
                $this->fail('PHP 열기 태그 없는 파일이 예외 없이 통과했다 — 원문이 그대로 돌아와 주석 채널 방어가 사라진다');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('열기 태그', $e->getMessage());
            }

            // ② 문자열 채널 — nowdoc·heredoc·일반 문자열 안의 배선 이름은 지워지고, 실제 호출은 남는다
            $strings = $dir.'/strings.php';
            file_put_contents($strings, <<<'PHP'
                <?php
                $hint = 'call installerHint( here';
                $doc = <<<'TXT'
                installerNowdoc(
                TXT;
                $here = <<<TXT
                installerHeredoc(
                TXT;
                installerReal($hint);
                ?>
                <script>
                const s = 'installerJsString(';
                const t = `installerTemplate( ${installerInterpolated()}`;
                installerJsReal();
                </script>
                PHP);
            $stripped = $this->sourceWithoutCommentsAndStrings($strings);
            foreach (['installerHint(', 'installerNowdoc(', 'installerHeredoc(', 'installerJsString(', 'installerTemplate('] as $ghost) {
                $this->assertStringNotContainsString($ghost, $stripped, $ghost.' 가 문자열 채널로 남아 있다');
            }
            foreach (['installerReal(', 'installerJsReal(', 'installerInterpolated('] as $real) {
                $this->assertStringContainsString($real, $stripped, $real.' 실제 호출이 지워졌다');
            }
            $this->assertSame(substr_count((string) file_get_contents($strings), "\n"), substr_count($stripped, "\n"), '줄 수가 보존되지 않는다');

            // ③ HTML 주석 안의 <script> 는 구간이 아니다 — 예외 없이 진짜 구간의 JS 주석만 지운다
            $view = $dir.'/view.php';
            file_put_contents($view, "<?php \$x = 1; ?>\n<!-- <script src=\"legacy.js\"></script> -->\n<script>\n// installerGhost(\ninstallerLive();\n</script>\n");
            $stripped = $this->sourceWithoutComments($view);
            $this->assertStringNotContainsString('installerGhost(', $stripped, 'HTML 주석을 몰라 진짜 <script> 구간의 JS 주석이 남았다');
            $this->assertStringContainsString('installerLive(', $stripped);
        } finally {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($dir.'/'.$entry);
                }
            }
            @rmdir($dir);
        }
    }

    /**
     * 인스톨러 생산 소스 전수가 스트리퍼를 예외 없이 지난다 — 열기 태그 검사가 실물을 막지 않는다.
     */
    public function test_installer_sources_all_pass_the_stripper(): void
    {
        $root = self::root().'/public/install';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $count++;
            $this->assertNotSame('', $this->sourceWithoutCommentsAndStrings($file->getPathname()), $file->getPathname());
        }
        $this->assertGreaterThanOrEqual(30, $count, '인스톨러 PHP 모집단이 실물과 어긋났다 (실측 37, 2026-08-29)');
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

        $this->assertTrue($this->comparesRawSource($rawGate, true), '원문 대조 게이트를 놓치면 이 축이 통째로 무의미해진다');

        // ── G3 회차 14 W4-8: 다섯 단언 밖의 문자 대조 — 판정 함수 + 일반 단언.
        $regexJudge = <<<'PHP'
            <?php
            $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/views/5-installation.php');
            preg_match_all('/class="([^"]*)"/', $source, $m);
            $this->assertSame([], array_diff($m[1], $declared));
            PHP;
        $regexJudgeStripped = <<<'PHP'
            <?php
            $source = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/views/5-installation.php');
            preg_match_all('/class="([^"]*)"/', $source, $m);
            $this->assertSame([], array_diff($m[1], $declared));
            PHP;
        $regexNoAssertion = <<<'PHP'
            <?php
            $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/views/5-installation.php');
            preg_match_all('/class="([^"]*)"/', $source, $m);
            return $m[1];
            PHP;

        $this->assertTrue($this->comparesRawSource($regexJudge, true), 'preg_match_all + assertSame 도 문자 대조 게이트다 — 다섯 단언만 보면 축 밖이다');
        $this->assertFalse($this->comparesRawSource($regexJudgeStripped, true), '공용 진입점(sourceWithoutComments)을 지난 읽기는 위반이 아니다');
        $this->assertFalse($this->comparesRawSource($regexNoAssertion, true), '판정 함수만 있고 단언이 없으면 게이트가 아니다');

        // 언어 정합 — 스트리퍼를 거쳤어도 언어가 어긋나면 지운 것이 아니다.
        $jsWithPhpStripper = <<<'PHP'
            <?php
            $js = $this->stripComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/assets/js/installer.js'));
            $this->assertStringContainsString('installerWired(', $js);
            PHP;
        $viewWithPhpStripper = <<<'PHP'
            <?php
            $view = $this->stripComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/views/5-installation.php'));
            $this->assertStringContainsString('command-output-body', $view);
            PHP;
        $phpWithJsStripper = <<<'PHP'
            <?php
            $php = $this->stripJsComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/task-runner.php'));
            $this->assertStringContainsString('installerRunArtisan(', $php);
            PHP;
        $includeWithPhpStripper = <<<'PHP'
            <?php
            $php = $this->stripComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/task-runner.php'));
            $this->assertStringContainsString('installerRunArtisan(', $php);
            PHP;
        $jsWithJsStripper = <<<'PHP'
            <?php
            $js = $this->stripJsComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/assets/js/installer.js'));
            $this->assertStringContainsString('installerWired(', $js);
            PHP;

        $this->assertTrue($this->comparesRawSource($jsWithPhpStripper, true), 'JS 파일에 PHP 토크나이저 — 아무것도 지워지지 않는다');
        $this->assertTrue($this->comparesRawSource($viewWithPhpStripper, true), 'PHP 뷰에 stripComments 만 — <script> 안의 JS 주석이 남는다');
        $this->assertTrue($this->comparesRawSource($phpWithJsStripper, true), 'PHP 파일에 JS 스캐너 — heredoc·# 주석을 모른다');
        $this->assertFalse($this->comparesRawSource($includeWithPhpStripper, true), '뷰가 아닌 PHP 파일에 stripComments 는 정합이다');
        $this->assertFalse($this->comparesRawSource($jsWithJsStripper, true), 'JS 파일에 stripJsComments 는 정합이다');

        $dispatchByExtension = <<<'PHP'
            <?php
            $source = str_ends_with($relative, '.js')
                ? $this->stripJsComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/'.$relative))
                : $this->stripComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/'.$relative));
            $this->assertStringContainsString('installerWired(', $source);
            PHP;
        $equalityOnFixture = <<<'PHP'
            <?php
            $targets = installerExposureProbeTargets(dirname(__DIR__, 3).'/public/install');
            preg_match('/x/', $targets[0], $m);
            $this->assertSame('content-'.$i, file_get_contents($path));
            PHP;

        $this->assertFalse($this->comparesRawSource($dispatchByExtension, true), '확장자로 분기하는 문장의 `.js` 리터럴은 조건이지 읽는 경로가 아니다');
        $this->assertFalse($this->comparesRawSource($equalityOnFixture, true), '읽은 내용 전체를 등가 비교하는 문장은 값 검증이지 문자 대조가 아니다');
        $this->assertFalse($this->comparesRawSource($strippedGate, true), '주석을 지운 게이트는 위반이 아니다');
        $this->assertFalse($this->comparesRawSource($stateReader, true), '상태 파일 읽기는 주석 채널 결함군과 무관하다');
        $this->assertFalse($this->comparesRawSource($behaviourTest, true), '행위 테스트는 문자 대조 게이트가 아니다');

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
            $this->comparesRawSource($rootThenSuffix, true),
            '뿌리를 먼저 잡고 뒤에서 조각을 잇는 형태 — 접미 슬래시를 요구하면 이 형태가 축 밖으로 빠진다'
        );
        $this->assertTrue(
            $this->comparesRawSource($fileFunctionRead, true),
            'file() 도 소스를 통째로 읽는다 — 읽기 표기를 file_get_contents 로만 한정하면 사각이 된다'
        );

        // ── G3 회차 13 W5: 검출기 정교화 세 축.
        //    ① 실행 전용 읽기 — `eval(source)` · `new Function(block)` 는 행위 테스트다.
        //    ② 테스트 소스 자신의 주석 — 독블록 속 `file_get_contents(` 는 읽기가 아니다.
        //    ③ 산출물 — 문서(`.md`)와 temp 기반(`BASE_PATH.$…`) 파일은 주석 채널이 없다.
        $evalLoader = <<<'TS'
            const source = readFileSync(path.resolve(__dirname, '../../../../public/install/assets/js/installation-monitor.js'), 'utf8');
            (0, eval)(source);
            expect(logged).toContain('환경 설정을 기록했습니다.');
            TS;
        $derivedExecution = <<<'TS'
            const src = readFileSync(path.resolve(__dirname, '../../../../public/install/assets/js/installer.js'), 'utf8');
            const block = src.slice(src.indexOf('*/', open) + 2, src.lastIndexOf('/*', close));
            const runner = new Function('status', 'payload', block + '\nreturn describe(status, payload);');
            expect(failure.detail).toContain('HTTP 500');
            TS;
        $executedAndCompared = <<<'TS'
            const src = readFileSync(path.resolve(__dirname, '../../../../public/install/assets/js/installer.js'), 'utf8');
            (0, eval)(src);
            const body = src.slice(0, 100);
            expect(body).toContain('statusText');
            TS;
        $commentedRead = <<<'PHP'
            <?php
            // 종전에는 $src = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/run-task.php'); 였다
            $this->assertStringContainsString('installerStepwiseTokenRejection(', $this->stripComments($this->live()));
            PHP;
        $docReader = <<<'PHP'
            <?php
            $source = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/config.php');
            $doc = (string) file_get_contents(dirname(__DIR__, 3).'/docs/requirements.md');
            $this->assertMatchesRegularExpression('/필수 확장 중 \d+개/u', $doc);
            PHP;
        $tempArtifact = <<<'PHP'
            <?php
            $targets = installerExposureProbeTargets(dirname(__DIR__, 3).'/public/install');
            $this->assertStringContainsString($sentinel['marker'], (string) file_get_contents(BASE_PATH.$sentinel['path']));
            PHP;

        $this->assertFalse($this->comparesRawSource($evalLoader, false), 'eval 로 실행만 하는 읽기는 문자 대조가 아니다');
        $this->assertFalse($this->comparesRawSource($derivedExecution, false), '파생 변수를 거쳐 new Function 으로 가는 읽기도 실행이다');
        $this->assertTrue($this->comparesRawSource($executedAndCompared, false), '실행도 하지만 파생 변수가 문자 대조에 닿으면 위반이다');
        $this->assertFalse($this->comparesRawSource($commentedRead, true), '테스트 소스의 주석 속 읽기 언급은 읽기가 아니다');
        $this->assertFalse($this->comparesRawSource($docReader, true), '문서 파일은 주석 채널이 없다');
        $this->assertFalse($this->comparesRawSource($tempArtifact, true), 'temp BASE_PATH 아래의 동적 경로는 검사 산출물이다');

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
