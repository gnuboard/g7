<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 실행 모드가 관여하는 지점의 전수 열거 (R9 · R10).
 *
 * 과거 사고(R9): 운영자가 bundled 를 골랐는데 Step 5 가 composer 를 강제 검사해 설치가 중단됐다 —
 * "모드를 읽어야 하는 지점" 하나가 모드를 읽지 않은 것이 원인이다. 실행 모드도 같은 형태로 빠질 수
 * 있으므로, 지점을 손으로 열거하지 않고 **소스를 스캔해 도출**한다. 새 지점이 생기면 이 테스트가
 * 모집단에 자동으로 넣고, 그 지점이 모드 보정을 거치지 않으면 red 가 된다.
 *
 * 판정 규칙:
 *  - Vendor 설치 방식(`vendor_mode`)을 읽는 PHP 파일은 같은 파일 안에서 실행 모드 보정
 *    (`installerCoerceVendorModeForExecution` / `vendorModeFromState`)을 거쳐야 한다. inprocess 에서는
 *    composer 가 성립하지 않으므로 보정 없는 읽기는 곧 모드 분기 누락이다 (A-4).
 *  - 실행 모드 선택값(`execution_mode`)을 읽는 PHP 파일은 정규화/해석 헬퍼
 *    (`installerNormalizeExecutionMode` / `installerResolveExecutionMode` / `installerEffectiveExecutionMode`)
 *    를 거쳐야 한다 — 어휘 밖의 값이 그대로 흘러 settings.json 이나 상태 파일에 남지 않게.
 *  - 화면(`views/*.php`)의 표시용 읽기도 같은 규칙을 받는다 — 화면이 보정 전 값을 그리면 운영자가
 *    고를 수 없는 선택지를 고른 것으로 보인다.
 *
 * 커버 축: mode_touchpoint vendor_mode_read·execution_mode_read
 */
class InstallerExecutionModeTouchpointCoverageTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /** 보정 함수를 정의하는 파일 — 자기 자신을 소비자로 세지 않는다 */
    private const DEFINITION_FILES = ['includes/execution-mode.php'];

    private function installerDir(): string
    {
        return dirname(__DIR__, 3).'/public/install';
    }

    /**
     * 인스톨러 PHP 소스 전수 (lang 제외).
     *
     * @return array<string, string> 상대 경로 => 내용
     */
    private function sources(): array
    {
        $dir = $this->installerDir();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        $sources = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            if (str_starts_with($relative, 'lang/')) {
                continue;
            }
            // 주석을 지운 뒤 담는다 — 원문 대조는 주석으로 감싼 헬퍼 호출도
            // 「헬퍼를 지났다」로 센다. 실측(12차 GI-4)에서 두 헬퍼 호출을 전부 주석으로
            // 바꿔도 이 파일의 판정이 초록이었다.
            $sources[$relative] = $this->stripComments((string) file_get_contents($file->getPathname()));
        }

        ksort($sources);

        return $sources;
    }

    /**
     * 주어진 키를 배열 첨자로 읽는 파일만 고른다 (`['vendor_mode']` / `["vendor_mode"]`).
     *
     * @param  array<string, string>  $sources
     * @return array<string, string>
     */
    private function readersOf(array $sources, string $key): array
    {
        $pattern = '/\[\s*[\'"]'.preg_quote($key, '/').'[\'"]\s*\]/';

        return array_filter(
            $sources,
            fn (string $content, string $path) => ! in_array($path, self::DEFINITION_FILES, true) && preg_match($pattern, $content) === 1,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public function test_population_is_derived_by_scanning_not_by_hand(): void
    {
        $sources = $this->sources();

        $this->assertGreaterThanOrEqual(20, count($sources), '인스톨러 PHP 소스가 스캔되어야 한다');
        $this->assertArrayHasKey('includes/task-runner.php', $sources);
        $this->assertArrayHasKey('includes/request-handler.php', $sources);
        $this->assertArrayHasKey('api/install-process.php', $sources);
        $this->assertArrayHasKey('views/3-configuration.php', $sources);
    }

    /**
     * R9/R10/A-4 — vendor_mode 를 읽는 모든 지점이 실행 모드 보정을 거친다.
     *
     * @effects installer_every_vendor_mode_read_is_coerced_by_execution_mode
     */
    public function test_every_vendor_mode_read_goes_through_execution_mode_coercion(): void
    {
        $readers = $this->readersOf($this->sources(), 'vendor_mode');

        $this->assertNotEmpty($readers, 'vendor_mode 를 읽는 지점이 하나도 없을 수 없다 — 스캔 패턴을 의심할 것');
        $this->assertArrayHasKey('includes/task-runner.php', $readers, '확장 설치 커맨드에 --vendor-mode 를 전파하는 지점');
        $this->assertArrayHasKey('api/install-process.php', $readers, '설치 시작 시 확정 지점');
        $this->assertArrayHasKey('includes/request-handler.php', $readers, 'Step 3 저장 지점');

        $missing = $this->readsWithoutHelper($readers, 'vendor_mode', ['installerCoerceVendorModeForExecution', 'vendorModeFromState']);

        $this->assertSame([], $missing, 'vendor_mode 를 읽지만 실행 모드로 보정하지 않는 지점 — inprocess 에서 composer 가 선택된 채 흘러간다 (R9 사고 재발): '.implode(', ', $missing));
    }

    /**
     * 실행 모드 선택값을 읽는 모든 지점이 정규화/해석 헬퍼를 거친다.
     *
     * @effects installer_every_execution_mode_read_is_normalized
     */
    public function test_every_execution_mode_read_goes_through_normalizer_or_resolver(): void
    {
        $readers = $this->readersOf($this->sources(), 'execution_mode');

        $this->assertNotEmpty($readers);
        $this->assertArrayHasKey('includes/task-runner.php', $readers, 'settings.json 에 선택값을 기록하는 지점');

        $missing = $this->readsWithoutHelper($readers, 'execution_mode', ['installerNormalizeExecutionMode', 'installerResolveExecutionMode', 'installerEffectiveExecutionMode']);

        $this->assertSame([], $missing, 'execution_mode 를 읽지만 정규화/해석하지 않는 지점: '.implode(', ', $missing));
    }

    /**
     * 태스크 실행 시점의 전파 지점은 vendorModeFromState() 하나로 수렴한다 — 커맨드 조립부가 state 를
     * 직접 읽으면 보정이 빠진다.
     */
    public function test_task_runner_reads_vendor_mode_only_through_state_helper(): void
    {
        $taskRunner = $this->sources()['includes/task-runner.php'];

        preg_match_all('/\[\s*[\'"]vendor_mode[\'"]\s*\]/', $taskRunner, $matches, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as [$literal, $offset]) {
            $enclosing = $this->enclosingFunctionName($taskRunner, $offset);
            $this->assertSame('vendorModeFromState', $enclosing, "task-runner 의 vendor_mode 직접 읽기는 vendorModeFromState 안에만 있어야 한다 (발견 위치: {$enclosing})");
        }
    }

    /**
     * 보정 헬퍼를 거치지 않는 **읽기 지점**을 찾는다 — 판정 단위는 「그 값이 헬퍼를 통과했는가」다.
     *
     * 종전 판정은 「같은 함수 본문 안에 헬퍼 **이름이 있는가**」였다 (9차 #G10 이 파일 단위에서
     * 함수 단위로 좁힌 형태). 그 조건은 세 방향으로 헐겁다:
     *  ① 주석 안의 이름이 그대로 계상된다 (지금은 sources() 가 주석을 지운다).
     *  ② 같은 함수의 **다른 값**을 보정한 호출이 이 읽기의 알리바이가 된다.
     *  ③ 읽기가 보정과 무관한 경로로 흘러도 초록이다.
     * 그래서 여기서는 읽은 값이 실제로 헬퍼에 도달하는 세 형태만 통과시킨다.
     *
     * @param  array<string, string>  $readers  파일 경로 → 소스 (주석 제거 상태)
     * @param  string  $key  배열 첨자 이름
     * @param  array<int, string>  $helpers  보정/정규화 헬퍼 이름
     * @return array<int, string> "파일::함수" 목록
     */
    private function readsWithoutHelper(array $readers, string $key, array $helpers): array
    {
        $pattern = '/\[\s*[\'"]'.preg_quote($key, '/').'[\'"]\s*\]/';
        $missing = [];

        foreach ($readers as $path => $content) {
            preg_match_all($pattern, $content, $found, PREG_OFFSET_CAPTURE);

            foreach ($found[0] as $match) {
                $offset = (int) $match[1];
                $statement = $this->statementAt($content, $offset);

                // ① 읽기가 헬퍼 호출과 같은 문장 안에 있다 (인자로 직접 넘기거나 그 자리에서 보정)
                if ($this->mentionsHelper($statement, $helpers)) {
                    continue;
                }

                $enclosing = $this->enclosingFunctionName($content, $offset);
                $scope = $enclosing === '(none)' ? $content : $this->functionBody($content, $enclosing);

                // ② 읽은 값을 담은 변수를 같은 범위에서 헬퍼에 통과시켰다 (대입 대상 추적)
                $assigned = $this->assignmentTargetOf($statement);
                if ($assigned !== null && $this->variableReachesHelper($scope, $assigned, $helpers)) {
                    continue;
                }

                // ③ 같은 범위에서 그 첨자 자체를 제자리 보정했다 (`$x['key'] = helper(...)`)
                if ($this->inPlaceCoercionExists($scope, $key, $helpers)) {
                    continue;
                }

                $label = $path.'::'.$enclosing;
                if (! in_array($label, $missing, true)) {
                    $missing[] = $label;
                }
            }
        }

        return $missing;
    }

    /**
     * 이 오프셋이 속한 **한 문장**. 앞은 직전 `;`/`{`/`}`/`?` + `>`, 뒤는 다음 `;` 로 끊는다.
     *
     * @param  string  $source  소스
     * @param  int  $offset  대상 위치
     * @return string 문장
     */
    private function statementAt(string $source, int $offset): string
    {
        $start = 0;
        foreach ([';', '{', '}', '?'.'>'] as $boundary) {
            $at = strrpos(substr($source, 0, $offset), $boundary);
            if ($at !== false && $at + strlen($boundary) > $start) {
                $start = $at + strlen($boundary);
            }
        }

        $end = strpos($source, ';', $offset);
        $end = $end === false ? strlen($source) : $end;

        return substr($source, $start, $end - $start);
    }

    /**
     * 조각 안에 헬퍼 호출이 있는가.
     *
     * @param  string  $fragment  소스 조각
     * @param  array<int, string>  $helpers  헬퍼 이름
     * @return bool 헬퍼 호출 존재 여부
     */
    private function mentionsHelper(string $fragment, array $helpers): bool
    {
        return preg_match('/\b(?:'.$this->helperAlternation($helpers).')\s*\(/', $fragment) === 1;
    }

    /**
     * 헬퍼 이름 목록을 정규식 교대 조각으로.
     *
     * @param  array<int, string>  $helpers  헬퍼 이름
     * @return string 교대 조각
     */
    private function helperAlternation(array $helpers): string
    {
        return implode('|', array_map(static fn (string $h): string => preg_quote($h, '/'), $helpers));
    }

    /**
     * 이 문장이 단순 변수에 대입하는가 — 대입 대상 변수명 (아니면 null).
     *
     * `$formData['vendor_mode'] = …` 처럼 첨자에 쓰는 문장은 대상이 아니다(그것은 쓰기이고,
     * 그 형태는 판정 ①/③ 이 이미 다룬다).
     *
     * @param  string  $statement  문장
     * @return string|null 변수명
     */
    private function assignmentTargetOf(string $statement): ?string
    {
        if (preg_match('/^\s*(\$[A-Za-z_][A-Za-z0-9_]*)\s*=(?![=>])/', $statement, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * 그 변수가 같은 범위에서 헬퍼의 **인자**로 들어가는가.
     *
     * @param  string  $scope  함수 본문 또는 파일 전체
     * @param  string  $variable  `$` 를 포함한 변수명
     * @param  array<int, string>  $helpers  헬퍼 이름
     * @return bool 도달 여부
     */
    private function variableReachesHelper(string $scope, string $variable, array $helpers): bool
    {
        return preg_match(
            '/\b(?:'.$this->helperAlternation($helpers).')\s*\([^;]*'.preg_quote($variable, '/').'\b/s',
            $scope
        ) === 1;
    }

    /**
     * 같은 범위에서 그 첨자 자체를 제자리 보정했는가 (`$config['vendor_mode'] = helper(...)`).
     *
     * 보정 뒤에 같은 첨자를 다시 읽는 것은 이미 보정된 값을 읽는 것이다 — 이 형태를 통과시키지
     * 않으면 in-place 보정 관용구 전체가 위반으로 잡힌다.
     *
     * @param  string  $scope  함수 본문 또는 파일 전체
     * @param  string  $key  배열 첨자 이름
     * @param  array<int, string>  $helpers  헬퍼 이름
     * @return bool 제자리 보정 존재 여부
     */
    private function inPlaceCoercionExists(string $scope, string $key, array $helpers): bool
    {
        return preg_match(
            '/\[\s*[\'"]'.preg_quote($key, '/').'[\'"]\s*\]\s*=(?![=>])[^;]*\b(?:'.$this->helperAlternation($helpers).')\s*\(/s',
            $scope
        ) === 1;
    }

    /**
     * 판정 3형태의 **부정 분기**를 합성 입력으로 태운다 (12차 GI-4).
     *
     * 실 소스가 준수 상태라 위 두 검사의 `$missing` 은 늘 빈 배열이다 — 판정이 헐거워져도
     * red 가 나지 않는다. 통과해야 할 형태와 잡혀야 할 형태를 나란히 태운다.
     */
    public function test_read_judgment_requires_the_value_to_reach_the_helper(): void
    {
        $helpers = ['installerCoerceVendorModeForExecution'];

        $sameStatement = "<?php\n\$x = installerCoerceVendorModeForExecution((string) (\$config['vendor_mode'] ?? 'auto'), \$mode);\n";
        $variableTraced = "<?php\nfunction f() {\n    \$vendorMode = trim(\$formData['vendor_mode'] ?? 'auto');\n    \$formData['x'] = installerCoerceVendorModeForExecution(\$vendorMode, \$mode);\n}\n";
        $inPlace = "<?php\n\$config['vendor_mode'] = installerCoerceVendorModeForExecution(\$config['vendor_mode'], \$mode);\n\$later = (string) \$config['vendor_mode'];\n";

        foreach (['same_statement' => $sameStatement, 'variable_traced' => $variableTraced, 'in_place' => $inPlace] as $label => $src) {
            $this->assertSame(
                [],
                $this->readsWithoutHelper([$label => $src], 'vendor_mode', $helpers),
                $label.' 은 읽은 값이 헬퍼에 도달하는 형태다'
            );
        }

        // 잡혀야 하는 형태 — 같은 함수 안에 헬퍼 **이름만** 있고, 이 읽기는 그 헬퍼를 지나지 않는다.
        // 종전 판정(본문에 이름이 있으면 통과)은 이것을 초록으로 넘겼다.
        $alibi = "<?php\nfunction f() {\n    \$other = installerCoerceVendorModeForExecution(\$somethingElse, \$mode);\n    \$raw = \$state['vendor_mode'] ?? '';\n\n    return \$raw;\n}\n";
        $this->assertSame(
            ['alibi::f'],
            $this->readsWithoutHelper(['alibi' => $alibi], 'vendor_mode', $helpers),
            '같은 함수의 다른 값을 보정한 호출이 이 읽기의 알리바이가 되면 안 된다'
        );

        // 주석 채널 — sources() 가 주석을 지우므로 여기서는 「지우기 전/후」를 나란히 고정한다.
        $commented = "<?php\nfunction f() {\n    \$raw = /* installerCoerceVendorModeForExecution( */ (\$state['vendor_mode'] ?? '');\n\n    return \$raw;\n}\n";
        $this->assertSame(
            [],
            $this->readsWithoutHelper(['raw' => $commented], 'vendor_mode', $helpers),
            '원문에는 이름이 남아 있어 통과한다 — 이 사실이 곧 stripComments 가 필요한 이유다'
        );
        $this->assertSame(
            ['stripped::f'],
            $this->readsWithoutHelper(['stripped' => $this->stripComments($commented)], 'vendor_mode', $helpers),
            '주석을 지우면 그 호출은 알리바이가 되지 못한다'
        );
    }
}
